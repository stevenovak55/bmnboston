//
//  NotificationItem.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Notification Center - Data Model
//

import Foundation

/// Represents a stored push notification
struct NotificationItem: Identifiable, Codable, Equatable {
    let id: String
    let title: String
    let body: String
    let type: NotificationType
    let receivedAt: Date
    var isRead: Bool
    var isDismissed: Bool

    // Server tracking (v6.50.0)
    var serverId: Int?          // Server notification ID for API calls
    var readAt: Date?
    var dismissedAt: Date?

    // Deep linking data
    var savedSearchId: Int?
    var appointmentId: Int?
    var clientId: Int?
    var listingId: String?
    var listingKey: String?

    // Additional context
    var listingCount: Int?
    var searchName: String?

    enum NotificationType: String, Codable {
        case savedSearch = "saved_search"
        case appointmentReminder = "appointment_reminder"
        case agentActivity = "agent_activity"
        case newListing = "new_listing"
        case priceChange = "price_change"
        case statusChange = "status_change"
        case openHouse = "open_house"
        case general = "general"

        var icon: String {
            switch self {
            case .savedSearch: return "magnifyingglass"
            case .appointmentReminder: return "calendar"
            case .agentActivity: return "person.2"
            case .newListing: return "house"
            case .priceChange: return "tag"
            case .statusChange: return "arrow.triangle.swap"
            case .openHouse: return "door.left.hand.open"
            case .general: return "bell"
            }
        }

        var color: String {
            switch self {
            case .savedSearch: return "blue"
            case .appointmentReminder: return "orange"
            case .agentActivity: return "purple"
            case .newListing: return "green"
            case .priceChange: return "red"
            case .statusChange: return "yellow"
            case .openHouse: return "teal"
            case .general: return "gray"
            }
        }
    }

    init(
        id: String = UUID().uuidString,
        title: String,
        body: String,
        type: NotificationType = .general,
        receivedAt: Date = Date(),
        isRead: Bool = false,
        isDismissed: Bool = false,
        serverId: Int? = nil,
        readAt: Date? = nil,
        dismissedAt: Date? = nil,
        savedSearchId: Int? = nil,
        appointmentId: Int? = nil,
        clientId: Int? = nil,
        listingId: String? = nil,
        listingKey: String? = nil,
        listingCount: Int? = nil,
        searchName: String? = nil
    ) {
        self.id = id
        self.title = title
        self.body = body
        self.type = type
        self.receivedAt = receivedAt
        self.isRead = isRead
        self.isDismissed = isDismissed
        self.serverId = serverId
        self.readAt = readAt
        self.dismissedAt = dismissedAt
        self.savedSearchId = savedSearchId
        self.appointmentId = appointmentId
        self.clientId = clientId
        self.listingId = listingId
        self.listingKey = listingKey
        self.listingCount = listingCount
        self.searchName = searchName
    }

    /// Create from push notification userInfo
    static func from(userInfo: [AnyHashable: Any]) -> NotificationItem {
        let aps = userInfo["aps"] as? [String: Any]
        let alert = aps?["alert"] as? [String: Any]

        let title = alert?["title"] as? String ?? "Notification"
        let body = alert?["body"] as? String ?? ""

        // Determine type based on payload
        var type: NotificationType = .general
        var savedSearchId: Int?
        var appointmentId: Int?
        var clientId: Int?
        var listingId: String?
        var listingKey: String?
        var listingCount: Int?
        var searchName: String?

        // Check notification_type FIRST for property-specific notifications
        // These may also include saved_search_id but should be treated as property notifications
        if let notificationType = userInfo["notification_type"] as? String {
            switch notificationType {
            case "new_listing", "exclusive_listing":
                // exclusive_listing: Agent created a new exclusive listing, notify their clients
                type = .newListing
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
                savedSearchId = userInfo["saved_search_id"] as? Int
            case "price_change":
                type = .priceChange
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
                savedSearchId = userInfo["saved_search_id"] as? Int
            case "status_change":
                type = .statusChange
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
                savedSearchId = userInfo["saved_search_id"] as? Int
            case "open_house", "open_house_reminder":
                type = .openHouse
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
                savedSearchId = userInfo["saved_search_id"] as? Int
            case "client_activity":
                type = .agentActivity
                clientId = userInfo["client_id"] as? Int
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
            case "appointment_reminder":
                // Enhanced in v203 to support property deep linking
                type = .appointmentReminder
                appointmentId = userInfo["appointment_id"] as? Int
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
            default:
                // Fall through to check other payload keys below
                break
            }
        }

        // If notification_type didn't match a specific type, check other payload keys
        if type == .general {
            if let searchId = userInfo["saved_search_id"] as? Int {
                type = .savedSearch
                savedSearchId = searchId
                searchName = userInfo["search_name"] as? String
                listingCount = userInfo["listing_count"] as? Int
            } else if let apptId = userInfo["appointment_id"] as? Int {
                type = .appointmentReminder
                appointmentId = apptId
            } else if let cId = userInfo["client_id"] as? Int {
                type = .agentActivity
                clientId = cId
                listingId = Self.extractListingId(from: userInfo)
                listingKey = userInfo["listing_key"] as? String
            }
        }

        return NotificationItem(
            title: title,
            body: body,
            type: type,
            savedSearchId: savedSearchId,
            appointmentId: appointmentId,
            clientId: clientId,
            listingId: listingId,
            listingKey: listingKey,
            listingCount: listingCount,
            searchName: searchName
        )
    }

    var timeAgo: String {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .abbreviated
        return formatter.localizedString(for: receivedAt, relativeTo: Date())
    }

    /// Extract listing_id from userInfo, handling both String and Int types
    private static func extractListingId(from userInfo: [AnyHashable: Any]) -> String? {
        if let stringId = userInfo["listing_id"] as? String {
            return stringId
        } else if let intId = userInfo["listing_id"] as? Int {
            return String(intId)
        }
        return nil
    }
}
