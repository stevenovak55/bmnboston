//
//  KioskSignInFormView.swift
//  BMNBoston
//
//  Multi-step sign-in form for open house kiosk mode
//  Created for BMN Boston Real Estate
//
//  VERSION: v6.71.0
//

import SwiftUI

// MARK: - Sign-In Step

enum KioskSignInStep: Int, CaseIterable {
    case contact = 1
    case agentStatus = 2
    case agentDetails = 3  // Only shown if working with other agent
    case buyingIntent = 4  // Only shown if NOT working with agent
    case agentPurpose = 5  // Only shown if visitor IS an agent
    case consent = 6
    case success = 7

    var title: String {
        switch self {
        case .contact: return "Your Contact Info"
        case .agentStatus: return "Are You Working With an Agent?"
        case .agentDetails: return "Agent Information"
        case .buyingIntent: return "Your Buying Timeline"
        case .agentPurpose: return "Your Visit Purpose"
        case .consent: return "Consent & Disclosure"
        case .success: return "Thank You!"
        }
    }
}

// MARK: - Kiosk Sign-In Form View

struct KioskSignInFormView: View {
    let openHouse: OpenHouse
    let onComplete: (OpenHouseAttendee) -> Void

    @Environment(\.dismiss) private var dismiss

    // Form state
    @State private var currentStep: KioskSignInStep = .contact
    @State private var attendee: OpenHouseAttendee
    @State private var isSubmitting = false
    @State private var errorMessage: String?
    @State private var showError = false

    init(openHouse: OpenHouse, onComplete: @escaping (OpenHouseAttendee) -> Void) {
        self.openHouse = openHouse
        self.onComplete = onComplete
        _attendee = State(initialValue: OpenHouseAttendee.createLocal(openHouseId: openHouse.id))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // Progress indicator
                if currentStep != .success {
                    progressIndicator
                        .padding(.vertical, 16)
                }

                // Current step content
                ScrollView {
                    VStack(spacing: 24) {
                        stepContent
                            .padding(.horizontal, 24)
                    }
                    .padding(.vertical, 24)
                }

                // Navigation buttons
                if currentStep != .success {
                    navigationButtons
                        .padding(24)
                }
            }
            .navigationTitle(currentStep.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    if currentStep == .contact {
                        Button("Cancel") {
                            dismiss()
                        }
                    }
                }
            }
            .alert("Error", isPresented: $showError) {
                Button("Try Again") { }
                Button("Cancel", role: .cancel) {
                    dismiss()
                }
            } message: {
                Text(errorMessage ?? "An error occurred")
            }
        }
    }

    // MARK: - Progress Indicator

    private var progressIndicator: some View {
        let totalSteps = effectiveSteps.count
        let currentIndex = effectiveSteps.firstIndex(of: currentStep) ?? 0

        return HStack(spacing: 8) {
            ForEach(0..<totalSteps, id: \.self) { index in
                Circle()
                    .fill(index <= currentIndex ? AppColors.brandTeal : Color.gray.opacity(0.3))
                    .frame(width: 10, height: 10)
            }
        }
    }

    // Determine which steps to show based on agent status
    private var effectiveSteps: [KioskSignInStep] {
        var steps: [KioskSignInStep] = [.contact, .agentStatus]

        if attendee.workingWithAgent == .yesOther {
            steps.append(.agentDetails)
        } else if attendee.workingWithAgent == .no {
            steps.append(.buyingIntent)
        } else if attendee.workingWithAgent == .iAmAnAgent {
            steps.append(.agentPurpose)
        }

        steps.append(contentsOf: [.consent, .success])
        return steps
    }

    // MARK: - Step Content

    @ViewBuilder
    private var stepContent: some View {
        switch currentStep {
        case .contact:
            contactInfoStep

        case .agentStatus:
            agentStatusStep

        case .agentDetails:
            agentDetailsStep

        case .buyingIntent:
            buyingIntentStep

        case .agentPurpose:
            agentPurposeStep

        case .consent:
            consentStep

        case .success:
            successStep
        }
    }

    // MARK: - Step 1: Contact Info

    private var contactInfoStep: some View {
        VStack(alignment: .leading, spacing: 20) {
            Text("Please enter your contact information")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(spacing: 16) {
                KioskTextField(
                    label: "First Name",
                    placeholder: "Enter your first name",
                    text: $attendee.firstName,
                    icon: "person.fill"
                )

                KioskTextField(
                    label: "Last Name",
                    placeholder: "Enter your last name",
                    text: $attendee.lastName,
                    icon: "person.fill"
                )

                KioskTextField(
                    label: "Email",
                    placeholder: "you@example.com",
                    text: $attendee.email,
                    icon: "envelope.fill",
                    keyboardType: .emailAddress
                )

                KioskTextField(
                    label: "Phone",
                    placeholder: "(617) 555-1234",
                    text: $attendee.phone,
                    icon: "phone.fill",
                    keyboardType: .phonePad
                )
            }
        }
    }

    // MARK: - Step 2: Agent Status

    private var agentStatusStep: some View {
        VStack(alignment: .leading, spacing: 20) {
            Text("Are you currently working with a real estate agent?")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(spacing: 12) {
                AgentStatusButton(
                    title: "No, I'm not working with an agent",
                    subtitle: "I'm exploring on my own",
                    isSelected: attendee.workingWithAgent == .no,
                    action: { attendee.workingWithAgent = .no }
                )

                AgentStatusButton(
                    title: "Yes, with another agent",
                    subtitle: "I have a buyer's agent",
                    isSelected: attendee.workingWithAgent == .yesOther,
                    action: { attendee.workingWithAgent = .yesOther }
                )

                AgentStatusButton(
                    title: "I am a real estate agent",
                    subtitle: "I'm an agent visiting this property",
                    isSelected: attendee.workingWithAgent == .iAmAnAgent,
                    action: {
                        attendee.workingWithAgent = .iAmAnAgent
                        attendee.isAgent = true
                    }
                )
            }
        }
    }

    // MARK: - Step 3a: Agent Details (if working with other agent)

    private var agentDetailsStep: some View {
        VStack(alignment: .leading, spacing: 20) {
            Text("Please provide your agent's contact information")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(spacing: 16) {
                KioskTextField(
                    label: "Agent Name",
                    placeholder: "Agent's full name",
                    text: Binding(
                        get: { attendee.otherAgentName ?? "" },
                        set: { attendee.otherAgentName = $0.isEmpty ? nil : $0 }
                    ),
                    icon: "person.fill"
                )

                KioskTextField(
                    label: "Brokerage",
                    placeholder: "Agent's company",
                    text: Binding(
                        get: { attendee.otherAgentBrokerage ?? "" },
                        set: { attendee.otherAgentBrokerage = $0.isEmpty ? nil : $0 }
                    ),
                    icon: "building.2.fill"
                )

                KioskTextField(
                    label: "Agent Phone",
                    placeholder: "(617) 555-1234",
                    text: Binding(
                        get: { attendee.otherAgentPhone ?? "" },
                        set: { attendee.otherAgentPhone = $0.isEmpty ? nil : $0 }
                    ),
                    icon: "phone.fill",
                    keyboardType: .phonePad
                )

                KioskTextField(
                    label: "Agent Email",
                    placeholder: "agent@example.com",
                    text: Binding(
                        get: { attendee.otherAgentEmail ?? "" },
                        set: { attendee.otherAgentEmail = $0.isEmpty ? nil : $0 }
                    ),
                    icon: "envelope.fill",
                    keyboardType: .emailAddress
                )
            }
        }
    }

    // MARK: - Step 3b: Buying Intent (if NOT working with agent)

    private var buyingIntentStep: some View {
        VStack(alignment: .leading, spacing: 24) {
            // Timeline
            VStack(alignment: .leading, spacing: 12) {
                Text("When are you looking to buy?")
                    .font(.headline)

                ForEach(BuyingTimeline.allCases, id: \.self) { timeline in
                    TimelineButton(
                        title: timeline.displayName,
                        isSelected: attendee.buyingTimeline == timeline,
                        action: { attendee.buyingTimeline = timeline }
                    )
                }
            }

            Divider()

            // Pre-approval status
            VStack(alignment: .leading, spacing: 12) {
                Text("Are you pre-approved for a mortgage?")
                    .font(.headline)

                HStack(spacing: 12) {
                    ForEach(PreApprovalStatus.allCases, id: \.self) { status in
                        PreApprovalButton(
                            title: status.displayName,
                            isSelected: attendee.preApproved == status,
                            action: { attendee.preApproved = status }
                        )
                    }
                }
            }

            // Lender name (if pre-approved)
            if attendee.preApproved == .yes {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Who is your lender?")
                        .font(.headline)

                    KioskTextField(
                        label: "Lender Name",
                        placeholder: "Bank or mortgage company",
                        text: Binding(
                            get: { attendee.lenderName ?? "" },
                            set: { attendee.lenderName = $0.isEmpty ? nil : $0 }
                        ),
                        icon: "building.columns.fill"
                    )
                }
            }
        }
    }

    // MARK: - Step 3c: Agent Purpose (if visitor IS an agent)

    private var agentPurposeStep: some View {
        VStack(alignment: .leading, spacing: 24) {
            // Visit purpose
            VStack(alignment: .leading, spacing: 12) {
                Text("What brings you here today?")
                    .font(.headline)

                VStack(spacing: 12) {
                    AgentPurposeButton(
                        title: "Previewing for a client",
                        subtitle: "I have a buyer interested in this property",
                        isSelected: attendee.agentVisitPurpose == .previewing,
                        action: {
                            attendee.agentVisitPurpose = .previewing
                            attendee.agentHasBuyer = true
                        }
                    )

                    AgentPurposeButton(
                        title: "For myself or general research",
                        subtitle: "Looking for comps, networking, or personal interest",
                        isSelected: attendee.agentVisitPurpose == .comps || attendee.agentVisitPurpose == .networking || attendee.agentVisitPurpose == .curiosity,
                        action: {
                            attendee.agentVisitPurpose = .curiosity
                            attendee.agentHasBuyer = false
                        }
                    )
                }
            }

            // Brokerage (optional)
            VStack(alignment: .leading, spacing: 12) {
                Text("Your Brokerage (Optional)")
                    .font(.headline)

                KioskTextField(
                    label: "Brokerage Name",
                    placeholder: "Your company name",
                    text: Binding(
                        get: { attendee.visitorAgentBrokerage ?? "" },
                        set: { attendee.visitorAgentBrokerage = $0.isEmpty ? nil : $0 }
                    ),
                    icon: "building.2.fill"
                )
            }

            // Buyer timeline (if previewing for client)
            if attendee.agentVisitPurpose == .previewing {
                VStack(alignment: .leading, spacing: 12) {
                    Text("Your buyer's timeline")
                        .font(.headline)

                    ForEach(BuyingTimeline.allCases, id: \.self) { timeline in
                        TimelineButton(
                            title: timeline.displayName,
                            isSelected: attendee.agentBuyerTimeline == timeline.rawValue,
                            action: { attendee.agentBuyerTimeline = timeline.rawValue }
                        )
                    }
                }
            }
        }
    }

    // MARK: - Step 4: Consent & MA Disclosure

    private var consentStep: some View {
        VStack(alignment: .leading, spacing: 24) {
            // Consent toggles
            VStack(alignment: .leading, spacing: 16) {
                Text("Communication Preferences")
                    .font(.headline)

                ConsentToggle(
                    title: "I consent to be contacted about this property",
                    isOn: $attendee.consentToFollowUp
                )

                ConsentToggle(
                    title: "I consent to receive email communications",
                    isOn: $attendee.consentToEmail
                )

                ConsentToggle(
                    title: "I consent to receive text messages",
                    isOn: $attendee.consentToText
                )
            }

            Divider()

            // Massachusetts Agency Disclosure
            VStack(alignment: .leading, spacing: 12) {
                Text("Massachusetts Agency Disclosure")
                    .font(.headline)

                Text(maDisclosureText)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .padding()
                    .background(Color.gray.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 8))

                ConsentToggle(
                    title: "I acknowledge that I have read and understand this disclosure",
                    isOn: $attendee.maDisclosureAcknowledged
                )
            }
        }
    }

    private var maDisclosureText: String {
        """
        The listing agent represents the seller in this transaction. As a prospective buyer, you are entitled to seek your own representation through a buyer's agent.

        Types of agency relationships in Massachusetts:
        • Seller's Agent: Represents only the seller's interests
        • Buyer's Agent: Represents only the buyer's interests
        • Dual Agent: Represents both parties with informed consent

        By signing in at this open house, you are not entering into any agency agreement. You retain the right to have your own representation.
        """
    }

    // MARK: - Step 5: Success

    private var successStep: some View {
        VStack(spacing: 24) {
            Image(systemName: "checkmark.circle.fill")
                .font(.system(size: 80))
                .foregroundStyle(.green)

            Text("Thank You!")
                .font(.largeTitle)
                .fontWeight(.bold)

            Text("You've been signed in to the open house. We'll follow up with you soon!")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            // v6.73.0: Show auto-search confirmation when saved search was created
            if attendee.autoSearchCreated {
                autoSearchConfirmation
            }

            Button {
                onComplete(attendee)
            } label: {
                Text("Done")
                    .font(.headline)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .padding(.top, 16)
        }
        .padding()
    }

    // MARK: - Auto-Search Confirmation (v6.73.0)

    /// Shows confirmation that an auto-saved search was created based on the open house property
    private var autoSearchConfirmation: some View {
        VStack(spacing: 12) {
            Divider()
                .padding(.vertical, 8)

            HStack(spacing: 12) {
                Image(systemName: "bell.badge.fill")
                    .font(.title2)
                    .foregroundStyle(AppColors.brandTeal)

                VStack(alignment: .leading, spacing: 4) {
                    Text("Property Alerts Set Up!")
                        .font(.headline)
                        .foregroundStyle(.primary)

                    Text(autoSearchDescription)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .fixedSize(horizontal: false, vertical: true)
                }

                Spacer()
            }
            .padding()
            .background(AppColors.brandTeal.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }

    /// Generates description of the auto-created saved search based on open house property
    private var autoSearchDescription: String {
        var criteria: [String] = []

        // City
        if !openHouse.propertyCity.isEmpty {
            criteria.append(openHouse.propertyCity)
        }

        // Beds
        if let beds = openHouse.beds, beds > 0 {
            criteria.append("\(beds)+ beds")
        }

        // Price range (±20% of list price)
        if let price = openHouse.listPrice, price > 0 {
            let minPrice = Int(Double(price) * 0.8)
            let maxPrice = Int(Double(price) * 1.2)
            criteria.append("\(formatPriceShort(minPrice))-\(formatPriceShort(maxPrice))")
        }

        if criteria.isEmpty {
            return "We'll notify you when similar properties become available."
        } else {
            return "We'll notify you about homes in \(criteria.joined(separator: ", "))."
        }
    }

    /// Formats price in short form (e.g., $500K, $1.2M)
    private func formatPriceShort(_ price: Int) -> String {
        if price >= 1_000_000 {
            let millions = Double(price) / 1_000_000.0
            if millions == floor(millions) {
                return "$\(Int(millions))M"
            } else {
                return "$\(String(format: "%.1f", millions))M"
            }
        } else if price >= 1000 {
            let thousands = price / 1000
            return "$\(thousands)K"
        } else {
            return "$\(price)"
        }
    }

    // MARK: - Navigation Buttons

    private var navigationButtons: some View {
        HStack(spacing: 16) {
            // Back button
            if currentStep != .contact {
                Button {
                    goToPreviousStep()
                } label: {
                    HStack {
                        Image(systemName: "chevron.left")
                        Text("Back")
                    }
                    .font(.headline)
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(Color.gray.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }

            // Next/Submit button
            Button {
                if currentStep == .consent {
                    submitAttendee()
                } else {
                    goToNextStep()
                }
            } label: {
                HStack {
                    if isSubmitting {
                        ProgressView()
                            .tint(.white)
                    } else {
                        Text(currentStep == .consent ? "Submit" : "Next")
                        Image(systemName: "chevron.right")
                    }
                }
                .font(.headline)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity)
                .padding()
                .background(canProceed ? AppColors.brandTeal : Color.gray)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(!canProceed || isSubmitting)
        }
    }

    // MARK: - Validation

    private var canProceed: Bool {
        switch currentStep {
        case .contact:
            return !attendee.firstName.isEmpty &&
                   !attendee.lastName.isEmpty &&
                   !attendee.email.isEmpty &&
                   attendee.email.contains("@")

        case .agentStatus:
            return true // Any selection is valid

        case .agentDetails:
            return attendee.otherAgentName != nil && !attendee.otherAgentName!.isEmpty

        case .buyingIntent:
            return true // Defaults are valid

        case .agentPurpose:
            return attendee.agentVisitPurpose != nil // Must select a purpose

        case .consent:
            return attendee.maDisclosureAcknowledged

        case .success:
            return true
        }
    }

    // MARK: - Navigation

    private func goToNextStep() {
        withAnimation {
            switch currentStep {
            case .contact:
                currentStep = .agentStatus

            case .agentStatus:
                if attendee.workingWithAgent == .yesOther {
                    currentStep = .agentDetails
                } else if attendee.workingWithAgent == .no {
                    currentStep = .buyingIntent
                } else if attendee.workingWithAgent == .iAmAnAgent {
                    currentStep = .agentPurpose
                } else {
                    currentStep = .consent
                }

            case .agentDetails, .buyingIntent, .agentPurpose:
                currentStep = .consent

            case .consent:
                currentStep = .success

            case .success:
                break
            }
        }
    }

    private func goToPreviousStep() {
        withAnimation {
            switch currentStep {
            case .contact:
                break

            case .agentStatus:
                currentStep = .contact

            case .agentDetails, .buyingIntent, .agentPurpose:
                currentStep = .agentStatus

            case .consent:
                if attendee.workingWithAgent == .yesOther {
                    currentStep = .agentDetails
                } else if attendee.workingWithAgent == .no {
                    currentStep = .buyingIntent
                } else if attendee.workingWithAgent == .iAmAnAgent {
                    currentStep = .agentPurpose
                } else {
                    currentStep = .agentStatus
                }

            case .success:
                break
            }
        }
    }

    // MARK: - Submit

    private func submitAttendee() {
        isSubmitting = true

        Task {
            do {
                // Set MA disclosure timestamp
                if attendee.maDisclosureAcknowledged {
                    var updatedAttendee = attendee
                    updatedAttendee.maDisclosureTimestamp = Date()
                    attendee = updatedAttendee
                }

                // v6.72.0: Set device token to exclude from push notifications
                // This prevents the kiosk device from receiving its own sign-in notification
                var attendeeToSubmit = attendee
                attendeeToSubmit.deviceTokenForExclusion = await PushNotificationManager.shared.deviceToken

                // Submit to server
                let savedAttendee = try await OpenHouseService.shared.addAttendee(
                    openHouseId: openHouse.id,
                    attendee: attendeeToSubmit
                )

                isSubmitting = false
                attendee = savedAttendee
                currentStep = .success

            } catch {
                isSubmitting = false
                errorMessage = "Failed to submit: \(error.localizedDescription)"
                showError = true
            }
        }
    }
}

// MARK: - Supporting Views

struct KioskTextField: View {
    let label: String
    let placeholder: String
    @Binding var text: String
    var icon: String = "textformat"
    var keyboardType: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(label)
                .font(.subheadline)
                .fontWeight(.medium)
                .foregroundStyle(.secondary)

            HStack(spacing: 12) {
                Image(systemName: icon)
                    .foregroundStyle(.secondary)
                    .frame(width: 24)

                TextField(placeholder, text: $text)
                    .font(.body)
                    .keyboardType(keyboardType)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(keyboardType == .emailAddress ? .never : .words)
            }
            .padding()
            .background(Color.gray.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 10))
        }
    }
}

struct AgentStatusButton: View {
    let title: String
    let subtitle: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                VStack(alignment: .leading, spacing: 4) {
                    Text(title)
                        .font(.headline)
                        .foregroundStyle(isSelected ? .white : .primary)

                    Text(subtitle)
                        .font(.caption)
                        .foregroundStyle(isSelected ? .white.opacity(0.8) : .secondary)
                }

                Spacer()

                if isSelected {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.white)
                }
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal : Color.gray.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }
}

struct AgentPurposeButton: View {
    let title: String
    let subtitle: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                VStack(alignment: .leading, spacing: 4) {
                    Text(title)
                        .font(.headline)
                        .foregroundStyle(isSelected ? .white : .primary)

                    Text(subtitle)
                        .font(.caption)
                        .foregroundStyle(isSelected ? .white.opacity(0.8) : .secondary)
                }

                Spacer()

                if isSelected {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.white)
                }
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal : Color.gray.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }
}

struct TimelineButton: View {
    let title: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                Text(title)
                    .font(.subheadline)
                    .foregroundStyle(isSelected ? .white : .primary)

                Spacer()

                if isSelected {
                    Image(systemName: "checkmark")
                        .foregroundStyle(.white)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(isSelected ? AppColors.brandTeal : Color.gray.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 8))
        }
    }
}

struct PreApprovalButton: View {
    let title: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(title)
                .font(.subheadline)
                .foregroundStyle(isSelected ? .white : .primary)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .background(isSelected ? AppColors.brandTeal : Color.gray.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 8))
        }
    }
}

struct ConsentToggle: View {
    let title: String
    @Binding var isOn: Bool

    var body: some View {
        Button {
            isOn.toggle()
        } label: {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: isOn ? "checkmark.square.fill" : "square")
                    .font(.title2)
                    .foregroundStyle(isOn ? AppColors.brandTeal : .secondary)

                Text(title)
                    .font(.subheadline)
                    .foregroundStyle(.primary)
                    .multilineTextAlignment(.leading)

                Spacer()
            }
        }
    }
}
