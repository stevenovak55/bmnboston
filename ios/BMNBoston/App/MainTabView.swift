//
//  MainTabView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @StateObject private var notificationStore = NotificationStore.shared
    @State private var selectedTab = 0

    var body: some View {
        TabView(selection: $selectedTab) {
            // Tab 0: Search (always shown)
            PropertySearchView()
                .tabItem {
                    Label("Search", systemImage: "magnifyingglass")
                }
                .tag(0)

            // Tab 1: Appointments (always shown)
            AppointmentsView()
                .tabItem {
                    Label("Appointments", systemImage: "calendar")
                }
                .tag(1)

            // Tab 2: Dynamic tab based on user type
            if let user = authViewModel.currentUser {
                if user.isAgent {
                    // Agents see "My Clients" tab
                    MyClientsTabView()
                        .tabItem {
                            Label("My Clients", systemImage: "person.2.fill")
                        }
                        .tag(2)
                } else {
                    // Clients see "My Agent" tab
                    MyAgentTabView()
                        .tabItem {
                            Label("My Agent", systemImage: "person.badge.shield.checkmark.fill")
                        }
                        .tag(2)
                }
            }

            // Tab 3: Notifications (authenticated users only)
            if authViewModel.isAuthenticated {
                NotificationCenterView(isSheet: false)
                    .environmentObject(notificationStore)
                    .tabItem {
                        Label("Notifications", systemImage: "bell.fill")
                    }
                    .badge(notificationStore.unreadCount > 0
                        ? (notificationStore.unreadCount > 99 ? "99+" : "\(notificationStore.unreadCount)")
                        : nil)
                    .tag(3)
            }

            // Tab 4 (or 2 for guests): Profile or Login
            if authViewModel.isAuthenticated {
                ProfileView()
                    .tabItem {
                        Label("Profile", systemImage: "person.fill")
                    }
                    .tag(4)
            } else {
                // Non-authenticated users see Login/Register
                LoginPromptTabView()
                    .tabItem {
                        Label("Login", systemImage: "person.badge.key.fill")
                    }
                    .tag(2)
            }
        }
        .tint(AppColors.brandTeal)
        .onReceive(NotificationCenter.default.publisher(for: .switchToSearchTab)) { _ in
            selectedTab = 0
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToAppointmentsTab)) { _ in
            selectedTab = 1
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToProfileTab)) { _ in
            selectedTab = authViewModel.isAuthenticated ? 4 : 2
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToNotificationsTab)) { _ in
            if authViewModel.isAuthenticated {
                HapticManager.impact(.light)
                selectedTab = 3
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToMyAgentTab)) { _ in
            if let currentUser = authViewModel.currentUser, !currentUser.isAgent {
                selectedTab = 2
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .switchToMyClientsTab)) { _ in
            if authViewModel.currentUser?.isAgent == true {
                selectedTab = 2
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .showSavedSearchesList)) { _ in
            if authViewModel.isAuthenticated {
                selectedTab = 4  // Profile tab
                // Post notification for ProfileView to open saved searches sheet
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                    NotificationCenter.default.post(name: .openSavedSearchesSheet, object: nil)
                }
            }
        }
    }
}

// MARK: - Login Prompt Tab View (for non-authenticated users)

struct LoginPromptTabView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @State private var showingRegister = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                Spacer()

                Image("Logo")
                    .resizable()
                    .aspectRatio(contentMode: .fit)
                    .frame(maxWidth: 240)

                VStack(spacing: 8) {
                    Text("Sign in to access more features")
                        .font(.title3)
                        .fontWeight(.semibold)

                    Text("Save searches, favorite properties, schedule appointments, and more.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                        .padding(.horizontal, 32)
                }

                VStack(spacing: 12) {
                    Button {
                        authViewModel.isGuestMode = false
                    } label: {
                        Text("Sign In")
                            .fontWeight(.semibold)
                            .frame(maxWidth: .infinity)
                            .padding()
                            .background(AppColors.brandTeal)
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .accessibilityLabel("Sign In")
                    .accessibilityHint("Double tap to sign in to your account")

                    Button {
                        showingRegister = true
                    } label: {
                        Text("Create Account")
                            .fontWeight(.medium)
                            .frame(maxWidth: .infinity)
                            .padding()
                            .background(Color(.secondarySystemBackground))
                            .foregroundStyle(AppColors.brandTeal)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .accessibilityLabel("Create Account")
                    .accessibilityHint("Double tap to create a new account")
                }
                .padding(.horizontal, 24)
                .padding(.top, 16)

                Spacer()
                Spacer()
            }
            .navigationTitle("Account")
            .sheet(isPresented: $showingRegister) {
                RegisterView()
                    .environmentObject(authViewModel)
            }
        }
    }
}

// MARK: - My Agent Tab View (for client users)

struct MyAgentTabView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @State private var myAgent: Agent?
    @State private var isLoadingAgent = true
    @State private var loadError: String?
    @State private var showBookAppointment = false
    @State private var sharedProperties: [SharedProperty] = []
    @State private var isLoadingShared = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 20) {
                    if isLoadingAgent {
                        ProgressView("Loading agent info...")
                            .padding(.top, 60)
                            .accessibilityLabel("Loading agent information")
                    } else if let agent = myAgent {
                        // Agent Card
                        MyAgentCard(agent: agent) {
                            showBookAppointment = true
                        }
                        .padding(.horizontal)
                        .padding(.top)

                        // Quick Contact Section
                        quickContactSection(agent: agent)

                        // Bio Section
                        if let bio = agent.bio, !bio.isEmpty {
                            bioSection(bio: bio)
                        }

                        // Shared Properties Section
                        sharedPropertiesSection

                    } else if authViewModel.currentUser?.hasAgent == true {
                        // User has agent but we couldn't load full details
                        if let agentSummary = authViewModel.currentUser?.assignedAgent {
                            VStack(spacing: 16) {
                                AgentSummaryCard(agent: agentSummary)
                                    .padding(.horizontal)

                                if let error = loadError {
                                    Text(error)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .padding(.top)
                        }
                    } else {
                        // No agent assigned
                        noAgentView
                    }
                }
                .padding(.bottom, 20)
            }
            .navigationTitle("My Agent")
            .refreshable {
                await loadData()
            }
            .task {
                await loadData()
            }
            .sheet(isPresented: $showBookAppointment) {
                BookAppointmentView(viewModel: AppointmentViewModel())
                    .environmentObject(authViewModel)
            }
        }
    }

    private func loadData() async {
        isLoadingAgent = true
        loadError = nil

        do {
            myAgent = try await AgentService.shared.fetchMyAgent()
        } catch {
            loadError = "Could not load agent details"
            myAgent = nil
        }
        isLoadingAgent = false

        // Load shared properties
        isLoadingShared = true
        do {
            sharedProperties = try await AgentService.shared.fetchSharedProperties()
        } catch {
            sharedProperties = []
        }
        isLoadingShared = false
    }

    private func quickContactSection(agent: Agent) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Contact")
                .font(.headline)
                .padding(.horizontal)

            VStack(spacing: 0) {
                // Phone row
                if let phone = agent.phone, let phoneLink = agent.formattedPhoneLink {
                    contactRow(
                        icon: "phone.fill",
                        iconColor: .green,
                        title: "Call",
                        subtitle: phone,
                        action: {
                            if let url = URL(string: "tel:\(phoneLink)") {
                                UIApplication.shared.open(url)
                            }
                        }
                    )
                    Divider().padding(.leading, 56)
                }

                // Text row
                if let phone = agent.phone, let phoneLink = agent.formattedPhoneLink {
                    contactRow(
                        icon: "message.fill",
                        iconColor: .green,
                        title: "Text",
                        subtitle: phone,
                        action: {
                            if let url = URL(string: "sms:\(phoneLink)") {
                                UIApplication.shared.open(url)
                            }
                        }
                    )
                    Divider().padding(.leading, 56)
                }

                // Email row
                contactRow(
                    icon: "envelope.fill",
                    iconColor: AppColors.brandTeal,
                    title: "Email",
                    subtitle: agent.email,
                    action: {
                        if let url = URL(string: "mailto:\(agent.email)") {
                            UIApplication.shared.open(url)
                        }
                    }
                )

                // Schedule row (if available)
                if agent.canBookShowings {
                    Divider().padding(.leading, 56)
                    contactRow(
                        icon: "calendar.badge.plus",
                        iconColor: .orange,
                        title: "Schedule Appointment",
                        subtitle: "Book a showing or consultation",
                        action: {
                            showBookAppointment = true
                        }
                    )
                }
            }
            .background(Color(.secondarySystemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .padding(.horizontal)
        }
    }

    private func contactRow(icon: String, iconColor: Color, title: String, subtitle: String, action: @escaping () -> Void) -> some View {
        Button(action: {
            HapticManager.impact(.light)
            action()
        }) {
            HStack(spacing: 16) {
                Image(systemName: icon)
                    .font(.title3)
                    .foregroundStyle(iconColor)
                    .frame(width: 24)
                    .accessibilityHidden(true)

                VStack(alignment: .leading, spacing: 2) {
                    Text(title)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(AppColors.textPrimary)
                    Text(subtitle)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Spacer()

                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .accessibilityHidden(true)
            }
            .padding()
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(title): \(subtitle)")
        .accessibilityHint("Double tap to \(title.lowercased())")
    }

    private func bioSection(bio: String) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("About")
                .font(.headline)
                .padding(.horizontal)

            Text(bio)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .padding()
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .padding(.horizontal)
        }
    }

    private var sharedPropertiesSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("From My Agent")
                    .font(.headline)

                Spacer()

                if !sharedProperties.isEmpty {
                    NavigationLink {
                        SharedPropertiesView()
                    } label: {
                        HStack(spacing: 4) {
                            Text("See All")
                                .font(.subheadline)
                            Image(systemName: "chevron.right")
                                .font(.caption)
                        }
                        .foregroundStyle(AppColors.brandTeal)
                    }
                }
            }
            .padding(.horizontal)

            if isLoadingShared {
                HStack {
                    Spacer()
                    ProgressView()
                    Spacer()
                }
                .padding()
            } else if sharedProperties.isEmpty {
                VStack(spacing: 8) {
                    Image(systemName: "house.and.flag")
                        .font(.largeTitle)
                        .foregroundStyle(.secondary)
                    Text("No shared properties yet")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text("Properties your agent shares with you will appear here")
                        .font(.caption)
                        .foregroundStyle(.tertiary)
                        .multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 24)
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .padding(.horizontal)
            } else {
                // Show first 3 shared properties
                VStack(spacing: 0) {
                    ForEach(sharedProperties.prefix(3)) { shared in
                        if let property = shared.property {
                            sharedPropertyRow(shared: shared, property: property)
                            if shared.id != sharedProperties.prefix(3).last?.id {
                                Divider().padding(.leading, 80)
                            }
                        }
                    }
                }
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .padding(.horizontal)
            }
        }
    }

    private func sharedPropertyRow(shared: SharedProperty, property: SharedPropertyData) -> some View {
        NavigationLink {
            // Navigate to property detail
            if let listingKey = shared.listingKey as String? {
                PropertyDetailFromKeyView(listingKey: listingKey)
            }
        } label: {
            HStack(spacing: 12) {
                // Property thumbnail
                if let photoUrl = property.photoUrl, let url = URL(string: photoUrl) {
                    AsyncImage(url: url) { image in
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    } placeholder: {
                        Rectangle()
                            .fill(Color(.systemGray5))
                    }
                    .frame(width: 60, height: 60)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                } else {
                    Rectangle()
                        .fill(Color(.systemGray5))
                        .frame(width: 60, height: 60)
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                        .overlay(
                            Image(systemName: "house.fill")
                                .foregroundStyle(.gray)
                        )
                }

                VStack(alignment: .leading, spacing: 4) {
                    if let price = property.listPrice {
                        Text("$\(price.formatted())")
                            .font(.subheadline)
                            .fontWeight(.semibold)
                            .foregroundStyle(AppColors.textPrimary)
                    }

                    Text(property.fullAddress)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)

                    if let note = shared.agentNote, !note.isEmpty {
                        HStack(spacing: 4) {
                            Image(systemName: "quote.bubble.fill")
                                .font(.caption2)
                            Text(note)
                                .lineLimit(1)
                        }
                        .font(.caption2)
                        .foregroundStyle(.orange)
                    }
                }

                Spacer()

                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            .padding()
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private var noAgentView: some View {
        VStack(spacing: 16) {
            Spacer()

            Image(systemName: "person.badge.shield.checkmark")
                .font(.system(size: 60))
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)

            Text("No Agent Assigned")
                .font(.title2)
                .fontWeight(.semibold)

            Text("You don't have an agent assigned yet. Contact us to get connected with one of our expert agents.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)

            Button {
                if let url = URL(string: "https://bmnboston.com/contact/") {
                    UIApplication.shared.open(url)
                }
            } label: {
                HStack {
                    Text("Contact Us")
                        .fontWeight(.medium)
                    Image(systemName: "arrow.up.right.square")
                        .font(.caption)
                }
                .padding()
                .background(AppColors.brandTeal)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .accessibilityLabel("Contact Us")
            .accessibilityHint("Double tap to open contact page in browser")
            .padding(.top, 8)

            Spacer()
            Spacer()
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("No agent assigned. Contact us to get connected with one of our expert agents.")
    }
}

// MARK: - Property Detail from Key View (helper)

struct PropertyDetailFromKeyView: View {
    let listingKey: String
    @State private var property: Property?
    @State private var isLoading = true
    @State private var error: String?

    var body: some View {
        Group {
            if isLoading {
                ProgressView("Loading property...")
            } else if let property = property {
                PropertyDetailView(propertyId: property.id)
            } else {
                VStack(spacing: 12) {
                    Image(systemName: "exclamationmark.triangle")
                        .font(.largeTitle)
                        .foregroundStyle(.secondary)
                    Text(error ?? "Could not load property")
                        .foregroundStyle(.secondary)
                }
            }
        }
        .task {
            do {
                property = try await APIClient.shared.request(.propertyDetail(id: listingKey))
            } catch {
                self.error = "Failed to load property"
            }
            isLoading = false
        }
    }
}

// MARK: - My Clients Tab View (for agent users)

struct MyClientsTabView: View {
    @EnvironmentObject var searchViewModel: PropertySearchViewModel

    var body: some View {
        MyClientsView()
            .environmentObject(searchViewModel)
    }
}

// Add notification name for tab switching
extension Notification.Name {
    static let switchToSearchTab = Notification.Name("switchToSearchTab")
    static let switchToMyAgentTab = Notification.Name("switchToMyAgentTab")
    static let switchToMyClientsTab = Notification.Name("switchToMyClientsTab")
    static let switchToNotificationsTab = Notification.Name("switchToNotificationsTab")
    static let openSavedSearchesSheet = Notification.Name("openSavedSearchesSheet")
}

// MARK: - Saved Searches Tab

struct SavedSearchesTabView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel

    @State private var showCreateSheet = false
    @State private var searchToEdit: SavedSearch?
    @State private var searchToDelete: SavedSearch?
    @State private var showDeleteConfirmation = false

    var body: some View {
        NavigationStack {
            Group {
                if viewModel.savedSearchSyncState == .loading && viewModel.savedSearches.isEmpty {
                    loadingView
                } else if viewModel.savedSearches.isEmpty {
                    emptyStateView
                } else {
                    searchListView
                }
            }
            .navigationTitle("Saved Searches")
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        showCreateSheet = true
                    } label: {
                        Image(systemName: "plus")
                    }
                }
            }
            .refreshable {
                await viewModel.loadSavedSearchesFromServer(forceRefresh: true)
            }
            .task {
                await viewModel.loadSavedSearchesFromServer()
            }
            .sheet(isPresented: $showCreateSheet) {
                CreateSavedSearchSheet()
                    .environmentObject(viewModel)
            }
            .sheet(item: $searchToEdit) { search in
                SavedSearchDetailView(search: search)
                    .environmentObject(viewModel)
            }
            .alert("Delete Search", isPresented: $showDeleteConfirmation) {
                Button("Cancel", role: .cancel) {}
                Button("Delete", role: .destructive) {
                    if let search = searchToDelete {
                        Task {
                            await viewModel.deleteSavedSearch(search)
                        }
                    }
                }
            } message: {
                Text("Are you sure you want to delete this saved search? This cannot be undone.")
            }
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading saved searches...")
                .foregroundColor(.secondary)
        }
    }

    private var emptyStateView: some View {
        VStack(spacing: 20) {
            Image(systemName: "bookmark.slash")
                .font(.system(size: 60))
                .foregroundColor(.secondary)

            Text("No Saved Searches")
                .font(.title2)
                .fontWeight(.semibold)

            Text("Save your searches to get notified when new listings match your criteria.")
                .font(.body)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button {
                showCreateSheet = true
            } label: {
                Label("Save Current Search", systemImage: "plus")
                    .fontWeight(.semibold)
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
            .padding(.top, 8)
        }
    }

    private var searchListView: some View {
        List {
            if case .error(let message) = viewModel.savedSearchSyncState {
                HStack {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .foregroundColor(.orange)
                    Text(message)
                        .font(.caption)
                    Spacer()
                    Button("Dismiss") {
                        viewModel.clearSavedSearchError()
                    }
                    .font(.caption)
                }
                .padding()
                .background(Color.orange.opacity(0.1))
                .cornerRadius(8)
            }

            ForEach(viewModel.savedSearches) { search in
                SavedSearchRow(search: search) {
                    viewModel.applySavedSearch(search)
                }
                .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                    Button(role: .destructive) {
                        searchToDelete = search
                        showDeleteConfirmation = true
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }

                    Button {
                        searchToEdit = search
                    } label: {
                        Label("Edit", systemImage: "pencil")
                    }
                    .tint(.blue)
                }
                .swipeActions(edge: .leading, allowsFullSwipe: true) {
                    Button {
                        Task {
                            await viewModel.toggleSavedSearchActive(search)
                        }
                    } label: {
                        Label(
                            search.isActive ? "Pause" : "Resume",
                            systemImage: search.isActive ? "pause.fill" : "play.fill"
                        )
                    }
                    .tint(search.isActive ? .orange : .green)
                }
            }
        }
        .listStyle(.insetGrouped)
    }
}

// AppointmentsView is now in Features/Appointments/Views/AppointmentsView.swift

struct ProfileView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @EnvironmentObject var appearanceManager: AppearanceManager
    @EnvironmentObject var searchViewModel: PropertySearchViewModel
    @EnvironmentObject var pushManager: PushNotificationManager
    @EnvironmentObject var notificationStore: NotificationStore

    @State private var showSavedSearches = false
    @State private var showSavedProperties = false
    @State private var showHiddenProperties = false
    @State private var showNotificationCenter = false
    @State private var showNotificationPreferences = false
    @State private var showDeleteAccountAlert = false
    @State private var showDeleteAccountConfirmation = false
    @State private var showAgentReferral = false
    @State private var showOpenHouse = false
    @State private var showExclusiveListings = false

    var body: some View {
        NavigationStack {
            List {
                // Account section
                if let user = authViewModel.currentUser {
                    Section("Account") {
                        HStack {
                            if let avatarUrlString = user.avatarUrl,
                               let avatarUrl = URL(string: avatarUrlString) {
                                AsyncImage(url: avatarUrl) { phase in
                                    switch phase {
                                    case .success(let image):
                                        image
                                            .resizable()
                                            .aspectRatio(contentMode: .fill)
                                            .frame(width: 44, height: 44)
                                            .clipShape(Circle())
                                    case .failure(_):
                                        Image(systemName: "person.circle.fill")
                                            .font(.system(size: 44))
                                            .foregroundStyle(AppColors.brandTeal)
                                    case .empty:
                                        ProgressView()
                                            .frame(width: 44, height: 44)
                                    @unknown default:
                                        Image(systemName: "person.circle.fill")
                                            .font(.system(size: 44))
                                            .foregroundStyle(AppColors.brandTeal)
                                    }
                                }
                            } else {
                                Image(systemName: "person.circle.fill")
                                    .font(.system(size: 44))
                                    .foregroundStyle(AppColors.brandTeal)
                            }
                            VStack(alignment: .leading, spacing: 2) {
                                Text(user.displayName)
                                    .font(.headline)
                                Text(user.email)
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.vertical, 4)

                        // Edit Profile button
                        Button {
                            if let url = URL(string: "https://bmnboston.com/my-dashboard/") {
                                UIApplication.shared.open(url)
                            }
                        } label: {
                            HStack {
                                Image(systemName: "pencil.circle.fill")
                                    .font(.title3)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)

                                Text("Edit Profile")
                                    .foregroundStyle(AppColors.textPrimary)

                                Spacer()

                                Image(systemName: "arrow.up.right.square")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .accessibilityHidden(true)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel("Edit Profile")
                        .accessibilityHint("Double tap to open profile editor in browser")
                    }
                }

                // Agent Tools section - Agent users only
                if let user = authViewModel.currentUser, user.isAgent {
                    Section("Agent Tools") {
                        // Exclusive Listings row
                        Button {
                            showExclusiveListings = true
                        } label: {
                            HStack {
                                Image(systemName: "house.fill")
                                    .font(.title3)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text("Exclusive Listings")
                                        .foregroundStyle(AppColors.textPrimary)
                                    Text("Manage your non-MLS listings")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                Spacer()

                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .accessibilityHidden(true)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityElement(children: .combine)
                        .accessibilityLabel("Exclusive Listings. Manage your non-MLS listings")
                        .accessibilityHint("Double tap to view and manage your exclusive listings")

                        // Referral Link row
                        Button {
                            showAgentReferral = true
                        } label: {
                            HStack {
                                Image(systemName: "link")
                                    .font(.title3)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text("Referral Link")
                                        .foregroundStyle(AppColors.textPrimary)
                                    Text("Share your link to get new clients")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                Spacer()

                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .accessibilityHidden(true)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityElement(children: .combine)
                        .accessibilityLabel("Referral Link. Share your link to get new clients")
                        .accessibilityHint("Double tap to view and share your referral link")

                        // Open House Sign-In row (v6.69.0)
                        Button {
                            showOpenHouse = true
                        } label: {
                            HStack(spacing: 12) {
                                Image(systemName: "person.crop.rectangle.badge.plus")
                                    .font(.title3)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text("Open House")
                                        .foregroundStyle(AppColors.textPrimary)
                                    Text("Manage sign-ins at your showings")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                        .accessibilityHidden(true)
                                }

                                Spacer()

                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .accessibilityHidden(true)
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityElement(children: .combine)
                        .accessibilityLabel("Open House. Manage sign-ins at your showings")
                        .accessibilityHint("Double tap to manage your open house events")
                    }
                }

                // Saved Searches section
                Section("Searches") {
                    Button {
                        showSavedSearches = true
                    } label: {
                        HStack {
                            Image(systemName: "bookmark.fill")
                                .font(.title3)
                                .foregroundStyle(AppColors.brandTeal)
                                .frame(width: 28)
                                .accessibilityHidden(true)

                            Text("Saved Searches")
                                .foregroundStyle(AppColors.textPrimary)

                            Spacer()

                            if !searchViewModel.savedSearches.isEmpty {
                                Text("\(searchViewModel.savedSearches.count)")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }

                            Image(systemName: "chevron.right")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .accessibilityHidden(true)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("Saved Searches\(searchViewModel.savedSearches.isEmpty ? "" : ", \(searchViewModel.savedSearches.count) saved")")
                    .accessibilityHint("Double tap to view your saved searches")

                    // Saved Properties row
                    Button {
                        showSavedProperties = true
                    } label: {
                        HStack {
                            Image(systemName: "heart.fill")
                                .font(.title3)
                                .foregroundStyle(.red)
                                .frame(width: 28)
                                .accessibilityHidden(true)

                            Text("Saved Properties")
                                .foregroundStyle(AppColors.textPrimary)

                            Spacer()

                            if !searchViewModel.favoriteProperties.isEmpty {
                                Text("\(searchViewModel.favoriteProperties.count)")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }

                            Image(systemName: "chevron.right")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .accessibilityHidden(true)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("Saved Properties\(searchViewModel.favoriteProperties.isEmpty ? "" : ", \(searchViewModel.favoriteProperties.count) saved")")
                    .accessibilityHint("Double tap to view your favorite properties")

                    // Hidden Properties row
                    Button {
                        showHiddenProperties = true
                    } label: {
                        HStack {
                            Image(systemName: "eye.slash.fill")
                                .font(.title3)
                                .foregroundStyle(.gray)
                                .frame(width: 28)
                                .accessibilityHidden(true)

                            Text("Hidden Properties")
                                .foregroundStyle(AppColors.textPrimary)

                            Spacer()

                            if !searchViewModel.hiddenProperties.isEmpty {
                                Text("\(searchViewModel.hiddenProperties.count)")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }

                            Image(systemName: "chevron.right")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .accessibilityHidden(true)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("Hidden Properties\(searchViewModel.hiddenProperties.isEmpty ? "" : ", \(searchViewModel.hiddenProperties.count) hidden")")
                    .accessibilityHint("Double tap to view properties you've hidden from search")
                }

                // Notifications section
                Section("Notifications") {
                    // Notification Center row
                    Button {
                        showNotificationCenter = true
                    } label: {
                        HStack {
                            ZStack(alignment: .topTrailing) {
                                Image(systemName: "bell.badge.fill")
                                    .font(.title3)
                                    .foregroundStyle(AppColors.brandTeal)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)
                            }

                            VStack(alignment: .leading, spacing: 2) {
                                Text("Notification Center")
                                    .foregroundStyle(AppColors.textPrimary)
                                Text("View and manage your notifications")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            Spacer()

                            if notificationStore.unreadCount > 0 {
                                Text("\(notificationStore.unreadCount)")
                                    .font(.caption)
                                    .fontWeight(.semibold)
                                    .foregroundStyle(.white)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(Color.red)
                                    .clipShape(Capsule())
                            }

                            Image(systemName: "chevron.right")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .accessibilityHidden(true)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("Notification Center\(notificationStore.unreadCount > 0 ? ", \(notificationStore.unreadCount) unread" : "")")
                    .accessibilityHint("Double tap to view and manage your notifications")

                    // Push Notifications toggle row
                    HStack {
                        Image(systemName: "bell.fill")
                            .font(.title3)
                            .foregroundStyle(pushManager.isAuthorized ? AppColors.brandTeal : .gray)
                            .frame(width: 28)
                            .accessibilityHidden(true)

                        VStack(alignment: .leading, spacing: 2) {
                            Text("Push Notifications")
                                .foregroundStyle(AppColors.textPrimary)
                            Text(notificationStatusText)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }

                        Spacer()

                        if pushManager.authorizationStatus == .notDetermined {
                            Button("Enable") {
                                Task {
                                    _ = await pushManager.requestPermission()
                                }
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(AppColors.brandTeal)
                            .controlSize(.small)
                            .accessibilityLabel("Enable push notifications")
                            .accessibilityHint("Double tap to enable push notifications for this app")
                        } else if pushManager.isAuthorized {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundStyle(.green)
                                .accessibilityLabel("Push notifications enabled")
                        } else {
                            Button("Settings") {
                                openNotificationSettings()
                            }
                            .buttonStyle(.bordered)
                            .controlSize(.small)
                            .accessibilityLabel("Open notification settings")
                            .accessibilityHint("Double tap to open iOS Settings to enable notifications")
                        }
                    }
                    .padding(.vertical, 4)
                    .accessibilityElement(children: .contain)

                    // Notification Preferences row (v6.48.0)
                    Button {
                        showNotificationPreferences = true
                    } label: {
                        HStack {
                            Image(systemName: "slider.horizontal.3")
                                .font(.title3)
                                .foregroundStyle(AppColors.brandTeal)
                                .frame(width: 28)
                                .accessibilityHidden(true)

                            VStack(alignment: .leading, spacing: 2) {
                                Text("Notification Settings")
                                    .foregroundStyle(AppColors.textPrimary)
                                Text("Manage alert types and quiet hours")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            Spacer()

                            Image(systemName: "chevron.right")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .accessibilityHidden(true)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityElement(children: .combine)
                    .accessibilityLabel("Notification Settings. Manage alert types and quiet hours")
                    .accessibilityHint("Double tap to customize your notification preferences")
                }

                // Appearance section
                Section("Appearance") {
                    ForEach(AppearanceMode.allCases, id: \.self) { mode in
                        Button {
                            withAnimation(.easeInOut(duration: 0.2)) {
                                appearanceManager.appearanceMode = mode
                            }
                        } label: {
                            HStack {
                                Image(systemName: mode.icon)
                                    .font(.title3)
                                    .foregroundStyle(mode == .light ? .orange : .indigo)
                                    .frame(width: 28)
                                    .accessibilityHidden(true)

                                Text(mode.displayName)
                                    .foregroundStyle(AppColors.textPrimary)

                                Spacer()

                                if appearanceManager.appearanceMode == mode {
                                    Image(systemName: "checkmark")
                                        .foregroundStyle(AppColors.brandTeal)
                                        .fontWeight(.semibold)
                                        .accessibilityHidden(true)
                                }
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityLabel("\(mode.displayName) mode\(appearanceManager.appearanceMode == mode ? ", selected" : "")")
                        .accessibilityHint(appearanceManager.appearanceMode == mode ? "Currently selected" : "Double tap to switch to \(mode.displayName.lowercased()) mode")
                        .accessibilityAddTraits(appearanceManager.appearanceMode == mode ? [.isSelected] : [])
                    }
                }

                // Log Out section
                Section {
                    Button(role: .destructive) {
                        Task {
                            await authViewModel.logout()
                        }
                    } label: {
                        HStack {
                            Image(systemName: "rectangle.portrait.and.arrow.right")
                            Text("Log Out")
                        }
                    }
                    .accessibilityLabel("Log Out")
                    .accessibilityHint("Double tap to sign out of your account")
                }

                // Delete Account section (Apple App Store Guideline 5.1.1(v) compliance)
                Section {
                    Button(role: .destructive) {
                        showDeleteAccountAlert = true
                    } label: {
                        HStack {
                            Image(systemName: "person.crop.circle.badge.xmark")
                            Text("Delete Account")
                        }
                    }
                    .accessibilityLabel("Delete Account")
                    .accessibilityHint("Double tap to permanently delete your account and all data")
                } footer: {
                    Text("Permanently delete your account and all associated data.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            .navigationTitle("Profile")
            .sheet(isPresented: $showSavedSearches) {
                SavedSearchesView()
                    .environmentObject(searchViewModel)
            }
            .sheet(isPresented: $showSavedProperties) {
                SavedPropertiesView()
                    .environmentObject(searchViewModel)
            }
            .sheet(isPresented: $showHiddenProperties) {
                HiddenPropertiesView()
                    .environmentObject(searchViewModel)
            }
            .sheet(isPresented: $showNotificationCenter) {
                NotificationCenterView()
                    .environmentObject(searchViewModel)
                    .environmentObject(notificationStore)
            }
            .sheet(isPresented: $showNotificationPreferences) {
                NotificationPreferencesView()
            }
            .sheet(isPresented: $showAgentReferral) {
                NavigationStack {
                    AgentReferralView()
                }
            }
            .sheet(isPresented: $showExclusiveListings) {
                NavigationStack {
                    ExclusiveListingsView()
                }
            }
            .sheet(isPresented: $showOpenHouse) {
                NavigationStack {
                    OpenHouseListView()
                }
            }
            .alert("Delete Account?", isPresented: $showDeleteAccountAlert) {
                Button("Cancel", role: .cancel) { }
                Button("Delete", role: .destructive) {
                    showDeleteAccountConfirmation = true
                }
            } message: {
                Text("This will permanently delete your account and all data including saved searches, favorites, and notification history. This action cannot be undone.")
            }
            .sheet(isPresented: $showDeleteAccountConfirmation) {
                DeleteAccountConfirmationView()
                    .environmentObject(authViewModel)
            }
            .onReceive(NotificationCenter.default.publisher(for: .openSavedSearchesSheet)) { _ in
                showSavedSearches = true
            }
            .task {
                await pushManager.checkAuthorizationStatus()
            }
        }
    }

    private var notificationStatusText: String {
        switch pushManager.authorizationStatus {
        case .authorized:
            return "Enabled - You'll receive alerts for saved searches"
        case .denied:
            return "Disabled - Tap Settings to enable"
        case .notDetermined:
            return "Get notified when new listings match your searches"
        case .provisional:
            return "Provisional - Quiet notifications enabled"
        case .ephemeral:
            return "Temporary access enabled"
        @unknown default:
            return "Unknown status"
        }
    }

    private func openNotificationSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) {
            UIApplication.shared.open(url)
        }
    }
}

#Preview {
    MainTabView()
        .environmentObject(AuthViewModel())
        .environmentObject(AppearanceManager.shared)
        .environmentObject(PushNotificationManager.shared)
}
