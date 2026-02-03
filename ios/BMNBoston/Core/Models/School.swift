//
//  School.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  School data model for property school information
//

import Foundation
import CoreLocation

// MARK: - Schools for Property Response

/// Response from /bmn-schools/v1/property/schools endpoint
struct PropertySchoolsResponse: Decodable {
    let success: Bool
    let data: PropertySchoolsData
}

struct PropertySchoolsData: Decodable {
    let district: SchoolDistrict?
    let schools: SchoolsByLevel
    let location: SchoolsLocation
}

struct SchoolsByLevel: Decodable {
    let elementary: [NearbySchool]
    let middle: [NearbySchool]
    let high: [NearbySchool]

    /// All schools flattened
    var all: [NearbySchool] {
        elementary + middle + high
    }

    /// Total count across all levels
    var totalCount: Int {
        elementary.count + middle.count + high.count
    }
}

struct SchoolsLocation: Decodable {
    let latitude: Double
    let longitude: Double
    let radius: Double
}

// MARK: - School District

struct SchoolDistrict: Decodable, Identifiable {
    let id: Int
    let name: String
    let type: String?
    let ranking: DistrictRanking?
    let collegeOutcomes: CollegeOutcomes?
    let discipline: DistrictDiscipline?

    enum CodingKeys: String, CodingKey {
        case id, name, type, ranking, discipline
        case collegeOutcomes = "college_outcomes"
    }

    /// User-friendly district type label
    var typeLabel: String {
        switch type {
        case "local": return "Local District"
        case "regional": return "Regional District"
        case "charter": return "Charter District"
        default: return type?.capitalized ?? "School District"
        }
    }
}

/// District ranking data
struct DistrictRanking: Decodable {
    let compositeScore: Double?
    let percentileRank: Int?
    let stateRank: Int?
    let letterGrade: String?
    let schoolsCount: Int?
    let elementaryAvg: Double?
    let middleAvg: Double?
    let highAvg: Double?

    enum CodingKeys: String, CodingKey {
        case compositeScore = "composite_score"
        case percentileRank = "percentile_rank"
        case stateRank = "state_rank"
        case letterGrade = "letter_grade"
        case schoolsCount = "schools_count"
        case elementaryAvg = "elementary_avg"
        case middleAvg = "middle_avg"
        case highAvg = "high_avg"
    }
}

/// College enrollment outcomes for district graduates
struct CollegeOutcomes: Decodable {
    let year: Int
    let gradCount: Int
    let totalPostsecondaryPct: Double
    let fourYearPct: Double
    let twoYearPct: Double
    let outOfStatePct: Double
    let employedPct: Double

    enum CodingKeys: String, CodingKey {
        case year
        case gradCount = "grad_count"
        case totalPostsecondaryPct = "total_postsecondary_pct"
        case fourYearPct = "four_year_pct"
        case twoYearPct = "two_year_pct"
        case outOfStatePct = "out_of_state_pct"
        case employedPct = "employed_pct"
    }

    /// Formatted headline (e.g., "52% go to college")
    var headlineFormatted: String {
        "\(Int(totalPostsecondaryPct))% go to college"
    }

    /// Breakdown for display
    var breakdownItems: [(label: String, value: String)] {
        [
            ("4-Year College", "\(Int(fourYearPct))%"),
            ("2-Year College", "\(Int(twoYearPct))%"),
            ("Out of State", "\(Int(outOfStatePct))%"),
            ("Employed", "\(Int(employedPct))%")
        ]
    }
}

/// District-level discipline data from DESE SSDR reports
struct DistrictDiscipline: Decodable {
    let year: Int
    let enrollment: Int?
    let studentsDisciplined: Int?
    let inSchoolSuspensionPct: Double?
    let outOfSchoolSuspensionPct: Double?
    let expulsionPct: Double?
    let removedToAlternatePct: Double?
    let emergencyRemovalPct: Double?
    let schoolBasedArrestPct: Double?
    let lawEnforcementReferralPct: Double?
    let disciplineRate: Double?
    let percentile: Int?
    let percentileLabel: String?

    enum CodingKeys: String, CodingKey {
        case year, enrollment, percentile
        case studentsDisciplined = "students_disciplined"
        case inSchoolSuspensionPct = "in_school_suspension_pct"
        case outOfSchoolSuspensionPct = "out_of_school_suspension_pct"
        case expulsionPct = "expulsion_pct"
        case removedToAlternatePct = "removed_to_alternate_pct"
        case emergencyRemovalPct = "emergency_removal_pct"
        case schoolBasedArrestPct = "school_based_arrest_pct"
        case lawEnforcementReferralPct = "law_enforcement_referral_pct"
        case disciplineRate = "discipline_rate"
        case percentileLabel = "percentile_label"
    }

    /// Whether this district has a low discipline rate (below 3%)
    var isLowDiscipline: Bool {
        guard let rate = disciplineRate else { return false }
        return rate < 3.0
    }

    /// User-friendly summary of discipline data
    var summary: String {
        guard let rate = disciplineRate else { return "Discipline data available" }
        if rate < 2 {
            return "Very low discipline rate"
        } else if rate < 5 {
            return "Low discipline rate"
        } else if rate < 10 {
            return "Average discipline rate"
        } else {
            return "Above average incidents"
        }
    }

    /// Formatted discipline rate
    var rateFormatted: String? {
        guard let rate = disciplineRate else { return nil }
        return String(format: "%.1f%%", rate)
    }

    /// Formatted suspension percentage (combined ISS + OSS)
    var suspensionFormatted: String? {
        let iss = inSchoolSuspensionPct ?? 0
        let oss = outOfSchoolSuspensionPct ?? 0
        let total = iss + oss
        if total > 0 {
            return String(format: "%.1f%% suspensions", total)
        }
        return nil
    }

    /// State average discipline rate for comparison (approximate)
    static let stateAverageRate: Double = 5.5
}

// MARK: - School Sports Data (MIAA)

/// Sports participation data for a school (from MIAA)
struct SchoolSports: Decodable {
    let sportsCount: Int
    let totalParticipants: Int
    let boysParticipants: Int
    let girlsParticipants: Int
    let sports: [SchoolSport]

    enum CodingKeys: String, CodingKey {
        case sportsCount = "sports_count"
        case totalParticipants = "total_participants"
        case boysParticipants = "boys_participants"
        case girlsParticipants = "girls_participants"
        case sports
    }

    /// Whether this school has strong athletics (15+ sports)
    var isStrongAthletics: Bool {
        sportsCount >= 15
    }

    /// Summary text (e.g., "20 sports • 1,381 athletes")
    var summary: String {
        let athletesFormatted = NumberFormatter.localizedString(from: NSNumber(value: totalParticipants), number: .decimal)
        return "\(sportsCount) sports • \(athletesFormatted) athletes"
    }
}

/// Individual sport participation data
struct SchoolSport: Decodable, Identifiable {
    let sport: String
    let gender: String
    let participants: Int

    var id: String { "\(sport)-\(gender)" }

    /// Icon for the sport (SF Symbol name)
    var icon: String {
        switch sport.lowercased() {
        case "football": return "sportscourt.fill"
        case "basketball": return "sportscourt.fill"
        case "soccer": return "soccerball"
        case "baseball", "softball": return "figure.baseball"
        case "volleyball": return "sportscourt.fill"
        case "tennis": return "sportscourt.fill"
        case "golf": return "figure.golf"
        case "swimming", "fall swim/dive", "winter swim/dive": return "figure.pool.swim"
        case "ice hockey": return "hockey.puck.fill"
        case "lacrosse": return "figure.lacrosse"
        case "track-indoor", "track-outdoor", "cross country": return "figure.run"
        case "wrestling": return "figure.wrestling"
        case "gymnastics": return "figure.gymnastics"
        case "skiing", "ski-alpine", "ski-nordic": return "figure.skiing.downhill"
        case "rugby": return "figure.rugby"
        case "field hockey": return "hockey.puck.fill"
        default: return "sportscourt.fill"
        }
    }
}

// MARK: - Nearby School (for property detail)

struct NearbySchool: Decodable, Identifiable {
    let id: Int
    let name: String
    let grades: String?
    let distance: Double
    let address: String?
    let city: String?  // City where school is located (shown for regional schools)
    let mcasProficientPct: Double?
    let latitude: Double
    let longitude: Double
    let ranking: NearbySchoolRanking?
    let demographics: NearbySchoolDemographics?
    let highlights: [SchoolHighlight]?
    let discipline: SchoolDiscipline?  // Phase 2.1: School safety/discipline data
    let sports: SchoolSports?  // MIAA sports participation data (high schools only)
    let isRegional: Bool?  // True if this is a regional/shared school from another city
    let regionalNote: String?  // e.g., "Students from Nahant attend this school"

    enum CodingKeys: String, CodingKey {
        case id, name, grades, distance, address, city, latitude, longitude, ranking, demographics, highlights, discipline, sports
        case mcasProficientPct = "mcas_proficient_pct"
        case isRegional = "is_regional"
        case regionalNote = "regional_note"
    }

    /// Formatted distance string
    var distanceFormatted: String {
        if distance < 0.1 {
            return "< 0.1 mi"
        }
        return String(format: "%.1f mi", distance)
    }

    /// MCAS score formatted as percentage
    var mcasFormatted: String? {
        guard let pct = mcasProficientPct else { return nil }
        return "\(Int(pct))% Proficient"
    }

    /// Letter grade from ranking (if available)
    var letterGrade: String? {
        ranking?.letterGrade
    }

    /// Composite score from ranking (if available)
    var compositeScore: Double? {
        ranking?.compositeScore
    }

    /// State rank from ranking (if available)
    var stateRank: Int? {
        ranking?.stateRank
    }

    /// Category total from ranking (if available)
    var categoryTotal: Int? {
        ranking?.categoryTotal
    }

    /// Formatted state rank (e.g., "#1 of 257")
    var stateRankFormatted: String? {
        guard let rank = stateRank else { return nil }
        if let total = categoryTotal {
            return "#\(rank) of \(total)"
        }
        return "#\(rank)"
    }

    /// Category label from ranking (if available)
    var categoryLabel: String? {
        ranking?.categoryLabel
    }

    /// Trend data from ranking (if available)
    var trend: RankingTrend? {
        ranking?.trend
    }

    /// Percentile context (e.g., "Top 25%") if ranking available
    var percentileContext: String? {
        ranking?.percentileContext
    }

    /// Combined grade with percentile context (e.g., "A- (Top 25%)") if ranking available
    var gradeWithContext: String? {
        ranking?.gradeWithContext
    }

    /// Data completeness info (if ranking available)
    var dataCompleteness: DataCompleteness? {
        ranking?.dataCompleteness
    }

    /// Benchmarks comparison (if ranking available)
    var benchmarks: RankingBenchmarks? {
        ranking?.benchmarks
    }

    /// Grade color for badge display
    var gradeColor: String {
        guard let grade = letterGrade?.prefix(1) else { return "gray" }
        switch grade {
        case "A": return "green"
        case "B": return "blue"
        case "C": return "yellow"
        case "D": return "orange"
        default: return "red"
        }
    }

    /// Coordinate for map display
    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: latitude, longitude: longitude)
    }
}

/// Demographics data for nearby schools (simplified version)
struct NearbySchoolDemographics: Decodable {
    let totalStudents: Int?
    let pctFreeReducedLunch: Double?
    let avgClassSize: Double?
    let diversity: String?

    enum CodingKeys: String, CodingKey {
        case totalStudents = "total_students"
        case pctFreeReducedLunch = "pct_free_reduced_lunch"
        case avgClassSize = "avg_class_size"
        case diversity
    }

    /// Formatted student count
    var studentsFormatted: String? {
        guard let count = totalStudents else { return nil }
        return "\(count) students"
    }

    /// Formatted free/reduced lunch percentage
    var freeLunchFormatted: String? {
        guard let pct = pctFreeReducedLunch else { return nil }
        return "\(Int(pct))% free lunch"
    }

    /// Formatted class size
    var classSizeFormatted: String? {
        guard let size = avgClassSize else { return nil }
        return "\(Int(size)) avg class"
    }
}

/// School discipline data from DESE SSDR reports (Phase 2.1)
struct SchoolDiscipline: Decodable {
    let year: Int
    let enrollment: Int?
    let studentsDisciplined: Int?
    let inSchoolSuspensionPct: Double?
    let outOfSchoolSuspensionPct: Double?
    let expulsionPct: Double?
    let disciplineRate: Double?  // Combined OSS + Expulsion + Emergency rate
    let isLowDiscipline: Bool  // True if in bottom 25% of discipline incidents

    enum CodingKeys: String, CodingKey {
        case year, enrollment
        case studentsDisciplined = "students_disciplined"
        case inSchoolSuspensionPct = "in_school_suspension_pct"
        case outOfSchoolSuspensionPct = "out_of_school_suspension_pct"
        case expulsionPct = "expulsion_pct"
        case disciplineRate = "discipline_rate"
        case isLowDiscipline = "is_low_discipline"
    }

    /// User-friendly summary of discipline data
    var summary: String {
        if isLowDiscipline {
            return "Low discipline rate"
        }
        if let rate = disciplineRate {
            if rate < 5 {
                return "Below average incidents"
            } else if rate < 10 {
                return "Average discipline rate"
            } else {
                return "Above average incidents"
            }
        }
        return "Discipline data available"
    }

    /// Formatted discipline rate
    var rateFormatted: String? {
        guard let rate = disciplineRate else { return nil }
        return "\(String(format: "%.1f", rate))% incident rate"
    }

    /// Formatted suspension percentage (combined ISS + OSS)
    var suspensionFormatted: String? {
        let iss = inSchoolSuspensionPct ?? 0
        let oss = outOfSchoolSuspensionPct ?? 0
        let total = iss + oss
        if total > 0 {
            return "\(String(format: "%.1f", total))% suspensions"
        }
        return nil
    }
}

/// Data completeness information for a school's ranking
struct DataCompleteness: Decodable {
    let componentsAvailable: Int
    let componentsTotal: Int
    let confidenceLevel: String  // "limited", "good", or "comprehensive"
    let components: [String]
    let limitedDataNote: String?  // Phase 5: Warning for elementary schools with limited data

    enum CodingKeys: String, CodingKey {
        case componentsAvailable = "components_available"
        case componentsTotal = "components_total"
        case confidenceLevel = "confidence_level"
        case components
        case limitedDataNote = "limited_data_note"
    }

    /// User-friendly label for confidence level
    var confidenceLabel: String {
        switch confidenceLevel {
        case "comprehensive": return "Comprehensive (\(componentsAvailable)/\(componentsTotal))"
        case "good": return "Good (\(componentsAvailable)/\(componentsTotal))"
        default: return "Limited (\(componentsAvailable)/\(componentsTotal))"
        }
    }

    /// Short label for compact display
    var shortLabel: String {
        "\(componentsAvailable)/\(componentsTotal) factors"
    }
}

/// Trend data comparing current year to previous year
struct RankingTrend: Decodable {
    let direction: String       // "up", "down", or "stable"
    let rankChange: Int         // Positive = improved (moved up in rank)
    let scoreChange: Double?    // Change in composite score (optional for regional schools)
    let percentileChange: Int?  // Change in percentile (optional for regional schools)
    let previousYear: Int?      // Optional for regional schools
    let previousRank: Int?      // Optional for regional schools
    let previousScore: Double?  // Optional for regional schools

    enum CodingKeys: String, CodingKey {
        case direction
        case rankChange = "rank_change"
        case scoreChange = "score_change"
        case percentileChange = "percentile_change"
        case previousYear = "previous_year"
        case previousRank = "previous_rank"
        case previousScore = "previous_score"
    }

    /// SF Symbol name for trend direction
    var trendIcon: String {
        switch direction {
        case "up": return "arrow.up.circle.fill"
        case "down": return "arrow.down.circle.fill"
        default: return "minus.circle.fill"
        }
    }

    /// Color for trend indicator
    var trendColor: String {
        switch direction {
        case "up": return "green"
        case "down": return "red"
        default: return "gray"
        }
    }

    /// Formatted rank change (e.g., "+5" or "-3")
    var rankChangeFormatted: String {
        if rankChange > 0 {
            return "+\(rankChange)"
        } else if rankChange < 0 {
            return "\(rankChange)"
        }
        return "0"
    }

    /// Clear text description of rank change (e.g., "Improved 5 spots" or "Dropped 3 spots")
    /// Note: rankChange > 0 means rank number decreased (improved), rankChange < 0 means worsened
    var rankChangeText: String {
        let spots = abs(rankChange)
        let spotsWord = spots == 1 ? "spot" : "spots"
        if rankChange > 0 {
            return "Improved \(spots) \(spotsWord)"
        } else if rankChange < 0 {
            return "Dropped \(spots) \(spotsWord)"
        }
        return "No change"
    }
}

/// Benchmark comparison data
struct RankingBenchmarks: Decodable {
    let categoryAverage: Double?
    let stateAverage: Double?
    let vsCategory: String?
    let vsState: String?

    enum CodingKeys: String, CodingKey {
        case categoryAverage = "category_average"
        case stateAverage = "state_average"
        case vsCategory = "vs_category"
        case vsState = "vs_state"
    }

    /// Short comparison text (e.g., "+12.2 above avg")
    var shortComparison: String? {
        vsState ?? vsCategory
    }
}

/// School highlight for display as a chip/badge
struct SchoolHighlight: Decodable, Identifiable {
    let type: String           // "ap", "ratio", "diversity", "cte", "early_college", etc.
    let text: String           // "Strong AP Program", "Small Class Sizes", etc.
    let detail: String?        // "85% pass rate", "12:1 ratio", etc.
    let icon: String           // SF Symbol name
    let priority: Int          // Lower = higher priority

    var id: String { type }

    /// Combined display text with optional detail
    var displayText: String {
        if let detail = detail {
            return "\(text) (\(detail))"
        }
        return text
    }

    /// Short display text for chips
    var shortText: String {
        text
    }
}

/// Simplified ranking data included in nearby school responses
struct NearbySchoolRanking: Decodable {
    let category: String?
    let categoryLabel: String?
    let compositeScore: Double
    let percentileRank: Int
    let stateRank: Int?
    let categoryTotal: Int?
    let letterGrade: String
    let trend: RankingTrend?
    let dataCompleteness: DataCompleteness?
    let benchmarks: RankingBenchmarks?

    enum CodingKeys: String, CodingKey {
        case category
        case categoryLabel = "category_label"
        case compositeScore = "composite_score"
        case percentileRank = "percentile_rank"
        case stateRank = "state_rank"
        case categoryTotal = "category_total"
        case trend
        case letterGrade = "letter_grade"
        case dataCompleteness = "data_completeness"
        case benchmarks
    }

    /// Percentile context for display (e.g., "Top 25%")
    var percentileContext: String {
        let topPercent = 100 - percentileRank
        if topPercent <= 1 {
            return "Top 1%"
        } else if topPercent <= 5 {
            return "Top 5%"
        } else if topPercent <= 10 {
            return "Top 10%"
        } else if topPercent <= 25 {
            return "Top 25%"
        } else if topPercent <= 50 {
            return "Top 50%"
        } else {
            return "Bottom \(100 - topPercent)%"
        }
    }

    /// Combined grade with percentile context (e.g., "A- (Top 25%)")
    var gradeWithContext: String {
        "\(letterGrade) (\(percentileContext))"
    }
}

// MARK: - School (full detail)

struct School: Decodable, Identifiable {
    let id: Int
    let name: String
    let type: String?
    let level: String?
    let grades: String?
    let address: String?
    let city: String?
    let state: String?
    let zip: String?
    let latitude: Double?
    let longitude: Double?
    let ncesId: String?
    let stateId: String?
    let districtId: Int?
    let phone: String?
    let website: String?
    let enrollment: Int?
    let studentTeacherRatio: Double?

    enum CodingKeys: String, CodingKey {
        case id, name, type, level, grades, address, city, state, zip
        case latitude, longitude, phone, website, enrollment
        case ncesId = "nces_id"
        case stateId = "state_id"
        case districtId = "district_id"
        case studentTeacherRatio = "student_teacher_ratio"
    }

    /// Full address formatted
    var fullAddress: String {
        var parts: [String] = []
        if let address = address { parts.append(address) }
        if let city = city { parts.append(city) }
        if let state = state, let zip = zip {
            parts.append("\(state) \(zip)")
        }
        return parts.joined(separator: ", ")
    }

    /// Coordinate for map display
    var coordinate: CLLocationCoordinate2D? {
        guard let lat = latitude, let lng = longitude else { return nil }
        return CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    /// Level icon name
    var levelIcon: String {
        switch level {
        case "elementary": return "figure.and.child.holdinghands"
        case "middle": return "person.2.fill"
        case "high": return "graduationcap.fill"
        default: return "building.2.fill"
        }
    }

    /// Level color
    var levelColorName: String {
        switch level {
        case "elementary": return "green"
        case "middle": return "blue"
        case "high": return "purple"
        default: return "orange"
        }
    }
}

// MARK: - School Detail Response

struct SchoolDetailResponse: Decodable {
    let success: Bool
    let data: SchoolDetail
}

struct SchoolDetail: Decodable, Identifiable {
    let id: Int
    let name: String
    let type: String?
    let level: String?
    let grades: String?
    let address: String?
    let city: String?
    let state: String?
    let zip: String?
    let latitude: Double?
    let longitude: Double?
    let ncesId: String?
    let stateId: String?
    let districtId: Int?
    let phone: String?
    let website: String?
    let enrollment: Int?
    let studentTeacherRatio: Double?
    let testScores: [TestScore]?
    let ranking: SchoolRanking?
    let demographics: SchoolDemographics?

    enum CodingKeys: String, CodingKey {
        case id, name, type, level, grades, address, city, state, zip
        case latitude, longitude, phone, website, enrollment
        case ncesId = "nces_id"
        case stateId = "state_id"
        case districtId = "district_id"
        case studentTeacherRatio = "student_teacher_ratio"
        case testScores = "test_scores"
        case ranking
        case demographics
    }
}

// MARK: - Test Scores

struct TestScore: Decodable, Identifiable {
    let year: Int
    let subject: String
    let grade: String?
    let proficientOrAbovePct: Double?
    let avgScaledScore: Double?

    var id: String { "\(year)-\(subject)-\(grade ?? "")" }

    enum CodingKeys: String, CodingKey {
        case year, subject, grade
        case proficientOrAbovePct = "proficient_or_above_pct"
        case avgScaledScore = "avg_scaled_score"
    }
}

// MARK: - Rankings

/// Composite school ranking based on multiple factors (MCAS, graduation, attendance, etc.)
struct SchoolRanking: Decodable {
    let year: Int
    let category: String?
    let categoryLabel: String?
    let compositeScore: Double
    let percentileRank: Int
    let stateRank: Int?
    let categoryTotal: Int?
    let letterGrade: String
    let trend: RankingTrend?
    let components: RankingComponents
    let calculatedAt: String?

    enum CodingKeys: String, CodingKey {
        case year
        case category
        case categoryLabel = "category_label"
        case compositeScore = "composite_score"
        case percentileRank = "percentile_rank"
        case stateRank = "state_rank"
        case categoryTotal = "category_total"
        case letterGrade = "letter_grade"
        case trend
        case components
        case calculatedAt = "calculated_at"
    }

    /// Color for the letter grade badge
    var gradeColor: String {
        switch letterGrade.prefix(1) {
        case "A": return "green"
        case "B": return "blue"
        case "C": return "yellow"
        case "D": return "orange"
        default: return "red"
        }
    }

    /// Formatted percentile (e.g., "Top 10%")
    var percentileFormatted: String {
        if percentileRank >= 90 {
            return "Top \(100 - percentileRank + 1)%"
        } else if percentileRank >= 50 {
            return "Top \(100 - percentileRank)%"
        } else {
            return "Bottom \(100 - percentileRank)%"
        }
    }

    /// Formatted state rank (e.g., "#1 of 257")
    var stateRankFormatted: String? {
        guard let rank = stateRank else { return nil }
        if let total = categoryTotal {
            return "#\(rank) of \(total)"
        }
        return "#\(rank)"
    }
}

/// Individual component scores that make up the composite ranking
struct RankingComponents: Decodable {
    let mcas: Double?
    let graduation: Double?
    let masscore: Double?
    let attendance: Double?
    let ap: Double?
    let growth: Double?
    let spending: Double?
    let ratio: Double?
}

// MARK: - Demographics

struct SchoolDemographics: Decodable {
    let totalStudents: Int?
    let pctFreeReducedLunch: Double?
    let avgClassSize: Double?

    enum CodingKeys: String, CodingKey {
        case totalStudents = "total_students"
        case pctFreeReducedLunch = "pct_free_reduced_lunch"
        case avgClassSize = "avg_class_size"
    }
}

// MARK: - Map Schools Response

struct MapSchoolsResponse: Decodable {
    let success: Bool
    let data: MapSchoolsData
}

struct MapSchoolsData: Decodable {
    let schools: [MapSchool]
    let count: Int
    let bounds: MapSchoolBounds
}

struct MapSchool: Decodable, Identifiable {
    let id: Int
    let name: String
    let level: String?
    let type: String?
    let lat: Double
    let lng: Double

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }
}

struct MapSchoolBounds: Decodable {
    let south: Double
    let west: Double
    let north: Double
    let east: Double
}

// MARK: - Glossary

/// A glossary term with description and parent tips
struct GlossaryTerm: Decodable, Identifiable {
    let term: String
    let fullName: String
    let category: String
    let description: String
    let parentTip: String

    var id: String { term }

    enum CodingKeys: String, CodingKey {
        case term
        case fullName = "full_name"
        case category
        case description
        case parentTip = "parent_tip"
    }
}

/// Full glossary response with all terms grouped by category
struct GlossaryResponse: Decodable {
    let terms: [String: GlossaryTerm]
    let categories: [String: String]
    let count: Int

    enum CodingKeys: String, CodingKey {
        case terms, categories, count
    }
}

