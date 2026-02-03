//
//  Appointment.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation

struct Appointment: Identifiable, Decodable {
    let id: Int
    let typeName: String
    let typeColor: String?
    let status: AppointmentStatus
    let date: String
    let startTime: String
    let endTime: String
    let staffId: Int?
    let staffName: String?
    let listingId: String?
    let propertyAddress: String?
    let clientNotes: String?
    let canCancel: Bool
    let canReschedule: Bool
    let rescheduleCount: Int?
    let googleEventId: String?
    let createdAt: String?

    private enum CodingKeys: String, CodingKey {
        case id
        case typeName = "type_name"
        case typeColor = "type_color"
        case status
        case date
        case startTime = "start_time"
        case endTime = "end_time"
        case staffId = "staff_id"
        case staffName = "staff_name"
        case listingId = "listing_id"
        case propertyAddress = "property_address"
        case clientNotes = "client_notes"
        case canCancel = "can_cancel"
        case canReschedule = "can_reschedule"
        case rescheduleCount = "reschedule_count"
        case googleEventId = "google_event_id"
        case createdAt = "created_at"
    }

    /// Server timezone - all appointment times are stored in Eastern time
    /// v321: Removed force unwrap to prevent crash if timezone identifier becomes invalid
    private static let serverTimezone: TimeZone = {
        if let tz = TimeZone(identifier: "America/New_York") {
            return tz
        }
        // Fallback to EST offset (-5 hours) if named timezone unavailable
        return TimeZone(secondsFromGMT: -5 * 3600) ?? .current
    }()

    var formattedDate: String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"
        inputFormatter.timeZone = Self.serverTimezone

        let outputFormatter = DateFormatter()
        outputFormatter.dateStyle = .long
        outputFormatter.timeZone = .current

        if let date = inputFormatter.date(from: date) {
            return outputFormatter.string(from: date)
        }
        return date
    }

    var formattedTime: String {
        guard let startDateTime = dateTime else {
            return startTime
        }

        let outputFormatter = DateFormatter()
        outputFormatter.timeStyle = .short
        outputFormatter.timeZone = .current

        var result = outputFormatter.string(from: startDateTime)

        if let endDateTime = endDateTime {
            result += " - \(outputFormatter.string(from: endDateTime))"
        }

        return result
    }

    /// Returns the appointment start time as a Date, parsed in server timezone (Eastern)
    var dateTime: Date? {
        let formatter = DateFormatter()
        formatter.timeZone = Self.serverTimezone
        // Try with seconds first (database format: "HH:mm:ss")
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = formatter.date(from: "\(date) \(startTime)") {
            return date
        }
        // Fall back to without seconds (HH:mm)
        formatter.dateFormat = "yyyy-MM-dd HH:mm"
        return formatter.date(from: "\(date) \(startTime)")
    }

    /// Returns the appointment end time as a Date, parsed in server timezone (Eastern)
    var endDateTime: Date? {
        let formatter = DateFormatter()
        formatter.timeZone = Self.serverTimezone
        // Try with seconds first (database format: "HH:mm:ss")
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = formatter.date(from: "\(self.date) \(endTime)") {
            return date
        }
        // Fall back to without seconds (HH:mm)
        formatter.dateFormat = "yyyy-MM-dd HH:mm"
        return formatter.date(from: "\(self.date) \(endTime)")
    }

    var isPast: Bool {
        guard let appointmentDate = dateTime else { return false }
        return appointmentDate < Date()
    }

    var isUpcoming: Bool {
        guard let appointmentDate = dateTime else { return true }
        return appointmentDate >= Date()
    }

    var statusColor: Color {
        switch status {
        case .pending: return .orange
        case .confirmed: return .green
        case .cancelled: return .red
        case .completed: return .blue
        case .noShow: return .gray
        }
    }

    /// Create a copy with updated date/time for optimistic UI updates
    func withUpdatedDateTime(date newDate: String, time newTime: String) -> Appointment {
        // Calculate new end time (add same duration)
        let duration = calculateDuration()
        let newEndTime = calculateEndTime(startTime: newTime, duration: duration)

        return Appointment(
            id: id,
            typeName: typeName,
            typeColor: typeColor,
            status: status,
            date: newDate,
            startTime: newTime,
            endTime: newEndTime,
            staffId: staffId,
            staffName: staffName,
            listingId: listingId,
            propertyAddress: propertyAddress,
            clientNotes: clientNotes,
            canCancel: canCancel,
            canReschedule: canReschedule,
            rescheduleCount: (rescheduleCount ?? 0) + 1,
            googleEventId: googleEventId,
            createdAt: createdAt
        )
    }

    private func calculateDuration() -> Int {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss"

        // Try with seconds format
        if let start = formatter.date(from: startTime),
           let end = formatter.date(from: endTime) {
            return Int(end.timeIntervalSince(start) / 60)
        }

        // Try without seconds
        formatter.dateFormat = "HH:mm"
        if let start = formatter.date(from: startTime),
           let end = formatter.date(from: endTime) {
            return Int(end.timeIntervalSince(start) / 60)
        }

        return 30 // Default 30 minutes
    }

    private func calculateEndTime(startTime: String, duration: Int) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm"

        if let start = formatter.date(from: startTime) {
            let end = start.addingTimeInterval(TimeInterval(duration * 60))
            return formatter.string(from: end)
        }

        return startTime
    }
}

import SwiftUI

enum AppointmentStatus: String, Decodable {
    case pending = "pending"
    case confirmed = "confirmed"
    case cancelled = "cancelled"
    case completed = "completed"
    case noShow = "no_show"

    var displayName: String {
        switch self {
        case .pending: return "Pending"
        case .confirmed: return "Confirmed"
        case .cancelled: return "Cancelled"
        case .completed: return "Completed"
        case .noShow: return "No Show"
        }
    }

    var color: String {
        switch self {
        case .pending: return "orange"
        case .confirmed: return "green"
        case .cancelled: return "red"
        case .completed: return "blue"
        case .noShow: return "gray"
        }
    }
}

// MARK: - Appointment Type

struct AppointmentType: Identifiable, Decodable {
    let id: Int
    let name: String
    let slug: String
    let description: String?
    let durationMinutes: Int
    let color: String?
    let requiresLogin: Bool
    let sortOrder: Int?

    private enum CodingKeys: String, CodingKey {
        case id
        case name
        case slug
        case description
        case durationMinutes = "duration_minutes"
        case color
        case requiresLogin = "requires_login"
        case sortOrder = "sort_order"
    }

    var formattedDuration: String {
        if durationMinutes >= 60 {
            let hours = durationMinutes / 60
            let minutes = durationMinutes % 60
            if minutes > 0 {
                return "\(hours)h \(minutes)m"
            }
            return "\(hours) hour\(hours > 1 ? "s" : "")"
        }
        return "\(durationMinutes) min"
    }

    /// Parse hex color string to SwiftUI Color
    var uiColor: Color {
        guard let color = color, color.hasPrefix("#") else {
            return .blue
        }
        let hex = String(color.dropFirst())
        guard hex.count == 6,
              let rgb = Int(hex, radix: 16) else {
            return .blue
        }
        return Color(
            red: Double((rgb >> 16) & 0xFF) / 255.0,
            green: Double((rgb >> 8) & 0xFF) / 255.0,
            blue: Double(rgb & 0xFF) / 255.0
        )
    }
}

// MARK: - Staff Member

struct StaffMember: Identifiable, Decodable {
    let id: Int
    let name: String
    let title: String?
    let email: String?
    let phone: String?
    let bio: String?
    let avatarUrl: String?
    let isPrimary: Bool

    private enum CodingKeys: String, CodingKey {
        case id
        case name
        case title
        case email
        case phone
        case bio
        case avatarUrl = "avatar_url"
        case isPrimary = "is_primary"
    }
}

// MARK: - Time Slot

struct TimeSlot: Identifiable, Decodable {
    let value: String
    let label: String

    var id: String { value }

    private enum CodingKeys: String, CodingKey {
        case value
        case label
    }

    var time: String { value }

    var formattedTime: String {
        // Label is already formatted from the API
        return label
    }
}

// MARK: - Book Appointment Request

struct BookAppointmentRequest {
    let appointmentTypeId: Int
    let staffId: Int
    let date: String
    let time: String
    var clientName: String?
    var clientEmail: String?
    var clientPhone: String?
    let listingId: String?
    let propertyAddress: String?
    var notes: String?
}

// MARK: - API Response Wrappers

/// Generic API response wrapper matching WordPress REST API format
struct AppointmentAPIResponse<T: Decodable>: Decodable {
    let success: Bool
    let code: String?
    let message: String?
    let data: T?

    private enum CodingKeys: String, CodingKey {
        case success
        case code
        case message
        case data
    }
}

/// Response for listing appointments
struct AppointmentsListResponse: Decodable {
    let appointments: [Appointment]
    let total: Int
    let pages: Int
    let currentPage: Int

    private enum CodingKeys: String, CodingKey {
        case appointments
        case total
        case pages
        case currentPage = "current_page"
    }
}

/// Response for availability query
struct AvailabilityResponse: Decodable {
    let datesWithAvailability: [String]
    let slots: [String: [TimeSlot]]

    private enum CodingKeys: String, CodingKey {
        case datesWithAvailability = "dates_with_availability"
        case slots
    }

    /// Get slots for a specific date
    func slotsForDate(_ date: String) -> [TimeSlot] {
        return slots[date] ?? []
    }

    /// Check if a date has available slots
    func hasAvailability(for date: String) -> Bool {
        return datesWithAvailability.contains(date)
    }
}

/// Response for creating an appointment
struct BookingResponse: Decodable {
    let appointmentId: Int
    let status: String
    let typeName: String
    let date: String
    let time: String
    let dateRaw: String?      // ISO format (yyyy-MM-dd) for calendar
    let timeRaw: String?      // 24h format (HH:mm:ss) for calendar
    let duration: Int?
    let googleSynced: Bool?

    private enum CodingKeys: String, CodingKey {
        case appointmentId = "appointment_id"
        case status
        case typeName = "type_name"
        case date
        case time
        case dateRaw = "date_raw"
        case timeRaw = "time_raw"
        case duration
        case googleSynced = "google_synced"
    }
}

/// Response for rescheduling an appointment
struct RescheduleResponse: Decodable {
    let id: Int
    let status: String
    let date: String
    let rescheduleCount: Int

    private enum CodingKeys: String, CodingKey {
        case id
        case status
        case date
        case rescheduleCount = "reschedule_count"
    }
}

/// Response for cancelling an appointment
struct CancelResponse: Decodable {
    let id: Int
    let status: String
}

/// Portal policy response
struct PortalPolicy: Decodable {
    let portalEnabled: Bool
    let cancellation: CancellationPolicy
    let reschedule: ReschedulePolicy

    private enum CodingKeys: String, CodingKey {
        case portalEnabled = "portal_enabled"
        case cancellation
        case reschedule
    }
}

struct CancellationPolicy: Decodable {
    let enabled: Bool
    let hoursBefore: Int
    let requireReason: Bool
    let policyText: String

    private enum CodingKeys: String, CodingKey {
        case enabled
        case hoursBefore = "hours_before"
        case requireReason = "require_reason"
        case policyText = "policy_text"
    }
}

struct ReschedulePolicy: Decodable {
    let enabled: Bool
    let hoursBefore: Int
    let maxReschedules: Int
    let policyText: String

    private enum CodingKeys: String, CodingKey {
        case enabled
        case hoursBefore = "hours_before"
        case maxReschedules = "max_reschedules"
        case policyText = "policy_text"
    }
}
