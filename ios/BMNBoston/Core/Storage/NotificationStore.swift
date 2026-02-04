//
//  NotificationStore.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Notification Center - Local Storage Manager
//

import Foundation
import SwiftUI
import UserNotifications

// MARK: - Server Response Models

/// Response from /notifications/history endpoint (v6.50.0)
struct NotificationHistoryResponse: Decodable {
    let notifications: [ServerNotification]
    let total: Int
    let hasMore: Bool
    let unreadCount: Int

    enum CodingKeys: String, CodingKey {
        case notifications, total
        case hasMore = "has_more"
        case unreadCount = "unread_count"
    }
}

/// Individual notification from server (v6.50.0)
struct ServerNotification: Decodable {
    let id: Int
    let notificationType: String
    let title: String
    let body: String
    let listingId: String?
    let listingKey: String?
    let imageUrl: String?
    let savedSearchId: Int?
    let savedSearchName: String?
    let appointmentId: Int?
    let clientId: Int?
    let sentAt: Date
    let isRead: Bool
    let readAt: Date?
    let isDismissed: Bool
    let dismissedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case notificationType = "notification_type"
        case title, body
        case listingId = "listing_id"
        case listingKey = "listing_key"
        case imageUrl = "image_url"
        case savedSearchId = "saved_search_id"
        case savedSearchName = "saved_search_name"
        case appointmentId = "appointment_id"
        case clientId = "client_id"
        case sentAt = "sent_at"
        case isRead = "is_read"
        case readAt = "read_at"
        case isDismissed = "is_dismissed"
        case dismissedAt = "dismissed_at"
    }

    /// Convert to NotificationItem for local storage
    func toNotificationItem() -> NotificationItem {
        let type: NotificationItem.NotificationType
        switch notificationType {
        case "new_listing", "exclusive_listing":
            // exclusive_listing: Agent created a new exclusive listing, notify their clients
            type = .newListing
        case "price_change":
            type = .priceChange
        case "status_change":
            type = .statusChange
        case "open_house":
            type = .openHouse
        case "saved_search":
            type = .savedSearch
        case "appointment_reminder", "tour_requested":
            type = .appointmentReminder
        case "agent_activity", "client_login":
            type = .agentActivity
        case "open_house_signin":
            // v6.69.0: Agent notified when visitor signs in at open house
            type = .agentActivity
        default:
            // LOG UNKNOWN TYPE for monitoring - helps identify new server types that need iOS support
            #if DEBUG
            debugLog("⚠️ NotificationStore: Unknown notification type '\(notificationType)' - treating as general (no deep linking)")
            #endif
            type = .general
        }

        // Validate navigation data for property-related notifications
        let propertyTypes: [NotificationItem.NotificationType] = [.newListing, .priceChange, .statusChange, .openHouse]
        if propertyTypes.contains(type) {
            if listingId == nil && listingKey == nil {
                #if DEBUG
                debugLog("⚠️ NotificationStore: Property notification '\(notificationType)' (id: \(id)) missing listing_id and listing_key - deep linking will fail")
                #endif
            }
        }

        // Validate navigation data for saved search notifications
        if type == .savedSearch && savedSearchId == nil {
            #if DEBUG
            debugLog("⚠️ NotificationStore: Saved search notification (id: \(id)) missing saved_search_id - deep linking will fail")
            #endif
        }

        // Validate navigation data for appointment notifications
        if type == .appointmentReminder && appointmentId == nil {
            #if DEBUG
            debugLog("⚠️ NotificationStore: Appointment notification (id: \(id)) missing appointment_id - navigation will fail")
            #endif
        }

        // Validate navigation data for agent activity notifications
        if type == .agentActivity && clientId == nil && listingId == nil && listingKey == nil {
            #if DEBUG
            debugLog("⚠️ NotificationStore: Agent activity notification (id: \(id)) missing client_id and listing data - navigation will fail")
            #endif
        }

        // Use server ID as the notification ID to avoid duplicates
        return NotificationItem(
            id: "server_\(id)",
            title: title,
            body: body,
            type: type,
            receivedAt: sentAt,
            isRead: isRead,
            isDismissed: isDismissed,
            serverId: id,
            readAt: readAt,
            dismissedAt: dismissedAt,
            savedSearchId: savedSearchId,
            appointmentId: appointmentId,
            clientId: clientId,
            listingId: listingId,
            listingKey: listingKey,
            listingCount: nil,
            searchName: savedSearchName
        )
    }
}

/// Response from mark read/dismiss endpoints (v6.50.0)
struct NotificationActionResponse: Decodable {
    let id: Int
    let isRead: Bool?
    let isDismissed: Bool?
    let readAt: Date?
    let dismissedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case isRead = "is_read"
        case isDismissed = "is_dismissed"
        case readAt = "read_at"
        case dismissedAt = "dismissed_at"
    }
}

/// Response from mark all read endpoint (v6.50.0)
struct MarkAllReadResponse: Decodable {
    let updated: Int
    let unreadCount: Int

    enum CodingKeys: String, CodingKey {
        case updated
        case unreadCount = "unread_count"
    }
}

/// Response from dismiss all endpoint (v6.50.3)
struct DismissAllResponse: Decodable {
    let dismissedCount: Int

    enum CodingKeys: String, CodingKey {
        case dismissedCount = "dismissed_count"
    }
}

@MainActor
class NotificationStore: ObservableObject {
    static let shared = NotificationStore()

    @Published var notifications: [NotificationItem] = []
    @Published var unreadCount: Int = 0
    @Published var isSyncing: Bool = false
    @Published var syncError: String?  // For debugging sync issues

    // Pending navigation from push notification tap
    @Published var pendingPropertyListingId: String?
    @Published var pendingPropertyListingKey: String?
    @Published var pendingAppointmentId: Int?
    @Published var pendingClientId: Int?

    private let storageKey = "com.bmnboston.notifications"
    private let pendingAppointmentKey = "com.bmnboston.pendingAppointmentId"
    private let pendingClientKey = "com.bmnboston.pendingClientId"
    private let lastSyncKey = "com.bmnboston.notifications.lastSync"

    /// Maximum notifications to keep in local storage.
    /// 100 provides ~2 weeks of history for most users while keeping storage reasonable.
    /// Older notifications are pruned when this limit is exceeded.
    private let maxNotifications = 100

    /// Minimum time between server syncs to prevent rapid duplicate requests.
    /// 10 seconds prevents multiple syncs when app launch triggers multiple
    /// lifecycle events (scenePhase change, auth state change, view appear).
    /// See v218 release notes for the duplicate sync bug this fixes.
    private let minimumSyncInterval: TimeInterval = 10.0

    private var lastSyncStartTime: Date?

    private init() {
        loadNotifications()
        loadPendingNavigation()
    }

    /// Load any persisted pending navigation from UserDefaults (for cold launch)
    private func loadPendingNavigation() {
        if let pendingAppointment = UserDefaults.standard.object(forKey: pendingAppointmentKey) as? Int {
            self.pendingAppointmentId = pendingAppointment
            #if DEBUG
            debugLog("🔔 NotificationStore: Loaded pending appointment navigation - appointmentId: \(pendingAppointment)")
            #endif
        }
        if let pendingClient = UserDefaults.standard.object(forKey: pendingClientKey) as? Int {
            self.pendingClientId = pendingClient
            #if DEBUG
            debugLog("🔔 NotificationStore: Loaded pending client navigation - clientId: \(pendingClient)")
            #endif
        }
    }

    /// Set pending property navigation (called from AppDelegate when notification is tapped)
    func setPendingPropertyNavigation(listingId: String?, listingKey: String?) {
        #if DEBUG
        debugLog("🔔 NotificationStore: setPendingPropertyNavigation - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
        #endif
        self.pendingPropertyListingId = listingId
        self.pendingPropertyListingKey = listingKey
    }

    /// Clear pending navigation after it's been handled
    func clearPendingPropertyNavigation() {
        #if DEBUG
        debugLog("🔔 NotificationStore: clearPendingPropertyNavigation")
        #endif
        self.pendingPropertyListingId = nil
        self.pendingPropertyListingKey = nil
    }

    /// Set pending appointment navigation (called from AppDelegate when notification is tapped)
    /// Also persists to UserDefaults for cold launch support
    func setPendingAppointmentNavigation(appointmentId: Int?) {
        #if DEBUG
        debugLog("🔔 NotificationStore: setPendingAppointmentNavigation - appointmentId: \(appointmentId ?? -1)")
        #endif
        self.pendingAppointmentId = appointmentId
        if let id = appointmentId {
            UserDefaults.standard.set(id, forKey: pendingAppointmentKey)
        } else {
            UserDefaults.standard.removeObject(forKey: pendingAppointmentKey)
        }
    }

    /// Clear pending appointment navigation after it's been handled
    func clearPendingAppointmentNavigation() {
        #if DEBUG
        debugLog("🔔 NotificationStore: clearPendingAppointmentNavigation")
        #endif
        self.pendingAppointmentId = nil
        UserDefaults.standard.removeObject(forKey: pendingAppointmentKey)
    }

    /// Set pending client navigation (called from AppDelegate when notification is tapped)
    /// Also persists to UserDefaults for cold launch support
    func setPendingClientNavigation(clientId: Int?) {
        #if DEBUG
        debugLog("🔔 NotificationStore: setPendingClientNavigation - clientId: \(clientId ?? -1)")
        #endif
        self.pendingClientId = clientId
        if let id = clientId {
            UserDefaults.standard.set(id, forKey: pendingClientKey)
        } else {
            UserDefaults.standard.removeObject(forKey: pendingClientKey)
        }
    }

    /// Clear pending client navigation after it's been handled
    func clearPendingClientNavigation() {
        #if DEBUG
        debugLog("🔔 NotificationStore: clearPendingClientNavigation")
        #endif
        self.pendingClientId = nil
        UserDefaults.standard.removeObject(forKey: pendingClientKey)
    }

    // MARK: - Public Methods

    /// Add a new notification
    func add(_ notification: NotificationItem) {
        var updated = notifications
        updated.insert(notification, at: 0)

        // Trim to max count
        if updated.count > maxNotifications {
            updated = Array(updated.prefix(maxNotifications))
        }

        notifications = updated
        updateUnreadCount()
        saveNotifications()
        updateBadge()
    }

    /// Mark a notification as read (v6.50.0 - server-driven)
    /// Optimistic UI update with server sync
    func markAsRead(_ notification: NotificationItem) async {
        guard let index = notifications.firstIndex(where: { $0.id == notification.id }) else { return }

        // Optimistic update
        notifications[index].isRead = true
        notifications[index].readAt = Date()
        updateUnreadCount()
        saveNotifications()
        updateBadge()

        // Sync to server if we have a server ID
        if let serverId = notification.serverId {
            do {
                let _: NotificationActionResponse = try await APIClient.shared.request(
                    .markNotificationRead(id: serverId)
                )
                #if DEBUG
                debugLog("🔔 NotificationStore: Marked notification \(serverId) as read on server")
                #endif
            } catch {
                #if DEBUG
                debugLog("🔔 NotificationStore: Failed to mark as read on server: \(error)")
                #endif
                // Don't revert - local state takes precedence for UX
                // Server will sync on next app launch
            }
        }
    }

    /// Mark all notifications as read (v6.50.0 - server-driven)
    /// Optimistic UI update with server sync
    func markAllAsRead() async {
        // Optimistic update
        let now = Date()
        for index in notifications.indices {
            notifications[index].isRead = true
            notifications[index].readAt = now
        }
        updateUnreadCount()
        saveNotifications()
        updateBadge()

        // Sync to server
        do {
            let _: MarkAllReadResponse = try await APIClient.shared.request(.markAllNotificationsRead)
            #if DEBUG
            debugLog("🔔 NotificationStore: Marked all notifications as read on server")
            #endif
        } catch {
            #if DEBUG
            debugLog("🔔 NotificationStore: Failed to mark all as read on server: \(error)")
            #endif
            // Don't revert - local state takes precedence for UX
        }
    }

    /// Dismiss a notification (v6.50.0 - server-driven)
    /// Optimistic UI update with server sync
    func dismiss(_ notification: NotificationItem) async {
        // Optimistic update - remove from list
        let previousNotifications = notifications
        notifications.removeAll { $0.id == notification.id }
        updateUnreadCount()
        saveNotifications()
        updateBadge()

        // Sync to server if we have a server ID
        if let serverId = notification.serverId {
            do {
                let _: NotificationActionResponse = try await APIClient.shared.request(
                    .dismissNotification(id: serverId)
                )
                #if DEBUG
                debugLog("🔔 NotificationStore: Dismissed notification \(serverId) on server")
                #endif
            } catch {
                #if DEBUG
                debugLog("🔔 NotificationStore: Failed to dismiss on server: \(error)")
                #endif
                // Revert on failure - dismissal is more critical
                notifications = previousNotifications
                updateUnreadCount()
                saveNotifications()
                updateBadge()
            }
        }
    }

    /// Delete a specific notification (local only - for backwards compatibility)
    /// Use dismiss() for server-synced removal
    func delete(_ notification: NotificationItem) {
        notifications.removeAll { $0.id == notification.id }
        updateUnreadCount()
        saveNotifications()
        updateBadge()
    }

    /// Dismiss all notifications (v6.50.3 - server-driven)
    /// Marks all notifications as dismissed on server and clears local cache
    func clearAll() async {
        // Store previous state for potential rollback
        let previousNotifications = notifications
        let previousUnreadCount = unreadCount

        // Optimistic update - clear locally immediately
        notifications.removeAll()
        unreadCount = 0
        saveNotifications()
        updateBadge()

        // Sync to server
        do {
            let _: DismissAllResponse = try await APIClient.shared.request(.dismissAllNotifications)
            #if DEBUG
            debugLog("🔔 NotificationStore: Dismissed all notifications on server")
            #endif
        } catch {
            #if DEBUG
            debugLog("🔔 NotificationStore: Failed to dismiss all on server: \(error)")
            #endif
            // Revert on failure - restore previous state
            notifications = previousNotifications
            unreadCount = previousUnreadCount
            saveNotifications()
            updateBadge()
        }
    }

    /// Delete notifications older than specified days
    func deleteOlderThan(days: Int) {
        let cutoffDate = Calendar.current.date(byAdding: .day, value: -days, to: Date()) ?? Date()
        notifications.removeAll { $0.receivedAt < cutoffDate }
        updateUnreadCount()
        saveNotifications()
        updateBadge()
    }

    // MARK: - Persistence

    private func loadNotifications() {
        guard let data = UserDefaults.standard.data(forKey: storageKey) else {
            // No stored notifications - sync badge to 0
            updateBadge()
            return
        }

        do {
            notifications = try JSONDecoder().decode([NotificationItem].self, from: data)
            updateUnreadCount()
            // Sync badge with actual unread count (fixes mismatch after app update)
            updateBadge()
        } catch {
            // Log decoding failure - this indicates data corruption or model changes
            #if DEBUG
            debugLog("🔔 NotificationStore: Failed to load notifications: \(error.localizedDescription)")
            if let decodingError = error as? DecodingError {
                debugLog("🔔 NotificationStore: Decoding error details: \(decodingError)")
            }
            #endif
            // Clear corrupted data and start fresh
            UserDefaults.standard.removeObject(forKey: storageKey)
            notifications = []
            updateBadge()
        }
    }

    private func saveNotifications() {
        do {
            let data = try JSONEncoder().encode(notifications)
            UserDefaults.standard.set(data, forKey: storageKey)
        } catch {
            // IMPORTANT: Log encoding failure - this indicates a bug in NotificationItem model
            #if DEBUG
            debugLog("🔔 NotificationStore: CRITICAL - Failed to save notifications: \(error.localizedDescription)")
            debugLog("🔔 NotificationStore: Notification count: \(notifications.count)")
            if let encodingError = error as? EncodingError {
                debugLog("🔔 NotificationStore: Encoding error details: \(encodingError)")
            }
            #endif
            // Note: Notifications will be lost on app restart if encoding consistently fails
            // This is a serious bug that needs investigation if it occurs
        }
    }

    private func updateUnreadCount() {
        unreadCount = notifications.filter { !$0.isRead }.count
    }

    // MARK: - Badge Management

    private func updateBadge() {
        UNUserNotificationCenter.current().setBadgeCount(unreadCount)
    }

    // MARK: - Server Sync (v6.50.0 - Server is source of truth)

    /// Sync notification history from server
    /// Server is the source of truth for notification state
    /// Call this on app launch and when user opens notification center
    func syncFromServer() async {
        // Clear previous error
        await MainActor.run { syncError = nil }

        // Check if user is authenticated
        guard await TokenManager.shared.isAuthenticated() else {
            #if DEBUG
            debugLog("🔔 NotificationStore: Skipping sync - user not authenticated")
            #endif
            await MainActor.run { syncError = "Not authenticated" }
            return
        }

        // v218: Throttle - skip if synced recently (prevents duplicate syncs on login)
        if let lastSync = lastSyncStartTime,
           Date().timeIntervalSince(lastSync) < minimumSyncInterval {
            #if DEBUG
            debugLog("🔔 NotificationStore: Skipping sync - throttled (\(String(format: "%.1f", Date().timeIntervalSince(lastSync)))s since last)")
            #endif
            return
        }

        // Prevent concurrent syncs
        guard !isSyncing else {
            #if DEBUG
            debugLog("🔔 NotificationStore: Sync already in progress")
            #endif
            return
        }

        await MainActor.run { isSyncing = true }
        lastSyncStartTime = Date()

        do {
            #if DEBUG
            debugLog("🔔 NotificationStore: Starting server sync...")
            #endif

            // Fetch notification history from server
            let response: NotificationHistoryResponse = try await APIClient.shared.request(
                .notificationHistory(limit: 100)
            )

            #if DEBUG
            debugLog("🔔 NotificationStore: Received \(response.notifications.count) notifications from server (total: \(response.total), unread: \(response.unreadCount))")
            #endif

            // Convert server notifications to NotificationItems
            // Server is source of truth - these include correct is_read/is_dismissed state
            let serverNotifications = response.notifications.map { $0.toNotificationItem() }

            // Merge with recent push notifications that may not be on server yet
            await mergeNotifications(serverNotifications, serverUnreadCount: response.unreadCount)

            // Save last sync time
            UserDefaults.standard.set(Date(), forKey: lastSyncKey)

            #if DEBUG
            debugLog("🔔 NotificationStore: Sync complete, total notifications: \(notifications.count), unread: \(unreadCount)")
            #endif

        } catch {
            let errorMessage = error.userFriendlyMessage
            await MainActor.run { syncError = errorMessage }
            #if DEBUG
            debugLog("🔔 NotificationStore: Sync failed - \(error.userFriendlyMessage)")
            debugLog("🔔 NotificationStore: Full error: \(error)")
            if let apiError = error as? APIError {
                debugLog("🔔 NotificationStore: API Error: \(apiError)")
            }
            #endif
        }

        await MainActor.run { isSyncing = false }
    }

    /// Merge server notifications with local notifications (v6.50.0)
    /// Server is source of truth - server notifications take precedence
    private func mergeNotifications(_ serverNotifications: [NotificationItem], serverUnreadCount: Int) async {
        await MainActor.run {
            // Build a set of server notification IDs for comparison
            let serverIds = Set(serverNotifications.map { $0.id })

            // Keep only local notifications that don't have a server counterpart
            // (recently received push notifications that aren't synced to server yet)
            var localOnlyNotifications: [NotificationItem] = []
            for notification in notifications {
                // Keep if no serverId (local-only) and not in server response
                if notification.serverId == nil && !serverIds.contains(notification.id) {
                    // Also check by content to avoid duplicates
                    let contentKey = "\(notification.title)_\(notification.body)"
                    let existsInServer = serverNotifications.contains {
                        "\($0.title)_\($0.body)" == contentKey
                    }
                    if !existsInServer {
                        localOnlyNotifications.append(notification)
                    }
                }
            }

            // Combine server notifications (source of truth) with local-only notifications
            var combined = serverNotifications + localOnlyNotifications

            // Sort by date
            combined.sort { $0.receivedAt > $1.receivedAt }

            // Trim to max count
            if combined.count > maxNotifications {
                combined = Array(combined.prefix(maxNotifications))
            }

            #if DEBUG
            debugLog("🔔 NotificationStore: Merged \(serverNotifications.count) server + \(localOnlyNotifications.count) local-only = \(combined.count) total")
            #endif

            // Update notifications
            notifications = combined

            // Use server's unread count as source of truth, plus any unread local-only notifications
            let localOnlyUnread = localOnlyNotifications.filter { !$0.isRead }.count
            unreadCount = serverUnreadCount + localOnlyUnread

            saveNotifications()
            updateBadge()
        }
    }

    /// Get the last sync time
    var lastSyncTime: Date? {
        UserDefaults.standard.object(forKey: lastSyncKey) as? Date
    }

    // MARK: - Filtering

    func notifications(ofType type: NotificationItem.NotificationType) -> [NotificationItem] {
        notifications.filter { $0.type == type }
    }

    var unreadNotifications: [NotificationItem] {
        notifications.filter { !$0.isRead }
    }

    var todayNotifications: [NotificationItem] {
        let calendar = Calendar.current
        return notifications.filter { calendar.isDateInToday($0.receivedAt) }
    }

    var earlierNotifications: [NotificationItem] {
        let calendar = Calendar.current
        return notifications.filter { !calendar.isDateInToday($0.receivedAt) }
    }
}
