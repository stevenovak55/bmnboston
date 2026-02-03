//
//  PushNotificationManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Manages push notification registration and permission
//

import Foundation
import UserNotifications
import UIKit

/// Registration status for each backend
struct BackendRegistrationStatus: Codable {
    var isRegistered: Bool = false
    var lastAttempt: Date?
    var lastError: String?
    var retryCount: Int = 0

    static let maxRetries = 3
    static let retryDelaySeconds: [Double] = [5, 30, 120] // 5s, 30s, 2min
}

@MainActor
class PushNotificationManager: ObservableObject {
    static let shared = PushNotificationManager()

    @Published var isAuthorized: Bool = false
    @Published var authorizationStatus: UNAuthorizationStatus = .notDetermined
    @Published var deviceToken: String?
    @Published var isRegistering: Bool = false

    /// Track registration status separately for each backend
    @Published var mldRegistrationStatus: BackendRegistrationStatus = BackendRegistrationStatus()
    @Published var snabRegistrationStatus: BackendRegistrationStatus = BackendRegistrationStatus()

    /// True only if both backends are registered
    var isFullyRegistered: Bool {
        mldRegistrationStatus.isRegistered && snabRegistrationStatus.isRegistered
    }

    private let userDefaults = UserDefaults.standard
    private let deviceTokenKey = "com.bmnboston.deviceToken"
    private let tokenRegisteredKey = "com.bmnboston.tokenRegistered"
    private let mldStatusKey = "com.bmnboston.mldRegistrationStatus"
    private let snabStatusKey = "com.bmnboston.snabRegistrationStatus"

    private var retryTask: Task<Void, Never>?

    /// Track previous authorization status for detecting Settings changes
    private var previousAuthorizationStatus: UNAuthorizationStatus = .notDetermined

    /// Observer token for foreground notification (for cleanup)
    private var foregroundObserver: NSObjectProtocol?

    private init() {
        // Load cached token
        deviceToken = userDefaults.string(forKey: deviceTokenKey)

        // Load cached registration status
        loadRegistrationStatus()

        // Check current authorization status
        Task {
            await checkAuthorizationStatus()
            previousAuthorizationStatus = authorizationStatus
        }

        // Observe app foreground to detect Settings changes (v6.49.4)
        setupForegroundObserver()
    }

    // MARK: - Foreground Observer (v6.49.4)

    /// Setup observer to detect when user enables notifications from iOS Settings
    private func setupForegroundObserver() {
        foregroundObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.willEnterForegroundNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                await self?.checkAndReregisterIfNeeded()
            }
        }
    }

    deinit {
        if let observer = foregroundObserver {
            NotificationCenter.default.removeObserver(observer)
        }
        retryTask?.cancel()
    }

    /// Check if user enabled notifications from Settings and trigger registration
    private func checkAndReregisterIfNeeded() async {
        let oldStatus = previousAuthorizationStatus
        await checkAuthorizationStatus()

        // User enabled notifications from Settings
        if oldStatus != .authorized && authorizationStatus == .authorized {
            #if DEBUG
            debugLog("📱 Notifications enabled from Settings - triggering registration")
            #endif
            UIApplication.shared.registerForRemoteNotifications()
        }

        previousAuthorizationStatus = authorizationStatus
    }

    private func loadRegistrationStatus() {
        if let mldData = userDefaults.data(forKey: mldStatusKey),
           let mldStatus = try? JSONDecoder().decode(BackendRegistrationStatus.self, from: mldData) {
            mldRegistrationStatus = mldStatus
        }
        if let snabData = userDefaults.data(forKey: snabStatusKey),
           let snabStatus = try? JSONDecoder().decode(BackendRegistrationStatus.self, from: snabData) {
            snabRegistrationStatus = snabStatus
        }
    }

    private func saveRegistrationStatus() {
        if let mldData = try? JSONEncoder().encode(mldRegistrationStatus) {
            userDefaults.set(mldData, forKey: mldStatusKey)
        }
        if let snabData = try? JSONEncoder().encode(snabRegistrationStatus) {
            userDefaults.set(snabData, forKey: snabStatusKey)
        }
    }

    // MARK: - Authorization

    /// Check current notification authorization status
    func checkAuthorizationStatus() async {
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        authorizationStatus = settings.authorizationStatus
        isAuthorized = settings.authorizationStatus == .authorized
    }

    /// Request notification permission from user
    func requestPermission() async -> Bool {
        do {
            let granted = try await UNUserNotificationCenter.current().requestAuthorization(
                options: [.alert, .badge, .sound]
            )

            await checkAuthorizationStatus()

            if granted {
                // Register for remote notifications on main thread
                UIApplication.shared.registerForRemoteNotifications()
            }

            return granted
        } catch {
            #if DEBUG
            debugLog("Error requesting notification permission: \(error)")
            #endif
            return false
        }
    }

    // MARK: - Device Token Registration

    /// Register device token with the server
    /// Registers with both appointments (snab/v1) and MLD notifications (mld-mobile/v1)
    /// Tracks status separately and retries failed registrations
    /// - Parameters:
    ///   - token: The APNs device token
    ///   - forceReRegister: If true, always re-register with server even if cached status says registered.
    ///                      Use this on app launch to ensure token is always fresh on server. (v197)
    func registerDeviceToken(_ token: String, forceReRegister: Bool = false) async {
        // Check if token changed or if we need to register/retry
        let tokenChanged = token != deviceToken
        let needsMldRegistration = !mldRegistrationStatus.isRegistered || tokenChanged || forceReRegister
        let needsSnabRegistration = !snabRegistrationStatus.isRegistered || tokenChanged || forceReRegister

        // Skip if both backends already registered with this token (and not forcing)
        guard needsMldRegistration || needsSnabRegistration else {
            #if DEBUG
            debugLog("📱 Token already registered with both backends")
            #endif
            return
        }

        #if DEBUG
        if forceReRegister {
            debugLog("📱 Force re-registering device token with server")
        }
        #endif

        // Save token locally
        deviceToken = token
        userDefaults.set(token, forKey: deviceTokenKey)

        // Reset status if token changed
        if tokenChanged {
            mldRegistrationStatus = BackendRegistrationStatus()
            snabRegistrationStatus = BackendRegistrationStatus()
        }

        // Only register with server if user is authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            #if DEBUG
            debugLog("📱 Skipping token registration - user not authenticated")
            #endif
            return
        }

        isRegistering = true
        defer { isRegistering = false }

        // Determine if sandbox/development build
        let isSandbox = Self.isAPNsSandbox()

        // Register with SNAB (appointments) if needed
        if needsSnabRegistration {
            await registerWithSnab(token: token, isSandbox: isSandbox)
        }

        // Register with MLD (property notifications) if needed
        if needsMldRegistration {
            await registerWithMld(token: token, isSandbox: isSandbox)
        }

        // Save status after registration attempts
        saveRegistrationStatus()

        // Schedule retry if either failed
        if !isFullyRegistered {
            scheduleRetryIfNeeded(token: token, isSandbox: isSandbox)
        }

        // Update legacy flag for compatibility
        userDefaults.set(isFullyRegistered, forKey: tokenRegisteredKey)

        #if DEBUG
        debugLog("📱 Registration status - MLD: \(mldRegistrationStatus.isRegistered), SNAB: \(snabRegistrationStatus.isRegistered)")
        #endif
    }

    /// Register with SNAB backend (appointments)
    private func registerWithSnab(token: String, isSandbox: Bool) async {
        snabRegistrationStatus.lastAttempt = Date()
        do {
            // APIClient.request() throws an error if the server returns success: false,
            // so if we get here, registration succeeded
            let _: DeviceRegistrationResponse = try await APIClient.shared.request(
                .registerDeviceToken(token: token, isSandbox: isSandbox)
            )
            snabRegistrationStatus.isRegistered = true
            snabRegistrationStatus.lastError = nil
            snabRegistrationStatus.retryCount = 0
            #if DEBUG
            debugLog("✅ Device token registered for appointments (SNAB)")
            #endif
        } catch {
            snabRegistrationStatus.lastError = error.localizedDescription
            #if DEBUG
            debugLog("❌ Failed to register device token for appointments: \(error)")
            #endif
        }
    }

    /// Register with MLD backend (property notifications)
    private func registerWithMld(token: String, isSandbox: Bool) async {
        mldRegistrationStatus.lastAttempt = Date()
        do {
            // APIClient.request() throws an error if the server returns success: false,
            // so if we get here, registration succeeded
            let _: DeviceRegistrationResponse = try await APIClient.shared.request(
                .registerMLDDeviceToken(token: token, isSandbox: isSandbox)
            )
            mldRegistrationStatus.isRegistered = true
            mldRegistrationStatus.lastError = nil
            mldRegistrationStatus.retryCount = 0
            #if DEBUG
            debugLog("✅ Device token registered for MLD notifications (saved searches, price changes)")
            #endif
        } catch {
            mldRegistrationStatus.lastError = error.localizedDescription
            #if DEBUG
            debugLog("❌ Failed to register device token for MLD notifications: \(error)")
            #endif
        }
    }

    /// Schedule retry for failed registrations with exponential backoff
    private func scheduleRetryIfNeeded(token: String, isSandbox: Bool) {
        // Cancel any existing retry task
        retryTask?.cancel()

        // Check if retry is possible
        let mldCanRetry = !mldRegistrationStatus.isRegistered && mldRegistrationStatus.retryCount < BackendRegistrationStatus.maxRetries
        let snabCanRetry = !snabRegistrationStatus.isRegistered && snabRegistrationStatus.retryCount < BackendRegistrationStatus.maxRetries

        guard mldCanRetry || snabCanRetry else {
            #if DEBUG
            if !isFullyRegistered {
                debugLog("⚠️ Max retries reached for one or more backends")
            }
            #endif
            return
        }

        // Determine retry delay based on highest retry count
        let maxRetryCount = max(mldRegistrationStatus.retryCount, snabRegistrationStatus.retryCount)
        let delayIndex = min(maxRetryCount, BackendRegistrationStatus.retryDelaySeconds.count - 1)
        let delay = BackendRegistrationStatus.retryDelaySeconds[delayIndex]

        #if DEBUG
        debugLog("📱 Scheduling retry in \(delay) seconds (attempt \(maxRetryCount + 1))")
        #endif

        retryTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))

            guard !Task.isCancelled else { return }
            guard let self = self else { return }

            // Increment retry counts before attempting
            if !self.mldRegistrationStatus.isRegistered {
                self.mldRegistrationStatus.retryCount += 1
            }
            if !self.snabRegistrationStatus.isRegistered {
                self.snabRegistrationStatus.retryCount += 1
            }

            // Retry registration
            await self.registerDeviceToken(token)
        }
    }

    /// Unregister device token from server (e.g., on logout)
    /// Unregisters from both appointments (snab/v1) and MLD notifications (mld-mobile/v1)
    func unregisterDeviceToken() async {
        // Cancel any pending retry
        retryTask?.cancel()
        retryTask = nil

        guard let token = deviceToken else { return }

        // Unregister from appointments
        do {
            let _: DeviceRegistrationResponse = try await APIClient.shared.request(
                .unregisterDeviceToken(token: token)
            )
            #if DEBUG
            debugLog("✅ Device token unregistered from appointments")
            #endif
        } catch {
            #if DEBUG
            debugLog("❌ Failed to unregister device token from appointments: \(error)")
            #endif
        }

        // Unregister from MLD notifications
        do {
            let _: DeviceRegistrationResponse = try await APIClient.shared.request(
                .unregisterMLDDeviceToken(token: token)
            )
            #if DEBUG
            debugLog("✅ Device token unregistered from MLD notifications")
            #endif
        } catch {
            #if DEBUG
            debugLog("❌ Failed to unregister device token from MLD notifications: \(error)")
            #endif
        }

        // Reset registration status
        mldRegistrationStatus = BackendRegistrationStatus()
        snabRegistrationStatus = BackendRegistrationStatus()
        saveRegistrationStatus()
        userDefaults.set(false, forKey: tokenRegisteredKey)
    }

    /// Re-register token after login
    func registerAfterLogin() async {
        guard let token = deviceToken else {
            // No token yet, request permission first
            if authorizationStatus == .notDetermined {
                _ = await requestPermission()
            } else if isAuthorized {
                UIApplication.shared.registerForRemoteNotifications()
            }
            return
        }

        // Reset registration status to force re-registration with new user
        mldRegistrationStatus = BackendRegistrationStatus()
        snabRegistrationStatus = BackendRegistrationStatus()
        saveRegistrationStatus()
        userDefaults.set(false, forKey: tokenRegisteredKey)

        // Register existing token with server
        await registerDeviceToken(token)
    }

    /// Get registration status summary for debugging/UI
    func getRegistrationSummary() -> String {
        var summary = "Push Notification Status:\n"
        summary += "- Token: \(deviceToken?.prefix(16) ?? "none")...\n"
        summary += "- MLD: \(mldRegistrationStatus.isRegistered ? "✓" : "✗")"
        if let error = mldRegistrationStatus.lastError {
            summary += " (Error: \(error))"
        }
        summary += "\n"
        summary += "- SNAB: \(snabRegistrationStatus.isRegistered ? "✓" : "✗")"
        if let error = snabRegistrationStatus.lastError {
            summary += " (Error: \(error))"
        }
        return summary
    }

    // MARK: - Helpers

    private func getDeviceModel() -> String {
        var systemInfo = utsname()
        uname(&systemInfo)
        let machineMirror = Mirror(reflecting: systemInfo.machine)
        let identifier = machineMirror.children.reduce("") { identifier, element in
            guard let value = element.value as? Int8, value != 0 else { return identifier }
            return identifier + String(UnicodeScalar(UInt8(value)))
        }
        return identifier
    }

    /// Determines if the app should use APNs sandbox environment.
    /// Returns true ONLY for Debug builds (development provisioning profile).
    /// Returns false for Release builds (TestFlight/App Store use distribution profile = production APNs).
    ///
    /// IMPORTANT: The `sandboxReceipt` check was WRONG for APNs:
    /// - `sandboxReceipt` is for StoreKit (in-app purchases), NOT APNs
    /// - APNs environment is determined by provisioning profile's aps-environment
    /// - Development profile → aps-environment = development → sandbox APNs
    /// - Distribution profile (TestFlight/App Store) → aps-environment = production → production APNs
    static func isAPNsSandbox() -> Bool {
        #if DEBUG
        // Debug builds use development profile with aps-environment = development
        return true
        #else
        // Release builds (TestFlight/App Store) use distribution profile
        // with aps-environment = production, so they use production APNs
        return false
        #endif
    }

    // MARK: - Badge Count Sync (v6.49.0 / v179)

    /// Sync badge count from server (call on app launch)
    func syncBadgeCount() async {
        // Only sync if authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            #if DEBUG
            debugLog("📱 Skipping badge sync - user not authenticated")
            #endif
            return
        }

        do {
            let response: BadgeCountResponse = try await APIClient.shared.request(.badgeCount)
            let badgeCount = response.badgeCount

            // Update local app badge
            await MainActor.run {
                UNUserNotificationCenter.current().setBadgeCount(badgeCount)
            }

            #if DEBUG
            debugLog("📱 Synced badge count: \(badgeCount)")
            #endif
        } catch {
            #if DEBUG
            debugLog("📱 Failed to sync badge count: \(error)")
            #endif
        }
    }

    /// Report any rich notification image failures to the server (v6.49.4)
    /// Called on app launch to report failures logged by NotificationServiceExtension
    func reportImageFailuresIfNeeded() async {
        guard let defaults = UserDefaults(suiteName: "group.com.bmnboston.app") else { return }

        // Get logged failures
        guard let failures = defaults.array(forKey: "notification_image_failures") as? [[String: String]],
              !failures.isEmpty else {
            return
        }

        // Only report if authenticated
        guard await TokenManager.shared.isAuthenticated() else { return }

        #if DEBUG
        debugLog("📱 Reporting \(failures.count) rich notification image failures")
        #endif

        // Report each failure
        for failure in failures {
            guard let url = failure["url"],
                  let reason = failure["reason"] else { continue }

            do {
                let _: EmptyResponse = try await APIClient.shared.request(
                    .trackNotificationEngagement(
                        notificationType: "rich_notification_image_failure",
                        action: "failed",
                        listingId: nil,
                        savedSearchId: nil,
                        appointmentId: nil
                    )
                )
                #if DEBUG
                debugLog("📱 Reported image failure: \(reason) for \(url)")
                #endif
            } catch {
                #if DEBUG
                debugLog("📱 Failed to report image failure: \(error)")
                #endif
            }
        }

        // Clear failures after reporting
        defaults.removeObject(forKey: "notification_image_failures")
    }

    /// Reset badge count on server and locally (call when user views notifications)
    func resetBadgeCount() async {
        // Only reset if authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            return
        }

        do {
            let _: BadgeCountResetResponse = try await APIClient.shared.request(.resetBadgeCount)

            // Clear local app badge
            await MainActor.run {
                UNUserNotificationCenter.current().setBadgeCount(0)
            }

            #if DEBUG
            debugLog("📱 Badge count reset successfully")
            #endif
        } catch {
            #if DEBUG
            debugLog("📱 Failed to reset badge count: \(error)")
            #endif
        }
    }

    // MARK: - Force Re-Registration (v197)

    /// Force re-register device token with server on app launch.
    /// This ensures the server always has the current device token, fixing issues where
    /// APNs accepts notifications (200) but device doesn't receive them due to stale token.
    /// Called from BMNBostonApp when app becomes active.
    func forceReRegisterIfNeeded() async {
        // Only re-register if authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            #if DEBUG
            debugLog("📱 Skipping force re-registration - user not authenticated")
            #endif
            return
        }

        // Only re-register if we have a cached token
        guard let token = deviceToken, !token.isEmpty else {
            #if DEBUG
            debugLog("📱 Skipping force re-registration - no cached device token")
            #endif
            // Request token from iOS (will trigger didRegisterForRemoteNotifications)
            UIApplication.shared.registerForRemoteNotifications()
            return
        }

        #if DEBUG
        debugLog("📱 Force re-registering device token on app launch")
        #endif

        // Re-register with both backends, bypassing the cached status check
        await registerDeviceToken(token, forceReRegister: true)
    }
}

// MARK: - Response Models

/// Response from device token registration endpoint
/// Note: The outer APIResponse wrapper handles success/error status.
/// This struct only needs to match the `data` field from the API response:
/// {"success": true, "message": "...", "data": {"id": 123}}
struct DeviceRegistrationResponse: Decodable {
    let id: Int?  // Token record ID from server (optional for backwards compatibility)

    private enum CodingKeys: String, CodingKey {
        case id
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        // Handle id being either Int or String (server inconsistency)
        if let intId = try? container.decode(Int.self, forKey: .id) {
            self.id = intId
        } else if let stringId = try? container.decode(String.self, forKey: .id),
                  let intValue = Int(stringId) {
            self.id = intValue
        } else {
            self.id = nil
        }
    }
}

struct BadgeCountResponse: Decodable {
    let badgeCount: Int
    let lastNotificationAt: String?
    let lastReadAt: String?

    private enum CodingKeys: String, CodingKey {
        case badgeCount = "badge_count"
        case lastNotificationAt = "last_notification_at"
        case lastReadAt = "last_read_at"
    }
}

struct BadgeCountResetResponse: Decodable {
    let badgeCount: Int

    private enum CodingKeys: String, CodingKey {
        case badgeCount = "badge_count"
    }
}
