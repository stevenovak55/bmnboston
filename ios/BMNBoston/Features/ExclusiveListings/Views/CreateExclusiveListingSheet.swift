//
//  CreateExclusiveListingSheet.swift
//  BMNBoston
//
//  Unified form sheet for creating and editing exclusive listings
//  Consolidates create and edit flows to eliminate code duplication
//
//  Created for BMN Boston Real Estate
//

import SwiftUI
import Combine

// MARK: - Form Mode

enum ExclusiveListingFormMode: Equatable {
    case create
    case edit(ExclusiveListing)

    var isEditing: Bool {
        if case .edit = self { return true }
        return false
    }

    var navigationTitle: String {
        isEditing ? "Edit Listing" : "New Listing"
    }

    var submitButtonTitle: String {
        isEditing ? "Save" : "Create"
    }

    var savingMessage: String {
        isEditing ? "Saving changes..." : "Creating listing..."
    }
}

// MARK: - Unified Form Sheet

struct ExclusiveListingFormSheet: View {
    @ObservedObject var viewModel: ExclusiveListingsViewModel
    let mode: ExclusiveListingFormMode
    let onDismiss: () -> Void

    @State private var showingAlert = false
    @State private var alertMessage = ""
    @State private var priceText: String = ""
    // v1.5.0: Text fields for decimal inputs (format: .number doesn't support decimals)
    @State private var bathroomsTotalText: String = ""
    @State private var lotSizeAcresText: String = ""
    @State private var lotSizeSqFtText: String = ""
    @State private var validationErrors: [String: String] = [:]
    @State private var hasAttemptedSubmit = false
    @State private var showDraftPrompt = false
    @State private var hasCheckedForDraft = false

    @FocusState private var focusedField: FormField?

    private enum FormField {
        case streetNumber, streetName, unitNumber, city, postalCode
        case listPrice, bedrooms, bathrooms, sqft, yearBuilt
        case lotSize, garageSpaces, publicRemarks
    }

    var body: some View {
        NavigationStack {
            Form {
                addressSection
                priceSection
                propertyTypeSection
                detailsSection
                propertyDescriptionSection  // Tier 1
                interiorDetailsSection      // Tier 2
                exteriorSection             // Tier 3
                financialSection            // Tier 4
                featuresSection
            }
            .navigationTitle(mode.navigationTitle)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button {
                        onDismiss()
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title2)
                            .symbolRenderingMode(.hierarchical)
                            .foregroundStyle(.secondary)
                    }
                }

                ToolbarItem(placement: .confirmationAction) {
                    Button(mode.submitButtonTitle) {
                        submitForm()
                    }
                    .disabled(!viewModel.editingRequest.isValid || viewModel.isSaving)
                }
            }
            .overlay {
                if viewModel.isSaving {
                    savingOverlay
                }
            }
            .alert("Error", isPresented: $showingAlert) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(alertMessage)
            }
            .task {
                await viewModel.loadOptions()
            }
            .onAppear {
                initializePriceText()
                checkForDraft()
            }
            .onReceive(viewModel.$editingRequest.debounce(for: .seconds(2), scheduler: RunLoop.main)) { _ in
                scheduleDraftSave()
            }
            .alert("Resume Draft?", isPresented: $showDraftPrompt) {
                Button("Resume Draft") {
                    _ = viewModel.loadDraft()
                    initializePriceText()
                }
                Button("Start Fresh", role: .destructive) {
                    viewModel.clearDraft()
                }
            } message: {
                Text(draftAlertMessage)
            }
        }
        .presentationDragIndicator(.visible)
    }

    // MARK: - Draft Helpers

    private var draftAlertMessage: String {
        if let draftDate = viewModel.draftSavedDate {
            let formatter = RelativeDateTimeFormatter()
            formatter.unitsStyle = .full
            let relativeDate = formatter.localizedString(for: draftDate, relativeTo: Date())
            return "You have an unsaved draft from \(relativeDate). Would you like to continue where you left off?"
        } else {
            return "You have an unsaved draft. Would you like to continue where you left off?"
        }
    }

    private func checkForDraft() {
        guard !hasCheckedForDraft else { return }
        hasCheckedForDraft = true

        // Only check for draft in create mode
        if case .create = mode {
            if viewModel.hasDraft {
                showDraftPrompt = true
            }
        }
    }

    private func scheduleDraftSave() {
        // Only save drafts in create mode
        if case .create = mode {
            viewModel.scheduleDraftSave()
        }
    }

    // MARK: - Address Section

    private var addressSection: some View {
        Section("Address") {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    TextField("Number", text: $viewModel.editingRequest.streetNumber)
                        .keyboardType(.numberPad)
                        .frame(width: 80)
                        .focused($focusedField, equals: .streetNumber)
                        .onChange(of: viewModel.editingRequest.streetNumber) { _ in
                            if hasAttemptedSubmit { validateStreetNumber() }
                        }

                    TextField("Street Name", text: $viewModel.editingRequest.streetName)
                        .focused($focusedField, equals: .streetName)
                        .onChange(of: viewModel.editingRequest.streetName) { _ in
                            if hasAttemptedSubmit { validateStreetName() }
                        }
                }
                if let error = validationErrors["streetNumber"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
                if let error = validationErrors["streetName"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }

            TextField("Unit # (optional)", text: Binding(
                get: { viewModel.editingRequest.unitNumber ?? "" },
                set: { viewModel.editingRequest.unitNumber = $0.isEmpty ? nil : $0 }
            ))
            .focused($focusedField, equals: .unitNumber)

            VStack(alignment: .leading, spacing: 4) {
                TextField("City", text: $viewModel.editingRequest.city)
                    .focused($focusedField, equals: .city)
                    .onChange(of: viewModel.editingRequest.city) { _ in
                        if hasAttemptedSubmit { validateCity() }
                    }
                if let error = validationErrors["city"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }

            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Picker("State", selection: $viewModel.editingRequest.stateOrProvince) {
                        Text("MA").tag("MA")
                        Text("NH").tag("NH")
                        Text("RI").tag("RI")
                        Text("CT").tag("CT")
                        Text("ME").tag("ME")
                        Text("VT").tag("VT")
                    }
                    .pickerStyle(.menu)

                    TextField("ZIP Code", text: $viewModel.editingRequest.postalCode)
                        .keyboardType(.numberPad)
                        .focused($focusedField, equals: .postalCode)
                        .onChange(of: viewModel.editingRequest.postalCode) { _ in
                            if hasAttemptedSubmit { validatePostalCode() }
                        }
                }
                if let error = validationErrors["postalCode"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }
        }
    }

    // MARK: - Price Section

    private var priceSection: some View {
        Section("Price & Status") {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("$")
                        .foregroundStyle(.secondary)
                    TextField("List Price", text: $priceText)
                        .keyboardType(.numberPad)
                        .focused($focusedField, equals: .listPrice)
                        .onChange(of: priceText) { newValue in
                            let cleanedValue = newValue.filter { $0.isNumber }
                            viewModel.editingRequest.listPrice = Double(cleanedValue) ?? 0
                            if hasAttemptedSubmit { validatePrice() }
                        }
                }
                if let error = validationErrors["price"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }

            Picker("Status", selection: $viewModel.editingRequest.standardStatus) {
                Text("Active").tag("Active")
                Text("Pending").tag("Pending")
                Text("Active Under Contract").tag("Active Under Contract")
                Text("Closed").tag("Closed")
            }

            // Contract Date (for Pending/Closed listings)
            if viewModel.editingRequest.standardStatus != "Active" {
                DatePicker(
                    "Contract Date",
                    selection: Binding(
                        get: { viewModel.editingRequest.listingContractDate ?? Date() },
                        set: { viewModel.editingRequest.listingContractDate = $0 }
                    ),
                    displayedComponents: .date
                )
            }
        }
    }

    // MARK: - Property Type Section

    // v6.65.0: Predefined exclusive tag options
    private let exclusiveTagOptions = ["Exclusive", "Coming Soon", "Off-Market", "Pocket Listing", "Pre-Market", "Private", "Custom..."]

    @State private var isCustomTag: Bool = false
    @State private var customTagText: String = ""

    private var propertyTypeSection: some View {
        Section("Property Type") {
            Picker("Type", selection: $viewModel.editingRequest.propertyType) {
                if let options = viewModel.options {
                    ForEach(options.propertyTypes, id: \.self) { type in
                        Text(type).tag(type)
                    }
                } else {
                    Text("Residential").tag("Residential")
                    Text("Residential Income").tag("Residential Income")
                    Text("Commercial Sale").tag("Commercial Sale")
                    Text("Land").tag("Land")
                }
            }

            if let options = viewModel.options,
               let subTypes = options.propertySubTypes[viewModel.editingRequest.propertyType],
               !subTypes.isEmpty {
                Picker("Sub-Type", selection: Binding(
                    get: { viewModel.editingRequest.propertySubType ?? "" },
                    set: { viewModel.editingRequest.propertySubType = $0.isEmpty ? nil : $0 }
                )) {
                    Text("None").tag("")
                    ForEach(subTypes, id: \.self) { subType in
                        Text(subType).tag(subType)
                    }
                }
            }

            // v6.65.0: Exclusive tag picker
            VStack(alignment: .leading, spacing: 8) {
                Text("Badge Text")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                Picker("Badge Text", selection: Binding(
                    get: {
                        if isCustomTag || !exclusiveTagOptions.dropLast().contains(viewModel.editingRequest.exclusiveTag) {
                            return "Custom..."
                        }
                        return viewModel.editingRequest.exclusiveTag
                    },
                    set: { newValue in
                        if newValue == "Custom..." {
                            isCustomTag = true
                            customTagText = viewModel.editingRequest.exclusiveTag
                        } else {
                            isCustomTag = false
                            viewModel.editingRequest.exclusiveTag = newValue
                        }
                    }
                )) {
                    ForEach(exclusiveTagOptions, id: \.self) { tag in
                        Text(tag).tag(tag)
                    }
                }
                .pickerStyle(.menu)

                if isCustomTag {
                    TextField("Custom badge text", text: $customTagText)
                        .textFieldStyle(.roundedBorder)
                        .onChange(of: customTagText) { newValue in
                            viewModel.editingRequest.exclusiveTag = newValue.isEmpty ? "Exclusive" : newValue
                        }
                }

                // Preview of badge
                HStack(spacing: 4) {
                    Image(systemName: "star.fill")
                        .font(.caption2)
                    Text(viewModel.editingRequest.exclusiveTag.isEmpty ? "Exclusive" : viewModel.editingRequest.exclusiveTag)
                        .font(.caption2)
                        .fontWeight(.semibold)
                }
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color(red: 0.85, green: 0.65, blue: 0.13))
                .foregroundStyle(.white)
                .clipShape(Capsule())
            }
            .padding(.vertical, 4)
        }
    }

    // MARK: - Details Section

    // v1.5.0: Constant for lot size conversion
    private let sqFtPerAcre: Double = 43560

    private var detailsSection: some View {
        Section("Property Details") {
            HStack {
                Image(systemName: "bed.double.fill")
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                TextField("Bedrooms", value: Binding(
                    get: { viewModel.editingRequest.bedroomsTotal },
                    set: { viewModel.editingRequest.bedroomsTotal = $0 }
                ), format: .number)
                .keyboardType(.numberPad)
                .focused($focusedField, equals: .bedrooms)
            }

            // v1.5.0: Total Bathrooms - uses text field for decimal support
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Image(systemName: "shower.fill")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    TextField("Total Bathrooms", text: $bathroomsTotalText)
                        .keyboardType(.decimalPad)
                        .focused($focusedField, equals: .bathrooms)
                        .onChange(of: bathroomsTotalText) { newValue in
                            // Parse and store decimal value
                            if let baths = Double(newValue) {
                                viewModel.editingRequest.bathroomsTotal = baths
                                // Auto-calculate full/half from total
                                let half = (baths - floor(baths)) * 2
                                viewModel.editingRequest.bathroomsFull = Int(floor(baths))
                                viewModel.editingRequest.bathroomsHalf = Int(half)
                            } else if newValue.isEmpty {
                                viewModel.editingRequest.bathroomsTotal = nil
                                viewModel.editingRequest.bathroomsFull = nil
                                viewModel.editingRequest.bathroomsHalf = nil
                            }
                        }
                }
                Text("Enter as decimal (e.g., 2.5 = 2 full + 1 half)")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            // Full/Half breakdown (auto-calculated, but also editable)
            HStack {
                Text("Full")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                TextField("Full", value: Binding(
                    get: { viewModel.editingRequest.bathroomsFull },
                    set: { newValue in
                        viewModel.editingRequest.bathroomsFull = newValue
                        updateBathroomsTotalFromFullHalf()
                    }
                ), format: .number)
                .keyboardType(.numberPad)
                .frame(width: 60)

                Text("Half")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                TextField("Half", value: Binding(
                    get: { viewModel.editingRequest.bathroomsHalf },
                    set: { newValue in
                        viewModel.editingRequest.bathroomsHalf = newValue
                        updateBathroomsTotalFromFullHalf()
                    }
                ), format: .number)
                .keyboardType(.numberPad)
                .frame(width: 60)
            }

            HStack {
                Image(systemName: "square.fill")
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                TextField("Square Feet", value: Binding(
                    get: { viewModel.editingRequest.buildingAreaTotal },
                    set: { viewModel.editingRequest.buildingAreaTotal = $0 }
                ), format: .number)
                .keyboardType(.numberPad)
                .focused($focusedField, equals: .sqft)
                Text("sq ft")
                    .foregroundStyle(.secondary)
            }

            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Image(systemName: "calendar")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    TextField("Year Built", value: Binding(
                        get: { viewModel.editingRequest.yearBuilt },
                        set: { viewModel.editingRequest.yearBuilt = $0 }
                    ), format: .number)
                    .keyboardType(.numberPad)
                    .focused($focusedField, equals: .yearBuilt)
                    .onChange(of: viewModel.editingRequest.yearBuilt) { _ in
                        if hasAttemptedSubmit { validateYearBuilt() }
                    }
                }
                if let error = validationErrors["yearBuilt"] {
                    Text(error)
                        .font(.caption)
                        .foregroundStyle(.red)
                }
            }

            // v1.5.0: Lot Size - dual input with auto-conversion
            VStack(alignment: .leading, spacing: 8) {
                Text("Lot Size")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                HStack {
                    Image(systemName: "leaf.fill")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    TextField("Square Feet", text: $lotSizeSqFtText)
                        .keyboardType(.numberPad)
                        .onChange(of: lotSizeSqFtText) { newValue in
                            if let sqft = Int(newValue), sqft > 0 {
                                viewModel.editingRequest.lotSizeSquareFeet = sqft
                                let acres = Double(sqft) / sqFtPerAcre
                                viewModel.editingRequest.lotSizeAcres = acres
                                // Update acres text field
                                lotSizeAcresText = String(format: "%.4f", acres)
                            } else if newValue.isEmpty {
                                viewModel.editingRequest.lotSizeSquareFeet = nil
                                viewModel.editingRequest.lotSizeAcres = nil
                                lotSizeAcresText = ""
                            }
                        }
                    Text("Sq Ft")
                        .foregroundStyle(.secondary)
                        .frame(width: 50)
                }

                HStack {
                    Spacer()
                        .frame(width: 24)
                    Text("OR")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                HStack {
                    Spacer()
                        .frame(width: 24)
                    TextField("Acres", text: $lotSizeAcresText)
                        .keyboardType(.decimalPad)
                        .focused($focusedField, equals: .lotSize)
                        .onChange(of: lotSizeAcresText) { newValue in
                            if let acres = Double(newValue), acres > 0 {
                                viewModel.editingRequest.lotSizeAcres = acres
                                let sqft = Int(acres * sqFtPerAcre)
                                viewModel.editingRequest.lotSizeSquareFeet = sqft
                                // Update sq ft text field
                                lotSizeSqFtText = String(sqft)
                            } else if newValue.isEmpty {
                                viewModel.editingRequest.lotSizeAcres = nil
                                viewModel.editingRequest.lotSizeSquareFeet = nil
                                lotSizeSqFtText = ""
                            }
                        }
                    Text("Acres")
                        .foregroundStyle(.secondary)
                        .frame(width: 50)
                }

                Text("Enter either value - auto-converts (1 acre = 43,560 sq ft)")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            // v1.5.0: Parking - Garage and Other Parking together with clear labels
            VStack(alignment: .leading, spacing: 8) {
                Text("Parking")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                HStack {
                    Image(systemName: "car.fill")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    TextField("Garage Spaces", value: Binding(
                        get: { viewModel.editingRequest.garageSpaces },
                        set: { viewModel.editingRequest.garageSpaces = $0 }
                    ), format: .number)
                    .keyboardType(.numberPad)
                    .focused($focusedField, equals: .garageSpaces)
                    Text("Garage")
                        .foregroundStyle(.secondary)
                }

                HStack {
                    Image(systemName: "car.2.fill")
                        .foregroundStyle(.secondary)
                        .frame(width: 24)
                    TextField("Other Parking", value: Binding(
                        get: { viewModel.editingRequest.parkingTotal },
                        set: { viewModel.editingRequest.parkingTotal = $0 }
                    ), format: .number)
                    .keyboardType(.numberPad)
                    Text("Other")
                        .foregroundStyle(.secondary)
                }

                Text("Garage = covered parking; Other = driveway, street, etc.")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // v1.5.0: Helper to update bathroomsTotal when full/half changes
    private func updateBathroomsTotalFromFullHalf() {
        let full = viewModel.editingRequest.bathroomsFull ?? 0
        let half = viewModel.editingRequest.bathroomsHalf ?? 0
        let total = Double(full) + Double(half) * 0.5
        viewModel.editingRequest.bathroomsTotal = total
        if total.truncatingRemainder(dividingBy: 1) == 0 {
            bathroomsTotalText = String(format: "%.0f", total)
        } else {
            bathroomsTotalText = String(format: "%.1f", total)
        }
    }

    // MARK: - Property Description Section (Tier 1)

    private var propertyDescriptionSection: some View {
        Section("Property Description") {
            // Original List Price
            HStack {
                Text("Original Price")
                    .foregroundStyle(.secondary)
                Spacer()
                HStack {
                    Text("$")
                        .foregroundStyle(.secondary)
                    TextField("", value: Binding(
                        get: { viewModel.editingRequest.originalListPrice },
                        set: { viewModel.editingRequest.originalListPrice = $0 }
                    ), format: .number)
                    .keyboardType(.numberPad)
                    .multilineTextAlignment(.trailing)
                    .frame(width: 120)
                }
            }

            // Architectural Style
            if let options = viewModel.options, let styles = options.architecturalStyles, !styles.isEmpty {
                Picker("Style", selection: Binding(
                    get: { viewModel.editingRequest.architecturalStyle ?? "" },
                    set: { viewModel.editingRequest.architecturalStyle = $0.isEmpty ? nil : $0 }
                )) {
                    Text("None").tag("")
                    ForEach(styles, id: \.self) { style in
                        Text(style).tag(style)
                    }
                }
            }

            // Stories
            HStack {
                Image(systemName: "building.fill")
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                TextField("Stories", value: Binding(
                    get: { viewModel.editingRequest.storiesTotal },
                    set: { viewModel.editingRequest.storiesTotal = $0 }
                ), format: .number)
                .keyboardType(.numberPad)
            }

            // Virtual Tour URL
            HStack {
                Image(systemName: "video.fill")
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                TextField("Virtual Tour URL", text: Binding(
                    get: { viewModel.editingRequest.virtualTourUrl ?? "" },
                    set: { viewModel.editingRequest.virtualTourUrl = $0.isEmpty ? nil : $0 }
                ))
                .keyboardType(.URL)
                .autocapitalization(.none)
            }

            // Public Remarks
            VStack(alignment: .leading, spacing: 4) {
                Text("Public Description")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                TextEditor(text: Binding(
                    get: { viewModel.editingRequest.publicRemarks ?? "" },
                    set: { viewModel.editingRequest.publicRemarks = $0.isEmpty ? nil : $0 }
                ))
                .frame(minHeight: 80)
                .focused($focusedField, equals: .publicRemarks)
            }

            // Private Remarks (Agent Only)
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("Private Notes")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Image(systemName: "lock.fill")
                        .font(.caption)
                        .foregroundStyle(.orange)
                    Text("Agent Only")
                        .font(.caption2)
                        .foregroundStyle(.orange)
                }
                TextEditor(text: Binding(
                    get: { viewModel.editingRequest.privateRemarks ?? "" },
                    set: { viewModel.editingRequest.privateRemarks = $0.isEmpty ? nil : $0 }
                ))
                .frame(minHeight: 60)
            }

            // Showing Instructions
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("Showing Instructions")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Image(systemName: "key.fill")
                        .font(.caption)
                        .foregroundStyle(.blue)
                }
                TextEditor(text: Binding(
                    get: { viewModel.editingRequest.showingInstructions ?? "" },
                    set: { viewModel.editingRequest.showingInstructions = $0.isEmpty ? nil : $0 }
                ))
                .frame(minHeight: 60)
            }
        }
    }

    // MARK: - Interior Details Section (Tier 2)

    private var interiorDetailsSection: some View {
        Section("Interior Details") {
            // Heating
            if let options = viewModel.options, let types = options.heatingTypes, !types.isEmpty {
                multiSelectRow(title: "Heating", options: types, selection: $viewModel.editingRequest.heating, icon: "thermometer.high")
            }

            // Cooling
            if let options = viewModel.options, let types = options.coolingTypes, !types.isEmpty {
                multiSelectRow(title: "Cooling", options: types, selection: $viewModel.editingRequest.cooling, icon: "snowflake")
            }

            // Interior Features
            if let options = viewModel.options, let features = options.interiorFeatures, !features.isEmpty {
                multiSelectRow(title: "Interior Features", options: features, selection: $viewModel.editingRequest.interiorFeatures, icon: "house.fill")
            }

            // Appliances
            if let options = viewModel.options, let applianceList = options.appliances, !applianceList.isEmpty {
                multiSelectRow(title: "Appliances", options: applianceList, selection: $viewModel.editingRequest.appliances, icon: "refrigerator.fill")
            }

            // Flooring
            if let options = viewModel.options, let types = options.flooringTypes, !types.isEmpty {
                multiSelectRow(title: "Flooring", options: types, selection: $viewModel.editingRequest.flooring, icon: "square.grid.3x3.fill")
            }

            // Laundry Features
            if let options = viewModel.options, let features = options.laundryFeatures, !features.isEmpty {
                multiSelectRow(title: "Laundry", options: features, selection: $viewModel.editingRequest.laundryFeatures, icon: "washer.fill")
            }

            // Basement
            if let options = viewModel.options, let types = options.basementTypes, !types.isEmpty {
                Picker("Basement", selection: Binding(
                    get: { viewModel.editingRequest.basement ?? "" },
                    set: { viewModel.editingRequest.basement = $0.isEmpty ? nil : $0 }
                )) {
                    Text("None").tag("")
                    ForEach(types, id: \.self) { type in
                        Text(type).tag(type)
                    }
                }
            }
        }
    }

    // MARK: - Exterior Section (Tier 3)

    private var exteriorSection: some View {
        Section("Exterior & Lot") {
            // Construction Materials
            if let options = viewModel.options, let materials = options.constructionMaterials, !materials.isEmpty {
                multiSelectRow(title: "Construction", options: materials, selection: $viewModel.editingRequest.constructionMaterials, icon: "hammer.fill")
            }

            // Roof
            if let options = viewModel.options, let types = options.roofTypes, !types.isEmpty {
                Picker("Roof", selection: Binding(
                    get: { viewModel.editingRequest.roof ?? "" },
                    set: { viewModel.editingRequest.roof = $0.isEmpty ? nil : $0 }
                )) {
                    Text("None").tag("")
                    ForEach(types, id: \.self) { type in
                        Text(type).tag(type)
                    }
                }
            }

            // Foundation
            if let options = viewModel.options, let types = options.foundationTypes, !types.isEmpty {
                Picker("Foundation", selection: Binding(
                    get: { viewModel.editingRequest.foundationDetails ?? "" },
                    set: { viewModel.editingRequest.foundationDetails = $0.isEmpty ? nil : $0 }
                )) {
                    Text("None").tag("")
                    ForEach(types, id: \.self) { type in
                        Text(type).tag(type)
                    }
                }
            }

            // Exterior Features
            if let options = viewModel.options, let features = options.exteriorFeatures, !features.isEmpty {
                multiSelectRow(title: "Exterior Features", options: features, selection: $viewModel.editingRequest.exteriorFeatures, icon: "tree.fill")
            }

            // Waterfront
            Toggle(isOn: $viewModel.editingRequest.waterfrontYn) {
                Label("Waterfront", systemImage: "water.waves")
            }

            if viewModel.editingRequest.waterfrontYn {
                if let options = viewModel.options, let features = options.waterfrontFeatures, !features.isEmpty {
                    multiSelectRow(title: "Waterfront Features", options: features, selection: $viewModel.editingRequest.waterfrontFeatures, icon: "water.waves")
                }
            }

            // View
            Toggle(isOn: $viewModel.editingRequest.viewYn) {
                Label("Notable View", systemImage: "eye.fill")
            }

            if viewModel.editingRequest.viewYn {
                if let options = viewModel.options, let types = options.viewTypes, !types.isEmpty {
                    multiSelectRow(title: "View Types", options: types, selection: $viewModel.editingRequest.view, icon: "eye.fill")
                }
            }

            // Parking Features (multi-select for covered, driveway, street, etc.)
            if let options = viewModel.options, let features = options.parkingFeatures, !features.isEmpty {
                multiSelectRow(title: "Parking Features", options: features, selection: $viewModel.editingRequest.parkingFeatures, icon: "car.fill")
            }
            // Note: Parking spaces count is in Property Details section
        }
    }

    // MARK: - Financial Section (Tier 4)

    private var financialSection: some View {
        Section("Financial") {
            // Tax Annual Amount
            HStack {
                Text("Annual Tax")
                    .foregroundStyle(.secondary)
                Spacer()
                HStack {
                    Text("$")
                        .foregroundStyle(.secondary)
                    TextField("", value: Binding(
                        get: { viewModel.editingRequest.taxAnnualAmount },
                        set: { viewModel.editingRequest.taxAnnualAmount = $0 }
                    ), format: .number)
                    .keyboardType(.numberPad)
                    .multilineTextAlignment(.trailing)
                    .frame(width: 100)
                }
            }

            // Tax Year
            HStack {
                Image(systemName: "calendar")
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                TextField("Tax Year", value: Binding(
                    get: { viewModel.editingRequest.taxYear },
                    set: { viewModel.editingRequest.taxYear = $0 }
                ), format: .number)
                .keyboardType(.numberPad)
            }

            // HOA Toggle
            Toggle(isOn: $viewModel.editingRequest.associationYn) {
                Label("Has HOA", systemImage: "building.2.fill")
            }

            // HOA Details (shown only if HOA is enabled)
            if viewModel.editingRequest.associationYn {
                // Association Fee
                HStack {
                    Text("HOA Fee")
                        .foregroundStyle(.secondary)
                    Spacer()
                    HStack {
                        Text("$")
                            .foregroundStyle(.secondary)
                        TextField("", value: Binding(
                            get: { viewModel.editingRequest.associationFee },
                            set: { viewModel.editingRequest.associationFee = $0 }
                        ), format: .number)
                        .keyboardType(.decimalPad)
                        .multilineTextAlignment(.trailing)
                        .frame(width: 100)
                    }
                }

                // Fee Frequency
                if let options = viewModel.options, let frequencies = options.associationFeeFrequencies, !frequencies.isEmpty {
                    Picker("Fee Frequency", selection: Binding(
                        get: { viewModel.editingRequest.associationFeeFrequency ?? "" },
                        set: { viewModel.editingRequest.associationFeeFrequency = $0.isEmpty ? nil : $0 }
                    )) {
                        Text("Select").tag("")
                        ForEach(frequencies, id: \.self) { freq in
                            Text(freq).tag(freq)
                        }
                    }
                }

                // Fee Includes
                if let options = viewModel.options, let includes = options.associationFeeIncludes, !includes.isEmpty {
                    multiSelectRow(title: "Fee Includes", options: includes, selection: $viewModel.editingRequest.associationFeeIncludes, icon: "list.bullet")
                }
            }
        }
    }

    // MARK: - Multi-Select Helper

    @ViewBuilder
    private func multiSelectRow(title: String, options: [String], selection: Binding<[String]>, icon: String) -> some View {
        NavigationLink {
            MultiSelectView(title: title, options: options, selection: selection)
        } label: {
            HStack {
                Image(systemName: icon)
                    .foregroundStyle(.secondary)
                    .frame(width: 24)
                Text(title)
                Spacer()
                if selection.wrappedValue.isEmpty {
                    Text("None")
                        .foregroundStyle(.secondary)
                } else {
                    Text("\(selection.wrappedValue.count) selected")
                        .foregroundStyle(.secondary)
                }
            }
        }
    }

    // MARK: - Features Section

    private var featuresSection: some View {
        Section("Quick Features") {
            Toggle(isOn: $viewModel.editingRequest.hasPool) {
                Label("Pool", systemImage: "figure.pool.swim")
            }

            Toggle(isOn: $viewModel.editingRequest.hasFireplace) {
                Label("Fireplace", systemImage: "flame.fill")
            }

            Toggle(isOn: $viewModel.editingRequest.hasBasement) {
                Label("Basement", systemImage: "arrow.down.square.fill")
            }

            Toggle(isOn: $viewModel.editingRequest.hasHoa) {
                Label("HOA", systemImage: "building.2.fill")
            }
        }
    }

    // MARK: - Saving Overlay

    private var savingOverlay: some View {
        ZStack {
            Color.black.opacity(0.3)
                .ignoresSafeArea()

            VStack(spacing: 16) {
                ProgressView()
                    .scaleEffect(1.2)
                Text(mode.savingMessage)
                    .font(.headline)
            }
            .padding(24)
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .shadow(radius: 10)
        }
    }

    // MARK: - Actions

    private func initializePriceText() {
        if viewModel.editingRequest.listPrice > 0 {
            priceText = String(format: "%.0f", viewModel.editingRequest.listPrice)
        }
        // v1.5.0: Initialize decimal text fields
        if let baths = viewModel.editingRequest.bathroomsTotal {
            if baths.truncatingRemainder(dividingBy: 1) == 0 {
                bathroomsTotalText = String(format: "%.0f", baths)
            } else {
                bathroomsTotalText = String(format: "%.1f", baths)
            }
        }
        if let acres = viewModel.editingRequest.lotSizeAcres {
            lotSizeAcresText = String(format: "%.4f", acres).trimmingCharacters(in: CharacterSet(charactersIn: "0")).trimmingCharacters(in: CharacterSet(charactersIn: "."))
            // Re-add leading zero if needed
            if lotSizeAcresText.hasPrefix(".") { lotSizeAcresText = "0" + lotSizeAcresText }
            // Clean up - keep only necessary decimals
            if let acresDouble = Double(lotSizeAcresText) {
                if acresDouble.truncatingRemainder(dividingBy: 1) == 0 {
                    lotSizeAcresText = String(format: "%.0f", acresDouble)
                } else {
                    lotSizeAcresText = String(acresDouble)
                }
            }
        }
        if let sqft = viewModel.editingRequest.lotSizeSquareFeet {
            lotSizeSqFtText = String(sqft)
        }
    }

    // MARK: - Validation

    private func validateAllFields() {
        validateStreetNumber()
        validateStreetName()
        validateCity()
        validatePostalCode()
        validatePrice()
        validateYearBuilt()
    }

    private func validateStreetNumber() {
        if viewModel.editingRequest.streetNumber.trimmingCharacters(in: .whitespaces).isEmpty {
            validationErrors["streetNumber"] = "Street number is required"
        } else {
            validationErrors.removeValue(forKey: "streetNumber")
        }
    }

    private func validateStreetName() {
        if viewModel.editingRequest.streetName.trimmingCharacters(in: .whitespaces).isEmpty {
            validationErrors["streetName"] = "Street name is required"
        } else {
            validationErrors.removeValue(forKey: "streetName")
        }
    }

    private func validateCity() {
        if viewModel.editingRequest.city.trimmingCharacters(in: .whitespaces).isEmpty {
            validationErrors["city"] = "City is required"
        } else {
            validationErrors.removeValue(forKey: "city")
        }
    }

    private func validatePostalCode() {
        let zip = viewModel.editingRequest.postalCode.trimmingCharacters(in: .whitespaces)
        if zip.isEmpty {
            validationErrors["postalCode"] = "ZIP code is required"
        } else if !zip.allSatisfy({ $0.isNumber }) || zip.count != 5 {
            validationErrors["postalCode"] = "ZIP must be 5 digits"
        } else {
            validationErrors.removeValue(forKey: "postalCode")
        }
    }

    private func validatePrice() {
        if viewModel.editingRequest.listPrice <= 0 {
            validationErrors["price"] = "Price must be greater than 0"
        } else {
            validationErrors.removeValue(forKey: "price")
        }
    }

    private func validateYearBuilt() {
        if let year = viewModel.editingRequest.yearBuilt {
            let currentYear = Calendar.current.component(.year, from: Date())
            if year < 1800 || year > currentYear {
                validationErrors["yearBuilt"] = "Year must be 1800-\(currentYear)"
            } else {
                validationErrors.removeValue(forKey: "yearBuilt")
            }
        } else {
            validationErrors.removeValue(forKey: "yearBuilt")
        }
    }

    private func submitForm() {
        hasAttemptedSubmit = true
        validateAllFields()

        // Check for validation errors
        if !validationErrors.isEmpty {
            // Focus on first field with error
            if validationErrors["streetNumber"] != nil {
                focusedField = .streetNumber
            } else if validationErrors["streetName"] != nil {
                focusedField = .streetName
            } else if validationErrors["city"] != nil {
                focusedField = .city
            } else if validationErrors["postalCode"] != nil {
                focusedField = .postalCode
            } else if validationErrors["price"] != nil {
                focusedField = .listPrice
            } else if validationErrors["yearBuilt"] != nil {
                focusedField = .yearBuilt
            }
            return
        }

        focusedField = nil

        Task {
            let success: Bool
            switch mode {
            case .create:
                success = await viewModel.createListing()
            case .edit(let listing):
                success = await viewModel.updateListing(id: listing.id)
            }

            if success {
                onDismiss()
            } else if let error = viewModel.errorMessage {
                alertMessage = error
                showingAlert = true
            }
        }
    }
}

// MARK: - Multi-Select View

struct MultiSelectView: View {
    let title: String
    let options: [String]
    @Binding var selection: [String]

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        List {
            Section {
                ForEach(options, id: \.self) { option in
                    Button {
                        toggleSelection(option)
                    } label: {
                        HStack {
                            Text(option)
                                .foregroundStyle(.primary)
                            Spacer()
                            if selection.contains(option) {
                                Image(systemName: "checkmark")
                                    .foregroundStyle(AppColors.brandTeal)
                            }
                        }
                    }
                }
            } footer: {
                if !selection.isEmpty {
                    Text("Selected: \(selection.joined(separator: ", "))")
                        .font(.caption)
                }
            }
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .confirmationAction) {
                Button("Done") {
                    dismiss()
                }
            }

            ToolbarItem(placement: .topBarLeading) {
                if !selection.isEmpty {
                    Button("Clear All") {
                        selection.removeAll()
                    }
                    .foregroundStyle(.red)
                }
            }
        }
    }

    private func toggleSelection(_ option: String) {
        if let index = selection.firstIndex(of: option) {
            selection.remove(at: index)
        } else {
            selection.append(option)
        }
    }
}

// MARK: - Convenience Wrappers (Backward Compatibility)

/// Wrapper for create mode using Bool binding (backward compatible)
struct CreateExclusiveListingSheet: View {
    @ObservedObject var viewModel: ExclusiveListingsViewModel
    @Binding var isPresented: Bool

    var body: some View {
        ExclusiveListingFormSheet(
            viewModel: viewModel,
            mode: .create,
            onDismiss: { isPresented = false }
        )
    }
}

/// Wrapper for edit mode using optional ExclusiveListing binding (backward compatible)
struct EditExclusiveListingSheet: View {
    @ObservedObject var viewModel: ExclusiveListingsViewModel
    let listing: ExclusiveListing
    @Binding var isPresented: ExclusiveListing?

    var body: some View {
        ExclusiveListingFormSheet(
            viewModel: viewModel,
            mode: .edit(listing),
            onDismiss: { isPresented = nil }
        )
    }
}

// MARK: - Preview

#Preview("Create Mode") {
    CreateExclusiveListingSheet(
        viewModel: ExclusiveListingsViewModel(),
        isPresented: .constant(true)
    )
}

#Preview("Edit Mode") {
    let sampleListing = ExclusiveListing(
        id: 1,
        listingId: 1,
        listingKey: "test",
        mlsId: nil,
        isExclusive: true,
        exclusiveTag: "Exclusive",
        status: "Active",
        standardStatus: "Active",
        listPrice: 1250000,
        pricePerSqft: 450,
        propertyType: "Residential",
        propertySubType: "Single Family",
        streetNumber: "123",
        streetName: "Main Street",
        unitNumber: nil,
        city: "Boston",
        state: "MA",
        stateOrProvince: "MA",
        postalCode: "02101",
        county: "Suffolk",
        subdivisionName: nil,
        unparsedAddress: nil,
        latitude: 42.36,
        longitude: -71.06,
        bedroomsTotal: 4,
        bathroomsTotal: 2.5,
        bathroomsFull: 2,
        bathroomsHalf: 1,
        buildingAreaTotal: 2800,
        lotSizeAcres: 0.25,
        lotSizeSquareFeet: 10890,
        yearBuilt: 1920,
        garageSpaces: 2,
        hasPool: false,
        hasFireplace: true,
        hasBasement: true,
        hasHoa: false,
        // Tier 1 - Property Description
        originalListPrice: nil,
        architecturalStyle: "Colonial",
        storiesTotal: 2,
        virtualTourUrl: nil,
        publicRemarks: "Beautiful colonial home",
        privateRemarks: nil,
        showingInstructions: nil,
        // Tier 2 - Interior Details
        heating: "Forced Air",
        cooling: "Central AC",
        heatingYn: true,
        coolingYn: true,
        interiorFeatures: "Hardwood Floors",
        appliances: nil,
        flooring: nil,
        laundryFeatures: nil,
        basement: "Finished",
        // Tier 3 - Exterior & Lot
        constructionMaterials: nil,
        roof: nil,
        foundationDetails: nil,
        exteriorFeatures: nil,
        waterfrontYn: false,
        waterfrontFeatures: nil,
        viewYn: false,
        view: nil,
        parkingFeatures: nil,
        parkingTotal: nil,
        // Tier 4 - Financial
        taxAnnualAmount: 12500,
        taxYear: 2024,
        associationYn: false,
        associationFee: nil,
        associationFeeFrequency: nil,
        associationFeeIncludes: nil,
        // Media & Dates
        mainPhotoUrl: nil,
        photoCount: 0,
        listingContractDate: nil,
        daysOnMarket: 14,
        modificationTimestamp: nil,
        url: nil,
        photos: nil
    )

    ExclusiveListingFormSheet(
        viewModel: ExclusiveListingsViewModel(),
        mode: .edit(sampleListing),
        onDismiss: {}
    )
}
