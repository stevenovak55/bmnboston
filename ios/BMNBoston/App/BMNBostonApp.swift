//
//  BMNBostonApp.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 5: Client Analytics
//

import SwiftUI
import UserNotifications
import UIKit

@main
struct BMNBostonApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var authViewModel = AuthViewModel()
    @StateObject private var appearanceManager = AppearanceManager.shared
    @StateObject private var pushNotificationManager = PushNotificationManager.shared
    @StateObject private var propertySearchViewModel = PropertySearchViewModel()
    @StateObject private var notificationStore = NotificationStore.shared
    @StateObject private var referralCodeManager = ReferralCodeManager.shared
    @StateObject private var siteContactManager = SiteContactManager.shared

    @Environment(\.scenePhase) private var scenePhase

    // MARK: - Initialization

    init() {
        // Increase URLCache limits for better image caching (v372)
        // Default is 4MB memory, 20MB disk - increase significantly for property images
        // AsyncImage uses URLSession.shared which respects this cache
        URLCache.shared = URLCache(
            memoryCapacity: 50 * 1024 * 1024,  // 50MB memory (~100 images)
            diskCapacity: 200 * 1024 * 1024     // 200MB disk (survives app restart)
        )
    }

    var body: some Scene {
        WindowGroup {
            ZStack {
                ContentView()
                    .environmentObject(authViewModel)
                    .environmentObject(appearanceManager)
                    .environmentObject(pushNotificationManager)
                    .environmentObject(propertySearchViewModel)
                    .environmentObject(notificationStore)
                    .environmentObject(referralCodeManager)
                    .environmentObject(siteContactManager)
                    .withOfflineBanner()  // v233: Show banner when offline

                // Toast overlay (v216) - always on top for notifications
                ToastOverlay()
            }
            .preferredColorScheme(appearanceManager.colorScheme)
                .onReceive(NotificationCenter.default.publisher(for: .didReceivePushNotification)) { notification in
                    handlePushNotification(notification)
                }
                .onChange(of: scenePhase) { newPhase in
                    handleScenePhaseChange(newPhase)
                }
                .onChange(of: authViewModel.isAuthenticated) { isAuthenticated in
                    handleAuthChange(isAuthenticated)
                }
                .onOpenURL { url in
                    handleDeepLink(url)
                }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { userActivity in
                    // Handle Universal Links (https://bmnboston.com/property/...)
                    if let url = userActivity.webpageURL {
                        handleDeepLink(url)
                    }
                }
                .task {
                    // Initialize public analytics for ALL users (anonymous + authenticated)
                    await PublicAnalyticsService.shared.initialize()
                    // Fetch site contact settings early (used for users without assigned agent)
                    await SiteContactManager.shared.fetchIfNeeded()

                    // v388: Initialize ActivityTracker with current auth state on app launch
                    // This fixes tracking for users who already have valid tokens from previous session
                    // (onChange only fires on VALUE CHANGE, not initial load)
                    let isAuth = authViewModel.isAuthenticated
                    await SessionManager.shared.setAuthenticated(isAuth)
                    await ActivityTracker.shared.setAuthenticated(isAuth)

                    // If authenticated, also track the app launch as a login activity
                    if isAuth {
                        await ActivityTracker.shared.track(.login)
                    }
                }
        }
    }

    // MARK: - Deep Link Handling

    /// Handle deep links from URL schemes (bmnboston://...) and Universal Links (https://bmnboston.com/...)
    private func handleDeepLink(_ url: URL) {
        #if DEBUG
        debugLog("📎 Received deep link: \(url)")
        #endif

        // Handle Universal Links (https://bmnboston.com/property/...)
        if url.scheme == "https" && (url.host == "bmnboston.com" || url.host == "www.bmnboston.com") {
            handleUniversalLink(url)
            return
        }

        guard url.scheme == "bmnboston" else { return }

        // Handle property deep links: bmnboston://property/{mls_number}
        if url.host == "property" {
            let pathComponents = url.pathComponents.filter { $0 != "/" }
            if let mlsNumber = pathComponents.first, !mlsNumber.isEmpty {
                #if DEBUG
                debugLog("📎 Deep link to property: \(mlsNumber)")
                #endif
                // Clear any existing property navigation state (v399 fix)
                NotificationCenter.default.post(name: .clearPropertyNavigation, object: nil)
                // Navigate to property detail
                notificationStore.setPendingPropertyNavigation(listingId: mlsNumber, listingKey: nil)
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }
            return
        }

        // Handle signup referral links: bmnboston://signup?ref=CODE
        if url.host == "signup" {
            if let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
               let refParam = components.queryItems?.first(where: { $0.name == "ref" })?.value,
               !refParam.isEmpty {
                // Store the referral code
                referralCodeManager.storeReferralCode(refParam)

                // If user is not authenticated, they'll see the referral code when they register
                // If user is already logged in, clear the code (they don't need it)
                if authViewModel.isAuthenticated {
                    referralCodeManager.clearReferralCode()
                    #if DEBUG
                    debugLog("📎 User already authenticated, ignoring referral code")
                    #endif
                } else {
                    // Notify to navigate to registration
                    NotificationCenter.default.post(name: .showRegistrationWithReferral, object: nil)
                    #if DEBUG
                    debugLog("📎 Stored referral code: \(refParam), will show registration")
                    #endif
                }
            }
        }
    }

    /// Handle Universal Links (iOS opens web URLs directly in app)
    private func handleUniversalLink(_ url: URL) {
        let path = url.path

        #if DEBUG
        debugLog("📎 Universal Link path: \(path)")
        #endif

        // Extract MLS number from /property/{mls_number}/ or /property/{mls_number}
        // Matches: /property/73464868/ or /property/73464868
        if path.hasPrefix("/property/") {
            // Remove /property/ prefix and trailing slash
            var mlsNumber = String(path.dropFirst("/property/".count))
            if mlsNumber.hasSuffix("/") {
                mlsNumber = String(mlsNumber.dropLast())
            }

            // Handle URLs like /property/address-slug-73464868/ (extract MLS number from end)
            // MLS numbers are typically 8 digits
            if mlsNumber.contains("-") {
                let components = mlsNumber.split(separator: "-")
                if let lastComponent = components.last,
                   lastComponent.allSatisfy({ $0.isNumber }),
                   lastComponent.count >= 6 {
                    mlsNumber = String(lastComponent)
                }
            }

            if !mlsNumber.isEmpty {
                #if DEBUG
                debugLog("📎 Universal Link to property: \(mlsNumber)")
                #endif
                // Track email click if eid parameter present (v6.75.9)
                trackEmailClickIfNeeded(url: url)
                // Clear any existing property navigation state (v399 fix)
                NotificationCenter.default.post(name: .clearPropertyNavigation, object: nil)
                // Navigate to property detail
                notificationStore.setPendingPropertyNavigation(listingId: mlsNumber, listingKey: nil)
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }
            return
        }

        // Handle /listing/ URLs (same as /property/)
        if path.hasPrefix("/listing/") {
            var mlsNumber = String(path.dropFirst("/listing/".count))
            if mlsNumber.hasSuffix("/") {
                mlsNumber = String(mlsNumber.dropLast())
            }

            if !mlsNumber.isEmpty {
                #if DEBUG
                debugLog("📎 Universal Link to listing: \(mlsNumber)")
                #endif
                // Track email click if eid parameter present (v6.75.9)
                trackEmailClickIfNeeded(url: url)
                // Clear any existing property navigation state (v399 fix)
                NotificationCenter.default.post(name: .clearPropertyNavigation, object: nil)
                notificationStore.setPendingPropertyNavigation(listingId: mlsNumber, listingKey: nil)
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }
            return
        }

        // Handle /saved-search/ or /saved-search/{id}/ URLs
        if path.hasPrefix("/saved-search") {
            var searchIdString = String(path.dropFirst("/saved-search".count))
            // Remove leading and trailing slashes
            if searchIdString.hasPrefix("/") {
                searchIdString = String(searchIdString.dropFirst())
            }
            if searchIdString.hasSuffix("/") {
                searchIdString = String(searchIdString.dropLast())
            }

            if searchIdString.isEmpty {
                // No ID - just /saved-search/ - open saved searches list
                #if DEBUG
                debugLog("📎 Universal Link to saved searches list")
                #endif
                NotificationCenter.default.post(name: .showSavedSearchesList, object: nil)
            } else if let searchId = Int(searchIdString) {
                // Has ID - open specific saved search results
                #if DEBUG
                debugLog("📎 Universal Link to saved search: \(searchId)")
                #endif
                NotificationCenter.default.post(
                    name: .navigateToSavedSearch,
                    object: nil,
                    userInfo: ["search_id": searchId]
                )
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }
            return
        }

        #if DEBUG
        debugLog("📎 Universal Link not handled: \(path)")
        #endif
    }

    /// Fire-and-forget email click tracking when app opens via email link (v6.75.9)
    /// Email property links include ?eid=xxx&et=click for tracking. When iOS intercepts
    /// the link via universal links, the server-side template_redirect hook never fires,
    /// so we ping the tracking endpoint from the app instead.
    private func trackEmailClickIfNeeded(url: URL) {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
              let eid = components.queryItems?.first(where: { $0.name == "eid" })?.value,
              !eid.isEmpty else {
            return
        }

        let trackingURLString = "\(AppEnvironment.current.fullAPIURL)/email/track/click?eid=\(eid)"
        guard let trackingURL = URL(string: trackingURLString) else { return }

        #if DEBUG
        debugLog("📎 Tracking email click for eid: \(eid)")
        #endif

        // Use detached task so it isn't cancelled if the parent task completes
        Task.detached(priority: .utility) {
            _ = try? await URLSession.shared.data(from: trackingURL)
        }
    }

    // MARK: - Analytics Lifecycle

    private func handleScenePhaseChange(_ phase: ScenePhase) {
        Task {
            switch phase {
            case .active:
                await SessionManager.shared.appWillEnterForeground()
                // Public analytics: may create new session if 30-min timeout passed
                await PublicAnalyticsService.shared.handleAppForeground()
                // Fetch site contact settings (used for users without assigned agent)
                await SiteContactManager.shared.fetchIfNeeded()
                // Sync badge count from server (v6.49.0)
                await PushNotificationManager.shared.syncBadgeCount()
                // Report any rich notification image failures (v6.49.4)
                await PushNotificationManager.shared.reportImageFailuresIfNeeded()
                // Force re-register device token to ensure server has current token (v197)
                // This fixes stale token issues where APNs accepts but device doesn't receive
                await PushNotificationManager.shared.forceReRegisterIfNeeded()
                // v218: Removed redundant NotificationStore.syncFromServer() call here
                // Notification sync happens on login (handleAuthChange) and when Notification Center opens
                // Syncing here caused duplicate notifications to appear on every app foregrounding
                // Report app opened to server (triggers agent notification for clients) (v207)
                await reportAppOpened()
            case .background:
                await SessionManager.shared.appDidEnterBackground()
                // Public analytics: flush events and save session state
                await PublicAnalyticsService.shared.handleAppBackground()
            case .inactive:
                // No action needed for inactive state
                break
            @unknown default:
                break
            }
        }
    }

    private func handleAuthChange(_ isAuthenticated: Bool) {
        debugLog("🔔 handleAuthChange called with: \(isAuthenticated)")
        Task {
            await SessionManager.shared.setAuthenticated(isAuthenticated)
            await ActivityTracker.shared.setAuthenticated(isAuthenticated)

            // Sync or clear badge count based on auth state
            if isAuthenticated {
                // User just logged in - sync badge count from server
                await PushNotificationManager.shared.syncBadgeCount()
                // Sync notification history from server (v6.49.16 / v187)
                await NotificationStore.shared.syncFromServer()
            } else {
                // User logged out - clear local badge and notifications
                try? await UNUserNotificationCenter.current().setBadgeCount(0)
                await NotificationStore.shared.clearAll()
            }
        }
    }

    private func handlePushNotification(_ notification: Notification) {
        guard let userInfo = notification.userInfo,
              let savedSearchId = userInfo["saved_search_id"] as? Int else {
            return
        }

        // Navigate to saved search results
        // This will be handled by the PropertySearchViewModel
        NotificationCenter.default.post(
            name: .navigateToSavedSearch,
            object: nil,
            userInfo: ["search_id": savedSearchId]
        )
    }

    /// Report app opened to server (v207)
    /// This triggers an agent notification when a client opens the app
    /// Server has 2-hour debounce to avoid spamming agents
    private func reportAppOpened() async {
        // Only report if user is authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            return
        }

        do {
            let _: EmptyResponse = try await APIClient.shared.request(.appOpened)
            #if DEBUG
            debugLog("📱 Reported app opened to server")
            #endif
        } catch {
            #if DEBUG
            debugLog("📱 Failed to report app opened: \(error)")
            #endif
            // Silently fail - this is not critical
        }
    }
}

// MARK: - AppDelegate

class AppDelegate: NSObject, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        // Set up notification delegate
        UNUserNotificationCenter.current().delegate = self
        return true
    }

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        let tokenString = deviceToken.map { String(format: "%02.2hhx", $0) }.joined()

        #if DEBUG
        debugLog("📱 Received APNs device token: \(tokenString)")
        #endif

        // Register token with server
        Task {
            await PushNotificationManager.shared.registerDeviceToken(tokenString)
        }
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        debugLog("❌ Failed to register for remote notifications: \(error.localizedDescription)")
    }
}

// MARK: - UNUserNotificationCenterDelegate

extension AppDelegate: UNUserNotificationCenterDelegate {
    // Handle notification when app is in foreground
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification
    ) async -> UNNotificationPresentationOptions {
        let userInfo = notification.request.content.userInfo

        #if DEBUG
        debugLog("📬 Received notification in foreground: \(userInfo)")
        #endif

        // Store notification in NotificationStore
        // Use await MainActor.run since this is an async method accessing @MainActor objects
        await MainActor.run {
            let notificationItem = NotificationItem.from(userInfo: userInfo as? [AnyHashable: Any] ?? [:])
            NotificationStore.shared.add(notificationItem)
        }

        // Show banner and play sound even when app is in foreground
        return [.banner, .sound, .badge]
    }

    // Handle notification tap
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let userInfo = response.notification.request.content.userInfo

        #if DEBUG
        debugLog("👆 User tapped notification: \(userInfo)")
        #endif

        // Track notification engagement (v6.49.4)
        Task {
            await trackNotificationEngagement(userInfo: userInfo, action: "opened")
        }

        // Handle notification storage and navigation on MainActor
        // Using Task with @MainActor instead of DispatchQueue.main.async for proper Swift concurrency
        Task { @MainActor in
            // Store notification if not already stored (e.g., app was closed)
            let notificationItem = NotificationItem.from(userInfo: userInfo as? [AnyHashable: Any] ?? [:])
            // Check if we already have this notification (from willPresent)
            let existingIds = NotificationStore.shared.notifications.map { $0.id }
            if !existingIds.contains(notificationItem.id) {
                NotificationStore.shared.add(notificationItem)
            }

            // Handle navigation based on notification type
            // Property listing navigation (individual property notification)
            // Handle listing_id as String or Number (APNs may deliver either)
            var listingIdString: String?
            if let id = userInfo["listing_id"] as? String {
                listingIdString = id
            } else if let id = userInfo["listing_id"] as? NSNumber {
                listingIdString = id.stringValue
            } else if let id = userInfo["listing_id"] as? Int {
                listingIdString = String(id)
            }

            let listingKey = userInfo["listing_key"] as? String

            if listingIdString != nil || listingKey != nil {
                #if DEBUG
                debugLog("📍 Setting pending property navigation - listingId: \(listingIdString ?? "nil"), listingKey: \(listingKey ?? "nil")")
                #endif
                // Clear any existing property navigation state (v399 fix)
                NotificationCenter.default.post(name: .clearPropertyNavigation, object: nil)
                // Store pending navigation in NotificationStore
                NotificationStore.shared.setPendingPropertyNavigation(listingId: listingIdString, listingKey: listingKey)
                // Switch to search tab
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
                completionHandler()
                return
            }

            // Saved search navigation (summary notification)
            if let savedSearchId = userInfo["saved_search_id"] as? Int {
                NotificationCenter.default.post(
                    name: .navigateToSavedSearch,
                    object: nil,
                    userInfo: ["search_id": savedSearchId]
                )
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }

            // Appointment navigation (appointment_reminder, tour_requested)
            // Extract appointment_id as Int from various possible types
            var appointmentIdInt: Int?
            if let id = userInfo["appointment_id"] as? Int {
                appointmentIdInt = id
            } else if let id = userInfo["appointment_id"] as? NSNumber {
                appointmentIdInt = id.intValue
            } else if let idString = userInfo["appointment_id"] as? String, let id = Int(idString) {
                appointmentIdInt = id
            }

            if let appointmentId = appointmentIdInt {
                #if DEBUG
                debugLog("📍 Setting pending appointment navigation - appointmentId: \(appointmentId)")
                #endif
                NotificationStore.shared.setPendingAppointmentNavigation(appointmentId: appointmentId)
                NotificationCenter.default.post(name: .switchToAppointmentsTab, object: nil)
                completionHandler()
                return
            }

            // Client activity navigation (agent_activity, client_login)
            // Extract client_id as Int from various possible types
            var clientIdInt: Int?
            if let id = userInfo["client_id"] as? Int {
                clientIdInt = id
            } else if let id = userInfo["client_id"] as? NSNumber {
                clientIdInt = id.intValue
            } else if let idString = userInfo["client_id"] as? String, let id = Int(idString) {
                clientIdInt = id
            }

            if let clientId = clientIdInt {
                #if DEBUG
                debugLog("📍 Setting pending client navigation - clientId: \(clientId)")
                #endif
                NotificationStore.shared.setPendingClientNavigation(clientId: clientId)
                NotificationCenter.default.post(name: .switchToMyClientsTab, object: nil)
                completionHandler()
                return
            }

            completionHandler()
        }
    }

    // MARK: - Notification Engagement Tracking (v6.49.4)

    /// Track notification engagement with the server
    private func trackNotificationEngagement(userInfo: [AnyHashable: Any], action: String) async {
        // Extract notification type
        let notificationType = userInfo["notification_type"] as? String ?? "unknown"

        // Extract optional IDs
        var listingId: String?
        if let id = userInfo["listing_id"] as? String {
            listingId = id
        } else if let id = userInfo["listing_id"] as? Int {
            listingId = String(id)
        } else if let id = userInfo["listing_id"] as? NSNumber {
            listingId = id.stringValue
        }

        let savedSearchId = userInfo["saved_search_id"] as? Int
        let appointmentId = userInfo["appointment_id"] as? Int

        // Fire and forget - don't block on response
        do {
            let _: EmptyResponse = try await APIClient.shared.request(
                .trackNotificationEngagement(
                    notificationType: notificationType,
                    action: action,
                    listingId: listingId,
                    savedSearchId: savedSearchId,
                    appointmentId: appointmentId
                )
            )
            #if DEBUG
            debugLog("📊 Tracked notification engagement: \(action) for \(notificationType)")
            #endif
        } catch {
            #if DEBUG
            debugLog("📊 Failed to track notification engagement: \(error)")
            #endif
            // Silently fail - engagement tracking is not critical
        }
    }
}

// MARK: - Notification Names

extension Notification.Name {
    static let didReceivePushNotification = Notification.Name("didReceivePushNotification")
    static let navigateToSavedSearch = Notification.Name("navigateToSavedSearch")
    static let navigateToProperty = Notification.Name("navigateToProperty")
    static let showRegistrationWithReferral = Notification.Name("showRegistrationWithReferral")
    static let showSavedSearchesList = Notification.Name("showSavedSearchesList")
    static let clearPropertyNavigation = Notification.Name("clearPropertyNavigation")
}
