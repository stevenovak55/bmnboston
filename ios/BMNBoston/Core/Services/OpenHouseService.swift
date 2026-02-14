//
//  OpenHouseService.swift
//  BMNBoston
//
//  Actor-based service for Open House API operations
//  Created for BMN Boston Real Estate
//
//  VERSION: v6.71.0
//

import Foundation
import CoreLocation

actor OpenHouseService {
    static let shared = OpenHouseService()

    // MARK: - Cache

    private var openHousesCache: [OpenHouse]?
    private var openHousesCacheTime: Date?
    private let cacheExpirationMinutes: TimeInterval = 5

    // MARK: - Open House CRUD

    /// Fetch agent's open houses
    func fetchOpenHouses(forceRefresh: Bool = false) async throws -> [OpenHouse] {
        // Check cache
        if !forceRefresh,
           let cached = openHousesCache,
           let cacheTime = openHousesCacheTime,
           Date().timeIntervalSince(cacheTime) < cacheExpirationMinutes * 60 {
            return cached
        }

        let response: OpenHouseListResponse = try await APIClient.shared.request(.openHouses)

        openHousesCache = response.openHouses
        openHousesCacheTime = Date()

        return response.openHouses
    }

    /// Fetch single open house with attendees
    func fetchOpenHouseDetail(id: Int) async throws -> OpenHouseDetailResponse {
        let response: OpenHouseDetailResponse = try await APIClient.shared.request(.openHouseDetail(id: id))
        return response
    }

    /// Create a new open house
    func createOpenHouse(request: CreateOpenHouseRequest) async throws -> OpenHouse {
        let response: CreateOpenHouseResponse = try await APIClient.shared.request(.createOpenHouse(request: request))

        // Invalidate cache
        openHousesCache = nil

        return response.openHouse
    }

    /// Update an existing open house
    func updateOpenHouse(id: Int, request: CreateOpenHouseRequest) async throws -> OpenHouse {
        let response: CreateOpenHouseResponse = try await APIClient.shared.request(.updateOpenHouse(id: id, request: request))

        // Invalidate cache
        openHousesCache = nil

        return response.openHouse
    }

    /// Delete an open house
    func deleteOpenHouse(id: Int) async throws {
        let _: EmptyResponse = try await APIClient.shared.request(.deleteOpenHouse(id: id))

        // Invalidate cache
        openHousesCache = nil
    }

    // MARK: - Open House Status

    /// Start an open house (mark as active)
    func startOpenHouse(id: Int) async throws -> OpenHouse {
        let response: CreateOpenHouseResponse = try await APIClient.shared.request(.startOpenHouse(id: id))

        // Invalidate cache
        openHousesCache = nil

        return response.openHouse
    }

    /// End an open house (mark as completed) — returns open house and optional summary
    func endOpenHouse(id: Int) async throws -> (openHouse: OpenHouse, summary: OpenHouseSummary?) {
        let response: CreateOpenHouseResponse = try await APIClient.shared.request(.endOpenHouse(id: id))

        // Invalidate cache
        openHousesCache = nil

        return (response.openHouse, response.summary)
    }

    // MARK: - Summary Report (v6.76.0)

    /// Fetch summary report for a completed open house
    func fetchSummary(openHouseId: Int) async throws -> OpenHouseSummary {
        let response: OpenHouseSummaryResponse = try await APIClient.shared.request(.openHouseSummary(id: openHouseId))
        return response.summary
    }

    // MARK: - Attendee Management

    /// Add a single attendee
    func addAttendee(openHouseId: Int, attendee: OpenHouseAttendee) async throws -> OpenHouseAttendee {
        let response: AddAttendeeResponse = try await APIClient.shared.request(
            .addAttendee(openHouseId: openHouseId, attendee: attendee)
        )

        // Server returns full attendee on success (201), but only id/local_uuid on dedup (200)
        if let savedAttendee = response.attendee {
            return savedAttendee
        }

        // Dedup case: server already has this attendee, return the original with synced status
        var dedupAttendee = attendee
        dedupAttendee.syncStatus = .synced
        return dedupAttendee
    }

    /// Bulk sync offline attendees
    func syncAttendees(openHouseId: Int, attendees: [OpenHouseAttendee]) async throws -> BulkSyncResponse {
        let response: BulkSyncResponse = try await APIClient.shared.request(
            .bulkSyncAttendees(openHouseId: openHouseId, attendees: attendees)
        )
        return response
    }

    /// Update attendee (e.g., interest level, notes)
    func updateAttendee(openHouseId: Int, attendeeId: Int, interestLevel: InterestLevel?, notes: String?) async throws -> OpenHouseAttendee {
        let response: AddAttendeeResponse = try await APIClient.shared.request(
            .updateAttendee(openHouseId: openHouseId, attendeeId: attendeeId, interestLevel: interestLevel, notes: notes)
        )
        guard let attendee = response.attendee else {
            throw URLError(.badServerResponse)
        }
        return attendee
    }

    // MARK: - Property Lookup

    /// Get nearby properties for quick property selection
    func fetchNearbyProperties(latitude: Double, longitude: Double, radius: Double = 0.5) async throws -> [NearbyProperty] {
        let response: NearbyPropertyResponse = try await APIClient.shared.request(
            .nearbyProperties(latitude: latitude, longitude: longitude, radius: radius)
        )
        return response.properties
    }

    // MARK: - Kiosk Mode (v6.71.0)

    /// Get property images for kiosk slideshow
    func fetchPropertyImages(openHouseId: Int) async throws -> [String] {
        let response: OpenHousePropertyImagesResponse = try await APIClient.shared.request(
            .openHousePropertyImages(id: openHouseId)
        )
        return response.images
    }

    // MARK: - Export

    /// Export attendees as CSV string
    func exportAttendees(openHouseId: Int) async throws -> String {
        let response: ExportAttendeesResponse = try await APIClient.shared.request(
            .exportAttendees(openHouseId: openHouseId)
        )
        return response.csv
    }

    // MARK: - CRM Integration (v6.70.0)

    /// Convert attendee to CRM client
    func convertToClient(attendeeId: Int) async throws -> ConvertToClientResponse {
        let response: ConvertToClientResponse = try await APIClient.shared.request(
            .convertAttendeeToClient(attendeeId: attendeeId)
        )
        return response
    }

    /// Get CRM status for an attendee
    func getCRMStatus(attendeeId: Int) async throws -> CRMStatusResponse {
        let response: CRMStatusResponse = try await APIClient.shared.request(
            .attendeeCRMStatus(attendeeId: attendeeId)
        )
        return response
    }

    /// Get attendee visit history (all open houses this email has attended)
    func getAttendeeHistory(attendeeId: Int) async throws -> AttendeeHistoryResponse {
        let response: AttendeeHistoryResponse = try await APIClient.shared.request(
            .attendeeHistory(attendeeId: attendeeId)
        )
        return response
    }

    /// Fetch open house detail with filter (v6.70.0)
    func fetchOpenHouseDetail(id: Int, filter: AttendeeFilterType = .all, sortBy: String = "signed_in_at") async throws -> OpenHouseDetailResponse {
        let response: OpenHouseDetailResponse = try await APIClient.shared.request(
            .openHouseDetailFiltered(id: id, filter: filter.rawValue, sortBy: sortBy)
        )
        return response
    }

    // MARK: - Cache Management

    func clearCache() {
        openHousesCache = nil
        openHousesCacheTime = nil
    }
}
