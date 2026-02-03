//
//  CMA.swift
//  BMNBoston
//
//  Created for Quick CMA feature
//  Allows agents to generate Comparative Market Analysis reports from property details
//

import Foundation
import SwiftUI

// MARK: - CMA Response

/// Response from the /cma/property/{listing_id} endpoint
struct CMAResponse: Codable {
    let subject: CMASubjectProperty
    let estimatedValue: Int?
    let valueRange: CMAValueRange
    let confidenceScore: Int?
    let rangeQuality: String?        // "tight", "moderate", "wide"
    let filterTierUsed: String?      // "tight", "moderate", "relaxed"
    let comparables: [CMAComparable]
    let comparablesCount: Int

    private enum CodingKeys: String, CodingKey {
        case subject
        case estimatedValue = "estimated_value"
        case valueRange = "value_range"
        case confidenceScore = "confidence_score"
        case rangeQuality = "range_quality"
        case filterTierUsed = "filter_tier_used"
        case comparables
        case comparablesCount = "comparables_count"
    }

    /// Confidence level as a descriptive string
    var confidenceLevel: String {
        guard let score = confidenceScore else { return "Low" }
        switch score {
        case 90...: return "Very High"
        case 80..<90: return "High"
        case 70..<80: return "Moderate"
        case 60..<70: return "Low"
        default: return "Very Low"
        }
    }

    /// Confidence color name for UI
    var confidenceColor: String {
        guard let score = confidenceScore else { return "gray" }
        switch score {
        case 80...: return "green"
        case 60..<80: return "orange"
        default: return "red"
        }
    }

    /// Human-readable range quality description
    var rangeQualityDescription: String {
        switch rangeQuality {
        case "tight": return "High precision estimate"
        case "moderate": return "Good estimate"
        case "wide": return "Approximate estimate"
        default: return "Limited data"
        }
    }
}

// MARK: - Subject Property

/// The subject property for the CMA
struct CMASubjectProperty: Codable {
    let listingId: String
    let address: String
    let city: String
    let state: String
    let beds: Int
    let baths: Double
    let sqft: Int
    let listPrice: Int

    private enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case address
        case city
        case state
        case beds
        case baths
        case sqft
        case listPrice = "list_price"
    }

    // Custom decoder to handle Int-to-Double conversion for baths
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        listingId = try container.decode(String.self, forKey: .listingId)
        address = try container.decode(String.self, forKey: .address)
        city = try container.decode(String.self, forKey: .city)
        state = try container.decode(String.self, forKey: .state)
        beds = try container.decode(Int.self, forKey: .beds)
        sqft = try container.decode(Int.self, forKey: .sqft)
        listPrice = try container.decode(Int.self, forKey: .listPrice)

        // Handle baths - try Double first, fall back to Int
        if let bathsDouble = try? container.decode(Double.self, forKey: .baths) {
            baths = bathsDouble
        } else if let bathsInt = try? container.decode(Int.self, forKey: .baths) {
            baths = Double(bathsInt)
        } else {
            baths = 0
        }
    }

    /// Formatted address including city and state
    var fullAddress: String {
        "\(address), \(city), \(state)"
    }

    /// Formatted bed/bath/sqft summary
    var summary: String {
        let bathsFormatted = baths.truncatingRemainder(dividingBy: 1) == 0
            ? String(format: "%.0f", baths)
            : String(format: "%.1f", baths)
        return "\(beds) bed \u{2022} \(bathsFormatted) bath \u{2022} \(sqft.formatted()) sqft"
    }
}

// MARK: - Value Range

/// Estimated value range from CMA analysis
struct CMAValueRange: Codable {
    let low: Int?
    let high: Int?

    /// Mid-point estimate
    var mid: Int? {
        // Check for nil AND zero values
        guard let low = low, let high = high, low > 0, high > 0 else { return nil }
        return (low + high) / 2
    }

    /// Formatted range string
    var formatted: String {
        // Check for nil AND zero values - API returns 0 when there are no comparables
        guard let low = low, let high = high, low > 0, high > 0 else { return "Unable to estimate" }
        return "$\(low.formatted()) - $\(high.formatted())"
    }
}

// MARK: - Comparable Property

/// A comparable sale used in the CMA
struct CMAComparable: Codable, Identifiable {
    let listingId: String
    let address: String
    let city: String
    let state: String
    let beds: Int
    let baths: Double
    let sqft: Int
    let soldPrice: Int
    let soldDate: String?
    let distanceMiles: Double
    let pricePerSqft: Int?
    let photoUrl: String?
    let comparabilityScore: Double?
    let comparabilityGrade: String?

    var id: String { listingId }

    private enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case address
        case city
        case state
        case beds
        case baths
        case sqft
        case soldPrice = "sold_price"
        case soldDate = "sold_date"
        case distanceMiles = "distance_miles"
        case pricePerSqft = "price_per_sqft"
        case photoUrl = "photo_url"
        case comparabilityScore = "comparability_score"
        case comparabilityGrade = "comparability_grade"
    }

    // Custom decoder to handle Int-to-Double conversion for baths and distanceMiles
    // PHP's json_encode outputs whole numbers as integers (2 instead of 2.0)
    // which causes Swift's JSONDecoder to fail when decoding into Double fields
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        listingId = try container.decode(String.self, forKey: .listingId)
        address = try container.decode(String.self, forKey: .address)
        city = try container.decode(String.self, forKey: .city)
        state = try container.decode(String.self, forKey: .state)
        beds = try container.decode(Int.self, forKey: .beds)
        sqft = try container.decode(Int.self, forKey: .sqft)
        soldPrice = try container.decode(Int.self, forKey: .soldPrice)
        soldDate = try container.decodeIfPresent(String.self, forKey: .soldDate)
        pricePerSqft = try container.decodeIfPresent(Int.self, forKey: .pricePerSqft)
        photoUrl = try container.decodeIfPresent(String.self, forKey: .photoUrl)

        // Handle baths - try Double first, fall back to Int
        if let bathsDouble = try? container.decode(Double.self, forKey: .baths) {
            baths = bathsDouble
        } else if let bathsInt = try? container.decode(Int.self, forKey: .baths) {
            baths = Double(bathsInt)
        } else {
            baths = 0
        }

        // Handle distanceMiles - try Double first, fall back to Int
        if let distanceDouble = try? container.decode(Double.self, forKey: .distanceMiles) {
            distanceMiles = distanceDouble
        } else if let distanceInt = try? container.decode(Int.self, forKey: .distanceMiles) {
            distanceMiles = Double(distanceInt)
        } else {
            distanceMiles = 0
        }

        // Handle comparability score - try Double first, fall back to Int
        if let scoreDouble = try? container.decode(Double.self, forKey: .comparabilityScore) {
            comparabilityScore = scoreDouble
        } else if let scoreInt = try? container.decode(Int.self, forKey: .comparabilityScore) {
            comparabilityScore = Double(scoreInt)
        } else {
            comparabilityScore = nil
        }

        comparabilityGrade = try container.decodeIfPresent(String.self, forKey: .comparabilityGrade)
    }

    /// Formatted distance string
    var formattedDistance: String {
        String(format: "%.1f mi", distanceMiles)
    }

    /// Formatted price per sqft
    var formattedPricePerSqft: String? {
        guard let ppsf = pricePerSqft else { return nil }
        return "$\(ppsf)/sqft"
    }

    /// Photo URL as URL type
    var photoURL: URL? {
        guard let urlString = photoUrl else { return nil }
        return URL(string: urlString)
    }

    /// Formatted sold date
    var formattedSoldDate: String? {
        guard let dateString = soldDate else { return nil }

        // Try parsing ISO date format
        let isoFormatter = ISO8601DateFormatter()
        isoFormatter.formatOptions = [.withFullDate, .withDashSeparatorInDate]

        if let date = isoFormatter.date(from: dateString) {
            let displayFormatter = DateFormatter()
            displayFormatter.dateStyle = .medium
            return displayFormatter.string(from: date)
        }

        // Try parsing database datetime format
        let dbFormatter = DateFormatter()
        dbFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        dbFormatter.timeZone = TimeZone(identifier: "America/New_York")

        if let date = dbFormatter.date(from: dateString) {
            let displayFormatter = DateFormatter()
            displayFormatter.dateStyle = .medium
            return displayFormatter.string(from: date)
        }

        // Return original string if parsing fails
        return dateString
    }

    /// Grade color for UI
    var gradeColor: Color {
        switch comparabilityGrade {
        case "A": return .green
        case "B": return .blue
        default: return .gray
        }
    }
}

// MARK: - PDF Generation Response

/// Response from the PDF generation endpoint
struct CMAPDFResponse: Codable {
    let pdfUrl: String
    let generatedAt: String

    private enum CodingKeys: String, CodingKey {
        case pdfUrl = "pdf_url"
        case generatedAt = "generated_at"
    }

    /// PDF URL as URL type
    var url: URL? {
        URL(string: pdfUrl)
    }
}

// MARK: - PDF Generation Request

/// Request parameters for generating a CMA PDF
struct CMAPDFRequest: Codable {
    let listingId: String
    let preparedFor: String?
    let includePhotos: Bool
    let includeForecast: Bool
    let includeInvestment: Bool

    private enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case preparedFor = "prepared_for"
        case includePhotos = "include_photos"
        case includeForecast = "include_forecast"
        case includeInvestment = "include_investment"
    }

    init(listingId: String, preparedFor: String? = nil) {
        self.listingId = listingId
        self.preparedFor = preparedFor
        self.includePhotos = true
        self.includeForecast = true
        self.includeInvestment = true
    }
}
