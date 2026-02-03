//
//  SavedSearchDetailView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Detail view for editing a saved search
//

import SwiftUI

struct SavedSearchDetailView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @Environment(\.dismiss) var dismiss

    let search: SavedSearch

    @State private var name: String
    @State private var description: String
    @State private var notificationFrequency: NotificationFrequency
    @State private var isActive: Bool

    @State private var isSaving = false
    @State private var showDeleteConfirmation = false
    @State private var errorMessage: String?
    @State private var hasChanges = false

    init(search: SavedSearch) {
        self.search = search
        _name = State(initialValue: search.name)
        _description = State(initialValue: search.description ?? "")
        _notificationFrequency = State(initialValue: search.notificationFrequency)
        _isActive = State(initialValue: search.isActive)
    }

    var body: some View {
        NavigationView {
            Form {
                // Agent Pick Banner (Phase 5)
                if search.isAgentCreated {
                    Section {
                        HStack(spacing: 12) {
                            Image(systemName: "star.fill")
                                .foregroundStyle(.orange)
                                .font(.title3)

                            VStack(alignment: .leading, spacing: 2) {
                                Text("Agent Recommended")
                                    .font(.subheadline)
                                    .fontWeight(.semibold)
                                Text("Your agent created this search for you")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            Spacer()

                            AgentPickBadge(compact: true)
                        }
                        .padding(.vertical, 4)
                    }
                }

                // Agent Notes (Phase 5)
                if search.hasAgentNotes, let notes = search.agentNotes {
                    Section {
                        AgentNotesView(notes: notes, agentName: nil)
                    } header: {
                        Text("Agent Notes")
                    }
                }

                // Name & Description
                Section {
                    TextField("Search Name", text: $name)
                        .onChange(of: name) { _ in hasChanges = true }

                    TextField("Description (optional)", text: $description, axis: .vertical)
                        .lineLimit(2...4)
                        .onChange(of: description) { _ in hasChanges = true }
                } header: {
                    Text("Details")
                }

                // Active Toggle
                Section {
                    Toggle(isOn: $isActive) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Notifications Active")
                            Text(isActive ? "You'll receive alerts for new matches" : "Notifications paused")
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                    }
                    .onChange(of: isActive) { _ in hasChanges = true }
                    .tint(AppColors.brandTeal)
                }

                // Notification Frequency
                Section {
                    Picker("Alert Frequency", selection: $notificationFrequency) {
                        ForEach(NotificationFrequency.allCases, id: \.self) { frequency in
                            Text(frequency.displayName).tag(frequency)
                        }
                    }
                    .onChange(of: notificationFrequency) { _ in hasChanges = true }

                    Text(notificationFrequency.shortDescription)
                        .font(.caption)
                        .foregroundColor(.secondary)

                    // CC Agent toggle (Phase 5 - show only if search has agent collaboration)
                    if search.isAgentCreated == true || search.ccAgentOnNotify == true {
                        Toggle(isOn: .constant(search.ccAgentOnNotify ?? false)) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Copy Agent on Alerts")
                                Text("Your agent will also receive notifications")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .tint(AppColors.brandTeal)
                        .disabled(true) // Read-only for now - managed by agent
                    }
                } header: {
                    Text("Notifications")
                }

                // Filter Summary
                Section {
                    filtersSummaryView
                } header: {
                    Text("Search Criteria")
                } footer: {
                    Text("To change filters, apply this search and modify from the search screen")
                }

                // Metadata
                Section {
                    HStack {
                        Text("Created")
                        Spacer()
                        Text(search.formattedCreatedAt)
                            .foregroundColor(.secondary)
                    }

                    HStack {
                        Text("Last Updated")
                        Spacer()
                        Text(search.formattedUpdatedAt)
                            .foregroundColor(.secondary)
                    }

                    if let matchCount = search.matchCount {
                        HStack {
                            Text("Current Matches")
                            Spacer()
                            Text("\(matchCount)")
                                .foregroundColor(.secondary)
                        }
                    }
                } header: {
                    Text("Info")
                }

                // Delete Section
                Section {
                    Button(role: .destructive) {
                        showDeleteConfirmation = true
                    } label: {
                        HStack {
                            Spacer()
                            Label("Delete Saved Search", systemImage: "trash")
                            Spacer()
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
            .navigationTitle("Edit Search")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                }

                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        saveChanges()
                    }
                    .disabled(!hasChanges || name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || isSaving)
                    .fontWeight(.semibold)
                }
            }
            .alert("Delete Search", isPresented: $showDeleteConfirmation) {
                Button("Cancel", role: .cancel) {}
                Button("Delete", role: .destructive) {
                    deleteSearch()
                }
            } message: {
                Text("Are you sure you want to delete '\(search.name)'? This cannot be undone.")
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

    // MARK: - Filters Summary

    private var schoolFiltersText: String? {
        let filters = search.filters
        var parts: [String] = []
        if filters["near_a_elementary"]?.boolValue == true { parts.append("A Elementary") }
        else if filters["near_ab_elementary"]?.boolValue == true { parts.append("A/B Elementary") }
        if filters["near_a_middle"]?.boolValue == true { parts.append("A Middle") }
        else if filters["near_ab_middle"]?.boolValue == true { parts.append("A/B Middle") }
        if filters["near_a_high"]?.boolValue == true { parts.append("A High") }
        else if filters["near_ab_high"]?.boolValue == true { parts.append("A/B High") }
        if let grade = filters["school_grade"]?.stringValue {
            parts.append("\(grade) District")
        }
        return parts.isEmpty ? nil : parts.joined(separator: ", ")
    }

    private var amenitiesText: String? {
        let filters = search.filters
        var parts: [String] = []
        if filters["has_pool"]?.boolValue == true { parts.append("Pool") }
        if filters["has_fireplace"]?.boolValue == true { parts.append("Fireplace") }
        if filters["has_garage"]?.boolValue == true { parts.append("Garage") }
        if filters["has_waterfront"]?.boolValue == true { parts.append("Waterfront") }
        if filters["has_view"]?.boolValue == true { parts.append("View") }
        if filters["has_cooling"]?.boolValue == true { parts.append("A/C") }
        if filters["has_spa"]?.boolValue == true { parts.append("Spa") }
        return parts.isEmpty ? nil : parts.joined(separator: ", ")
    }

    private func formatPrice(_ price: Int) -> String {
        if price >= 1_000_000 {
            let millions = Double(price) / 1_000_000.0
            if millions.truncatingRemainder(dividingBy: 1) == 0 {
                return "$\(Int(millions))M"
            } else {
                return String(format: "$%.2fM", millions)
            }
        } else {
            return "$\(price / 1000)K"
        }
    }

    private var priceText: String? {
        let filters = search.filters
        let minPrice = filters["min_price"]?.intValue ?? filters["price_min"]?.intValue
        let maxPrice = filters["max_price"]?.intValue ?? filters["price_max"]?.intValue
        if let min = minPrice, let max = maxPrice {
            return "\(formatPrice(min)) - \(formatPrice(max))"
        } else if let min = minPrice {
            return "\(formatPrice(min))+"
        } else if let max = maxPrice {
            return "Up to \(formatPrice(max))"
        }
        return nil
    }

    private var listingTypeText: String? {
        let filters = search.filters
        if let listingType = filters["listing_type"]?.stringValue {
            return listingType == "for_sale" ? "For Sale" : "For Rent"
        }
        return nil
    }

    private var statusText: String? {
        let filters = search.filters
        if let statuses = filters["status"]?.stringArrayValue ?? filters["statuses"]?.stringArrayValue, !statuses.isEmpty {
            return statuses.joined(separator: ", ")
        }
        return nil
    }

    private var sqftText: String? {
        let filters = search.filters
        let minSqft = filters["sqft_min"]?.intValue ?? filters["min_sqft"]?.intValue
        let maxSqft = filters["sqft_max"]?.intValue ?? filters["max_sqft"]?.intValue
        if let min = minSqft, let max = maxSqft {
            return "\(min.formatted()) - \(max.formatted())"
        } else if let min = minSqft {
            return "\(min.formatted())+"
        } else if let max = maxSqft {
            return "Up to \(max.formatted())"
        }
        return nil
    }

    private var yearBuiltText: String? {
        let filters = search.filters
        let minYear = filters["year_built_min"]?.intValue ?? filters["min_year_built"]?.intValue
        let maxYear = filters["year_built_max"]?.intValue ?? filters["max_year_built"]?.intValue
        if let min = minYear, let max = maxYear {
            return "\(min) - \(max)"
        } else if let min = minYear {
            return "\(min)+"
        } else if let max = maxYear {
            return "Before \(max)"
        }
        return nil
    }

    private var filtersSummaryView: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(search.filterSummary)
                .font(.subheadline)
                .foregroundColor(.primary)

            // Show key filter details - handle both iOS and web keys
            let filters = search.filters

            // Listing Type (Buy/Rent)
            if let listingType = listingTypeText {
                filterChip(label: "Type", value: listingType)
            }

            // Status (Active, Pending, Sold)
            if let status = statusText {
                filterChip(label: "Status", value: status)
            }

            // Cities - handle both iOS "city" and web "City" keys
            if let cities = filters["city"]?.stringArrayValue ?? filters["City"]?.stringArrayValue, !cities.isEmpty {
                filterChip(label: "Cities", value: cities.joined(separator: ", "))
            }

            // Property Types - handle both iOS "property_type" and web "PropertyType" keys
            if let types = filters["property_type"]?.stringArrayValue ?? filters["PropertyType"]?.stringArrayValue, !types.isEmpty {
                filterChip(label: "Types", value: types.joined(separator: ", "))
            }

            // Price
            if let price = priceText {
                filterChip(label: "Price", value: price)
            }

            // Beds - handle both formats
            if let beds = filters["beds"]?.intValue ?? filters["Beds"]?.intValue {
                filterChip(label: "Beds", value: "\(beds)+")
            }

            // Baths - handle both formats
            if let baths = filters["baths"]?.doubleValue ?? filters["min_baths"]?.doubleValue {
                filterChip(label: "Baths", value: "\(Int(baths))+")
            }

            // Square Footage
            if let sqft = sqftText {
                filterChip(label: "Sq Ft", value: sqft)
            }

            // Year Built
            if let yearBuilt = yearBuiltText {
                filterChip(label: "Year Built", value: yearBuilt)
            }

            // School Filters
            if let schoolText = schoolFiltersText {
                filterChip(label: "Schools", value: schoolText)
            }

            // Amenities
            if let amenityText = amenitiesText {
                filterChip(label: "Amenities", value: amenityText)
            }

            // Special Filters
            if filters["price_reduced"]?.boolValue == true {
                filterChip(label: "Special", value: "Price Reduced")
            }

            if filters["new_listing_days"]?.intValue != nil {
                filterChip(label: "Listing Age", value: "New This Week")
            }

            if filters["open_house_only"]?.boolValue == true {
                filterChip(label: "Open Houses", value: "Only")
            }

            // Custom Polygon Area
            if search.polygonShapes != nil {
                filterChip(label: "Area", value: "Custom Drawn Area")
            }
        }
    }

    private func filterChip(label: String, value: String) -> some View {
        HStack {
            Text(label)
                .font(.caption)
                .foregroundColor(.secondary)
            Text(value)
                .font(.caption)
                .fontWeight(.medium)
                .foregroundColor(.primary)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(Color.gray.opacity(0.1))
        .cornerRadius(8)
    }

    // MARK: - Actions

    private func saveChanges() {
        let trimmedName = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmedName.isEmpty else { return }

        isSaving = true
        errorMessage = nil

        Task {
            do {
                let _ = try await SavedSearchService.shared.updateSearch(
                    id: search.id,
                    name: trimmedName,
                    description: description.isEmpty ? nil : description,
                    frequency: notificationFrequency,
                    isActive: isActive,
                    currentUpdatedAt: search.updatedAt
                )

                // Refresh the list
                await viewModel.loadSavedSearchesFromServer(forceRefresh: true)

                await MainActor.run {
                    isSaving = false
                    dismiss()
                }
            } catch {
                await MainActor.run {
                    isSaving = false
                    if case SavedSearchError.serverConflict = error {
                        errorMessage = "This search was modified elsewhere. Changes have been refreshed."
                        // Refresh to get latest
                        Task {
                            await viewModel.loadSavedSearchesFromServer(forceRefresh: true)
                        }
                    } else {
                        errorMessage = error.userFriendlyMessage
                    }
                }
            }
        }
    }

    private func deleteSearch() {
        isSaving = true

        Task {
            await viewModel.deleteSavedSearch(search)

            await MainActor.run {
                isSaving = false
                dismiss()
            }
        }
    }
}

// MARK: - Preview

// Preview removed - SavedSearch init requires decoder
