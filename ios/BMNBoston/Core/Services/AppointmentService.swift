//
//  AppointmentService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Appointment booking service with caching
//

import Foundation

/// Actor-based service for appointment booking operations
actor AppointmentService {
    static let shared = AppointmentService()

    // MARK: - Cache Configuration

    private var appointmentTypesCache: [AppointmentType]?
    private var appointmentTypesCacheTime: Date?
    private let appointmentTypesCacheDuration: TimeInterval = 3600 // 1 hour

    private var staffCache: [Int?: [StaffMember]] = [:] // Keyed by typeId (nil = all)
    private var staffCacheTime: [Int?: Date] = [:]
    private let staffCacheDuration: TimeInterval = 3600 // 1 hour

    private var appointmentsCache: [Appointment]?
    private var appointmentsCacheTime: Date?
    private let appointmentsCacheDuration: TimeInterval = 60 // 1 minute

    private var policyCache: PortalPolicy?
    private var policyCacheTime: Date?
    private let policyCacheDuration: TimeInterval = 3600 // 1 hour

    // Availability cache keyed by "staffId_typeId_startDate_endDate"
    private var availabilityCache: [String: AvailabilityResponse] = [:]
    private var availabilityCacheTime: [String: Date] = [:]
    private let availabilityCacheDuration: TimeInterval = 600 // 10 minutes

    private init() {}

    // MARK: - Cache Management

    /// Clear all caches
    func clearCache() {
        appointmentTypesCache = nil
        appointmentTypesCacheTime = nil
        staffCache = [:]
        staffCacheTime = [:]
        appointmentsCache = nil
        appointmentsCacheTime = nil
        policyCache = nil
        policyCacheTime = nil
        availabilityCache = [:]
        availabilityCacheTime = [:]
    }

    /// Clear appointments cache (after booking/cancel/reschedule)
    func clearAppointmentsCache() {
        appointmentsCache = nil
        appointmentsCacheTime = nil
    }

    /// Clear availability cache (after booking)
    func clearAvailabilityCache() {
        availabilityCache = [:]
        availabilityCacheTime = [:]
    }

    private func isCacheValid(_ cacheTime: Date?, duration: TimeInterval) -> Bool {
        guard let cacheTime = cacheTime else { return false }
        return Date().timeIntervalSince(cacheTime) < duration
    }

    // MARK: - Appointment Types

    /// Fetch available appointment types
    /// - Parameter forceRefresh: Bypass cache and fetch fresh data
    /// - Returns: Array of appointment types
    func fetchAppointmentTypes(forceRefresh: Bool = false) async throws -> [AppointmentType] {
        // Check cache
        if !forceRefresh,
           let cached = appointmentTypesCache,
           isCacheValid(appointmentTypesCacheTime, duration: appointmentTypesCacheDuration) {
            return cached
        }

        // Fetch from API - APIClient.request() already unwraps the response
        let types: [AppointmentType] = try await APIClient.shared.request(.appointmentTypes)

        // Update cache
        appointmentTypesCache = types
        appointmentTypesCacheTime = Date()

        return types
    }

    // MARK: - Staff

    /// Fetch staff members
    /// - Parameters:
    ///   - typeId: Optional appointment type ID to filter staff
    ///   - forceRefresh: Bypass cache and fetch fresh data
    /// - Returns: Array of staff members
    func fetchStaff(forTypeId typeId: Int? = nil, forceRefresh: Bool = false) async throws -> [StaffMember] {
        // Check cache
        if !forceRefresh,
           let cached = staffCache[typeId],
           isCacheValid(staffCacheTime[typeId], duration: staffCacheDuration) {
            return cached
        }

        // Fetch from API
        let staff: [StaffMember] = try await APIClient.shared.request(.appointmentStaff(typeId: typeId))

        // Update cache
        staffCache[typeId] = staff
        staffCacheTime[typeId] = Date()

        return staff
    }

    // MARK: - Availability

    /// Fetch available time slots for a date range
    /// - Parameters:
    ///   - startDate: Start date (Y-m-d format)
    ///   - endDate: End date (Y-m-d format)
    ///   - typeId: Appointment type ID
    ///   - staffId: Optional staff member ID
    ///   - forceRefresh: Bypass cache and fetch fresh data
    /// - Returns: Availability response with dates and time slots
    func fetchAvailability(
        startDate: String,
        endDate: String,
        typeId: Int,
        staffId: Int? = nil,
        forceRefresh: Bool = false
    ) async throws -> AvailabilityResponse {
        // Build cache key
        let staffKey = staffId.map { String($0) } ?? "any"
        let cacheKey = "\(staffKey)_\(typeId)_\(startDate)_\(endDate)"

        // Check cache
        if !forceRefresh,
           let cached = availabilityCache[cacheKey],
           isCacheValid(availabilityCacheTime[cacheKey], duration: availabilityCacheDuration) {
            return cached
        }

        // Fetch from API
        let availability: AvailabilityResponse = try await APIClient.shared.request(
            .appointmentAvailability(
                startDate: startDate,
                endDate: endDate,
                typeId: typeId,
                staffId: staffId
            )
        )

        // Update cache
        availabilityCache[cacheKey] = availability
        availabilityCacheTime[cacheKey] = Date()

        return availability
    }

    // MARK: - Booking

    /// Create a new appointment
    /// - Parameter request: Booking request with appointment details
    /// - Returns: Booking response with confirmation details
    func bookAppointment(request: BookAppointmentRequest) async throws -> BookingResponse {
        let booking: BookingResponse = try await APIClient.shared.request(
            .createAppointment(request: request)
        )

        // Clear caches after successful booking
        clearAppointmentsCache()
        clearAvailabilityCache()

        return booking
    }

    // MARK: - User Appointments

    /// Fetch user's appointments
    /// - Parameters:
    ///   - status: Filter by status (upcoming, past, cancelled)
    ///   - page: Page number for pagination
    ///   - forceRefresh: Bypass cache and fetch fresh data
    /// - Returns: Array of appointments
    func fetchUserAppointments(
        status: String? = nil,
        page: Int = 1,
        forceRefresh: Bool = false
    ) async throws -> [Appointment] {
        // Check cache (only for first page with no status filter)
        if !forceRefresh && page == 1 && status == nil,
           let cached = appointmentsCache,
           isCacheValid(appointmentsCacheTime, duration: appointmentsCacheDuration) {
            return cached
        }

        // Fetch from API
        let listResponse: AppointmentsListResponse = try await APIClient.shared.request(
            .userAppointments(status: status, page: page)
        )

        // Update cache for first page with no filter
        if page == 1 && status == nil {
            appointmentsCache = listResponse.appointments
            appointmentsCacheTime = Date()
        }

        return listResponse.appointments
    }

    /// Fetch a single appointment by ID
    /// - Parameter id: Appointment ID
    /// - Returns: Appointment details
    func fetchAppointment(id: Int) async throws -> Appointment {
        let appointment: Appointment = try await APIClient.shared.request(
            .appointmentDetail(id: id)
        )

        return appointment
    }

    // MARK: - Cancel & Reschedule

    /// Cancel an appointment
    /// - Parameters:
    ///   - id: Appointment ID
    ///   - reason: Optional cancellation reason
    func cancelAppointment(id: Int, reason: String? = nil) async throws {
        let _: CancelResponse = try await APIClient.shared.request(
            .cancelAppointment(id: id, reason: reason)
        )

        // Clear appointments cache
        clearAppointmentsCache()
    }

    /// Reschedule an appointment
    /// - Parameters:
    ///   - id: Appointment ID
    ///   - newDate: New date (Y-m-d format)
    ///   - newTime: New time (H:i format)
    func rescheduleAppointment(id: Int, newDate: String, newTime: String) async throws {
        // API returns a simplified response, we just need to confirm it succeeded
        let _: RescheduleResponse = try await APIClient.shared.request(
            .rescheduleAppointment(id: id, newDate: newDate, newTime: newTime)
        )

        // Clear appointments cache
        clearAppointmentsCache()
    }

    /// Fetch available slots for rescheduling
    /// - Parameters:
    ///   - appointmentId: Appointment ID
    ///   - startDate: Start date (Y-m-d format)
    ///   - endDate: End date (Y-m-d format)
    /// - Returns: Availability response
    func fetchRescheduleSlots(
        appointmentId: Int,
        startDate: String,
        endDate: String
    ) async throws -> AvailabilityResponse {
        let availability: AvailabilityResponse = try await APIClient.shared.request(
            .rescheduleSlots(appointmentId: appointmentId, startDate: startDate, endDate: endDate)
        )

        return availability
    }

    // MARK: - Portal Policy

    /// Fetch portal cancellation/reschedule policies
    /// - Parameter forceRefresh: Bypass cache and fetch fresh data
    /// - Returns: Portal policy configuration
    func fetchPortalPolicy(forceRefresh: Bool = false) async throws -> PortalPolicy {
        // Check cache
        if !forceRefresh,
           let cached = policyCache,
           isCacheValid(policyCacheTime, duration: policyCacheDuration) {
            return cached
        }

        // Fetch from API
        let policy: PortalPolicy = try await APIClient.shared.request(
            .appointmentPortalPolicy
        )

        // Update cache
        policyCache = policy
        policyCacheTime = Date()

        return policy
    }
}

// MARK: - Error Types

enum AppointmentServiceError: LocalizedError {
    case apiError(String)
    case notAuthenticated
    case invalidData

    var errorDescription: String? {
        switch self {
        case .apiError(let message):
            return message
        case .notAuthenticated:
            return "Please log in to view your appointments"
        case .invalidData:
            return "Invalid appointment data received"
        }
    }
}

// Note: EmptyResponse is defined in APIClient.swift
