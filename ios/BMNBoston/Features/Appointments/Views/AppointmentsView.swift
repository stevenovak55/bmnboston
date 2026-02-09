//
//  AppointmentsView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Main appointments list view
//

import SwiftUI

struct AppointmentsView: View {
    @StateObject private var viewModel = AppointmentViewModel()
    @EnvironmentObject var authViewModel: AuthViewModel
    @ObservedObject private var notificationStore = NotificationStore.shared
    @State private var showBookingSheet = false
    @State private var selectedAppointment: Appointment?
    @State private var showCancelConfirmation = false
    @State private var cancelReason = ""
    @State private var pendingNavigationAppointmentId: Int?

    var body: some View {
        NavigationStack {
            Group {
                if !authViewModel.isAuthenticated {
                    guestPromptView
                } else {
                    appointmentsContent
                }
            }
            .navigationTitle("Appointments")
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    Button {
                        showBookingSheet = true
                    } label: {
                        Image(systemName: "plus")
                    }
                    .accessibilityLabel("Book new appointment")
                    .accessibilityHint("Double tap to schedule a new appointment")
                }
            }
            .sheet(isPresented: $showBookingSheet) {
                BookAppointmentView(viewModel: viewModel)
            }
            .sheet(item: $selectedAppointment) { appointment in
                AppointmentDetailSheet(
                    appointment: appointment,
                    viewModel: viewModel,
                    onDismiss: { selectedAppointment = nil }
                )
            }
        }
        .task {
            if authViewModel.isAuthenticated {
                await viewModel.loadAppointments()
                await viewModel.loadAppointmentTypes()
            }
        }
        .onAppear {
            checkPendingAppointmentNavigation()
        }
        .onChange(of: notificationStore.pendingAppointmentId) { _ in
            checkPendingAppointmentNavigation()
        }
        .onChange(of: viewModel.appointments.count) { _ in
            // If we have a pending navigation and appointments just loaded, try again
            if pendingNavigationAppointmentId != nil {
                checkPendingAppointmentNavigation()
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToAppointmentsTab)) { _ in
            // Small delay to ensure tab switch animation completes
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                checkPendingAppointmentNavigation()
            }
        }
    }

    // MARK: - Pending Navigation

    /// Checks for pending appointment navigation from push notification and navigates to the specific appointment
    private func checkPendingAppointmentNavigation() {
        guard let appointmentId = notificationStore.pendingAppointmentId else { return }

        #if DEBUG
        print("📍 AppointmentsView: Checking pending appointment navigation - appointmentId: \(appointmentId)")
        #endif

        // Clear the pending state immediately to prevent duplicate navigation
        notificationStore.clearPendingAppointmentNavigation()

        // Store locally in case appointments haven't loaded yet
        pendingNavigationAppointmentId = appointmentId

        // Try to find and select the appointment
        if let appointment = viewModel.appointments.first(where: { $0.id == appointmentId }) {
            #if DEBUG
            print("📍 AppointmentsView: Found appointment, selecting it")
            #endif
            pendingNavigationAppointmentId = nil
            selectedAppointment = appointment
        } else {
            #if DEBUG
            print("📍 AppointmentsView: Appointment not found in list, will retry when appointments load")
            #endif
            // Refresh appointments to ensure we have the latest data
            Task {
                await viewModel.loadAppointments(forceRefresh: true)
                // After refresh, try to find the appointment again
                if let appointment = viewModel.appointments.first(where: { $0.id == appointmentId }) {
                    await MainActor.run {
                        pendingNavigationAppointmentId = nil
                        selectedAppointment = appointment
                    }
                } else {
                    #if DEBUG
                    print("📍 AppointmentsView: Appointment \(appointmentId) not found even after refresh")
                    #endif
                    await MainActor.run {
                        pendingNavigationAppointmentId = nil
                    }
                }
            }
        }
    }

    // MARK: - Guest Prompt

    private var guestPromptView: some View {
        VStack(spacing: 24) {
            Image(systemName: "calendar.badge.clock")
                .font(.system(size: 60))
                .foregroundStyle(.secondary)

            Text("Sign In to View Appointments")
                .font(.title2)
                .fontWeight(.semibold)

            Text("Log in to see your upcoming appointments, book new showings, and manage your schedule.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)

            Button {
                showBookingSheet = true
            } label: {
                Label("Book as Guest", systemImage: "calendar.badge.plus")
                    .font(.headline)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .accessibilityLabel("Book as Guest")
            .accessibilityHint("Double tap to book an appointment without signing in")
            .padding(.horizontal, 32)
        }
        .padding()
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Sign in required. Log in to see your appointments or book as a guest.")
    }

    // MARK: - Appointments Content

    private var appointmentsContent: some View {
        VStack(spacing: 0) {
            // Filter picker
            filterPicker

            if viewModel.appointmentsLoading && viewModel.appointments.isEmpty {
                loadingView
            } else if let error = viewModel.appointmentsError {
                errorView(error)
            } else if viewModel.filteredAppointments.isEmpty {
                emptyStateView
            } else {
                appointmentsList
            }
        }
    }

    private var filterPicker: some View {
        Picker("Filter", selection: $viewModel.selectedFilter) {
            ForEach(AppointmentFilter.allCases, id: \.self) { filter in
                Text(filter.displayName).tag(filter)
            }
        }
        .pickerStyle(.segmented)
        .padding()
        .accessibilityLabel("Appointment filter")
        .accessibilityValue(viewModel.selectedFilter.displayName)
        .accessibilityHint("Double tap to switch between upcoming and past appointments")
        .onChange(of: viewModel.selectedFilter) { _ in
            Task {
                await viewModel.loadAppointments(forceRefresh: true)
            }
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .accessibilityHidden(true)
            Text("Loading appointments...")
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Loading appointments")
    }

    private func errorView(_ error: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 40))
                .foregroundStyle(.orange)
                .accessibilityHidden(true)

            Text(error)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            Button("Try Again") {
                Task {
                    await viewModel.loadAppointments(forceRefresh: true)
                }
            }
            .buttonStyle(.bordered)
            .accessibilityLabel("Try again")
            .accessibilityHint("Double tap to retry loading appointments")
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Error loading appointments. \(error)")
    }

    private var emptyStateView: some View {
        VStack(spacing: 16) {
            Image(systemName: viewModel.selectedFilter == .upcoming ? "calendar" : "clock.arrow.circlepath")
                .font(.system(size: 50))
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)

            Text(viewModel.selectedFilter == .upcoming ?
                 "No Upcoming Appointments" :
                 "No Past Appointments")
                .font(.title3)
                .fontWeight(.semibold)

            Text(viewModel.selectedFilter == .upcoming ?
                 "Book a showing or consultation to get started." :
                 "Your appointment history will appear here.")
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            if viewModel.selectedFilter == .upcoming {
                Button {
                    showBookingSheet = true
                } label: {
                    Label("Book Appointment", systemImage: "plus")
                }
                .buttonStyle(.borderedProminent)
                .tint(AppColors.brandTeal)
                .accessibilityLabel("Book appointment")
                .accessibilityHint("Double tap to schedule a new appointment")
            }
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityElement(children: .contain)
        .accessibilityLabel(viewModel.selectedFilter == .upcoming ?
            "No upcoming appointments. Book a showing or consultation to get started." :
            "No past appointments. Your appointment history will appear here.")
    }

    private var appointmentsList: some View {
        List {
            ForEach(viewModel.filteredAppointments) { appointment in
                AppointmentRow(appointment: appointment)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        selectedAppointment = appointment
                    }
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("\(appointment.typeName) on \(appointment.formattedDate) at \(appointment.formattedTime). Status: \(appointment.status.displayName)\(appointment.hasMultipleAttendees ? ". \(appointment.attendeeCount ?? 0) attendees" : "")")
                    .accessibilityHint("Double tap to view appointment details")
            }
        }
        .listStyle(.plain)
        .refreshable {
            await viewModel.refreshAppointments()
        }
        .accessibilityLabel("Appointments list")
    }
}

// MARK: - Appointment Row

struct AppointmentRow: View {
    let appointment: Appointment

    var body: some View {
        HStack(spacing: 12) {
            // Color indicator
            RoundedRectangle(cornerRadius: 4)
                .fill(appointment.statusColor)
                .frame(width: 4)
                .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 4) {
                // Type and status
                HStack {
                    Text(appointment.typeName)
                        .font(.headline)

                    Spacer()

                    StatusBadge(status: appointment.status)
                }

                // Date and time
                HStack {
                    Image(systemName: "calendar")
                        .foregroundStyle(.secondary)
                    Text(appointment.formattedDate)
                        .foregroundStyle(.secondary)
                }
                .font(.subheadline)

                HStack {
                    Image(systemName: "clock")
                        .foregroundStyle(.secondary)
                    Text(appointment.formattedTime)
                        .foregroundStyle(.secondary)
                }
                .font(.subheadline)

                // Staff name
                if let staffName = appointment.staffName {
                    HStack {
                        Image(systemName: "person")
                            .foregroundStyle(.secondary)
                        Text(staffName)
                            .foregroundStyle(.secondary)
                    }
                    .font(.subheadline)
                }

                // Property address
                if let address = appointment.propertyAddress {
                    HStack {
                        Image(systemName: "house")
                            .foregroundStyle(appointment.listingId != nil ? AppColors.brandTeal : .secondary)
                        Text(address)
                            .foregroundStyle(appointment.listingId != nil ? AppColors.brandTeal : .secondary)
                            .lineLimit(1)
                        if appointment.listingId != nil {
                            Image(systemName: "chevron.right")
                                .font(.caption2)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                    .font(.subheadline)
                }

                // Multi-attendee indicator (v1.10.0)
                if appointment.hasMultipleAttendees, let count = appointment.attendeeCount {
                    HStack {
                        Image(systemName: "person.2")
                            .foregroundStyle(.secondary)
                        Text("\(count) attendees")
                            .foregroundStyle(.secondary)
                    }
                    .font(.subheadline)
                }
            }

            Image(systemName: "chevron.right")
                .font(.caption)
                .foregroundStyle(.tertiary)
                .accessibilityHidden(true)
        }
        .padding(.vertical, 8)
    }
}

// MARK: - Status Badge

struct StatusBadge: View {
    let status: AppointmentStatus

    var body: some View {
        Text(status.displayName)
            .font(.caption)
            .fontWeight(.medium)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(backgroundColor)
            .foregroundStyle(foregroundColor)
            .clipShape(Capsule())
            .accessibilityLabel("Status: \(status.displayName)")
    }

    private var backgroundColor: Color {
        switch status {
        case .pending: return .orange.opacity(0.2)
        case .confirmed: return .green.opacity(0.2)
        case .cancelled: return .red.opacity(0.2)
        case .completed: return .blue.opacity(0.2)
        case .noShow: return .gray.opacity(0.2)
        }
    }

    private var foregroundColor: Color {
        switch status {
        case .pending: return .orange
        case .confirmed: return .green
        case .cancelled: return .red
        case .completed: return .blue
        case .noShow: return .gray
        }
    }
}

// MARK: - Appointment Detail Sheet

struct AppointmentDetailSheet: View {
    let appointment: Appointment
    @ObservedObject var viewModel: AppointmentViewModel
    let onDismiss: () -> Void

    @State private var showCancelSheet = false
    @State private var showRescheduleSheet = false
    @State private var cancelReason = ""
    @State private var cancelError: String?
    @State private var isCancelling = false

    // Calendar integration
    @State private var addedToCalendar = false
    @State private var calendarError: String?
    @State private var isAddingToCalendar = false

    // Directions
    @State private var showDirectionsSheet = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    // Header
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text(appointment.typeName)
                                .font(.title2)
                                .fontWeight(.bold)

                            Spacer()

                            StatusBadge(status: appointment.status)
                        }

                        if let address = appointment.propertyAddress {
                            if let listingId = appointment.listingId {
                                Button {
                                    // listing_id from appointments API is actually a listing_key (hash)
                                    onDismiss()
                                    DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                                        NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
                                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                                            NotificationStore.shared.setPendingPropertyNavigation(listingId: nil, listingKey: listingId)
                                        }
                                    }
                                } label: {
                                    HStack {
                                        Image(systemName: "house")
                                        Text(address)
                                            .multilineTextAlignment(.leading)
                                        Spacer()
                                        Image(systemName: "chevron.right")
                                            .font(.caption)
                                    }
                                    .foregroundStyle(AppColors.brandTeal)
                                }
                            } else {
                                Label(address, systemImage: "house")
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                    .padding()
                    .background(Color(.systemGray6))
                    .clipShape(RoundedRectangle(cornerRadius: 12))

                    // Get Directions
                    if appointment.propertyAddress != nil {
                        Button {
                            showDirectionsSheet = true
                        } label: {
                            Label("Get Directions", systemImage: "arrow.triangle.turn.up.right.diamond.fill")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                    }

                    // Details
                    VStack(spacing: 16) {
                        DetailRow(icon: "calendar", title: "Date", value: appointment.formattedDate)
                        DetailRow(icon: "clock", title: "Time", value: appointment.formattedTime)

                        if let staffName = appointment.staffName {
                            DetailRow(icon: "person", title: "Agent", value: staffName)
                        }

                        if let notes = appointment.clientNotes, !notes.isEmpty {
                            DetailRow(icon: "note.text", title: "Notes", value: notes)
                        }

                        if let rescheduleCount = appointment.rescheduleCount, rescheduleCount > 0 {
                            DetailRow(
                                icon: "arrow.triangle.2.circlepath",
                                title: "Rescheduled",
                                value: "\(rescheduleCount) time\(rescheduleCount > 1 ? "s" : "")"
                            )
                        }
                    }
                    .padding()
                    .background(Color(.systemGray6))
                    .clipShape(RoundedRectangle(cornerRadius: 12))

                    // Attendees section (v1.10.0 - Multi-Attendee Support)
                    if let attendees = appointment.attendees, attendees.count > 1 {
                        VStack(alignment: .leading, spacing: 12) {
                            HStack {
                                Image(systemName: "person.2.fill")
                                    .foregroundStyle(AppColors.brandTeal)
                                Text("Attendees (\(attendees.count))")
                                    .font(.headline)
                            }

                            ForEach(attendees) { attendee in
                                HStack(alignment: .top, spacing: 12) {
                                    // Attendee type indicator
                                    Circle()
                                        .fill(attendee.type == .primary ? AppColors.brandTeal : Color(.systemGray4))
                                        .frame(width: 8, height: 8)
                                        .padding(.top, 6)

                                    VStack(alignment: .leading, spacing: 2) {
                                        HStack {
                                            Text(attendee.displayName)
                                                .font(.subheadline)
                                                .fontWeight(attendee.type == .primary ? .semibold : .regular)

                                            if attendee.type == .primary {
                                                Text("Primary")
                                                    .font(.caption2)
                                                    .foregroundStyle(.white)
                                                    .padding(.horizontal, 6)
                                                    .padding(.vertical, 2)
                                                    .background(AppColors.brandTeal)
                                                    .clipShape(Capsule())
                                            } else if attendee.type == .cc {
                                                Text("CC")
                                                    .font(.caption2)
                                                    .foregroundStyle(.secondary)
                                                    .padding(.horizontal, 6)
                                                    .padding(.vertical, 2)
                                                    .background(Color(.systemGray5))
                                                    .clipShape(Capsule())
                                            }
                                        }

                                        if attendee.type != .cc {
                                            Text(attendee.email)
                                                .font(.caption)
                                                .foregroundStyle(.secondary)

                                            if let phone = attendee.phone, !phone.isEmpty {
                                                Text(phone)
                                                    .font(.caption)
                                                    .foregroundStyle(.secondary)
                                            }
                                        }
                                    }

                                    Spacer()
                                }
                            }
                        }
                        .padding()
                        .background(Color(.systemGray6))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    }

                    // Actions
                    if appointment.status != .cancelled && appointment.status != .completed {
                        VStack(spacing: 12) {
                            if appointment.canReschedule {
                                Button {
                                    showRescheduleSheet = true
                                } label: {
                                    Label("Reschedule", systemImage: "calendar.badge.clock")
                                        .frame(maxWidth: .infinity)
                                }
                                .buttonStyle(.bordered)
                                .accessibilityLabel("Reschedule appointment")
                                .accessibilityHint("Double tap to choose a new date and time")
                            }

                            if appointment.canCancel {
                                Button(role: .destructive) {
                                    showCancelSheet = true
                                } label: {
                                    Label("Cancel Appointment", systemImage: "xmark.circle")
                                        .frame(maxWidth: .infinity)
                                }
                                .buttonStyle(.bordered)
                                .accessibilityLabel("Cancel appointment")
                                .accessibilityHint("Double tap to cancel this appointment")
                            }
                        }
                    }

                    // Add to Calendar
                    if !addedToCalendar {
                        Button {
                            isAddingToCalendar = true
                            Task {
                                let success = await CalendarService.shared.addToCalendar(appointment: appointment)
                                isAddingToCalendar = false
                                if success {
                                    addedToCalendar = true
                                } else {
                                    calendarError = CalendarService.shared.lastError
                                }
                            }
                        } label: {
                            HStack {
                                if isAddingToCalendar {
                                    ProgressView()
                                        .tint(.primary)
                                        .accessibilityHidden(true)
                                } else {
                                    Image(systemName: "calendar.badge.plus")
                                }
                                Text("Add to Apple Calendar")
                            }
                            .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .disabled(isAddingToCalendar)
                        .accessibilityLabel(isAddingToCalendar ? "Adding to calendar" : "Add to Apple Calendar")
                        .accessibilityHint(isAddingToCalendar ? "" : "Double tap to add this appointment to your calendar")

                        if let error = calendarError {
                            Text(error)
                                .font(.caption)
                                .foregroundStyle(.red)
                                .accessibilityLabel("Calendar error: \(error)")
                        }
                    } else {
                        HStack {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundStyle(.green)
                                .accessibilityHidden(true)
                            Text("Added to Calendar")
                                .foregroundStyle(.secondary)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 8)
                        .accessibilityElement(children: .combine)
                        .accessibilityLabel("Successfully added to calendar")
                    }

                    Spacer()
                }
                .padding()
            }
            .navigationTitle("Appointment Details")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") {
                        onDismiss()
                    }
                    .accessibilityLabel("Done")
                    .accessibilityHint("Double tap to close appointment details")
                }
            }
            .sheet(isPresented: $showCancelSheet) {
                CancelAppointmentSheet(
                    appointment: appointment,
                    viewModel: viewModel,
                    onComplete: {
                        // First dismiss the cancel sheet
                        showCancelSheet = false
                        // Then dismiss the parent detail sheet after animation
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                            onDismiss()
                        }
                    }
                )
            }
            .sheet(isPresented: $showRescheduleSheet) {
                RescheduleSheet(
                    appointment: appointment,
                    viewModel: viewModel,
                    onComplete: {
                        // First dismiss the reschedule sheet
                        showRescheduleSheet = false
                        // Then dismiss the parent detail sheet after animation
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                            onDismiss()
                        }
                    }
                )
            }
            .confirmationDialog("Get Directions", isPresented: $showDirectionsSheet) {
                Button("Apple Maps") { openInAppleMaps() }
                Button("Google Maps") { openInGoogleMaps() }
                Button("Cancel", role: .cancel) { }
            }
        }
    }

    // MARK: - Directions

    private func openInAppleMaps() {
        guard let address = appointment.propertyAddress else { return }
        let encoded = address.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? address
        if let url = URL(string: "https://maps.apple.com/?daddr=\(encoded)") {
            UIApplication.shared.open(url)
        }
    }

    private func openInGoogleMaps() {
        guard let address = appointment.propertyAddress else { return }
        let encoded = address.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? address

        // Try Google Maps app first
        if let url = URL(string: "comgooglemaps://?daddr=\(encoded)&directionsmode=driving"),
           UIApplication.shared.canOpenURL(url) {
            UIApplication.shared.open(url)
        } else {
            // Fallback to web
            if let url = URL(string: "https://www.google.com/maps/dir/?api=1&destination=\(encoded)") {
                UIApplication.shared.open(url)
            }
        }
    }
}

// MARK: - Detail Row

struct DetailRow: View {
    let icon: String
    let title: String
    let value: String

    var body: some View {
        HStack(alignment: .top) {
            Image(systemName: icon)
                .frame(width: 24)
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)

            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Text(value)
            }

            Spacer()
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(title): \(value)")
    }
}

// MARK: - Cancel Appointment Sheet

struct CancelAppointmentSheet: View {
    let appointment: Appointment
    @ObservedObject var viewModel: AppointmentViewModel
    let onComplete: () -> Void

    @State private var reason = ""
    @State private var isCancelling = false
    @State private var error: String?

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 20) {
                // Warning header
                HStack(spacing: 12) {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(.title)
                        .foregroundStyle(.orange)
                        .accessibilityHidden(true)

                    VStack(alignment: .leading, spacing: 4) {
                        Text("Cancel Appointment")
                            .font(.headline)
                        Text("This action cannot be undone")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color.orange.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .accessibilityElement(children: .combine)
                .accessibilityLabel("Warning: Cancel appointment. This action cannot be undone.")

                // Appointment info
                VStack(alignment: .leading, spacing: 8) {
                    Text(appointment.typeName)
                        .font(.headline)
                    Text("\(appointment.formattedDate) at \(appointment.formattedTime)")
                        .foregroundStyle(.secondary)
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.systemGray6))
                .clipShape(RoundedRectangle(cornerRadius: 12))

                // Reason input
                VStack(alignment: .leading, spacing: 8) {
                    Text("Reason for cancellation")
                        .font(.headline)
                    Text("Optional - helps us improve our service")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("Enter reason...", text: $reason, axis: .vertical)
                        .textFieldStyle(.roundedBorder)
                        .lineLimit(3...6)
                }

                // Error message
                if let error = error {
                    Text(error)
                        .font(.subheadline)
                        .foregroundStyle(.red)
                        .padding()
                        .frame(maxWidth: .infinity)
                        .background(Color.red.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                }

                Spacer()

                // Action buttons
                VStack(spacing: 12) {
                    Button(role: .destructive) {
                        Task { await cancelAppointment() }
                    } label: {
                        if isCancelling {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                                .accessibilityHidden(true)
                        } else {
                            Text("Cancel Appointment")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.red)
                    .disabled(isCancelling)
                    .accessibilityLabel(isCancelling ? "Cancelling appointment" : "Cancel appointment")
                    .accessibilityHint(isCancelling ? "" : "Double tap to confirm cancellation")

                    Button("Keep Appointment") {
                        dismiss()
                    }
                    .buttonStyle(.bordered)
                    .disabled(isCancelling)
                    .accessibilityLabel("Keep appointment")
                    .accessibilityHint("Double tap to go back without cancelling")
                }
            }
            .padding()
            .navigationTitle("Cancel Appointment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Close") { dismiss() }
                        .disabled(isCancelling)
                        .accessibilityLabel("Close")
                        .accessibilityHint("Double tap to close without cancelling")
                }
            }
        }
    }

    private func cancelAppointment() async {
        isCancelling = true
        error = nil

        let success = await viewModel.cancelAppointment(
            appointment,
            reason: reason.isEmpty ? nil : reason
        )

        if success {
            onComplete()
        } else {
            error = viewModel.cancelError ?? "Failed to cancel appointment"
            isCancelling = false
        }
    }
}

// MARK: - Reschedule Sheet

struct RescheduleSheet: View {
    let appointment: Appointment
    @ObservedObject var viewModel: AppointmentViewModel
    let onComplete: () -> Void

    @State private var availability: AvailabilityResponse?
    @State private var selectedDate: String?
    @State private var selectedTime: TimeSlot?
    @State private var isLoading = true
    @State private var error: String?

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    ProgressView("Loading available times...")
                        .accessibilityLabel("Loading available times")
                } else if let error = error {
                    VStack(spacing: 16) {
                        Image(systemName: "exclamationmark.triangle")
                            .font(.largeTitle)
                            .foregroundStyle(.orange)
                            .accessibilityHidden(true)
                        Text(error)
                            .multilineTextAlignment(.center)
                        Button("Try Again") {
                            Task { await loadAvailability() }
                        }
                        .buttonStyle(.bordered)
                        .accessibilityLabel("Try again")
                        .accessibilityHint("Double tap to retry loading available times")
                    }
                    .padding()
                    .accessibilityElement(children: .contain)
                    .accessibilityLabel("Error loading availability. \(error)")
                } else if let availability = availability {
                    rescheduleContent(availability)
                }
            }
            .navigationTitle("Reschedule")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") { dismiss() }
                        .disabled(viewModel.isRescheduling)
                        .accessibilityLabel("Cancel")
                        .accessibilityHint("Double tap to close without rescheduling")
                }
                ToolbarItem(placement: .topBarTrailing) {
                    if viewModel.isRescheduling {
                        ProgressView()
                            .accessibilityLabel("Rescheduling appointment")
                    } else {
                        Button("Confirm") {
                            Task { await confirmReschedule() }
                        }
                        .disabled(selectedDate == nil || selectedTime == nil)
                        .accessibilityLabel("Confirm reschedule")
                        .accessibilityHint(selectedDate != nil && selectedTime != nil ?
                            "Double tap to reschedule to the selected date and time" :
                            "Select a date and time first")
                    }
                }
            }
            .disabled(viewModel.isRescheduling)
        }
        .task {
            await loadAvailability()
        }
    }

    private func rescheduleContent(_ availability: AvailabilityResponse) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Current appointment
                VStack(alignment: .leading, spacing: 8) {
                    Text("Current Appointment")
                        .font(.headline)
                    Text("\(appointment.formattedDate) at \(appointment.formattedTime)")
                        .foregroundStyle(.secondary)
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.systemGray6))
                .clipShape(RoundedRectangle(cornerRadius: 12))

                // Date selection
                VStack(alignment: .leading, spacing: 12) {
                    Text("Select New Date")
                        .font(.headline)

                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(availability.datesWithAvailability, id: \.self) { date in
                                DateButton(
                                    date: date,
                                    isSelected: selectedDate == date
                                ) {
                                    selectedDate = date
                                    selectedTime = nil
                                }
                            }
                        }
                    }
                }

                // Time selection
                if let date = selectedDate {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("Select New Time")
                            .font(.headline)

                        let slots = availability.slotsForDate(date)
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 80))], spacing: 8) {
                            ForEach(slots) { slot in
                                TimeButton(
                                    slot: slot,
                                    isSelected: selectedTime?.value == slot.value
                                ) {
                                    selectedTime = slot
                                }
                            }
                        }
                    }
                }
            }
            .padding()
        }
    }

    private func loadAvailability() async {
        isLoading = true
        error = nil
        availability = await viewModel.loadRescheduleSlots(for: appointment)
        if availability == nil {
            error = viewModel.rescheduleError ?? "Failed to load availability"
        }
        isLoading = false
    }

    private func confirmReschedule() async {
        guard let date = selectedDate, let time = selectedTime else { return }
        let success = await viewModel.rescheduleAppointment(appointment, newDate: date, newTime: time.value)
        if success {
            onComplete()
        } else {
            // Show error to user
            error = viewModel.rescheduleError ?? "Failed to reschedule appointment"
        }
    }
}

// MARK: - Date Button

struct DateButton: View {
    let date: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 2) {
                // Today indicator
                if isToday {
                    Text("TODAY")
                        .font(.system(size: 8, weight: .bold))
                        .foregroundStyle(isSelected ? .white : AppColors.brandTeal)
                } else {
                    Text(dayOfWeek)
                        .font(.caption)
                }

                Text(dayNumber)
                    .font(.headline)
                Text(month)
                    .font(.caption2)
            }
            .frame(width: 60, height: 70)
            .background(
                isSelected ? AppColors.brandTeal :
                isToday ? AppColors.brandTeal.opacity(0.15) : Color(.systemGray5)
            )
            .foregroundStyle(isSelected ? .white : .primary)
            .clipShape(RoundedRectangle(cornerRadius: 8))
            .overlay(
                RoundedRectangle(cornerRadius: 8)
                    .stroke(isToday && !isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
            )
        }
        .accessibilityLabel("\(dayOfWeek), \(month) \(dayNumber)\(isToday ? ", Today" : "")\(isSelected ? ", selected" : "")")
        .accessibilityHint("Double tap to select this date")
        .accessibilityAddTraits(isSelected ? [.isSelected] : [])
    }

    private var parsedDate: Date {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter.date(from: date) ?? Date()
    }

    private var isToday: Bool {
        Calendar.current.isDateInToday(parsedDate)
    }

    private var dayOfWeek: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "EEE"
        return formatter.string(from: parsedDate)
    }

    private var dayNumber: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "d"
        return formatter.string(from: parsedDate)
    }

    private var month: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "MMM"
        return formatter.string(from: parsedDate)
    }
}

// MARK: - Time Button

struct TimeButton: View {
    let slot: TimeSlot
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(slot.formattedTime)
                .font(.subheadline)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .frame(minWidth: 80)
                .background(isSelected ? AppColors.brandTeal : Color(.systemGray5))
                .foregroundStyle(isSelected ? .white : .primary)
                .clipShape(RoundedRectangle(cornerRadius: 8))
        }
        .accessibilityLabel("\(slot.formattedTime)\(isSelected ? ", selected" : "")")
        .accessibilityHint("Double tap to select this time")
        .accessibilityAddTraits(isSelected ? [.isSelected] : [])
    }
}

#Preview {
    AppointmentsView()
        .environmentObject(AuthViewModel())
}
