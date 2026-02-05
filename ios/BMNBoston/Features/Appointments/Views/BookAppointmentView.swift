//
//  BookAppointmentView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Multi-step appointment booking flow
//

import SwiftUI

struct BookAppointmentView: View {
    @ObservedObject var viewModel: AppointmentViewModel
    @EnvironmentObject var authViewModel: AuthViewModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // Progress indicator
                BookingProgressView(currentStep: viewModel.currentStep)
                    .padding()

                // Step content
                Group {
                    switch viewModel.currentStep {
                    case .selectType:
                        TypeSelectionView(viewModel: viewModel)
                    case .selectStaff:
                        StaffSelectionView(viewModel: viewModel)
                    case .selectDateTime:
                        DateTimeSelectionView(viewModel: viewModel)
                    case .enterDetails:
                        ContactDetailsView(viewModel: viewModel, authViewModel: authViewModel)
                    case .confirm:
                        ConfirmationView(viewModel: viewModel)
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)

                // Navigation buttons
                if !viewModel.bookingSuccess {
                    navigationButtons
                }
            }
            .navigationTitle(viewModel.bookingSuccess ? "Booking Confirmed" : "Book Appointment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") {
                        viewModel.resetBookingFlow()
                        dismiss()
                    }
                }
            }
            .task {
                await viewModel.loadAppointmentTypes()
            }
            .alert("Booking Error", isPresented: .constant(viewModel.bookingError != nil)) {
                Button("OK") {
                    viewModel.bookingError = nil
                }
            } message: {
                Text(viewModel.bookingError ?? "")
            }
        }
    }

    private var navigationButtons: some View {
        HStack(spacing: 16) {
            // Back button
            if viewModel.currentStep != .selectType {
                Button {
                    viewModel.previousStep()
                } label: {
                    HStack {
                        Image(systemName: "chevron.left")
                        Text("Back")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
            }

            // Next/Book button
            Button {
                if viewModel.currentStep == .confirm {
                    Task {
                        await viewModel.bookAppointment()
                    }
                } else {
                    viewModel.nextStep()
                }
            } label: {
                HStack {
                    if viewModel.isBooking {
                        ProgressView()
                            .tint(.white)
                    } else {
                        Text(viewModel.currentStep == .confirm ? "Confirm Booking" : "Continue")
                    }
                    if viewModel.currentStep != .confirm {
                        Image(systemName: "chevron.right")
                    }
                }
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
            .disabled(!viewModel.canProceedToNextStep || viewModel.isBooking)
        }
        .padding()
    }
}

// MARK: - Progress View

struct BookingProgressView: View {
    let currentStep: BookingStep

    var body: some View {
        HStack(spacing: 0) {
            ForEach(BookingStep.allCases, id: \.self) { step in
                HStack(spacing: 0) {
                    // Step circle
                    ZStack {
                        Circle()
                            .fill(step.rawValue <= currentStep.rawValue ? AppColors.brandTeal : Color(.systemGray4))
                            .frame(width: 28, height: 28)

                        if step.rawValue < currentStep.rawValue {
                            Image(systemName: "checkmark")
                                .font(.caption.bold())
                                .foregroundStyle(.white)
                        } else {
                            Text("\(step.rawValue + 1)")
                                .font(.caption.bold())
                                .foregroundStyle(step.rawValue <= currentStep.rawValue ? .white : .secondary)
                        }
                    }

                    // Connector line
                    if step != .confirm {
                        Rectangle()
                            .fill(step.rawValue < currentStep.rawValue ? AppColors.brandTeal : Color(.systemGray4))
                            .frame(height: 2)
                    }
                }
            }
        }
    }
}

// MARK: - Type Selection

struct TypeSelectionView: View {
    @ObservedObject var viewModel: AppointmentViewModel

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("What would you like to schedule?")
                    .font(.headline)
                    .padding(.horizontal)

                if viewModel.typesLoading {
                    ProgressView()
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    LazyVStack(spacing: 12) {
                        ForEach(viewModel.appointmentTypes) { type in
                            TypeCard(
                                type: type,
                                isSelected: viewModel.selectedType?.id == type.id
                            ) {
                                viewModel.selectedType = type
                            }
                        }
                    }
                    .padding(.horizontal)
                }
            }
            .padding(.vertical)
        }
    }
}

struct TypeCard: View {
    let type: AppointmentType
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 16) {
                // Color indicator
                RoundedRectangle(cornerRadius: 4)
                    .fill(type.uiColor)
                    .frame(width: 4, height: 50)

                VStack(alignment: .leading, spacing: 4) {
                    Text(type.name)
                        .font(.headline)
                        .foregroundStyle(.primary)

                    if let description = type.description {
                        Text(description)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                    }

                    Text(type.formattedDuration)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Spacer()

                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(.title2)
                    .foregroundStyle(isSelected ? AppColors.brandTeal : .secondary)
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal.opacity(0.1) : Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
            )
        }
    }
}

// MARK: - Staff Selection

struct StaffSelectionView: View {
    @ObservedObject var viewModel: AppointmentViewModel

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Choose an agent")
                    .font(.headline)
                    .padding(.horizontal)

                if viewModel.staffLoading {
                    ProgressView()
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if viewModel.staffMembers.isEmpty {
                    Text("No agents available for this service.")
                        .foregroundStyle(.secondary)
                        .padding()
                } else {
                    LazyVStack(spacing: 12) {
                        ForEach(viewModel.staffMembers) { staff in
                            StaffCard(
                                staff: staff,
                                isSelected: viewModel.selectedStaff?.id == staff.id
                            ) {
                                viewModel.selectedStaff = staff
                            }
                        }
                    }
                    .padding(.horizontal)
                }
            }
            .padding(.vertical)
        }
    }
}

struct StaffCard: View {
    let staff: StaffMember
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 16) {
                // Avatar
                if let avatarUrl = staff.avatarUrl, let url = URL(string: avatarUrl) {
                    AsyncImage(url: url) { image in
                        image
                            .resizable()
                            .scaledToFill()
                    } placeholder: {
                        staffInitials
                    }
                    .frame(width: 50, height: 50)
                    .clipShape(Circle())
                } else {
                    staffInitials
                }

                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(staff.name)
                            .font(.headline)
                            .foregroundStyle(.primary)

                        if staff.isPrimary {
                            Text("Primary")
                                .font(.caption2)
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(AppColors.brandTeal.opacity(0.2))
                                .foregroundStyle(AppColors.brandTeal)
                                .clipShape(Capsule())
                        }
                    }

                    if let title = staff.title {
                        Text(title)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    if let bio = staff.bio, !bio.isEmpty {
                        Text(bio)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                    }
                }

                Spacer()

                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(.title2)
                    .foregroundStyle(isSelected ? AppColors.brandTeal : .secondary)
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal.opacity(0.1) : Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
            )
        }
    }

    private var staffInitials: some View {
        ZStack {
            Circle()
                .fill(AppColors.brandTeal.opacity(0.2))
            Text(staff.name.prefix(1))
                .font(.title2.bold())
                .foregroundStyle(AppColors.brandTeal)
        }
        .frame(width: 50, height: 50)
    }
}

// MARK: - Date/Time Selection

struct DateTimeSelectionView: View {
    @ObservedObject var viewModel: AppointmentViewModel

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if viewModel.availabilityLoading {
                    ProgressView("Loading available times...")
                        .frame(maxWidth: .infinity, maxHeight: 200)
                } else if viewModel.availableDates.isEmpty {
                    VStack(spacing: 16) {
                        Image(systemName: "calendar.badge.exclamationmark")
                            .font(.largeTitle)
                            .foregroundStyle(.secondary)
                        Text("No available times in the next 2 weeks")
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, maxHeight: 200)
                } else {
                    // Date selection
                    VStack(alignment: .leading, spacing: 12) {
                        Text("Select a Date")
                            .font(.headline)

                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(viewModel.availableDates, id: \.self) { date in
                                    DateButton(
                                        date: date,
                                        isSelected: viewModel.selectedDate == date
                                    ) {
                                        viewModel.selectedDate = date
                                        viewModel.selectedTime = nil
                                    }
                                }
                            }
                        }
                    }
                    .padding(.horizontal)

                    // Time selection
                    if viewModel.selectedDate != nil {
                        VStack(alignment: .leading, spacing: 12) {
                            HStack {
                                Text("Select a Time")
                                    .font(.headline)
                                Spacer()
                                Text("Times shown in ET")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            let slots = viewModel.slotsForSelectedDate()
                            if slots.isEmpty {
                                Text("No available times for this date")
                                    .foregroundStyle(.secondary)
                            } else {
                                LazyVGrid(columns: [GridItem(.adaptive(minimum: 90))], spacing: 8) {
                                    ForEach(slots) { slot in
                                        TimeButton(
                                            slot: slot,
                                            isSelected: viewModel.selectedTime?.value == slot.value
                                        ) {
                                            viewModel.selectedTime = slot
                                        }
                                    }
                                }
                            }
                        }
                        .padding(.horizontal)
                    }
                }
            }
            .padding(.vertical)
        }
    }
}

// MARK: - Contact Details

struct ContactDetailsView: View {
    @ObservedObject var viewModel: AppointmentViewModel
    @ObservedObject var authViewModel: AuthViewModel
    @FocusState private var focusedField: Field?
    @State private var clientSearchText = ""
    @State private var ccEmailInput = ""
    @State private var guestNameInput = ""
    @State private var guestEmailInput = ""

    enum Field {
        case name, email, phone, notes, clientSearch, ccEmail, guestName, guestEmail
    }

    /// Check if user is an agent with clients
    private var isAgentWithClients: Bool {
        authViewModel.currentUser?.isAgent == true && !viewModel.agentClients.isEmpty
    }

    /// Filter clients based on search text
    private var filteredClients: [AgentClient] {
        if clientSearchText.isEmpty {
            return viewModel.agentClients
        }
        let searchLower = clientSearchText.lowercased()
        return viewModel.agentClients.filter { client in
            client.displayName.lowercased().contains(searchLower) ||
            client.email.lowercased().contains(searchLower)
        }
    }

    /// Count of selected clients for display
    private var selectedClientCount: Int {
        viewModel.selectedClients.count
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Agent client picker (only for agents)
                if authViewModel.currentUser?.isAgent == true {
                    clientPickerSection
                } else if authViewModel.isAuthenticated {
                    // Guest invite section for non-agent logged-in users (v1.10.4)
                    guestInviteSection
                }

                Text(isAgentWithClients ? "Client Contact Information" : "Your Contact Information")
                    .font(.headline)
                    .padding(.horizontal)

                VStack(spacing: 16) {
                    // Name
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Full Name")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("Enter your name", text: $viewModel.clientName)
                            .textFieldStyle(.roundedBorder)
                            .textContentType(.name)
                            .focused($focusedField, equals: .name)
                        if !viewModel.clientName.isEmpty, let error = viewModel.nameValidationError {
                            Text(error)
                                .font(.caption2)
                                .foregroundStyle(.red)
                        }
                    }

                    // Email
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Email")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("Enter your email", text: $viewModel.clientEmail)
                            .textFieldStyle(.roundedBorder)
                            .textContentType(.emailAddress)
                            .keyboardType(.emailAddress)
                            .autocapitalization(.none)
                            .focused($focusedField, equals: .email)
                        if !viewModel.clientEmail.isEmpty, let error = viewModel.emailValidationError {
                            Text(error)
                                .font(.caption2)
                                .foregroundStyle(.red)
                        }
                    }

                    // Phone
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Phone")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("Enter your phone number", text: $viewModel.clientPhone)
                            .textFieldStyle(.roundedBorder)
                            .textContentType(.telephoneNumber)
                            .keyboardType(.phonePad)
                            .focused($focusedField, equals: .phone)
                        if !viewModel.clientPhone.isEmpty, let error = viewModel.phoneValidationError {
                            Text(error)
                                .font(.caption2)
                                .foregroundStyle(.red)
                        }
                    }

                    // Notes (optional)
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Notes (optional)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("Any special requests or notes", text: $viewModel.notes, axis: .vertical)
                            .textFieldStyle(.roundedBorder)
                            .lineLimit(3...5)
                            .focused($focusedField, equals: .notes)
                    }
                }
                .padding(.horizontal)

                // Property info if booking a showing
                if let address = viewModel.propertyAddress {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Property")
                            .font(.caption)
                            .foregroundStyle(.secondary)

                        HStack {
                            Image(systemName: "house")
                                .foregroundStyle(.secondary)
                            Text(address)
                        }
                        .padding()
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(.systemGray6))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .padding(.horizontal)
                }
            }
            .padding(.vertical)
        }
        .onAppear {
            // Pre-fill from logged in user (only if no clients selected)
            if authViewModel.isAuthenticated && viewModel.selectedClients.isEmpty {
                viewModel.prefillUserInfo(from: authViewModel.currentUser)
            }
        }
        .task {
            // Load agent's clients if user is an agent
            if authViewModel.currentUser?.isAgent == true {
                await viewModel.loadAgentClients()
            }
        }
        .toolbar {
            ToolbarItemGroup(placement: .keyboard) {
                Spacer()
                Button("Done") {
                    focusedField = nil
                }
            }
        }
    }

    // MARK: - Client Picker Section (Multi-Select v1.10.0)

    @ViewBuilder
    private var clientPickerSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("Booking For")
                    .font(.headline)
                Spacer()
                // Show selected count badge
                if selectedClientCount > 0 {
                    Text("\(selectedClientCount) selected")
                        .font(.caption)
                        .foregroundStyle(.white)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(AppColors.brandTeal)
                        .clipShape(Capsule())
                }
                if viewModel.agentClientsLoading {
                    ProgressView()
                        .scaleEffect(0.8)
                }
            }
            .padding(.horizontal)

            if viewModel.agentClients.isEmpty && !viewModel.agentClientsLoading {
                // No clients - show hint
                Text("You can book for yourself or enter client details manually below.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .padding(.horizontal)
            } else {
                // Search field for filtering clients (show when 3+ clients)
                if viewModel.agentClients.count >= 3 {
                    HStack {
                        Image(systemName: "magnifyingglass")
                            .foregroundStyle(.secondary)
                        TextField("Search clients...", text: $clientSearchText)
                            .textFieldStyle(.plain)
                            .focused($focusedField, equals: .clientSearch)
                        if !clientSearchText.isEmpty {
                            Button {
                                clientSearchText = ""
                            } label: {
                                Image(systemName: "xmark.circle.fill")
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                    .padding(10)
                    .background(Color(.systemGray6))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .padding(.horizontal)
                }

                // Client picker (multi-select)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 12) {
                        // "Myself" option (always visible, regardless of search)
                        if clientSearchText.isEmpty {
                            ClientPickerChip(
                                name: "Myself",
                                isSelected: viewModel.selectedClients.isEmpty,
                                action: {
                                    viewModel.selectClient(nil)
                                    viewModel.prefillUserInfo(from: authViewModel.currentUser)
                                }
                            )
                        }

                        // Client options (filtered) - multi-select with checkmarks
                        ForEach(filteredClients) { client in
                            ClientPickerChip(
                                name: client.displayName,
                                isSelected: viewModel.isClientSelected(client),
                                showCheckmark: true,
                                action: {
                                    viewModel.selectClient(client)
                                    // Don't clear search - user may want to select more
                                }
                            )
                        }
                    }
                    .padding(.horizontal)
                }

                // No results message
                if !clientSearchText.isEmpty && filteredClients.isEmpty {
                    Text("No clients match '\(clientSearchText)'")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .padding(.horizontal)
                }

                // Multi-select hint
                if !viewModel.agentClients.isEmpty {
                    Text("Select multiple clients to book for a group")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .padding(.horizontal)
                }
            }

            // CC Emails Section (v1.10.0)
            ccEmailsSection
        }
        .padding(.bottom, 8)
    }

    // MARK: - Guest Invite Section (v1.10.4 - for non-agent logged-in users)

    @ViewBuilder
    private var guestInviteSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Invite a Guest")
                .font(.headline)
                .padding(.horizontal)

            // Guest name + email input
            VStack(spacing: 8) {
                HStack {
                    Image(systemName: "person")
                        .foregroundStyle(.secondary)
                    TextField("Guest name", text: $guestNameInput)
                        .textFieldStyle(.plain)
                        .textContentType(.name)
                        .focused($focusedField, equals: .guestName)
                }
                .padding(10)
                .background(Color(.systemGray6))
                .clipShape(RoundedRectangle(cornerRadius: 10))

                HStack {
                    Image(systemName: "envelope")
                        .foregroundStyle(.secondary)
                    TextField("Guest email", text: $guestEmailInput)
                        .textFieldStyle(.plain)
                        .keyboardType(.emailAddress)
                        .autocapitalization(.none)
                        .focused($focusedField, equals: .guestEmail)
                        .onSubmit {
                            addManualGuest()
                        }
                    if !guestNameInput.isEmpty && !guestEmailInput.isEmpty {
                        Button {
                            addManualGuest()
                        } label: {
                            Image(systemName: "plus.circle.fill")
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                }
                .padding(10)
                .background(Color(.systemGray6))
                .clipShape(RoundedRectangle(cornerRadius: 10))
            }
            .padding(.horizontal)

            // Guest chips
            if !viewModel.manualGuests.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(viewModel.manualGuests.enumerated()), id: \.offset) { index, guest in
                            GuestChip(name: guest.name, email: guest.email) {
                                viewModel.removeManualGuest(at: index)
                            }
                        }
                    }
                    .padding(.horizontal)
                }
            }

            // CC Emails
            ccEmailsSection
        }
        .padding(.bottom, 8)
    }

    private func addManualGuest() {
        let name = guestNameInput.trimmingCharacters(in: .whitespaces)
        let email = guestEmailInput.trimmingCharacters(in: .whitespaces)
        guard !name.isEmpty, !email.isEmpty else { return }
        viewModel.addManualGuest(name: name, email: email)
        guestNameInput = ""
        guestEmailInput = ""
    }

    // MARK: - CC Emails Section (v1.10.0)

    @ViewBuilder
    private var ccEmailsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("CC Emails (Optional)")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .padding(.horizontal)

            // CC email input
            HStack {
                Image(systemName: "envelope")
                    .foregroundStyle(.secondary)
                TextField("Add email to receive notifications", text: $ccEmailInput)
                    .textFieldStyle(.plain)
                    .keyboardType(.emailAddress)
                    .autocapitalization(.none)
                    .focused($focusedField, equals: .ccEmail)
                    .onSubmit {
                        addCCEmail()
                    }
                if !ccEmailInput.isEmpty {
                    Button {
                        addCCEmail()
                    } label: {
                        Image(systemName: "plus.circle.fill")
                            .foregroundStyle(AppColors.brandTeal)
                    }
                }
            }
            .padding(10)
            .background(Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .padding(.horizontal)

            // CC email chips
            if !viewModel.ccEmails.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(viewModel.ccEmails, id: \.self) { email in
                            CCEmailChip(email: email) {
                                viewModel.removeCCEmail(email)
                            }
                        }
                    }
                    .padding(.horizontal)
                }
            }
        }
        .padding(.top, 8)
    }

    private func addCCEmail() {
        let email = ccEmailInput.trimmingCharacters(in: .whitespaces)
        guard !email.isEmpty else { return }
        viewModel.addCCEmail(email)
        ccEmailInput = ""
    }
}

// MARK: - Client Picker Chip (Multi-Select v1.10.0)

struct ClientPickerChip: View {
    let name: String
    let isSelected: Bool
    var showCheckmark: Bool = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if showCheckmark {
                    // Multi-select mode: show checkbox
                    Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                        .font(.subheadline)
                } else {
                    // Single-select mode: show person icon
                    Image(systemName: isSelected ? "checkmark.circle.fill" : "person.circle")
                        .font(.subheadline)
                }
                Text(name)
                    .font(.subheadline)
                    .lineLimit(1)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(isSelected ? AppColors.brandTeal.opacity(0.15) : Color(.systemGray6))
            .foregroundStyle(isSelected ? AppColors.brandTeal : .primary)
            .clipShape(Capsule())
            .overlay(
                Capsule()
                    .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 1.5)
            )
        }
    }
}

// MARK: - CC Email Chip (v1.10.0)

struct CCEmailChip: View {
    let email: String
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 4) {
            Text(email)
                .font(.caption)
                .lineLimit(1)
            Button(action: onRemove) {
                Image(systemName: "xmark.circle.fill")
                    .font(.caption)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(Color(.systemGray5))
        .foregroundStyle(.primary)
        .clipShape(Capsule())
    }
}

// MARK: - Guest Chip (v1.10.4)

struct GuestChip: View {
    let name: String
    let email: String
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 4) {
            VStack(alignment: .leading, spacing: 0) {
                Text(name)
                    .font(.caption)
                    .fontWeight(.medium)
                    .lineLimit(1)
                Text(email)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            Button(action: onRemove) {
                Image(systemName: "xmark.circle.fill")
                    .font(.caption)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(AppColors.brandTeal.opacity(0.1))
        .overlay(
            Capsule()
                .stroke(AppColors.brandTeal.opacity(0.3), lineWidth: 1)
        )
        .foregroundStyle(.primary)
        .clipShape(Capsule())
    }
}

// MARK: - Confirmation View

struct ConfirmationView: View {
    @ObservedObject var viewModel: AppointmentViewModel
    @Environment(\.dismiss) private var dismiss

    // Calendar integration state
    @State private var addedToCalendar = false
    @State private var calendarError: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 24) {
                if viewModel.bookingSuccess, let response = viewModel.bookingResponse {
                    // Success state
                    successView(response)
                } else {
                    // Review state
                    reviewView
                }
            }
            .padding()
        }
    }

    private func successView(_ response: BookingResponse) -> some View {
        VStack(spacing: 24) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 60))
                .foregroundStyle(.green)

            VStack(spacing: 8) {
                Text("Booking Confirmed!")
                    .font(.title2)
                    .fontWeight(.bold)

                // Confirmation number
                HStack(spacing: 4) {
                    Text("Confirmation #")
                        .foregroundStyle(.secondary)
                    Text("\(response.appointmentId)")
                        .fontWeight(.semibold)
                        .foregroundStyle(AppColors.brandTeal)
                }
                .font(.subheadline)

                Text("Your \(response.typeName) has been scheduled.")
                    .foregroundStyle(.secondary)
            }

            VStack(spacing: 16) {
                HStack {
                    Image(systemName: "calendar")
                        .foregroundStyle(.secondary)
                    Text(response.date)
                    Spacer()
                }

                HStack {
                    Image(systemName: "clock")
                        .foregroundStyle(.secondary)
                    Text(response.time)
                    Spacer()
                }

                if response.googleSynced == true {
                    HStack {
                        Image(systemName: "checkmark.circle")
                            .foregroundStyle(.green)
                        Text("Added to calendar")
                            .foregroundStyle(.secondary)
                        Spacer()
                    }
                }
            }
            .padding()
            .background(Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))

            // Confirmation email message (v1.10.0: show attendee count)
            if let attendeeCount = response.attendeeCount, attendeeCount > 1 {
                Text("Confirmation emails have been sent to all \(attendeeCount) attendees")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            } else {
                Text("A confirmation email has been sent to \(viewModel.clientEmail)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }

            // Add to Apple Calendar button
            if !addedToCalendar {
                Button {
                    Task {
                        // Use raw date/time for proper parsing, fallback to formatted
                        let calendarDate = response.dateRaw ?? response.date
                        let calendarTime = response.timeRaw ?? response.time
                        let success = await CalendarService.shared.addToCalendar(
                            typeName: response.typeName,
                            date: calendarDate,
                            time: calendarTime,
                            staffName: viewModel.selectedStaff?.name,
                            propertyAddress: viewModel.propertyAddress,
                            appointmentId: response.appointmentId
                        )
                        if success {
                            addedToCalendar = true
                        } else {
                            calendarError = CalendarService.shared.lastError
                        }
                    }
                } label: {
                    HStack {
                        Image(systemName: "calendar.badge.plus")
                        Text("Add to Apple Calendar")
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)

                if let error = calendarError {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            } else {
                HStack {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                    Text("Added to Calendar")
                        .foregroundStyle(.secondary)
                }
            }

            Button {
                viewModel.resetBookingFlow()
                dismiss()
            } label: {
                Text("Done")
                    .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
        }
    }

    private var reviewView: some View {
        VStack(alignment: .leading, spacing: 20) {
            Text("Review Your Booking")
                .font(.headline)

            // Appointment details
            VStack(alignment: .leading, spacing: 16) {
                if let type = viewModel.selectedType {
                    HStack {
                        RoundedRectangle(cornerRadius: 4)
                            .fill(type.uiColor)
                            .frame(width: 4, height: 40)
                        VStack(alignment: .leading) {
                            Text("Service")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text(type.name)
                                .fontWeight(.medium)
                        }
                        Spacer()
                    }
                }

                if let staff = viewModel.selectedStaff {
                    HStack {
                        Image(systemName: "person")
                            .foregroundStyle(.secondary)
                            .frame(width: 24)
                        VStack(alignment: .leading) {
                            Text("Agent")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text(staff.name)
                        }
                        Spacer()
                    }
                }

                if let date = viewModel.selectedDate, let time = viewModel.selectedTime {
                    HStack {
                        Image(systemName: "calendar")
                            .foregroundStyle(.secondary)
                            .frame(width: 24)
                        VStack(alignment: .leading) {
                            Text("Date & Time")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text("\(formatDate(date)) at \(time.formattedTime)")
                        }
                        Spacer()
                    }
                }

                if let address = viewModel.propertyAddress {
                    HStack {
                        Image(systemName: "house")
                            .foregroundStyle(.secondary)
                            .frame(width: 24)
                        VStack(alignment: .leading) {
                            Text("Property")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text(address)
                        }
                        Spacer()
                    }
                }
            }
            .padding()
            .background(Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))

            // Contact details (v1.10.0: Multi-attendee support)
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text("Contact Information")
                        .font(.subheadline)
                        .fontWeight(.medium)
                    if viewModel.selectedClients.count > 1 || !viewModel.manualGuests.isEmpty || !viewModel.ccEmails.isEmpty {
                        Spacer()
                        HStack(spacing: 4) {
                            Image(systemName: "person.2")
                            Text("\(max(1, viewModel.selectedClients.count) + viewModel.manualGuests.count + viewModel.ccEmails.count) attendees")
                        }
                        .font(.caption)
                        .foregroundStyle(AppColors.brandTeal)
                    }
                }

                // Primary contact
                HStack {
                    Image(systemName: "person")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    VStack(alignment: .leading) {
                        Text(viewModel.clientName)
                        Text("Primary")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }

                HStack {
                    Image(systemName: "envelope")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    Text(viewModel.clientEmail)
                }

                HStack {
                    Image(systemName: "phone")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    Text(viewModel.clientPhone)
                }

                // Additional attendees (v1.10.0 + v1.10.4 manual guests)
                if viewModel.selectedClients.count > 1 || !viewModel.manualGuests.isEmpty {
                    Divider()
                    Text("Additional Attendees")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    // Agent-selected clients
                    ForEach(viewModel.selectedClients.dropFirst(), id: \.id) { client in
                        HStack {
                            Image(systemName: "person")
                                .foregroundStyle(.secondary)
                                .frame(width: 24)
                            VStack(alignment: .leading) {
                                Text(client.displayName)
                                Text(client.email)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }

                    // Manual guests (v1.10.4)
                    ForEach(Array(viewModel.manualGuests.enumerated()), id: \.offset) { _, guest in
                        HStack {
                            Image(systemName: "person")
                                .foregroundStyle(.secondary)
                                .frame(width: 24)
                            VStack(alignment: .leading) {
                                Text(guest.name)
                                Text(guest.email)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }

                // CC emails (v1.10.0)
                if !viewModel.ccEmails.isEmpty {
                    Divider()
                    Text("CC (Notification Only)")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    ForEach(viewModel.ccEmails, id: \.self) { email in
                        HStack {
                            Image(systemName: "envelope")
                                .foregroundStyle(.secondary)
                                .frame(width: 24)
                            Text(email)
                                .font(.subheadline)
                        }
                    }
                }

                if !viewModel.notes.isEmpty {
                    HStack(alignment: .top) {
                        Image(systemName: "note.text")
                            .foregroundStyle(.secondary)
                            .frame(width: 24)
                        Text(viewModel.notes)
                    }
                }
            }
            .padding()
            .background(Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 12))

            // Cancellation policy
            if let policy = viewModel.portalPolicy?.cancellation, policy.enabled {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "info.circle")
                        .foregroundStyle(.blue)
                    Text(policy.policyText.isEmpty ?
                         "Free cancellation until \(policy.hoursBefore) hours before your appointment" :
                         policy.policyText)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding()
                .background(Color.blue.opacity(0.05))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }
        }
        .task {
            await viewModel.loadPortalPolicy()
        }
    }

    private func formatDate(_ dateString: String) -> String {
        let inputFormatter = DateFormatter()
        inputFormatter.dateFormat = "yyyy-MM-dd"

        let outputFormatter = DateFormatter()
        outputFormatter.dateStyle = .long

        if let date = inputFormatter.date(from: dateString) {
            return outputFormatter.string(from: date)
        }
        return dateString
    }
}

#Preview {
    BookAppointmentView(viewModel: AppointmentViewModel())
        .environmentObject(AuthViewModel())
}
