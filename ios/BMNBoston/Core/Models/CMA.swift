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
    let marketContext: CMAMarketContext?

    private enum CodingKeys: String, CodingKey {
        case subject
        case estimatedValue = "estimated_value"
        case valueRange = "value_range"
        case confidenceScore = "confidence_score"
        case rangeQuality = "range_quality"
        case filterTierUsed = "filter_tier_used"
        case comparables
        case comparablesCount = "comparables_count"
        case marketContext = "market_context"
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
    let adjustedPrice: Int?
    let adjustments: CMAComparableAdjustments?

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
        case adjustedPrice = "adjusted_price"
        case adjustments
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
        adjustedPrice = try container.decodeIfPresent(Int.self, forKey: .adjustedPrice)
        adjustments = try container.decodeIfPresent(CMAComparableAdjustments.self, forKey: .adjustments)

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

    /// Has any adjustments applied
    var hasAdjustments: Bool {
        guard let adj = adjustments, let items = adj.items else { return false }
        return !items.isEmpty
    }

    /// Formatted adjusted price
    var formattedAdjustedPrice: String? {
        guard let price = adjustedPrice else { return nil }
        return "$\(price.formatted())"
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

// MARK: - Comparable Adjustments

/// Individual adjustment item for a comparable
struct CMAComparableAdjustmentItem: Codable, Identifiable {
    let feature: String
    let difference: String
    let adjustment: Int

    var id: String { feature }

    private enum CodingKeys: String, CodingKey {
        case feature
        case difference
        case adjustment
    }

    /// Formatted adjustment amount with sign
    var formattedAdjustment: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let value = formatter.string(from: NSNumber(value: abs(adjustment))) ?? "$\(abs(adjustment))"
        return adjustment >= 0 ? "+\(value)" : "-\(value)"
    }
}

/// Adjustments container for a comparable property
struct CMAComparableAdjustments: Codable {
    let items: [CMAComparableAdjustmentItem]?
    let totalAdjustment: Int?
    let grossPct: Double?
    let netPct: Double?
    let warnings: [String]?

    private enum CodingKeys: String, CodingKey {
        case items
        case totalAdjustment = "total_adjustment"
        case grossPct = "gross_pct"
        case netPct = "net_pct"
        case warnings
    }

    // Custom decoder to handle Int-to-Double conversion for percentage fields
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        items = try container.decodeIfPresent([CMAComparableAdjustmentItem].self, forKey: .items)
        totalAdjustment = try container.decodeIfPresent(Int.self, forKey: .totalAdjustment)
        warnings = try container.decodeIfPresent([String].self, forKey: .warnings)

        // Handle grossPct - try Double first, fall back to Int
        if let pctDouble = try? container.decode(Double.self, forKey: .grossPct) {
            grossPct = pctDouble
        } else if let pctInt = try? container.decode(Int.self, forKey: .grossPct) {
            grossPct = Double(pctInt)
        } else {
            grossPct = nil
        }

        // Handle netPct - try Double first, fall back to Int
        if let pctDouble = try? container.decode(Double.self, forKey: .netPct) {
            netPct = pctDouble
        } else if let pctInt = try? container.decode(Int.self, forKey: .netPct) {
            netPct = Double(pctInt)
        } else {
            netPct = nil
        }
    }

    /// Formatted total adjustment
    var formattedTotalAdjustment: String {
        guard let total = totalAdjustment else { return "$0" }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let value = formatter.string(from: NSNumber(value: abs(total))) ?? "$\(abs(total))"
        return total >= 0 ? "+\(value)" : "-\(value)"
    }

    /// Formatted net percentage
    var formattedNetPct: String? {
        guard let pct = netPct else { return nil }
        return String(format: "%.1f%% net adj", abs(pct))
    }

    /// Has warning if net adjustment exceeds 15%
    var hasWarning: Bool {
        guard let pct = netPct else { return false }
        return abs(pct) > 15.0
    }
}

// MARK: - Market Context

/// Market context information for the CMA area
struct CMAMarketContext: Codable {
    let marketType: String?
    let avgSpLpRatio: Double?
    let avgDom: Double?  // Changed from Int? - API returns decimal values like 65.2
    let monthlyVelocity: Double?
    let description: String?

    private enum CodingKeys: String, CodingKey {
        case marketType = "market_type"
        case avgSpLpRatio = "avg_sp_lp_ratio"
        case avgDom = "avg_dom"
        case monthlyVelocity = "monthly_velocity"
        case description
    }

    // Custom decoder to handle Int-to-Double conversion
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        marketType = try container.decodeIfPresent(String.self, forKey: .marketType)
        description = try container.decodeIfPresent(String.self, forKey: .description)

        // Handle avgSpLpRatio - try Double first, fall back to Int
        if let ratio = try? container.decode(Double.self, forKey: .avgSpLpRatio) {
            avgSpLpRatio = ratio
        } else if let ratioInt = try? container.decode(Int.self, forKey: .avgSpLpRatio) {
            avgSpLpRatio = Double(ratioInt)
        } else {
            avgSpLpRatio = nil
        }

        // Handle avgDom - try Double first, fall back to Int
        if let dom = try? container.decode(Double.self, forKey: .avgDom) {
            avgDom = dom
        } else if let domInt = try? container.decode(Int.self, forKey: .avgDom) {
            avgDom = Double(domInt)
        } else {
            avgDom = nil
        }

        // Handle monthlyVelocity - try Double first, fall back to Int
        if let velocity = try? container.decode(Double.self, forKey: .monthlyVelocity) {
            monthlyVelocity = velocity
        } else if let velocityInt = try? container.decode(Int.self, forKey: .monthlyVelocity) {
            monthlyVelocity = Double(velocityInt)
        } else {
            monthlyVelocity = nil
        }
    }

    /// Display name for market type
    var marketTypeDisplay: String {
        switch marketType {
        case "seller": return "Seller's Market"
        case "buyer": return "Buyer's Market"
        case "balanced": return "Balanced Market"
        default: return "Unknown"
        }
    }

    /// Icon for market type
    var marketTypeIcon: String {
        switch marketType {
        case "seller": return "flame.fill"
        case "buyer": return "snowflake"
        case "balanced": return "scale.3d"
        default: return "questionmark.circle"
        }
    }

    /// Color name for market type
    var marketTypeColorName: String {
        switch marketType {
        case "seller": return "green"
        case "buyer": return "blue"
        case "balanced": return "gray"
        default: return "gray"
        }
    }

    /// Formatted SP/LP ratio
    var formattedSpLpRatio: String? {
        guard let ratio = avgSpLpRatio else { return nil }
        return String(format: "%.1f%%", ratio)
    }

    /// SP/LP ratio description
    var spLpDescription: String? {
        guard let ratio = avgSpLpRatio else { return nil }
        if ratio > 100 {
            return "selling over ask"
        } else if ratio < 99 {
            return "selling below ask"
        } else {
            return "selling at ask"
        }
    }

    /// Formatted average DOM
    var formattedAvgDom: String? {
        guard let dom = avgDom else { return nil }
        return "\(Int(dom)) days"
    }

    /// Formatted monthly velocity
    var formattedVelocity: String? {
        guard let velocity = monthlyVelocity else { return nil }
        return String(format: "%.1f sales/month", velocity)
    }
}

// MARK: - Manual Adjustments

/// Condition options for manual adjustments
enum CMACondition: String, CaseIterable, Identifiable {
    case newConstruction = "new_construction"
    case fullyRenovated = "fully_renovated"
    case someUpdates = "some_updates"
    case needsUpdating = "needs_updating"
    case distressed = "distressed"

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .newConstruction: return "New Construction"
        case .fullyRenovated: return "Fully Renovated"
        case .someUpdates: return "Some Updates"
        case .needsUpdating: return "Needs Updating"
        case .distressed: return "Distressed"
        }
    }

    /// Adjustment percentage for condition
    var adjustmentPercent: Double {
        switch self {
        case .newConstruction: return 0.20
        case .fullyRenovated: return 0.12
        case .someUpdates: return 0.0
        case .needsUpdating: return -0.12
        case .distressed: return -0.30
        }
    }
}

/// Manual adjustments for a comparable
struct CMAManualAdjustment {
    var condition: CMACondition = .someUpdates
    var hasPool: Bool = false
    var hasWaterfront: Bool = false

    /// Pool adjustment fixed amount
    static let poolAdjustment: Int = 50000

    /// Waterfront adjustment fixed amount
    static let waterfrontAdjustment: Int = 200000

    /// Calculate total manual adjustment for a given base price
    func calculateAdjustment(basePrice: Int) -> Int {
        var total = 0

        // Condition adjustment (percentage of base price)
        total += Int(Double(basePrice) * condition.adjustmentPercent)

        // Pool adjustment
        if hasPool {
            total += Self.poolAdjustment
        }

        // Waterfront adjustment
        if hasWaterfront {
            total += Self.waterfrontAdjustment
        }

        return total
    }

    /// Whether any manual adjustments have been made
    var hasAdjustments: Bool {
        condition != .someUpdates || hasPool || hasWaterfront
    }
}

// MARK: - AI Condition Analysis (v6.75.0)

/// Response from the /cma/analyze-condition endpoint
/// Uses Claude Vision API to analyze property photos and suggest condition rating
struct ConditionAnalysisResponse: Codable {
    let condition: String           // Maps to CMACondition raw value
    let conditionLabel: String
    let confidence: Int             // 0-100
    let reasoning: String
    let featuresDetected: [FeatureAssessment]?
    let cached: Bool

    private enum CodingKeys: String, CodingKey {
        case condition
        case conditionLabel = "condition_label"
        case confidence
        case reasoning
        case featuresDetected = "features_detected"
        case cached
    }

    /// Convert the AI condition string to CMACondition enum
    var cmaCondition: CMACondition? {
        CMACondition(rawValue: condition)
    }

    /// Confidence level description
    var confidenceLevel: String {
        switch confidence {
        case 90...: return "Very High"
        case 80..<90: return "High"
        case 70..<80: return "Moderate"
        case 60..<70: return "Low"
        default: return "Very Low"
        }
    }

    /// Color for confidence level
    var confidenceColor: Color {
        switch confidence {
        case 80...: return .green
        case 60..<80: return .orange
        default: return .red
        }
    }
}

/// Individual feature assessment from AI analysis
struct FeatureAssessment: Codable, Identifiable {
    let feature: String
    let assessment: String
    let details: String?

    var id: String { feature }

    /// Icon for the feature type
    var icon: String {
        switch feature.lowercased() {
        case "kitchen": return "fork.knife"
        case "bathrooms", "bathroom": return "shower"
        case "flooring": return "square.grid.3x3"
        case "overall finishes", "finishes": return "paintbrush"
        default: return "house"
        }
    }

    /// Color based on assessment quality
    var assessmentColor: Color {
        switch assessment.lowercased() {
        case "updated", "modern", "good", "new":
            return .green
        case "original", "average", "fair":
            return .orange
        case "dated", "poor", "damaged":
            return .red
        default:
            return .secondary
        }
    }
}
