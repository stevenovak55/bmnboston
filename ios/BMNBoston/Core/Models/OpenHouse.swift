//
//  OpenHouse.swift
//  BMNBoston
//
//  Open House Sign-In System Models
//  Created for BMN Boston Real Estate
//
//  VERSION: v6.71.0
//

import Foundation
import CoreLocation

// MARK: - Open House Model

struct OpenHouse: Identifiable, Codable, Equatable {
    let id: Int
    let agentId: Int
    let listingId: String?                  // MLS number if linked to MLS property
    let propertyAddress: String
    let propertyCity: String
    let propertyState: String
    let propertyZip: String
    let propertyType: String?
    let beds: Int?
    let baths: Double?
    let listPrice: Int?
    let photoUrl: String?
    let latitude: Double?
    let longitude: Double?
    let eventDate: String                   // "2026-01-25"
    let startTime: String                   // "14:00"
    let endTime: String                     // "16:00"
    let status: OpenHouseStatus
    let attendeeCount: Int
    let notes: String?
    let createdAt: String?
    let updatedAt: String?

    private enum CodingKeys: String, CodingKey {
        case id
        case agentId = "agent_id"
        case listingId = "listing_id"
        case propertyAddress = "property_address"
        case propertyCity = "property_city"
        case propertyState = "property_state"
        case propertyZip = "property_zip"
        case propertyType = "property_type"
        case beds
        case baths
        case listPrice = "list_price"
        case photoUrl = "photo_url"
        case latitude
        case longitude
        case eventDate = "date"
        case startTime = "start_time"
        case endTime = "end_time"
        case status
        case attendeeCount = "attendee_count"
        case notes
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }

    /// Server timezone - all times are stored in Eastern time
    private static let serverTimezone: TimeZone = {
        if let tz = TimeZone(identifier: "America/New_York") {
            return tz
        }
        // Fallback to current timezone rather than hardcoded EST offset
        // This handles EDT correctly and is a better fallback for edge cases
        return .current
    }()

    var formattedDate: String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"
        inputFormatter.timeZone = Self.serverTimezone

        let outputFormatter = DateFormatter()
        outputFormatter.dateStyle = .long
        outputFormatter.timeZone = .current

        if let date = inputFormatter.date(from: eventDate) {
            return outputFormatter.string(from: date)
        }
        return eventDate
    }

    var formattedTime: String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "HH:mm"
        inputFormatter.timeZone = Self.serverTimezone

        let outputFormatter = DateFormatter()
        outputFormatter.timeStyle = .short
        outputFormatter.timeZone = .current

        var result = startTime
        if let startDate = inputFormatter.date(from: startTime) {
            result = outputFormatter.string(from: startDate)
        }

        if let endDate = inputFormatter.date(from: endTime) {
            result += " - \(outputFormatter.string(from: endDate))"
        }

        return result
    }

    var dateTime: Date? {
        let formatter = DateFormatter()
        formatter.timeZone = Self.serverTimezone
        formatter.dateFormat = "yyyy-MM-dd HH:mm"
        return formatter.date(from: "\(eventDate) \(startTime)")
    }

    var isToday: Bool {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        formatter.timeZone = Self.serverTimezone
        return eventDate == formatter.string(from: Date())
    }

    var isPast: Bool {
        guard let dateTime = dateTime else { return false }
        return dateTime < Date()
    }

    var isUpcoming: Bool {
        guard let dateTime = dateTime else { return true }
        return dateTime >= Date()
    }

    var coordinate: CLLocationCoordinate2D? {
        guard let lat = latitude, let lng = longitude else { return nil }
        return CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    var fullAddress: String {
        return "\(propertyAddress), \(propertyCity), \(propertyState) \(propertyZip)"
    }

    var formattedPrice: String? {
        guard let price = listPrice else { return nil }
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: price))
    }
}

// MARK: - Open House Status

enum OpenHouseStatus: String, Codable, CaseIterable {
    case scheduled = "scheduled"
    case active = "active"
    case completed = "completed"
    case cancelled = "cancelled"

    var displayName: String {
        switch self {
        case .scheduled: return "Scheduled"
        case .active: return "In Progress"
        case .completed: return "Completed"
        case .cancelled: return "Cancelled"
        }
    }

    var color: String {
        switch self {
        case .scheduled: return "blue"
        case .active: return "green"
        case .completed: return "gray"
        case .cancelled: return "red"
        }
    }
}

// MARK: - Open House Attendee

struct OpenHouseAttendee: Identifiable, Codable, Equatable {
    let id: Int?                            // Server ID (nil for offline)
    let localUUID: UUID                     // For offline tracking
    let openHouseId: Int                    // May be 0 when decoded from API detail response

    // Contact Info (Required)
    var firstName: String
    var lastName: String
    var email: String
    var phone: String

    // Agent Visitor Detection (v6.70.0)
    var isAgent: Bool                       // Is this visitor a real estate agent?

    // Agent Visitor Fields (v6.70.0) - only used if isAgent == true
    var visitorAgentBrokerage: String?      // Their company/brokerage
    var agentVisitPurpose: AgentVisitPurpose? // Why they're visiting
    var agentHasBuyer: Bool?                // Do they have a buyer interested?
    var agentBuyerTimeline: String?         // When might the buyer offer?
    var agentNetworkInterest: Bool?         // Open to networking/referrals?

    // Buyer Path Fields (only used if isAgent == false)
    var workingWithAgent: WorkingWithAgentStatus
    var otherAgentName: String?
    var otherAgentBrokerage: String?
    var otherAgentPhone: String?        // v6.71.0: Enhanced agent contact
    var otherAgentEmail: String?        // v6.71.0: Enhanced agent contact

    // Buying Intent (buyer path only)
    var buyingTimeline: BuyingTimeline
    var preApproved: PreApprovalStatus
    var lenderName: String?

    // Marketing Attribution
    var howHeardAbout: HowHeardSource?

    // Consent (GDPR/Privacy)
    var consentToFollowUp: Bool
    var consentToEmail: Bool
    var consentToText: Bool

    // Agent Assessment
    var interestLevel: InterestLevel
    var agentNotes: String?

    // CRM Integration (v6.70.0)
    var userId: Int?                        // FK to wp_users when converted to client
    var priorityScore: Int                  // Calculated lead priority (0-100)

    // Auto-Processing Flags (v6.71.0)
    var autoCrmProcessed: Bool              // Whether auto-CRM processing occurred
    var autoSearchCreated: Bool             // Whether auto-saved search was created
    var autoSearchId: Int?                  // FK to wp_mld_saved_searches if created

    // Massachusetts Disclosure (v6.71.0)
    var maDisclosureAcknowledged: Bool      // Whether MA disclosure was acknowledged
    var maDisclosureTimestamp: Date?        // When disclosure was acknowledged

    // Timestamps
    let signedInAt: Date
    var syncStatus: SyncStatus

    // Transient property (not encoded) - v6.72.0
    // Used to pass device token to API for notification exclusion
    var deviceTokenForExclusion: String?

    private enum CodingKeys: String, CodingKey {
        case id
        case localUUID = "local_uuid"
        case openHouseId = "open_house_id"
        case firstName = "first_name"
        case lastName = "last_name"
        case email
        case phone
        // Agent visitor fields (v6.70.0)
        case isAgent = "is_agent"
        case visitorAgentBrokerage = "visitor_agent_brokerage"
        case agentVisitPurpose = "agent_visit_purpose"
        case agentHasBuyer = "agent_has_buyer"
        case agentBuyerTimeline = "agent_buyer_timeline"
        case agentNetworkInterest = "agent_network_interest"
        // Buyer path fields
        case workingWithAgent = "working_with_agent"
        case otherAgentName = "agent_name"
        case otherAgentBrokerage = "agent_brokerage"
        case otherAgentPhone = "agent_phone"         // v6.71.0
        case otherAgentEmail = "agent_email"         // v6.71.0
        case buyingTimeline = "buying_timeline"
        case preApproved = "pre_approved"
        case lenderName = "lender_name"
        case howHeardAbout = "how_heard_about"
        case consentToFollowUp = "consent_to_follow_up"
        case consentToEmail = "consent_to_email"
        case consentToText = "consent_to_text"
        case interestLevel = "interest_level"
        case agentNotes = "notes"
        // CRM fields (v6.70.0)
        case userId = "user_id"
        case priorityScore = "priority_score"
        // Auto-processing flags (v6.71.0)
        case autoCrmProcessed = "auto_crm_processed"
        case autoSearchCreated = "auto_search_created"
        case autoSearchId = "auto_search_id"
        // MA Disclosure (v6.71.0)
        case maDisclosureAcknowledged = "ma_disclosure_acknowledged"
        case maDisclosureTimestamp = "ma_disclosure_timestamp"
        case signedInAt = "signed_in_at"
        case syncStatus = "sync_status"
    }

    /// Custom decoder to handle API responses that may not include all fields
    /// API returns attendees without open_house_id or sync_status fields
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Basic fields
        id = try container.decodeIfPresent(Int.self, forKey: .id)

        // UUID - handle String or UUID
        if let uuidString = try? container.decode(String.self, forKey: .localUUID),
           let uuid = UUID(uuidString: uuidString) {
            localUUID = uuid
        } else if let uuid = try? container.decode(UUID.self, forKey: .localUUID) {
            localUUID = uuid
        } else {
            // Generate a UUID if not provided (for backwards compatibility)
            localUUID = UUID()
        }

        // openHouseId - may not be in the response, default to 0
        openHouseId = (try? container.decode(Int.self, forKey: .openHouseId)) ?? 0

        // Contact info
        firstName = try container.decode(String.self, forKey: .firstName)
        lastName = try container.decode(String.self, forKey: .lastName)
        email = try container.decode(String.self, forKey: .email)
        phone = (try? container.decode(String.self, forKey: .phone)) ?? ""

        // Agent visitor fields
        isAgent = (try? container.decode(Bool.self, forKey: .isAgent)) ?? false
        visitorAgentBrokerage = try? container.decode(String.self, forKey: .visitorAgentBrokerage)
        agentVisitPurpose = try? container.decode(AgentVisitPurpose.self, forKey: .agentVisitPurpose)
        agentHasBuyer = try? container.decode(Bool.self, forKey: .agentHasBuyer)
        agentBuyerTimeline = try? container.decode(String.self, forKey: .agentBuyerTimeline)
        agentNetworkInterest = try? container.decode(Bool.self, forKey: .agentNetworkInterest)

        // Buyer path fields - use defaults if not present
        workingWithAgent = (try? container.decode(WorkingWithAgentStatus.self, forKey: .workingWithAgent)) ?? .no
        otherAgentName = try? container.decode(String.self, forKey: .otherAgentName)
        otherAgentBrokerage = try? container.decode(String.self, forKey: .otherAgentBrokerage)
        otherAgentPhone = try? container.decode(String.self, forKey: .otherAgentPhone)
        otherAgentEmail = try? container.decode(String.self, forKey: .otherAgentEmail)
        buyingTimeline = (try? container.decode(BuyingTimeline.self, forKey: .buyingTimeline)) ?? .justBrowsing
        preApproved = (try? container.decode(PreApprovalStatus.self, forKey: .preApproved)) ?? .notSure
        lenderName = try? container.decode(String.self, forKey: .lenderName)

        // Marketing
        howHeardAbout = try? container.decode(HowHeardSource.self, forKey: .howHeardAbout)

        // Consent - default to true for backwards compatibility
        consentToFollowUp = (try? container.decode(Bool.self, forKey: .consentToFollowUp)) ?? true
        consentToEmail = (try? container.decode(Bool.self, forKey: .consentToEmail)) ?? true
        consentToText = (try? container.decode(Bool.self, forKey: .consentToText)) ?? false

        // Assessment
        interestLevel = (try? container.decode(InterestLevel.self, forKey: .interestLevel)) ?? .unknown
        agentNotes = try? container.decode(String.self, forKey: .agentNotes)

        // CRM fields
        userId = try? container.decode(Int.self, forKey: .userId)
        priorityScore = (try? container.decode(Int.self, forKey: .priorityScore)) ?? 0

        // Auto-processing flags (v6.71.0)
        autoCrmProcessed = (try? container.decode(Bool.self, forKey: .autoCrmProcessed)) ?? false
        autoSearchCreated = (try? container.decode(Bool.self, forKey: .autoSearchCreated)) ?? false
        autoSearchId = try? container.decode(Int.self, forKey: .autoSearchId)

        // MA Disclosure (v6.71.0)
        maDisclosureAcknowledged = (try? container.decode(Bool.self, forKey: .maDisclosureAcknowledged)) ?? false
        if let disclosureDateString = try? container.decode(String.self, forKey: .maDisclosureTimestamp) {
            let formatter = DateFormatter()
            formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
            formatter.timeZone = TimeZone(identifier: "America/New_York")
            maDisclosureTimestamp = formatter.date(from: disclosureDateString)
        } else {
            maDisclosureTimestamp = nil
        }

        // signedInAt - handle date string format "yyyy-MM-dd HH:mm:ss"
        if let dateString = try? container.decode(String.self, forKey: .signedInAt) {
            let formatter = DateFormatter()
            formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
            formatter.timeZone = TimeZone(identifier: "America/New_York")
            signedInAt = formatter.date(from: dateString) ?? Date()
        } else if let date = try? container.decode(Date.self, forKey: .signedInAt) {
            signedInAt = date
        } else {
            signedInAt = Date()
        }

        // syncStatus - not in API response, default to .synced for server data
        syncStatus = (try? container.decode(SyncStatus.self, forKey: .syncStatus)) ?? .synced

        // deviceTokenForExclusion - transient, not in API response
        deviceTokenForExclusion = nil
    }

    /// Memberwise initializer for creating local attendees
    init(
        id: Int?,
        localUUID: UUID,
        openHouseId: Int,
        firstName: String,
        lastName: String,
        email: String,
        phone: String,
        isAgent: Bool,
        visitorAgentBrokerage: String?,
        agentVisitPurpose: AgentVisitPurpose?,
        agentHasBuyer: Bool?,
        agentBuyerTimeline: String?,
        agentNetworkInterest: Bool?,
        workingWithAgent: WorkingWithAgentStatus,
        otherAgentName: String?,
        otherAgentBrokerage: String?,
        otherAgentPhone: String?,           // v6.71.0
        otherAgentEmail: String?,           // v6.71.0
        buyingTimeline: BuyingTimeline,
        preApproved: PreApprovalStatus,
        lenderName: String?,
        howHeardAbout: HowHeardSource?,
        consentToFollowUp: Bool,
        consentToEmail: Bool,
        consentToText: Bool,
        interestLevel: InterestLevel,
        agentNotes: String?,
        userId: Int?,
        priorityScore: Int,
        autoCrmProcessed: Bool = false,     // v6.71.0
        autoSearchCreated: Bool = false,    // v6.71.0
        autoSearchId: Int? = nil,           // v6.71.0
        maDisclosureAcknowledged: Bool = false,  // v6.71.0
        maDisclosureTimestamp: Date? = nil,      // v6.71.0
        signedInAt: Date,
        syncStatus: SyncStatus,
        deviceTokenForExclusion: String? = nil   // v6.72.0
    ) {
        self.id = id
        self.localUUID = localUUID
        self.openHouseId = openHouseId
        self.firstName = firstName
        self.lastName = lastName
        self.email = email
        self.phone = phone
        self.isAgent = isAgent
        self.visitorAgentBrokerage = visitorAgentBrokerage
        self.agentVisitPurpose = agentVisitPurpose
        self.agentHasBuyer = agentHasBuyer
        self.agentBuyerTimeline = agentBuyerTimeline
        self.agentNetworkInterest = agentNetworkInterest
        self.workingWithAgent = workingWithAgent
        self.otherAgentName = otherAgentName
        self.otherAgentBrokerage = otherAgentBrokerage
        self.otherAgentPhone = otherAgentPhone
        self.otherAgentEmail = otherAgentEmail
        self.buyingTimeline = buyingTimeline
        self.preApproved = preApproved
        self.lenderName = lenderName
        self.howHeardAbout = howHeardAbout
        self.consentToFollowUp = consentToFollowUp
        self.consentToEmail = consentToEmail
        self.consentToText = consentToText
        self.interestLevel = interestLevel
        self.agentNotes = agentNotes
        self.userId = userId
        self.priorityScore = priorityScore
        self.autoCrmProcessed = autoCrmProcessed
        self.autoSearchCreated = autoSearchCreated
        self.autoSearchId = autoSearchId
        self.maDisclosureAcknowledged = maDisclosureAcknowledged
        self.maDisclosureTimestamp = maDisclosureTimestamp
        self.signedInAt = signedInAt
        self.syncStatus = syncStatus
        self.deviceTokenForExclusion = deviceTokenForExclusion
    }

    var fullName: String {
        return "\(firstName) \(lastName)"
    }

    /// Priority tier based on score (v6.70.0)
    var priorityTier: PriorityTier {
        if priorityScore >= 80 { return .hot }
        if priorityScore >= 50 { return .warm }
        return .cool
    }

    /// Whether this attendee has been converted to a CRM client (v6.70.0)
    var isConvertedToClient: Bool {
        return userId != nil
    }

    /// Create a new local attendee for kiosk sign-in (buyer path default)
    static func createLocal(openHouseId: Int) -> OpenHouseAttendee {
        return OpenHouseAttendee(
            id: nil,
            localUUID: UUID(),
            openHouseId: openHouseId,
            firstName: "",
            lastName: "",
            email: "",
            phone: "",
            isAgent: false,
            visitorAgentBrokerage: nil,
            agentVisitPurpose: nil,
            agentHasBuyer: nil,
            agentBuyerTimeline: nil,
            agentNetworkInterest: nil,
            workingWithAgent: .no,
            otherAgentName: nil,
            otherAgentBrokerage: nil,
            otherAgentPhone: nil,
            otherAgentEmail: nil,
            buyingTimeline: .justBrowsing,
            preApproved: .notSure,
            lenderName: nil,
            howHeardAbout: nil,
            consentToFollowUp: true,
            consentToEmail: true,
            consentToText: false,
            interestLevel: .unknown,
            agentNotes: nil,
            userId: nil,
            priorityScore: 0,
            autoCrmProcessed: false,
            autoSearchCreated: false,
            autoSearchId: nil,
            maDisclosureAcknowledged: false,
            maDisclosureTimestamp: nil,
            signedInAt: Date(),
            syncStatus: .pending
        )
    }

    /// Create a new local agent attendee for kiosk sign-in (v6.70.0)
    static func createLocalAgent(openHouseId: Int) -> OpenHouseAttendee {
        return OpenHouseAttendee(
            id: nil,
            localUUID: UUID(),
            openHouseId: openHouseId,
            firstName: "",
            lastName: "",
            email: "",
            phone: "",
            isAgent: true,
            visitorAgentBrokerage: nil,
            agentVisitPurpose: nil,
            agentHasBuyer: nil,
            agentBuyerTimeline: nil,
            agentNetworkInterest: nil,
            workingWithAgent: .no,
            otherAgentName: nil,
            otherAgentBrokerage: nil,
            otherAgentPhone: nil,
            otherAgentEmail: nil,
            buyingTimeline: .justBrowsing,
            preApproved: .notSure,
            lenderName: nil,
            howHeardAbout: nil,
            consentToFollowUp: true,
            consentToEmail: true,
            consentToText: false,
            interestLevel: .unknown,
            agentNotes: nil,
            userId: nil,
            priorityScore: 0,
            autoCrmProcessed: false,
            autoSearchCreated: false,
            autoSearchId: nil,
            maDisclosureAcknowledged: false,
            maDisclosureTimestamp: nil,
            signedInAt: Date(),
            syncStatus: .pending
        )
    }
}

// MARK: - Attendee Enums

enum WorkingWithAgentStatus: String, Codable, CaseIterable {
    case no = "no"
    case yesOther = "yes_other"
    case iAmAnAgent = "i_am_agent"

    var displayName: String {
        switch self {
        case .no: return "No"
        case .yesOther: return "Yes, with another agent"
        case .iAmAnAgent: return "I am a real estate agent"
        }
    }
}

enum BuyingTimeline: String, Codable, CaseIterable {
    case justBrowsing = "just_browsing"
    case zeroToThreeMonths = "0_to_3_months"
    case threeToSixMonths = "3_to_6_months"
    case sixPlus = "6_plus"

    var displayName: String {
        switch self {
        case .justBrowsing: return "Just browsing"
        case .zeroToThreeMonths: return "0-3 months"
        case .threeToSixMonths: return "3-6 months"
        case .sixPlus: return "6+ months"
        }
    }
}

enum PreApprovalStatus: String, Codable, CaseIterable {
    case yes = "yes"
    case no = "no"
    case notSure = "not_sure"

    var displayName: String {
        switch self {
        case .yes: return "Yes"
        case .no: return "No"
        case .notSure: return "Not sure"
        }
    }
}

enum HowHeardSource: String, Codable, CaseIterable {
    case signage = "signage"
    case onlineAd = "online_ad"
    case zillow = "zillow"
    case redfin = "redfin"
    case realtorCom = "realtor_com"
    case facebook = "facebook"
    case instagram = "instagram"
    case friend = "friend"
    case agentMarketing = "agent_marketing"
    case driveBy = "drive_by"
    case other = "other"

    var displayName: String {
        switch self {
        case .signage: return "Signage"
        case .onlineAd: return "Online Ad"
        case .zillow: return "Zillow"
        case .redfin: return "Redfin"
        case .realtorCom: return "Realtor.com"
        case .facebook: return "Facebook"
        case .instagram: return "Instagram"
        case .friend: return "Friend/Family"
        case .agentMarketing: return "Agent Marketing"
        case .driveBy: return "Drive By"
        case .other: return "Other"
        }
    }

    var iconName: String {
        switch self {
        case .signage: return "signpost.right.fill"
        case .onlineAd: return "megaphone.fill"
        case .zillow: return "house.fill"
        case .redfin: return "house.fill"
        case .realtorCom: return "house.fill"
        case .facebook: return "person.2.fill"
        case .instagram: return "camera.fill"
        case .friend: return "person.2.fill"
        case .agentMarketing: return "envelope.fill"
        case .driveBy: return "car.fill"
        case .other: return "ellipsis.circle.fill"
        }
    }
}

enum InterestLevel: String, Codable, CaseIterable {
    case notInterested = "not_interested"
    case somewhat = "somewhat"
    case veryInterested = "very_interested"
    case unknown = "unknown"

    var displayName: String {
        switch self {
        case .notInterested: return "Not Interested"
        case .somewhat: return "Somewhat Interested"
        case .veryInterested: return "Very Interested"
        case .unknown: return "Unknown"
        }
    }

    var color: String {
        switch self {
        case .notInterested: return "red"
        case .somewhat: return "orange"
        case .veryInterested: return "green"
        case .unknown: return "gray"
        }
    }
}

enum SyncStatus: String, Codable {
    case pending = "pending"
    case synced = "synced"
    case failed = "failed"
}

// MARK: - Agent Visitor Enums (v6.70.0)

/// Why an agent visitor is attending the open house
enum AgentVisitPurpose: String, Codable, CaseIterable {
    case previewing = "previewing"      // Previewing for a buyer
    case comps = "comps"                // Checking comparable prices
    case networking = "networking"      // Networking/meeting agents
    case curiosity = "curiosity"        // General curiosity
    case other = "other"

    var displayName: String {
        switch self {
        case .previewing: return "Previewing for a buyer"
        case .comps: return "Checking comps"
        case .networking: return "Networking"
        case .curiosity: return "General curiosity"
        case .other: return "Other"
        }
    }

    var iconName: String {
        switch self {
        case .previewing: return "person.2.fill"
        case .comps: return "chart.bar.fill"
        case .networking: return "network"
        case .curiosity: return "eyes"
        case .other: return "ellipsis.circle"
        }
    }
}

/// Priority tier for lead scoring (v6.70.0)
enum PriorityTier: String, Codable {
    case hot = "hot"
    case warm = "warm"
    case cool = "cool"

    var displayName: String {
        switch self {
        case .hot: return "Hot Lead"
        case .warm: return "Warm Lead"
        case .cool: return "Cool Lead"
        }
    }

    var color: String {
        switch self {
        case .hot: return "red"
        case .warm: return "orange"
        case .cool: return "blue"
        }
    }

    var iconName: String {
        switch self {
        case .hot: return "flame.fill"
        case .warm: return "thermometer.medium"
        case .cool: return "snowflake"
        }
    }
}

// MARK: - API Response Wrappers

struct OpenHouseAPIResponse<T: Decodable>: Decodable {
    let success: Bool
    let code: String?
    let message: String?
    let data: T?
}

struct OpenHouseListResponse: Decodable {
    let openHouses: [OpenHouse]
    let count: Int
    let total: Int?
    let pages: Int?
    let currentPage: Int?

    private enum CodingKeys: String, CodingKey {
        case openHouses = "open_houses"
        case count
        case total
        case pages
        case currentPage = "current_page"
    }
}

struct OpenHouseDetailResponse: Decodable {
    let openHouse: OpenHouse
    let attendees: [OpenHouseAttendee]

    private enum CodingKeys: String, CodingKey {
        case openHouse = "open_house"
        case attendees
    }

    /// Custom decoder to handle API response format where open house fields and attendees
    /// are at the same level (not wrapped in "open_house")
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Try the expected format first (open_house wrapper)
        if let oh = try? container.decode(OpenHouse.self, forKey: .openHouse) {
            self.openHouse = oh
            self.attendees = (try? container.decode([OpenHouseAttendee].self, forKey: .attendees)) ?? []
        } else {
            // API returns open house fields directly with attendees nested inside
            // We need to decode the OpenHouse from root and extract attendees
            self.openHouse = try OpenHouse(from: decoder)

            // Attendees are at root level in the API response
            let rootContainer = try decoder.container(keyedBy: RootCodingKeys.self)
            self.attendees = (try? rootContainer.decode([OpenHouseAttendee].self, forKey: .attendees)) ?? []
        }
    }

    private enum RootCodingKeys: String, CodingKey {
        case attendees
    }
}

struct CreateOpenHouseResponse: Decodable {
    let openHouse: OpenHouse
    let message: String?

    private enum CodingKeys: String, CodingKey {
        case openHouse = "open_house"
        case message
    }
}

struct AddAttendeeResponse: Decodable {
    let attendee: OpenHouseAttendee?
    let message: String?
    let id: Int?
    let localUUID: String?

    private enum CodingKeys: String, CodingKey {
        case attendee, message, id
        case localUUID = "local_uuid"
    }
}

/// Individual synced attendee result from bulk sync API
struct SyncedAttendeeResult: Decodable {
    let id: Int?
    let localUUID: String?
    let status: String?

    private enum CodingKeys: String, CodingKey {
        case id
        case localUUID = "local_uuid"
        case status
    }
}

/// Error result for attendee that failed to sync
struct SyncErrorResult: Decodable {
    let localUUID: String?
    let error: String?

    private enum CodingKeys: String, CodingKey {
        case localUUID = "local_uuid"
        case error
    }
}

struct BulkSyncResponse: Decodable {
    /// Array of successfully synced attendees with their local UUIDs
    let synced: [SyncedAttendeeResult]
    /// Array of attendees that failed to sync with error messages
    let errors: [SyncErrorResult]
    /// Count of successfully synced attendees
    let syncedCount: Int
    /// Count of failed attendees
    let errorCount: Int
    /// Full attendee objects (may be returned by some API versions)
    let attendees: [OpenHouseAttendee]?

    private enum CodingKeys: String, CodingKey {
        case synced
        case errors
        case syncedCount = "synced_count"
        case errorCount = "error_count"
        case attendees
    }

    /// Custom decoder to handle both old (synced: Int) and new (synced: []) API formats
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Handle synced - could be an array (new format) or we need to build from counts
        if let syncedArray = try? container.decode([SyncedAttendeeResult].self, forKey: .synced) {
            synced = syncedArray
        } else {
            synced = []
        }

        // Handle errors array
        errors = (try? container.decode([SyncErrorResult].self, forKey: .errors)) ?? []

        // Handle counts - may come from synced_count/error_count or from array lengths
        if let count = try? container.decode(Int.self, forKey: .syncedCount) {
            syncedCount = count
        } else {
            syncedCount = synced.count
        }

        if let count = try? container.decode(Int.self, forKey: .errorCount) {
            errorCount = count
        } else {
            errorCount = errors.count
        }

        // Optional attendees array
        attendees = try? container.decode([OpenHouseAttendee].self, forKey: .attendees)
    }
}

struct NearbyPropertyResponse: Decodable {
    let properties: [NearbyProperty]
}

/// Property images for kiosk slideshow (v6.71.0)
struct OpenHousePropertyImagesResponse: Decodable {
    let images: [String]
    let count: Int
    let listingId: String?

    private enum CodingKeys: String, CodingKey {
        case images
        case count
        case listingId = "listing_id"
    }
}

struct ExportAttendeesResponse: Decodable {
    let csv: String
    let filename: String?
    let attendeeCount: Int?

    private enum CodingKeys: String, CodingKey {
        case csv
        case filename
        case attendeeCount = "attendee_count"
    }
}

struct NearbyProperty: Identifiable, Codable {
    let id: String                          // listing_id
    let address: String
    let city: String
    let state: String
    let zip: String
    let propertyType: String?
    let beds: Int?
    let baths: Double?
    let listPrice: Int?
    let photoUrl: String?
    let latitude: Double
    let longitude: Double
    let distance: Double                    // Miles from current location

    private enum CodingKeys: String, CodingKey {
        case id = "listing_id"
        case address
        case city
        case state
        case zip
        case propertyType = "property_type"
        case beds
        case baths
        case listPrice = "list_price"
        case photoUrl = "photo_url"
        case latitude
        case longitude
        case distance
    }

    var fullAddress: String {
        return "\(address), \(city), \(state) \(zip)"
    }
}

// MARK: - Create/Update Request

// MARK: - CRM Integration Models (v6.70.0)

/// Response from convert-to-client endpoint
struct ConvertToClientResponse: Decodable {
    let userId: Int
    let status: String              // "created_new", "assigned_existing", "linked_existing"
    let message: String

    private enum CodingKeys: String, CodingKey {
        case userId = "user_id"
        case status
        case message
    }

    var wasNewUserCreated: Bool {
        return status == "created_new"
    }
}

/// Response from crm-status endpoint
struct CRMStatusResponse: Decodable {
    let isConverted: Bool           // Has been converted to CRM client
    let userId: Int?                // User ID if converted
    let emailExists: Bool           // Email exists in WordPress users
    let isMyClient: Bool            // Already assigned to this agent
    let isOtherAgentClient: Bool    // Assigned to another agent

    private enum CodingKeys: String, CodingKey {
        case isConverted = "is_converted"
        case userId = "user_id"
        case emailExists = "email_exists"
        case isMyClient = "is_my_client"
        case isOtherAgentClient = "is_other_agent_client"
    }
}

/// Response from attendee history endpoint (v6.70.0)
struct AttendeeHistoryResponse: Decodable {
    let email: String
    let totalVisits: Int
    let history: [AttendeeHistoryItem]

    private enum CodingKeys: String, CodingKey {
        case email
        case totalVisits = "total_visits"
        case history
    }
}

/// Single history item for attendee visit history
struct AttendeeHistoryItem: Identifiable, Decodable {
    let attendeeId: Int
    let openHouseId: Int
    let propertyAddress: String
    let propertyCity: String
    let eventDate: String
    let listPrice: Int?
    let signedInAt: String
    let interestLevel: String

    var id: Int { attendeeId }

    private enum CodingKeys: String, CodingKey {
        case attendeeId = "attendee_id"
        case openHouseId = "open_house_id"
        case propertyAddress = "property_address"
        case propertyCity = "property_city"
        case eventDate = "event_date"
        case listPrice = "list_price"
        case signedInAt = "signed_in_at"
        case interestLevel = "interest_level"
    }
}

/// Attendee filter types for list filtering (v6.70.0)
enum AttendeeFilterType: String, CaseIterable {
    case all = "all"
    case buyers = "buyers"
    case agents = "agents"
    case hot = "hot"

    var displayName: String {
        switch self {
        case .all: return "All"
        case .buyers: return "Buyers"
        case .agents: return "Agents"
        case .hot: return "Hot Leads"
        }
    }

    var iconName: String {
        switch self {
        case .all: return "person.3.fill"
        case .buyers: return "house.fill"
        case .agents: return "briefcase.fill"
        case .hot: return "flame.fill"
        }
    }
}

// MARK: - Create/Update Request

struct CreateOpenHouseRequest: Encodable {
    let listingId: String?
    let propertyAddress: String
    let propertyCity: String
    let propertyState: String
    let propertyZip: String
    let propertyType: String?
    let beds: Int?
    let baths: Double?
    let listPrice: Int?
    let photoUrl: String?
    let latitude: Double?
    let longitude: Double?
    let eventDate: String
    let startTime: String
    let endTime: String
    let notes: String?

    private enum CodingKeys: String, CodingKey {
        case listingId = "listing_id"
        case propertyAddress = "property_address"
        case propertyCity = "property_city"
        case propertyState = "property_state"
        case propertyZip = "property_zip"
        case propertyType = "property_type"
        case beds
        case baths
        case listPrice = "list_price"
        case photoUrl = "photo_url"
        case latitude
        case longitude
        case eventDate = "date"
        case startTime = "start_time"
        case endTime = "end_time"
        case notes
    }

    /// Create from a NearbyProperty (database selection)
    static func fromNearbyProperty(_ property: NearbyProperty, date: String, startTime: String, endTime: String, notes: String?) -> CreateOpenHouseRequest {
        return CreateOpenHouseRequest(
            listingId: property.id,
            propertyAddress: property.address,
            propertyCity: property.city,
            propertyState: property.state,
            propertyZip: property.zip,
            propertyType: property.propertyType,
            beds: property.beds,
            baths: property.baths,
            listPrice: property.listPrice,
            photoUrl: property.photoUrl,
            latitude: property.latitude,
            longitude: property.longitude,
            eventDate: date,
            startTime: startTime,
            endTime: endTime,
            notes: notes
        )
    }
}
