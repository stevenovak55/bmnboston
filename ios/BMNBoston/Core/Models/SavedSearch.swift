//
//  SavedSearch.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation
import CoreLocation

// MARK: - SavedSearch Model

struct SavedSearch: Identifiable, Codable {
    let id: Int
    let name: String
    let description: String?
    let filters: [String: AnyCodableValue]
    let polygonShapes: [[PolygonPoint]]?
    let notificationFrequency: NotificationFrequency
    let isActive: Bool
    let matchCount: Int?
    let createdAt: Date
    let updatedAt: Date
    let lastNotifiedAt: Date?

    // Phase 5: Agent-Client Collaboration fields
    let createdByUserId: Int?
    let lastModifiedByUserId: Int?
    let lastModifiedAt: Date?
    let isAgentRecommended: Bool?
    let agentNotes: String?
    let ccAgentOnNotify: Bool?

    enum CodingKeys: String, CodingKey {
        case id, name, description, filters
        case polygonShapes = "polygon_shapes"
        case notificationFrequency = "notification_frequency"
        case isActive = "is_active"
        case matchCount = "match_count"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
        case lastNotifiedAt = "last_notified_at"
        // Collaboration fields
        case createdByUserId = "created_by_user_id"
        case lastModifiedByUserId = "last_modified_by_user_id"
        case lastModifiedAt = "last_modified_at"
        case isAgentRecommended = "is_agent_recommended"
        case agentNotes = "agent_notes"
        case ccAgentOnNotify = "cc_agent_on_notify"
    }

    /// Check if this search was created by an agent for the client
    var isAgentCreated: Bool {
        isAgentRecommended == true
    }

    /// Check if this search has agent notes
    var hasAgentNotes: Bool {
        if let notes = agentNotes, !notes.isEmpty {
            return true
        }
        return false
    }

    var formattedCreatedAt: String {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        return formatter.string(from: createdAt)
    }

    var formattedUpdatedAt: String {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        formatter.timeStyle = .short
        return formatter.string(from: updatedAt)
    }

    /// Generate a human-readable summary of the search filters
    var filterSummary: String {
        var parts: [String] = []

        // Cities (handle both iOS "city" and web "City" keys)
        if let cities = filters["city"]?.stringArrayValue ?? filters["City"]?.stringArrayValue, !cities.isEmpty {
            parts.append(cities.joined(separator: ", "))
        }

        // Property type (handle both iOS "property_type" and web "PropertyType" keys)
        if let types = filters["property_type"]?.stringArrayValue ?? filters["PropertyType"]?.stringArrayValue, !types.isEmpty {
            parts.append(types.first ?? "")
        }

        // Bedrooms
        if let beds = filters["beds"]?.intValue {
            parts.append("\(beds)+ beds")
        }

        // Price (handle both iOS and web keys)
        let maxPrice = filters["max_price"]?.intValue ?? filters["price_max"]?.intValue
        let minPrice = filters["min_price"]?.intValue ?? filters["price_min"]?.intValue

        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0

        if let max = maxPrice {
            if let formatted = formatter.string(from: NSNumber(value: max)) {
                parts.append("Under \(formatted)")
            }
        } else if let min = minPrice {
            if let formatted = formatter.string(from: NSNumber(value: min)) {
                parts.append("\(formatted)+")
            }
        }

        // School filters
        if filters["near_a_elementary"]?.boolValue == true {
            parts.append("A Elementary")
        } else if filters["near_ab_elementary"]?.boolValue == true {
            parts.append("A/B Elementary")
        }

        if filters["near_a_middle"]?.boolValue == true {
            parts.append("A Middle")
        } else if filters["near_ab_middle"]?.boolValue == true {
            parts.append("A/B Middle")
        }

        if filters["near_a_high"]?.boolValue == true {
            parts.append("A High")
        } else if filters["near_ab_high"]?.boolValue == true {
            parts.append("A/B High")
        }

        if let grade = filters["school_grade"]?.stringValue {
            parts.append("\(grade) District")
        }

        // Special filters
        if filters["price_reduced"]?.boolValue == true {
            parts.append("Price Reduced")
        }

        if filters["new_listing_days"]?.intValue != nil {
            parts.append("New Listing")
        }

        return parts.isEmpty ? "All properties" : parts.joined(separator: " • ")
    }

    /// Convert server filters to PropertySearchFilters for applying the search
    func toPropertySearchFilters() -> PropertySearchFilters {
        return PropertySearchFilters(fromServerJSON: filters)
    }

    /// Convert polygon shapes to coordinates for map display
    func toPolygonCoordinates() -> [[CLLocationCoordinate2D]] {
        guard let shapes = polygonShapes else { return [] }
        return shapes.map { shape in
            shape.map { $0.coordinate }
        }
    }

    /// Check if this saved search has multiple polygon shapes
    var hasMultiplePolygons: Bool {
        guard let shapes = polygonShapes else { return false }
        return shapes.count > 1
    }
}

// MARK: - PolygonPoint

struct PolygonPoint: Codable, Equatable {
    let lat: Double
    let lng: Double

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    init(lat: Double, lng: Double) {
        self.lat = lat
        self.lng = lng
    }

    init(coordinate: CLLocationCoordinate2D) {
        self.lat = coordinate.latitude
        self.lng = coordinate.longitude
    }
}

// MARK: - NotificationFrequency

enum NotificationFrequency: String, Codable, CaseIterable {
    case instant = "instant"
    case fifteenMin = "fifteen_min"
    case hourly = "hourly"
    case daily = "daily"
    case weekly = "weekly"
    case none = "none"

    var displayName: String {
        switch self {
        case .instant: return "Instant"
        case .fifteenMin: return "Every 15 min"
        case .hourly: return "Hourly"
        case .daily: return "Daily"
        case .weekly: return "Weekly"
        case .none: return "None"
        }
    }

    var shortDescription: String {
        switch self {
        case .instant: return "Get notified immediately when new listings match"
        case .fifteenMin: return "Check every 15 minutes for new matches"
        case .hourly: return "Get hourly digest of new matches"
        case .daily: return "Get a daily summary email"
        case .weekly: return "Get a weekly summary email"
        case .none: return "No email notifications"
        }
    }
}

// MARK: - AnyCodableValue (for flexible JSON handling)

enum AnyCodableValue: Codable, Equatable {
    case string(String)
    case int(Int)
    case double(Double)
    case bool(Bool)
    case array([AnyCodableValue])
    case dictionary([String: AnyCodableValue])
    case null

    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()

        if container.decodeNil() {
            self = .null
        } else if let bool = try? container.decode(Bool.self) {
            self = .bool(bool)
        } else if let int = try? container.decode(Int.self) {
            self = .int(int)
        } else if let double = try? container.decode(Double.self) {
            self = .double(double)
        } else if let string = try? container.decode(String.self) {
            self = .string(string)
        } else if let array = try? container.decode([AnyCodableValue].self) {
            self = .array(array)
        } else if let dict = try? container.decode([String: AnyCodableValue].self) {
            self = .dictionary(dict)
        } else {
            self = .null
        }
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .string(let value): try container.encode(value)
        case .int(let value): try container.encode(value)
        case .double(let value): try container.encode(value)
        case .bool(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .dictionary(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }

    // Convenience accessors
    var stringValue: String? {
        if case .string(let value) = self { return value }
        return nil
    }

    var intValue: Int? {
        if case .int(let value) = self { return value }
        if case .double(let value) = self { return Int(value) }
        // Handle string numbers (web often sends "2495" instead of 2495)
        if case .string(let value) = self { return Int(value) }
        return nil
    }

    var doubleValue: Double? {
        if case .double(let value) = self { return value }
        if case .int(let value) = self { return Double(value) }
        // Handle string numbers (web often sends "2495.5" instead of 2495.5)
        if case .string(let value) = self { return Double(value) }
        return nil
    }

    var boolValue: Bool? {
        if case .bool(let value) = self { return value }
        // Handle string booleans (web often sends "true" or "1")
        if case .string(let value) = self {
            return value == "true" || value == "1"
        }
        // Handle integer booleans (1 = true, 0 = false)
        if case .int(let value) = self { return value != 0 }
        return nil
    }

    var stringArrayValue: [String]? {
        if case .array(let values) = self {
            return values.compactMap { $0.stringValue }
        }
        if case .string(let value) = self {
            return [value]
        }
        return nil
    }

    var intArrayValue: [Int]? {
        if case .array(let values) = self {
            return values.compactMap { $0.intValue }
        }
        if case .int(let value) = self {
            return [value]
        }
        return nil
    }

    /// Initialize from an Any value (for converting dictionaries to AnyCodableValue)
    init(from value: Any) {
        if let string = value as? String {
            self = .string(string)
        } else if let int = value as? Int {
            self = .int(int)
        } else if let double = value as? Double {
            self = .double(double)
        } else if let bool = value as? Bool {
            self = .bool(bool)
        } else if let array = value as? [Any] {
            self = .array(array.map { AnyCodableValue(from: $0) })
        } else if let dict = value as? [String: Any] {
            self = .dictionary(dict.mapValues { AnyCodableValue(from: $0) })
        } else {
            self = .null
        }
    }

    /// Convert back to Any for serialization
    var rawValue: Any {
        switch self {
        case .string(let value): return value
        case .int(let value): return value
        case .double(let value): return value
        case .bool(let value): return value
        case .array(let value): return value.map { $0.rawValue }
        case .dictionary(let value): return value.mapValues { $0.rawValue }
        case .null: return NSNull()
        }
    }
}

// MARK: - API Request Types

struct CreateSavedSearchRequest: Encodable {
    let name: String
    let description: String?
    let filters: [String: Any]
    let polygonShapes: [[PolygonPoint]]?
    let notificationFrequency: NotificationFrequency

    enum CodingKeys: String, CodingKey {
        case name, description, filters
        case polygonShapes = "polygon_shapes"
        case notificationFrequency = "notification_frequency"
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(name, forKey: .name)
        try container.encodeIfPresent(description, forKey: .description)

        // Encode filters as JSON data then decode to encodable format
        if let jsonData = try? JSONSerialization.data(withJSONObject: filters),
           let encodableFilters = try? JSONDecoder().decode([String: AnyCodableValue].self, from: jsonData) {
            try container.encode(encodableFilters, forKey: .filters)
        }

        try container.encodeIfPresent(polygonShapes, forKey: .polygonShapes)
        try container.encode(notificationFrequency.rawValue, forKey: .notificationFrequency)
    }

    init(name: String, description: String?, filters: PropertySearchFilters, polygonShapes: [[PolygonPoint]]? = nil, notificationFrequency: NotificationFrequency) {
        self.name = name
        self.description = description
        // Use web-compatible keys for cross-platform compatibility
        self.filters = filters.toSavedSearchDictionary()
        self.polygonShapes = polygonShapes
        self.notificationFrequency = notificationFrequency
    }
}

struct UpdateSavedSearchRequest: Encodable {
    let name: String?
    let description: String?
    let filters: [String: Any]?
    let polygonShapes: [[PolygonPoint]]?
    let notificationFrequency: NotificationFrequency?
    let isActive: Bool?
    let updatedAt: Date

    enum CodingKeys: String, CodingKey {
        case name, description, filters
        case polygonShapes = "polygon_shapes"
        case notificationFrequency = "notification_frequency"
        case isActive = "is_active"
        case updatedAt = "updated_at"
    }

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encodeIfPresent(name, forKey: .name)
        try container.encodeIfPresent(description, forKey: .description)

        if let filters = filters {
            if let jsonData = try? JSONSerialization.data(withJSONObject: filters),
               let encodableFilters = try? JSONDecoder().decode([String: AnyCodableValue].self, from: jsonData) {
                try container.encode(encodableFilters, forKey: .filters)
            }
        }

        try container.encodeIfPresent(polygonShapes, forKey: .polygonShapes)

        if let freq = notificationFrequency {
            try container.encode(freq.rawValue, forKey: .notificationFrequency)
        }

        try container.encodeIfPresent(isActive, forKey: .isActive)

        let formatter = ISO8601DateFormatter()
        try container.encode(formatter.string(from: updatedAt), forKey: .updatedAt)
    }
}

// MARK: - API Response Types
// Note: These are the inner "data" types - APIResponse<T> handles the outer success/data wrapper

struct SavedSearchListResponse: Decodable {
    let searches: [SavedSearch]
    let count: Int
}

// For create/update, the API returns the SavedSearch directly as data
// So we use SavedSearch as T in APIClient.request<SavedSearch>
