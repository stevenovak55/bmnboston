//
//  AdvancedFilterModal.swift
//  BMNBoston
//
//  Comprehensive filter modal matching MLD WordPress plugin 100%
//

import SwiftUI

struct AdvancedFilterModal: View {
    @Environment(\.dismiss) var dismiss
    @Binding var filters: PropertySearchFilters
    let onApply: (PropertySearchFilters) -> Void
    let onFetchCount: ((PropertySearchFilters) async -> Int?)?

    // Local state for editing
    @State private var localFilters: PropertySearchFilters

    // Section expansion states
    @State private var expandedSections: Set<FilterSection> = [.listingType, .homeType, .price, .beds, .baths]

    // Price input strings
    @State private var minPriceText: String = ""
    @State private var maxPriceText: String = ""

    // Sqft input strings
    @State private var minSqftText: String = ""
    @State private var maxSqftText: String = ""

    // Year built input strings
    @State private var minYearText: String = ""
    @State private var maxYearText: String = ""

    // Slider state for sqft (v216)
    @State private var sqftSliderMin: Double = 0
    @State private var sqftSliderMax: Double = 10000

    // Slider state for year built (v216)
    @State private var yearSliderMin: Double = 1900
    @State private var yearSliderMax: Double = 2026

    // Lot size input strings
    @State private var minLotText: String = ""
    @State private var maxLotText: String = ""

    // Loading state for count
    @State private var isLoadingCount: Bool = false
    @State private var matchingCount: Int?

    // Debounce task for count fetching
    @State private var countFetchTask: Task<Void, Never>?

    // Price distribution data for histogram
    @State private var priceDistributionData: PriceDistributionData?
    @State private var isLoadingPriceDistribution: Bool = false
    @State private var priceDistributionTask: Task<Void, Never>?

    enum FilterSection: String, CaseIterable {
        case listingType = "Listing Type"
        case homeType = "Property Type"
        case price = "Price Range"
        case beds = "Bedrooms"
        case baths = "Bathrooms"
        case rentalDetails = "Rental Details"  // Only shown for rentals
        case schools = "Schools"
        case status = "Status"
        case sqft = "Square Footage"
        case yearBuilt = "Year Built"
        case lotSize = "Lot Size"
        case parking = "Parking & Garage"
        case amenities = "Features & Amenities"
        case special = "Special Filters"
    }

    init(filters: Binding<PropertySearchFilters>, onApply: @escaping (PropertySearchFilters) -> Void, onFetchCount: ((PropertySearchFilters) async -> Int?)? = nil) {
        self._filters = filters
        self.onApply = onApply
        self.onFetchCount = onFetchCount
        self._localFilters = State(initialValue: filters.wrappedValue)
    }

    var body: some View {
        VStack(spacing: 0) {
            // Header
            filterHeader

            // Active filter chips
            if !localFilters.activeFilterChips.isEmpty {
                activeFilterChipsView
            }

            // Filter sections
            ScrollView {
                LazyVStack(spacing: 0) {
                    // All sections shown inline
                    ForEach(FilterSection.allCases, id: \.self) { section in
                        // Only show rental details section for For Rent listings
                        if section == .rentalDetails {
                            if localFilters.listingType == .forRent {
                                filterSectionView(section)
                            }
                        } else {
                            filterSectionView(section)
                        }
                    }
                }
                .padding(.bottom, 100) // Space for footer
            }
        }
        .background(Color(.systemGroupedBackground))
        .safeAreaInset(edge: .bottom) {
            applyButtonView
        }
        .onAppear {
            loadFilterValues()
            fetchCountDebounced()
            fetchPriceDistribution()
        }
        .onChange(of: localFilters) { _ in
            fetchCountDebounced()
        }
        .onChange(of: localFilters.listingType) { _ in
            fetchPriceDistribution()
        }
        .onChange(of: localFilters.propertyTypes) { _ in
            fetchPriceDistribution()
        }
        .onChange(of: localFilters.beds) { _ in
            fetchPriceDistribution()
        }
        .onChange(of: localFilters.minBaths) { _ in
            fetchPriceDistribution()
        }
    }

    // MARK: - Filter Header

    private var filterHeader: some View {
        HStack {
            Button("Cancel") {
                dismiss()
            }
            .foregroundStyle(.secondary)
            .accessibilityLabel("Cancel")
            .accessibilityHint("Double tap to close filters without applying changes")

            Spacer()

            Text("Filters")
                .font(.headline)
                .fontWeight(.semibold)
                .accessibilityAddTraits(.isHeader)

            Spacer()

            Button("Reset") {
                resetFilters()
            }
            .foregroundStyle(.red)
            .accessibilityLabel("Reset all filters")
            .accessibilityHint("Double tap to clear all filter selections")
        }
        .padding()
        .background(Color(.systemBackground))
    }

    // MARK: - Count Fetching

    private func fetchCountDebounced() {
        // Cancel any existing task
        countFetchTask?.cancel()

        // Start new debounced task
        countFetchTask = Task {
            try? await Task.sleep(nanoseconds: SearchConstants.filterModalDebounceNanoseconds)

            guard !Task.isCancelled else { return }

            await MainActor.run {
                isLoadingCount = true
            }

            if let fetchCount = onFetchCount {
                let count = await fetchCount(localFilters)
                await MainActor.run {
                    if !Task.isCancelled {
                        matchingCount = count
                        isLoadingCount = false
                    }
                }
            } else {
                await MainActor.run {
                    isLoadingCount = false
                }
            }
        }
    }

    // MARK: - Price Distribution Fetching

    private func fetchPriceDistribution() {
        // Cancel any existing task
        priceDistributionTask?.cancel()

        // Start new debounced task
        priceDistributionTask = Task {
            try? await Task.sleep(nanoseconds: SearchConstants.filterPriceDebounceNanoseconds)

            guard !Task.isCancelled else { return }

            await MainActor.run {
                isLoadingPriceDistribution = true
            }

            do {
                let response: PriceDistributionResponse = try await APIClient.shared.request(
                    .priceDistribution(filters: localFilters)
                )

                await MainActor.run {
                    if !Task.isCancelled {
                        priceDistributionData = response.data
                        isLoadingPriceDistribution = false
                    }
                }
            } catch {
                await MainActor.run {
                    isLoadingPriceDistribution = false
                }
            }
        }
    }

    // MARK: - Active Filter Chips

    private var activeFilterChipsView: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(localFilters.activeFilterChips) { chip in
                    FilterChipView(chip: chip) {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            localFilters.removeFilter(chipId: chip.id)
                            syncFilterValues()
                        }
                    }
                }

                if localFilters.activeFilterCount > 1 {
                    Button {
                        withAnimation {
                            resetFilters()
                        }
                    } label: {
                        Text("Clear All")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.red)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(Color.red.opacity(0.1))
                            .clipShape(Capsule())
                    }
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 12)
        }
        .background(Color(.systemBackground))
    }

    // MARK: - Filter Section View

    @ViewBuilder
    private func filterSectionView(_ section: FilterSection) -> some View {
        VStack(spacing: 0) {
            // Section Header
            Button {
                withAnimation(.easeInOut(duration: 0.2)) {
                    if expandedSections.contains(section) {
                        expandedSections.remove(section)
                    } else {
                        expandedSections.insert(section)
                    }
                }
            } label: {
                HStack {
                    Text(section.rawValue)
                        .font(.headline)
                        .foregroundStyle(.primary)

                    Spacer()

                    // Badge for active filters in section
                    if sectionHasActiveFilters(section) {
                        Circle()
                            .fill(AppColors.brandTeal)
                            .frame(width: 8, height: 8)
                    }

                    Image(systemName: expandedSections.contains(section) ? "chevron.up" : "chevron.down")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding()
                .background(Color(.systemBackground))
            }
            .buttonStyle(.plain)

            // Section Content
            if expandedSections.contains(section) {
                sectionContent(section)
                    .padding()
                    .background(Color(.systemBackground))
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }

            Divider()
        }
    }

    @ViewBuilder
    private func sectionContent(_ section: FilterSection) -> some View {
        switch section {
        case .listingType:
            listingTypeSection
        case .homeType:
            homeTypeSection
        case .price:
            priceSection
        case .beds:
            bedsSection
        case .baths:
            bathsSection
        case .rentalDetails:
            rentalDetailsSection
        case .schools:
            schoolsSection
        case .status:
            statusSection
        case .sqft:
            sqftSection
        case .yearBuilt:
            yearBuiltSection
        case .lotSize:
            lotSizeSection
        case .parking:
            parkingSection
        case .amenities:
            amenitiesSection
        case .special:
            specialSection
        }
    }

    // MARK: - Listing Type Section

    private var listingTypeSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Looking to buy or rent?")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            HStack(spacing: 12) {
                ForEach(ListingType.allCases) { type in
                    Button {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            HapticManager.selection()
                            localFilters.listingType = type
                            // Set default property type for the selected listing type
                            switch type {
                            case .forSale:
                                localFilters.propertyTypes = [.house, .condo]
                            case .forRent:
                                localFilters.propertyTypes = [.residentialRental]
                            }
                        }
                    } label: {
                        HStack(spacing: 8) {
                            Image(systemName: type.icon)
                                .font(.title3)
                            Text(type.displayName)
                                .font(.headline)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 16)
                        .background(
                            RoundedRectangle(cornerRadius: 12)
                                .fill(localFilters.listingType == type ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        )
                        .foregroundStyle(localFilters.listingType == type ? .white : .primary)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    // MARK: - Price Section

    private var priceSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Price histogram slider (with histogram if data available)
            // v6.60.0: Pass isRental for appropriate price increments
            PriceHistogramSlider(
                minPrice: $localFilters.minPrice,
                maxPrice: $localFilters.maxPrice,
                priceData: priceDistributionData,
                onPriceChanged: {
                    fetchCountDebounced()
                },
                isRental: localFilters.listingType == .forRent
            )
        }
    }

    // MARK: - Beds Section

    private var bedsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            MinValueSlider(
                value: minBedsBinding,
                range: 0...5,
                formatValue: { value in
                    if let val = value {
                        return val == 5 ? "5+ Bedrooms" : "\(val)+ Bedroom\(val == 1 ? "" : "s")"
                    }
                    return "Any Bedrooms"
                },
                onEditingChanged: {
                    fetchCountDebounced()
                }
            )
        }
    }

    /// Binding to convert between Set<Int> and Int? for the slider
    private var minBedsBinding: Binding<Int?> {
        Binding(
            get: {
                localFilters.beds.isEmpty ? nil : localFilters.beds.min()
            },
            set: { newValue in
                if let value = newValue, value > 0 {
                    localFilters.beds = Set([value])
                } else {
                    localFilters.beds = []
                }
            }
        )
    }

    // MARK: - Baths Section

    private var bathsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            MinValueSliderDouble(
                value: $localFilters.minBaths,
                steps: [0, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5],
                formatValue: { value in
                    if let val = value {
                        if val == 5 {
                            return "5+ Bathrooms"
                        } else if val == floor(val) {
                            return "\(Int(val))+ Bathroom\(Int(val) == 1 ? "" : "s")"
                        } else {
                            return "\(val.formatted())+ Bathrooms"
                        }
                    }
                    return "Any Bathrooms"
                },
                onEditingChanged: {
                    fetchCountDebounced()
                }
            )
        }
    }

    // MARK: - Rental Details Section (Phase 1)

    private var rentalDetailsSection: some View {
        VStack(alignment: .leading, spacing: 20) {
            // Available Now Toggle
            Toggle(isOn: $localFilters.availableNow) {
                HStack(spacing: 8) {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(localFilters.availableNow ? AppColors.brandTeal : AppColors.textSecondary)
                    Text("Available Now")
                        .font(.subheadline)
                        .fontWeight(.medium)
                }
            }
            .tint(AppColors.brandTeal)
            .onChange(of: localFilters.availableNow) { _ in
                HapticManager.selection()
            }

            // Available By Date Picker
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Text("Available By")
                        .font(.subheadline)
                        .fontWeight(.medium)
                    Spacer()
                    if localFilters.availableBy != nil {
                        Button {
                            HapticManager.selection()
                            localFilters.availableBy = nil
                        } label: {
                            Text("Clear")
                                .font(.caption)
                                .foregroundStyle(AppColors.brandTeal)
                        }
                    }
                }

                DatePicker(
                    "Move-in date",
                    selection: Binding(
                        get: { localFilters.availableBy ?? Date() },
                        set: { localFilters.availableBy = $0 }
                    ),
                    in: Date()...,
                    displayedComponents: .date
                )
                .datePickerStyle(.compact)
                .labelsHidden()
                .tint(AppColors.brandTeal)
                .onChange(of: localFilters.availableBy) { _ in
                    HapticManager.selection()
                }

                Text("Show rentals available by this date")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            .padding(.vertical, 4)

            Divider()

            // Pets - Multi-select: Dogs, Cats, No Pets, Negotiable (v6.60.2)
            VStack(alignment: .leading, spacing: 8) {
                Text("Pets")
                    .font(.subheadline)
                    .fontWeight(.medium)

                // First row: Dogs, Cats
                HStack(spacing: 8) {
                    // Dogs Allowed
                    Button {
                        HapticManager.selection()
                        localFilters.petsDogs.toggle()
                    } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "dog.fill")
                                .font(.system(size: 12))
                            Text("Dogs")
                        }
                        .font(.subheadline)
                        .fontWeight(localFilters.petsDogs ? .semibold : .regular)
                        .foregroundStyle(localFilters.petsDogs ? .white : AppColors.textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(localFilters.petsDogs ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)

                    // Cats Allowed
                    Button {
                        HapticManager.selection()
                        localFilters.petsCats.toggle()
                    } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "cat.fill")
                                .font(.system(size: 12))
                            Text("Cats")
                        }
                        .font(.subheadline)
                        .fontWeight(localFilters.petsCats ? .semibold : .regular)
                        .foregroundStyle(localFilters.petsCats ? .white : AppColors.textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(localFilters.petsCats ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)
                }

                // Second row: No Pets, Negotiable
                HStack(spacing: 8) {
                    // No Pets
                    Button {
                        HapticManager.selection()
                        localFilters.petsNone.toggle()
                    } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "nosign")
                                .font(.system(size: 12))
                            Text("No Pets")
                        }
                        .font(.subheadline)
                        .fontWeight(localFilters.petsNone ? .semibold : .regular)
                        .foregroundStyle(localFilters.petsNone ? .white : AppColors.textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(localFilters.petsNone ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)

                    // Negotiable
                    Button {
                        HapticManager.selection()
                        localFilters.petsNegotiable.toggle()
                    } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "questionmark.bubble.fill")
                                .font(.system(size: 12))
                            Text("Negotiable")
                        }
                        .font(.subheadline)
                        .fontWeight(localFilters.petsNegotiable ? .semibold : .regular)
                        .foregroundStyle(localFilters.petsNegotiable ? .white : AppColors.textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(localFilters.petsNegotiable ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)
                }
            }

            // Laundry Features - Multi-select chips
            VStack(alignment: .leading, spacing: 8) {
                Text("Laundry")
                    .font(.subheadline)
                    .fontWeight(.medium)

                let laundryOptions = ["In Unit", "In Building", "In Basement", "Hookups", "None"]

                LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                    ForEach(laundryOptions, id: \.self) { option in
                        Button {
                            HapticManager.selection()
                            if localFilters.laundryTypes.contains(option) {
                                localFilters.laundryTypes.remove(option)
                            } else {
                                localFilters.laundryTypes.insert(option)
                            }
                        } label: {
                            let isSelected = localFilters.laundryTypes.contains(option)
                            HStack(spacing: 4) {
                                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                                    .font(.system(size: 14))
                                Text(option)
                                    .font(.subheadline)
                                    .lineLimit(1)
                            }
                            .fontWeight(isSelected ? .semibold : .regular)
                            .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }

            // Lease Terms - Multi-select buttons
            VStack(alignment: .leading, spacing: 8) {
                Text("Lease Term")
                    .font(.subheadline)
                    .fontWeight(.medium)

                let leaseOptions = ["12 Months", "6 Months", "Monthly", "Flexible"]

                LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                    ForEach(leaseOptions, id: \.self) { option in
                        Button {
                            HapticManager.selection()
                            if localFilters.leaseTerms.contains(option) {
                                localFilters.leaseTerms.remove(option)
                            } else {
                                localFilters.leaseTerms.insert(option)
                            }
                        } label: {
                            let isSelected = localFilters.leaseTerms.contains(option)
                            HStack(spacing: 4) {
                                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                                    .font(.system(size: 14))
                                Text(option)
                                    .font(.subheadline)
                                    .lineLimit(1)
                            }
                            .fontWeight(isSelected ? .semibold : .regular)
                            .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 10)
                            .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    // MARK: - Property Type Section

    private var homeTypeSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Select property types for \(localFilters.listingType.displayName)")
                .font(.caption)
                .foregroundStyle(.secondary)

            // Property types based on listing type (For Sale vs For Rent) using CombinedPropertyType
            let availableTypes = localFilters.listingType.combinedPropertyTypes

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                ForEach(availableTypes) { propertyType in
                    Button {
                        togglePropertyType(propertyType)
                    } label: {
                        let isSelected = localFilters.propertyTypes.contains(propertyType)
                        HStack(spacing: 8) {
                            Image(systemName: propertyType.icon)
                                .font(.system(size: 14))
                            Text(propertyType.displayLabel)
                                .font(.subheadline)
                                .lineLimit(1)
                                .minimumScaleFactor(0.8)
                        }
                        .fontWeight(isSelected ? .semibold : .regular)
                        .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                        .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private func togglePropertyType(_ type: CombinedPropertyType) {
        HapticManager.selection()
        if localFilters.propertyTypes.contains(type) {
            // Only allow removal if there will still be at least one property type selected
            if localFilters.propertyTypes.count > 1 {
                localFilters.propertyTypes.remove(type)
            }
            // If this is the last one, don't remove it (require at least one)
        } else {
            localFilters.propertyTypes.insert(type)
        }
    }

    // MARK: - Status Section

    private var statusSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Select listing status")
                .font(.caption)
                .foregroundStyle(.secondary)

            HStack(spacing: 8) {
                ForEach(PropertyStatus.filterOptions, id: \.self) { status in
                    Button {
                        toggleStatus(status)
                    } label: {
                        // Check for both .sold and .closed since API returns "Closed" for sold listings
                        let isSelected = localFilters.statuses.contains(status) ||
                                        (status == .sold && localFilters.statuses.contains(.closed))
                        Text(status.displayName)
                            .font(.subheadline)
                            .fontWeight(isSelected ? .semibold : .regular)
                            .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 12)
                            .background(isSelected ? Color(hex: status.color) : Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private func toggleStatus(_ status: PropertyStatus) {
        if localFilters.statuses.contains(status) {
            if localFilters.statuses.count > 1 {
                localFilters.statuses.remove(status)
            }
        } else {
            localFilters.statuses.insert(status)
        }
    }

    // MARK: - Sqft Section

    private var sqftSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Range slider (v216)
            RangeSlider(
                minValue: $sqftSliderMin,
                maxValue: $sqftSliderMax,
                range: 0...10000,
                step: 100,
                formatValue: { value in
                    if value == 0 { return "No Min" }
                    if value >= 10000 { return "10,000+" }
                    return "\(Int(value).formatted()) sqft"
                },
                onEditingChanged: {
                    // Sync slider to filter values
                    localFilters.minSqft = sqftSliderMin > 0 ? Int(sqftSliderMin) : nil
                    localFilters.maxSqft = sqftSliderMax < 10000 ? Int(sqftSliderMax) : nil
                    syncFilterValues()
                }
            )
            .padding(.horizontal, 4)

            // Text input row (for precise values)
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Minimum")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("No Min", text: $minSqftText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: minSqftText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            minSqftText = filtered
                            if let value = Int(filtered) {
                                localFilters.minSqft = value
                                sqftSliderMin = Double(value)
                            } else {
                                localFilters.minSqft = nil
                                sqftSliderMin = 0
                            }
                        }
                }

                Text("to")
                    .foregroundStyle(.secondary)
                    .padding(.top, 24)

                VStack(alignment: .leading, spacing: 4) {
                    Text("Maximum")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("No Max", text: $maxSqftText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: maxSqftText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            maxSqftText = filtered
                            if let value = Int(filtered) {
                                localFilters.maxSqft = value
                                sqftSliderMax = min(Double(value), 10000)
                            } else {
                                localFilters.maxSqft = nil
                                sqftSliderMax = 10000
                            }
                        }
                }
            }

            // Quick sqft presets
            HStack(spacing: 8) {
                ForEach(sqftPresets, id: \.label) { preset in
                    Button {
                        localFilters.minSqft = preset.min
                        localFilters.maxSqft = preset.max
                        // Update slider positions
                        sqftSliderMin = Double(preset.min ?? 0)
                        sqftSliderMax = Double(preset.max ?? 10000)
                        syncFilterValues()
                    } label: {
                        Text(preset.label)
                            .font(.caption)
                            .foregroundStyle(AppColors.brandTeal)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(AppColors.brandTeal.opacity(0.1))
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private var sqftPresets: [(label: String, min: Int?, max: Int?)] {
        [
            ("1,000+", 1000, nil),
            ("1,500+", 1500, nil),
            ("2,000+", 2000, nil),
            ("3,000+", 3000, nil)
        ]
    }

    // MARK: - Year Built Section

    private var yearBuiltSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Range slider (v216)
            RangeSlider(
                minValue: $yearSliderMin,
                maxValue: $yearSliderMax,
                range: 1900...2026,
                step: 1,
                formatValue: { value in
                    if value <= 1900 { return "Any" }
                    if value >= 2026 { return "2026" }
                    return String(Int(value))
                },
                onEditingChanged: {
                    // Sync slider to filter values
                    localFilters.minYearBuilt = yearSliderMin > 1900 ? Int(yearSliderMin) : nil
                    localFilters.maxYearBuilt = yearSliderMax < 2026 ? Int(yearSliderMax) : nil
                    syncFilterValues()
                }
            )
            .padding(.horizontal, 4)

            // Text input row (for precise values)
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("From")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("Any", text: $minYearText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: minYearText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            minYearText = String(filtered.prefix(4))
                            if let value = Int(minYearText), value >= 1900 {
                                localFilters.minYearBuilt = value
                                yearSliderMin = Double(value)
                            } else {
                                localFilters.minYearBuilt = nil
                                yearSliderMin = 1900
                            }
                        }
                }

                Text("to")
                    .foregroundStyle(.secondary)
                    .padding(.top, 24)

                VStack(alignment: .leading, spacing: 4) {
                    Text("To")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("Any", text: $maxYearText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: maxYearText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            maxYearText = String(filtered.prefix(4))
                            if let value = Int(maxYearText), value <= 2026 {
                                localFilters.maxYearBuilt = value
                                yearSliderMax = Double(value)
                            } else {
                                localFilters.maxYearBuilt = nil
                                yearSliderMax = 2026
                            }
                        }
                }
            }

            // Quick year presets
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(yearPresets, id: \.label) { preset in
                        Button {
                            localFilters.minYearBuilt = preset.min
                            localFilters.maxYearBuilt = preset.max
                            // Update slider positions
                            yearSliderMin = Double(preset.min ?? 1900)
                            yearSliderMax = Double(preset.max ?? 2026)
                            syncFilterValues()
                        } label: {
                            Text(preset.label)
                                .font(.caption)
                                .foregroundStyle(AppColors.brandTeal)
                                .padding(.horizontal, 12)
                                .padding(.vertical, 6)
                                .background(AppColors.brandTeal.opacity(0.1))
                                .clipShape(Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private var yearPresets: [(label: String, min: Int?, max: Int?)] {
        [
            ("New (2020+)", 2020, nil),
            ("2010+", 2010, nil),
            ("2000+", 2000, nil),
            ("Historic (<1950)", nil, 1950)
        ]
    }

    // MARK: - Lot Size Section

    private var lotSizeSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Minimum (sqft)")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("No Min", text: $minLotText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: minLotText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            minLotText = filtered
                            localFilters.minLotSize = Double(filtered)
                        }
                }

                Text("to")
                    .foregroundStyle(.secondary)
                    .padding(.top, 24)

                VStack(alignment: .leading, spacing: 4) {
                    Text("Maximum (sqft)")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    TextField("No Max", text: $maxLotText)
                        .keyboardType(.numberPad)
                        .padding(12)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .onChange(of: maxLotText) { newValue in
                            let filtered = newValue.filter { $0.isNumber }
                            maxLotText = filtered
                            localFilters.maxLotSize = Double(filtered)
                        }
                }
            }

            // Quick lot presets
            HStack(spacing: 8) {
                ForEach(lotPresets, id: \.label) { preset in
                    Button {
                        localFilters.minLotSize = preset.min
                        localFilters.maxLotSize = preset.max
                        syncFilterValues()
                    } label: {
                        Text(preset.label)
                            .font(.caption)
                            .foregroundStyle(AppColors.brandTeal)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(AppColors.brandTeal.opacity(0.1))
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private var lotPresets: [(label: String, min: Double?, max: Double?)] {
        [
            ("1/4 acre+", 10890, nil),
            ("1/2 acre+", 21780, nil),
            ("1 acre+", 43560, nil),
            ("2+ acres", 87120, nil)
        ]
    }

    // MARK: - Parking Section

    private var parkingSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Garage Spaces
            VStack(alignment: .leading, spacing: 8) {
                Text("Minimum Garage Spaces")
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    ForEach([0, 1, 2, 3, 4], id: \.self) { spaces in
                        Button {
                            localFilters.minGarageSpaces = spaces == 0 ? nil : spaces
                        } label: {
                            let isSelected = (spaces == 0 && localFilters.minGarageSpaces == nil) || localFilters.minGarageSpaces == spaces
                            Text(spaces == 0 ? "Any" : "\(spaces)+")
                                .font(.subheadline)
                                .fontWeight(isSelected ? .semibold : .regular)
                                .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 10)
                                .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }

            // Total Parking
            VStack(alignment: .leading, spacing: 8) {
                Text("Minimum Parking Spaces")
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    ForEach([0, 1, 2, 3, 4, 5], id: \.self) { spaces in
                        Button {
                            localFilters.minParkingTotal = spaces == 0 ? nil : spaces
                        } label: {
                            let isSelected = (spaces == 0 && localFilters.minParkingTotal == nil) || localFilters.minParkingTotal == spaces
                            Text(spaces == 0 ? "Any" : "\(spaces)+")
                                .font(.subheadline)
                                .fontWeight(isSelected ? .semibold : .regular)
                                .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 10)
                                .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                                .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    // MARK: - Amenities Section

    private var amenitiesSection: some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
            AmenityFilterToggle(label: "Pool", icon: "figure.pool.swim", isOn: $localFilters.hasPool)
            AmenityFilterToggle(label: "Waterfront", icon: "water.waves", isOn: $localFilters.hasWaterfront)
            AmenityFilterToggle(label: "View", icon: "eye.fill", isOn: $localFilters.hasView)
            AmenityFilterToggle(label: "Water View", icon: "sailboat.fill", isOn: $localFilters.hasWaterView)
            AmenityFilterToggle(label: "Fireplace", icon: "flame.fill", isOn: $localFilters.hasFireplace)
            AmenityFilterToggle(label: "Garage", icon: "car.fill", isOn: $localFilters.hasGarage)
            AmenityFilterToggle(label: "A/C", icon: "snowflake", isOn: $localFilters.hasCooling)
            AmenityFilterToggle(label: "Spa/Hot Tub", icon: "drop.fill", isOn: $localFilters.hasSpa)
            AmenityFilterToggle(label: "Outdoor Space", icon: "leaf.fill", isOn: $localFilters.hasOutdoorSpace)
            AmenityFilterToggle(label: "Virtual Tour", icon: "video.fill", isOn: $localFilters.hasVirtualTour)
        }
    }

    // MARK: - Schools Section

    private var schoolsSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Explanation text
            Text("Filter properties by school district quality or proximity to top-rated schools")
                .font(.caption)
                .foregroundStyle(.secondary)

            // District Rating Filter (v89)
            VStack(alignment: .leading, spacing: 8) {
                Label("Minimum District Rating", systemImage: "building.2.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(AppColors.schoolGreen)

                Picker("District Rating", selection: $localFilters.schoolGrade) {
                    Text("Any").tag(String?.none)
                    Text("A").tag("A" as String?)
                    Text("B+").tag("B+" as String?)
                    Text("B").tag("B" as String?)
                    Text("C+").tag("C+" as String?)
                }
                .pickerStyle(.segmented)
            }

            Divider()

            // Elementary School (K-4) - mutually exclusive toggles
            VStack(alignment: .leading, spacing: 12) {
                Label("Elementary School (K-4)", systemImage: "building.columns.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(AppColors.schoolGreen)

                Toggle(isOn: $localFilters.nearAElementary) {
                    Text("Near A-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearAElementary) { newValue in
                    if newValue { localFilters.nearABElementary = false }
                }

                Toggle(isOn: $localFilters.nearABElementary) {
                    Text("Near A or B-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearABElementary) { newValue in
                    if newValue { localFilters.nearAElementary = false }
                }
            }

            Divider()

            // Middle School (4-8) - mutually exclusive toggles
            VStack(alignment: .leading, spacing: 12) {
                Label("Middle School (4-8)", systemImage: "books.vertical.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(AppColors.schoolGreen)

                Toggle(isOn: $localFilters.nearAMiddle) {
                    Text("Near A-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearAMiddle) { newValue in
                    if newValue { localFilters.nearABMiddle = false }
                }

                Toggle(isOn: $localFilters.nearABMiddle) {
                    Text("Near A or B-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearABMiddle) { newValue in
                    if newValue { localFilters.nearAMiddle = false }
                }
            }

            Divider()

            // High School (9-12) - mutually exclusive toggles
            VStack(alignment: .leading, spacing: 12) {
                Label("High School (9-12)", systemImage: "graduationcap.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(AppColors.schoolGreen)

                Toggle(isOn: $localFilters.nearAHigh) {
                    Text("Near A-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearAHigh) { newValue in
                    if newValue { localFilters.nearABHigh = false }
                }

                Toggle(isOn: $localFilters.nearABHigh) {
                    Text("Near A or B-rated school (within 1 mi)")
                        .font(.subheadline)
                }
                .tint(AppColors.schoolGreen)
                .onChange(of: localFilters.nearABHigh) { newValue in
                    if newValue { localFilters.nearAHigh = false }
                }
            }
        }
    }

    // MARK: - Special Section

    private var specialSection: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Open House
            Toggle(isOn: $localFilters.openHouseOnly) {
                HStack {
                    Image(systemName: "calendar.badge.clock")
                        .foregroundStyle(AppColors.brandTeal)
                    Text("Open House Only")
                        .font(.subheadline)
                }
            }
            .tint(AppColors.brandTeal)

            Divider()

            // New Listings
            Toggle(isOn: $localFilters.newListing) {
                HStack {
                    Image(systemName: "sparkles")
                        .foregroundStyle(.orange)
                    Text("New Listings (Last 7 Days)")
                        .font(.subheadline)
                }
            }
            .tint(AppColors.brandTeal)

            Divider()

            // Price Reduced
            Toggle(isOn: $localFilters.priceReduced) {
                HStack {
                    Image(systemName: "arrow.down.circle.fill")
                        .foregroundStyle(.green)
                    Text("Price Reduced")
                        .font(.subheadline)
                }
            }
            .tint(AppColors.brandTeal)

            Divider()

            // Senior Community
            Toggle(isOn: $localFilters.isSeniorCommunity) {
                HStack {
                    Image(systemName: "person.2.fill")
                        .foregroundStyle(.purple)
                    Text("Senior Community")
                        .font(.subheadline)
                }
            }
            .tint(AppColors.brandTeal)

            Divider()

            // v6.64.0 / v284: Exclusive Listings Only
            Toggle(isOn: $localFilters.exclusiveOnly) {
                HStack {
                    Image(systemName: "star.fill")
                        .foregroundStyle(Color(red: 0.85, green: 0.65, blue: 0.13))  // Gold color
                    Text("Exclusive Listings Only")
                        .font(.subheadline)
                }
            }
            .tint(AppColors.brandTeal)

            Divider()

            // Maximum Days on Market
            VStack(alignment: .leading, spacing: 8) {
                Text("Maximum Days on Market")
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    ForEach([nil, 7, 14, 30, 90] as [Int?], id: \.self) { days in
                        Button {
                            localFilters.maxDaysOnMarket = days
                        } label: {
                            let isSelected = localFilters.maxDaysOnMarket == days
                            Text(days.map { "\($0)d" } ?? "Any")
                                .font(.caption)
                                .fontWeight(isSelected ? .semibold : .regular)
                                .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 8)
                                .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                                .clipShape(RoundedRectangle(cornerRadius: 6))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }

            Divider()

            // Minimum Days on Market
            VStack(alignment: .leading, spacing: 8) {
                Text("Minimum Days on Market")
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    ForEach([nil, 7, 14, 30, 90] as [Int?], id: \.self) { days in
                        Button {
                            localFilters.minDaysOnMarket = days
                        } label: {
                            let isSelected = localFilters.minDaysOnMarket == days
                            Text(days.map { "\($0)d" } ?? "Any")
                                .font(.caption)
                                .fontWeight(isSelected ? .semibold : .regular)
                                .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 8)
                                .background(isSelected ? AppColors.brandTeal : Color(.secondarySystemBackground))
                                .clipShape(RoundedRectangle(cornerRadius: 6))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    // MARK: - Apply Button

    private var applyButtonView: some View {
        VStack(spacing: 0) {
            Divider()

            HStack(spacing: 16) {
                // Clear all button
                Button {
                    resetFilters()
                } label: {
                    Text("Clear")
                        .font(.headline)
                        .foregroundStyle(AppColors.brandTeal)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(AppColors.brandTeal.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Clear all filters")
                .accessibilityHint("Double tap to reset all filter selections")

                // Apply button
                Button {
                    applyFilters()
                } label: {
                    HStack {
                        if isLoadingCount {
                            ProgressView()
                                .tint(.white)
                        } else {
                            Text(matchingCount != nil ? "See \(matchingCount!) Listings" : "Apply Filters")
                        }
                    }
                    .font(.headline)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(isLoadingCount ? "Loading results" : (matchingCount != nil ? "See \(matchingCount!) listings" : "Apply filters"))
                .accessibilityHint("Double tap to apply filters and view matching properties")
            }
            .padding()
            .background(Color(.systemBackground))
        }
    }

    // MARK: - Helper Functions

    private func sectionHasActiveFilters(_ section: FilterSection) -> Bool {
        switch section {
        case .listingType: return localFilters.listingType != .forSale  // Show dot if not default
        case .homeType:
            // Show dot if property type differs from default for the listing type
            let defaultType: Set<CombinedPropertyType> = localFilters.listingType == .forSale ? [.house, .condo] : [.residentialRental]
            return localFilters.propertyTypes != defaultType
        case .price: return localFilters.minPrice != nil || localFilters.maxPrice != nil
        case .beds: return !localFilters.beds.isEmpty
        case .baths: return localFilters.minBaths != nil
        case .rentalDetails: return localFilters.petsDogs || localFilters.petsCats ||
            localFilters.petsNone || localFilters.petsNegotiable ||
            !localFilters.laundryTypes.isEmpty || !localFilters.leaseTerms.isEmpty
        case .schools: return localFilters.schoolGrade != nil ||
            localFilters.nearAElementary || localFilters.nearABElementary ||
            localFilters.nearAMiddle || localFilters.nearABMiddle ||
            localFilters.nearAHigh || localFilters.nearABHigh ||
            localFilters.schoolDistrictId != nil
        case .status: return localFilters.statuses != [.active]
        case .sqft: return localFilters.minSqft != nil || localFilters.maxSqft != nil
        case .yearBuilt: return localFilters.minYearBuilt != nil || localFilters.maxYearBuilt != nil
        case .lotSize: return localFilters.minLotSize != nil || localFilters.maxLotSize != nil
        case .parking: return localFilters.minGarageSpaces != nil || localFilters.minParkingTotal != nil
        case .amenities: return localFilters.hasPool || localFilters.hasWaterfront || localFilters.hasView ||
            localFilters.hasFireplace || localFilters.hasGarage || localFilters.hasCooling ||
            localFilters.hasSpa || localFilters.hasOutdoorSpace || localFilters.hasVirtualTour
        case .special: return localFilters.openHouseOnly || localFilters.newListing || localFilters.priceReduced ||
            localFilters.isSeniorCommunity || localFilters.exclusiveOnly || localFilters.maxDaysOnMarket != nil || localFilters.minDaysOnMarket != nil
        }
    }

    private func loadFilterValues() {
        minPriceText = localFilters.minPrice.map { String($0) } ?? ""
        maxPriceText = localFilters.maxPrice.map { String($0) } ?? ""
        minSqftText = localFilters.minSqft.map { String($0) } ?? ""
        maxSqftText = localFilters.maxSqft.map { String($0) } ?? ""
        minYearText = localFilters.minYearBuilt.map { String($0) } ?? ""
        maxYearText = localFilters.maxYearBuilt.map { String($0) } ?? ""
        minLotText = localFilters.minLotSize.map { String(Int($0)) } ?? ""
        maxLotText = localFilters.maxLotSize.map { String(Int($0)) } ?? ""

        // Initialize slider positions (v216)
        sqftSliderMin = Double(localFilters.minSqft ?? 0)
        sqftSliderMax = min(Double(localFilters.maxSqft ?? 10000), 10000)
        yearSliderMin = Double(localFilters.minYearBuilt ?? 1900)
        yearSliderMax = Double(localFilters.maxYearBuilt ?? 2026)
    }

    private func syncFilterValues() {
        loadFilterValues()
    }

    private func resetFilters() {
        localFilters = PropertySearchFilters()
        loadFilterValues()
    }

    private func applyFilters() {

        // Update the binding for UI state consistency
        filters = localFilters

        // CRITICAL: Call onApply BEFORE dismiss to ensure callback executes
        // before the sheet is removed from view hierarchy
        onApply(localFilters)

        // Now dismiss the modal
        dismiss()
    }
}

// MARK: - Filter Chip View

struct FilterChipView: View {
    let chip: FilterChip
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 6) {
            Text(chip.label)
                .font(.caption)
                .fontWeight(.medium)

            Button(action: onRemove) {
                Image(systemName: "xmark.circle.fill")
                    .font(.system(size: 14))
            }
        }
        .foregroundStyle(Color(hex: chip.category.color))
        .padding(.horizontal, 12)
        .padding(.vertical, 6)
        .background(Color(hex: chip.category.color).opacity(0.15))
        .clipShape(Capsule())
    }
}

// MARK: - Amenity Filter Toggle

struct AmenityFilterToggle: View {
    let label: String
    let icon: String
    @Binding var isOn: Bool

    var body: some View {
        Button {
            isOn.toggle()
        } label: {
            HStack(spacing: 8) {
                Image(systemName: icon)
                    .font(.system(size: 14))
                Text(label)
                    .font(.subheadline)
                    .lineLimit(1)
                Spacer()
                if isOn {
                    Image(systemName: "checkmark")
                        .font(.caption.bold())
                }
            }
            .foregroundStyle(isOn ? .white : AppColors.textSecondary)
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background(isOn ? AppColors.brandTeal : Color(.secondarySystemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
    }
}

// Note: FlowLayout is defined in PropertyDetailView.swift

// MARK: - Preview

#Preview {
    AdvancedFilterModal(
        filters: .constant(PropertySearchFilters()),
        onApply: { _ in },
        onFetchCount: { _ in return 473 }
    )
}
