//
//  MyClientsView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Phase 5: Agent-Client Collaboration System
//

import SwiftUI

// MARK: - My Clients View

struct MyClientsView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var clients: [AgentClient] = []
    @State private var metrics: AgentMetrics?
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var selectedClient: AgentClient?
    @State private var showCreateClient = false
    @State private var analyticsClient: AgentClient?

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    loadingView
                } else if let error = errorMessage {
                    errorView(error)
                } else if clients.isEmpty {
                    emptyStateView
                } else {
                    clientListView
                }
            }
            .navigationTitle("My Clients")
            .navigationBarTitleDisplayMode(.large)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Done") {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        showCreateClient = true
                    } label: {
                        Image(systemName: "plus")
                    }
                }
            }
            .refreshable {
                await loadClients(forceRefresh: true)
            }
            .task {
                await loadClients()
            }
            .sheet(item: $selectedClient) { client in
                ClientDetailView(client: client, dismissSheet: {
                    selectedClient = nil
                })
            }
            .sheet(isPresented: $showCreateClient) {
                CreateClientView { newClient in
                    clients.insert(newClient, at: 0)
                    showCreateClient = false
                }
            }
            .sheet(item: $analyticsClient) { client in
                ClientAnalyticsView(client: client)
            }
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading clients...")
                .foregroundStyle(.secondary)
        }
    }

    private func errorView(_ error: String) -> some View {
        VStack(spacing: 20) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 50))
                .foregroundStyle(.orange)

            Text("Unable to Load Clients")
                .font(.title2)
                .fontWeight(.semibold)

            Text(error)
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button("Try Again") {
                Task {
                    await loadClients(forceRefresh: true)
                }
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
        }
    }

    private var emptyStateView: some View {
        VStack(spacing: 20) {
            Image(systemName: "person.2.slash")
                .font(.system(size: 60))
                .foregroundStyle(.secondary)

            Text("No Clients Yet")
                .font(.title2)
                .fontWeight(.semibold)

            Text("You don't have any clients yet. Add your first client to manage their saved searches and track their property interests.")
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button {
                showCreateClient = true
            } label: {
                Label("Add Your First Client", systemImage: "plus")
                    .fontWeight(.semibold)
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
            .padding(.top, 8)
        }
    }

    private var clientListView: some View {
        List {
            // Metrics summary
            if let metrics = metrics {
                Section {
                    HStack(spacing: 16) {
                        MetricCard(title: "Total", value: "\(metrics.totalClients)", icon: "person.2.fill", color: .blue)
                        MetricCard(title: "Active", value: "\(metrics.activeClients)", icon: "person.fill.checkmark", color: .green)
                        MetricCard(title: "Searches", value: "\(metrics.totalSearches)", icon: "bookmark.fill", color: .purple)
                    }
                    .padding(.vertical, 4)
                }
                .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
            }

            // Client list
            Section("Clients") {
                ForEach(clients) { client in
                    ClientRow(client: client, onAnalyticsTap: {
                        analyticsClient = client
                    })
                    .contentShape(Rectangle())
                    .onTapGesture {
                        selectedClient = client
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
    }

    private func loadClients(forceRefresh: Bool = false) async {
        isLoading = true
        errorMessage = nil

        // Fetch clients - treat any error as "no clients" to show friendly empty state
        // User can pull-to-refresh to retry if it was a network error
        do {
            clients = try await AgentService.shared.fetchAgentClients(forceRefresh: forceRefresh)
        } catch {
            // Log the error for debugging but show empty state instead of error
            print("MyClientsView: fetchAgentClients error: \(error)")
            clients = []
            // Don't set errorMessage - let empty state show instead
        }

        // Fetch metrics separately - don't let metrics failure block showing clients
        do {
            metrics = try await AgentService.shared.fetchAgentMetrics(forceRefresh: forceRefresh)
        } catch {
            // Metrics are optional, just set default values
            metrics = nil
        }

        isLoading = false
    }
}

// MARK: - Metric Card

private struct MetricCard: View {
    let title: String
    let value: String
    let icon: String
    let color: Color

    var body: some View {
        VStack(spacing: 4) {
            Image(systemName: icon)
                .font(.title2)
                .foregroundStyle(color)

            Text(value)
                .font(.title2)
                .fontWeight(.bold)

            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(color.opacity(0.1))
        .cornerRadius(12)
    }
}

// MARK: - Client Row

private struct ClientRow: View {
    let client: AgentClient
    var onAnalyticsTap: (() -> Void)?

    var body: some View {
        HStack(spacing: 12) {
            // Avatar with engagement indicator
            ZStack(alignment: .bottomTrailing) {
                Circle()
                    .fill(AppColors.brandTeal.opacity(0.2))
                    .frame(width: 44, height: 44)
                    .overlay {
                        Text(client.initials)
                            .font(.headline)
                            .foregroundStyle(AppColors.brandTeal)
                    }

                // Engagement indicator dot (based on recent activity)
                if let lastActivity = client.lastActivity {
                    let daysSince = Calendar.current.dateComponents([.day], from: lastActivity, to: Date()).day ?? 999
                    Circle()
                        .fill(engagementColor(daysSince: daysSince))
                        .frame(width: 10, height: 10)
                        .overlay(
                            Circle()
                                .stroke(Color(.systemBackground), lineWidth: 2)
                        )
                }
            }

            // Info
            VStack(alignment: .leading, spacing: 2) {
                Text(client.displayName)
                    .font(.headline)

                Text(client.email)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                // Activity stats
                HStack(spacing: 12) {
                    if client.searchesCount > 0 {
                        Label("\(client.searchesCount)", systemImage: "bookmark.fill")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    if client.favoritesCount > 0 {
                        Label("\(client.favoritesCount)", systemImage: "heart.fill")
                            .font(.caption)
                            .foregroundStyle(.red.opacity(0.7))
                    }
                    if client.hiddenCount > 0 {
                        Label("\(client.hiddenCount)", systemImage: "eye.slash.fill")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                .padding(.top, 2)
            }

            Spacer()

            // Analytics button
            Button {
                onAnalyticsTap?()
            } label: {
                Image(systemName: "chart.bar.fill")
                    .font(.system(size: 16))
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 32, height: 32)
                    .background(AppColors.brandTeal.opacity(0.1))
                    .clipShape(Circle())
            }
            .buttonStyle(.plain)

            Image(systemName: "chevron.right")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(.vertical, 4)
    }

    /// Returns color based on days since last activity
    private func engagementColor(daysSince: Int) -> Color {
        switch daysSince {
        case 0...3: return .green      // Very active
        case 4...7: return .blue       // Active
        case 8...14: return .yellow    // Moderate
        case 15...30: return .orange   // Low
        default: return .gray          // Inactive
        }
    }
}

// MARK: - Client Detail View

struct ClientDetailView: View {
    @Environment(\.dismiss) private var dismiss
    let client: AgentClient
    var dismissSheet: (() -> Void)? = nil

    @State private var searches: [ClientSavedSearch] = []
    @State private var favorites: [ClientFavorite] = []
    @State private var hidden: [ClientHiddenProperty] = []
    @State private var isLoading = true

    var body: some View {
        NavigationStack {
            List {
                // Client info header
                Section {
                    VStack(alignment: .center, spacing: 12) {
                        Circle()
                            .fill(AppColors.brandTeal.opacity(0.2))
                            .frame(width: 80, height: 80)
                            .overlay {
                                Text(client.initials)
                                    .font(.largeTitle)
                                    .foregroundStyle(AppColors.brandTeal)
                            }

                        Text(client.displayName)
                            .font(.title2)
                            .fontWeight(.bold)

                        Text(client.email)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)

                        if let phone = client.phone {
                            Text(phone)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 8)
                }
                .listRowBackground(Color.clear)

                // Contact actions
                Section {
                    if let phoneLink = client.formattedPhoneLink, let url = URL(string: "tel:\(phoneLink)") {
                        Link(destination: url) {
                            Label("Call", systemImage: "phone.fill")
                        }
                    }

                    if let url = URL(string: "mailto:\(client.email)") {
                        Link(destination: url) {
                            Label("Email", systemImage: "envelope.fill")
                        }
                    }
                }

                // Saved searches
                if isLoading {
                    Section("Saved Searches") {
                        HStack {
                            ProgressView()
                                .padding(.trailing, 8)
                            Text("Loading...")
                                .foregroundStyle(.secondary)
                        }
                    }
                } else {
                    Section("Saved Searches (\(searches.count))") {
                        if searches.isEmpty {
                            Text("No saved searches")
                                .foregroundStyle(.secondary)
                                .italic()
                        } else {
                            ForEach(searches) { search in
                                NavigationLink(destination: ClientSavedSearchDetailView(search: search, clientName: client.displayName, dismissSheet: dismissSheet)) {
                                    HStack {
                                        VStack(alignment: .leading, spacing: 4) {
                                            Text(search.name)
                                                .font(.headline)
                                            Text(search.filterSummary)
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                            if let count = search.lastMatchedCount {
                                                Text("\(count) matching properties")
                                                    .font(.caption)
                                                    .foregroundStyle(AppColors.brandTeal)
                                            }
                                        }
                                        Spacer()
                                        Image(systemName: "chevron.right")
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                                .buttonStyle(.plain)
                                .padding(.vertical, 2)
                            }
                        }
                    }

                    // Favorites
                    Section("Favorites (\(favorites.count))") {
                        if favorites.isEmpty {
                            Text("No favorited properties")
                                .foregroundStyle(.secondary)
                                .italic()
                        } else {
                            ForEach(favorites) { favorite in
                                NavigationLink(destination: PropertyDetailView(propertyId: favorite.listingKey)) {
                                    ClientPropertyCard(
                                        photoUrl: favorite.photoURL,
                                        address: favorite.address ?? "Unknown Address",
                                        city: favorite.city,
                                        price: favorite.formattedPrice,
                                        beds: favorite.beds,
                                        baths: favorite.baths,
                                        isHidden: false
                                    )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }

                    // Hidden properties
                    if !hidden.isEmpty {
                        Section("Hidden Properties (\(hidden.count))") {
                            ForEach(hidden) { prop in
                                NavigationLink(destination: PropertyDetailView(propertyId: prop.listingKey)) {
                                    ClientPropertyCard(
                                        photoUrl: prop.photoURL,
                                        address: prop.address ?? "Unknown Address",
                                        city: prop.city,
                                        price: prop.formattedPrice,
                                        beds: prop.beds,
                                        baths: prop.baths,
                                        isHidden: true
                                    )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("Client Details")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
            .task {
                await loadClientData()
            }
        }
    }

    private func loadClientData() async {
        isLoading = true

        do {
            async let searchesRequest = AgentService.shared.fetchClientSearches(clientId: client.id)
            async let favoritesRequest = AgentService.shared.fetchClientFavorites(clientId: client.id)
            async let hiddenRequest = AgentService.shared.fetchClientHidden(clientId: client.id)

            let (s, f, h) = try await (searchesRequest, favoritesRequest, hiddenRequest)
            searches = s
            favorites = f
            hidden = h
        } catch {
            // Silently fail - sections will show empty
        }

        isLoading = false
    }
}

// MARK: - Create Client View

struct CreateClientView: View {
    @Environment(\.dismiss) private var dismiss
    let onSuccess: (AgentClient) -> Void

    @State private var email = ""
    @State private var firstName = ""
    @State private var lastName = ""
    @State private var phone = ""
    @State private var sendNotification = true
    @State private var isSubmitting = false
    @State private var errorMessage: String?

    private var isValid: Bool {
        !email.isEmpty && email.contains("@") && !firstName.isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Email", text: $email)
                        .keyboardType(.emailAddress)
                        .textContentType(.emailAddress)
                        .autocapitalization(.none)

                    TextField("First Name", text: $firstName)
                        .textContentType(.givenName)

                    TextField("Last Name", text: $lastName)
                        .textContentType(.familyName)

                    TextField("Phone (optional)", text: $phone)
                        .keyboardType(.phonePad)
                        .textContentType(.telephoneNumber)
                } header: {
                    Text("Client Information")
                } footer: {
                    Text("Enter your client's information. They will receive an email invitation to create an account if you enable notifications.")
                }

                Section {
                    Toggle("Send Welcome Email", isOn: $sendNotification)
                } footer: {
                    Text("Send an email inviting the client to set up their account and start their property search.")
                }

                if let error = errorMessage {
                    Section {
                        Text(error)
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle("Add Client")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Add") {
                        Task {
                            await createClient()
                        }
                    }
                    .disabled(!isValid || isSubmitting)
                    .fontWeight(.semibold)
                }
            }
            .disabled(isSubmitting)
            .overlay {
                if isSubmitting {
                    ProgressView("Adding client...")
                        .padding()
                        .background(.ultraThinMaterial)
                        .cornerRadius(12)
                }
            }
        }
    }

    private func createClient() async {
        isSubmitting = true
        errorMessage = nil

        do {
            let newClient = try await AgentService.shared.createClient(
                email: email.trimmingCharacters(in: .whitespaces),
                firstName: firstName.trimmingCharacters(in: .whitespaces),
                lastName: lastName.isEmpty ? nil : lastName.trimmingCharacters(in: .whitespaces),
                phone: phone.isEmpty ? nil : phone.trimmingCharacters(in: .whitespaces),
                sendNotification: sendNotification
            )
            onSuccess(newClient)
        } catch {
            errorMessage = error.userFriendlyMessage
        }

        isSubmitting = false
    }
}

// MARK: - Client Saved Search Detail View

struct ClientSavedSearchDetailView: View {
    let search: ClientSavedSearch
    let clientName: String
    var dismissSheet: (() -> Void)? = nil

    @EnvironmentObject var viewModel: PropertySearchViewModel

    var body: some View {
        List {
            // Search info header
            Section {
                VStack(alignment: .center, spacing: 12) {
                    Image(systemName: "bookmark.fill")
                        .font(.system(size: 40))
                        .foregroundStyle(AppColors.brandTeal)

                    Text(search.name)
                        .font(.title2)
                        .fontWeight(.bold)
                        .multilineTextAlignment(.center)

                    Text("Saved by \(clientName)")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
            }
            .listRowBackground(Color.clear)

            // Filter details
            Section("Search Filters") {
                Text(search.filterSummary)
                    .font(.body)

                if let frequency = search.notificationFrequency {
                    HStack {
                        Image(systemName: "bell.fill")
                            .foregroundStyle(.secondary)
                        Text("Notifications: \(frequency.capitalized)")
                    }
                    .font(.subheadline)
                }

                HStack {
                    Image(systemName: search.isActive ? "checkmark.circle.fill" : "xmark.circle.fill")
                        .foregroundStyle(search.isActive ? .green : .secondary)
                    Text(search.isActive ? "Active" : "Inactive")
                }
                .font(.subheadline)
            }

            // Matching properties
            if let count = search.lastMatchedCount {
                Section {
                    HStack {
                        Image(systemName: "house.fill")
                            .foregroundStyle(AppColors.brandTeal)
                        Text("\(count) matching properties")
                            .fontWeight(.medium)
                    }
                }
            }

            // View results button
            Section {
                Button {
                    applySearchAndNavigate()
                } label: {
                    HStack {
                        Spacer()
                        Label("View Matching Properties", systemImage: "magnifyingglass")
                            .fontWeight(.semibold)
                        Spacer()
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(AppColors.brandTeal)
                .listRowBackground(Color.clear)
            }

            // Created date
            if let createdAt = search.createdAt {
                Section {
                    HStack {
                        Text("Created")
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(createdAt, style: .date)
                    }
                    .font(.subheadline)
                }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle("Saved Search")
        .navigationBarTitleDisplayMode(.inline)
    }

    private func applySearchAndNavigate() {
        // Apply filters from the saved search
        if let filters = search.filters {
            viewModel.filters = PropertySearchFilters(fromServerJSON: filters)
        }

        // Trigger search
        Task {
            await viewModel.search()
        }

        // Dismiss all sheets and switch to search tab
        dismissSheet?()
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
            NotificationCenter.default.post(name: .dismissMyClientsSheet, object: nil)
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.4) {
            NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
        }
    }
}

extension Notification.Name {
    static let dismissMyClientsSheet = Notification.Name("dismissMyClientsSheet")
}

// MARK: - Client Property Card

private struct ClientPropertyCard: View {
    let photoUrl: URL?
    let address: String
    let city: String?
    let price: String
    let beds: Int?
    let baths: Double?
    let isHidden: Bool

    var body: some View {
        HStack(spacing: 12) {
            // Property image
            ZStack(alignment: .topLeading) {
                AsyncImage(url: photoUrl) { phase in
                    switch phase {
                    case .empty:
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                            .overlay {
                                ProgressView()
                            }
                    case .success(let image):
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    case .failure:
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                            .overlay {
                                Image(systemName: "photo")
                                    .font(.title2)
                                    .foregroundStyle(.secondary)
                            }
                    @unknown default:
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                    }
                }
                .frame(width: 80, height: 60)
                .clipShape(RoundedRectangle(cornerRadius: 8))
                .opacity(isHidden ? 0.6 : 1.0)

                // Hidden badge
                if isHidden {
                    Text("Hidden")
                        .font(.system(size: 8, weight: .bold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 4)
                        .padding(.vertical, 2)
                        .background(Color.red.opacity(0.9))
                        .clipShape(RoundedRectangle(cornerRadius: 4))
                        .offset(x: 4, y: 4)
                }
            }

            // Property details
            VStack(alignment: .leading, spacing: 4) {
                Text(address)
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .lineLimit(1)

                if let city = city {
                    Text(city)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                HStack(spacing: 8) {
                    if !price.isEmpty {
                        Text(price)
                            .font(.subheadline)
                            .fontWeight(.semibold)
                            .foregroundStyle(AppColors.brandTeal)
                    }

                    if let beds = beds {
                        HStack(spacing: 2) {
                            Image(systemName: "bed.double.fill")
                                .font(.system(size: 10))
                            Text("\(beds)")
                                .font(.caption)
                        }
                        .foregroundStyle(.secondary)
                    }

                    if let baths = baths {
                        HStack(spacing: 2) {
                            Image(systemName: "shower.fill")
                                .font(.system(size: 10))
                            Text(baths.truncatingRemainder(dividingBy: 1) == 0 ? "\(Int(baths))" : String(format: "%.1f", baths))
                                .font(.caption)
                        }
                        .foregroundStyle(.secondary)
                    }
                }
            }

            Spacer()

            Image(systemName: "chevron.right")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(.vertical, 4)
    }
}

#Preview {
    MyClientsView()
}
