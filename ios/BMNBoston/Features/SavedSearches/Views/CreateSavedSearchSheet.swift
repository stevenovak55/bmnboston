//
//  CreateSavedSearchSheet.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sheet for creating a new saved search
//

import SwiftUI

struct CreateSavedSearchSheet: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @EnvironmentObject var authViewModel: AuthViewModel
    @Environment(\.dismiss) var dismiss

    @State private var name: String = ""
    @State private var description: String = ""
    @State private var notificationFrequency: NotificationFrequency = .fifteenMin
    @State private var isSaving = false
    @State private var errorMessage: String?

    // Agent client selection state
    @State private var saveForSelf = true
    @State private var selectedClientIds: Set<Int> = []
    @State private var clients: [AgentClient] = []
    @State private var agentNote: String = ""
    @State private var ccOnNotify: Bool = true
    @State private var isLoadingClients = false

    @FocusState private var isNameFocused: Bool

    /// Check if current user is an agent
    private var isAgent: Bool {
        authViewModel.currentUser?.isAgent == true
    }

    /// Check if save button should be disabled
    private var saveButtonDisabled: Bool {
        let nameEmpty = name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        let savingForClientsWithNoneSelected = isAgent && !saveForSelf && selectedClientIds.isEmpty
        return nameEmpty || isSaving || savingForClientsWithNoneSelected
    }

    var body: some View {
        NavigationView {
            Form {
                // Name Section
                Section {
                    TextField("Search Name", text: $name)
                        .focused($isNameFocused)

                    TextField("Description (optional)", text: $description, axis: .vertical)
                        .lineLimit(2...4)
                } header: {
                    Text("Details")
                } footer: {
                    Text("Give your search a memorable name")
                }

                // Current Filters Summary
                Section {
                    currentFiltersSummary
                } header: {
                    Text("Current Filters")
                } footer: {
                    Text("These filters will be saved with this search")
                }

                // Notification Frequency
                Section {
                    Picker("Alert Frequency", selection: $notificationFrequency) {
                        ForEach(NotificationFrequency.allCases, id: \.self) { frequency in
                            VStack(alignment: .leading) {
                                Text(frequency.displayName)
                            }
                            .tag(frequency)
                        }
                    }
                    .pickerStyle(.menu)

                    Text(notificationFrequency.shortDescription)
                        .font(.caption)
                        .foregroundColor(.secondary)
                } header: {
                    Text("Notifications")
                }

                // Save For Section (agents only)
                if isAgent {
                    Section {
                        // Radio buttons for save type
                        VStack(alignment: .leading, spacing: 12) {
                            Button(action: {
                                saveForSelf = true
                                selectedClientIds.removeAll()
                            }) {
                                HStack {
                                    Image(systemName: saveForSelf ? "largecircle.fill.circle" : "circle")
                                        .foregroundColor(saveForSelf ? AppColors.brandTeal : .secondary)
                                    Text("Save for myself")
                                        .foregroundColor(.primary)
                                    Spacer()
                                }
                            }
                            .buttonStyle(PlainButtonStyle())

                            Button(action: {
                                saveForSelf = false
                                if clients.isEmpty && !isLoadingClients {
                                    loadClients()
                                }
                            }) {
                                HStack {
                                    Image(systemName: saveForSelf ? "circle" : "largecircle.fill.circle")
                                        .foregroundColor(saveForSelf ? .secondary : AppColors.brandTeal)
                                    Text("Save for client(s)")
                                        .foregroundColor(.primary)
                                    Spacer()
                                }
                            }
                            .buttonStyle(PlainButtonStyle())
                        }

                        // Client selection (when saving for clients)
                        if !saveForSelf {
                            if isLoadingClients {
                                HStack {
                                    ProgressView()
                                        .scaleEffect(0.8)
                                    Text("Loading clients...")
                                        .font(.subheadline)
                                        .foregroundColor(.secondary)
                                }
                                .padding(.vertical, 8)
                            } else if clients.isEmpty {
                                Text("No clients found. Add clients from My Clients.")
                                    .font(.subheadline)
                                    .foregroundColor(.secondary)
                                    .padding(.vertical, 8)
                            } else {
                                // Select All / Deselect All
                                HStack {
                                    Button(selectedClientIds.count == clients.count ? "Deselect All" : "Select All") {
                                        if selectedClientIds.count == clients.count {
                                            selectedClientIds.removeAll()
                                        } else {
                                            selectedClientIds = Set(clients.map { $0.id })
                                        }
                                    }
                                    .font(.subheadline)
                                    .foregroundColor(AppColors.brandTeal)

                                    Spacer()

                                    Text("\(selectedClientIds.count) selected")
                                        .font(.caption)
                                        .foregroundColor(.secondary)
                                }

                                // Client list
                                ForEach(clients) { client in
                                    Button(action: {
                                        if selectedClientIds.contains(client.id) {
                                            selectedClientIds.remove(client.id)
                                        } else {
                                            selectedClientIds.insert(client.id)
                                        }
                                    }) {
                                        HStack {
                                            Image(systemName: selectedClientIds.contains(client.id) ? "checkmark.circle.fill" : "circle")
                                                .foregroundColor(selectedClientIds.contains(client.id) ? AppColors.brandTeal : .secondary)
                                            VStack(alignment: .leading, spacing: 2) {
                                                Text(client.displayName)
                                                    .font(.subheadline)
                                                    .foregroundColor(.primary)
                                                Text(client.email)
                                                    .font(.caption)
                                                    .foregroundColor(.secondary)
                                            }
                                            Spacer()
                                        }
                                    }
                                    .buttonStyle(PlainButtonStyle())
                                }

                                // Agent note
                                TextField("Note to clients (optional)", text: $agentNote, axis: .vertical)
                                    .lineLimit(2...4)

                                // CC toggle
                                Toggle("CC me on notifications", isOn: $ccOnNotify)
                            }
                        }
                    } header: {
                        Text("Save For")
                    } footer: {
                        if !saveForSelf && !clients.isEmpty {
                            Text("Selected clients will receive this search and get notified of new matches")
                        }
                    }
                }

                // Error message
                if let error = errorMessage {
                    Section {
                        HStack {
                            Image(systemName: "exclamationmark.triangle.fill")
                                .foregroundColor(.orange)
                            Text(error)
                                .font(.caption)
                        }
                    }
                }
            }
            .navigationTitle("Save Search")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                }

                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        saveSearch()
                    }
                    .disabled(saveButtonDisabled)
                    .fontWeight(.semibold)
                }
            }
            .onAppear {
                isNameFocused = true
                // Suggest a name based on current filters
                if name.isEmpty {
                    name = suggestedName
                }
            }
            .disabled(isSaving)
            .overlay {
                if isSaving {
                    Color.black.opacity(0.2)
                        .ignoresSafeArea()
                    ProgressView("Saving...")
                        .padding()
                        .background(.regularMaterial)
                        .cornerRadius(10)
                }
            }
        }
    }

    // MARK: - Current Filters Summary

    private var schoolFiltersText: String? {
        var parts: [String] = []
        if viewModel.filters.nearAElementary { parts.append("A Elementary") }
        else if viewModel.filters.nearABElementary { parts.append("A/B Elementary") }
        if viewModel.filters.nearAMiddle { parts.append("A Middle") }
        else if viewModel.filters.nearABMiddle { parts.append("A/B Middle") }
        if viewModel.filters.nearAHigh { parts.append("A High") }
        else if viewModel.filters.nearABHigh { parts.append("A/B High") }
        if let grade = viewModel.filters.schoolGrade { parts.append("\(grade) District") }
        return parts.isEmpty ? nil : parts.joined(separator: ", ")
    }

    private var amenitiesText: String? {
        var parts: [String] = []
        if viewModel.filters.hasPool { parts.append("Pool") }
        if viewModel.filters.hasFireplace { parts.append("Fireplace") }
        if viewModel.filters.hasGarage { parts.append("Garage") }
        if viewModel.filters.hasWaterfront { parts.append("Waterfront") }
        if viewModel.filters.hasView { parts.append("View") }
        if viewModel.filters.hasCooling { parts.append("A/C") }
        if viewModel.filters.hasSpa { parts.append("Spa") }
        if viewModel.filters.hasVirtualTour { parts.append("Virtual Tour") }
        if viewModel.filters.isSeniorCommunity { parts.append("55+") }
        if viewModel.filters.hasOutdoorSpace { parts.append("Outdoor Space") }
        if viewModel.filters.isAttached == true { parts.append("Attached") }
        if viewModel.filters.isLenderOwned { parts.append("Lender Owned") }
        if viewModel.filters.hasDPR { parts.append("DPR") }
        if viewModel.filters.hasWaterView { parts.append("Water View") }
        return parts.isEmpty ? nil : parts.joined(separator: ", ")
    }

    private var currentFiltersSummary: some View {
        VStack(alignment: .leading, spacing: 8) {
            filterRow(label: "Listing Type", value: viewModel.filters.listingType.displayName)

            if !viewModel.filters.cities.isEmpty {
                filterRow(label: "Cities", value: viewModel.filters.cities.joined(separator: ", "))
            }

            if !viewModel.filters.propertyTypes.isEmpty {
                filterRow(label: "Property Types", value: viewModel.filters.propertyTypes.map { $0.displayLabel }.joined(separator: ", "))
            }

            if viewModel.filters.minPrice != nil || viewModel.filters.maxPrice != nil {
                filterRow(label: "Price", value: formatPriceRange(
                    min: viewModel.filters.minPrice,
                    max: viewModel.filters.maxPrice
                ))
            }

            if !viewModel.filters.beds.isEmpty {
                filterRow(label: "Bedrooms", value: viewModel.filters.beds.map { "\($0)+" }.joined(separator: ", "))
            }

            if let minBaths = viewModel.filters.minBaths {
                filterRow(label: "Bathrooms", value: "\(minBaths)+")
            }

            if let minSqft = viewModel.filters.minSqft, let maxSqft = viewModel.filters.maxSqft {
                filterRow(label: "Sq Ft", value: "\(minSqft.formatted()) - \(maxSqft.formatted())")
            } else if let minSqft = viewModel.filters.minSqft {
                filterRow(label: "Sq Ft", value: "\(minSqft.formatted())+")
            } else if let maxSqft = viewModel.filters.maxSqft {
                filterRow(label: "Sq Ft", value: "Up to \(maxSqft.formatted())")
            }

            // Year Built
            if let minYear = viewModel.filters.minYearBuilt, let maxYear = viewModel.filters.maxYearBuilt {
                filterRow(label: "Year Built", value: "\(minYear) - \(maxYear)")
            } else if let minYear = viewModel.filters.minYearBuilt {
                filterRow(label: "Year Built", value: "\(minYear)+")
            } else if let maxYear = viewModel.filters.maxYearBuilt {
                filterRow(label: "Year Built", value: "Before \(maxYear)")
            }

            // Status (if not just Active)
            if viewModel.filters.statuses != [.active] {
                filterRow(label: "Status", value: viewModel.filters.statuses.map { $0.displayName }.joined(separator: ", "))
            }

            // School Filters
            if let schoolText = schoolFiltersText {
                filterRow(label: "Schools", value: schoolText)
            }

            // Amenities
            if let amenityText = amenitiesText {
                filterRow(label: "Amenities", value: amenityText)
            }

            // Special filters
            if viewModel.filters.priceReduced == true {
                filterRow(label: "Special", value: "Price Reduced")
            }

            if viewModel.filters.newListing {
                filterRow(label: "Listing Age", value: "New This Week")
            }

            if viewModel.filters.openHouseOnly {
                filterRow(label: "Open Houses", value: "Only")
            }

            if viewModel.filters.polygonCoordinates != nil {
                filterRow(label: "Area", value: "Custom Drawn Area")
            }
        }
    }

    private func filterRow(label: String, value: String) -> some View {
        HStack {
            Text(label)
                .foregroundColor(.secondary)
            Spacer()
            Text(value)
                .foregroundColor(.primary)
                .multilineTextAlignment(.trailing)
        }
        .font(.subheadline)
    }

    private func formatPriceRange(min: Int?, max: Int?) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0

        if let min = min, let max = max {
            return "\(formatter.string(from: NSNumber(value: min)) ?? "") - \(formatter.string(from: NSNumber(value: max)) ?? "")"
        } else if let min = min {
            return "\(formatter.string(from: NSNumber(value: min)) ?? "")+"
        } else if let max = max {
            return "Up to \(formatter.string(from: NSNumber(value: max)) ?? "")"
        }
        return "Any"
    }

    // MARK: - Suggested Name (v216 - improved auto-generation)

    private var suggestedName: String {
        var parts: [String] = []

        // Location first
        if let firstCity = viewModel.filters.cities.first {
            parts.append(firstCity)
        }

        // Beds
        if !viewModel.filters.beds.isEmpty, let minBeds = viewModel.filters.beds.min() {
            parts.append("\(minBeds)+ bed")
        }

        // Price
        if let maxPrice = viewModel.filters.maxPrice {
            if maxPrice >= 1_000_000 {
                let millions = Double(maxPrice) / 1_000_000.0
                if millions == Double(Int(millions)) {
                    parts.append("under $\(Int(millions))M")
                } else {
                    parts.append("under $\(String(format: "%.1f", millions))M")
                }
            } else {
                parts.append("under $\(maxPrice / 1000)K")
            }
        }

        // Property type as fallback
        if parts.isEmpty, let firstType = viewModel.filters.propertyTypes.first {
            parts.append(firstType.displayLabel)
        }

        // Listing type as last fallback
        if parts.isEmpty {
            parts.append(viewModel.filters.listingType.displayName)
        }

        return parts.isEmpty ? "My Search" : parts.joined(separator: " ")
    }

    // MARK: - Save Action

    private func saveSearch() {
        let trimmedName = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmedName.isEmpty else { return }

        isSaving = true
        errorMessage = nil

        Task {
            // Check if saving for clients (agent flow)
            if isAgent && !saveForSelf && !selectedClientIds.isEmpty {
                // Batch create searches for clients
                await saveSearchesForClients(name: trimmedName)
            } else {
                // Save for self (normal flow)
                await viewModel.saveCurrentSearch(
                    name: trimmedName,
                    notificationFrequency: notificationFrequency
                )

                await MainActor.run {
                    isSaving = false
                    if case .error(let message) = viewModel.savedSearchSyncState {
                        errorMessage = message
                    } else {
                        // Show toast and dismiss (v216)
                        ToastManager.shared.success("Search saved!", icon: "bookmark.fill")
                        dismiss()
                    }
                }
            }
        }
    }

    /// Save searches for selected clients (agent only)
    private func saveSearchesForClients(name: String) async {
        do {
            // Get filters in web-compatible format
            let filters = viewModel.filters.toSavedSearchDictionary()

            let response = try await AgentService.shared.createSearchesForClients(
                clientIds: Array(selectedClientIds),
                name: name,
                filters: filters,
                notificationFrequency: notificationFrequency.rawValue,
                agentNotes: agentNote.isEmpty ? nil : agentNote,
                ccAgentOnNotify: ccOnNotify
            )

            await MainActor.run {
                isSaving = false
                // Show toast and dismiss (v216)
                let message = "Search created for \(response.createdCount) client(s)"
                ToastManager.shared.success(message, icon: "person.2.fill")
                dismiss()
            }
        } catch {
            await MainActor.run {
                isSaving = false
                errorMessage = error.userFriendlyMessage
            }
        }
    }

    /// Load agent's clients
    private func loadClients() {
        isLoadingClients = true

        Task {
            do {
                let fetchedClients = try await AgentService.shared.fetchAgentClients(forceRefresh: true)
                await MainActor.run {
                    clients = fetchedClients
                    isLoadingClients = false
                }
            } catch {
                await MainActor.run {
                    isLoadingClients = false
                    errorMessage = "Failed to load clients"
                }
            }
        }
    }
}

// MARK: - Preview

#Preview {
    CreateSavedSearchSheet()
        .environmentObject(PropertySearchViewModel())
        .environmentObject(AuthViewModel())
}
