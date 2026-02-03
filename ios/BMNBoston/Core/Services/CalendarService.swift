//
//  CalendarService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  EventKit integration for adding appointments to Apple Calendar
//

import Foundation
import EventKit

/// Service for integrating with Apple Calendar via EventKit
@MainActor
class CalendarService: ObservableObject {
    static let shared = CalendarService()

    private let eventStore = EKEventStore()

    @Published var authorizationStatus: EKAuthorizationStatus = .notDetermined
    @Published var lastError: String?

    private init() {
        updateAuthorizationStatus()
    }

    // MARK: - Authorization

    func updateAuthorizationStatus() {
        authorizationStatus = EKEventStore.authorizationStatus(for: .event)
    }

    /// Request access to the calendar
    func requestAccess() async -> Bool {
        if #available(iOS 17.0, *) {
            do {
                let granted = try await eventStore.requestFullAccessToEvents()
                updateAuthorizationStatus()
                return granted
            } catch {
                lastError = error.localizedDescription
                updateAuthorizationStatus()
                return false
            }
        } else {
            // iOS 16 fallback
            return await withCheckedContinuation { continuation in
                eventStore.requestAccess(to: .event) { granted, error in
                    Task { @MainActor in
                        if let error = error {
                            self.lastError = error.localizedDescription
                        }
                        self.updateAuthorizationStatus()
                        continuation.resume(returning: granted)
                    }
                }
            }
        }
    }

    var hasAccess: Bool {
        if #available(iOS 17.0, *) {
            return authorizationStatus == .fullAccess
        } else {
            return authorizationStatus == .authorized
        }
    }

    var needsPermission: Bool {
        authorizationStatus == .notDetermined
    }

    // MARK: - Add to Calendar

    /// Add an appointment to the default calendar
    /// - Parameters:
    ///   - appointment: The appointment to add
    /// - Returns: True if successfully added
    func addToCalendar(appointment: Appointment) async -> Bool {
        // Request access if needed
        if !hasAccess {
            let granted = await requestAccess()
            if !granted {
                lastError = "Calendar access denied. Please enable in Settings."
                return false
            }
        }

        guard let dateTime = appointment.dateTime else {
            lastError = "Could not parse appointment date/time"
            return false
        }

        // Calculate end time
        let duration = calculateDuration(startTime: appointment.startTime, endTime: appointment.endTime)
        let endDate = dateTime.addingTimeInterval(TimeInterval(duration * 60))

        // Create event
        let event = EKEvent(eventStore: eventStore)
        event.title = appointment.typeName
        event.startDate = dateTime
        event.endDate = endDate
        event.calendar = eventStore.defaultCalendarForNewEvents

        // Add notes with property address if available
        var notes = "BMN Boston Appointment"
        if let staffName = appointment.staffName {
            notes += "\nWith: \(staffName)"
        }
        if let address = appointment.propertyAddress {
            notes += "\nProperty: \(address)"
            event.location = address
        }
        if let clientNotes = appointment.clientNotes, !clientNotes.isEmpty {
            notes += "\nNotes: \(clientNotes)"
        }
        notes += "\n\nConfirmation #\(appointment.id)"
        event.notes = notes

        // Add reminder 1 hour before
        let alarm = EKAlarm(relativeOffset: -3600)
        event.addAlarm(alarm)

        // Save event
        do {
            try eventStore.save(event, span: .thisEvent)
            return true
        } catch {
            lastError = error.localizedDescription
            return false
        }
    }

    /// Add a booking response to the calendar (for newly booked appointments)
    func addToCalendar(
        typeName: String,
        date: String,
        time: String,
        staffName: String?,
        propertyAddress: String?,
        appointmentId: Int
    ) async -> Bool {
        // Request access if needed
        if !hasAccess {
            let granted = await requestAccess()
            if !granted {
                lastError = "Calendar access denied. Please enable in Settings."
                return false
            }
        }

        // Parse date and time
        let dateFormatter = DateFormatter()
        dateFormatter.dateFormat = "yyyy-MM-dd"

        let timeFormatter = DateFormatter()
        timeFormatter.dateFormat = "HH:mm"

        guard let parsedDate = dateFormatter.date(from: date) else {
            lastError = "Could not parse date"
            return false
        }

        // Parse time - handle various formats
        var startHour = 9
        var startMinute = 0

        // Try parsing "2:00 PM" format first
        let displayTimeFormatter = DateFormatter()
        displayTimeFormatter.dateFormat = "h:mm a"
        if let displayTime = displayTimeFormatter.date(from: time) {
            let calendar = Calendar.current
            startHour = calendar.component(.hour, from: displayTime)
            startMinute = calendar.component(.minute, from: displayTime)
        } else if let militaryTime = timeFormatter.date(from: time) {
            // Try HH:mm format
            let calendar = Calendar.current
            startHour = calendar.component(.hour, from: militaryTime)
            startMinute = calendar.component(.minute, from: militaryTime)
        }

        // Combine date and time
        var calendar = Calendar.current
        calendar.timeZone = TimeZone(identifier: "America/New_York") ?? .current
        var components = calendar.dateComponents([.year, .month, .day], from: parsedDate)
        components.hour = startHour
        components.minute = startMinute

        guard let startDate = calendar.date(from: components) else {
            lastError = "Could not create start date"
            return false
        }

        // Default 30 minute duration
        let endDate = startDate.addingTimeInterval(30 * 60)

        // Create event
        let event = EKEvent(eventStore: eventStore)
        event.title = typeName
        event.startDate = startDate
        event.endDate = endDate
        event.calendar = eventStore.defaultCalendarForNewEvents

        // Add notes
        var notes = "BMN Boston Appointment"
        if let staffName = staffName {
            notes += "\nWith: \(staffName)"
        }
        if let address = propertyAddress {
            notes += "\nProperty: \(address)"
            event.location = address
        }
        notes += "\n\nConfirmation #\(appointmentId)"
        event.notes = notes

        // Add reminder 1 hour before
        let alarm = EKAlarm(relativeOffset: -3600)
        event.addAlarm(alarm)

        // Save event
        do {
            try eventStore.save(event, span: .thisEvent)
            return true
        } catch {
            lastError = error.localizedDescription
            return false
        }
    }

    // MARK: - Helpers

    private func calculateDuration(startTime: String, endTime: String) -> Int {
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
}
