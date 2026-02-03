//
//  Agent.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Phase 5: Agent-Client Collaboration System
//

import Foundation

// MARK: - Agent Model

/// Represents an agent in the system
struct Agent: Identifiable, Codable, Equatable {
    let id: Int
    let userId: Int?  // Optional - may not be in API response
    let name: String
    let email: String
    let phone: String?
    let title: String?
    let photoUrl: String?
    let officeName: String?
    let bio: String?
    let mlsAgentId: String?  // MLS Agent ID for ShowingTime integration
    let snabStaffId: Int?
    let canBookAppointment: Bool?  // From API

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case name
        case email
        case phone
        case title
        case photoUrl = "photo_url"
        case officeName = "office_name"
        case bio
        case mlsAgentId = "mls_agent_id"
        case snabStaffId = "snab_staff_id"
        case canBookAppointment = "can_book_appointment"
    }

    /// Get display name (name or email if no name)
    var displayName: String {
        name.isEmpty ? email : name
    }

    /// Get first name only
    var firstName: String {
        name.components(separatedBy: " ").first ?? name
    }

    /// Get initials for avatar placeholder
    var initials: String {
        let parts = name.components(separatedBy: " ")
        if parts.count >= 2, let first = parts.first?.first, let last = parts.last?.first {
            return "\(first)\(last)".uppercased()
        }
        return String(name.prefix(2)).uppercased()
    }

    /// Formatted phone for tel: links
    var formattedPhoneLink: String? {
        guard let phone = phone else { return nil }
        return phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }

    /// Check if agent has booking capability
    var canBookShowings: Bool {
        canBookAppointment == true || snabStaffId != nil
    }
}

// MARK: - Agent List Response

struct AgentListResponse: Decodable {
    let agents: [Agent]
    let count: Int
}

// MARK: - My Agent Response

/// Response from /my-agent endpoint (client's assigned agent)
struct MyAgentResponse: Decodable {
    let agent: Agent?
    let assignedAt: Date?
    let assignedBy: Int?
    let notes: String?

    enum CodingKeys: String, CodingKey {
        case agent
        case assignedAt = "assigned_at"
        case assignedBy = "assigned_by"
        case notes
    }
}

// MARK: - Agent Summary (for embedding in User)

/// Lightweight agent reference embedded in User response
struct AgentSummary: Codable, Equatable {
    let id: Int
    let name: String
    let email: String
    let phone: String?
    let photoUrl: String?

    enum CodingKeys: String, CodingKey {
        case id
        case name
        case email
        case phone
        case photoUrl = "photo_url"
    }

    /// Get initials for avatar placeholder
    var initials: String {
        let parts = name.components(separatedBy: " ")
        if parts.count >= 2, let first = parts.first?.first, let last = parts.last?.first {
            return "\(first)\(last)".uppercased()
        }
        return String(name.prefix(2)).uppercased()
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }
}

// MARK: - User Type

/// User type in the system
enum UserType: String, Codable {
    case client = "client"
    case agent = "agent"
    case admin = "admin"

    var displayName: String {
        switch self {
        case .client: return "Client"
        case .agent: return "Agent"
        case .admin: return "Admin"
        }
    }

    var isAgent: Bool {
        self == .agent || self == .admin
    }
}

// MARK: - Agent Client (for agent's client management)

/// Represents a client from the agent's perspective
struct AgentClient: Identifiable, Codable, Equatable {
    let id: Int
    let email: String
    let firstName: String?
    let lastName: String?
    let phone: String?
    let searchesCount: Int
    let favoritesCount: Int
    let hiddenCount: Int
    let lastActivity: Date?
    let assignedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case email
        case firstName = "first_name"
        case lastName = "last_name"
        case phone
        case searchesCount = "searches_count"
        case favoritesCount = "favorites_count"
        case hiddenCount = "hidden_count"
        case lastActivity = "last_activity"
        case assignedAt = "assigned_at"
    }

    /// Full name or email if no name
    var displayName: String {
        let name = [firstName, lastName].compactMap { $0 }.joined(separator: " ")
        return name.isEmpty ? email : name
    }

    /// Get initials for avatar placeholder
    var initials: String {
        if let first = firstName?.first {
            if let last = lastName?.first {
                return "\(first)\(last)".uppercased()
            }
            return String(first).uppercased()
        }
        return String(email.prefix(1)).uppercased()
    }

    /// Formatted phone for tel: links
    var formattedPhoneLink: String? {
        guard let phone = phone, !phone.isEmpty else { return nil }
        return phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
    }

    /// Total engagement count
    var totalEngagement: Int {
        searchesCount + favoritesCount
    }
}

// MARK: - Agent Client List Response

struct AgentClientListResponse: Decodable {
    let clients: [AgentClient]
    let count: Int
}

// MARK: - Agent Client Detail Response

struct AgentClientDetailResponse: Decodable {
    let client: AgentClient
    let searches: [ClientSavedSearch]?
    let favorites: [ClientFavorite]?
    let hidden: [ClientHiddenProperty]?
}

// MARK: - Client Saved Search (simplified for agent view)

struct ClientSavedSearch: Identifiable, Codable {
    let id: Int
    let name: String
    let filters: [String: AnyCodableValue]?
    let notificationFrequency: String?
    let isActive: Bool
    let lastMatchedCount: Int?
    let createdAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case name
        case filters
        case notificationFrequency = "notification_frequency"
        case isActive = "is_active"
        case lastMatchedCount = "last_matched_count"
        case createdAt = "created_at"
    }

    /// Filter summary for display
    var filterSummary: String {
        guard let filters = filters else { return "All properties" }
        var parts: [String] = []

        if let city = filters["city"]?.stringValue ?? filters["City"]?.stringValue {
            parts.append(city)
        }
        if let minPrice = filters["min_price"]?.intValue ?? filters["price_min"]?.intValue,
           let maxPrice = filters["max_price"]?.intValue ?? filters["price_max"]?.intValue {
            parts.append("$\(minPrice/1000)K - $\(maxPrice/1000)K")
        }
        if let beds = filters["beds"]?.intValue ?? filters["min_beds"]?.intValue {
            parts.append("\(beds)+ beds")
        }

        return parts.isEmpty ? "All properties" : parts.joined(separator: " • ")
    }
}

// MARK: - Client Favorite (simplified for agent view)

struct ClientFavorite: Identifiable, Codable {
    let id: String  // listing_key
    let listingKey: String
    let listingId: String?
    let address: String?
    let city: String?
    let listPrice: Int?
    let photoUrl: String?
    let beds: Int?
    let baths: Double?
    let addedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case listingKey = "listing_key"
        case listingId = "listing_id"
        case address
        case city
        case listPrice = "list_price"
        case photoUrl = "photo_url"
        case beds
        case baths
        case addedAt = "added_at"
    }

    /// Formatted price
    var formattedPrice: String {
        guard let price = listPrice else { return "" }
        if price >= 1_000_000 {
            return String(format: "$%.1fM", Double(price) / 1_000_000)
        } else {
            return "$\(price / 1000)K"
        }
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }
}

// MARK: - Client Hidden Property

struct ClientHiddenProperty: Identifiable, Codable {
    let id: String  // listing_key
    let listingKey: String
    let listingId: String?
    let address: String?
    let city: String?
    let listPrice: Int?
    let photoUrl: String?
    let beds: Int?
    let baths: Double?
    let hiddenAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case listingKey = "listing_key"
        case listingId = "listing_id"
        case address
        case city
        case listPrice = "list_price"
        case photoUrl = "photo_url"
        case beds
        case baths
        case hiddenAt = "hidden_at"
    }

    /// Formatted price
    var formattedPrice: String {
        guard let price = listPrice else { return "" }
        if price >= 1_000_000 {
            return String(format: "$%.1fM", Double(price) / 1_000_000)
        } else {
            return "$\(price / 1000)K"
        }
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }
}

// MARK: - Agent Metrics

struct AgentMetrics: Codable {
    let totalClients: Int
    let activeClients: Int
    let totalSearches: Int
    let totalFavorites: Int
    let totalHidden: Int
    let newClientsThisMonth: Int?
    let activeSearchesThisWeek: Int?

    enum CodingKeys: String, CodingKey {
        case totalClients = "total_clients"
        case activeClients = "active_clients"
        case totalSearches = "total_searches"
        case totalFavorites = "total_favorites"
        case totalHidden = "total_hidden"
        case newClientsThisMonth = "new_clients_this_month"
        case activeSearchesThisWeek = "active_searches_this_week"
    }
}

// MARK: - Create Client Response

struct CreateClientResponse: Decodable {
    let client: AgentClient
    let message: String?
}

// MARK: - Client Analytics (Sprint 5)

/// Analytics summary for a single client
struct ClientAnalytics: Codable, Identifiable {
    let userId: Int
    let periodDays: Int
    let totalSessions: Int
    let totalDurationMinutes: Double
    let propertiesViewed: Int
    let uniquePropertiesViewed: Int
    let searchesRun: Int
    let favoritesAdded: Int
    let engagementScore: Double
    let activityBreakdown: [String: Int]?
    let platformBreakdown: [String: Int]?
    let lastActivity: String?

    // Added by client analytics endpoint
    let email: String?
    let name: String?

    var id: Int { userId }

    enum CodingKeys: String, CodingKey {
        case userId = "user_id"
        case periodDays = "period_days"
        case totalSessions = "total_sessions"
        case totalDurationMinutes = "total_duration_minutes"
        case propertiesViewed = "properties_viewed"
        case uniquePropertiesViewed = "unique_properties_viewed"
        case searchesRun = "searches_run"
        case favoritesAdded = "favorites_added"
        case engagementScore = "engagement_score"
        case activityBreakdown = "activity_breakdown"
        case platformBreakdown = "platform_breakdown"
        case lastActivity = "last_activity"
        case email
        case name
    }

    /// Engagement level description
    var engagementLevel: String {
        switch engagementScore {
        case 80...: return "Very Active"
        case 60..<80: return "Active"
        case 40..<60: return "Moderate"
        case 20..<40: return "Low"
        default: return "Inactive"
        }
    }

    /// Engagement color for display
    var engagementColor: String {
        switch engagementScore {
        case 80...: return "green"
        case 60..<80: return "blue"
        case 40..<60: return "yellow"
        case 20..<40: return "orange"
        default: return "gray"
        }
    }

    /// Format duration as hours and minutes
    var formattedDuration: String {
        let hours = Int(totalDurationMinutes) / 60
        let minutes = Int(totalDurationMinutes) % 60
        if hours > 0 {
            return "\(hours)h \(minutes)m"
        }
        return "\(minutes)m"
    }
}

// MARK: - Client Activity Event

/// Single activity event from timeline
struct ClientActivityEvent: Codable, Identifiable {
    let id: Int
    let activityType: String
    let entityId: String?
    let entityType: String?
    let platform: String
    let createdAt: String
    let description: String
    let metadata: [String: AnyCodableValue]?

    enum CodingKeys: String, CodingKey {
        case id
        case activityType = "activity_type"
        case entityId = "entity_id"
        case entityType = "entity_type"
        case platform
        case createdAt = "created_at"
        case description
        case metadata
    }

    /// Icon for activity type
    var icon: String {
        switch activityType {
        case "property_view": return "eye.fill"
        case "property_share": return "square.and.arrow.up"
        case "search_run": return "magnifyingglass"
        case "filter_used": return "slider.horizontal.3"
        case "favorite_add": return "heart.fill"
        case "favorite_remove": return "heart.slash"
        case "hidden_add": return "eye.slash.fill"
        case "hidden_remove": return "eye.fill"
        case "search_save": return "bookmark.fill"
        case "login": return "person.fill"
        case "page_view": return "doc.text"
        default: return "circle.fill"
        }
    }

    /// Formatted time ago
    var timeAgo: String {
        guard let date = ISO8601DateFormatter().date(from: createdAt) else {
            return createdAt
        }
        let interval = Date().timeIntervalSince(date)

        if interval < 60 {
            return "Just now"
        } else if interval < 3600 {
            let minutes = Int(interval / 60)
            return "\(minutes)m ago"
        } else if interval < 86400 {
            let hours = Int(interval / 3600)
            return "\(hours)h ago"
        } else {
            let days = Int(interval / 86400)
            return "\(days)d ago"
        }
    }
}

// MARK: - Client Activity Timeline Response

struct ClientActivityTimelineResponse: Decodable {
    let activities: [ClientActivityEvent]
    let count: Int
    let offset: Int
    let limit: Int
}

// MARK: - Agent Analytics Dashboard Response

struct AgentAnalyticsDashboardResponse: Decodable {
    let clients: [ClientAnalytics]
    let totals: AgentAnalyticsTotals
    let periodDays: Int

    enum CodingKeys: String, CodingKey {
        case clients
        case totals
        case periodDays = "period_days"
    }
}

struct AgentAnalyticsTotals: Decodable {
    let totalSessions: Int
    let totalPropertiesViewed: Int
    let totalSearches: Int
    let activeClients: Int

    enum CodingKeys: String, CodingKey {
        case totalSessions = "total_sessions"
        case totalPropertiesViewed = "total_properties_viewed"
        case totalSearches = "total_searches"
        case activeClients = "active_clients"
    }
}

// MARK: - Site Contact Settings

/// Default site contact settings for users without an assigned agent
struct SiteContactSettings: Decodable {
    let name: String
    let phone: String?
    let email: String
    let photoUrl: String?
    let brokerageName: String?

    enum CodingKeys: String, CodingKey {
        case name
        case phone
        case email
        case photoUrl = "photo_url"
        case brokerageName = "brokerage_name"
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }

    /// Formatted phone for tel: links
    var formattedPhoneLink: String? {
        guard let phone = phone else { return nil }
        return phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
    }
}
