//
//  AppointmentViewModel.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  ViewModel for appointment booking and management
//

import SwiftUI
import Combine

/// Filter for appointment list
enum AppointmentFilter: String, CaseIterable {
    case upcoming = "upcoming"
    case past = "past"
    case all = "all"

    var displayName: String {
        switch self {
        case .upcoming: return "Upcoming"
        case .past: return "Past"
        case .all: return "All"
        }
    }
}

/// Booking flow step
enum BookingStep: Int, CaseIterable {
    case selectType = 0
    case selectStaff = 1
    case selectDateTime = 2
    case enterDetails = 3
    case confirm = 4

    var title: String {
        switch self {
        case .selectType: return "Service"
        case .selectStaff: return "Agent"
        case .selectDateTime: return "Date & Time"
        case .enterDetails: return "Your Info"
        case .confirm: return "Confirm"
        }
    }
}

@MainActor
class AppointmentViewModel: ObservableObject {

    // MARK: - Appointments List State

    @Published var appointments: [Appointment] = []
    @Published var appointmentsLoading = false
    @Published var appointmentsError: String?
    @Published var selectedFilter: AppointmentFilter = .upcoming

    // MARK: - Booking Flow State

    @Published var appointmentTypes: [AppointmentType] = []
    @Published var staffMembers: [StaffMember] = []
    @Published var availability: AvailabilityResponse?
    @Published var portalPolicy: PortalPolicy?

    @Published var currentStep: BookingStep = .selectType
    @Published var selectedType: AppointmentType?
    @Published var selectedStaff: StaffMember?
    @Published var selectedDate: String?
    @Published var selectedTime: TimeSlot?

    // Guest/contact info
    @Published var clientName: String = ""
    @Published var clientEmail: String = ""
    @Published var clientPhone: String = ""
    @Published var notes: String = ""

    // Property context (for showings booked from property detail)
    @Published var listingId: String?
    @Published var propertyAddress: String?

    // Booking state
    @Published var isBooking = false
    @Published var bookingSuccess = false
    @Published var bookingResponse: BookingResponse?
    @Published var bookingError: String?

    // Loading states
    @Published var typesLoading = false
    @Published var staffLoading = false
    @Published var availabilityLoading = false

    // Cancel/Reschedule state
    @Published var isCancelling = false
    @Published var isRescheduling = false
    @Published var cancelError: String?
    @Published var rescheduleError: String?

    // Agent client selection (for booking on behalf of clients)
    @Published var agentClients: [AgentClient] = []
    @Published var agentClientsLoading = false
    // Multi-attendee support (v1.10.0)
    @Published var selectedClients: [AgentClient] = []
    @Published var ccEmails: [String] = []
    // Manual guest invites for non-agent users (v1.10.4)
    @Published var manualGuests: [AttendeeInput] = []

    // MARK: - Computed Properties

    var filteredAppointments: [Appointment] {
        switch selectedFilter {
        case .upcoming:
            return appointments.filter { $0.isUpcoming && $0.status != .cancelled }
        case .past:
            return appointments.filter { $0.isPast || $0.status == .completed }
        case .all:
            return appointments
        }
    }

    var canProceedToNextStep: Bool {
        switch currentStep {
        case .selectType:
            return selectedType != nil
        case .selectStaff:
            return selectedStaff != nil
        case .selectDateTime:
            return selectedDate != nil && selectedTime != nil
        case .enterDetails:
            return isContactInfoValid
        case .confirm:
            return true
        }
    }

    var isContactInfoValid: Bool {
        isNameValid && isEmailValid && isPhoneValid
    }

    var isNameValid: Bool {
        !clientName.trimmingCharacters(in: .whitespaces).isEmpty
    }

    var isEmailValid: Bool {
        let email = clientEmail.trimmingCharacters(in: .whitespaces)
        guard !email.isEmpty else { return false }

        // RFC 5322 compliant email regex
        let emailRegex = #"^[A-Z0-9a-z._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"#
        return email.range(of: emailRegex, options: .regularExpression) != nil
    }

    var isPhoneValid: Bool {
        let phone = clientPhone.trimmingCharacters(in: .whitespaces)
        guard !phone.isEmpty else { return false }

        // Remove common phone formatting characters
        let digitsOnly = phone.replacingOccurrences(of: "[^0-9]", with: "", options: .regularExpression)

        // US phone: 10 digits (or 11 with leading 1)
        return digitsOnly.count >= 10 && digitsOnly.count <= 11
    }

    var nameValidationError: String? {
        clientName.trimmingCharacters(in: .whitespaces).isEmpty ? "Name is required" : nil
    }

    var emailValidationError: String? {
        let email = clientEmail.trimmingCharacters(in: .whitespaces)
        if email.isEmpty { return "Email is required" }
        if !isEmailValid { return "Please enter a valid email address" }
        return nil
    }

    var phoneValidationError: String? {
        let phone = clientPhone.trimmingCharacters(in: .whitespaces)
        if phone.isEmpty { return "Phone is required" }
        if !isPhoneValid { return "Please enter a valid 10-digit phone number" }
        return nil
    }

    var availableDates: [String] {
        availability?.datesWithAvailability.compactMap { $0 } ?? []
    }

    func slotsForSelectedDate() -> [TimeSlot] {
        guard let date = selectedDate else { return [] }
        return availability?.slotsForDate(date) ?? []
    }

    // MARK: - Appointments List

    /// Load user's appointments
    func loadAppointments(forceRefresh: Bool = false) async {
        guard !appointmentsLoading else { return }

        appointmentsLoading = true
        appointmentsError = nil

        do {
            let fetchedAppointments = try await AppointmentService.shared.fetchUserAppointments(
                status: selectedFilter == .all ? nil : selectedFilter.rawValue,
                forceRefresh: forceRefresh
            )
            appointments = fetchedAppointments
            updateBadgeCount()
        } catch {
            appointmentsError = error.userFriendlyMessage
        }

        appointmentsLoading = false
    }

    /// Refresh appointments (for pull-to-refresh)
    func refreshAppointments() async {
        await loadAppointments(forceRefresh: true)
    }

    /// Update the badge count on the Appointments tab
    private func updateBadgeCount() {
        let count = appointments.filter { $0.isUpcoming && $0.status != .cancelled }.count
        AppointmentBadgeStore.shared.upcomingCount = count
    }

    // MARK: - Booking Flow

    /// Load appointment types
    func loadAppointmentTypes() async {
        guard !typesLoading else { return }

        typesLoading = true

        do {
            appointmentTypes = try await AppointmentService.shared.fetchAppointmentTypes()

            // Auto-select "Property Showing" type if property context is set
            if propertyAddress != nil,
               let showingType = appointmentTypes.first(where: { $0.slug == "property-showing" }) {
                selectedType = showingType
                currentStep = .selectStaff
                await loadStaff()
            }
        } catch {
            bookingError = error.userFriendlyMessage
        }

        typesLoading = false
    }

    /// Load staff members for selected type
    func loadStaff() async {
        guard !staffLoading else { return }

        staffLoading = true

        do {
            staffMembers = try await AppointmentService.shared.fetchStaff(forTypeId: selectedType?.id)

            // Auto-select if only one staff member or a primary staff
            if staffMembers.count == 1 {
                selectedStaff = staffMembers.first
            } else if let primary = staffMembers.first(where: { $0.isPrimary }) {
                selectedStaff = primary
            }
        } catch {
            bookingError = error.userFriendlyMessage
        }

        staffLoading = false
    }

    /// Load availability for selected type and staff
    func loadAvailability() async {
        guard let typeId = selectedType?.id else { return }
        guard !availabilityLoading else { return }

        availabilityLoading = true

        // Get date range (2 weeks from today)
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        let startDate = formatter.string(from: Date())
        let endDate = formatter.string(from: Calendar.current.date(byAdding: .day, value: 14, to: Date()) ?? Date())

        do {
            availability = try await AppointmentService.shared.fetchAvailability(
                startDate: startDate,
                endDate: endDate,
                typeId: typeId,
                staffId: selectedStaff?.id
            )
        } catch {
            bookingError = error.userFriendlyMessage
        }

        availabilityLoading = false
    }

    /// Load portal policy
    func loadPortalPolicy() async {
        do {
            portalPolicy = try await AppointmentService.shared.fetchPortalPolicy()
        } catch {
            // Policy is optional, don't show error
        }
    }

    /// Move to next step in booking flow
    func nextStep() {
        guard canProceedToNextStep else { return }

        switch currentStep {
        case .selectType:
            currentStep = .selectStaff
            Task { await loadStaff() }
        case .selectStaff:
            currentStep = .selectDateTime
            Task { await loadAvailability() }
        case .selectDateTime:
            currentStep = .enterDetails
        case .enterDetails:
            currentStep = .confirm
        case .confirm:
            break
        }
    }

    /// Move to previous step in booking flow
    func previousStep() {
        switch currentStep {
        case .selectType:
            break
        case .selectStaff:
            currentStep = .selectType
            selectedStaff = nil
        case .selectDateTime:
            currentStep = .selectStaff
            selectedDate = nil
            selectedTime = nil
        case .enterDetails:
            currentStep = .selectDateTime
        case .confirm:
            currentStep = .enterDetails
        }
    }

    /// Book the appointment
    func bookAppointment() async {
        guard let type = selectedType,
              let staff = selectedStaff,
              let date = selectedDate,
              let time = selectedTime else {
            bookingError = "Please complete all required fields"
            return
        }

        isBooking = true
        bookingError = nil

        // Build additional clients array from selected clients and manual guests (v1.10.4)
        var additionalClients: [AttendeeInput]? = nil
        var allAdditional: [AttendeeInput] = []
        if selectedClients.count > 1 {
            allAdditional += selectedClients.dropFirst().map { client in
                AttendeeInput(
                    name: client.displayName,
                    email: client.email,
                    phone: client.phone
                )
            }
        }
        if !manualGuests.isEmpty {
            allAdditional += manualGuests
        }
        if !allAdditional.isEmpty {
            additionalClients = allAdditional
        }

        let request = BookAppointmentRequest(
            appointmentTypeId: type.id,
            staffId: staff.id,
            date: date,
            time: time.value,
            clientName: clientName.trimmingCharacters(in: .whitespaces),
            clientEmail: clientEmail.trimmingCharacters(in: .whitespaces).lowercased(),
            clientPhone: clientPhone.trimmingCharacters(in: .whitespaces),
            listingId: listingId,
            propertyAddress: propertyAddress,
            notes: notes.isEmpty ? nil : notes,
            additionalClients: additionalClients,
            ccEmails: ccEmails.isEmpty ? nil : ccEmails
        )

        do {
            let response = try await AppointmentService.shared.bookAppointment(request: request)
            bookingResponse = response
            bookingSuccess = true
        } catch {
            bookingError = error.userFriendlyMessage
        }

        isBooking = false
    }

    /// Reset booking flow to start
    func resetBookingFlow() {
        currentStep = .selectType
        selectedType = nil
        selectedStaff = nil
        selectedDate = nil
        selectedTime = nil
        clientName = ""
        clientEmail = ""
        clientPhone = ""
        notes = ""
        listingId = nil
        propertyAddress = nil
        bookingSuccess = false
        bookingResponse = nil
        bookingError = nil
        availability = nil
        // Multi-attendee: clear selected clients, CC emails, and manual guests
        selectedClients = []
        ccEmails = []
        manualGuests = []
    }

    /// Pre-fill booking for property showing
    func preparePropertyShowing(listingId: String, address: String) {
        self.listingId = listingId
        self.propertyAddress = address

        // Auto-select "Property Showing" type if available
        if let showingType = appointmentTypes.first(where: { $0.slug == "property-showing" }) {
            selectedType = showingType
            currentStep = .selectStaff
            Task { await loadStaff() }
        }
    }

    // MARK: - Cancel & Reschedule

    /// Cancel an appointment with optimistic UI update
    func cancelAppointment(_ appointment: Appointment, reason: String?) async -> Bool {
        isCancelling = true
        cancelError = nil

        // Optimistic update: remove from local list immediately
        let originalAppointments = appointments
        appointments.removeAll { $0.id == appointment.id }

        do {
            try await AppointmentService.shared.cancelAppointment(id: appointment.id, reason: reason)
            // Background sync to ensure consistency
            Task {
                await loadAppointments(forceRefresh: true)
            }
            isCancelling = false
            return true
        } catch {
            // Restore original list on failure
            appointments = originalAppointments
            cancelError = error.userFriendlyMessage
            isCancelling = false
            return false
        }
    }

    /// Reschedule an appointment with optimistic UI update
    func rescheduleAppointment(_ appointment: Appointment, newDate: String, newTime: String) async -> Bool {
        isRescheduling = true
        rescheduleError = nil

        // Optimistic update: update local appointment immediately
        let originalAppointments = appointments
        if let index = appointments.firstIndex(where: { $0.id == appointment.id }) {
            // Create updated appointment with new date/time
            // We'll just mark it as modified for now, the background sync will get the real data
            appointments[index] = appointment.withUpdatedDateTime(date: newDate, time: newTime)
        }

        do {
            try await AppointmentService.shared.rescheduleAppointment(
                id: appointment.id,
                newDate: newDate,
                newTime: newTime
            )
            // Background sync to ensure consistency
            Task {
                await loadAppointments(forceRefresh: true)
            }
            isRescheduling = false
            return true
        } catch {
            // Restore original list on failure
            appointments = originalAppointments
            rescheduleError = error.userFriendlyMessage
            isRescheduling = false
            return false
        }
    }

    /// Load reschedule slots for an appointment
    func loadRescheduleSlots(for appointment: Appointment) async -> AvailabilityResponse? {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        let startDate = formatter.string(from: Date())
        let endDate = formatter.string(from: Calendar.current.date(byAdding: .day, value: 14, to: Date()) ?? Date())

        do {
            return try await AppointmentService.shared.fetchRescheduleSlots(
                appointmentId: appointment.id,
                startDate: startDate,
                endDate: endDate
            )
        } catch {
            rescheduleError = error.userFriendlyMessage
            return nil
        }
    }

    // MARK: - Prefill User Info

    /// Pre-fill contact info from logged-in user
    func prefillUserInfo(from user: User?) {
        guard let user = user else { return }
        clientName = user.displayName ?? "\(user.firstName ?? "") \(user.lastName ?? "")".trimmingCharacters(in: .whitespaces)
        clientEmail = user.email ?? ""
        // Phone would need to be added to User model if available
    }

    // MARK: - Agent Client Management

    /// Load agent's client list (for booking on behalf of clients)
    func loadAgentClients() async {
        agentClientsLoading = true
        defer { agentClientsLoading = false }

        do {
            let response: AgentClientListResponse = try await APIClient.shared.request(.agentClients)
            agentClients = response.clients
        } catch {
            // Silently fail - user may not be an agent
            agentClients = []
        }
    }

    /// Toggle client selection (multi-select support v1.10.0)
    /// First selected client becomes primary and prefills contact info
    func selectClient(_ client: AgentClient?) {
        guard let client = client else {
            // Deselect all - user selected "Myself"
            selectedClients = []
            return
        }

        // Toggle selection
        if let index = selectedClients.firstIndex(where: { $0.id == client.id }) {
            selectedClients.remove(at: index)
        } else {
            selectedClients.append(client)
        }

        // Prefill contact info from primary (first selected) client
        if let primary = selectedClients.first {
            clientName = primary.displayName
            clientEmail = primary.email
            clientPhone = primary.phone ?? ""
        }
    }

    /// Check if a client is selected
    func isClientSelected(_ client: AgentClient) -> Bool {
        selectedClients.contains { $0.id == client.id }
    }

    /// Clear all selected clients
    func clearSelectedClients() {
        selectedClients = []
        // Don't clear the fields - user may have edited them
    }

    // MARK: - Manual Guest Management (v1.10.4)

    /// Add a manual guest (for non-agent users)
    func addManualGuest(name: String, email: String) {
        let trimmedName = name.trimmingCharacters(in: .whitespaces)
        let trimmedEmail = email.trimmingCharacters(in: .whitespaces).lowercased()
        guard !trimmedName.isEmpty, !trimmedEmail.isEmpty else { return }
        // Basic email validation
        let emailRegex = #"^[A-Z0-9a-z._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"#
        guard trimmedEmail.range(of: emailRegex, options: .regularExpression) != nil else { return }
        // Prevent duplicates
        guard !manualGuests.contains(where: { $0.email == trimmedEmail }) else { return }
        manualGuests.append(AttendeeInput(name: trimmedName, email: trimmedEmail, phone: nil))
    }

    /// Remove a manual guest by index
    func removeManualGuest(at index: Int) {
        guard index >= 0 && index < manualGuests.count else { return }
        manualGuests.remove(at: index)
    }

    // MARK: - CC Email Management (v1.10.0)

    /// Add a CC email address
    func addCCEmail(_ email: String) {
        let trimmedEmail = email.trimmingCharacters(in: .whitespaces).lowercased()
        guard !trimmedEmail.isEmpty, !ccEmails.contains(trimmedEmail) else { return }
        // Basic email validation
        let emailRegex = #"^[A-Z0-9a-z._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$"#
        guard trimmedEmail.range(of: emailRegex, options: .regularExpression) != nil else { return }
        ccEmails.append(trimmedEmail)
    }

    /// Remove a CC email address
    func removeCCEmail(_ email: String) {
        ccEmails.removeAll { $0 == email }
    }
}
