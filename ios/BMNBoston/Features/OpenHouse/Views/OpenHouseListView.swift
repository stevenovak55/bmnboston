//
//  OpenHouseListView.swift
//  BMNBoston
//
//  Main view for listing agent's open houses
//  Created for BMN Boston Real Estate
//
//  VERSION: v6.71.0
//

import SwiftUI

// MARK: - PropertyOpenHouseData (v6.69.0)
// Data structure for pre-filling CreateOpenHouseView from a property detail

struct PropertyOpenHouseData {
    let listingId: String?
    let streetAddress: String
    let city: String
    let state: String
    let zip: String
    let propertyType: String?
    let beds: Int?
    let baths: Double?
    let listPrice: Int?
    let photoUrl: String?
    let latitude: Double?
    let longitude: Double?

    /// Initialize from a PropertyDetail model
    init(from property: PropertyDetail) {
        self.listingId = property.mlsNumber
        self.streetAddress = property.address
        self.city = property.city
        self.state = property.state
        self.zip = property.zip
        self.propertyType = property.propertySubtype ?? property.propertyType
        self.beds = property.beds
        self.baths = property.baths
        self.listPrice = property.price
        self.photoUrl = property.photoUrl
        self.latitude = property.latitude
        self.longitude = property.longitude
    }
}

struct OpenHouseListView: View {
    @Environment(\.dismiss) private var dismiss
    @StateObject private var viewModel = OpenHouseListViewModel()
    @StateObject private var offlineStore = OfflineOpenHouseStore.shared
    @ObservedObject private var notificationStore = NotificationStore.shared
    @State private var pendingNavigationOpenHouseId: Int?

    var body: some View {
        Group {
            if viewModel.isLoading && viewModel.openHouses.isEmpty {
                ProgressView("Loading open houses...")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if viewModel.openHouses.isEmpty {
                emptyStateView
            } else {
                openHousesList
            }
        }
        .navigationTitle("Open Houses")
        .navigationBarTitleDisplayMode(.large)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Close") {
                    dismiss()
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    viewModel.showCreateSheet = true
                } label: {
                    Image(systemName: "plus")
                }
            }
        }
        .refreshable {
            await viewModel.loadOpenHouses(forceRefresh: true)
        }
        .task {
            await viewModel.loadOpenHouses()
        }
        .sheet(isPresented: $viewModel.showCreateSheet) {
            NavigationStack {
                CreateOpenHouseView(onSave: { newOpenHouse in
                    viewModel.openHouses.insert(newOpenHouse, at: 0)
                })
            }
        }
        .sheet(item: $viewModel.selectedOpenHouse) { openHouse in
            NavigationStack {
                OpenHouseDetailView(openHouse: openHouse, onUpdate: { updated in
                    if let index = viewModel.openHouses.firstIndex(where: { $0.id == updated.id }) {
                        viewModel.openHouses[index] = updated
                    }
                })
            }
        }
        .alert("Error", isPresented: $viewModel.showError) {
            Button("OK", role: .cancel) { }
        } message: {
            Text(viewModel.errorMessage)
        }
        .alert("Delete Open House?", isPresented: $viewModel.showDeleteConfirmation) {
            Button("Cancel", role: .cancel) {
                viewModel.openHouseToDelete = nil
            }
            Button("Delete", role: .destructive) {
                Task {
                    await viewModel.deleteOpenHouse()
                }
            }
        } message: {
            if let openHouse = viewModel.openHouseToDelete {
                Text("Are you sure you want to delete the open house at \(openHouse.propertyAddress)? This will also delete all attendee sign-ins.")
            }
        }
        // v409: Pending navigation from push notification deep link
        .onAppear {
            checkPendingOpenHouseNavigation()
        }
        .onChange(of: notificationStore.pendingOpenHouseId) { _ in
            checkPendingOpenHouseNavigation()
        }
        .onChange(of: viewModel.openHouses.count) { _ in
            if pendingNavigationOpenHouseId != nil {
                checkPendingOpenHouseNavigation()
            }
        }
    }

    // MARK: - Pending Navigation (v409)

    private func checkPendingOpenHouseNavigation() {
        guard let openHouseId = notificationStore.pendingOpenHouseId else { return }
        notificationStore.clearPendingOpenHouseNavigation()
        pendingNavigationOpenHouseId = openHouseId

        if let openHouse = viewModel.openHouses.first(where: { $0.id == openHouseId }) {
            pendingNavigationOpenHouseId = nil
            viewModel.selectedOpenHouse = openHouse
        } else {
            // Data may not be loaded yet — refresh and retry
            Task {
                await viewModel.loadOpenHouses(forceRefresh: true)
                if let openHouse = viewModel.openHouses.first(where: { $0.id == openHouseId }) {
                    await MainActor.run {
                        pendingNavigationOpenHouseId = nil
                        viewModel.selectedOpenHouse = openHouse
                    }
                } else {
                    await MainActor.run { pendingNavigationOpenHouseId = nil }
                }
            }
        }
    }

    // MARK: - Empty State

    private var emptyStateView: some View {
        VStack(spacing: 20) {
            Image(systemName: "person.crop.rectangle.badge.plus")
                .font(.system(size: 60))
                .foregroundStyle(AppColors.brandTeal)

            Text("No Open Houses")
                .font(.title2)
                .fontWeight(.semibold)

            Text("Create your first open house to start collecting sign-ins from visitors.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button {
                viewModel.showCreateSheet = true
            } label: {
                Text("Create Open House")
                    .fontWeight(.semibold)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .padding(.horizontal, 40)
            .padding(.top, 10)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Sync Status Banner

    @ViewBuilder
    private var syncStatusBanner: some View {
        if !offlineStore.isOnline || offlineStore.hasPendingSync {
            HStack {
                if !offlineStore.isOnline {
                    Image(systemName: "wifi.slash")
                        .foregroundStyle(.white)
                    Text("Offline - Attendees will sync when connected")
                        .foregroundStyle(.white)
                } else if offlineStore.isSyncing {
                    ProgressView()
                        .tint(.white)
                    Text("Syncing \(offlineStore.pendingSyncCount) attendee(s)...")
                        .foregroundStyle(.white)
                } else if offlineStore.hasPendingSync {
                    Image(systemName: "exclamationmark.icloud")
                        .foregroundStyle(.white)
                    Text("\(offlineStore.pendingSyncCount) attendee(s) pending sync")
                        .foregroundStyle(.white)
                    Spacer()
                    Button("Retry") {
                        Task {
                            await offlineStore.retryFailedAttendees()
                        }
                    }
                    .font(.caption)
                    .foregroundStyle(.white)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(Color.white.opacity(0.2))
                    .clipShape(Capsule())
                }
            }
            .font(.subheadline)
            .padding()
            .frame(maxWidth: .infinity)
            .background(!offlineStore.isOnline ? Color.orange : Color.yellow.opacity(0.8))
        }
    }

    // MARK: - Open Houses List

    private var openHousesList: some View {
        List {
            // Sync status
            if !offlineStore.isOnline || offlineStore.hasPendingSync {
                Section {
                    syncStatusBanner
                        .listRowInsets(EdgeInsets())
                        .listRowBackground(Color.clear)
                }
            }

            // Today's open houses
            let todayOpenHouses = viewModel.openHouses.filter { $0.isToday }
            if !todayOpenHouses.isEmpty {
                Section("Today") {
                    ForEach(todayOpenHouses) { openHouse in
                        OpenHouseRow(openHouse: openHouse)
                            .contentShape(Rectangle())
                            .onTapGesture {
                                viewModel.selectedOpenHouse = openHouse
                            }
                            .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                                Button(role: .destructive) {
                                    viewModel.confirmDelete(openHouse)
                                } label: {
                                    Label("Delete", systemImage: "trash")
                                }
                            }
                    }
                }
            }

            // Upcoming open houses
            let upcomingOpenHouses = viewModel.openHouses.filter { !$0.isToday && $0.isUpcoming && $0.status != .cancelled }
            if !upcomingOpenHouses.isEmpty {
                Section("Upcoming") {
                    ForEach(upcomingOpenHouses) { openHouse in
                        OpenHouseRow(openHouse: openHouse)
                            .contentShape(Rectangle())
                            .onTapGesture {
                                viewModel.selectedOpenHouse = openHouse
                            }
                            .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                                Button(role: .destructive) {
                                    viewModel.confirmDelete(openHouse)
                                } label: {
                                    Label("Delete", systemImage: "trash")
                                }
                            }
                    }
                }
            }

            // Past open houses
            let pastOpenHouses = viewModel.openHouses.filter { $0.isPast || $0.status == .completed }
            if !pastOpenHouses.isEmpty {
                Section("Past") {
                    ForEach(pastOpenHouses) { openHouse in
                        OpenHouseRow(openHouse: openHouse)
                            .contentShape(Rectangle())
                            .onTapGesture {
                                viewModel.selectedOpenHouse = openHouse
                            }
                            .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                                Button(role: .destructive) {
                                    viewModel.confirmDelete(openHouse)
                                } label: {
                                    Label("Delete", systemImage: "trash")
                                }
                            }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
    }
}

// MARK: - Open House Row

struct OpenHouseRow: View {
    let openHouse: OpenHouse

    var body: some View {
        HStack(spacing: 12) {
            // Property photo or placeholder
            if let photoUrl = openHouse.photoUrl, let url = URL(string: photoUrl) {
                AsyncImage(url: url) { image in
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                } placeholder: {
                    Rectangle()
                        .fill(Color.gray.opacity(0.2))
                }
                .frame(width: 70, height: 50)
                .clipShape(RoundedRectangle(cornerRadius: 8))
            } else {
                Rectangle()
                    .fill(Color.gray.opacity(0.2))
                    .frame(width: 70, height: 50)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                    .overlay {
                        Image(systemName: "house.fill")
                            .foregroundStyle(.gray)
                    }
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(openHouse.propertyAddress)
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .lineLimit(1)

                Text("\(openHouse.propertyCity), \(openHouse.propertyState)")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                HStack(spacing: 8) {
                    Label(openHouse.formattedTime, systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    if openHouse.attendeeCount > 0 {
                        Label("\(openHouse.attendeeCount)", systemImage: "person.fill")
                            .font(.caption)
                            .foregroundStyle(AppColors.brandTeal)
                    }
                }
            }

            Spacer()

            // Status badge
            statusBadge
        }
        .padding(.vertical, 4)
    }

    @ViewBuilder
    private var statusBadge: some View {
        switch openHouse.status {
        case .active:
            Text("LIVE")
                .font(.caption2)
                .fontWeight(.bold)
                .foregroundStyle(.white)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(.green)
                .clipShape(Capsule())

        case .scheduled:
            if openHouse.isToday {
                Text("TODAY")
                    .font(.caption2)
                    .fontWeight(.bold)
                    .foregroundStyle(.white)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(AppColors.brandTeal)
                    .clipShape(Capsule())
            } else {
                Text(openHouse.formattedDate)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

        case .completed:
            Text("Done")
                .font(.caption)
                .foregroundStyle(.secondary)

        case .cancelled:
            Text("Cancelled")
                .font(.caption)
                .foregroundStyle(.red)
        }
    }
}

// MARK: - View Model

@MainActor
class OpenHouseListViewModel: ObservableObject {
    @Published var openHouses: [OpenHouse] = []
    @Published var isLoading = false
    @Published var showCreateSheet = false
    @Published var selectedOpenHouse: OpenHouse?
    @Published var showError = false
    @Published var errorMessage = ""
    @Published var openHouseToDelete: OpenHouse?
    @Published var showDeleteConfirmation = false
    @Published var isDeleting = false

    func loadOpenHouses(forceRefresh: Bool = false) async {
        isLoading = true
        defer { isLoading = false }

        do {
            openHouses = try await OpenHouseService.shared.fetchOpenHouses(forceRefresh: forceRefresh)
            // Sort by date, most recent first
            openHouses.sort { ($0.dateTime ?? .distantPast) > ($1.dateTime ?? .distantPast) }
        } catch {
            errorMessage = error.localizedDescription
            showError = true
        }
    }

    func confirmDelete(_ openHouse: OpenHouse) {
        openHouseToDelete = openHouse
        showDeleteConfirmation = true
    }

    func deleteOpenHouse() async {
        guard let openHouse = openHouseToDelete else { return }
        isDeleting = true
        defer { isDeleting = false }

        do {
            try await OpenHouseService.shared.deleteOpenHouse(id: openHouse.id)
            // Remove from local list
            openHouses.removeAll { $0.id == openHouse.id }
            openHouseToDelete = nil
        } catch {
            errorMessage = "Failed to delete: \(error.localizedDescription)"
            showError = true
        }
    }
}

// MARK: - Create Open House View

struct CreateOpenHouseView: View {
    var prefilledProperty: PropertyOpenHouseData? = nil  // v6.69.0: Optional pre-fill from property detail
    let onSave: (OpenHouse) -> Void
    @Environment(\.dismiss) private var dismiss
    @StateObject private var viewModel = CreateOpenHouseViewModel()

    var body: some View {
        Form {
            // Property Address Section
            Section("Property Address") {
                TextField("Street Address", text: $viewModel.streetAddress)
                    .textContentType(.streetAddressLine1)
                    .disabled(prefilledProperty != nil)

                HStack {
                    TextField("City", text: $viewModel.city)
                        .textContentType(.addressCity)
                        .disabled(prefilledProperty != nil)

                    TextField("State", text: $viewModel.state)
                        .textContentType(.addressState)
                        .frame(width: 60)
                        .disabled(prefilledProperty != nil)
                }

                TextField("ZIP Code", text: $viewModel.zip)
                    .textContentType(.postalCode)
                    .keyboardType(.numberPad)
                    .disabled(prefilledProperty != nil)
            }

            // Property Details Section (Optional)
            Section("Property Details (Optional)") {
                HStack {
                    Text("Bedrooms")
                    Spacer()
                    TextField("0", text: $viewModel.bedsText)
                        .keyboardType(.numberPad)
                        .multilineTextAlignment(.trailing)
                        .frame(width: 60)
                }

                HStack {
                    Text("Bathrooms")
                    Spacer()
                    TextField("0", text: $viewModel.bathsText)
                        .keyboardType(.decimalPad)
                        .multilineTextAlignment(.trailing)
                        .frame(width: 60)
                }

                HStack {
                    Text("List Price")
                    Spacer()
                    TextField("$0", text: $viewModel.priceText)
                        .keyboardType(.numberPad)
                        .multilineTextAlignment(.trailing)
                        .frame(width: 120)
                }
            }

            // Event Details Section
            Section("Event Details") {
                DatePicker("Date", selection: $viewModel.eventDate, displayedComponents: .date)

                DatePicker("Start Time", selection: $viewModel.startTime, displayedComponents: .hourAndMinute)

                DatePicker("End Time", selection: $viewModel.endTime, displayedComponents: .hourAndMinute)
            }

            // Notes Section
            Section("Notes (Optional)") {
                TextField("Add any notes about the open house...", text: $viewModel.notes, axis: .vertical)
                    .lineLimit(3...6)
            }
        }
        .navigationTitle("New Open House")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Cancel") {
                    dismiss()
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button("Save") {
                    Task {
                        if let openHouse = await viewModel.createOpenHouse() {
                            onSave(openHouse)
                            dismiss()
                        }
                    }
                }
                .disabled(!viewModel.isValid || viewModel.isSaving)
            }
        }
        .alert("Error", isPresented: $viewModel.showError) {
            Button("OK", role: .cancel) { }
        } message: {
            Text(viewModel.errorMessage)
        }
        .overlay {
            if viewModel.isSaving {
                Color.black.opacity(0.3)
                    .ignoresSafeArea()
                ProgressView("Creating open house...")
                    .padding()
                    .background(Color(.systemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
        .onAppear {
            // v6.69.0: Pre-fill from property if provided
            if let property = prefilledProperty {
                viewModel.prefill(from: property)
            }
        }
    }
}

// MARK: - Create Open House View Model

@MainActor
class CreateOpenHouseViewModel: ObservableObject {
    @Published var streetAddress = ""
    @Published var city = ""
    @Published var state = "MA"
    @Published var zip = ""

    @Published var bedsText = ""
    @Published var bathsText = ""
    @Published var priceText = ""

    @Published var eventDate = Date()
    @Published var startTime = Calendar.current.date(bySettingHour: 14, minute: 0, second: 0, of: Date()) ?? Date()
    @Published var endTime = Calendar.current.date(bySettingHour: 16, minute: 0, second: 0, of: Date()) ?? Date()
    @Published var notes = ""

    @Published var isSaving = false
    @Published var showError = false
    @Published var errorMessage = ""

    // v6.69.0: Store prefilled property data for use in request
    private var prefilledProperty: PropertyOpenHouseData?

    /// Pre-fill form fields from property data
    func prefill(from property: PropertyOpenHouseData) {
        prefilledProperty = property
        streetAddress = property.streetAddress
        city = property.city
        state = property.state
        zip = property.zip

        if let beds = property.beds {
            bedsText = String(beds)
        }
        if let baths = property.baths {
            bathsText = String(format: "%.1f", baths)
        }
        if let price = property.listPrice {
            priceText = "$\(NumberFormatter.localizedString(from: NSNumber(value: price), number: .decimal))"
        }
    }

    var isValid: Bool {
        !streetAddress.trimmingCharacters(in: .whitespaces).isEmpty &&
        !city.trimmingCharacters(in: .whitespaces).isEmpty &&
        !state.trimmingCharacters(in: .whitespaces).isEmpty &&
        !zip.trimmingCharacters(in: .whitespaces).isEmpty
    }

    private var beds: Int? {
        Int(bedsText)
    }

    private var baths: Double? {
        Double(bathsText)
    }

    private var price: Int? {
        // Remove any non-numeric characters except digits
        let cleanPrice = priceText.components(separatedBy: CharacterSet.decimalDigits.inverted).joined()
        return Int(cleanPrice)
    }

    private var formattedStartTime: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm"
        return formatter.string(from: startTime)
    }

    private var formattedEndTime: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm"
        return formatter.string(from: endTime)
    }

    private var formattedEventDate: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter.string(from: eventDate)
    }

    func createOpenHouse() async -> OpenHouse? {
        isSaving = true
        defer { isSaving = false }

        // v6.69.0: Use prefilled property data when available
        let request = CreateOpenHouseRequest(
            listingId: prefilledProperty?.listingId,
            propertyAddress: streetAddress.trimmingCharacters(in: .whitespaces),
            propertyCity: city.trimmingCharacters(in: .whitespaces),
            propertyState: state.trimmingCharacters(in: .whitespaces),
            propertyZip: zip.trimmingCharacters(in: .whitespaces),
            propertyType: prefilledProperty?.propertyType,
            beds: beds,
            baths: baths,
            listPrice: price,
            photoUrl: prefilledProperty?.photoUrl,
            latitude: prefilledProperty?.latitude,
            longitude: prefilledProperty?.longitude,
            eventDate: formattedEventDate,
            startTime: formattedStartTime,
            endTime: formattedEndTime,
            notes: notes.isEmpty ? nil : notes
        )

        do {
            let openHouse = try await OpenHouseService.shared.createOpenHouse(request: request)
            return openHouse
        } catch {
            errorMessage = error.localizedDescription
            showError = true
            return nil
        }
    }
}

struct OpenHouseDetailView: View {
    let openHouse: OpenHouse
    let onUpdate: (OpenHouse) -> Void
    @Environment(\.dismiss) private var dismiss
    @StateObject private var viewModel = OpenHouseDetailViewModel()
    @State private var showKioskMode = false
    @State private var showAttendees = false
    @State private var showEndConfirmation = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Property photo
                if let photoUrl = openHouse.photoUrl, let url = URL(string: photoUrl) {
                    AsyncImage(url: url) { image in
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    } placeholder: {
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                    }
                    .frame(height: 200)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }

                // Property info
                VStack(alignment: .leading, spacing: 8) {
                    Text(openHouse.propertyAddress)
                        .font(.title2)
                        .fontWeight(.bold)

                    Text("\(openHouse.propertyCity), \(openHouse.propertyState) \(openHouse.propertyZip)")
                        .foregroundStyle(.secondary)

                    HStack {
                        if let beds = openHouse.beds {
                            Label("\(beds) beds", systemImage: "bed.double.fill")
                        }
                        if let baths = openHouse.baths {
                            Label(String(format: "%.1f baths", baths), systemImage: "shower.fill")
                        }
                        if let price = openHouse.formattedPrice {
                            Text(price)
                                .fontWeight(.semibold)
                        }
                    }
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .shadow(color: .black.opacity(0.05), radius: 5)

                // Status badge
                statusSection

                // Event info
                VStack(alignment: .leading, spacing: 12) {
                    Text("Event Details")
                        .font(.headline)

                    HStack {
                        Image(systemName: "calendar")
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 24)
                        Text(openHouse.formattedDate)
                    }

                    HStack {
                        Image(systemName: "clock")
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 24)
                        Text(openHouse.formattedTime)
                    }

                    HStack {
                        Image(systemName: "person.2.fill")
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 24)
                        Text("\(openHouse.attendeeCount) attendees")
                    }

                    if let notes = openHouse.notes, !notes.isEmpty {
                        HStack(alignment: .top) {
                            Image(systemName: "note.text")
                                .foregroundStyle(AppColors.brandTeal)
                                .frame(width: 24)
                            Text(notes)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .shadow(color: .black.opacity(0.05), radius: 5)

                // Actions
                actionsSection
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("Open House")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Close") {
                    dismiss()
                }
            }
        }
        .alert("End Open House?", isPresented: $showEndConfirmation) {
            Button("Cancel", role: .cancel) { }
            Button("End", role: .destructive) {
                Task {
                    if let updated = await viewModel.endOpenHouse(id: openHouse.id) {
                        onUpdate(updated)
                    }
                }
            }
        } message: {
            Text("This will mark the open house as completed. You can still view attendee data afterward.")
        }
        .alert("Error", isPresented: $viewModel.showError) {
            Button("OK", role: .cancel) { }
        } message: {
            Text(viewModel.errorMessage)
        }
        .fullScreenCover(isPresented: $showKioskMode) {
            KioskSignInView(openHouse: openHouse) { attendee in
                // Attendee was added via kiosk mode
                // The attendee is already saved to the server by KioskSignInFormView
            }
        }
        .sheet(isPresented: $showAttendees) {
            NavigationStack {
                AttendeeListView(openHouseId: openHouse.id)
            }
        }
        .overlay {
            if viewModel.isLoading {
                Color.black.opacity(0.3)
                    .ignoresSafeArea()
                ProgressView()
                    .padding()
                    .background(Color(.systemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    @ViewBuilder
    private var statusSection: some View {
        HStack {
            switch openHouse.status {
            case .active:
                Label("LIVE NOW", systemImage: "circle.fill")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.white)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 8)
                    .background(.green)
                    .clipShape(Capsule())

            case .scheduled:
                if openHouse.isToday {
                    Label("TODAY", systemImage: "calendar")
                        .font(.subheadline)
                        .fontWeight(.semibold)
                        .foregroundStyle(.white)
                        .padding(.horizontal, 16)
                        .padding(.vertical, 8)
                        .background(AppColors.brandTeal)
                        .clipShape(Capsule())
                } else {
                    Label("Scheduled", systemImage: "calendar.badge.clock")
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(.secondary)
                }

            case .completed:
                Label("Completed", systemImage: "checkmark.circle.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)

            case .cancelled:
                Label("Cancelled", systemImage: "xmark.circle.fill")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(.red)
            }
            Spacer()
        }
    }

    @ViewBuilder
    private var actionsSection: some View {
        VStack(spacing: 12) {
            if openHouse.status == .scheduled {
                Button {
                    Task {
                        if let updated = await viewModel.startOpenHouse(id: openHouse.id) {
                            onUpdate(updated)
                            showKioskMode = true
                        }
                    }
                } label: {
                    Label("Start Kiosk Mode", systemImage: "play.fill")
                        .fontWeight(.semibold)
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(AppColors.brandTeal)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }

            if openHouse.status == .active {
                Button {
                    showKioskMode = true
                } label: {
                    Label("Continue Kiosk Mode", systemImage: "play.fill")
                        .fontWeight(.semibold)
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(AppColors.brandTeal)
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }

                Button {
                    showAttendees = true
                } label: {
                    Label("View Attendees", systemImage: "person.3.fill")
                        .fontWeight(.semibold)
                        .foregroundStyle(AppColors.brandTeal)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(AppColors.brandTeal.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }

                Button {
                    showEndConfirmation = true
                } label: {
                    Label("End Open House", systemImage: "stop.fill")
                        .fontWeight(.semibold)
                        .foregroundStyle(.red)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(Color.red.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }

            if openHouse.status == .completed && openHouse.attendeeCount > 0 {
                Button {
                    showAttendees = true
                } label: {
                    Label("View Attendees (\(openHouse.attendeeCount))", systemImage: "person.3.fill")
                        .fontWeight(.semibold)
                        .foregroundStyle(AppColors.brandTeal)
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(AppColors.brandTeal.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }
        }
        .padding()
    }
}

// MARK: - Open House Detail View Model

@MainActor
class OpenHouseDetailViewModel: ObservableObject {
    @Published var isLoading = false
    @Published var showError = false
    @Published var errorMessage = ""

    func startOpenHouse(id: Int) async -> OpenHouse? {
        isLoading = true
        defer { isLoading = false }

        do {
            let updated = try await OpenHouseService.shared.startOpenHouse(id: id)
            return updated
        } catch {
            errorMessage = error.localizedDescription
            showError = true
            return nil
        }
    }

    func endOpenHouse(id: Int) async -> OpenHouse? {
        isLoading = true
        defer { isLoading = false }

        do {
            let updated = try await OpenHouseService.shared.endOpenHouse(id: id)
            return updated
        } catch {
            errorMessage = error.localizedDescription
            showError = true
            return nil
        }
    }
}

// MARK: - Kiosk Mode View (iPad-Optimized)

struct OpenHouseKioskView: View {
    let openHouse: OpenHouse
    let onEnd: (OpenHouse) -> Void
    @Environment(\.dismiss) private var dismiss
    @StateObject private var offlineStore = OfflineOpenHouseStore.shared
    @State private var showSignInForm = false
    @State private var attendeeCount: Int
    @State private var exitTapCount = 0
    @State private var showExitConfirmation = false

    init(openHouse: OpenHouse, onEnd: @escaping (OpenHouse) -> Void) {
        self.openHouse = openHouse
        self.onEnd = onEnd
        self._attendeeCount = State(initialValue: openHouse.attendeeCount)
    }

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                // Background gradient
                LinearGradient(
                    colors: [AppColors.brandTeal.opacity(0.1), Color(.systemBackground)],
                    startPoint: .top,
                    endPoint: .bottom
                )
                .ignoresSafeArea()

                VStack(spacing: 40) {
                    // Property info header
                    VStack(spacing: 12) {
                        Text("Welcome to")
                            .font(.title2)
                            .foregroundStyle(.secondary)

                        Text(openHouse.propertyAddress)
                            .font(.system(size: 32, weight: .bold))
                            .multilineTextAlignment(.center)

                        Text("\(openHouse.propertyCity), \(openHouse.propertyState)")
                            .font(.title3)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.top, 60)

                    Spacer()

                    // Sign-in button
                    Button {
                        showSignInForm = true
                    } label: {
                        VStack(spacing: 16) {
                            Image(systemName: "person.crop.rectangle.badge.plus")
                                .font(.system(size: 60))

                            Text("Tap to Sign In")
                                .font(.system(size: 28, weight: .semibold))
                        }
                        .foregroundStyle(.white)
                        .frame(width: min(geometry.size.width * 0.6, 400), height: 220)
                        .background(AppColors.brandTeal)
                        .clipShape(RoundedRectangle(cornerRadius: 24))
                        .shadow(color: AppColors.brandTeal.opacity(0.3), radius: 20, y: 10)
                    }

                    Spacer()

                    // Sync status indicator
                    if !offlineStore.isOnline || offlineStore.hasPendingSync {
                        HStack(spacing: 8) {
                            if !offlineStore.isOnline {
                                Image(systemName: "wifi.slash")
                                    .foregroundStyle(.orange)
                                Text("Offline Mode")
                                    .foregroundStyle(.orange)
                            } else if offlineStore.isSyncing {
                                ProgressView()
                                    .scaleEffect(0.8)
                                Text("Syncing...")
                                    .foregroundStyle(.blue)
                            } else if offlineStore.hasPendingSync {
                                Image(systemName: "exclamationmark.icloud")
                                    .foregroundStyle(.yellow)
                                Text("\(offlineStore.pendingSyncCount) pending sync")
                                    .foregroundStyle(.yellow)
                            }
                        }
                        .font(.subheadline)
                        .padding(.horizontal, 16)
                        .padding(.vertical, 8)
                        .background(Color(.systemBackground).opacity(0.9))
                        .clipShape(Capsule())
                        .padding(.bottom, 8)
                    }

                    // Attendee count
                    HStack {
                        Image(systemName: "person.2.fill")
                        Text("\(attendeeCount) visitors signed in")
                    }
                    .font(.title3)
                    .foregroundStyle(.secondary)
                    .padding(.bottom, 40)
                }

                // Hidden exit button (top-left corner - 5 taps to exit)
                VStack {
                    HStack {
                        Rectangle()
                            .fill(Color.clear)
                            .frame(width: 60, height: 60)
                            .contentShape(Rectangle())
                            .onTapGesture {
                                exitTapCount += 1
                                if exitTapCount >= 5 {
                                    showExitConfirmation = true
                                    exitTapCount = 0
                                }
                                // Reset tap count after 2 seconds
                                Task {
                                    try? await Task.sleep(nanoseconds: 2_000_000_000)
                                    exitTapCount = 0
                                }
                            }
                        Spacer()
                    }
                    Spacer()
                }
            }
        }
        .sheet(isPresented: $showSignInForm) {
            AttendeeSignInFormView(openHouseId: openHouse.id) { _ in
                attendeeCount += 1
            }
        }
        .alert("Exit Kiosk Mode?", isPresented: $showExitConfirmation) {
            Button("Cancel", role: .cancel) { }
            Button("Exit") {
                dismiss()
            }
        } message: {
            Text("You can resume kiosk mode from the open house details.")
        }
        .statusBarHidden()
        .persistentSystemOverlays(.hidden)
    }
}

// MARK: - Attendee Sign-In Form View (v6.70.0 - Agent Branching)

struct AttendeeSignInFormView: View {
    let openHouseId: Int
    let onSave: (OpenHouseAttendee) -> Void
    @Environment(\.dismiss) private var dismiss
    @StateObject private var viewModel = AttendeeSignInViewModel()
    @State private var currentStep = 0

    // Total steps depends on whether visitor is agent or buyer
    private var totalSteps: Int {
        viewModel.isVisitorAgent ? 5 : 5  // Both paths have 5 steps (including the branch decision)
    }

    var body: some View {
        NavigationStack {
            VStack {
                // Progress indicator
                HStack(spacing: 8) {
                    ForEach(0..<totalSteps, id: \.self) { step in
                        Capsule()
                            .fill(step <= currentStep ? AppColors.brandTeal : Color.gray.opacity(0.3))
                            .frame(height: 4)
                    }
                }
                .padding(.horizontal, 40)
                .padding(.top)

                TabView(selection: $currentStep) {
                    // Step 0: Name
                    nameStep.tag(0)

                    // Step 1: Contact
                    contactStep.tag(1)

                    // Step 2: Are you a real estate agent?
                    isAgentStep.tag(2)

                    // Step 3A: Agent Details (if agent) OR Step 3B: Buyer Questions (if buyer)
                    if viewModel.isVisitorAgent {
                        agentDetailsStep.tag(3)
                    } else {
                        buyerQuestionsStep.tag(3)
                    }

                    // Step 4: Consent & Submit
                    finalStep.tag(4)
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .animation(.easeInOut, value: currentStep)
            }
            .navigationTitle("Sign In")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
            }
            .alert("Error", isPresented: $viewModel.showError) {
                Button("OK", role: .cancel) { }
            } message: {
                Text(viewModel.errorMessage)
            }
        }
    }

    // MARK: - Step 0: Name

    private var nameStep: some View {
        VStack(spacing: 24) {
            Text("What's your name?")
                .font(.title)
                .fontWeight(.bold)

            VStack(spacing: 16) {
                TextField("First Name", text: $viewModel.firstName)
                    .textFieldStyle(.roundedBorder)
                    .font(.title3)
                    .textContentType(.givenName)

                TextField("Last Name", text: $viewModel.lastName)
                    .textFieldStyle(.roundedBorder)
                    .font(.title3)
                    .textContentType(.familyName)
            }
            .padding(.horizontal, 40)

            Spacer()

            nextButton(enabled: !viewModel.firstName.isEmpty && !viewModel.lastName.isEmpty)
        }
        .padding(.top, 40)
    }

    // MARK: - Step 1: Contact

    private var contactStep: some View {
        VStack(spacing: 24) {
            Text("How can we reach you?")
                .font(.title)
                .fontWeight(.bold)

            VStack(spacing: 16) {
                TextField("Email", text: $viewModel.email)
                    .textFieldStyle(.roundedBorder)
                    .font(.title3)
                    .textContentType(.emailAddress)
                    .keyboardType(.emailAddress)
                    .autocapitalization(.none)

                TextField("Phone", text: $viewModel.phone)
                    .textFieldStyle(.roundedBorder)
                    .font(.title3)
                    .textContentType(.telephoneNumber)
                    .keyboardType(.phonePad)
            }
            .padding(.horizontal, 40)

            Spacer()

            nextButton(enabled: !viewModel.email.isEmpty && !viewModel.phone.isEmpty)
        }
        .padding(.top, 40)
    }

    // MARK: - Step 2: Are you a real estate agent? (v6.70.0)

    private var isAgentStep: some View {
        VStack(spacing: 24) {
            Text("Are you a real estate agent?")
                .font(.title)
                .fontWeight(.bold)

            Text("This helps us provide the right information")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(spacing: 16) {
                Button {
                    viewModel.isVisitorAgent = true
                    withAnimation {
                        currentStep = 3
                    }
                } label: {
                    HStack {
                        Image(systemName: "briefcase.fill")
                        Text("Yes, I'm an Agent")
                    }
                    .fontWeight(.medium)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(Color.orange)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }

                Button {
                    viewModel.isVisitorAgent = false
                    withAnimation {
                        currentStep = 3
                    }
                } label: {
                    HStack {
                        Image(systemName: "house.fill")
                        Text("No, I'm a Buyer")
                    }
                    .fontWeight(.medium)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }
            .padding(.horizontal, 40)

            Spacer()
        }
        .padding(.top, 40)
    }

    // MARK: - Step 3A: Agent Details (v6.70.0)

    private var agentDetailsStep: some View {
        ScrollView {
            VStack(spacing: 24) {
                // Orange badge indicating agent path
                HStack {
                    Image(systemName: "briefcase.fill")
                    Text("Agent Information")
                }
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.white)
                .padding(.horizontal, 12)
                .padding(.vertical, 6)
                .background(Color.orange)
                .clipShape(Capsule())

                Text("Tell us about your visit")
                    .font(.title)
                    .fontWeight(.bold)

                VStack(spacing: 16) {
                    // Brokerage (Required)
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Your Brokerage *")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                        TextField("e.g., Compass, RE/MAX", text: $viewModel.agentBrokerage)
                            .textFieldStyle(.roundedBorder)
                            .font(.title3)
                    }

                    // Visit Purpose (Required)
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Why are you visiting? *")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                        ForEach(AgentVisitPurpose.allCases, id: \.self) { purpose in
                            Button {
                                viewModel.agentVisitPurpose = purpose
                            } label: {
                                HStack {
                                    Image(systemName: purposeIcon(purpose))
                                    Text(purpose.displayName)
                                    Spacer()
                                    if viewModel.agentVisitPurpose == purpose {
                                        Image(systemName: "checkmark")
                                    }
                                }
                                .fontWeight(.medium)
                                .foregroundStyle(viewModel.agentVisitPurpose == purpose ? .white : .primary)
                                .padding()
                                .background(viewModel.agentVisitPurpose == purpose ? Color.orange : Color.gray.opacity(0.1))
                                .clipShape(RoundedRectangle(cornerRadius: 10))
                            }
                        }
                    }

                    // Has buyer interested? (Optional)
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Do you have a buyer interested in this property?")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                        HStack(spacing: 12) {
                            Button {
                                viewModel.agentHasBuyer = true
                            } label: {
                                HStack {
                                    Image(systemName: viewModel.agentHasBuyer == true ? "checkmark.circle.fill" : "circle")
                                    Text("Yes")
                                }
                                .fontWeight(.medium)
                                .foregroundStyle(viewModel.agentHasBuyer == true ? .white : .primary)
                                .frame(maxWidth: .infinity)
                                .padding()
                                .background(viewModel.agentHasBuyer == true ? .green : Color.gray.opacity(0.1))
                                .clipShape(RoundedRectangle(cornerRadius: 10))
                            }

                            Button {
                                viewModel.agentHasBuyer = false
                            } label: {
                                HStack {
                                    Image(systemName: viewModel.agentHasBuyer == false ? "checkmark.circle.fill" : "circle")
                                    Text("No")
                                }
                                .fontWeight(.medium)
                                .foregroundStyle(viewModel.agentHasBuyer == false ? .white : .primary)
                                .frame(maxWidth: .infinity)
                                .padding()
                                .background(viewModel.agentHasBuyer == false ? Color.gray.opacity(0.5) : Color.gray.opacity(0.1))
                                .clipShape(RoundedRectangle(cornerRadius: 10))
                            }
                        }
                    }

                    // Buyer timeline (conditional on has buyer)
                    if viewModel.agentHasBuyer == true {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("When might they make an offer?")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)

                            Picker("Buyer Timeline", selection: $viewModel.agentBuyerTimeline) {
                                Text("ASAP").tag("asap")
                                Text("This Week").tag("this_week")
                                Text("This Month").tag("this_month")
                                Text("A Few Months").tag("few_months")
                                Text("Not Sure").tag("not_sure")
                            }
                            .pickerStyle(.segmented)
                        }
                    }

                    // Network interest (Optional)
                    Toggle(isOn: Binding(
                        get: { viewModel.agentNetworkInterest ?? false },
                        set: { viewModel.agentNetworkInterest = $0 }
                    )) {
                        VStack(alignment: .leading) {
                            Text("Open to networking/referrals?")
                                .font(.subheadline)
                            Text("The hosting agent may reach out")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .tint(Color.orange)
                }
                .padding(.horizontal, 40)

                Spacer(minLength: 20)

                nextButton(enabled: !viewModel.agentBrokerage.isEmpty && viewModel.agentVisitPurpose != nil)
            }
            .padding(.top, 20)
        }
    }

    private func purposeIcon(_ purpose: AgentVisitPurpose) -> String {
        switch purpose {
        case .previewing: return "eye.fill"
        case .comps: return "chart.bar.fill"
        case .networking: return "person.2.fill"
        case .curiosity: return "questionmark.circle.fill"
        case .other: return "ellipsis.circle.fill"
        }
    }

    // MARK: - Step 3B: Buyer Questions (existing)

    private var buyerQuestionsStep: some View {
        VStack(spacing: 24) {
            Text("Tell us about your search")
                .font(.title)
                .fontWeight(.bold)

            VStack(spacing: 20) {
                // Working with agent
                VStack(alignment: .leading, spacing: 8) {
                    Text("Are you working with an agent?")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    ForEach(WorkingWithAgentStatus.allCases, id: \.self) { status in
                        Button {
                            viewModel.workingWithAgent = status
                        } label: {
                            HStack {
                                Text(status.displayName)
                                Spacer()
                                if viewModel.workingWithAgent == status {
                                    Image(systemName: "checkmark")
                                }
                            }
                            .fontWeight(.medium)
                            .foregroundStyle(viewModel.workingWithAgent == status ? .white : .primary)
                            .padding()
                            .background(viewModel.workingWithAgent == status ? AppColors.brandTeal : Color.gray.opacity(0.1))
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                        }
                    }
                }

                // Buying timeline
                VStack(alignment: .leading, spacing: 8) {
                    Text("When are you looking to buy?")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    Picker("Timeline", selection: $viewModel.buyingTimeline) {
                        ForEach(BuyingTimeline.allCases, id: \.self) { timeline in
                            Text(timeline.displayName).tag(timeline)
                        }
                    }
                    .pickerStyle(.segmented)
                }

                // Pre-approved
                VStack(alignment: .leading, spacing: 8) {
                    Text("Are you pre-approved for a mortgage?")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    Picker("Pre-Approved", selection: $viewModel.preApproved) {
                        ForEach(PreApprovalStatus.allCases, id: \.self) { status in
                            Text(status.displayName).tag(status)
                        }
                    }
                    .pickerStyle(.segmented)
                }
            }
            .padding(.horizontal, 40)

            Spacer()

            nextButton(enabled: true)
        }
        .padding(.top, 40)
    }

    // MARK: - Step 4: Consent & Submit

    private var finalStep: some View {
        VStack(spacing: 24) {
            Text("Almost done!")
                .font(.title)
                .fontWeight(.bold)

            VStack(spacing: 16) {
                // Show different text based on visitor type
                if viewModel.isVisitorAgent {
                    Text("Thanks for visiting! The hosting agent may reach out to connect.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                } else {
                    Text("We'd love to help you find your perfect home.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }

                // Consent toggles
                VStack(alignment: .leading, spacing: 12) {
                    Toggle("I consent to receive follow-up communications", isOn: $viewModel.consentToFollowUp)
                        .tint(AppColors.brandTeal)

                    if viewModel.consentToFollowUp {
                        VStack(alignment: .leading, spacing: 8) {
                            Toggle("Email updates", isOn: $viewModel.consentToEmail)
                                .tint(AppColors.brandTeal)
                                .font(.subheadline)

                            Toggle("Text/SMS updates", isOn: $viewModel.consentToText)
                                .tint(AppColors.brandTeal)
                                .font(.subheadline)
                        }
                        .padding(.leading, 24)
                    }
                }
            }
            .padding(.horizontal, 40)

            Spacer()

            Button {
                Task {
                    if let attendee = await viewModel.submitAttendee(openHouseId: openHouseId) {
                        onSave(attendee)
                        dismiss()
                    }
                }
            } label: {
                if viewModel.isSaving {
                    ProgressView()
                        .tint(.white)
                } else {
                    Text("Complete Sign In")
                }
            }
            .fontWeight(.semibold)
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity)
            .padding()
            .background(viewModel.isVisitorAgent ? Color.orange : AppColors.brandTeal)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .padding(.horizontal, 40)
            .disabled(viewModel.isSaving)
        }
        .padding(.top, 40)
    }

    // MARK: - Next Button

    private func nextButton(enabled: Bool) -> some View {
        Button {
            withAnimation {
                currentStep += 1
            }
        } label: {
            Text("Next")
                .fontWeight(.semibold)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity)
                .padding()
                .background(enabled ? (viewModel.isVisitorAgent && currentStep >= 2 ? Color.orange : AppColors.brandTeal) : Color.gray)
                .clipShape(RoundedRectangle(cornerRadius: 12))
        }
        .disabled(!enabled)
        .padding(.horizontal, 40)
        .padding(.bottom, 40)
    }
}

// MARK: - Attendee Sign-In View Model (v6.70.0 - Agent Branching)

@MainActor
class AttendeeSignInViewModel: ObservableObject {
    // Basic info (all visitors)
    @Published var firstName = ""
    @Published var lastName = ""
    @Published var email = ""
    @Published var phone = ""

    // v6.70.0: Agent detection
    @Published var isVisitorAgent = false

    // Agent-specific fields (v6.70.0)
    @Published var agentBrokerage = ""
    @Published var agentVisitPurpose: AgentVisitPurpose?
    @Published var agentHasBuyer: Bool?
    @Published var agentBuyerTimeline = "not_sure"
    @Published var agentNetworkInterest: Bool?

    // Buyer-specific fields
    @Published var workingWithAgent: WorkingWithAgentStatus = .no
    @Published var buyingTimeline: BuyingTimeline = .justBrowsing
    @Published var preApproved: PreApprovalStatus = .notSure

    // Consent (all visitors)
    @Published var consentToFollowUp = true
    @Published var consentToEmail = true
    @Published var consentToText = true

    // State
    @Published var isSaving = false
    @Published var showError = false
    @Published var errorMessage = ""

    func submitAttendee(openHouseId: Int) async -> OpenHouseAttendee? {
        isSaving = true
        defer { isSaving = false }

        // Determine interest level based on visitor type
        var interestLevel: InterestLevel = .somewhat

        if isVisitorAgent {
            // Agents with buyers are high priority
            if agentHasBuyer == true {
                interestLevel = .veryInterested
            } else {
                interestLevel = .somewhat
            }
        }

        let attendee = OpenHouseAttendee(
            id: nil,
            localUUID: UUID(),
            openHouseId: openHouseId,
            firstName: firstName.trimmingCharacters(in: .whitespaces),
            lastName: lastName.trimmingCharacters(in: .whitespaces),
            email: email.trimmingCharacters(in: .whitespaces),
            phone: phone.trimmingCharacters(in: .whitespaces),
            // v6.70.0: Agent visitor fields (must come after phone per struct definition)
            isAgent: isVisitorAgent,
            visitorAgentBrokerage: isVisitorAgent ? agentBrokerage.trimmingCharacters(in: .whitespaces) : nil,
            agentVisitPurpose: isVisitorAgent ? agentVisitPurpose : nil,
            agentHasBuyer: isVisitorAgent ? agentHasBuyer : nil,
            agentBuyerTimeline: (isVisitorAgent && agentHasBuyer == true) ? agentBuyerTimeline : nil,
            agentNetworkInterest: isVisitorAgent ? agentNetworkInterest : nil,
            // Buyer path fields
            workingWithAgent: isVisitorAgent ? .no : workingWithAgent,  // Not applicable for agents
            otherAgentName: nil,
            otherAgentBrokerage: nil,
            otherAgentPhone: nil,      // v6.71.0: Agent contact phone
            otherAgentEmail: nil,      // v6.71.0: Agent contact email
            buyingTimeline: isVisitorAgent ? .justBrowsing : buyingTimeline,  // Not applicable for agents
            preApproved: isVisitorAgent ? .notSure : preApproved,  // Not applicable for agents
            lenderName: nil,
            howHeardAbout: nil,
            consentToFollowUp: consentToFollowUp,
            consentToEmail: consentToEmail,
            consentToText: consentToText,
            interestLevel: interestLevel,
            agentNotes: nil,
            userId: nil,
            priorityScore: 0,
            signedInAt: Date(),
            syncStatus: .pending,
            deviceTokenForExclusion: PushNotificationManager.shared.deviceToken  // v6.72.0
        )

        // Save locally first for offline support
        await OfflineOpenHouseStore.shared.saveAttendeeLocally(attendee)

        // Try to sync immediately
        do {
            let saved = try await OpenHouseService.shared.addAttendee(openHouseId: openHouseId, attendee: attendee)
            return saved
        } catch {
            // Attendee is saved locally, will sync later
            print("AttendeeSignInViewModel: Failed to sync attendee immediately, saved locally: \(error)")
            // Return the local attendee so the UI updates
            return attendee
        }
    }
}

// MARK: - Attendee List View

struct AttendeeListView: View {
    let openHouseId: Int
    @Environment(\.dismiss) private var dismiss
    @State private var attendees: [OpenHouseAttendee] = []
    @State private var isLoading = true
    @State private var errorMessage: String?

    // Export state
    @State private var isExporting = false
    @State private var showShareSheet = false
    @State private var exportFileURL: URL?

    // Search & filter state
    @State private var searchText = ""
    @State private var filterInterestLevel: InterestLevel?
    @State private var filterTimeline: BuyingTimeline?
    @State private var filterAttendeeType: AttendeeFilterType = .all  // v6.70.0

    // Navigation to detail view
    @State private var selectedAttendee: OpenHouseAttendee?

    private var filteredAttendees: [OpenHouseAttendee] {
        attendees.filter { attendee in
            let matchesSearch = searchText.isEmpty ||
                "\(attendee.firstName) \(attendee.lastName)".localizedCaseInsensitiveContains(searchText) ||
                attendee.email.localizedCaseInsensitiveContains(searchText) ||
                attendee.phone.contains(searchText)

            let matchesInterest = filterInterestLevel == nil || attendee.interestLevel == filterInterestLevel

            let matchesTimeline = filterTimeline == nil || attendee.buyingTimeline == filterTimeline

            // v6.70.0: Attendee type filter
            let matchesType: Bool
            switch filterAttendeeType {
            case .all:
                matchesType = true
            case .buyers:
                matchesType = !attendee.isAgent
            case .agents:
                matchesType = attendee.isAgent
            case .hot:
                matchesType = attendee.priorityScore >= 80 || (attendee.isAgent && attendee.agentHasBuyer == true)
            }

            return matchesSearch && matchesInterest && matchesTimeline && matchesType
        }
    }

    private var navigationTitle: String {
        if filteredAttendees.count != attendees.count {
            return "Attendees (\(filteredAttendees.count) of \(attendees.count))"
        }
        return "Attendees (\(attendees.count))"
    }

    // v6.70.0: Helper computed property for filter indicator
    private var hasActiveFilters: Bool {
        filterInterestLevel != nil || filterTimeline != nil || filterAttendeeType != .all
    }

    // v6.70.0: Icon for filter type
    private func filterTypeIcon(_ type: AttendeeFilterType) -> String {
        switch type {
        case .all: return "person.3.fill"
        case .buyers: return "house.fill"
        case .agents: return "briefcase.fill"
        case .hot: return "flame.fill"
        }
    }

    var body: some View {
        Group {
            if isLoading {
                ProgressView("Loading attendees...")
            } else if attendees.isEmpty {
                VStack(spacing: 16) {
                    Image(systemName: "person.3")
                        .font(.system(size: 50))
                        .foregroundStyle(.secondary)
                    Text("No attendees yet")
                        .font(.headline)
                }
            } else {
                List(filteredAttendees) { attendee in
                    AttendeeRow(attendee: attendee)
                        .contentShape(Rectangle())
                        .onTapGesture {
                            selectedAttendee = attendee
                        }
                }
                .listStyle(.insetGrouped)
                .searchable(text: $searchText, prompt: "Search by name, email, or phone")
            }
        }
        .navigationTitle(navigationTitle)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Close") {
                    dismiss()
                }
            }
            ToolbarItemGroup(placement: .topBarTrailing) {
                // Filter menu
                Menu {
                    // v6.70.0: Attendee type filter (All, Buyers, Agents, Hot Leads)
                    Menu {
                        ForEach(AttendeeFilterType.allCases, id: \.self) { type in
                            Button {
                                filterAttendeeType = type
                            } label: {
                                HStack {
                                    Image(systemName: filterTypeIcon(type))
                                    Text(type.displayName)
                                    if filterAttendeeType == type {
                                        Image(systemName: "checkmark")
                                    }
                                }
                            }
                        }
                    } label: {
                        Label("Visitor Type", systemImage: "person.2")
                    }

                    // Interest level filter
                    Menu {
                        Button("All Levels") {
                            filterInterestLevel = nil
                        }
                        ForEach(InterestLevel.allCases, id: \.self) { level in
                            Button {
                                filterInterestLevel = level
                            } label: {
                                if filterInterestLevel == level {
                                    Label(level.displayName, systemImage: "checkmark")
                                } else {
                                    Text(level.displayName)
                                }
                            }
                        }
                    } label: {
                        Label("Interest Level", systemImage: "star")
                    }

                    // Timeline filter (only relevant for buyers)
                    if filterAttendeeType != .agents {
                        Menu {
                            Button("All Timelines") {
                                filterTimeline = nil
                            }
                            ForEach(BuyingTimeline.allCases, id: \.self) { timeline in
                                Button {
                                    filterTimeline = timeline
                                } label: {
                                    if filterTimeline == timeline {
                                        Label(timeline.displayName, systemImage: "checkmark")
                                    } else {
                                        Text(timeline.displayName)
                                    }
                                }
                            }
                        } label: {
                            Label("Timeline", systemImage: "calendar")
                        }
                    }

                    Divider()

                    // Clear filters
                    if filterInterestLevel != nil || filterTimeline != nil || filterAttendeeType != .all {
                        Button("Clear Filters") {
                            filterInterestLevel = nil
                            filterTimeline = nil
                            filterAttendeeType = .all
                        }
                    }
                } label: {
                    Image(systemName: hasActiveFilters ? "line.3.horizontal.decrease.circle.fill" : "line.3.horizontal.decrease.circle")
                }

                // Export button
                Button {
                    Task { await exportAttendees() }
                } label: {
                    if isExporting {
                        ProgressView()
                    } else {
                        Image(systemName: "square.and.arrow.up")
                    }
                }
                .disabled(isExporting || attendees.isEmpty)
            }
        }
        .task {
            await loadAttendees()
        }
        .sheet(isPresented: $showShareSheet) {
            if let url = exportFileURL {
                ShareSheet(items: [url])
            }
        }
        .sheet(item: $selectedAttendee) { attendee in
            NavigationStack {
                AttendeeDetailView(
                    attendee: attendee,
                    openHouseId: openHouseId,
                    onUpdate: { updatedAttendee in
                        if let index = attendees.firstIndex(where: { $0.localUUID == updatedAttendee.localUUID }) {
                            attendees[index] = updatedAttendee
                        }
                    }
                )
            }
        }
        .alert("Error", isPresented: .constant(errorMessage != nil)) {
            Button("OK") { errorMessage = nil }
        } message: {
            if let message = errorMessage {
                Text(message)
            }
        }
    }

    private func loadAttendees() async {
        isLoading = true
        defer { isLoading = false }

        do {
            let detail = try await OpenHouseService.shared.fetchOpenHouseDetail(id: openHouseId)
            attendees = detail.attendees
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func exportAttendees() async {
        isExporting = true
        defer { isExporting = false }

        do {
            let csvContent = try await OpenHouseService.shared.exportAttendees(openHouseId: openHouseId)
            let tempURL = FileManager.default.temporaryDirectory
                .appendingPathComponent("open_house_attendees_\(openHouseId).csv")
            try csvContent.write(to: tempURL, atomically: true, encoding: .utf8)
            exportFileURL = tempURL
            showShareSheet = true
        } catch {
            errorMessage = "Export failed: \(error.localizedDescription)"
        }
    }
}

// MARK: - Share Sheet

// Note: Using ShareSheet from PropertyDetailView.swift to avoid duplicate definition

// MARK: - Attendee Detail View

struct AttendeeDetailView: View {
    let attendee: OpenHouseAttendee
    let openHouseId: Int
    let onUpdate: (OpenHouseAttendee) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var interestLevel: InterestLevel
    @State private var notes: String
    @State private var isSaving = false
    @State private var showError = false
    @State private var errorMessage = ""
    @State private var hasChanges = false

    init(attendee: OpenHouseAttendee, openHouseId: Int, onUpdate: @escaping (OpenHouseAttendee) -> Void) {
        self.attendee = attendee
        self.openHouseId = openHouseId
        self.onUpdate = onUpdate
        _interestLevel = State(initialValue: attendee.interestLevel)
        _notes = State(initialValue: attendee.agentNotes ?? "")
    }

    var body: some View {
        Form {
            AttendeeContactSection(attendee: attendee)
            AttendeeContactActionsSection(attendee: attendee)
            AttendeeVisitorDetailsSection(attendee: attendee)

            // Priority Score Section (v6.70.0)
            if attendee.priorityScore > 0 {
                Section("Lead Score") {
                    HStack {
                        let tier = attendee.priorityTier
                        Image(systemName: tier == .hot ? "flame.fill" : tier == .warm ? "thermometer.medium" : "thermometer.snowflake")
                            .foregroundStyle(tier == .hot ? .red : tier == .warm ? .orange : .blue)
                        Text("\(attendee.priorityScore) points")
                        Spacer()
                        Text(tier.rawValue.capitalized)
                            .fontWeight(.semibold)
                            .foregroundStyle(tier == .hot ? .red : tier == .warm ? .orange : .blue)
                    }
                }
            }

            // Agent Assessment Section (Editable)
            Section("Agent Assessment") {
                Picker("Interest Level", selection: $interestLevel) {
                    ForEach(InterestLevel.allCases, id: \.self) { level in
                        HStack {
                            Circle()
                                .fill(interestLevelColor(level))
                                .frame(width: 8, height: 8)
                            Text(level.displayName)
                        }
                        .tag(level)
                    }
                }
                .onChange(of: interestLevel) { _ in hasChanges = true }

                VStack(alignment: .leading, spacing: 8) {
                    Text("Notes")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    TextField("Add notes about this visitor...", text: $notes, axis: .vertical)
                        .lineLimit(3...6)
                        .textFieldStyle(.roundedBorder)
                        .onChange(of: notes) { _ in hasChanges = true }
                }
            }

            AttendeeConsentSection(attendee: attendee)

            // Signed In At
            Section {
                LabeledContent("Signed In", value: formattedSignInDate)
            }
        }
        .navigationTitle("Attendee Details")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Close") {
                    dismiss()
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button("Save") {
                    Task { await saveChanges() }
                }
                .disabled(!hasChanges || isSaving)
                .fontWeight(hasChanges ? .semibold : .regular)
            }
        }
        .alert("Error", isPresented: $showError) {
            Button("OK", role: .cancel) { }
        } message: {
            Text(errorMessage)
        }
        .overlay {
            if isSaving {
                Color.black.opacity(0.3)
                    .ignoresSafeArea()
                ProgressView("Saving...")
                    .padding()
                    .background(Color(.systemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
    }

    private var formattedSignInDate: String {
        let formatter = DateFormatter()
        formatter.dateStyle = .medium
        formatter.timeStyle = .short
        return formatter.string(from: attendee.signedInAt)
    }

    private func interestLevelColor(_ level: InterestLevel) -> Color {
        switch level {
        case .veryInterested: return .green
        case .somewhat: return .orange
        case .notInterested: return .red
        case .unknown: return .gray
        }
    }

    private func saveChanges() async {
        guard let attendeeId = attendee.id else {
            errorMessage = "Cannot save: Attendee not synced to server yet"
            showError = true
            return
        }

        isSaving = true
        defer { isSaving = false }

        do {
            let updatedAttendee = try await OpenHouseService.shared.updateAttendee(
                openHouseId: openHouseId,
                attendeeId: attendeeId,
                interestLevel: interestLevel,
                notes: notes.isEmpty ? nil : notes
            )

            // Create updated local copy with new values
            var updated = attendee
            updated.interestLevel = interestLevel
            updated.agentNotes = notes.isEmpty ? nil : notes

            onUpdate(updated)
            hasChanges = false
            dismiss()
        } catch {
            errorMessage = error.localizedDescription
            showError = true
        }
    }
}

// MARK: - Attendee Detail Extracted Sections (v6.76.1 - ViewBuilder complexity fix)

private struct AttendeeContactSection: View {
    let attendee: OpenHouseAttendee

    var body: some View {
        Section("Contact Information") {
            LabeledContent("Name", value: "\(attendee.firstName) \(attendee.lastName)")

            HStack {
                Text("Email")
                Spacer()
                Text(attendee.email)
                    .foregroundStyle(.secondary)
            }

            HStack {
                Text("Phone")
                Spacer()
                Text(attendee.phone)
                    .foregroundStyle(.secondary)
            }
        }
    }
}

private struct AttendeeContactActionsSection: View {
    let attendee: OpenHouseAttendee

    var body: some View {
        Section {
            HStack(spacing: 12) {
                Button {
                    if let url = URL(string: "tel:\(attendee.phone.replacingOccurrences(of: " ", with: "").replacingOccurrences(of: "-", with: ""))") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    VStack(spacing: 4) {
                        Image(systemName: "phone.fill")
                            .font(.title2)
                        Text("Call")
                            .font(.caption)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }
                .buttonStyle(.plain)
                .foregroundStyle(AppColors.brandTeal)

                Button {
                    if let url = URL(string: "sms:\(attendee.phone.replacingOccurrences(of: " ", with: "").replacingOccurrences(of: "-", with: ""))") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    VStack(spacing: 4) {
                        Image(systemName: "message.fill")
                            .font(.title2)
                        Text("Text")
                            .font(.caption)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }
                .buttonStyle(.plain)
                .foregroundStyle(AppColors.brandTeal)

                Button {
                    if let url = URL(string: "mailto:\(attendee.email)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    VStack(spacing: 4) {
                        Image(systemName: "envelope.fill")
                            .font(.title2)
                        Text("Email")
                            .font(.caption)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }
                .buttonStyle(.plain)
                .foregroundStyle(AppColors.brandTeal)
            }
            .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
        }
    }
}

private struct AttendeeVisitorDetailsSection: View {
    let attendee: OpenHouseAttendee

    var body: some View {
        if attendee.isAgent {
            Section {
                HStack {
                    Image(systemName: "briefcase.fill")
                        .foregroundStyle(.orange)
                    Text("Real Estate Agent")
                        .fontWeight(.semibold)
                }
            } header: {
                Text("Visitor Type")
            }

            Section("Agent Details") {
                if let brokerage = attendee.visitorAgentBrokerage {
                    LabeledContent("Brokerage", value: brokerage)
                }
                if let purpose = attendee.agentVisitPurpose {
                    LabeledContent("Visit Purpose", value: purpose.displayName)
                }
                if let hasBuyer = attendee.agentHasBuyer {
                    HStack {
                        Text("Has Interested Buyer")
                        Spacer()
                        if hasBuyer {
                            HStack {
                                Image(systemName: "star.fill")
                                    .foregroundStyle(.yellow)
                                Text("Yes")
                                    .foregroundStyle(.green)
                            }
                        } else {
                            Text("No")
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                if attendee.agentHasBuyer == true, let timeline = attendee.agentBuyerTimeline {
                    LabeledContent("Buyer Timeline", value: Self.agentBuyerTimelineDisplay(timeline))
                }
                if let networkInterest = attendee.agentNetworkInterest {
                    LabeledContent("Open to Networking", value: networkInterest ? "Yes" : "No")
                }
            }
        } else {
            Section("Buying Details") {
                LabeledContent("Timeline", value: attendee.buyingTimeline.displayName)
                LabeledContent("Working with Agent", value: attendee.workingWithAgent.displayName)
                LabeledContent("Pre-Approved", value: attendee.preApproved.displayName)

                if let lender = attendee.lenderName, !lender.isEmpty {
                    LabeledContent("Lender", value: lender)
                }

                if let howHeard = attendee.howHeardAbout {
                    LabeledContent("How Heard About", value: howHeard.displayName)
                }
            }

            // v6.76.1: Other Agent Details (for buyers working with an agent)
            if attendee.workingWithAgent == .yesOther {
                if let agentName = attendee.otherAgentName, !agentName.isEmpty {
                    Section("Their Agent") {
                        LabeledContent("Name", value: agentName)

                        if let brokerage = attendee.otherAgentBrokerage, !brokerage.isEmpty {
                            LabeledContent("Brokerage", value: brokerage)
                        }
                        if let phone = attendee.otherAgentPhone, !phone.isEmpty {
                            LabeledContent("Phone", value: phone)
                        }
                        if let email = attendee.otherAgentEmail, !email.isEmpty {
                            LabeledContent("Email", value: email)
                        }
                    }
                }
            }
        }
    }

    private static func agentBuyerTimelineDisplay(_ timeline: String) -> String {
        switch timeline {
        case "asap": return "ASAP"
        case "this_week": return "This Week"
        case "this_month": return "This Month"
        case "few_months": return "A Few Months"
        default: return "Not Sure"
        }
    }
}

private struct AttendeeConsentSection: View {
    let attendee: OpenHouseAttendee

    var body: some View {
        Section("Consent & Compliance") {
            HStack {
                Text("Follow-Up")
                Spacer()
                Image(systemName: attendee.consentToFollowUp ? "checkmark.circle.fill" : "xmark.circle")
                    .foregroundStyle(attendee.consentToFollowUp ? .green : .secondary)
                Text(attendee.consentToFollowUp ? "Yes" : "No")
                    .foregroundStyle(.secondary)
            }
            HStack {
                Text("Email")
                Spacer()
                Image(systemName: attendee.consentToEmail ? "checkmark.circle.fill" : "xmark.circle")
                    .foregroundStyle(attendee.consentToEmail ? .green : .secondary)
                Text(attendee.consentToEmail ? "Yes" : "No")
                    .foregroundStyle(.secondary)
            }
            HStack {
                Text("Text")
                Spacer()
                Image(systemName: attendee.consentToText ? "checkmark.circle.fill" : "xmark.circle")
                    .foregroundStyle(attendee.consentToText ? .green : .secondary)
                Text(attendee.consentToText ? "Yes" : "No")
                    .foregroundStyle(.secondary)
            }
            HStack {
                Text("MA Disclosure")
                Spacer()
                Image(systemName: attendee.maDisclosureAcknowledged ? "checkmark.shield.fill" : "xmark.shield")
                    .foregroundStyle(attendee.maDisclosureAcknowledged ? .green : .secondary)
                Text(attendee.maDisclosureAcknowledged ? "Acknowledged" : "Not Acknowledged")
                    .foregroundStyle(.secondary)
            }
        }
    }
}

// MARK: - Attendee Row (v6.70.0 - Agent Badge)

struct AttendeeRow: View {
    let attendee: OpenHouseAttendee

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("\(attendee.firstName) \(attendee.lastName)")
                    .font(.headline)

                // v6.70.0: Agent badge
                if attendee.isAgent {
                    HStack(spacing: 4) {
                        Image(systemName: "briefcase.fill")
                            .font(.system(size: 10))
                        Text("AGENT")
                            .font(.caption2)
                            .fontWeight(.bold)
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(Color.orange)
                    .clipShape(Capsule())
                }

                // v6.70.0: Star for agent with buyer
                if attendee.isAgent && attendee.agentHasBuyer == true {
                    Image(systemName: "star.fill")
                        .font(.caption)
                        .foregroundStyle(.yellow)
                }

                Spacer()

                // Show timeline for buyers, purpose for agents
                if attendee.isAgent {
                    if let purpose = attendee.agentVisitPurpose {
                        Text(purpose.displayName)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Color.orange.opacity(0.1))
                            .clipShape(Capsule())
                    }
                } else {
                    Text(attendee.buyingTimeline.displayName)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color.gray.opacity(0.1))
                        .clipShape(Capsule())
                }
            }

            HStack(spacing: 16) {
                Label(attendee.email, systemImage: "envelope")
                Label(attendee.phone, systemImage: "phone")
            }
            .font(.caption)
            .foregroundStyle(.secondary)

            // v6.70.0: Agent-specific info
            if attendee.isAgent {
                if let brokerage = attendee.visitorAgentBrokerage {
                    Text(brokerage)
                        .font(.caption)
                        .foregroundStyle(.orange)
                }
                if attendee.agentHasBuyer == true {
                    HStack {
                        Image(systemName: "person.badge.clock.fill")
                        Text("Has interested buyer")
                        if let timeline = attendee.agentBuyerTimeline {
                            Text("• \(agentBuyerTimelineDisplay(timeline))")
                        }
                    }
                    .font(.caption)
                    .foregroundStyle(.green)
                }
            } else {
                // Buyer-specific info
                if attendee.workingWithAgent != .no {
                    Text("Working with agent: \(attendee.workingWithAgent.displayName)")
                        .font(.caption)
                        .foregroundStyle(.orange)
                }
            }

            // Priority indicator
            if attendee.priorityScore >= 80 {
                HStack {
                    Image(systemName: "flame.fill")
                    Text("Hot Lead")
                }
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.red)
            } else if attendee.priorityScore >= 50 {
                HStack {
                    Image(systemName: "thermometer.medium")
                    Text("Warm Lead")
                }
                .font(.caption)
                .foregroundStyle(.orange)
            }
        }
        .padding(.vertical, 4)
    }

    private func agentBuyerTimelineDisplay(_ timeline: String) -> String {
        switch timeline {
        case "asap": return "ASAP"
        case "this_week": return "This week"
        case "this_month": return "This month"
        case "few_months": return "Few months"
        default: return "Not sure"
        }
    }
}

#Preview {
    NavigationStack {
        OpenHouseListView()
    }
}
