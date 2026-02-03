//
//  ExclusiveListing.swift
//  BMNBoston
//
//  Exclusive Listings - Agent-created non-MLS property listings
//  API: /mld-mobile/v1/exclusive-listings
//
//  Created for BMN Boston Real Estate
//

import Foundation
import CoreLocation

// MARK: - ExclusiveListing Model

struct ExclusiveListing: Identifiable, Codable, Equatable {
    let id: Int
    let listingId: Int
    let listingKey: String
    let mlsId: String?
    let isExclusive: Bool
    // v6.65.0 / v1.5.0: Custom badge text for exclusive listings (Coming Soon, Off-Market, etc.)
    let exclusiveTag: String?

    // Status
    let status: String
    let standardStatus: String

    // Price
    let listPrice: Double
    let pricePerSqft: Double?

    // Property type
    let propertyType: String
    let propertySubType: String?

    // Address
    let streetNumber: String?
    let streetName: String?
    let unitNumber: String?
    let city: String
    let state: String
    let stateOrProvince: String
    let postalCode: String?
    let county: String?
    let subdivisionName: String?
    let unparsedAddress: String?

    // Coordinates
    let latitude: Double?
    let longitude: Double?

    // Property details
    let bedroomsTotal: Int?
    let bathroomsTotal: Double?
    let bathroomsFull: Int?
    let bathroomsHalf: Int?
    let buildingAreaTotal: Int?
    let lotSizeAcres: Double?
    // v1.5.0: Lot size in square feet (API now returns both for MLS parity)
    let lotSizeSquareFeet: Int?
    let yearBuilt: Int?
    let garageSpaces: Int?

    // Legacy Features (kept for backward compatibility)
    let hasPool: Bool
    let hasFireplace: Bool
    let hasBasement: Bool
    let hasHoa: Bool

    // v1.4.0 - Tier 1 Property Description
    let originalListPrice: Double?
    let architecturalStyle: String?
    let storiesTotal: Int?
    let virtualTourUrl: String?
    let publicRemarks: String?
    let privateRemarks: String?
    let showingInstructions: String?

    // v1.4.0 - Tier 2 Interior Details
    let heating: String?
    let cooling: String?
    let heatingYn: Bool?
    let coolingYn: Bool?
    let interiorFeatures: String?
    let appliances: String?
    let flooring: String?
    let laundryFeatures: String?
    let basement: String?

    // v1.4.0 - Tier 3 Exterior & Lot
    let constructionMaterials: String?
    let roof: String?
    let foundationDetails: String?
    let exteriorFeatures: String?
    let waterfrontYn: Bool?
    let waterfrontFeatures: String?
    let viewYn: Bool?
    let view: String?
    let parkingFeatures: String?
    let parkingTotal: Int?

    // v1.4.0 - Tier 4 Financial
    let taxAnnualAmount: Double?
    let taxYear: Int?
    let associationYn: Bool?
    let associationFee: Double?
    let associationFeeFrequency: String?
    let associationFeeIncludes: String?

    // Media
    let mainPhotoUrl: String?
    let photoCount: Int

    // Dates
    let listingContractDate: String?
    let daysOnMarket: Int
    let modificationTimestamp: String?

    // URL
    let url: String?

    // Photos (when fetched with detail endpoint)
    var photos: [ExclusiveListingPhoto]?

    enum CodingKeys: String, CodingKey {
        case id
        case listingId = "listing_id"
        case listingKey = "listing_key"
        case mlsId = "mls_id"
        case isExclusive = "is_exclusive"
        case exclusiveTag = "exclusive_tag"
        case status
        case standardStatus = "standard_status"
        case listPrice = "list_price"
        case pricePerSqft = "price_per_sqft"
        case propertyType = "property_type"
        case propertySubType = "property_sub_type"
        case streetNumber = "street_number"
        case streetName = "street_name"
        case unitNumber = "unit_number"
        case city
        case state
        case stateOrProvince = "state_or_province"
        case postalCode = "postal_code"
        case county
        case subdivisionName = "subdivision_name"
        case unparsedAddress = "unparsed_address"
        case latitude
        case longitude
        case bedroomsTotal = "bedrooms_total"
        case bathroomsTotal = "bathrooms_total"
        case bathroomsFull = "bathrooms_full"
        case bathroomsHalf = "bathrooms_half"
        case buildingAreaTotal = "building_area_total"
        case lotSizeAcres = "lot_size_acres"
        case lotSizeSquareFeet = "lot_size_square_feet"
        case yearBuilt = "year_built"
        case garageSpaces = "garage_spaces"
        case hasPool = "has_pool"
        case hasFireplace = "has_fireplace"
        case hasBasement = "has_basement"
        case hasHoa = "has_hoa"

        // v1.4.0 - Tier 1 Property Description
        case originalListPrice = "original_list_price"
        case architecturalStyle = "architectural_style"
        case storiesTotal = "stories_total"
        case virtualTourUrl = "virtual_tour_url"
        case publicRemarks = "public_remarks"
        case privateRemarks = "private_remarks"
        case showingInstructions = "showing_instructions"

        // v1.4.0 - Tier 2 Interior Details
        case heating
        case cooling
        case heatingYn = "heating_yn"
        case coolingYn = "cooling_yn"
        case interiorFeatures = "interior_features"
        case appliances
        case flooring
        case laundryFeatures = "laundry_features"
        case basement

        // v1.4.0 - Tier 3 Exterior & Lot
        case constructionMaterials = "construction_materials"
        case roof
        case foundationDetails = "foundation_details"
        case exteriorFeatures = "exterior_features"
        case waterfrontYn = "waterfront_yn"
        case waterfrontFeatures = "waterfront_features"
        case viewYn = "view_yn"
        case view
        case parkingFeatures = "parking_features"
        case parkingTotal = "parking_total"

        // v1.4.0 - Tier 4 Financial
        case taxAnnualAmount = "tax_annual_amount"
        case taxYear = "tax_year"
        case associationYn = "association_yn"
        case associationFee = "association_fee"
        case associationFeeFrequency = "association_fee_frequency"
        case associationFeeIncludes = "association_fee_includes"

        case mainPhotoUrl = "main_photo_url"
        case photoCount = "photo_count"
        case listingContractDate = "listing_contract_date"
        case daysOnMarket = "days_on_market"
        case modificationTimestamp = "modification_timestamp"
        case url
        case photos
    }

    // MARK: - Computed Properties

    var fullAddress: String {
        var parts: [String] = []
        if let num = streetNumber { parts.append(num) }
        if let name = streetName { parts.append(name) }
        if let unit = unitNumber, !unit.isEmpty { parts.append("Unit \(unit)") }

        let street = parts.joined(separator: " ")
        return "\(street), \(city), \(state) \(postalCode ?? "")"
    }

    var shortAddress: String {
        var parts: [String] = []
        if let num = streetNumber { parts.append(num) }
        if let name = streetName { parts.append(name) }
        if let unit = unitNumber, !unit.isEmpty { parts.append("#\(unit)") }
        return parts.joined(separator: " ")
    }

    var formattedPrice: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: listPrice)) ?? "$\(Int(listPrice))"
    }

    var formattedBeds: String {
        guard let beds = bedroomsTotal else { return "-" }
        return "\(beds)"
    }

    var formattedBaths: String {
        guard let baths = bathroomsTotal else { return "-" }
        if baths.truncatingRemainder(dividingBy: 1) == 0 {
            return "\(Int(baths))"
        }
        return String(format: "%.1f", baths)
    }

    var formattedSqft: String? {
        guard let sqft = buildingAreaTotal else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: sqft))
    }

    var primaryImageURL: URL? {
        guard let urlString = mainPhotoUrl else { return nil }
        return URL(string: urlString)
    }

    var coordinate: CLLocationCoordinate2D? {
        guard let lat = latitude, let lng = longitude else { return nil }
        return CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var statusColor: String {
        switch standardStatus.lowercased() {
        case "active": return "green"
        case "pending", "active under contract": return "orange"
        case "closed": return "gray"
        default: return "blue"
        }
    }
}

// MARK: - ExclusiveListingPhoto

struct ExclusiveListingPhoto: Identifiable, Codable, Equatable {
    let id: Int
    let url: String
    let sortOrder: Int
    let isPrimary: Bool

    enum CodingKeys: String, CodingKey {
        case id
        case url
        case sortOrder = "sort_order"
        case isPrimary = "is_primary"
    }

    var imageURL: URL? {
        URL(string: url)
    }
}

// MARK: - API Response Types

struct ExclusiveListingListResponse: Decodable {
    let items: [ExclusiveListing]
    let total: Int
    let page: Int
    let perPage: Int
    let totalPages: Int

    enum CodingKeys: String, CodingKey {
        case items
        case total
        case page
        case perPage = "per_page"
        case totalPages = "total_pages"
    }
}

struct ExclusiveListingSingleResponse: Decodable {
    let listing: ExclusiveListing
}

struct ExclusiveListingCreateResponse: Decodable {
    let listing: ExclusiveListing
    let message: String
}

struct ExclusiveListingUpdateResponse: Decodable {
    let listing: ExclusiveListing
    let message: String
}

struct ExclusiveListingDeleteResponse: Decodable {
    let message: String
}

struct ExclusiveListingPhotosResponse: Decodable {
    let photos: [ExclusiveListingPhoto]
    let count: Int
}

struct ExclusiveListingPhotoUploadResponse: Decodable {
    let results: [PhotoUploadResult]
    let uploaded: Int
    let failed: Int
}

struct PhotoUploadResult: Decodable {
    let filename: String
    let success: Bool
    let data: ExclusiveListingPhoto?
    let error: String?
}

struct ExclusiveListingOptionsResponse: Decodable {
    // Basic options
    let propertyTypes: [String]
    let propertySubTypes: [String: [String]]
    let statuses: [String]
    let requiredFields: [String]
    let maxPhotos: Int
    let maxFileSizeMb: Double

    // v1.4.0 - Property Description
    let architecturalStyles: [String]?

    // v1.4.0 - Interior Details
    let heatingTypes: [String]?
    let coolingTypes: [String]?
    let interiorFeatures: [String]?
    let appliances: [String]?
    let flooringTypes: [String]?
    let laundryFeatures: [String]?
    let basementTypes: [String]?

    // v1.4.0 - Exterior & Lot
    let constructionMaterials: [String]?
    let roofTypes: [String]?
    let foundationTypes: [String]?
    let exteriorFeatures: [String]?
    let waterfrontFeatures: [String]?
    let viewTypes: [String]?
    let parkingFeatures: [String]?

    // v1.4.0 - Financial
    let associationFeeFrequencies: [String]?
    let associationFeeIncludes: [String]?

    enum CodingKeys: String, CodingKey {
        case propertyTypes = "property_types"
        case propertySubTypes = "property_sub_types"
        case statuses
        case requiredFields = "required_fields"
        case maxPhotos = "max_photos"
        case maxFileSizeMb = "max_file_size_mb"

        // v1.4.0 options
        case architecturalStyles = "architectural_styles"
        case heatingTypes = "heating_types"
        case coolingTypes = "cooling_types"
        case interiorFeatures = "interior_features"
        case appliances
        case flooringTypes = "flooring_types"
        case laundryFeatures = "laundry_features"
        case basementTypes = "basement_types"
        case constructionMaterials = "construction_materials"
        case roofTypes = "roof_types"
        case foundationTypes = "foundation_types"
        case exteriorFeatures = "exterior_features"
        case waterfrontFeatures = "waterfront_features"
        case viewTypes = "view_types"
        case parkingFeatures = "parking_features"
        case associationFeeFrequencies = "association_fee_frequencies"
        case associationFeeIncludes = "association_fee_includes"
    }
}

// MARK: - Create/Update Request

struct ExclusiveListingRequest {
    // Required
    var streetNumber: String = ""
    var streetName: String = ""
    var city: String = ""
    var stateOrProvince: String = "MA"
    var postalCode: String = ""
    var listPrice: Double = 0
    var propertyType: String = "Residential"

    // Optional - Basic
    var unitNumber: String?
    var propertySubType: String?
    var standardStatus: String = "Active"
    // v6.65.0 / v1.5.0: Custom badge text for exclusive listings
    var exclusiveTag: String = "Exclusive"
    var bedroomsTotal: Int?
    var bathroomsTotal: Double?
    var bathroomsFull: Int?
    var bathroomsHalf: Int?
    var buildingAreaTotal: Int?
    var lotSizeAcres: Double?
    // v1.5.0: Lot size in square feet (auto-converts with acres)
    var lotSizeSquareFeet: Int?
    var yearBuilt: Int?
    var garageSpaces: Int?
    var hasPool: Bool = false
    var hasFireplace: Bool = false
    var hasBasement: Bool = false
    var hasHoa: Bool = false
    var latitude: Double?
    var longitude: Double?
    var listingContractDate: Date?

    // v1.4.0 - Tier 1 Property Description
    var originalListPrice: Double?
    var architecturalStyle: String?
    var storiesTotal: Int?
    var virtualTourUrl: String?
    var publicRemarks: String?
    var privateRemarks: String?
    var showingInstructions: String?

    // v1.4.0 - Tier 2 Interior Details
    var heating: [String] = []
    var cooling: [String] = []
    var interiorFeatures: [String] = []
    var appliances: [String] = []
    var flooring: [String] = []
    var laundryFeatures: [String] = []
    var basement: String?

    // v1.4.0 - Tier 3 Exterior & Lot
    var constructionMaterials: [String] = []
    var roof: String?
    var foundationDetails: String?
    var exteriorFeatures: [String] = []
    var waterfrontYn: Bool = false
    var waterfrontFeatures: [String] = []
    var viewYn: Bool = false
    var view: [String] = []
    var parkingFeatures: [String] = []
    var parkingTotal: Int?

    // v1.4.0 - Tier 4 Financial
    var taxAnnualAmount: Double?
    var taxYear: Int?
    var associationYn: Bool = false
    var associationFee: Double?
    var associationFeeFrequency: String?
    var associationFeeIncludes: [String] = []

    func toDictionary() -> [String: Any] {
        var dict: [String: Any] = [
            "street_number": streetNumber,
            "street_name": streetName,
            "city": city,
            "state_or_province": stateOrProvince,
            "postal_code": postalCode,
            "list_price": listPrice,
            "property_type": propertyType,
            "standard_status": standardStatus,
            "has_pool": hasPool,
            "has_fireplace": hasFireplace,
            "has_basement": hasBasement,
            "has_hoa": hasHoa,
            "exclusive_tag": exclusiveTag
        ]

        // Basic optional fields
        if let unit = unitNumber, !unit.isEmpty { dict["unit_number"] = unit }
        if let subType = propertySubType { dict["property_sub_type"] = subType }
        if let beds = bedroomsTotal { dict["bedrooms_total"] = beds }
        if let baths = bathroomsTotal { dict["bathrooms_total"] = baths }
        if let bathsFull = bathroomsFull { dict["bathrooms_full"] = bathsFull }
        if let bathsHalf = bathroomsHalf { dict["bathrooms_half"] = bathsHalf }
        if let sqft = buildingAreaTotal { dict["building_area_total"] = sqft }
        if let lot = lotSizeAcres { dict["lot_size_acres"] = lot }
        if let lotSqFt = lotSizeSquareFeet { dict["lot_size_square_feet"] = lotSqFt }
        if let year = yearBuilt { dict["year_built"] = year }
        if let garage = garageSpaces { dict["garage_spaces"] = garage }
        if let lat = latitude { dict["latitude"] = lat }
        if let lng = longitude { dict["longitude"] = lng }
        if let date = listingContractDate {
            let formatter = DateFormatter()
            formatter.dateFormat = "yyyy-MM-dd"
            dict["listing_contract_date"] = formatter.string(from: date)
        }

        // Tier 1 - Property Description
        if let origPrice = originalListPrice { dict["original_list_price"] = origPrice }
        if let style = architecturalStyle, !style.isEmpty { dict["architectural_style"] = style }
        if let stories = storiesTotal { dict["stories_total"] = stories }
        if let tourUrl = virtualTourUrl, !tourUrl.isEmpty { dict["virtual_tour_url"] = tourUrl }
        if let remarks = publicRemarks, !remarks.isEmpty { dict["public_remarks"] = remarks }
        if let privRemarks = privateRemarks, !privRemarks.isEmpty { dict["private_remarks"] = privRemarks }
        if let showing = showingInstructions, !showing.isEmpty { dict["showing_instructions"] = showing }

        // Tier 2 - Interior Details (multi-select as comma-separated)
        if !heating.isEmpty { dict["heating"] = heating.joined(separator: ", ") }
        if !cooling.isEmpty { dict["cooling"] = cooling.joined(separator: ", ") }
        if !interiorFeatures.isEmpty { dict["interior_features"] = interiorFeatures.joined(separator: ", ") }
        if !appliances.isEmpty { dict["appliances"] = appliances.joined(separator: ", ") }
        if !flooring.isEmpty { dict["flooring"] = flooring.joined(separator: ", ") }
        if !laundryFeatures.isEmpty { dict["laundry_features"] = laundryFeatures.joined(separator: ", ") }
        if let basementVal = basement, !basementVal.isEmpty { dict["basement"] = basementVal }

        // Tier 3 - Exterior & Lot
        if !constructionMaterials.isEmpty { dict["construction_materials"] = constructionMaterials.joined(separator: ", ") }
        if let roofVal = roof, !roofVal.isEmpty { dict["roof"] = roofVal }
        if let foundation = foundationDetails, !foundation.isEmpty { dict["foundation_details"] = foundation }
        if !exteriorFeatures.isEmpty { dict["exterior_features"] = exteriorFeatures.joined(separator: ", ") }
        dict["waterfront_yn"] = waterfrontYn
        if !waterfrontFeatures.isEmpty { dict["waterfront_features"] = waterfrontFeatures.joined(separator: ", ") }
        dict["view_yn"] = viewYn
        if !view.isEmpty { dict["view"] = view.joined(separator: ", ") }
        if !parkingFeatures.isEmpty { dict["parking_features"] = parkingFeatures.joined(separator: ", ") }
        if let parkTotal = parkingTotal { dict["parking_total"] = parkTotal }

        // Tier 4 - Financial
        if let taxAmount = taxAnnualAmount { dict["tax_annual_amount"] = taxAmount }
        if let taxYr = taxYear { dict["tax_year"] = taxYr }
        dict["association_yn"] = associationYn
        if let assocFee = associationFee { dict["association_fee"] = assocFee }
        if let assocFreq = associationFeeFrequency, !assocFreq.isEmpty { dict["association_fee_frequency"] = assocFreq }
        if !associationFeeIncludes.isEmpty { dict["association_fee_includes"] = associationFeeIncludes.joined(separator: ", ") }

        return dict
    }

    var isValid: Bool {
        !streetNumber.isEmpty &&
        !streetName.isEmpty &&
        !city.isEmpty &&
        !postalCode.isEmpty &&
        listPrice > 0 &&
        !propertyType.isEmpty
    }
}
