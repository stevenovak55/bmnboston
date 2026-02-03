//
//  Property.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Comprehensive model matching MLD WordPress plugin 100%
//

import Foundation
import CoreLocation
import MapKit

// MARK: - CLLocationCoordinate2D Equatable Conformance

extension CLLocationCoordinate2D: @retroactive Equatable {
    public static func == (lhs: CLLocationCoordinate2D, rhs: CLLocationCoordinate2D) -> Bool {
        lhs.latitude == rhs.latitude && lhs.longitude == rhs.longitude
    }
}

// MARK: - Property List Response

struct PropertyListData: Decodable {
    let listings: [Property]
    let total: Int?
    let page: Int?
    let perPage: Int?
    let totalPages: Int?
    let priceStats: PriceStatistics?

    enum CodingKeys: String, CodingKey {
        case listings
        case total
        case page
        case perPage = "per_page"
        case totalPages = "total_pages"
        case priceStats = "price_stats"
    }

    /// Convenience to check if there are more pages
    var hasMorePages: Bool {
        guard let page = page, let totalPages = totalPages else {
            // If no pagination info, assume there might be more
            return listings.count >= 100
        }
        return page < totalPages
    }
}

// MARK: - Price Statistics (for histogram)

struct PriceStatistics: Decodable {
    let min: Int
    let max: Int
    let median: Int
    let distribution: [PriceBucket]?

    struct PriceBucket: Decodable {
        let min: Int
        let max: Int
        let count: Int
    }
}

// MARK: - Price Distribution (for histogram slider)

struct PriceDistributionResponse: Decodable {
    let success: Bool
    let data: PriceDistributionData
}

struct PriceDistributionData: Decodable, Equatable {
    let min: Int
    let max: Int
    let displayMax: Int
    let distribution: [Int]
    let outlierCount: Int
    let totalCount: Int

    enum CodingKeys: String, CodingKey {
        case min
        case max
        case displayMax = "display_max"
        case distribution
        case outlierCount = "outlier_count"
        case totalCount = "total_count"
    }
}

// MARK: - Favorites Response

struct FavoritesResponse: Decodable {
    let properties: [Property]
    let count: Int
}

// MARK: - Hidden Properties Response

struct HiddenPropertiesResponse: Decodable {
    let properties: [Property]
    let count: Int
}

// MARK: - Hide/Unhide Response

struct HidePropertyResponse: Decodable {
    let isHidden: Bool

    enum CodingKeys: String, CodingKey {
        case isHidden
    }
}

// MARK: - Filter Options Response (v6.59.0)

/// Response from /filter-options endpoint
/// Returns available home types (property_sub_type values) for current filters
struct FilterOptionsResponse: Decodable {
    let homeTypes: [String]

    enum CodingKeys: String, CodingKey {
        case homeTypes = "home_types"
    }
}

// MARK: - Photo Object (for API responses with {url, caption, order})

struct PhotoObject: Decodable {
    let url: String
    let caption: String?
    let order: Int?
}

// MARK: - Autocomplete Suggestions

/// Autocomplete suggestion from API
/// Production returns: { "success": true, "data": [{value, type, icon}, ...] }
/// APIClient unwraps to just the array of suggestions
struct AutocompleteSuggestion: Decodable, Identifiable {
    var id: String { "\(type)-\(value)" }
    let value: String
    let type: String

    // Optional fields from production API
    let state: String?
    let count: Int?
    let city: String?
    let listingId: String?
    let listingKey: String?  // v6.68.6: For direct navigation to property detail

    enum CodingKeys: String, CodingKey {
        case value, type, state, count, city
        case listingId = "listing_id"
        case listingKey = "listing_key"
    }

    var sfSymbol: String {
        // MLD API returns type values like "City", "ZIP Code", "Street Name", etc.
        switch type.lowercased() {
        case "city": return "building.2.fill"
        case "zip", "zip code": return "mappin.circle.fill"
        case "neighborhood": return "map.fill"
        case "address", "street address": return "house.fill"
        case "mls", "mls number": return "number.circle.fill"
        case "street", "street name": return "road.lanes"
        default: return "magnifyingglass"
        }
    }

    /// Generate subtitle from available data
    var subtitle: String? {
        if let city = city {
            return city
        } else if let state = state {
            return state
        } else if let count = count, count > 0 {
            return "\(count) listings"
        }
        return nil
    }

    /// Convert to SearchSuggestion for compatibility with existing code
    func toSearchSuggestion() -> SearchSuggestion {
        let suggestionType: SearchSuggestion.SuggestionType = {
            // MLD API returns type values like "City", "ZIP Code", "Street Name", etc.
            switch type.lowercased() {
            case "city": return .city
            case "zip", "zip code": return .zip
            case "neighborhood": return .neighborhood
            case "address", "street address": return .address
            case "mls", "mls number": return .mlsNumber
            case "street", "street name": return .streetName
            default: return .address
            }
        }()

        return SearchSuggestion(
            type: suggestionType,
            value: value,
            displayText: value,
            subtitle: subtitle,
            listingId: listingId,
            listingKey: listingKey
        )
    }
}

// MARK: - Property

struct Property: Identifiable, Decodable {
    let id: String
    let address: String
    let groupingAddress: String?  // Address without unit number, for map clustering
    let city: String
    let state: String
    let zip: String
    let neighborhood: String?
    let price: Int
    let originalPrice: Int?  // For price reduction calculation
    let closePrice: Int?  // For sold listings - final sale price
    let closeDate: String?  // For sold listings - sale date
    let beds: Int
    let baths: Double
    let bathsFull: Int?  // Full bathrooms count
    let bathsHalf: Int?  // Half bathrooms count
    let sqft: Int?
    let propertyType: String
    let propertySubtype: String?
    let structureType: String?
    let architecturalStyle: String?
    let latitude: Double?
    let longitude: Double?
    let listDate: String?
    let dom: Int?
    let photoUrl: String?
    let photos: [String]?  // Array of photo URLs for carousel (first 5)
    let yearBuilt: Int?
    let lotSize: Double?
    let garageSpaces: Int?
    let parkingTotal: Int?
    let stories: Int?
    let entryLevel: Int?
    let standardStatus: PropertyStatus
    let mlsNumber: String?
    let virtualTourUrl: String?
    let hasOpenHouse: Bool
    let openHouseDate: String?
    let nextOpenHouse: String?  // Next open house datetime
    var isFavorite: Bool = false
    var isHidden: Bool = false

    // Amenity flags
    let hasSpa: Bool
    let hasWaterfront: Bool
    let hasView: Bool
    let hasWaterView: Bool
    let isAttached: Bool
    let isLenderOwned: Bool
    let isSeniorCommunity: Bool
    let hasOutdoorSpace: Bool
    let hasDPR: Bool
    let hasCooling: Bool
    let hasPool: Bool
    let hasFireplace: Bool
    let hasGarage: Bool

    // District school rating (v89)
    let districtGrade: String?       // A+, A, A-, B+, B, B-, C+, C, C-, D, F
    let districtPercentile: Int?     // 0-100

    // Property sharing (v144 - Sprint 3, v145 - Recommended by Agent badge)
    let isSharedByAgent: Bool        // True if this property was shared by user's agent
    let sharedByAgentName: String?   // First name of agent who shared (e.g., "Ashlie")
    let sharedByAgentPhoto: String?  // Photo URL of agent who shared

    // v6.64.0 / v284: Exclusive listing flag (listing_id < 1,000,000 = exclusive, MLS IDs are 60M+)
    let isExclusive: Bool
    // v6.65.0: Custom badge text for exclusive listings (Coming Soon, Off-Market, etc.)
    let exclusiveTag: String?

    // MARK: - Computed Properties for Price Reduction

    var isPriceReduced: Bool {
        guard let original = originalPrice else { return false }
        return original > price
    }

    var priceReductionAmount: Int? {
        guard let original = originalPrice, original > price else { return nil }
        return original - price
    }

    var priceReductionPercent: Double? {
        guard let original = originalPrice, original > price else { return nil }
        return Double(original - price) / Double(original) * 100
    }

    var isNewListing: Bool {
        guard let dom = dom else { return false }
        return dom <= 7
    }

    var formattedBathroomsDetailed: String {
        if let full = bathsFull, let half = bathsHalf, half > 0 {
            return "\(full) full, \(half) half"
        } else if let full = bathsFull {
            return "\(full) full"
        }
        let bathString = baths.truncatingRemainder(dividingBy: 1) == 0
            ? "\(Int(baths))"
            : String(format: "%.1f", baths)
        return bathString
    }

    enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case id  // Alternative key used by dev server
        case address
        case groupingAddress = "grouping_address"  // For map clustering (no unit number)
        case city
        case state
        case zip
        case neighborhood
        case price
        case originalPrice = "original_price"
        case closePrice = "close_price"
        case closeDate = "close_date"
        case beds
        case baths
        case bathsFull = "baths_full"
        case bathsHalf = "baths_half"
        case sqft
        case propertyType = "property_type"
        case propertySubtype = "property_subtype"
        case structureType = "structure_type"
        case architecturalStyle = "architectural_style"
        case latitude
        case longitude
        case listDate = "list_date"
        case dom
        case photoUrl = "photo_url"
        case photos
        case yearBuilt = "year_built"
        case lotSize = "lot_size"
        case garageSpaces = "garage_spaces"
        case parkingTotal = "parking_total"
        case stories
        case entryLevel = "entry_level"
        case standardStatus = "status"
        case mlsNumber = "mls_number"
        case virtualTourUrl = "virtual_tour_url"
        case hasOpenHouse = "has_open_house"
        case openHouseDate = "open_house_date"
        case nextOpenHouse = "next_open_house"
        case hasSpa = "has_spa"
        case hasWaterfront = "has_waterfront"
        case hasView = "has_view"
        case hasWaterView = "has_water_view"
        case isAttached = "is_attached"
        case isLenderOwned = "is_lender_owned"
        case isSeniorCommunity = "senior_community"
        case hasOutdoorSpace = "has_outdoor_space"
        case hasDPR = "has_dpr"
        case hasCooling = "has_cooling"
        case hasPool = "has_pool"
        case hasFireplace = "has_fireplace"
        case hasGarage = "has_garage"
        case districtGrade = "district_grade"
        case districtPercentile = "district_percentile"
        case isSharedByAgent = "is_shared_by_agent"
        case sharedByAgentName = "shared_by_agent_name"
        case sharedByAgentPhoto = "shared_by_agent_photo"
        case isExclusive = "is_exclusive"
        case exclusiveTag = "exclusive_tag"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        // Try "listing_id" first (production), fall back to "id" (dev server)
        if let listingId = try container.decodeIfPresent(String.self, forKey: .listingId) {
            id = listingId
        } else {
            id = try container.decode(String.self, forKey: .id)
        }
        address = try container.decode(String.self, forKey: .address)
        groupingAddress = try container.decodeIfPresent(String.self, forKey: .groupingAddress)
        city = try container.decode(String.self, forKey: .city)
        state = try container.decode(String.self, forKey: .state)
        zip = try container.decode(String.self, forKey: .zip)
        neighborhood = try container.decodeIfPresent(String.self, forKey: .neighborhood)
        price = try container.decode(Int.self, forKey: .price)
        originalPrice = try container.decodeIfPresent(Int.self, forKey: .originalPrice)
        closePrice = try container.decodeIfPresent(Int.self, forKey: .closePrice)
        closeDate = try container.decodeIfPresent(String.self, forKey: .closeDate)
        beds = try container.decode(Int.self, forKey: .beds)

        // Handle baths as either Int or Double from API
        if let bathsDouble = try? container.decode(Double.self, forKey: .baths) {
            baths = bathsDouble
        } else if let bathsInt = try? container.decode(Int.self, forKey: .baths) {
            baths = Double(bathsInt)
        } else {
            baths = 0
        }

        bathsFull = try container.decodeIfPresent(Int.self, forKey: .bathsFull)
        bathsHalf = try container.decodeIfPresent(Int.self, forKey: .bathsHalf)
        sqft = try container.decodeIfPresent(Int.self, forKey: .sqft)
        propertyType = try container.decode(String.self, forKey: .propertyType)
        propertySubtype = try container.decodeIfPresent(String.self, forKey: .propertySubtype)
        structureType = try container.decodeIfPresent(String.self, forKey: .structureType)
        architecturalStyle = try container.decodeIfPresent(String.self, forKey: .architecturalStyle)
        latitude = try container.decodeIfPresent(Double.self, forKey: .latitude)
        longitude = try container.decodeIfPresent(Double.self, forKey: .longitude)
        listDate = try container.decodeIfPresent(String.self, forKey: .listDate)
        dom = try container.decodeIfPresent(Int.self, forKey: .dom)
        photoUrl = try container.decodeIfPresent(String.self, forKey: .photoUrl)
        photos = try container.decodeIfPresent([String].self, forKey: .photos)
        yearBuilt = try container.decodeIfPresent(Int.self, forKey: .yearBuilt)
        lotSize = try container.decodeIfPresent(Double.self, forKey: .lotSize)
        garageSpaces = try container.decodeIfPresent(Int.self, forKey: .garageSpaces)
        parkingTotal = try container.decodeIfPresent(Int.self, forKey: .parkingTotal)
        stories = try container.decodeIfPresent(Int.self, forKey: .stories)

        // v6.68.12: Handle entry_level as either Int or String from API
        // API may return "10" (string) or 10 (int) - condos need this for floor display
        if let entryInt = try? container.decodeIfPresent(Int.self, forKey: .entryLevel) {
            entryLevel = entryInt
        } else if let entryString = try? container.decodeIfPresent(String.self, forKey: .entryLevel),
                  let entryInt = Int(entryString) {
            entryLevel = entryInt
        } else {
            entryLevel = nil
        }

        // Status with fallback
        if let statusString = try? container.decode(String.self, forKey: .standardStatus) {
            standardStatus = PropertyStatus(rawValue: statusString) ?? .active
        } else {
            standardStatus = .active
        }

        mlsNumber = try container.decodeIfPresent(String.self, forKey: .mlsNumber)
        virtualTourUrl = try container.decodeIfPresent(String.self, forKey: .virtualTourUrl)
        hasOpenHouse = (try? container.decode(Bool.self, forKey: .hasOpenHouse)) ?? false
        openHouseDate = try container.decodeIfPresent(String.self, forKey: .openHouseDate)
        nextOpenHouse = try container.decodeIfPresent(String.self, forKey: .nextOpenHouse)

        // Amenity flags with defaults
        hasSpa = (try? container.decode(Bool.self, forKey: .hasSpa)) ?? false
        hasWaterfront = (try? container.decode(Bool.self, forKey: .hasWaterfront)) ?? false
        hasView = (try? container.decode(Bool.self, forKey: .hasView)) ?? false
        hasWaterView = (try? container.decode(Bool.self, forKey: .hasWaterView)) ?? false
        isAttached = (try? container.decode(Bool.self, forKey: .isAttached)) ?? false
        isLenderOwned = (try? container.decode(Bool.self, forKey: .isLenderOwned)) ?? false
        isSeniorCommunity = (try? container.decode(Bool.self, forKey: .isSeniorCommunity)) ?? false
        hasOutdoorSpace = (try? container.decode(Bool.self, forKey: .hasOutdoorSpace)) ?? false
        hasDPR = (try? container.decode(Bool.self, forKey: .hasDPR)) ?? false
        hasCooling = (try? container.decode(Bool.self, forKey: .hasCooling)) ?? false
        hasPool = (try? container.decode(Bool.self, forKey: .hasPool)) ?? false
        hasFireplace = (try? container.decode(Bool.self, forKey: .hasFireplace)) ?? false
        hasGarage = (try? container.decode(Bool.self, forKey: .hasGarage)) ?? false

        // District school rating (v89)
        districtGrade = try container.decodeIfPresent(String.self, forKey: .districtGrade)
        districtPercentile = try container.decodeIfPresent(Int.self, forKey: .districtPercentile)

        // Property sharing (v144, v145)
        isSharedByAgent = (try? container.decode(Bool.self, forKey: .isSharedByAgent)) ?? false
        sharedByAgentName = try container.decodeIfPresent(String.self, forKey: .sharedByAgentName)
        sharedByAgentPhoto = try container.decodeIfPresent(String.self, forKey: .sharedByAgentPhoto)

        // v6.64.0 / v284: Exclusive listing flag
        isExclusive = (try? container.decode(Bool.self, forKey: .isExclusive)) ?? false
        // v6.65.0: Custom badge text for exclusive listings
        exclusiveTag = try container.decodeIfPresent(String.self, forKey: .exclusiveTag)

        isFavorite = false
        isHidden = false
    }

    var formattedPrice: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }

    var shortFormattedPrice: String {
        if price >= 1_000_000 {
            return String(format: "$%.1fM", Double(price) / 1_000_000)
        } else if price >= 1000 {
            return String(format: "$%.0fK", Double(price) / 1000)
        }
        return formattedPrice
    }

    var formattedBedBath: String {
        let bathString = baths.truncatingRemainder(dividingBy: 1) == 0
            ? "\(Int(baths))"
            : String(format: "%.1f", baths)
        return "\(beds) bd | \(bathString) ba"
    }

    var formattedSqft: String? {
        guard let sqft = sqft else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: sqft)).map { "\($0) sqft" }
    }

    var formattedLotSize: String? {
        guard let lotSize = lotSize else { return nil }
        if lotSize >= 43560 {
            return String(format: "%.2f acres", lotSize / 43560)
        }
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: lotSize)).map { "\($0) sqft lot" }
    }

    var fullAddress: String {
        "\(address), \(city), \(state) \(zip)"
    }

    var primaryImageURL: URL? {
        guard let photoUrl = photoUrl else { return nil }
        return URL(string: photoUrl)
    }

    /// All photo URLs for carousel (uses photos array if available, falls back to photoUrl)
    var photoURLs: [URL] {
        if let photos = photos, !photos.isEmpty {
            return photos.compactMap { URL(string: $0) }
        } else if let url = primaryImageURL {
            return [url]
        }
        return []
    }

    /// Whether this property has multiple photos for carousel
    var hasMultiplePhotos: Bool {
        guard let photos = photos else { return false }
        return photos.count > 1
    }

    var thumbnailURL: URL? {
        primaryImageURL
    }

    var location: CLLocationCoordinate2D? {
        guard let lat = latitude, let lng = longitude else { return nil }
        return CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var daysOnMarket: String? {
        guard let dom = dom else { return nil }
        if dom == 0 { return "New" }
        if dom == 1 { return "1 day" }
        return "\(dom) days"
    }

    var statusColor: String {
        switch standardStatus {
        case .active: return "#059669"
        case .pending: return "#D97706"
        case .sold, .closed: return "#DC2626"
        case .withdrawn, .expired, .canceled: return "#6B7280"
        }
    }
}

// MARK: - Property Hashable/Equatable

extension Property: Hashable, Equatable {
    func hash(into hasher: inout Hasher) {
        hasher.combine(id)
    }

    /// Equatable compares id plus mutable state fields (isFavorite, isHidden)
    /// This allows SwiftUI to efficiently diff and only re-render cards where
    /// these values have changed, rather than re-rendering all cards.
    static func == (lhs: Property, rhs: Property) -> Bool {
        lhs.id == rhs.id &&
        lhs.isFavorite == rhs.isFavorite &&
        lhs.isHidden == rhs.isHidden
    }
}

// MARK: - Property Status

enum PropertyStatus: String, CaseIterable, Codable {
    case active = "Active"
    case pending = "Pending"
    case sold = "Sold"
    case closed = "Closed"  // API returns "Closed" for sold listings
    case withdrawn = "Withdrawn"
    case expired = "Expired"
    case canceled = "Canceled"

    var displayName: String {
        switch self {
        case .closed: return "Sold"  // Display "Sold" for closed listings
        default: return rawValue
        }
    }

    var color: String {
        switch self {
        case .active: return "#059669"
        case .pending: return "#D97706"
        case .sold, .closed: return "#DC2626"
        case .withdrawn, .expired, .canceled: return "#6B7280"
        }
    }

    /// Check if this is a sold/closed status
    var isSold: Bool {
        self == .sold || self == .closed
    }

    /// Statuses available for filtering in the UI (excludes .closed which is internal)
    static var filterOptions: [PropertyStatus] {
        [.active, .pending, .sold]
    }
}

// MARK: - Property Subtype (Home Type)

enum PropertySubtype: String, CaseIterable {
    case singleFamily = "Single Family"
    case condo = "Condo"
    case townhouse = "Townhouse"
    case multiFamily = "Multi-Family"
    case coop = "Co-op"
    case mobile = "Mobile"
    case land = "Land"
    case farm = "Farm"
    case other = "Other"

    var displayName: String {
        rawValue
    }

    var icon: String {
        switch self {
        case .singleFamily: return "house.fill"
        case .condo: return "building.2.fill"
        case .townhouse: return "building.fill"
        case .multiFamily: return "building.2.crop.circle.fill"
        case .coop: return "person.3.fill"
        case .mobile: return "car.fill"
        case .land: return "leaf.fill"
        case .farm: return "tree.fill"
        case .other: return "questionmark.circle.fill"
        }
    }
}

// MARK: - Room Data

struct Room: Decodable, Identifiable {
    var id: String { "\(type ?? "room")-\(level ?? "")-\(dimensions ?? "")" }
    let type: String?
    let level: String?
    let dimensions: String?
    let features: String?
    let description: String?

    // v6.68.19: Enhanced room level tracking
    let hasLevel: Bool?           // True if room has assigned floor level from MLS
    let isLikelyPlaceholder: Bool? // True if no level, no dimensions, no features
    let isSpecial: Bool?          // True for bonus room, in-law, etc. from interior_features
    let levelInferred: Bool?      // True if level was inferred from context, not MLS data

    enum CodingKeys: String, CodingKey {
        case type, level, dimensions, features, description
        case hasLevel = "has_level"
        case isLikelyPlaceholder = "is_likely_placeholder"
        case isSpecial = "is_special"
        case levelInferred = "level_inferred"
    }

    // Custom initializer with default values for new fields (for previews and tests)
    init(type: String?, level: String?, dimensions: String?, features: String?, description: String?,
         hasLevel: Bool? = nil, isLikelyPlaceholder: Bool? = nil, isSpecial: Bool? = nil, levelInferred: Bool? = nil) {
        self.type = type
        self.level = level
        self.dimensions = dimensions
        self.features = features
        self.description = description
        self.hasLevel = hasLevel ?? (level != nil && !level!.isEmpty)
        self.isLikelyPlaceholder = isLikelyPlaceholder ?? false
        self.isSpecial = isSpecial ?? false
        self.levelInferred = levelInferred ?? false
    }
}

// MARK: - Computed Room Counts (v6.68.19)

struct ComputedRoomCounts: Decodable {
    let bedroomsFromRooms: Int?
    let bathroomsFromRooms: Int?
    let totalRoomsDisplayed: Int?
    let roomsWithLevel: Int?      // How many rooms have floor assignment
    let roomsWithoutLevel: Int?   // How many rooms lack floor assignment
    let hasInLaw: Bool?
    let hasBonusRoom: Bool?

    enum CodingKeys: String, CodingKey {
        case bedroomsFromRooms = "bedrooms_from_rooms"
        case bathroomsFromRooms = "bathrooms_from_rooms"
        case totalRoomsDisplayed = "total_rooms_displayed"
        case roomsWithLevel = "rooms_with_level"
        case roomsWithoutLevel = "rooms_without_level"
        case hasInLaw = "has_in_law"
        case hasBonusRoom = "has_bonus_room"
    }
}

// MARK: - Property Detail Response

struct PropertyDetailData: Decodable {
    let listing: PropertyDetail
}

// MARK: - Agent Only Info (v6.60.6)
// This struct contains agent-only information that is only returned
// when an authenticated agent user requests property details.

struct AgentOnlyInfo: Decodable {
    // Only fields that exist in the MLSPIN database
    let privateRemarks: String?
    let privateOfficeRemarks: String?
    let showingInstructions: String?

    enum CodingKeys: String, CodingKey {
        case privateRemarks = "private_remarks"
        case privateOfficeRemarks = "private_office_remarks"
        case showingInstructions = "showing_instructions"
    }
}

/// MARK: - Unit Rent (for multi-unit properties)

struct UnitRent: Decodable, Identifiable {
    let unit: Int
    let rent: Double
    let lease: String?

    var id: Int { unit }

    var formattedRent: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: rent)) ?? "$\(Int(rent))"
    }
}

// MARK: - Property Detail (Extended)

struct PropertyDetail: Identifiable, Decodable {
    // Core identification
    let id: String
    let mlsNumber: String?

    // Address & Location
    let address: String
    let city: String
    let state: String
    let zip: String
    let neighborhood: String?
    let latitude: Double?
    let longitude: Double?

    // Price & Status
    let price: Int
    let originalPrice: Int?
    let closePrice: Int?
    let closeDate: String?
    let pricePerSqft: Int?
    let standardStatus: PropertyStatus

    // Sold Statistics (for closed listings)
    let listToSaleRatio: Double?
    let soldAboveBelow: String?
    let priceDifference: Int?

    // Property Type
    let propertyType: String
    let propertySubtype: String?
    let structureType: String?

    // Core Stats
    let beds: Int
    let baths: Double
    let bathsFull: Int?
    let bathsHalf: Int?
    let sqft: Int?
    let yearBuilt: Int?

    // Lot
    let lotSize: Double?
    let lotSizeAcres: Double?
    let lotSizeDimensions: String?
    let lotFeatures: String?

    // Parking & Garage
    let garageSpaces: Int?
    let parkingTotal: Int?
    let parkingFeatures: String?
    let coveredSpaces: Int?
    let openParkingSpaces: Int?

    // Timing
    let listDate: String?
    let dom: Int?

    // Media
    let photoUrl: String?
    let photos: [String]?
    let photoCount: Int?
    let virtualTourUrl: String?
    let virtualTours: [String]?  // Array of up to 3 virtual tour URLs

    // Open Houses
    let hasOpenHouse: Bool
    let openHouses: [PropertyOpenHouse]?

    // Description
    let description: String?
    let features: [String]?

    // Structure
    let stories: Int?
    let constructionMaterials: String?
    let architecturalStyle: String?
    let roof: String?
    let foundationDetails: String?

    // Interior Features
    let heating: String?
    let cooling: String?
    let flooring: String?
    let appliances: String?
    let fireplaceFeatures: String?
    let fireplacesTotal: Int?
    let basement: String?
    let laundryFeatures: String?
    let interiorFeatures: String?

    // Exterior Features
    let exteriorFeatures: String?
    let patioAndPorchFeatures: String?
    let poolFeatures: String?
    let spaFeatures: String?
    let waterfrontFeatures: String?
    let view: String?
    let fencing: String?

    // HOA & Community
    let hoaFee: Double?
    let hoaFeeFrequency: String?
    let associationAmenities: String?
    let petsAllowed: String?
    let seniorCommunity: Bool

    // Financial
    let taxAnnual: Double?
    let taxYear: Int?
    let taxAssessedValue: Double?
    let taxLegalDescription: String?
    let taxLot: String?
    let taxBlock: String?
    let taxMapNumber: String?
    let parcelNumber: String?
    let additionalParcelsYn: Bool
    let additionalParcelsDescription: String?
    let zoning: String?
    let zoningDescription: String?

    // Additional Interior Features
    let windowFeatures: String?
    let doorFeatures: String?
    let attic: String?
    let insulation: String?
    let accessibilityFeatures: String?
    let securityFeatures: String?
    let commonWalls: String?
    let entryLevel: String?
    let entryLocation: String?
    let levels: String?
    let roomsTotal: Int?
    let mainLevelBedrooms: Int?
    let mainLevelBathrooms: Int?
    let otherRooms: String?
    let masterBedroomLevel: String?

    // Area Breakdown
    let aboveGradeFinishedArea: Int?
    let belowGradeFinishedArea: Int?
    let totalArea: Int?

    // Additional Exterior Features
    let waterBodyName: String?
    let foundationArea: Int?

    // Additional Lot & Land
    let lotSizeSquareFeet: Int?
    let landLeaseYn: Bool
    let landLeaseAmount: Double?
    let landLeaseExpirationDate: String?
    let horseYn: Bool
    let horseAmenities: String?
    let vegetation: String?
    let topography: String?
    let frontageType: String?
    let frontageLength: Int?
    let roadSurfaceType: String?
    let roadFrontageType: String?

    // Additional Parking
    let attachedGarageYn: Bool
    let carportSpaces: Int?
    let carportYn: Bool
    let drivewaySurface: String?

    // Utilities & Systems
    let utilities: String?
    let waterSource: String?
    let sewer: String?
    let electric: String?
    let electricOnPropertyYn: Bool
    let gas: String?
    let internetType: String?
    let cableAvailableYn: Bool
    let smartHomeFeatures: String?
    let energyFeatures: String?
    let greenBuildingCertification: String?
    let greenCertificationRating: String?
    let greenEnergyEfficient: String?
    let greenSustainability: String?

    // Additional Community & HOA
    let communityFeatures: String?
    let associationYn: Bool
    let associationFee2: Double?
    let associationFee2Frequency: String?
    let associationName: String?
    let associationPhone: String?
    let masterAssociationFee: Double?
    let condoAssociationFee: Double?
    let petRestrictions: String?

    // Schools
    let schoolDistrict: String?
    let elementarySchool: String?
    let middleOrJuniorSchool: String?
    let highSchool: String?

    // Additional Details
    let yearBuiltSource: String?
    let yearBuiltDetails: String?
    let yearBuiltEffective: Int?
    let buildingName: String?
    let buildingFeatures: String?
    let propertyAttachedYn: Bool
    let propertyCondition: String?
    let disclosures: String?
    let exclusions: String?
    let inclusions: String?
    let ownership: String?
    let occupantType: String?
    let possession: String?
    let listingTerms: String?
    let listingService: String?
    let specialListingConditions: String?

    // Rooms
    let rooms: [Room]?
    let specialRooms: [Room]?                   // v6.68.19: Special rooms from interior_features
    let computedRoomCounts: ComputedRoomCounts? // v6.68.19: Room count summary

    // Feature Flags
    let hasPool: Bool
    let hasWaterfront: Bool
    let hasView: Bool
    let hasWaterView: Bool
    let hasSpa: Bool
    let hasFireplace: Bool
    let hasGarage: Bool
    let hasCooling: Bool

    // v6.64.0 / v284: Exclusive listing flag (listing_id < 1,000,000 = exclusive, MLS IDs are 60M+)
    let isExclusive: Bool
    // v6.65.0: Custom badge text for exclusive listings (Coming Soon, Off-Market, etc.)
    let exclusiveTag: String?

    // Agent
    let listingAgent: ListingAgent?

    // Agent-Only Info (nil for non-agent users)
    let agentOnlyInfo: AgentOnlyInfo?

    // v6.60.9: Rental Details (for lease properties)
    let availabilityDate: String?
    let availableNow: Bool
    let leaseTerm: String?
    let rentIncludes: String?
    let securityDeposit: Double?
    let firstMonthRequired: Bool
    let lastMonthRequired: Bool
    let referencesRequired: Bool
    let depositRequired: Bool
    let insuranceRequired: Bool

    // v6.60.9: Multi-unit rent breakdown (for investment properties)
    let unitRents: [UnitRent]?
    let totalMonthlyRent: Double?

    // v6.60.9: Investment metrics
    let grossIncome: Double?
    let netOperatingIncome: Double?
    let operatingExpense: Double?
    let totalActualRent: Double?

    // v6.60.10: Enhanced HOA Information
    let associationFeeIncludes: String?
    let optionalFee: Double?
    let optionalFeeIncludes: String?
    let ownerOccupiedUnits: Int?

    // v6.60.10: Land/Lot specific fields
    let roadResponsibility: String?
    let numberOfLots: Int?
    let pastureArea: Double?
    let cultivatedArea: Double?
    let woodedArea: Double?

    // v6.60.10: Commercial property fields
    let grossScheduledIncome: Double?
    let existingLeaseType: String?
    let tenantPays: String?
    let lenderOwned: Bool
    let concessionsAmount: Double?
    let capRate: Double?
    let businessType: String?
    let developmentStatus: String?

    // v6.60.10: MA-specific disclosure fields
    let leadPaint: Bool
    let title5Compliant: Bool
    let percTestDone: Bool
    let percTestDate: String?
    let shortSale: Bool

    // v6.66.0: Enhanced property details for improved iOS display
    let homeWarranty: Bool
    let heatZones: Int?
    let coolZones: Int?
    let yearRound: Bool
    let showingDeferralDate: String?
    let bedsByFloor: [String: Int]?
    let bathsByFloor: [String: Int]?

    // v6.68.0: Data freshness indicator
    let modificationTimestamp: String?

    var isFavorite: Bool = false

    enum CodingKeys: String, CodingKey {
        case id
        case mlsNumber = "mls_number"
        case address, city, state, zip, neighborhood
        case latitude, longitude
        case price
        case originalPrice = "original_price"
        case closePrice = "close_price"
        case closeDate = "close_date"
        case pricePerSqft = "price_per_sqft"
        case standardStatus = "status"
        case listToSaleRatio = "list_to_sale_ratio"
        case soldAboveBelow = "sold_above_below"
        case priceDifference = "price_difference"
        case propertyType = "property_type"
        case propertySubtype = "property_subtype"
        case structureType = "structure_type"
        case beds, baths
        case bathsFull = "baths_full"
        case bathsHalf = "baths_half"
        case sqft
        case yearBuilt = "year_built"
        case lotSize = "lot_size"
        case lotSizeAcres = "lot_size_acres"
        case lotSizeDimensions = "lot_size_dimensions"
        case lotFeatures = "lot_features"
        case garageSpaces = "garage_spaces"
        case parkingTotal = "parking_total"
        case parkingFeatures = "parking_features"
        case coveredSpaces = "covered_spaces"
        case openParkingSpaces = "open_parking_spaces"
        case listDate = "list_date"
        case dom
        case photoUrl = "photo_url"
        case photos
        case photoCount = "photo_count"
        case virtualTourUrl = "virtual_tour_url"
        case virtualTours = "virtual_tours"
        case hasOpenHouse = "has_open_house"
        case openHouses = "open_houses"
        case description, features, stories
        case constructionMaterials = "construction_materials"
        case architecturalStyle = "architectural_style"
        case roof
        case foundationDetails = "foundation_details"
        case heating, cooling, flooring, appliances
        case fireplaceFeatures = "fireplace_features"
        case fireplacesTotal = "fireplaces_total"
        case basement
        case laundryFeatures = "laundry_features"
        case interiorFeatures = "interior_features"
        case exteriorFeatures = "exterior_features"
        case patioAndPorchFeatures = "patio_and_porch_features"
        case poolFeatures = "pool_features"
        case spaFeatures = "spa_features"
        case waterfrontFeatures = "waterfront_features"
        case view, fencing
        case hoaFee = "hoa_fee"
        case hoaFeeFrequency = "hoa_fee_frequency"
        case associationAmenities = "association_amenities"
        case petsAllowed = "pets_allowed"
        case seniorCommunity = "senior_community"
        case taxAnnual = "tax_annual"
        case taxYear = "tax_year"
        case taxAssessedValue = "tax_assessed_value"
        case taxLegalDescription = "tax_legal_description"
        case taxLot = "tax_lot"
        case taxBlock = "tax_block"
        case taxMapNumber = "tax_map_number"
        case parcelNumber = "parcel_number"
        case additionalParcelsYn = "additional_parcels_yn"
        case additionalParcelsDescription = "additional_parcels_description"
        case zoning, zoningDescription = "zoning_description"
        // Additional Interior Features
        case windowFeatures = "window_features"
        case doorFeatures = "door_features"
        case attic, insulation
        case accessibilityFeatures = "accessibility_features"
        case securityFeatures = "security_features"
        case commonWalls = "common_walls"
        case entryLevel = "entry_level"
        case entryLocation = "entry_location"
        case levels
        case roomsTotal = "rooms_total"
        case mainLevelBedrooms = "main_level_bedrooms"
        case mainLevelBathrooms = "main_level_bathrooms"
        case otherRooms = "other_rooms"
        case masterBedroomLevel = "master_bedroom_level"
        // Area Breakdown
        case aboveGradeFinishedArea = "above_grade_finished_area"
        case belowGradeFinishedArea = "below_grade_finished_area"
        case totalArea = "total_area"
        // Additional Exterior Features
        case waterBodyName = "water_body_name"
        case foundationArea = "foundation_area"
        // Additional Lot & Land
        case lotSizeSquareFeet = "lot_size_square_feet"
        case landLeaseYn = "land_lease_yn"
        case landLeaseAmount = "land_lease_amount"
        case landLeaseExpirationDate = "land_lease_expiration_date"
        case horseYn = "horse_yn"
        case horseAmenities = "horse_amenities"
        case vegetation, topography
        case frontageType = "frontage_type"
        case frontageLength = "frontage_length"
        case roadSurfaceType = "road_surface_type"
        case roadFrontageType = "road_frontage_type"
        // Additional Parking
        case attachedGarageYn = "attached_garage_yn"
        case carportSpaces = "carport_spaces"
        case carportYn = "carport_yn"
        case drivewaySurface = "driveway_surface"
        // Utilities & Systems
        case utilities
        case waterSource = "water_source"
        case sewer, electric
        case electricOnPropertyYn = "electric_on_property_yn"
        case gas
        case internetType = "internet_type"
        case cableAvailableYn = "cable_available_yn"
        case smartHomeFeatures = "smart_home_features"
        case energyFeatures = "energy_features"
        case greenBuildingCertification = "green_building_certification"
        case greenCertificationRating = "green_certification_rating"
        case greenEnergyEfficient = "green_energy_efficient"
        case greenSustainability = "green_sustainability"
        // Additional Community & HOA
        case communityFeatures = "community_features"
        case associationYn = "association_yn"
        case associationFee2 = "association_fee2"
        case associationFee2Frequency = "association_fee2_frequency"
        case associationName = "association_name"
        case associationPhone = "association_phone"
        case masterAssociationFee = "master_association_fee"
        case condoAssociationFee = "condo_association_fee"
        case petRestrictions = "pet_restrictions"
        // Schools
        case schoolDistrict = "school_district"
        case elementarySchool = "elementary_school"
        case middleOrJuniorSchool = "middle_or_junior_school"
        case highSchool = "high_school"
        // Additional Details
        case yearBuiltSource = "year_built_source"
        case yearBuiltDetails = "year_built_details"
        case yearBuiltEffective = "year_built_effective"
        case buildingName = "building_name"
        case buildingFeatures = "building_features"
        case propertyAttachedYn = "property_attached_yn"
        case propertyCondition = "property_condition"
        case disclosures, exclusions, inclusions
        case ownership
        case occupantType = "occupant_type"
        case possession
        case listingTerms = "listing_terms"
        case listingService = "listing_service"
        case specialListingConditions = "special_listing_conditions"
        // Rooms
        case rooms
        case specialRooms = "special_rooms"
        case computedRoomCounts = "computed_room_counts"
        // Feature Flags
        case hasPool = "has_pool"
        case hasWaterfront = "has_waterfront"
        case hasView = "has_view"
        case hasWaterView = "has_water_view"
        case hasSpa = "has_spa"
        case hasFireplace = "has_fireplace"
        case hasGarage = "has_garage"
        case hasCooling = "has_cooling"
        // v6.64.0 / v284: Exclusive listing flag
        case isExclusive = "is_exclusive"
        case exclusiveTag = "exclusive_tag"
        case listingAgent = "agent"
        case agentOnlyInfo = "agent_only_info"
        // v6.60.9: Rental Details
        case availabilityDate = "availability_date"
        case availableNow = "available_now"
        case leaseTerm = "lease_term"
        case rentIncludes = "rent_includes"
        case securityDeposit = "security_deposit"
        case firstMonthRequired = "first_month_required"
        case lastMonthRequired = "last_month_required"
        case referencesRequired = "references_required"
        case depositRequired = "deposit_required"
        case insuranceRequired = "insurance_required"
        // v6.60.9: Multi-unit rent breakdown
        case unitRents = "unit_rents"
        case totalMonthlyRent = "total_monthly_rent"
        // v6.60.9: Investment metrics
        case grossIncome = "gross_income"
        case netOperatingIncome = "net_operating_income"
        case operatingExpense = "operating_expense"
        case totalActualRent = "total_actual_rent"
        // v6.60.10: Enhanced HOA Information
        case associationFeeIncludes = "association_fee_includes"
        case optionalFee = "optional_fee"
        case optionalFeeIncludes = "optional_fee_includes"
        case ownerOccupiedUnits = "owner_occupied_units"
        // v6.60.10: Land/Lot specific fields
        case roadResponsibility = "road_responsibility"
        case numberOfLots = "number_of_lots"
        case pastureArea = "pasture_area"
        case cultivatedArea = "cultivated_area"
        case woodedArea = "wooded_area"
        // v6.60.10: Commercial property fields
        case grossScheduledIncome = "gross_scheduled_income"
        case existingLeaseType = "existing_lease_type"
        case tenantPays = "tenant_pays"
        case lenderOwned = "lender_owned"
        case concessionsAmount = "concessions_amount"
        case capRate = "cap_rate"
        case businessType = "business_type"
        case developmentStatus = "development_status"
        // v6.60.10: MA-specific disclosure fields
        case leadPaint = "lead_paint"
        case title5Compliant = "title_5_compliant"
        case percTestDone = "perc_test_done"
        case percTestDate = "perc_test_date"
        case shortSale = "short_sale"
        // v6.66.0: Enhanced property details
        case homeWarranty = "home_warranty"
        case heatZones = "heat_zones"
        case coolZones = "cool_zones"
        case yearRound = "year_round"
        case showingDeferralDate = "showing_deferral_date"
        case bedsByFloor = "beds_by_floor"
        case bathsByFloor = "baths_by_floor"
        // v6.68.0: Data freshness indicator
        case modificationTimestamp = "modification_timestamp"
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Core identification
        id = try container.decode(String.self, forKey: .id)
        mlsNumber = try container.decodeIfPresent(String.self, forKey: .mlsNumber)

        // Address & Location
        address = try container.decode(String.self, forKey: .address)
        city = try container.decode(String.self, forKey: .city)
        state = try container.decode(String.self, forKey: .state)
        zip = try container.decode(String.self, forKey: .zip)
        neighborhood = try container.decodeIfPresent(String.self, forKey: .neighborhood)
        latitude = try container.decodeIfPresent(Double.self, forKey: .latitude)
        longitude = try container.decodeIfPresent(Double.self, forKey: .longitude)

        // Price & Status
        price = try container.decode(Int.self, forKey: .price)
        originalPrice = try container.decodeIfPresent(Int.self, forKey: .originalPrice)
        closePrice = try container.decodeIfPresent(Int.self, forKey: .closePrice)
        closeDate = try container.decodeIfPresent(String.self, forKey: .closeDate)
        pricePerSqft = try container.decodeIfPresent(Int.self, forKey: .pricePerSqft)

        if let statusString = try? container.decode(String.self, forKey: .standardStatus) {
            standardStatus = PropertyStatus(rawValue: statusString) ?? .active
        } else {
            standardStatus = .active
        }

        // Sold Statistics
        listToSaleRatio = try container.decodeIfPresent(Double.self, forKey: .listToSaleRatio)
        soldAboveBelow = try container.decodeIfPresent(String.self, forKey: .soldAboveBelow)
        priceDifference = try container.decodeIfPresent(Int.self, forKey: .priceDifference)

        // Property Type
        propertyType = try container.decode(String.self, forKey: .propertyType)
        propertySubtype = try container.decodeIfPresent(String.self, forKey: .propertySubtype)
        structureType = try container.decodeIfPresent(String.self, forKey: .structureType)

        // Core Stats
        beds = try container.decode(Int.self, forKey: .beds)
        if let bathsDouble = try? container.decode(Double.self, forKey: .baths) {
            baths = bathsDouble
        } else if let bathsInt = try? container.decode(Int.self, forKey: .baths) {
            baths = Double(bathsInt)
        } else {
            baths = 0
        }
        bathsFull = try container.decodeIfPresent(Int.self, forKey: .bathsFull)
        bathsHalf = try container.decodeIfPresent(Int.self, forKey: .bathsHalf)
        sqft = try container.decodeIfPresent(Int.self, forKey: .sqft)
        yearBuilt = try container.decodeIfPresent(Int.self, forKey: .yearBuilt)

        // Lot
        lotSize = try container.decodeIfPresent(Double.self, forKey: .lotSize)
        lotSizeAcres = try container.decodeIfPresent(Double.self, forKey: .lotSizeAcres)
        lotSizeDimensions = try container.decodeIfPresent(String.self, forKey: .lotSizeDimensions)
        lotFeatures = try container.decodeIfPresent(String.self, forKey: .lotFeatures)

        // Parking & Garage
        garageSpaces = try container.decodeIfPresent(Int.self, forKey: .garageSpaces)
        parkingTotal = try container.decodeIfPresent(Int.self, forKey: .parkingTotal)
        parkingFeatures = try container.decodeIfPresent(String.self, forKey: .parkingFeatures)
        coveredSpaces = try container.decodeIfPresent(Int.self, forKey: .coveredSpaces)
        openParkingSpaces = try container.decodeIfPresent(Int.self, forKey: .openParkingSpaces)

        // Timing
        listDate = try container.decodeIfPresent(String.self, forKey: .listDate)
        dom = try container.decodeIfPresent(Int.self, forKey: .dom)

        // Media
        photoUrl = try container.decodeIfPresent(String.self, forKey: .photoUrl)
        if let photoStrings = try? container.decodeIfPresent([String].self, forKey: .photos) {
            photos = photoStrings
        } else if let photoObjects = try? container.decodeIfPresent([PhotoObject].self, forKey: .photos) {
            photos = photoObjects.map { $0.url }
        } else {
            photos = nil
        }
        photoCount = try container.decodeIfPresent(Int.self, forKey: .photoCount)
        virtualTourUrl = try container.decodeIfPresent(String.self, forKey: .virtualTourUrl)
        virtualTours = try container.decodeIfPresent([String].self, forKey: .virtualTours)

        // Open Houses
        hasOpenHouse = (try? container.decode(Bool.self, forKey: .hasOpenHouse)) ?? false
        openHouses = try container.decodeIfPresent([PropertyOpenHouse].self, forKey: .openHouses)

        // Description
        description = try container.decodeIfPresent(String.self, forKey: .description)
        features = try container.decodeIfPresent([String].self, forKey: .features)

        // Structure
        stories = try container.decodeIfPresent(Int.self, forKey: .stories)
        constructionMaterials = try container.decodeIfPresent(String.self, forKey: .constructionMaterials)
        architecturalStyle = try container.decodeIfPresent(String.self, forKey: .architecturalStyle)
        roof = try container.decodeIfPresent(String.self, forKey: .roof)
        foundationDetails = try container.decodeIfPresent(String.self, forKey: .foundationDetails)

        // Interior Features
        heating = try container.decodeIfPresent(String.self, forKey: .heating)
        cooling = try container.decodeIfPresent(String.self, forKey: .cooling)
        flooring = try container.decodeIfPresent(String.self, forKey: .flooring)
        appliances = try container.decodeIfPresent(String.self, forKey: .appliances)
        fireplaceFeatures = try container.decodeIfPresent(String.self, forKey: .fireplaceFeatures)
        fireplacesTotal = try container.decodeIfPresent(Int.self, forKey: .fireplacesTotal)
        basement = try container.decodeIfPresent(String.self, forKey: .basement)
        laundryFeatures = try container.decodeIfPresent(String.self, forKey: .laundryFeatures)
        interiorFeatures = try container.decodeIfPresent(String.self, forKey: .interiorFeatures)

        // Exterior Features
        exteriorFeatures = try container.decodeIfPresent(String.self, forKey: .exteriorFeatures)
        patioAndPorchFeatures = try container.decodeIfPresent(String.self, forKey: .patioAndPorchFeatures)
        poolFeatures = try container.decodeIfPresent(String.self, forKey: .poolFeatures)
        spaFeatures = try container.decodeIfPresent(String.self, forKey: .spaFeatures)
        waterfrontFeatures = try container.decodeIfPresent(String.self, forKey: .waterfrontFeatures)
        view = try container.decodeIfPresent(String.self, forKey: .view)
        fencing = try container.decodeIfPresent(String.self, forKey: .fencing)

        // HOA & Community
        hoaFee = try container.decodeIfPresent(Double.self, forKey: .hoaFee)
        hoaFeeFrequency = try container.decodeIfPresent(String.self, forKey: .hoaFeeFrequency)
        associationAmenities = try container.decodeIfPresent(String.self, forKey: .associationAmenities)
        petsAllowed = try container.decodeIfPresent(String.self, forKey: .petsAllowed)
        seniorCommunity = (try? container.decode(Bool.self, forKey: .seniorCommunity)) ?? false

        // Financial
        taxAnnual = try container.decodeIfPresent(Double.self, forKey: .taxAnnual)
        taxYear = try container.decodeIfPresent(Int.self, forKey: .taxYear)
        taxAssessedValue = try container.decodeIfPresent(Double.self, forKey: .taxAssessedValue)
        taxLegalDescription = try container.decodeIfPresent(String.self, forKey: .taxLegalDescription)
        taxLot = try container.decodeIfPresent(String.self, forKey: .taxLot)
        taxBlock = try container.decodeIfPresent(String.self, forKey: .taxBlock)
        taxMapNumber = try container.decodeIfPresent(String.self, forKey: .taxMapNumber)
        parcelNumber = try container.decodeIfPresent(String.self, forKey: .parcelNumber)
        additionalParcelsYn = (try? container.decode(Bool.self, forKey: .additionalParcelsYn)) ?? false
        additionalParcelsDescription = try container.decodeIfPresent(String.self, forKey: .additionalParcelsDescription)
        zoning = try container.decodeIfPresent(String.self, forKey: .zoning)
        zoningDescription = try container.decodeIfPresent(String.self, forKey: .zoningDescription)

        // Additional Interior Features
        windowFeatures = try container.decodeIfPresent(String.self, forKey: .windowFeatures)
        doorFeatures = try container.decodeIfPresent(String.self, forKey: .doorFeatures)
        attic = try container.decodeIfPresent(String.self, forKey: .attic)
        insulation = try container.decodeIfPresent(String.self, forKey: .insulation)
        accessibilityFeatures = try container.decodeIfPresent(String.self, forKey: .accessibilityFeatures)
        securityFeatures = try container.decodeIfPresent(String.self, forKey: .securityFeatures)
        commonWalls = try container.decodeIfPresent(String.self, forKey: .commonWalls)
        // Entry level can come as String or Int from API
        if let entryLevelString = try? container.decodeIfPresent(String.self, forKey: .entryLevel) {
            entryLevel = entryLevelString
        } else if let entryLevelInt = try? container.decodeIfPresent(Int.self, forKey: .entryLevel) {
            entryLevel = String(entryLevelInt)
        } else {
            entryLevel = nil
        }
        entryLocation = try container.decodeIfPresent(String.self, forKey: .entryLocation)
        levels = try container.decodeIfPresent(String.self, forKey: .levels)
        roomsTotal = try container.decodeIfPresent(Int.self, forKey: .roomsTotal)
        mainLevelBedrooms = try container.decodeIfPresent(Int.self, forKey: .mainLevelBedrooms)
        mainLevelBathrooms = try container.decodeIfPresent(Int.self, forKey: .mainLevelBathrooms)
        otherRooms = try container.decodeIfPresent(String.self, forKey: .otherRooms)
        masterBedroomLevel = try container.decodeIfPresent(String.self, forKey: .masterBedroomLevel)

        // Area Breakdown
        aboveGradeFinishedArea = try container.decodeIfPresent(Int.self, forKey: .aboveGradeFinishedArea)
        belowGradeFinishedArea = try container.decodeIfPresent(Int.self, forKey: .belowGradeFinishedArea)
        totalArea = try container.decodeIfPresent(Int.self, forKey: .totalArea)

        // Additional Exterior Features
        waterBodyName = try container.decodeIfPresent(String.self, forKey: .waterBodyName)
        foundationArea = try container.decodeIfPresent(Int.self, forKey: .foundationArea)

        // Additional Lot & Land
        lotSizeSquareFeet = try container.decodeIfPresent(Int.self, forKey: .lotSizeSquareFeet)
        landLeaseYn = (try? container.decode(Bool.self, forKey: .landLeaseYn)) ?? false
        landLeaseAmount = try container.decodeIfPresent(Double.self, forKey: .landLeaseAmount)
        landLeaseExpirationDate = try container.decodeIfPresent(String.self, forKey: .landLeaseExpirationDate)
        horseYn = (try? container.decode(Bool.self, forKey: .horseYn)) ?? false
        horseAmenities = try container.decodeIfPresent(String.self, forKey: .horseAmenities)
        vegetation = try container.decodeIfPresent(String.self, forKey: .vegetation)
        topography = try container.decodeIfPresent(String.self, forKey: .topography)
        frontageType = try container.decodeIfPresent(String.self, forKey: .frontageType)
        frontageLength = try container.decodeIfPresent(Int.self, forKey: .frontageLength)
        roadSurfaceType = try container.decodeIfPresent(String.self, forKey: .roadSurfaceType)
        roadFrontageType = try container.decodeIfPresent(String.self, forKey: .roadFrontageType)

        // Additional Parking
        attachedGarageYn = (try? container.decode(Bool.self, forKey: .attachedGarageYn)) ?? false
        carportSpaces = try container.decodeIfPresent(Int.self, forKey: .carportSpaces)
        carportYn = (try? container.decode(Bool.self, forKey: .carportYn)) ?? false
        drivewaySurface = try container.decodeIfPresent(String.self, forKey: .drivewaySurface)

        // Utilities & Systems
        utilities = try container.decodeIfPresent(String.self, forKey: .utilities)
        waterSource = try container.decodeIfPresent(String.self, forKey: .waterSource)
        sewer = try container.decodeIfPresent(String.self, forKey: .sewer)
        electric = try container.decodeIfPresent(String.self, forKey: .electric)
        electricOnPropertyYn = (try? container.decode(Bool.self, forKey: .electricOnPropertyYn)) ?? false
        gas = try container.decodeIfPresent(String.self, forKey: .gas)
        internetType = try container.decodeIfPresent(String.self, forKey: .internetType)
        cableAvailableYn = (try? container.decode(Bool.self, forKey: .cableAvailableYn)) ?? false
        smartHomeFeatures = try container.decodeIfPresent(String.self, forKey: .smartHomeFeatures)
        energyFeatures = try container.decodeIfPresent(String.self, forKey: .energyFeatures)
        greenBuildingCertification = try container.decodeIfPresent(String.self, forKey: .greenBuildingCertification)
        greenCertificationRating = try container.decodeIfPresent(String.self, forKey: .greenCertificationRating)
        greenEnergyEfficient = try container.decodeIfPresent(String.self, forKey: .greenEnergyEfficient)
        greenSustainability = try container.decodeIfPresent(String.self, forKey: .greenSustainability)

        // Additional Community & HOA
        communityFeatures = try container.decodeIfPresent(String.self, forKey: .communityFeatures)
        associationYn = (try? container.decode(Bool.self, forKey: .associationYn)) ?? false
        associationFee2 = try container.decodeIfPresent(Double.self, forKey: .associationFee2)
        associationFee2Frequency = try container.decodeIfPresent(String.self, forKey: .associationFee2Frequency)
        associationName = try container.decodeIfPresent(String.self, forKey: .associationName)
        associationPhone = try container.decodeIfPresent(String.self, forKey: .associationPhone)
        masterAssociationFee = try container.decodeIfPresent(Double.self, forKey: .masterAssociationFee)
        condoAssociationFee = try container.decodeIfPresent(Double.self, forKey: .condoAssociationFee)
        petRestrictions = try container.decodeIfPresent(String.self, forKey: .petRestrictions)

        // Schools
        schoolDistrict = try container.decodeIfPresent(String.self, forKey: .schoolDistrict)
        elementarySchool = try container.decodeIfPresent(String.self, forKey: .elementarySchool)
        middleOrJuniorSchool = try container.decodeIfPresent(String.self, forKey: .middleOrJuniorSchool)
        highSchool = try container.decodeIfPresent(String.self, forKey: .highSchool)

        // Additional Details
        yearBuiltSource = try container.decodeIfPresent(String.self, forKey: .yearBuiltSource)
        yearBuiltDetails = try container.decodeIfPresent(String.self, forKey: .yearBuiltDetails)
        yearBuiltEffective = try container.decodeIfPresent(Int.self, forKey: .yearBuiltEffective)
        buildingName = try container.decodeIfPresent(String.self, forKey: .buildingName)
        buildingFeatures = try container.decodeIfPresent(String.self, forKey: .buildingFeatures)
        propertyAttachedYn = (try? container.decode(Bool.self, forKey: .propertyAttachedYn)) ?? false
        propertyCondition = try container.decodeIfPresent(String.self, forKey: .propertyCondition)
        disclosures = try container.decodeIfPresent(String.self, forKey: .disclosures)
        exclusions = try container.decodeIfPresent(String.self, forKey: .exclusions)
        inclusions = try container.decodeIfPresent(String.self, forKey: .inclusions)
        ownership = try container.decodeIfPresent(String.self, forKey: .ownership)
        occupantType = try container.decodeIfPresent(String.self, forKey: .occupantType)
        possession = try container.decodeIfPresent(String.self, forKey: .possession)
        listingTerms = try container.decodeIfPresent(String.self, forKey: .listingTerms)
        listingService = try container.decodeIfPresent(String.self, forKey: .listingService)
        specialListingConditions = try container.decodeIfPresent(String.self, forKey: .specialListingConditions)

        // Rooms
        rooms = try container.decodeIfPresent([Room].self, forKey: .rooms)

        // Feature Flags
        hasPool = (try? container.decode(Bool.self, forKey: .hasPool)) ?? false
        hasWaterfront = (try? container.decode(Bool.self, forKey: .hasWaterfront)) ?? false
        hasView = (try? container.decode(Bool.self, forKey: .hasView)) ?? false
        hasWaterView = (try? container.decode(Bool.self, forKey: .hasWaterView)) ?? false
        hasSpa = (try? container.decode(Bool.self, forKey: .hasSpa)) ?? false
        hasFireplace = (try? container.decode(Bool.self, forKey: .hasFireplace)) ?? false
        hasGarage = (try? container.decode(Bool.self, forKey: .hasGarage)) ?? false
        hasCooling = (try? container.decode(Bool.self, forKey: .hasCooling)) ?? false

        // v6.64.0 / v284: Exclusive listing flag
        isExclusive = (try? container.decode(Bool.self, forKey: .isExclusive)) ?? false
        // v6.65.0: Custom badge text for exclusive listings
        exclusiveTag = try container.decodeIfPresent(String.self, forKey: .exclusiveTag)

        // Agent
        listingAgent = try container.decodeIfPresent(ListingAgent.self, forKey: .listingAgent)

        // Agent-Only Info (only present for agent users)
        agentOnlyInfo = try container.decodeIfPresent(AgentOnlyInfo.self, forKey: .agentOnlyInfo)

        // v6.60.9: Rental Details
        availabilityDate = try container.decodeIfPresent(String.self, forKey: .availabilityDate)
        availableNow = (try? container.decode(Bool.self, forKey: .availableNow)) ?? false
        leaseTerm = try container.decodeIfPresent(String.self, forKey: .leaseTerm)
        rentIncludes = try container.decodeIfPresent(String.self, forKey: .rentIncludes)
        securityDeposit = try container.decodeIfPresent(Double.self, forKey: .securityDeposit)
        firstMonthRequired = (try? container.decode(Bool.self, forKey: .firstMonthRequired)) ?? false
        lastMonthRequired = (try? container.decode(Bool.self, forKey: .lastMonthRequired)) ?? false
        referencesRequired = (try? container.decode(Bool.self, forKey: .referencesRequired)) ?? false
        depositRequired = (try? container.decode(Bool.self, forKey: .depositRequired)) ?? false
        insuranceRequired = (try? container.decode(Bool.self, forKey: .insuranceRequired)) ?? false

        // v6.60.9: Multi-unit rent breakdown
        unitRents = try container.decodeIfPresent([UnitRent].self, forKey: .unitRents)
        totalMonthlyRent = try container.decodeIfPresent(Double.self, forKey: .totalMonthlyRent)

        // v6.60.9: Investment metrics
        grossIncome = try container.decodeIfPresent(Double.self, forKey: .grossIncome)
        netOperatingIncome = try container.decodeIfPresent(Double.self, forKey: .netOperatingIncome)
        operatingExpense = try container.decodeIfPresent(Double.self, forKey: .operatingExpense)
        totalActualRent = try container.decodeIfPresent(Double.self, forKey: .totalActualRent)

        // v6.60.10: Enhanced HOA Information
        associationFeeIncludes = try container.decodeIfPresent(String.self, forKey: .associationFeeIncludes)
        optionalFee = try container.decodeIfPresent(Double.self, forKey: .optionalFee)
        optionalFeeIncludes = try container.decodeIfPresent(String.self, forKey: .optionalFeeIncludes)
        ownerOccupiedUnits = try container.decodeIfPresent(Int.self, forKey: .ownerOccupiedUnits)

        // v6.60.10: Land/Lot specific fields
        roadResponsibility = try container.decodeIfPresent(String.self, forKey: .roadResponsibility)
        numberOfLots = try container.decodeIfPresent(Int.self, forKey: .numberOfLots)
        pastureArea = try container.decodeIfPresent(Double.self, forKey: .pastureArea)
        cultivatedArea = try container.decodeIfPresent(Double.self, forKey: .cultivatedArea)
        woodedArea = try container.decodeIfPresent(Double.self, forKey: .woodedArea)

        // v6.60.10: Commercial property fields
        grossScheduledIncome = try container.decodeIfPresent(Double.self, forKey: .grossScheduledIncome)
        existingLeaseType = try container.decodeIfPresent(String.self, forKey: .existingLeaseType)
        tenantPays = try container.decodeIfPresent(String.self, forKey: .tenantPays)
        lenderOwned = (try? container.decode(Bool.self, forKey: .lenderOwned)) ?? false
        concessionsAmount = try container.decodeIfPresent(Double.self, forKey: .concessionsAmount)
        capRate = try container.decodeIfPresent(Double.self, forKey: .capRate)
        businessType = try container.decodeIfPresent(String.self, forKey: .businessType)
        developmentStatus = try container.decodeIfPresent(String.self, forKey: .developmentStatus)

        // v6.60.10: MA-specific disclosure fields
        leadPaint = (try? container.decode(Bool.self, forKey: .leadPaint)) ?? false
        title5Compliant = (try? container.decode(Bool.self, forKey: .title5Compliant)) ?? false
        percTestDone = (try? container.decode(Bool.self, forKey: .percTestDone)) ?? false
        percTestDate = try container.decodeIfPresent(String.self, forKey: .percTestDate)
        shortSale = (try? container.decode(Bool.self, forKey: .shortSale)) ?? false

        // v6.66.0: Enhanced property details
        homeWarranty = (try? container.decode(Bool.self, forKey: .homeWarranty)) ?? false
        heatZones = try container.decodeIfPresent(Int.self, forKey: .heatZones)
        coolZones = try container.decodeIfPresent(Int.self, forKey: .coolZones)
        yearRound = (try? container.decode(Bool.self, forKey: .yearRound)) ?? false
        showingDeferralDate = try container.decodeIfPresent(String.self, forKey: .showingDeferralDate)
        bedsByFloor = try container.decodeIfPresent([String: Int].self, forKey: .bedsByFloor)
        bathsByFloor = try container.decodeIfPresent([String: Int].self, forKey: .bathsByFloor)

        // v6.68.0: Data freshness indicator
        modificationTimestamp = try container.decodeIfPresent(String.self, forKey: .modificationTimestamp)

        // v6.68.19: Enhanced room level tracking
        specialRooms = try container.decodeIfPresent([Room].self, forKey: .specialRooms)
        computedRoomCounts = try container.decodeIfPresent(ComputedRoomCounts.self, forKey: .computedRoomCounts)

        isFavorite = false
    }

    // MARK: - Computed Properties

    var formattedPrice: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }

    var fullAddress: String {
        "\(address), \(city), \(state) \(zip)"
    }

    var location: CLLocationCoordinate2D? {
        guard let lat = latitude, let lng = longitude else { return nil }
        return CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var imageURLs: [URL] {
        var urls: [URL] = []
        if let photoUrl = photoUrl, let url = URL(string: photoUrl) {
            urls.append(url)
        }
        if let photos = photos {
            urls.append(contentsOf: photos.compactMap { URL(string: $0) })
        }
        return urls
    }

    var isPriceReduced: Bool {
        guard let original = originalPrice else { return false }
        return original > price
    }

    var priceReductionAmount: Int? {
        guard let original = originalPrice, original > price else { return nil }
        return original - price
    }

    var priceReductionPercent: Double? {
        guard let original = originalPrice, original > price else { return nil }
        return Double(original - price) / Double(original) * 100
    }

    var isNewListing: Bool {
        guard let dom = dom else { return false }
        return dom <= 7
    }

    var formattedBathroomsDetailed: String {
        if let full = bathsFull, let half = bathsHalf, half > 0 {
            return "\(full) full, \(half) half"
        } else if let full = bathsFull {
            return "\(full) full"
        }
        let bathString = baths.truncatingRemainder(dividingBy: 1) == 0
            ? "\(Int(baths))"
            : String(format: "%.1f", baths)
        return bathString
    }

    var formattedHoaFee: String? {
        guard let fee = hoaFee, fee > 0 else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let amount = formatter.string(from: NSNumber(value: fee)) ?? "$\(Int(fee))"
        if let frequency = hoaFeeFrequency {
            return "\(amount)/\(frequency.lowercased())"
        }
        return amount
    }

    var formattedTaxAnnual: String? {
        guard let tax = taxAnnual, tax > 0 else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let amount = formatter.string(from: NSNumber(value: tax)) ?? "$\(Int(tax))"
        if let year = taxYear {
            return "\(amount) (\(year))"
        }
        return amount
    }

    var formattedLotSize: String? {
        if let acres = lotSizeAcres, acres > 0 {
            return String(format: "%.2f acres", acres)
        }
        guard let sqft = lotSize, sqft > 0 else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: sqft)).map { "\($0) sqft" }
    }

    var nextOpenHouse: PropertyOpenHouse? {
        openHouses?.first
    }

    /// v6.68.0: Data freshness indicator - returns relative time since last update
    var dataFreshnessText: String? {
        guard let timestamp = modificationTimestamp else { return nil }

        // Try ISO8601 format with timezone
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        var date = formatter.date(from: timestamp)

        // Try without fractional seconds
        if date == nil {
            formatter.formatOptions = [.withInternetDateTime]
            date = formatter.date(from: timestamp)
        }

        guard let modDate = date else { return nil }

        let now = Date()
        let interval = now.timeIntervalSince(modDate)

        // Don't show if in the future or more than 30 days old
        if interval < 0 || interval > 30 * 24 * 60 * 60 {
            return nil
        }

        let relativeFormatter = RelativeDateTimeFormatter()
        relativeFormatter.unitsStyle = .abbreviated
        return "Updated \(relativeFormatter.localizedString(for: modDate, relativeTo: now))"
    }

    /// Get all highlight tags for this property
    var highlightTags: [PropertyHighlight] {
        var tags: [PropertyHighlight] = []
        if hasPool { tags.append(.pool) }
        if hasWaterfront { tags.append(.waterfront) }
        if hasView { tags.append(.view) }
        if hasGarage { tags.append(.garage) }
        if hasFireplace { tags.append(.fireplace) }
        if basement != nil { tags.append(.basement) }
        if hasCooling { tags.append(.centralAC) }
        return tags
    }
}

// MARK: - Property Highlight Tags

enum PropertyHighlight: String, CaseIterable {
    case pool = "Pool"
    case waterfront = "Waterfront"
    case view = "View"
    case garage = "Garage"
    case fireplace = "Fireplace"
    case basement = "Basement"
    case centralAC = "Central A/C"
    case newConstruction = "New Construction"

    var icon: String {
        switch self {
        case .pool: return "drop.fill"
        case .waterfront: return "water.waves"
        case .view: return "mountain.2.fill"
        case .garage: return "car.fill"
        case .fireplace: return "flame.fill"
        case .basement: return "rectangle.split.1x2.fill"
        case .centralAC: return "snowflake"
        case .newConstruction: return "hammer.fill"
        }
    }

    var color: String {
        switch self {
        case .pool: return "#0891B2"
        case .waterfront: return "#0EA5E9"
        case .view: return "#059669"
        case .garage: return "#6366F1"
        case .fireplace: return "#F97316"
        case .basement: return "#8B5CF6"
        case .centralAC: return "#06B6D4"
        case .newConstruction: return "#EAB308"
        }
    }
}

// MARK: - Property History

/// A single event in the property's price/status history
/// v6.67.1: Enhanced with status changes, agent info, and detailed event types
struct PropertyHistoryEvent: Decodable, Identifiable {
    var id: String { "\(date ?? "unknown")-\(event)-\(eventType ?? "default")" }
    let date: String?  // Can be null for events without a specific date
    let event: String
    let price: Int?
    let change: Int?

    // v6.67.1: Enhanced fields from tracked history
    let eventType: String?          // new_listing, price_change, status_change, pending, sold, off_market, back_on_market, agent_change, contingency_change
    let oldStatus: String?          // Previous status (e.g., "Active")
    let newStatus: String?          // New status (e.g., "Pending")
    let daysOnMarket: Int?          // DOM at this event (for status changes)
    let agentName: String?          // Agent name (for new_listing, agent_change)
    let officeName: String?         // Office name (for new_listing)
    let pricePerSqft: Int?          // Price per sqft at this event
    let details: String?            // Human-readable description
    // v6.68.0: Agent change fields
    let oldAgent: String?           // Previous agent (for agent_change)
    let newAgent: String?           // New agent (for agent_change)

    enum CodingKeys: String, CodingKey {
        case date, event, price, change
        case eventType = "event_type"
        case oldStatus = "old_status"
        case newStatus = "new_status"
        case daysOnMarket = "days_on_market"
        case agentName = "agent_name"
        case officeName = "office_name"
        case pricePerSqft = "price_per_sqft"
        case details
        case oldAgent = "old_agent"
        case newAgent = "new_agent"
    }

    /// Formatted date for display
    var formattedDate: String? {
        guard let dateString = date else { return nil }
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"
        guard let parsedDate = inputFormatter.date(from: dateString) else { return dateString }
        let outputFormatter = DateFormatter()
        outputFormatter.dateFormat = "MMM d, yyyy"
        return outputFormatter.string(from: parsedDate)
    }

    /// Formatted date with time for recent events
    var formattedDateTime: String? {
        guard let dateString = date else { return nil }
        let inputFormatter = DateFormatter()
        // Try datetime format first
        inputFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let parsedDate = inputFormatter.date(from: dateString) {
            let outputFormatter = DateFormatter()
            outputFormatter.dateFormat = "MMM d, yyyy 'at' h:mm a"
            return outputFormatter.string(from: parsedDate)
        }
        // Fall back to date-only format
        inputFormatter.dateFormat = "yyyy-MM-dd"
        guard let parsedDate = inputFormatter.date(from: dateString) else { return dateString }
        let outputFormatter = DateFormatter()
        outputFormatter.dateFormat = "MMM d, yyyy"
        return outputFormatter.string(from: parsedDate)
    }

    /// Icon for the event type
    var icon: String {
        // Use eventType if available for more specific icons
        if let type = eventType {
            switch type {
            case "new_listing": return "house.fill"
            case "price_change": return change ?? 0 < 0 ? "arrow.down.circle.fill" : "arrow.up.circle.fill"
            case "status_change": return "arrow.triangle.swap"
            case "pending": return "clock.fill"
            case "sold": return "checkmark.circle.fill"
            case "off_market": return "xmark.circle.fill"
            case "back_on_market": return "arrow.uturn.left.circle.fill"
            case "agent_change": return "person.2.fill"
            case "contingency_change": return "doc.text.fill"
            default: break
            }
        }
        // Fallback to event name matching
        switch event.lowercased() {
        case "listed", "listed for sale": return "house.fill"
        case "sold": return "checkmark.circle.fill"
        case "price reduced", "price change": return "arrow.down.circle.fill"
        case "price increased": return "arrow.up.circle.fill"
        case "pending", "pending sale": return "clock.fill"
        case "back on market": return "arrow.uturn.left.circle.fill"
        case "listing expired", "withdrawn from market", "listing canceled": return "xmark.circle.fill"
        default: return "circle.fill"
        }
    }

    /// Color for the event type (hex string)
    var color: String {
        // Use eventType if available
        if let type = eventType {
            switch type {
            case "new_listing": return "#059669"     // Green
            case "price_change": return change ?? 0 < 0 ? "#DC2626" : "#059669"  // Red for reduction, green for increase
            case "status_change": return "#6366F1"  // Indigo
            case "pending": return "#D97706"        // Orange
            case "sold": return "#059669"           // Green
            case "off_market": return "#6B7280"     // Gray
            case "back_on_market": return "#6366F1" // Indigo
            case "agent_change": return "#8B5CF6"   // Purple
            case "contingency_change": return "#0EA5E9" // Sky blue
            default: break
            }
        }
        // Fallback to event name matching
        switch event.lowercased() {
        case "listed", "listed for sale": return "#059669"  // Green
        case "sold": return "#059669"       // Green (sold is positive)
        case "price reduced", "price change": return "#DC2626"  // Red
        case "price increased": return "#059669"  // Green
        case "pending", "pending sale": return "#D97706"  // Orange
        case "back on market": return "#6366F1"   // Indigo
        case "listing expired", "withdrawn from market", "listing canceled": return "#6B7280"  // Gray
        default: return "#6B7280"  // Gray
        }
    }

    /// Whether this is a status change event that should show transition arrow
    var isStatusChange: Bool {
        eventType == "status_change" || eventType == "pending" || eventType == "sold" || eventType == "off_market"
    }

    /// Status transition text (e.g., "Active → Pending")
    var statusTransition: String? {
        guard let old = oldStatus, let new = newStatus else { return nil }
        return "\(old) → \(new)"
    }

    // v6.68.0: Agent change helpers
    /// Whether this event is an agent change
    var isAgentChange: Bool {
        eventType == "agent_change"
    }

    /// Agent transition text (e.g., "John Smith → Jane Doe")
    var agentTransition: String? {
        guard let old = oldAgent, let new = newAgent else { return nil }
        return "\(old) → \(new)"
    }
}

/// Full property history response from the API
struct PropertyHistoryData: Decodable {
    let listingId: String
    let mlsNumber: String
    let currentStatus: String
    let daysOnMarket: Int
    let originalPrice: Int
    let finalPrice: Int
    let totalPriceChange: Int
    let totalPriceChangePercent: Double
    let events: [PropertyHistoryEvent]

    // v6.67.1: Enhanced market insights from tracked history
    let listingContractDate: String?     // Date when listing went under contract
    let priceChangesCount: Int?          // Number of price changes
    let statusChangesCount: Int?         // Number of status changes
    let priceRangePercent: Double?       // Price volatility (high-low range as % of original)
    let hasTrackedHistory: Bool?         // Whether this is from tracked history

    // v6.67.2: Granular time on market
    let listingTimestamp: String?        // Full datetime when property was listed
    let hoursOnMarket: Int?              // Total hours on market
    let minutesOnMarket: Int?            // Total minutes on market
    let timeOnMarketText: String?        // Human-readable text like "6 hours, 53 min"

    enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case mlsNumber = "mls_number"
        case currentStatus = "current_status"
        case daysOnMarket = "days_on_market"
        case originalPrice = "original_price"
        case finalPrice = "final_price"
        case totalPriceChange = "total_price_change"
        case totalPriceChangePercent = "total_price_change_percent"
        case events
        case listingContractDate = "listing_contract_date"
        case priceChangesCount = "price_changes_count"
        case statusChangesCount = "status_changes_count"
        case priceRangePercent = "price_range_percent"
        case hasTrackedHistory = "has_tracked_history"
        case listingTimestamp = "listing_timestamp"
        case hoursOnMarket = "hours_on_market"
        case minutesOnMarket = "minutes_on_market"
        case timeOnMarketText = "time_on_market_text"
    }

    /// Formatted price change for display
    var formattedPriceChange: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let amount = formatter.string(from: NSNumber(value: abs(totalPriceChange))) ?? "$\(abs(totalPriceChange))"
        if totalPriceChange < 0 {
            return "-\(amount)"
        } else if totalPriceChange > 0 {
            return "+\(amount)"
        }
        return amount
    }

    /// Formatted percentage change for display
    var formattedPercentChange: String {
        String(format: "%.1f%%", abs(totalPriceChangePercent))
    }

    /// Market insights summary line
    var marketInsightsSummary: String? {
        var parts: [String] = []
        if let priceChanges = priceChangesCount, priceChanges > 0 {
            parts.append("\(priceChanges) price change\(priceChanges > 1 ? "s" : "")")
        }
        if let statusChanges = statusChangesCount, statusChanges > 1 {
            parts.append("\(statusChanges) status changes")
        }
        return parts.isEmpty ? nil : parts.joined(separator: " • ")
    }
}

// MARK: - Address History (Previous Sales at Same Address)

/// Response wrapper for address history endpoint
struct AddressHistoryResponse: Decodable {
    let success: Bool
    let data: AddressHistoryData
}

/// Address history data containing previous sales at the same address
struct AddressHistoryData: Decodable {
    let listingId: String
    let address: String
    let previousSales: [PreviousSale]
    let totalCount: Int

    enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case address
        case previousSales = "previous_sales"
        case totalCount = "total_count"
    }

    /// Check if there are any previous sales to display
    var hasPreviousSales: Bool {
        totalCount > 0
    }
}

/// Individual previous sale record at the same address
struct PreviousSale: Decodable, Identifiable {
    let mlsNumber: String
    let listDate: String?
    let listPrice: Int?
    let closeDate: String?
    let closePrice: Int?
    let daysOnMarket: Int?
    let status: String
    let priceChange: Int?
    let priceChangePercent: Double?

    var id: String { mlsNumber }

    enum CodingKeys: String, CodingKey {
        case mlsNumber = "mls_number"
        case listDate = "list_date"
        case listPrice = "list_price"
        case closeDate = "close_date"
        case closePrice = "close_price"
        case daysOnMarket = "days_on_market"
        case status
        case priceChange = "price_change"
        case priceChangePercent = "price_change_percent"
    }

    /// Parse close date to Date object
    var closeDateFormatted: String? {
        guard let closeDate = closeDate else { return nil }
        let isoFormatter = ISO8601DateFormatter()
        isoFormatter.formatOptions = [.withInternetDateTime]
        if let date = isoFormatter.date(from: closeDate) {
            let displayFormatter = DateFormatter()
            displayFormatter.dateFormat = "MMM d, yyyy"
            return displayFormatter.string(from: date)
        }
        return nil
    }

    /// Parse list date to Date object
    var listDateFormatted: String? {
        guard let listDate = listDate else { return nil }
        let isoFormatter = ISO8601DateFormatter()
        isoFormatter.formatOptions = [.withInternetDateTime]
        if let date = isoFormatter.date(from: listDate) {
            let displayFormatter = DateFormatter()
            displayFormatter.dateFormat = "MMM d, yyyy"
            return displayFormatter.string(from: date)
        }
        return nil
    }

    /// Formatted close price for display
    var formattedClosePrice: String? {
        guard let price = closePrice else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price))
    }

    /// Formatted list price for display
    var formattedListPrice: String? {
        guard let price = listPrice else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price))
    }

    /// Price change formatted for display (e.g., "+$25,000" or "-$10,000")
    var formattedPriceChange: String? {
        guard let change = priceChange else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let amount = formatter.string(from: NSNumber(value: abs(change))) ?? "$\(abs(change))"
        return change >= 0 ? "+\(amount)" : "-\(amount)"
    }

    /// Was the property sold above or below list price?
    var soldAboveOrBelow: String? {
        guard let change = priceChange else { return nil }
        if change > 0 {
            return "above asking"
        } else if change < 0 {
            return "below asking"
        }
        return "at asking"
    }
}

// MARK: - Listing Agent (for property listings)

struct ListingAgent: Decodable, Identifiable {
    var id: String { name ?? UUID().uuidString }
    let name: String?
    let email: String?
    let phone: String?
    let photoUrl: String?
    let officeName: String?
    let officePhone: String?
    let agentMlsId: String?
    let officeMlsId: String?

    enum CodingKeys: String, CodingKey {
        case name
        case email
        case phone
        case photoUrl = "photo_url"
        case officeName = "office_name"
        case officePhone = "office_phone"
        case agentMlsId = "agent_mls_id"
        case officeMlsId = "office_mls_id"
    }

    var displayName: String {
        name ?? "Listing Agent"
    }
}

// MARK: - Open House (MLS Schedule Data)

struct PropertyOpenHouse: Decodable, Identifiable {
    var id: String { startTime }
    let startTime: String
    let endTime: String
    let remarks: String?

    enum CodingKeys: String, CodingKey {
        case startTime = "start_time"
        case endTime = "end_time"
        case remarks
    }

    /// Parse start time string into Date
    var startDate: Date? {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = formatter.date(from: startTime) { return date }
        // Try without fractional seconds
        formatter.formatOptions = [.withInternetDateTime]
        if let date = formatter.date(from: startTime) { return date }
        // Try MySQL format
        let mysqlFormatter = DateFormatter()
        mysqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        mysqlFormatter.timeZone = TimeZone(identifier: "America/New_York")
        return mysqlFormatter.date(from: startTime)
    }

    /// Parse end time string into Date
    var endDate: Date? {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = formatter.date(from: endTime) { return date }
        formatter.formatOptions = [.withInternetDateTime]
        if let date = formatter.date(from: endTime) { return date }
        let mysqlFormatter = DateFormatter()
        mysqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        mysqlFormatter.timeZone = TimeZone(identifier: "America/New_York")
        return mysqlFormatter.date(from: endTime)
    }

    /// Formatted date for display (e.g., "Sun, Dec 22")
    var formattedDate: String {
        guard let date = startDate else { return startTime }
        let formatter = DateFormatter()
        formatter.dateFormat = "EEE, MMM d"
        return formatter.string(from: date)
    }

    /// Formatted time range for display (e.g., "1:00 PM - 3:00 PM")
    var formattedTimeRange: String {
        guard let start = startDate, let end = endDate else {
            return "\(startTime) - \(endTime)"
        }
        let formatter = DateFormatter()
        formatter.dateFormat = "h:mm a"
        return "\(formatter.string(from: start)) - \(formatter.string(from: end))"
    }

    /// Short format for cards (e.g., "Open Sun 1-3pm")
    var formattedShort: String {
        guard let start = startDate, let end = endDate else {
            return "Open House"
        }
        let dayFormatter = DateFormatter()
        dayFormatter.dateFormat = "EEE"
        let timeFormatter = DateFormatter()
        timeFormatter.dateFormat = "h"
        let endTimeFormatter = DateFormatter()
        endTimeFormatter.dateFormat = "ha"
        return "\(dayFormatter.string(from: start)) \(timeFormatter.string(from: start))-\(endTimeFormatter.string(from: end).lowercased())"
    }
}

// MARK: - Comprehensive Search Filters (Matching Web 100%)

struct PropertySearchFilters: Equatable {
    // Pagination
    var page: Int = 1
    var perPage: Int = SearchConstants.defaultPerPage

    // Listing Type (For Sale vs For Rent) - PRIMARY FILTER
    var listingType: ListingType = .forSale

    // Price Range
    var minPrice: Int?
    var maxPrice: Int?

    // Beds (array for multi-select: [1,2,3,4,5+])
    var beds: Set<Int> = []

    // Baths minimum
    var minBaths: Double?

    // Garage & Parking
    var minGarageSpaces: Int?
    var minParkingTotal: Int?

    // Property Type - Combined property_type and property_sub_type filters
    // Uses CombinedPropertyType enum for unified filter options
    // Default to "house" and "condo" for For Sale listing type
    var propertyTypes: Set<CombinedPropertyType> = [.house, .condo]

    // Status (multi-select)
    var statuses: Set<PropertyStatus> = [.active]

    // Structure Type (multi-select)
    var structureTypes: Set<String> = []

    // Architectural Style (multi-select)
    var architecturalStyles: Set<String> = []

    // Square Footage
    var minSqft: Int?
    var maxSqft: Int?

    // Year Built
    var minYearBuilt: Int?
    var maxYearBuilt: Int?

    // Lot Size (in sqft)
    var minLotSize: Double?
    var maxLotSize: Double?

    // Entry Level (floor)
    var minEntryLevel: Int?
    var maxEntryLevel: Int?

    // Stories
    var minStories: Int?
    var maxStories: Int?

    // Open House Only
    var openHouseOnly: Bool = false

    // Property Type (Residential, Commercial, etc.)
    var propertyType: String?

    // Location Filters
    var cities: Set<String> = []
    var neighborhoods: Set<String> = []
    var zips: Set<String> = []
    var address: String?
    var mlsNumber: String?
    var listingKey: String?  // MD5 hash identifier for lookup
    var streetName: String?  // For street name searches (e.g., "Main Street")

    // Geographic Filters
    var mapBounds: MapBounds?
    var polygonCoordinates: [CLLocationCoordinate2D]?

    // Amenity Filters (all boolean)
    var hasSpa: Bool = false
    var hasWaterfront: Bool = false
    var hasView: Bool = false
    var hasWaterView: Bool = false
    var isAttached: Bool?
    var isLenderOwned: Bool = false
    var isSeniorCommunity: Bool = false
    var hasOutdoorSpace: Bool = false
    var hasDPR: Bool = false
    var hasCooling: Bool = false
    var hasPool: Bool = false
    var hasFireplace: Bool = false
    var hasGarage: Bool = false
    var hasVirtualTour: Bool = false

    // Availability
    var availableBy: Date?
    var availableNow: Bool = false

    // Rental-specific filters (Phase 1)
    // Granular pet filters - v6.60.2: supports dogs, cats, no pets, negotiable
    var petsDogs: Bool = false         // Dogs allowed
    var petsCats: Bool = false         // Cats allowed
    var petsNone: Bool = false         // No pets allowed
    var petsNegotiable: Bool = false   // Pet policy is negotiable/unknown
    var laundryTypes: Set<String> = [] // "In Unit", "In Building", "Hookups", etc.
    var leaseTerms: Set<String> = []   // "12 months", "6 months", "Monthly", "Weekly"

    // Days on Market
    var maxDaysOnMarket: Int?
    var minDaysOnMarket: Int?

    // Price Changes
    var priceReduced: Bool = false
    var newListing: Bool = false // Listed within X days
    var newListingDays: Int = 7

    // v6.64.0 / v284: Exclusive listings filter
    var exclusiveOnly: Bool = false

    // School Quality Filters (Phase 5 - BMN Schools integration)
    var schoolGrade: String?       // Minimum grade: "A", "B", "C" (nil = any)

    // Elementary School (K-4) - within 1 mile
    var nearAElementary: Bool = false    // Within 1mi of A-rated elementary
    var nearABElementary: Bool = false   // Within 1mi of A or B rated elementary

    // Middle School (4-8) - within 1 mile
    var nearAMiddle: Bool = false        // Within 1mi of A-rated middle school
    var nearABMiddle: Bool = false       // Within 1mi of A or B rated middle school

    // High School (9-12) - within 1 mile
    var nearAHigh: Bool = false          // Within 1mi of A-rated high school
    var nearABHigh: Bool = false         // Within 1mi of A or B rated high school

    var schoolDistrictId: Int?     // Filter by specific district

    // Sort
    var sort: SortOption = .dateDesc

    // Search text (for autocomplete)
    var searchText: String?

    func toDictionary() -> [String: Any] {
        var dict: [String: Any] = [
            "page": page,
            "per_page": perPage
        ]

        // Listing Type (For Sale vs For Rent)
        dict["listing_type"] = listingType.rawValue

        // Property Types - Convert combined types to API parameters
        // Each CombinedPropertyType maps to property_type and optionally property_sub_type
        if !propertyTypes.isEmpty {
            var apiPropertyTypes: Set<String> = []
            var apiPropertySubTypes: Set<String> = []

            for propType in propertyTypes {
                apiPropertyTypes.formUnion(propType.apiPropertyTypes)
                if let subTypes = propType.apiPropertySubTypes {
                    apiPropertySubTypes.formUnion(subTypes)
                }
            }

            dict["property_type"] = Array(apiPropertyTypes)

            if !apiPropertySubTypes.isEmpty {
                dict["property_sub_type"] = Array(apiPropertySubTypes)
            }
        }

        // Price (API expects min_price/max_price)
        if let minPrice = minPrice { dict["min_price"] = minPrice }
        if let maxPrice = maxPrice { dict["max_price"] = maxPrice }

        // Beds (API expects single min value, not array)
        if !beds.isEmpty { dict["beds"] = beds.min() ?? 0 }

        // Baths (API expects "baths" not "baths_min")
        if let minBaths = minBaths { dict["baths"] = minBaths }

        // Garage & Parking
        if let minGarageSpaces = minGarageSpaces { dict["garage_spaces_min"] = minGarageSpaces }
        if let minParkingTotal = minParkingTotal { dict["parking_total_min"] = minParkingTotal }

        // Status - only send if different from default (just Active)
        // API defaults to Active status if not specified
        if !statuses.isEmpty && statuses != [.active] {
            dict["status"] = statuses.map { $0.rawValue }
        }

        // Structure Types
        if !structureTypes.isEmpty { dict["structure_type"] = Array(structureTypes) }

        // Architectural Styles
        if !architecturalStyles.isEmpty { dict["architectural_style"] = Array(architecturalStyles) }

        // Square Footage
        if let minSqft = minSqft { dict["sqft_min"] = minSqft }
        if let maxSqft = maxSqft { dict["sqft_max"] = maxSqft }

        // Year Built
        if let minYearBuilt = minYearBuilt { dict["year_built_min"] = minYearBuilt }
        if let maxYearBuilt = maxYearBuilt { dict["year_built_max"] = maxYearBuilt }

        // Lot Size
        if let minLotSize = minLotSize { dict["lot_size_min"] = minLotSize }
        if let maxLotSize = maxLotSize { dict["lot_size_max"] = maxLotSize }

        // Entry Level
        if let minEntryLevel = minEntryLevel { dict["entry_level_min"] = minEntryLevel }
        if let maxEntryLevel = maxEntryLevel { dict["entry_level_max"] = maxEntryLevel }

        // Stories
        if let minStories = minStories { dict["stories_min"] = minStories }
        if let maxStories = maxStories { dict["stories_max"] = maxStories }

        // Open House
        if openHouseOnly { dict["open_house_only"] = true }

        // Location
        if !cities.isEmpty { dict["city"] = Array(cities) }
        if !neighborhoods.isEmpty { dict["neighborhood"] = Array(neighborhoods) }
        if !zips.isEmpty { dict["zip"] = Array(zips) }
        if let address = address, !address.isEmpty { dict["address"] = address }
        if let mlsNumber = mlsNumber, !mlsNumber.isEmpty { dict["mls_number"] = mlsNumber }
        if let listingKey = listingKey, !listingKey.isEmpty { dict["listing_key"] = listingKey }
        if let streetName = streetName, !streetName.isEmpty { dict["street_name"] = streetName }

        // Geographic - bounds and polygon only (radius search removed)
        // Send bounds as CSV string: "south,west,north,east" (API parses with explode)
        if let bounds = mapBounds {
            dict["bounds"] = "\(bounds.south),\(bounds.west),\(bounds.north),\(bounds.east)"
        }
        if let polygon = polygonCoordinates, !polygon.isEmpty {
            dict["polygon"] = polygon.map { ["lat": $0.latitude, "lng": $0.longitude] }
        }

        // Amenities
        if hasSpa { dict["SpaYN"] = true }
        if hasWaterfront { dict["WaterfrontYN"] = true }
        if hasView { dict["ViewYN"] = true }
        if hasWaterView { dict["MLSPIN_WATERVIEW_FLAG"] = true }
        if let isAttached = isAttached { dict["PropertyAttachedYN"] = isAttached }
        if isLenderOwned { dict["MLSPIN_LENDER_OWNED"] = true }
        if isSeniorCommunity { dict["SeniorCommunityYN"] = true }
        if hasOutdoorSpace { dict["MLSPIN_OUTDOOR_SPACE_AVAILABLE"] = true }
        if hasDPR { dict["MLSPIN_DPR_Flag"] = true }
        if hasCooling { dict["CoolingYN"] = true }
        if hasPool { dict["PoolPrivateYN"] = true }
        if hasFireplace { dict["FireplaceYN"] = true }
        if hasGarage { dict["GarageYN"] = true }
        if hasVirtualTour { dict["has_virtual_tour"] = true }

        // Availability
        if let availableBy = availableBy {
            let formatter = ISO8601DateFormatter()
            dict["available_by"] = formatter.string(from: availableBy)
        }
        if availableNow { dict["MLSPIN_AvailableNow"] = true }

        // Rental-specific filters - granular pet filters (v6.60.2)
        if petsDogs { dict["pets_dogs"] = 1 }
        if petsCats { dict["pets_cats"] = 1 }
        if petsNone { dict["pets_none"] = 1 }
        if petsNegotiable { dict["pets_negotiable"] = 1 }
        if !laundryTypes.isEmpty { dict["laundry_features"] = Array(laundryTypes) }
        if !leaseTerms.isEmpty { dict["lease_term"] = Array(leaseTerms) }

        // Days on Market
        if let maxDaysOnMarket = maxDaysOnMarket { dict["max_dom"] = maxDaysOnMarket }
        if let minDaysOnMarket = minDaysOnMarket { dict["min_dom"] = minDaysOnMarket }

        // Price Changes
        if priceReduced { dict["price_reduced"] = true }
        if newListing { dict["new_listing_days"] = newListingDays }

        // v6.64.0 / v284: Exclusive listings filter
        if exclusiveOnly { dict["exclusive_only"] = true }

        // School Quality Filters
        if let schoolGrade = schoolGrade { dict["school_grade"] = schoolGrade }
        // Elementary (K-4)
        if nearAElementary { dict["near_a_elementary"] = true }
        if nearABElementary { dict["near_ab_elementary"] = true }
        // Middle (4-8)
        if nearAMiddle { dict["near_a_middle"] = true }
        if nearABMiddle { dict["near_ab_middle"] = true }
        // High (9-12)
        if nearAHigh { dict["near_a_high"] = true }
        if nearABHigh { dict["near_ab_high"] = true }
        if let schoolDistrictId = schoolDistrictId { dict["school_district_id"] = schoolDistrictId }

        // Sort - only send if not the default (newest first)
        // API defaults to newest listings first if not specified
        if sort != .dateDesc {
            dict["sort"] = sort.apiValue
        }

        // Search text
        if let searchText = searchText, !searchText.isEmpty { dict["search"] = searchText }

        return dict
    }

    /// Converts filters to dictionary using web-compatible keys for saved search storage.
    /// This uses the same key format as the web platform to ensure saved searches
    /// created on iOS can be properly opened/displayed on web.
    func toSavedSearchDictionary() -> [String: Any] {
        var dict: [String: Any] = [:]

        // Listing Type
        dict["listing_type"] = listingType.rawValue

        // Property Types - Convert combined types to web-compatible format
        if !propertyTypes.isEmpty {
            var apiPropertyTypes: Set<String> = []
            var apiPropertySubTypes: Set<String> = []

            for propType in propertyTypes {
                apiPropertyTypes.formUnion(propType.apiPropertyTypes)
                if let subTypes = propType.apiPropertySubTypes {
                    apiPropertySubTypes.formUnion(subTypes)
                }
            }

            dict["PropertyType"] = Array(apiPropertyTypes)

            if !apiPropertySubTypes.isEmpty {
                dict["home_type"] = Array(apiPropertySubTypes)
            }
        }

        // Price - web uses price_min/price_max (suffix pattern)
        if let minPrice = minPrice { dict["price_min"] = minPrice }
        if let maxPrice = maxPrice { dict["price_max"] = maxPrice }

        // Beds - web uses beds_min (minimum beds, not array)
        if !beds.isEmpty { dict["beds_min"] = beds.min() ?? 0 }

        // Baths - web uses baths_min
        if let minBaths = minBaths { dict["baths_min"] = minBaths }

        // Garage & Parking
        if let minGarageSpaces = minGarageSpaces { dict["garage_spaces_min"] = minGarageSpaces }
        if let minParkingTotal = minParkingTotal { dict["parking_total_min"] = minParkingTotal }

        // Status - always save if not empty (web saves even default Active)
        if !statuses.isEmpty {
            dict["status"] = statuses.map { $0.rawValue }
        }

        // Structure Types
        if !structureTypes.isEmpty { dict["structure_type"] = Array(structureTypes) }

        // Architectural Styles
        if !architecturalStyles.isEmpty { dict["architectural_style"] = Array(architecturalStyles) }

        // Square Footage - web uses sqft_min/sqft_max (same)
        if let minSqft = minSqft { dict["sqft_min"] = minSqft }
        if let maxSqft = maxSqft { dict["sqft_max"] = maxSqft }

        // Year Built - web uses year_built_min/year_built_max (same)
        if let minYearBuilt = minYearBuilt { dict["year_built_min"] = minYearBuilt }
        if let maxYearBuilt = maxYearBuilt { dict["year_built_max"] = maxYearBuilt }

        // Lot Size
        if let minLotSize = minLotSize { dict["lot_size_min"] = minLotSize }
        if let maxLotSize = maxLotSize { dict["lot_size_max"] = maxLotSize }

        // Entry Level
        if let minEntryLevel = minEntryLevel { dict["entry_level_min"] = minEntryLevel }
        if let maxEntryLevel = maxEntryLevel { dict["entry_level_max"] = maxEntryLevel }

        // Stories
        if let minStories = minStories { dict["stories_min"] = minStories }
        if let maxStories = maxStories { dict["stories_max"] = maxStories }

        // Open House
        if openHouseOnly { dict["open_house_only"] = true }

        // Location - web uses PascalCase for City, Neighborhood
        if !cities.isEmpty { dict["City"] = Array(cities) }
        if !neighborhoods.isEmpty { dict["Neighborhood"] = Array(neighborhoods) }
        if !zips.isEmpty { dict["zip"] = Array(zips) }
        if let address = address, !address.isEmpty { dict["address"] = address }
        if let mlsNumber = mlsNumber, !mlsNumber.isEmpty { dict["mls_number"] = mlsNumber }
        if let streetName = streetName, !streetName.isEmpty { dict["street_name"] = streetName }

        // Map Bounds (for reference when reopening search)
        if let bounds = mapBounds {
            dict["bounds"] = "\(bounds.south),\(bounds.west),\(bounds.north),\(bounds.east)"
        }

        // Polygon coordinates for draw search
        if let polygon = polygonCoordinates, !polygon.isEmpty {
            dict["polygon"] = polygon.map { ["lat": $0.latitude, "lng": $0.longitude] }
        }

        // Amenities - keep as-is (web handles both formats)
        if hasSpa { dict["SpaYN"] = true }
        if hasWaterfront { dict["WaterfrontYN"] = true }
        if hasView { dict["ViewYN"] = true }
        if hasWaterView { dict["MLSPIN_WATERVIEW_FLAG"] = true }
        if let isAttached = isAttached { dict["PropertyAttachedYN"] = isAttached }
        if isLenderOwned { dict["MLSPIN_LENDER_OWNED"] = true }
        if isSeniorCommunity { dict["SeniorCommunityYN"] = true }
        if hasOutdoorSpace { dict["MLSPIN_OUTDOOR_SPACE_AVAILABLE"] = true }
        if hasDPR { dict["MLSPIN_DPR_Flag"] = true }
        if hasCooling { dict["CoolingYN"] = true }
        if hasPool { dict["PoolPrivateYN"] = true }
        if hasFireplace { dict["FireplaceYN"] = true }
        if hasGarage { dict["GarageYN"] = true }
        if hasVirtualTour { dict["has_virtual_tour"] = true }

        // Availability
        if let availableBy = availableBy {
            let formatter = ISO8601DateFormatter()
            dict["available_by"] = formatter.string(from: availableBy)
        }
        if availableNow { dict["MLSPIN_AvailableNow"] = true }

        // Rental-specific filters - granular pet filters (v6.60.2)
        if petsDogs { dict["pets_dogs"] = 1 }
        if petsCats { dict["pets_cats"] = 1 }
        if petsNone { dict["pets_none"] = 1 }
        if petsNegotiable { dict["pets_negotiable"] = 1 }
        if !laundryTypes.isEmpty { dict["laundry_features"] = Array(laundryTypes) }
        if !leaseTerms.isEmpty { dict["lease_term"] = Array(leaseTerms) }

        // Days on Market
        if let maxDaysOnMarket = maxDaysOnMarket { dict["max_dom"] = maxDaysOnMarket }
        if let minDaysOnMarket = minDaysOnMarket { dict["min_dom"] = minDaysOnMarket }

        // Price Changes
        if priceReduced { dict["price_reduced"] = true }
        if newListing { dict["new_listing_days"] = newListingDays }

        // v6.64.0 / v284: Exclusive listings filter
        if exclusiveOnly { dict["exclusive_only"] = true }

        // School Quality Filters
        if let schoolGrade = schoolGrade { dict["school_grade"] = schoolGrade }
        if nearAElementary { dict["near_a_elementary"] = true }
        if nearABElementary { dict["near_ab_elementary"] = true }
        if nearAMiddle { dict["near_a_middle"] = true }
        if nearABMiddle { dict["near_ab_middle"] = true }
        if nearAHigh { dict["near_a_high"] = true }
        if nearABHigh { dict["near_ab_high"] = true }
        if let schoolDistrictId = schoolDistrictId { dict["school_district_id"] = schoolDistrictId }

        // Sort
        if sort != .dateDesc {
            dict["sort"] = sort.apiValue
        }

        return dict
    }

    var activeFilterCount: Int {
        var count = 0
        if minPrice != nil || maxPrice != nil { count += 1 }
        if !beds.isEmpty { count += 1 }
        if minBaths != nil { count += 1 }
        if minGarageSpaces != nil { count += 1 }
        if minParkingTotal != nil { count += 1 }
        if !propertyTypes.isEmpty { count += 1 }
        if statuses != [.active] { count += 1 }
        if !structureTypes.isEmpty { count += 1 }
        if !architecturalStyles.isEmpty { count += 1 }
        if minSqft != nil || maxSqft != nil { count += 1 }
        if minYearBuilt != nil || maxYearBuilt != nil { count += 1 }
        if minLotSize != nil || maxLotSize != nil { count += 1 }
        if minEntryLevel != nil || maxEntryLevel != nil { count += 1 }
        if minStories != nil || maxStories != nil { count += 1 }
        if openHouseOnly { count += 1 }
        if propertyType != nil { count += 1 }
        if !cities.isEmpty { count += 1 }
        if !neighborhoods.isEmpty { count += 1 }
        if !zips.isEmpty { count += 1 }
        if address != nil { count += 1 }
        if mlsNumber != nil { count += 1 }
        if streetName != nil { count += 1 }
        if mapBounds != nil { count += 1 }
        if polygonCoordinates != nil { count += 1 }
        if hasSpa { count += 1 }
        if hasWaterfront { count += 1 }
        if hasView { count += 1 }
        if hasWaterView { count += 1 }
        if isAttached != nil { count += 1 }
        if isLenderOwned { count += 1 }
        if isSeniorCommunity { count += 1 }
        if hasOutdoorSpace { count += 1 }
        if hasDPR { count += 1 }
        if hasCooling { count += 1 }
        if hasPool { count += 1 }
        if hasFireplace { count += 1 }
        if hasGarage { count += 1 }
        if hasVirtualTour { count += 1 }
        if availableBy != nil { count += 1 }
        if availableNow { count += 1 }
        // Rental-specific - granular pet filters
        if petsDogs { count += 1 }
        if petsCats { count += 1 }
        if petsNone { count += 1 }
        if petsNegotiable { count += 1 }
        if !laundryTypes.isEmpty { count += 1 }
        if !leaseTerms.isEmpty { count += 1 }
        if maxDaysOnMarket != nil { count += 1 }
        if minDaysOnMarket != nil { count += 1 }
        if priceReduced { count += 1 }
        if newListing { count += 1 }
        // School filters
        if schoolGrade != nil { count += 1 }
        if nearAElementary { count += 1 }
        if nearABElementary { count += 1 }
        if nearAMiddle { count += 1 }
        if nearABMiddle { count += 1 }
        if nearAHigh { count += 1 }
        if nearABHigh { count += 1 }
        if schoolDistrictId != nil { count += 1 }
        return count
    }

    var activeFilterChips: [FilterChip] {
        var chips: [FilterChip] = []

        // Listing type is always shown as the first chip
        chips.append(FilterChip(id: "listingType", label: listingType.displayName, category: .listingType))

        if let minPrice = minPrice, let maxPrice = maxPrice {
            chips.append(FilterChip(id: "price", label: "$\(minPrice.formatted()) - $\(maxPrice.formatted())", category: .price))
        } else if let minPrice = minPrice {
            chips.append(FilterChip(id: "price", label: "$\(minPrice.formatted())+", category: .price))
        } else if let maxPrice = maxPrice {
            chips.append(FilterChip(id: "price", label: "Up to $\(maxPrice.formatted())", category: .price))
        }

        if !beds.isEmpty {
            let bedsStr = beds.sorted().map { $0 == 5 ? "5+" : "\($0)" }.joined(separator: ", ")
            chips.append(FilterChip(id: "beds", label: "\(bedsStr) beds", category: .beds))
        }

        if let minBaths = minBaths {
            chips.append(FilterChip(id: "baths", label: "\(minBaths.formatted())+ baths", category: .baths))
        }

        if !propertyTypes.isEmpty {
            let typeLabels = propertyTypes.map { $0.displayLabel }.joined(separator: ", ")
            chips.append(FilterChip(id: "propertyTypes", label: typeLabels, category: .homeType))
        }

        // Only show status chip if statuses is not empty and different from default [.active]
        if !statuses.isEmpty && statuses != [.active] {
            chips.append(FilterChip(id: "status", label: statuses.map { $0.displayName }.joined(separator: ", "), category: .status))
        }

        if minSqft != nil || maxSqft != nil {
            var sqftStr = ""
            if let min = minSqft, let max = maxSqft {
                sqftStr = "\(min.formatted()) - \(max.formatted()) sqft"
            } else if let min = minSqft {
                sqftStr = "\(min.formatted())+ sqft"
            } else if let max = maxSqft {
                sqftStr = "Up to \(max.formatted()) sqft"
            }
            chips.append(FilterChip(id: "sqft", label: sqftStr, category: .sqft))
        }

        if minYearBuilt != nil || maxYearBuilt != nil {
            var yearStr = ""
            if let min = minYearBuilt, let max = maxYearBuilt {
                yearStr = "\(min) - \(max)"
            } else if let min = minYearBuilt {
                yearStr = "\(min)+"
            } else if let max = maxYearBuilt {
                yearStr = "Before \(max)"
            }
            chips.append(FilterChip(id: "yearBuilt", label: "Built \(yearStr)", category: .yearBuilt))
        }

        if !cities.isEmpty {
            chips.append(FilterChip(id: "cities", label: cities.joined(separator: ", "), category: .location))
        }

        if !zips.isEmpty {
            chips.append(FilterChip(id: "zips", label: zips.joined(separator: ", "), category: .location))
        }

        if !neighborhoods.isEmpty {
            chips.append(FilterChip(id: "neighborhoods", label: neighborhoods.joined(separator: ", "), category: .location))
        }

        if let streetName = streetName, !streetName.isEmpty {
            chips.append(FilterChip(id: "streetName", label: streetName, category: .location))
        }

        if openHouseOnly {
            chips.append(FilterChip(id: "openHouse", label: "Open House", category: .special))
        }

        // Lot Size
        if minLotSize != nil || maxLotSize != nil {
            var lotStr = ""
            if let min = minLotSize, let max = maxLotSize {
                lotStr = "\(Int(min).formatted()) - \(Int(max).formatted()) sqft lot"
            } else if let min = minLotSize {
                lotStr = "\(Int(min).formatted())+ sqft lot"
            } else if let max = maxLotSize {
                lotStr = "Up to \(Int(max).formatted()) sqft lot"
            }
            chips.append(FilterChip(id: "lotSize", label: lotStr, category: .sqft))
        }

        // Parking filters
        if let garageSpaces = minGarageSpaces {
            chips.append(FilterChip(id: "garageSpaces", label: "\(garageSpaces)+ garage", category: .amenity))
        }
        if let parkingSpaces = minParkingTotal {
            chips.append(FilterChip(id: "parkingSpaces", label: "\(parkingSpaces)+ parking", category: .amenity))
        }

        // Amenity toggles
        if hasPool { chips.append(FilterChip(id: "pool", label: "Pool", category: .amenity)) }
        if hasWaterfront { chips.append(FilterChip(id: "waterfront", label: "Waterfront", category: .amenity)) }
        if hasFireplace { chips.append(FilterChip(id: "fireplace", label: "Fireplace", category: .amenity)) }
        if hasGarage { chips.append(FilterChip(id: "garage", label: "Garage", category: .amenity)) }
        if hasView { chips.append(FilterChip(id: "view", label: "View", category: .amenity)) }
        if hasWaterView { chips.append(FilterChip(id: "waterView", label: "Water View", category: .amenity)) }
        if hasSpa { chips.append(FilterChip(id: "spa", label: "Spa", category: .amenity)) }
        if hasCooling { chips.append(FilterChip(id: "cooling", label: "A/C", category: .amenity)) }
        if hasOutdoorSpace { chips.append(FilterChip(id: "outdoorSpace", label: "Outdoor Space", category: .amenity)) }

        // Rental-specific filters
        if availableNow {
            chips.append(FilterChip(id: "availableNow", label: "Available Now", category: .rental))
        }
        if let availDate = availableBy {
            let formatter = DateFormatter()
            formatter.dateStyle = .medium
            chips.append(FilterChip(id: "availableBy", label: "By \(formatter.string(from: availDate))", category: .rental))
        }
        // Granular pet filter chips
        if petsDogs {
            chips.append(FilterChip(id: "petsDogs", label: "Dogs OK", category: .rental))
        }
        if petsCats {
            chips.append(FilterChip(id: "petsCats", label: "Cats OK", category: .rental))
        }
        if petsNone {
            chips.append(FilterChip(id: "petsNone", label: "No Pets", category: .rental))
        }
        if petsNegotiable {
            chips.append(FilterChip(id: "petsNegotiable", label: "Pets Negotiable", category: .rental))
        }
        if !laundryTypes.isEmpty {
            let laundryStr = laundryTypes.sorted().joined(separator: ", ")
            chips.append(FilterChip(id: "laundryTypes", label: laundryStr, category: .rental))
        }
        if !leaseTerms.isEmpty {
            let leaseStr = leaseTerms.sorted().joined(separator: ", ")
            chips.append(FilterChip(id: "leaseTerms", label: leaseStr, category: .rental))
        }

        // Special filters
        if hasVirtualTour { chips.append(FilterChip(id: "virtualTour", label: "Virtual Tour", category: .special)) }
        if priceReduced { chips.append(FilterChip(id: "priceReduced", label: "Price Reduced", category: .special)) }
        if newListing { chips.append(FilterChip(id: "newListing", label: "New Listing", category: .special)) }
        if isSeniorCommunity { chips.append(FilterChip(id: "seniorCommunity", label: "55+ Community", category: .special)) }
        // v6.64.0 / v284: Exclusive listings filter
        if exclusiveOnly { chips.append(FilterChip(id: "exclusiveOnly", label: "Exclusive Only", category: .special)) }

        // Days on Market
        if let maxDom = maxDaysOnMarket {
            chips.append(FilterChip(id: "maxDom", label: "≤\(maxDom)d on market", category: .special))
        }
        if let minDom = minDaysOnMarket {
            chips.append(FilterChip(id: "minDom", label: "≥\(minDom)d on market", category: .special))
        }

        // School filters
        if let grade = schoolGrade {
            chips.append(FilterChip(id: "schoolGrade", label: "\(grade)+ District", category: .school))
        }
        // Elementary
        if nearAElementary {
            chips.append(FilterChip(id: "nearAElementary", label: "A Elementary", category: .school))
        }
        if nearABElementary {
            chips.append(FilterChip(id: "nearABElementary", label: "A/B Elementary", category: .school))
        }
        // Middle
        if nearAMiddle {
            chips.append(FilterChip(id: "nearAMiddle", label: "A Middle", category: .school))
        }
        if nearABMiddle {
            chips.append(FilterChip(id: "nearABMiddle", label: "A/B Middle", category: .school))
        }
        // High
        if nearAHigh {
            chips.append(FilterChip(id: "nearAHigh", label: "A High", category: .school))
        }
        if nearABHigh {
            chips.append(FilterChip(id: "nearABHigh", label: "A/B High", category: .school))
        }

        return chips
    }

    mutating func clearAll() {
        self = PropertySearchFilters()
    }

    mutating func removeFilter(chipId: String) {
        switch chipId {
        case "listingType": listingType = .forSale  // Reset to default (For Sale)
        case "price": minPrice = nil; maxPrice = nil
        case "beds": beds = []
        case "baths": minBaths = nil
        case "propertyTypes": propertyTypes = [.house, .condo]  // Reset to default
        case "status": statuses = [.active]
        case "sqft": minSqft = nil; maxSqft = nil
        case "yearBuilt": minYearBuilt = nil; maxYearBuilt = nil
        case "cities": cities = []
        case "zips": zips = []
        case "neighborhoods": neighborhoods = []
        case "streetName": streetName = nil
        case "openHouse": openHouseOnly = false
        case "pool": hasPool = false
        case "waterfront": hasWaterfront = false
        case "fireplace": hasFireplace = false
        case "garage": hasGarage = false
        case "view": hasView = false
        case "spa": hasSpa = false
        case "cooling": hasCooling = false
        case "waterView": hasWaterView = false
        case "outdoorSpace": hasOutdoorSpace = false
        case "virtualTour": hasVirtualTour = false
        case "priceReduced": priceReduced = false
        case "newListing": newListing = false
        case "seniorCommunity": isSeniorCommunity = false
        case "exclusiveOnly": exclusiveOnly = false  // v6.64.0 / v284
        case "lotSize": minLotSize = nil; maxLotSize = nil
        case "garageSpaces": minGarageSpaces = nil
        case "parkingSpaces": minParkingTotal = nil
        case "maxDom": maxDaysOnMarket = nil
        case "minDom": minDaysOnMarket = nil
        // School filters
        case "schoolGrade": schoolGrade = nil
        case "nearAElementary": nearAElementary = false
        case "nearABElementary": nearABElementary = false
        case "nearAMiddle": nearAMiddle = false
        case "nearABMiddle": nearABMiddle = false
        case "nearAHigh": nearAHigh = false
        case "nearABHigh": nearABHigh = false
        // Rental filters
        case "availableNow": availableNow = false
        case "availableBy": availableBy = nil
        case "petsDogs": petsDogs = false
        case "petsCats": petsCats = false
        case "petsNone": petsNone = false
        case "petsNegotiable": petsNegotiable = false
        case "laundryTypes": laundryTypes = []
        case "leaseTerms": leaseTerms = []
        default: break
        }
    }
}

// MARK: - PropertySearchFilters Server JSON Conversion

extension PropertySearchFilters {
    /// Initialize from server JSON dictionary (inverse of toDictionary)
    /// Used when loading saved searches from the server
    init(fromServerJSON json: [String: AnyCodableValue]) {
        self.init()

        // Pagination
        if let page = json["page"]?.intValue { self.page = page }
        if let perPage = json["per_page"]?.intValue { self.perPage = perPage }

        // Listing Type (For Sale vs For Rent)
        if let listingTypeStr = json["listing_type"]?.stringValue,
           let listingType = ListingType(rawValue: listingTypeStr) {
            self.listingType = listingType
        }

        // Price (handle both iOS keys and web keys)
        if let minPrice = json["min_price"]?.intValue ?? json["price_min"]?.intValue {
            self.minPrice = minPrice
        }
        if let maxPrice = json["max_price"]?.intValue ?? json["price_max"]?.intValue {
            self.maxPrice = maxPrice
        }

        // Beds (handle both iOS "beds" and web "beds_min" keys)
        if let beds = json["beds"]?.intValue ?? json["beds_min"]?.intValue {
            self.beds = [beds]
        } else if let bedsArray = json["beds"]?.intArrayValue {
            self.beds = Set(bedsArray)
        }

        // Baths (handle both iOS "baths" and web "baths_min")
        if let baths = json["baths"]?.doubleValue ?? json["baths_min"]?.doubleValue {
            self.minBaths = baths
        }

        // Garage & Parking
        if let garageMin = json["garage_spaces_min"]?.intValue { self.minGarageSpaces = garageMin }
        if let parkingMin = json["parking_total_min"]?.intValue { self.minParkingTotal = parkingMin }

        // Property Types - Convert legacy property_type/property_sub_type back to CombinedPropertyType
        // Handle both iOS "property_type" and web "PropertyType" keys
        let serverPropertyTypes = json["property_type"]?.stringArrayValue ?? json["PropertyType"]?.stringArrayValue ?? []
        let serverSubTypes = json["property_sub_type"]?.stringArrayValue ?? json["home_type"]?.stringArrayValue ?? []

        var restoredPropertyTypes: Set<CombinedPropertyType> = []

        // First check for specific sub-type matches
        for subType in serverSubTypes {
            switch subType {
            case "Single Family Residence":
                restoredPropertyTypes.insert(.house)
            case "Condominium", "Condex", "Stock Cooperative":
                restoredPropertyTypes.insert(.condo)
            case "Mobile Home":
                restoredPropertyTypes.insert(.mobileHome)
            case "Farm", "Equestrian":
                restoredPropertyTypes.insert(.farmEquestrian)
            default:
                break
            }
        }

        // Then map property types that don't have sub-types
        for propType in serverPropertyTypes {
            switch propType {
            case "Residential Income":
                restoredPropertyTypes.insert(.multiFamily)
            case "Land":
                restoredPropertyTypes.insert(.land)
            case "Commercial Sale":
                restoredPropertyTypes.insert(.commercial)
            case "Business Opportunity":
                restoredPropertyTypes.insert(.businessOpportunity)
            case "Residential Lease":
                restoredPropertyTypes.insert(.residentialRental)
            case "Commercial Lease":
                restoredPropertyTypes.insert(.commercialRental)
            case "Residential":
                // If Residential type with no specific sub-type, default to house
                if restoredPropertyTypes.isEmpty || !restoredPropertyTypes.contains(.house) && !restoredPropertyTypes.contains(.condo) && !restoredPropertyTypes.contains(.mobileHome) {
                    restoredPropertyTypes.insert(.house)
                }
            default:
                break
            }
        }

        if !restoredPropertyTypes.isEmpty {
            self.propertyTypes = restoredPropertyTypes
        }

        // Status
        if let statuses = json["status"]?.stringArrayValue {
            self.statuses = Set(statuses.compactMap { PropertyStatus(rawValue: $0) })
        }

        // Structure Types
        if let structureTypes = json["structure_type"]?.stringArrayValue {
            self.structureTypes = Set(structureTypes)
        }

        // Architectural Styles
        if let styles = json["architectural_style"]?.stringArrayValue {
            self.architecturalStyles = Set(styles)
        }

        // Square Footage
        if let sqftMin = json["sqft_min"]?.intValue { self.minSqft = sqftMin }
        if let sqftMax = json["sqft_max"]?.intValue { self.maxSqft = sqftMax }

        // Year Built
        if let yearMin = json["year_built_min"]?.intValue { self.minYearBuilt = yearMin }
        if let yearMax = json["year_built_max"]?.intValue { self.maxYearBuilt = yearMax }

        // Lot Size
        if let lotMin = json["lot_size_min"]?.doubleValue { self.minLotSize = lotMin }
        if let lotMax = json["lot_size_max"]?.doubleValue { self.maxLotSize = lotMax }

        // Entry Level
        if let entryMin = json["entry_level_min"]?.intValue { self.minEntryLevel = entryMin }
        if let entryMax = json["entry_level_max"]?.intValue { self.maxEntryLevel = entryMax }

        // Stories
        if let storiesMin = json["stories_min"]?.intValue { self.minStories = storiesMin }
        if let storiesMax = json["stories_max"]?.intValue { self.maxStories = storiesMax }

        // Open House
        if let openHouse = json["open_house_only"]?.boolValue { self.openHouseOnly = openHouse }

        // Location (handle both iOS "city" and web "City" PascalCase)
        if let cities = json["city"]?.stringArrayValue ?? json["City"]?.stringArrayValue {
            self.cities = Set(cities)
        }
        if let neighborhoods = json["neighborhood"]?.stringArrayValue ?? json["Neighborhood"]?.stringArrayValue {
            self.neighborhoods = Set(neighborhoods)
        }
        if let zips = json["zip"]?.stringArrayValue {
            self.zips = Set(zips)
        }
        if let address = json["address"]?.stringValue { self.address = address }
        if let mlsNumber = json["mls_number"]?.stringValue { self.mlsNumber = mlsNumber }
        if let streetName = json["street_name"]?.stringValue { self.streetName = streetName }

        // Geographic - bounds and polygon only (radius search removed)
        // Bounds (stored as CSV string: "south,west,north,east")
        if let boundsStr = json["bounds"]?.stringValue {
            let parts = boundsStr.components(separatedBy: ",").compactMap { Double($0.trimmingCharacters(in: .whitespaces)) }
            if parts.count == 4 {
                self.mapBounds = MapBounds(north: parts[2], south: parts[0], east: parts[3], west: parts[1])
            }
        }

        // Polygon coordinates - handle iOS format: [{lat, lng}...]
        if case .array(let polygonArray) = json["polygon"] {
            var coords: [CLLocationCoordinate2D] = []
            for point in polygonArray {
                if case .dictionary(let pointDict) = point,
                   let lat = pointDict["lat"]?.doubleValue,
                   let lng = pointDict["lng"]?.doubleValue {
                    coords.append(CLLocationCoordinate2D(latitude: lat, longitude: lng))
                }
            }
            if !coords.isEmpty {
                self.polygonCoordinates = coords
            }
        }

        // Polygon shapes - handle web format: [[[lat, lng], [lat, lng]...]]
        // Web stores as array of polygons, each polygon is array of [lat, lng] pairs
        if case .array(let shapesArray) = json["polygon_shapes"] {
            var coords: [CLLocationCoordinate2D] = []
            // Get first polygon shape (we only support one polygon currently)
            if let firstShape = shapesArray.first, case .array(let pointsArray) = firstShape {
                for point in pointsArray {
                    if case .array(let coordPair) = point,
                       coordPair.count >= 2,
                       let lat = coordPair[0].doubleValue,
                       let lng = coordPair[1].doubleValue {
                        coords.append(CLLocationCoordinate2D(latitude: lat, longitude: lng))
                    }
                }
            }
            if !coords.isEmpty {
                self.polygonCoordinates = coords
            }
        }

        // Amenities
        if let spa = json["SpaYN"]?.boolValue { self.hasSpa = spa }
        if let waterfront = json["WaterfrontYN"]?.boolValue { self.hasWaterfront = waterfront }
        if let view = json["ViewYN"]?.boolValue { self.hasView = view }
        if let waterView = json["MLSPIN_WATERVIEW_FLAG"]?.boolValue { self.hasWaterView = waterView }
        if let attached = json["PropertyAttachedYN"]?.boolValue { self.isAttached = attached }
        if let lenderOwned = json["MLSPIN_LENDER_OWNED"]?.boolValue { self.isLenderOwned = lenderOwned }
        if let senior = json["SeniorCommunityYN"]?.boolValue { self.isSeniorCommunity = senior }
        if let outdoor = json["MLSPIN_OUTDOOR_SPACE_AVAILABLE"]?.boolValue { self.hasOutdoorSpace = outdoor }
        if let dpr = json["MLSPIN_DPR_Flag"]?.boolValue { self.hasDPR = dpr }
        if let cooling = json["CoolingYN"]?.boolValue { self.hasCooling = cooling }
        if let pool = json["PoolPrivateYN"]?.boolValue { self.hasPool = pool }
        if let fireplace = json["FireplaceYN"]?.boolValue { self.hasFireplace = fireplace }
        if let garage = json["GarageYN"]?.boolValue { self.hasGarage = garage }
        if let virtualTour = json["has_virtual_tour"]?.boolValue { self.hasVirtualTour = virtualTour }

        // Availability
        if let availNow = json["MLSPIN_AvailableNow"]?.boolValue { self.availableNow = availNow }
        if let availByStr = json["available_by"]?.stringValue {
            let formatter = ISO8601DateFormatter()
            self.availableBy = formatter.date(from: availByStr)
        }

        // Rental-specific filters - granular pet filters (v6.60.2)
        if let dogs = json["pets_dogs"]?.intValue {
            self.petsDogs = dogs == 1
        }
        if let cats = json["pets_cats"]?.intValue {
            self.petsCats = cats == 1
        }
        if let none = json["pets_none"]?.intValue {
            self.petsNone = none == 1
        }
        if let negotiable = json["pets_negotiable"]?.intValue {
            self.petsNegotiable = negotiable == 1
        }
        if let laundry = json["laundry_features"]?.stringArrayValue {
            self.laundryTypes = Set(laundry)
        }
        if let lease = json["lease_term"]?.stringArrayValue {
            self.leaseTerms = Set(lease)
        }

        // Days on Market
        if let maxDom = json["max_dom"]?.intValue { self.maxDaysOnMarket = maxDom }
        if let minDom = json["min_dom"]?.intValue { self.minDaysOnMarket = minDom }

        // Price Changes
        if let priceReduced = json["price_reduced"]?.boolValue { self.priceReduced = priceReduced }
        if let newListingDays = json["new_listing_days"]?.intValue {
            self.newListing = true
            self.newListingDays = newListingDays
        }

        // School Quality Filters
        if let grade = json["school_grade"]?.stringValue { self.schoolGrade = grade }
        if let nearAElem = json["near_a_elementary"]?.boolValue { self.nearAElementary = nearAElem }
        if let nearABElem = json["near_ab_elementary"]?.boolValue { self.nearABElementary = nearABElem }
        if let nearAMid = json["near_a_middle"]?.boolValue { self.nearAMiddle = nearAMid }
        if let nearABMid = json["near_ab_middle"]?.boolValue { self.nearABMiddle = nearABMid }
        if let nearAH = json["near_a_high"]?.boolValue { self.nearAHigh = nearAH }
        if let nearABH = json["near_ab_high"]?.boolValue { self.nearABHigh = nearABH }
        if let districtId = json["school_district_id"]?.intValue { self.schoolDistrictId = districtId }

        // Sort
        if let sortStr = json["sort"]?.stringValue {
            self.sort = SortOption.allCases.first { $0.apiValue == sortStr } ?? .dateDesc
        }

        // Search text
        if let search = json["search"]?.stringValue { self.searchText = search }
    }
}

// MARK: - Filter Chip

struct FilterChip: Identifiable, Equatable {
    let id: String
    let label: String
    let category: FilterCategory

    enum FilterCategory {
        case listingType, price, beds, baths, homeType, status, sqft, yearBuilt, location, amenity, special, school, rental

        var color: String {
            switch self {
            case .listingType: return "#6366F1"  // Indigo
            case .price: return "#0891B2"
            case .beds, .baths: return "#059669"
            case .homeType: return "#7C3AED"
            case .status: return "#DC2626"
            case .sqft, .yearBuilt: return "#D97706"
            case .location: return "#2563EB"
            case .amenity: return "#EC4899"
            case .special: return "#F59E0B"
            case .school: return "#10B981"  // Emerald (education green)
            case .rental: return "#F97316"  // Orange (rental-specific)
            }
        }
    }
}

// MARK: - Map Bounds

struct MapBounds: Equatable {
    let north: Double
    let south: Double
    let east: Double
    let west: Double

    var center: CLLocationCoordinate2D {
        CLLocationCoordinate2D(
            latitude: (north + south) / 2,
            longitude: (east + west) / 2
        )
    }

    /// Calculate the span (zoom level) of the bounds
    var span: Double {
        max(north - south, east - west)
    }
}

// MARK: - Neighborhood Analytics (for map price overlays)

struct NeighborhoodAnalytics: Codable {
    let name: String
    let type: String
    let center: NeighborhoodCenter
    let medianPrice: Int
    let avgPrice: Int
    let avgDom: Double
    let listingCount: Int
    let marketHeat: String

    enum CodingKeys: String, CodingKey {
        case name, type, center
        case medianPrice = "median_price"
        case avgPrice = "avg_price"
        case avgDom = "avg_dom"
        case listingCount = "listing_count"
        case marketHeat = "market_heat"
    }

    /// Format median price for display (e.g., "$850K" or "$1.2M")
    var formattedMedianPrice: String {
        if medianPrice >= 1_000_000 {
            let millions = Double(medianPrice) / 1_000_000.0
            return String(format: "$%.1fM", millions)
        } else {
            let thousands = medianPrice / 1000
            return "$\(thousands)K"
        }
    }

    /// Color for market heat indicator
    var heatColor: String {
        switch marketHeat {
        case "hot": return "#EF4444"      // Red
        case "warm": return "#F59E0B"     // Orange
        case "balanced": return "#10B981" // Green
        case "cool": return "#3B82F6"     // Blue
        case "cold": return "#6366F1"     // Indigo
        default: return "#6B7280"         // Gray
        }
    }
}

struct NeighborhoodCenter: Codable {
    let lat: Double
    let lng: Double

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }
}

struct NeighborhoodAnalyticsResponse: Codable {
    let neighborhoods: [NeighborhoodAnalytics]
    let bounds: NeighborhoodBoundsResponse
    let propertyType: String

    enum CodingKeys: String, CodingKey {
        case neighborhoods, bounds
        case propertyType = "property_type"
    }
}

struct NeighborhoodBoundsResponse: Codable {
    let south: Double
    let west: Double
    let north: Double
    let east: Double
}

// MARK: - City Market Insights (v6.73.0)
// Used to display market analytics in property detail view

struct CityMarketInsights: Codable {
    let citySummary: CityMarketSummary?
    let marketHeat: MarketHeatIndex?
    let meta: MarketInsightsMeta?

    enum CodingKeys: String, CodingKey {
        case citySummary = "city_summary"
        case marketHeat = "market_heat"
        case meta
    }
}

struct CityMarketSummary: Codable {
    let activeCount: Int?
    let pendingCount: Int?
    let newThisWeek: Int?
    let newThisMonth: Int?
    let avgListPrice: Double?
    let avgPricePerSqft: Double?
    let minListPrice: Int?
    let maxListPrice: Int?
    let medianListPrice: Int?
    let sold12m: Int?
    let totalVolume12m: Double?
    let avgClosePrice12m: Double?
    let avgDom12m: Double?
    let avgSpLp12m: Double?  // Sale Price to List Price ratio
    let monthsOfSupply: Double?
    let absorptionRate: Double?
    let marketHeatScore: Int?
    let marketHeatClassification: String?
    let yoyPriceChangePct: Double?
    let yoySalesChangePct: Double?

    enum CodingKeys: String, CodingKey {
        case activeCount = "active_count"
        case pendingCount = "pending_count"
        case newThisWeek = "new_this_week"
        case newThisMonth = "new_this_month"
        case avgListPrice = "avg_list_price"
        case avgPricePerSqft = "avg_price_per_sqft"
        case minListPrice = "min_list_price"
        case maxListPrice = "max_list_price"
        case medianListPrice = "median_list_price"
        case sold12m = "sold_12m"
        case totalVolume12m = "total_volume_12m"
        case avgClosePrice12m = "avg_close_price_12m"
        case avgDom12m = "avg_dom_12m"
        case avgSpLp12m = "avg_sp_lp_12m"
        case monthsOfSupply = "months_of_supply"
        case absorptionRate = "absorption_rate"
        case marketHeatScore = "market_heat_score"
        case marketHeatClassification = "market_heat_classification"
        case yoyPriceChangePct = "yoy_price_change_pct"
        case yoySalesChangePct = "yoy_sales_change_pct"
    }

    /// Format price for display (e.g., "$850K" or "$1.2M")
    static func formatPrice(_ price: Double?) -> String {
        guard let price = price, price > 0 else { return "N/A" }
        if price >= 1_000_000 {
            return String(format: "$%.1fM", price / 1_000_000)
        } else if price >= 1_000 {
            return String(format: "$%.0fK", price / 1_000)
        }
        return String(format: "$%.0f", price)
    }

    /// Format price per sqft
    static func formatPricePerSqft(_ price: Double?) -> String {
        guard let price = price, price > 0 else { return "N/A" }
        return String(format: "$%.0f/sqft", price)
    }

    /// Format percentage
    static func formatPercent(_ pct: Double?, showSign: Bool = false) -> String {
        guard let pct = pct else { return "N/A" }
        if showSign && pct > 0 {
            return String(format: "+%.1f%%", pct)
        }
        return String(format: "%.1f%%", pct)
    }

    /// Format days
    static func formatDays(_ days: Double?) -> String {
        guard let days = days, days >= 0 else { return "N/A" }
        let rounded = Int(days.rounded())
        return "\(rounded) days"
    }

    /// Format months of supply
    static func formatMonths(_ months: Double?) -> String {
        guard let months = months, months > 0 else { return "N/A" }
        return String(format: "%.1f months", months)
    }

    // MARK: - Instance Computed Properties for Display

    /// Formatted median price
    var formattedMedianPrice: String? {
        guard let price = medianListPrice, price > 0 else { return nil }
        if Double(price) >= 1_000_000 {
            return String(format: "$%.1fM", Double(price) / 1_000_000)
        } else if price >= 1_000 {
            return String(format: "$%.0fK", Double(price) / 1_000)
        }
        return "$\(price)"
    }

    /// Formatted average days on market
    var formattedAvgDOM: String? {
        guard let days = avgDom12m, days >= 0 else { return nil }
        let rounded = Int(days.rounded())
        return "\(rounded) days"
    }

    /// Formatted months of supply
    var formattedMonthsOfSupply: String? {
        guard let months = monthsOfSupply, months > 0 else { return nil }
        return String(format: "%.1f months", months)
    }

    /// Formatted year-over-year price change
    var formattedYoYPriceChange: String? {
        guard let pct = yoyPriceChangePct else { return nil }
        if pct > 0 {
            return String(format: "+%.1f%%", pct)
        }
        return String(format: "%.1f%%", pct)
    }
}

struct MarketHeatIndex: Codable {
    let score: Int?
    let classification: String?

    enum CodingKeys: String, CodingKey {
        case score = "heat_index"
        case classification
    }

    /// Color name for market heat classification (use in view to convert to Color)
    var colorName: String {
        switch classification?.lowercased() {
        case "very hot", "hot":
            return "red"
        case "warm":
            return "orange"
        case "balanced":
            return "yellow"
        case "cool", "cold":
            return "blue"
        default:
            return "gray"
        }
    }

    /// Icon for market heat
    var icon: String {
        switch classification?.lowercased() {
        case "very hot", "hot":
            return "flame.fill"
        case "warm":
            return "sun.max.fill"
        case "balanced":
            return "equal.circle.fill"
        case "cool", "cold":
            return "snowflake"
        default:
            return "questionmark.circle"
        }
    }
}

struct MarketInsightsMeta: Codable {
    let city: String?
    let state: String?
    let propertyType: String?
    let timestamp: String?

    enum CodingKeys: String, CodingKey {
        case city, state, timestamp
        case propertyType = "property_type"
    }
}

// MARK: - Listing Type (For Sale vs For Rent)

enum ListingType: String, CaseIterable, Identifiable {
    case forSale = "for_sale"
    case forRent = "for_rent"

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .forSale: return "Buy"
        case .forRent: return "Rent"
        }
    }

    var icon: String {
        switch self {
        case .forSale: return "house.fill"
        case .forRent: return "key.fill"
        }
    }

    /// Combined property types available for this listing type
    var combinedPropertyTypes: [CombinedPropertyType] {
        switch self {
        case .forSale:
            return [.house, .condo, .multiFamily, .mobileHome, .farmEquestrian, .land, .commercial, .businessOpportunity]
        case .forRent:
            return [.residentialRental, .commercialRental]
        }
    }

    /// Legacy: Property types included in this listing type (for backward compatibility)
    var propertyTypes: [String] {
        switch self {
        case .forSale:
            return ["Residential", "Residential Income", "Commercial Sale", "Business Opportunity", "Land"]
        case .forRent:
            return ["Residential Lease", "Commercial Lease"]
        }
    }

    /// Display label for property types (backend values remain unchanged)
    static func displayLabel(for propertyType: String) -> String {
        // First check if it's a combined property type key
        if let combined = CombinedPropertyType(rawValue: propertyType) {
            return combined.displayLabel
        }
        // Fall back to legacy property type labels
        switch propertyType {
        case "Residential": return "House/Condo"
        case "Residential Income": return "Multi-Family"
        case "Commercial Sale": return "Commercial"
        case "Residential Lease": return "Residential"
        case "Commercial Lease": return "Commercial"
        default: return propertyType
        }
    }
}

// MARK: - Combined Property Type (v6.59.2 - Unified filter)
// Combines property_type and property_sub_type into user-friendly categories

enum CombinedPropertyType: String, CaseIterable, Identifiable {
    // For Sale types
    case house = "house"
    case condo = "condo"
    case multiFamily = "multi_family"
    case mobileHome = "mobile_home"
    case farmEquestrian = "farm_equestrian"
    case land = "land"
    case commercial = "commercial"
    case businessOpportunity = "business_opportunity"

    // For Rent types
    case residentialRental = "residential_rental"
    case commercialRental = "commercial_rental"

    var id: String { rawValue }

    var displayLabel: String {
        switch self {
        case .house: return "House"
        case .condo: return "Condo"
        case .multiFamily: return "Multi-Family"
        case .mobileHome: return "Mobile Home"
        case .farmEquestrian: return "Farm & Equestrian"
        case .land: return "Land"
        case .commercial: return "Commercial"
        case .businessOpportunity: return "Business Opportunity"
        case .residentialRental: return "Residential"
        case .commercialRental: return "Commercial"
        }
    }

    var icon: String {
        switch self {
        case .house: return "house.fill"
        case .condo: return "building.fill"
        case .multiFamily: return "building.2.fill"
        case .mobileHome: return "house.lodge.fill"
        case .farmEquestrian: return "hare.fill"
        case .land: return "leaf.fill"
        case .commercial: return "storefront.fill"
        case .businessOpportunity: return "briefcase.fill"
        case .residentialRental: return "house.fill"
        case .commercialRental: return "storefront.fill"
        }
    }

    /// The property_type value(s) to send to API
    var apiPropertyTypes: [String] {
        switch self {
        case .house: return ["Residential"]
        case .condo: return ["Residential"]
        case .multiFamily: return ["Residential Income"]
        case .mobileHome: return ["Residential"]
        case .farmEquestrian: return ["Residential"]
        case .land: return ["Land"]
        case .commercial: return ["Commercial Sale"]
        case .businessOpportunity: return ["Business Opportunity"]
        case .residentialRental: return ["Residential Lease"]
        case .commercialRental: return ["Commercial Lease"]
        }
    }

    /// The property_sub_type value(s) to send to API (nil if not applicable)
    var apiPropertySubTypes: [String]? {
        switch self {
        case .house: return ["Single Family Residence"]
        case .condo: return ["Condominium", "Condex", "Stock Cooperative"]
        case .multiFamily: return nil  // No sub type filter needed
        case .mobileHome: return ["Mobile Home"]
        case .farmEquestrian: return ["Farm", "Equestrian"]
        case .land: return nil  // No sub type filter needed
        case .commercial: return nil
        case .businessOpportunity: return nil
        case .residentialRental: return nil
        case .commercialRental: return nil
        }
    }

    /// Whether this is a rental type
    var isRental: Bool {
        switch self {
        case .residentialRental, .commercialRental: return true
        default: return false
        }
    }

    /// Get CombinedPropertyType for a listing type
    static func forListingType(_ listingType: ListingType) -> [CombinedPropertyType] {
        return listingType.combinedPropertyTypes
    }

    /// Default type for listing type
    static func defaultFor(_ listingType: ListingType) -> CombinedPropertyType {
        switch listingType {
        case .forSale: return .house
        case .forRent: return .residentialRental
        }
    }
}

// MARK: - Sort Options (Complete matching web)

enum SortOption: String, CaseIterable, Codable {
    case dateDesc = "newest"
    case dateAsc = "list-date-asc"
    case priceAsc = "price-asc"
    case priceDesc = "price-desc"
    case addressAsc = "address-asc"
    case addressDesc = "address-desc"
    case bedsAsc = "beds-asc"
    case bedsDesc = "beds-desc"
    case sqftAsc = "sqft-asc"
    case sqftDesc = "sqft-desc"

    /// API parameter value (uses underscores, not dashes)
    var apiValue: String {
        switch self {
        case .dateDesc: return "list_date_desc"
        case .dateAsc: return "list_date_asc"
        case .priceAsc: return "price_asc"
        case .priceDesc: return "price_desc"
        case .addressAsc: return "address_asc"
        case .addressDesc: return "address_desc"
        case .bedsAsc: return "beds_asc"
        case .bedsDesc: return "beds_desc"
        case .sqftAsc: return "sqft_asc"
        case .sqftDesc: return "sqft_desc"
        }
    }

    var displayName: String {
        switch self {
        case .dateDesc: return "Newest First"
        case .dateAsc: return "Oldest First"
        case .priceAsc: return "Price: Low to High"
        case .priceDesc: return "Price: High to Low"
        case .addressAsc: return "Address: A-Z"
        case .addressDesc: return "Address: Z-A"
        case .bedsAsc: return "Beds: Low to High"
        case .bedsDesc: return "Beds: High to Low"
        case .sqftAsc: return "Size: Small to Large"
        case .sqftDesc: return "Size: Large to Small"
        }
    }

    var icon: String {
        switch self {
        case .dateDesc, .dateAsc: return "calendar"
        case .priceAsc, .priceDesc: return "dollarsign.circle"
        case .addressAsc, .addressDesc: return "textformat.abc"
        case .bedsAsc, .bedsDesc: return "bed.double"
        case .sqftAsc, .sqftDesc: return "square.dashed"
        }
    }
}

// MARK: - Saved Search (Extended for local storage)
// Note: The API SavedSearch is in SavedSearch.swift
// This is for local saved search creation

struct LocalSavedSearch: Identifiable, Codable {
    let id: String
    let name: String
    let filters: LocalSavedSearchFilters
    let createdAt: Date
    var notificationFrequency: NotificationFrequency
    var isEnabled: Bool
    var lastNotified: Date?
    var newListingsCount: Int?
}

// Codable version of filters for local persistence
struct LocalSavedSearchFilters: Codable, Equatable {
    var minPrice: Int?
    var maxPrice: Int?
    var beds: [Int]
    var minBaths: Double?
    var homeTypes: [String]
    var statuses: [String]
    var minSqft: Int?
    var maxSqft: Int?
    var minYearBuilt: Int?
    var maxYearBuilt: Int?
    var cities: [String]
    var neighborhoods: [String]
    var zips: [String]
    var propertyType: String?
    var listingType: String
    var hasPool: Bool
    var hasWaterfront: Bool
    var hasFireplace: Bool
    var hasGarage: Bool
    var openHouseOnly: Bool

    init(from filters: PropertySearchFilters) {
        self.minPrice = filters.minPrice
        self.maxPrice = filters.maxPrice
        self.beds = Array(filters.beds)
        self.minBaths = filters.minBaths
        // Store property types as raw values for persistence
        self.homeTypes = filters.propertyTypes.map { $0.rawValue }
        self.statuses = filters.statuses.map { $0.rawValue }
        self.minSqft = filters.minSqft
        self.maxSqft = filters.maxSqft
        self.minYearBuilt = filters.minYearBuilt
        self.maxYearBuilt = filters.maxYearBuilt
        self.cities = Array(filters.cities)
        self.neighborhoods = Array(filters.neighborhoods)
        self.zips = Array(filters.zips)
        self.propertyType = filters.propertyType
        self.listingType = filters.listingType.rawValue
        self.hasPool = filters.hasPool
        self.hasWaterfront = filters.hasWaterfront
        self.hasFireplace = filters.hasFireplace
        self.hasGarage = filters.hasGarage
        self.openHouseOnly = filters.openHouseOnly
    }

    func toFilters() -> PropertySearchFilters {
        var filters = PropertySearchFilters()
        filters.minPrice = minPrice
        filters.maxPrice = maxPrice
        filters.beds = Set(beds)
        filters.minBaths = minBaths
        // Restore combined property types from raw values
        filters.propertyTypes = Set(homeTypes.compactMap { CombinedPropertyType(rawValue: $0) })
        if filters.propertyTypes.isEmpty {
            // Default to house and condo if no valid types restored
            filters.propertyTypes = [.house, .condo]
        }
        filters.statuses = Set(statuses.compactMap { PropertyStatus(rawValue: $0) })
        filters.minSqft = minSqft
        filters.maxSqft = maxSqft
        filters.minYearBuilt = minYearBuilt
        filters.maxYearBuilt = maxYearBuilt
        filters.cities = Set(cities)
        filters.neighborhoods = Set(neighborhoods)
        filters.zips = Set(zips)
        filters.propertyType = propertyType
        filters.listingType = ListingType(rawValue: listingType) ?? .forSale
        filters.hasPool = hasPool
        filters.hasWaterfront = hasWaterfront
        filters.hasFireplace = hasFireplace
        filters.hasGarage = hasGarage
        filters.openHouseOnly = openHouseOnly
        return filters
    }
}

// MARK: - Search Suggestion

struct SearchSuggestion: Identifiable {
    let id = UUID()
    let type: SuggestionType
    let value: String
    let displayText: String
    let subtitle: String?
    // v6.68.6: For direct navigation to property detail (Address and MLS Number suggestions)
    let listingId: String?
    let listingKey: String?

    // Initializer with default values for backward compatibility
    init(type: SuggestionType, value: String, displayText: String, subtitle: String?, listingId: String? = nil, listingKey: String? = nil) {
        self.type = type
        self.value = value
        self.displayText = displayText
        self.subtitle = subtitle
        self.listingId = listingId
        self.listingKey = listingKey
    }

    enum SuggestionType: String {
        case address
        case city
        case neighborhood
        case zip
        case mlsNumber
        case streetName  // Street name searches (e.g., "Main Street")

        var icon: String {
            switch self {
            case .address: return "mappin.circle.fill"
            case .city: return "building.2.fill"
            case .neighborhood: return "map.fill"
            case .zip: return "number.circle.fill"
            case .mlsNumber: return "doc.text.fill"
            case .streetName: return "road.lanes"
            }
        }

        var displayLabel: String {
            switch self {
            case .address: return "Address"
            case .city: return "City"
            case .neighborhood: return "Area"
            case .zip: return "ZIP"
            case .mlsNumber: return "MLS #"
            case .streetName: return "Street"
            }
        }
    }
}

// MARK: - Recent Search (with full filter memory)

struct RecentSearch: Codable, Identifiable {
    let id: UUID
    let displayText: String
    let filters: LocalSavedSearchFilters
    let timestamp: Date

    init(id: UUID = UUID(), displayText: String, filters: LocalSavedSearchFilters, timestamp: Date = Date()) {
        self.id = id
        self.displayText = displayText
        self.filters = filters
        self.timestamp = timestamp
    }

    /// Generate display text from filters
    static func generateDisplayText(from filters: PropertySearchFilters) -> String {
        var parts: [String] = []

        // Location
        if !filters.cities.isEmpty {
            parts.append(filters.cities.first ?? "")
            if filters.cities.count > 1 {
                parts[0] += " +\(filters.cities.count - 1)"
            }
        } else if !filters.neighborhoods.isEmpty {
            parts.append(filters.neighborhoods.first ?? "")
        } else if !filters.zips.isEmpty {
            parts.append(filters.zips.first ?? "")
        } else if let address = filters.address {
            parts.append(address)
        }

        return parts.isEmpty ? "All Properties" : parts.joined(separator: ", ")
    }

    /// Filter pills for display
    var filterPills: [String] {
        var pills: [String] = []

        // Listing type
        if filters.listingType == "for_rent" {
            pills.append("For Rent")
        } else {
            pills.append("For Sale")
        }

        // Beds
        if !filters.beds.isEmpty {
            let minBeds = filters.beds.min() ?? 0
            pills.append("\(minBeds)+ beds")
        }

        // Price
        if let min = filters.minPrice, let max = filters.maxPrice {
            pills.append("$\(formatPrice(min))-$\(formatPrice(max))")
        } else if let min = filters.minPrice {
            pills.append("$\(formatPrice(min))+")
        } else if let max = filters.maxPrice {
            pills.append("Up to $\(formatPrice(max))")
        }

        // Baths
        if let baths = filters.minBaths, baths > 0 {
            pills.append("\(Int(baths))+ baths")
        }

        return pills
    }

    private func formatPrice(_ price: Int) -> String {
        if price >= 1_000_000 {
            return String(format: "%.1fM", Double(price) / 1_000_000)
        } else {
            return "\(price / 1000)K"
        }
    }

    /// Time ago string
    var timeAgo: String {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .abbreviated
        return formatter.localizedString(for: timestamp, relativeTo: Date())
    }
}

// MARK: - User Preferences

struct UserPropertyPreferences: Codable {
    var likedPropertyIds: Set<String> = []
    var hiddenPropertyIds: Set<String> = []
    var recentSearches: [RecentSearch] = []
    var legacyRecentSearches: [String] = []  // For migration
    var savedSearches: [LocalSavedSearch] = []
    var preferredSort: SortOption = .dateDesc
    var preferredView: ViewMode = .list
    var mapType: MapType = .standard

    enum ViewMode: String, Codable {
        case list
        case map
        case split
    }

    enum MapType: String, Codable {
        case standard
        case satellite
        case hybrid
    }

    enum CodingKeys: String, CodingKey {
        case likedPropertyIds
        case hiddenPropertyIds
        case recentSearches
        case legacyRecentSearches = "recentSearchesLegacy"
        case savedSearches
        case preferredSort
        case preferredView
        case mapType
    }

    init() {}

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        likedPropertyIds = try container.decodeIfPresent(Set<String>.self, forKey: .likedPropertyIds) ?? []
        hiddenPropertyIds = try container.decodeIfPresent(Set<String>.self, forKey: .hiddenPropertyIds) ?? []
        savedSearches = try container.decodeIfPresent([LocalSavedSearch].self, forKey: .savedSearches) ?? []
        preferredSort = try container.decodeIfPresent(SortOption.self, forKey: .preferredSort) ?? .dateDesc
        preferredView = try container.decodeIfPresent(ViewMode.self, forKey: .preferredView) ?? .list
        mapType = try container.decodeIfPresent(MapType.self, forKey: .mapType) ?? .standard

        // Try to decode new format first, fall back to legacy
        if let newSearches = try? container.decodeIfPresent([RecentSearch].self, forKey: .recentSearches) {
            recentSearches = newSearches
        } else if let legacySearches = try? container.decodeIfPresent([String].self, forKey: .recentSearches) {
            // Migrate legacy searches
            legacyRecentSearches = legacySearches
            recentSearches = []
        }
    }

    mutating func addRecentSearch(_ search: RecentSearch) {
        // Remove existing search with same display text
        recentSearches.removeAll { $0.displayText == search.displayText }
        recentSearches.insert(search, at: 0)
        // Keep only last 5 searches
        if recentSearches.count > 5 {
            recentSearches = Array(recentSearches.prefix(5))
        }
    }

    mutating func addRecentSearch(displayText: String, filters: PropertySearchFilters) {
        let localFilters = LocalSavedSearchFilters(from: filters)
        let search = RecentSearch(displayText: displayText, filters: localFilters)
        addRecentSearch(search)
    }
}
