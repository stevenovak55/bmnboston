//
//  SharedProperty.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 3: Property Sharing - Agent to Client
//

import Foundation

// MARK: - Shared Property Model

/// Represents a property shared by an agent with a client
struct SharedProperty: Identifiable, Codable, Equatable {
    let id: Int
    let listingKey: String
    let listingId: String?
    let agentNote: String?
    let sharedAt: Date
    let viewedAt: Date?
    let viewCount: Int
    let clientResponse: ClientResponse
    let clientNote: String?
    let isDismissed: Bool
    let agent: SharedPropertyAgent?
    let property: SharedPropertyData?

    enum CodingKeys: String, CodingKey {
        case id
        case listingKey = "listing_key"
        case listingId = "listing_id"
        case agentNote = "agent_note"
        case sharedAt = "shared_at"
        case viewedAt = "viewed_at"
        case viewCount = "view_count"
        case clientResponse = "client_response"
        case clientNote = "client_note"
        case isDismissed = "is_dismissed"
        case agent
        case property
    }

    /// Formatted shared date for display
    var formattedSharedAt: String {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        formatter.timeStyle = .short
        return formatter.string(from: sharedAt)
    }

    /// Relative time string (e.g., "2 hours ago")
    var relativeSharedAt: String {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .abbreviated
        return formatter.localizedString(for: sharedAt, relativeTo: Date())
    }

    /// Check if property has been viewed by client
    var hasBeenViewed: Bool {
        viewedAt != nil || viewCount > 0
    }

    /// Check if client has responded
    var hasClientResponse: Bool {
        clientResponse != .none
    }
}

// MARK: - Client Response Enum

/// Client's response to a shared property
enum ClientResponse: String, Codable {
    case none = "none"
    case interested = "interested"
    case notInterested = "not_interested"

    var displayName: String {
        switch self {
        case .none: return "No Response"
        case .interested: return "Interested"
        case .notInterested: return "Not Interested"
        }
    }

    var icon: String {
        switch self {
        case .none: return "questionmark.circle"
        case .interested: return "heart.fill"
        case .notInterested: return "xmark.circle"
        }
    }
}

// MARK: - Shared Property Agent (simplified agent for embedding)

/// Simplified agent info embedded in shared property response
struct SharedPropertyAgent: Codable, Equatable {
    let id: Int
    let name: String
    let email: String?
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

// MARK: - Shared Property Data (simplified property for embedding)

/// Simplified property info embedded in shared property response
struct SharedPropertyData: Codable, Equatable {
    let listingKey: String
    let listingId: String
    let address: String?
    let city: String?
    let state: String?
    let zipCode: String?
    let listPrice: Int?
    let beds: Int?
    let baths: Double?
    let sqft: Int?
    let photoUrl: String?
    let status: String?
    let propertyType: String?

    enum CodingKeys: String, CodingKey {
        case listingKey = "listing_key"
        case listingId = "listing_id"
        case address
        case city
        case state
        case zipCode = "zip_code"
        case listPrice = "list_price"
        case beds
        case baths
        case sqft
        case photoUrl = "photo_url"
        case status
        case propertyType = "property_type"
    }

    /// Formatted address
    var fullAddress: String {
        var parts: [String] = []
        if let address = address { parts.append(address) }
        if let city = city { parts.append(city) }
        if let state = state { parts.append(state) }
        if let zip = zipCode { parts.append(zip) }
        return parts.joined(separator: ", ")
    }

    /// Short address (street only)
    var shortAddress: String {
        address ?? "Unknown Address"
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

    /// Formatted beds/baths
    var bedsAndBaths: String {
        var parts: [String] = []
        if let beds = beds { parts.append("\(beds) bd") }
        if let baths = baths { parts.append("\(Int(baths)) ba") }
        if let sqft = sqft { parts.append("\(sqft.formatted()) sqft") }
        return parts.joined(separator: " • ")
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let photoUrl = photoUrl, !photoUrl.isEmpty else { return nil }
        return URL(string: photoUrl)
    }
}

// MARK: - API Response Types

/// Response from GET /shared-properties (for clients)
struct SharedPropertiesListResponse: Decodable {
    let properties: [SharedProperty]?
    let count: Int?

    // Handle different response formats
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Try to decode as array first (common format)
        if let propertiesArray = try? container.decode([SharedProperty].self, forKey: .properties) {
            self.properties = propertiesArray
            self.count = propertiesArray.count
        } else {
            self.properties = nil
            self.count = try? container.decode(Int.self, forKey: .count)
        }
    }

    enum CodingKeys: String, CodingKey {
        case properties
        case count
    }
}

/// Response from GET /agent/shared-properties (for agents)
struct AgentSharedPropertiesResponse: Decodable {
    let shares: [AgentShareRecord]
    let count: Int
}

/// Record of a property shared by the agent
struct AgentShareRecord: Identifiable, Codable, Equatable {
    let id: Int
    let clientId: Int
    let clientName: String?
    let clientEmail: String?
    let listingKey: String
    let listingId: String?
    let agentNote: String?
    let sharedAt: Date
    let viewedAt: Date?
    let viewCount: Int
    let clientResponse: ClientResponse
    let clientNote: String?
    let property: SharedPropertyData?

    enum CodingKeys: String, CodingKey {
        case id
        case clientId = "client_id"
        case clientName = "client_name"
        case clientEmail = "client_email"
        case listingKey = "listing_key"
        case listingId = "listing_id"
        case agentNote = "agent_note"
        case sharedAt = "shared_at"
        case viewedAt = "viewed_at"
        case viewCount = "view_count"
        case clientResponse = "client_response"
        case clientNote = "client_note"
        case property
    }

    /// Display name for the client
    var clientDisplayName: String {
        clientName ?? clientEmail ?? "Unknown Client"
    }

    /// Formatted shared date
    var formattedSharedAt: String {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        formatter.timeStyle = .short
        return formatter.string(from: sharedAt)
    }
}

// MARK: - Share Request Types

/// Request to share properties with clients
struct SharePropertiesRequest {
    let clientIds: [Int]
    let listingKeys: [String]
    let note: String?

    /// Convert to API parameters
    func toParameters() -> [String: Any] {
        var params: [String: Any] = [
            "client_ids": clientIds,
            "listing_keys": listingKeys
        ]
        if let note = note, !note.isEmpty {
            params["note"] = note
        }
        return params
    }
}

/// Response from POST /shared-properties
struct SharePropertiesResponse: Decodable {
    let sharedCount: Int
    let shares: [ShareCreated]?
    let notificationsSent: NotificationsSent?

    enum CodingKeys: String, CodingKey {
        case sharedCount = "shared_count"
        case shares
        case notificationsSent = "notifications_sent"
    }
}

/// Individual share record from create response
struct ShareCreated: Codable, Identifiable {
    let id: Int
    let clientId: Int
    let listingKey: String

    enum CodingKeys: String, CodingKey {
        case id
        case clientId = "client_id"
        case listingKey = "listing_key"
    }
}

/// Notifications sent summary
struct NotificationsSent: Codable {
    let push: Int?
    let email: Int?
}

// MARK: - Update Response

/// Response from PUT /shared-properties/{id} (client updating response)
struct UpdateSharedPropertyResponse: Decodable {
    let id: Int
    let clientResponse: ClientResponse
    let clientNote: String?
    let updatedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case clientResponse = "client_response"
        case clientNote = "client_note"
        case updatedAt = "updated_at"
    }
}
