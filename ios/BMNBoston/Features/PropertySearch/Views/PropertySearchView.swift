//
//  PropertySearchView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Comprehensive property search matching MLD WordPress plugin
//

import SwiftUI
import MapKit

// MARK: - List View Mode (v216)

enum ListViewMode: String, CaseIterable {
    case card = "Card"
    case grid = "Grid"
    case compact = "Compact"

    var icon: String {
        switch self {
        case .card: return "rectangle.grid.1x2"
        case .grid: return "square.grid.2x2"
        case .compact: return "list.bullet"
        }
    }
}

struct PropertySearchView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @EnvironmentObject var notificationStore: NotificationStore
    @State private var showingFilters = false
    @State private var showingAdvancedFilters = false
    @State private var showingMap = false
    @State private var selectedProperty: Property?
    @State private var showingSearchAutocomplete = false
    @State private var showingSaveSearch = false
    // v6.68.12: Track when autocomplete navigation is in progress
    // This prevents onChange from handling navigation while modal is dismissing
    @State private var isAutocompleteNavigationInProgress = false

    // List view mode persistence (v216)
    @AppStorage("listViewMode") private var listViewModeRaw: String = ListViewMode.card.rawValue
    private var listViewMode: ListViewMode {
        ListViewMode(rawValue: listViewModeRaw) ?? .card
    }

    // First launch tracking (v217) - default to map view and wait for permissions
    @AppStorage("hasCompletedFirstLaunch") private var hasCompletedFirstLaunch = false
    private let permissionsOnboardingKey = "com.bmnboston.permissionsOnboardingCompleted"
    @State private var isWaitingForPermissions = false

    // Focus state for search bars
    @FocusState private var isSearchFocused: Bool

    var body: some View {
        NavigationStack {
            ZStack {
                // Main content - use searchState for clearer differentiation
                switch viewModel.searchState {
                case .loading where viewModel.properties.isEmpty:
                    loadingView
                case .error(let message) where viewModel.properties.isEmpty:
                    errorView(message)
                case .empty where !viewModel.isMapMode:
                    emptyStateView
                default:
                    if viewModel.isMapMode {
                        mapView
                    } else {
                        listView
                    }
                }
            }
            .navigationTitle(viewModel.isMapMode ? "" : "Search")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(viewModel.isMapMode ? .hidden : .visible, for: .navigationBar)
            .toolbar {
                toolbarContent
            }
            .fullScreenCover(isPresented: $showingSearchAutocomplete, onDismiss: {
                // v6.68.11: Check for pending navigation when search autocomplete modal is dismissed
                // This handles direct lookup (address/MLS tap) which starts an async fetch
                handleSearchModalDismiss()
            }) {
                SearchModalView(
                    searchText: $viewModel.searchText,
                    filters: $viewModel.filters,
                    suggestions: $viewModel.searchSuggestions,
                    recentSearches: viewModel.recentSearches,
                    onSearch: { text in
                        viewModel.performSearch(text)
                    },
                    onSuggestionTap: { suggestion in
                        // v6.68.12: Set flag for direct lookup (address/MLS) to prevent onChange from handling
                        // The handleSearchModalDismiss will handle navigation after modal is fully dismissed
                        if suggestion.type == .address || suggestion.type == .mlsNumber {
                            isAutocompleteNavigationInProgress = true
                            print("🔍 [PropertySearchView] Setting isAutocompleteNavigationInProgress = true for \(suggestion.type)")
                        }
                        viewModel.applySuggestion(suggestion)
                    },
                    onRecentSearchTap: { search in
                        viewModel.restoreRecentSearch(search)
                    },
                    onClearRecent: {
                        viewModel.clearRecentSearches()
                    }
                )
            }
            .sheet(isPresented: $showingAdvancedFilters) {
                AdvancedFilterModal(
                    filters: $viewModel.filters,
                    onApply: { appliedFilters in
                        // Pass filters directly to avoid @Binding timing issues
                        viewModel.applyFiltersAndSearch(appliedFilters)
                    },
                    onFetchCount: { filters in
                        await viewModel.fetchCount(for: filters)
                    }
                )
                .presentationDetents([.medium, .large])
                .presentationDragIndicator(.visible)
            }
            .sheet(isPresented: $showingSaveSearch) {
                CreateSavedSearchSheet()
                    .environmentObject(viewModel)
            }
            .navigationDestination(isPresented: Binding(
                get: { selectedProperty != nil },
                set: { if !$0 { selectedProperty = nil } }
            )) {
                if let property = selectedProperty {
                    PropertyDetailView(propertyId: property.id)
                }
            }
        }
        .toolbar(.visible, for: .navigationBar)
        .toolbar(.visible, for: .tabBar)
        .task {
            // v217: Handle first launch - default to map view and wait for permissions
            if !hasCompletedFirstLaunch {
                // Default to map view on first launch
                viewModel.setMapMode(true)

                // Check if permissions onboarding is needed
                let permissionsCompleted = UserDefaults.standard.bool(forKey: permissionsOnboardingKey)
                if !permissionsCompleted {
                    // Wait for permissions to be completed before loading
                    isWaitingForPermissions = true
                    // Use NotificationCenter to wait for completion (event-driven, not polling)
                    await waitForPermissionsCompletion()
                    isWaitingForPermissions = false
                    // Small delay to let location manager get first location
                    try? await Task.sleep(nanoseconds: 300_000_000) // 0.3s
                }

                hasCompletedFirstLaunch = true
            }

            // Now load properties
            if viewModel.properties.isEmpty {
                await viewModel.search()
            }
        }
        .onAppear {
            // Check for pending navigation when view appears (handles notification tap before tab switch completes)
            checkPendingNavigation()
        }
        .onChange(of: viewModel.pendingPropertyNavigation) { newProperty in
            // Handle push notification navigation to property (from ViewModel)
            // v6.68.12: Skip if autocomplete navigation is in progress - handleSearchModalDismiss will handle it
            // Navigation can't happen while fullScreenCover is dismissing, so let onDismiss handle autocomplete
            print("🔍 [PropertySearchView] pendingPropertyNavigation onChange - newProperty: \(newProperty?.id ?? "nil"), isAutocompleteNavigationInProgress: \(isAutocompleteNavigationInProgress)")
            guard !isAutocompleteNavigationInProgress else {
                print("🔍 [PropertySearchView] Skipping onChange - autocomplete navigation in progress, will handle in onDismiss")
                return
            }
            if let property = newProperty {
                print("🔍 [PropertySearchView] Setting selectedProperty to: \(property.id)")
                #if DEBUG
                debugLog("🔔 PropertySearchView: pendingPropertyNavigation changed to: \(property.id)")
                #endif
                selectedProperty = property
                print("🔍 [PropertySearchView] selectedProperty is now: \(selectedProperty?.id ?? "nil")")
                viewModel.pendingPropertyNavigation = nil
            }
        }
        .onChange(of: notificationStore.pendingPropertyListingId) { newListingId in
            // Handle push notification navigation via NotificationStore
            // Read BOTH listingId and listingKey from the store since they're set together
            #if DEBUG
            debugLog("🔔 PropertySearchView: pendingPropertyListingId changed to: \(newListingId ?? "nil")")
            #endif
            if newListingId != nil || notificationStore.pendingPropertyListingKey != nil {
                let listingId = newListingId
                let listingKey = notificationStore.pendingPropertyListingKey
                Task {
                    await fetchAndNavigateToProperty(listingId: listingId, listingKey: listingKey)
                }
            }
        }
        .onChange(of: notificationStore.pendingPropertyListingKey) { newListingKey in
            // Handle push notification navigation via NotificationStore
            // Only trigger if listingId hasn't already handled it (to avoid double navigation)
            #if DEBUG
            debugLog("🔔 PropertySearchView: pendingPropertyListingKey changed to: \(newListingKey ?? "nil")")
            #endif
            // Only proceed if we have a key but NO listingId (fallback case)
            if let listingKey = newListingKey, notificationStore.pendingPropertyListingId == nil {
                Task {
                    await fetchAndNavigateToProperty(listingId: nil, listingKey: listingKey)
                }
            }
        }
    }

    // MARK: - Push Notification Navigation

    /// Fetch property by ID or key and navigate to it
    /// Note: listing_key (hash) is preferred for API calls since /properties/{id} expects the hash, not MLS number
    private func fetchAndNavigateToProperty(listingId: String?, listingKey: String?) async {
        #if DEBUG
        debugLog("🔔 fetchAndNavigateToProperty - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
        #endif

        do {
            // Prefer listing_key for API calls (the /properties/{id} endpoint expects the hash, not MLS number)
            if let listingKey = listingKey {
                #if DEBUG
                debugLog("🔔 Fetching property by key (hash): \(listingKey)")
                #endif
                let property: Property = try await APIClient.shared.request(.propertyDetail(id: listingKey))
                #if DEBUG
                debugLog("🔔 Got property: \(property.id) - \(property.address)")
                #endif
                await MainActor.run {
                    selectedProperty = property
                    notificationStore.clearPendingPropertyNavigation()
                }
            } else if let listingId = listingId {
                // Fallback: if only MLS number provided, search by listing_id filter
                #if DEBUG
                debugLog("🔔 Fetching property by MLS number: \(listingId)")
                #endif
                var searchFilters = PropertySearchFilters()
                searchFilters.mlsNumber = listingId
                searchFilters.perPage = 1
                let data: PropertyListData = try await APIClient.shared.request(
                    .properties(filters: searchFilters)
                )
                if let property = data.listings.first {
                    #if DEBUG
                    debugLog("🔔 Got property by MLS number: \(property.id)")
                    #endif
                    await MainActor.run {
                        selectedProperty = property
                        notificationStore.clearPendingPropertyNavigation()
                    }
                } else {
                    #if DEBUG
                    debugLog("🔔 Property not found for MLS number: \(listingId)")
                    #endif
                    await MainActor.run {
                        notificationStore.clearPendingPropertyNavigation()
                    }
                }
            } else {
                #if DEBUG
                debugLog("🔔 No listing ID or key provided")
                #endif
                await MainActor.run {
                    notificationStore.clearPendingPropertyNavigation()
                }
            }
        } catch {
            #if DEBUG
            debugLog("🔔 Error fetching property: \(error.localizedDescription)")
            #endif
            await MainActor.run {
                notificationStore.clearPendingPropertyNavigation()
            }
        }
    }

    /// Check if there's a pending property navigation from a push notification tap
    /// Called on view appear to handle cases where notification was tapped before view was visible
    private func checkPendingNavigation() {
        let listingId = notificationStore.pendingPropertyListingId
        let listingKey = notificationStore.pendingPropertyListingKey

        guard listingId != nil || listingKey != nil else { return }

        #if DEBUG
        debugLog("🔔 PropertySearchView.onAppear: Found pending navigation - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
        #endif

        // Small delay to ensure navigation stack is ready after tab switch
        Task {
            try? await Task.sleep(nanoseconds: 100_000_000) // 100ms
            await fetchAndNavigateToProperty(listingId: listingId, listingKey: listingKey)
        }
    }

    /// v6.68.12: Handle search modal dismiss - check for pending property navigation
    /// This is called when the search autocomplete fullScreenCover is dismissed
    /// It handles the case where user tapped an address/MLS suggestion which starts
    /// an async property fetch.
    ///
    /// Navigation is handled by the .onChange(of: viewModel.pendingPropertyNavigation) handler,
    /// but we add a small delay here to let the fullScreenCover dismiss animation complete
    /// before the navigation can occur. Without this delay, SwiftUI may block the navigation
    /// while the dismiss animation is still in progress.
    private func handleSearchModalDismiss() {
        print("🔍 [PropertySearchView] handleSearchModalDismiss called, isAutocompleteNavigationInProgress: \(isAutocompleteNavigationInProgress)")

        // v6.68.12: Wait for dismiss animation to complete, then check for pending navigation
        // The async task in applySuggestion may still be running, so we need to wait for it
        Task { @MainActor in
            // Wait 500ms for dismiss animation to complete
            try? await Task.sleep(nanoseconds: 500_000_000)

            print("🔍 [PropertySearchView] After 500ms delay, checking for pending navigation")
            print("🔍 [PropertySearchView] pendingPropertyNavigation: \(viewModel.pendingPropertyNavigation?.id ?? "nil")")
            print("🔍 [PropertySearchView] selectedProperty: \(selectedProperty?.id ?? "nil")")

            // If pendingPropertyNavigation is set, trigger navigation
            if let property = viewModel.pendingPropertyNavigation {
                print("🔍 [PropertySearchView] Triggering navigation to: \(property.id)")
                // Small delay to ensure SwiftUI has finished processing modal dismiss
                try? await Task.sleep(nanoseconds: 100_000_000)
                selectedProperty = property
                viewModel.pendingPropertyNavigation = nil
                isAutocompleteNavigationInProgress = false
                print("🔍 [PropertySearchView] selectedProperty now set to: \(selectedProperty?.id ?? "nil")")
                return
            }

            // If no pending navigation yet, the API call may still be in progress
            // Wait a bit longer and check again
            print("🔍 [PropertySearchView] No pending navigation yet, waiting another 500ms...")
            try? await Task.sleep(nanoseconds: 500_000_000)

            if let property = viewModel.pendingPropertyNavigation {
                print("🔍 [PropertySearchView] Found pending navigation after second wait: \(property.id)")
                // Small delay to ensure SwiftUI has finished processing
                try? await Task.sleep(nanoseconds: 100_000_000)
                selectedProperty = property
                viewModel.pendingPropertyNavigation = nil
                isAutocompleteNavigationInProgress = false
                print("🔍 [PropertySearchView] selectedProperty now set to: \(selectedProperty?.id ?? "nil")")
            } else {
                print("🔍 [PropertySearchView] No pending navigation found after 1 second total")
                // Clear flag anyway in case something went wrong
                isAutocompleteNavigationInProgress = false
            }
        }
    }

    /// Wait for permissions onboarding to complete using NotificationCenter
    /// This is event-driven (no polling/busy-wait) and has a timeout for safety
    private func waitForPermissionsCompletion() async {
        // Double-check if already completed (race condition protection)
        guard !UserDefaults.standard.bool(forKey: permissionsOnboardingKey) else { return }

        // Create a task that waits for the notification with a timeout
        await withCheckedContinuation { continuation in
            var didResume = false
            let resumeOnce = {
                guard !didResume else { return }
                didResume = true
                continuation.resume()
            }

            // Listen for the completion notification
            var observer: NSObjectProtocol?
            observer = NotificationCenter.default.addObserver(
                forName: .permissionsOnboardingCompleted,
                object: nil,
                queue: .main
            ) { _ in
                if let obs = observer {
                    NotificationCenter.default.removeObserver(obs)
                }
                resumeOnce()
            }

            // Safety timeout after 60 seconds (in case notification is never sent)
            DispatchQueue.main.asyncAfter(deadline: .now() + 60) {
                if let obs = observer {
                    NotificationCenter.default.removeObserver(obs)
                }
                resumeOnce()
            }

            // Also check if it was completed while we were setting up
            if UserDefaults.standard.bool(forKey: permissionsOnboardingKey) {
                if let obs = observer {
                    NotificationCenter.default.removeObserver(obs)
                }
                resumeOnce()
            }
        }
    }

    // MARK: - Loading View

    private var loadingView: some View {
        VStack(spacing: 0) {
            // Skeleton search header
            SkeletonSearchHeader()
            // Skeleton property cards
            SkeletonPropertyList(count: 4)
        }
    }

    // MARK: - Error View

    private func errorView(_ error: String) -> some View {
        VStack(spacing: 20) {
            // Error icon based on error type
            Image(systemName: errorIcon(for: error))
                .font(.system(size: 56))
                .foregroundStyle(errorColor(for: error))

            Text(errorTitle(for: error))
                .font(.title3)
                .fontWeight(.semibold)

            Text(error)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            // Action buttons
            VStack(spacing: 12) {
                Button {
                    Task {
                        await viewModel.search()
                    }
                } label: {
                    Label("Try Again", systemImage: "arrow.clockwise")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(AppColors.brandTeal)
                .accessibilityLabel("Try again")
                .accessibilityHint("Double tap to retry loading properties")

                if viewModel.activeFilterCount > 0 {
                    Button {
                        viewModel.clearFilters()
                    } label: {
                        Label("Clear Filters", systemImage: "xmark.circle")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .accessibilityLabel("Clear filters")
                    .accessibilityHint("Double tap to remove all search filters")
                }
            }
            .padding(.horizontal, 40)
        }
        .padding()
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Error loading properties")
    }

    private func errorIcon(for error: String) -> String {
        if error.lowercased().contains("network") || error.lowercased().contains("internet") {
            return "wifi.slash"
        } else if error.lowercased().contains("server") {
            return "server.rack"
        } else {
            return "exclamationmark.triangle"
        }
    }

    private func errorColor(for error: String) -> Color {
        if error.lowercased().contains("network") || error.lowercased().contains("internet") {
            return .blue
        } else if error.lowercased().contains("server") {
            return .red
        } else {
            return .orange
        }
    }

    private func errorTitle(for error: String) -> String {
        if error.lowercased().contains("network") || error.lowercased().contains("internet") {
            return "No Connection"
        } else if error.lowercased().contains("server") {
            return "Server Error"
        } else {
            return "Unable to Load"
        }
    }

    // MARK: - Empty State View

    private var emptyStateView: some View {
        VStack(spacing: 16) {
            Image(systemName: "house.fill")
                .font(.system(size: 48))
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)
            Text("No Properties Found")
                .font(.headline)
            Text("Try adjusting your search filters or zoom out on the map")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            HStack(spacing: 12) {
                Button("Clear Filters") {
                    viewModel.clearFilters()
                }
                .buttonStyle(.bordered)
                .accessibilityLabel("Clear filters")
                .accessibilityHint("Double tap to remove all search filters and see more properties")

                Button("View Map") {
                    viewModel.setMapMode(true)
                }
                .buttonStyle(.borderedProminent)
                .tint(AppColors.brandTeal)
                .accessibilityLabel("View map")
                .accessibilityHint("Double tap to switch to map view and search a different area")
            }
        }
        .padding()
        .accessibilityElement(children: .contain)
        .accessibilityLabel("No properties found. Try adjusting your search filters or zoom out on the map.")
    }

    // MARK: - Map View (with bounds-based search)

    private var mapView: some View {
        ZStack {
            PropertyMapViewWithCard(
                properties: viewModel.properties,
                onPropertySelected: { property in
                    selectedProperty = property
                },
                onFavoriteTap: { property in
                    Task {
                        await viewModel.toggleFavorite(for: property)
                    }
                },
                onHideTap: { property in
                    Task {
                        await viewModel.toggleHidden(for: property)
                    }
                },
                onPolygonDrawn: { coordinates in
                    viewModel.filters.polygonCoordinates = coordinates
                    Task {
                        await viewModel.search()
                    }
                },
                onMultipleShapesDrawn: { shapes in
                    // Multi-shape search: search each shape and combine results (OR logic)
                    Task {
                        await viewModel.searchMultipleShapes(shapes)
                    }
                },
                onPolygonCleared: {
                    // All shapes cleared - reset to bounds-based search
                    viewModel.clearPolygonSearch()
                },
                onBoundsChanged: { bounds in
                    // Store current bounds for schools toggle
                    currentMapBounds = bounds

                    // Always save the map region for restoration on 0 results
                    viewModel.saveCurrentMapRegion(bounds)

                    // Auto-search when map moves
                    viewModel.updateMapBounds(bounds)

                    // Fetch schools when schools overlay is enabled
                    if showSchools {
                        viewModel.fetchMapSchools(for: bounds)
                    }

                    // Fetch transit stations when transit overlay is enabled
                    if showTransit {
                        let region = MKCoordinateRegion(
                            center: CLLocationCoordinate2D(
                                latitude: (bounds.north + bounds.south) / 2,
                                longitude: (bounds.east + bounds.west) / 2
                            ),
                            span: MKCoordinateSpan(
                                latitudeDelta: bounds.north - bounds.south,
                                longitudeDelta: bounds.east - bounds.west
                            )
                        )
                        viewModel.fetchMapTransitStations(for: region)
                    }
                },
                propertiesVersion: viewModel.propertiesVersion,
                targetMapRegion: $viewModel.targetMapRegion,
                animateMapRegion: $viewModel.animateMapRegion,
                cityBoundaries: $viewModel.cityBoundaries,
                boundariesVersion: $viewModel.boundariesVersion,
                cityPriceAnnotations: [],  // City Prices feature removed
                schoolAnnotations: showSchools ? viewModel.mapSchools : [],
                onSchoolSelected: { school in
                    selectedSchool = school
                },
                transitAnnotations: showTransit ? viewModel.mapTransitStations : [],
                transitRoutes: {
                    let routes = showTransit ? viewModel.mapTransitRoutes : []
                    if showTransit {
                        print("🚇 PropertySearchView: Passing \(routes.count) routes to map (showTransit=\(showTransit), version=\(viewModel.transitRoutesVersion))")
                    }
                    return routes
                }(),
                transitRoutesVersion: viewModel.transitRoutesVersion,
                onTransitSelected: { station in
                    // Future: could show a callout or detail for the station
                    print("Selected transit station: \(station.name)")
                },
                showTransit: showTransit,
                autoSelectSingleResult: viewModel.isExactMatchSearch,
                externalPolygonCoordinates: viewModel.filters.polygonCoordinates,
                recentlyViewedIds: viewModel.recentlyViewedIds
            )
            .ignoresSafeArea(edges: .top)

            // Overlay controls
            VStack(spacing: 0) {
                // Top - Search bar (with safe area padding)
                HStack(spacing: 8) {
                    // Tappable search field that opens modal (v217: improved tap area)
                    Button {
                        showingSearchAutocomplete = true
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "magnifyingglass")
                                .font(.system(size: 14))
                                .foregroundStyle(.secondary)

                            Text(viewModel.searchText.isEmpty ? "Search location..." : viewModel.searchText)
                                .font(.subheadline)
                                .foregroundStyle(viewModel.searchText.isEmpty ? .secondary : .primary)
                                .lineLimit(1)

                            Spacer()

                            if !viewModel.searchText.isEmpty {
                                Button {
                                    viewModel.searchText = ""
                                    viewModel.filters.searchText = nil
                                    Task {
                                        await viewModel.search()
                                    }
                                } label: {
                                    Image(systemName: "xmark.circle.fill")
                                        .font(.system(size: 14))
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                        .contentShape(Rectangle()) // v217: Make entire area tappable
                    }
                    .buttonStyle(.plain)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .contentShape(Rectangle()) // v217: Ensure tap works on background too

                    // List/Map toggle - preserves map bounds as filter (v217: improved visibility)
                    Button {
                        withAnimation {
                            viewModel.setMapMode(false)
                        }
                        // Note: map bounds are preserved so list shows same area
                        // User can clear via search or clear filters
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "list.bullet")
                                .font(.system(size: 14, weight: .semibold))
                            Text("List")
                                .font(.subheadline)
                                .fontWeight(.semibold)
                        }
                        .foregroundStyle(AppColors.brandTeal)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 10)
                        .background(Color(.systemBackground))
                        .clipShape(Capsule())
                        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
                    }
                    .accessibilityLabel("Switch to list view")
                    .accessibilityHint("Double tap to view properties as a scrollable list")

                    // Filter button
                    Button {
                        showingAdvancedFilters = true
                    } label: {
                        Image(systemName: "slider.horizontal.3")
                            .font(.system(size: 16, weight: .medium))
                            .foregroundStyle(.primary)
                            .frame(width: 40, height: 40)
                            .background(Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                            .overlay(alignment: .topTrailing) {
                                if viewModel.activeFilterCount > 0 {
                                    Circle()
                                        .fill(Color.red)
                                        .frame(width: 8, height: 8)
                                        .offset(x: 2, y: -2)
                                }
                            }
                    }
                    .accessibilityLabel("Filters\(viewModel.activeFilterCount > 0 ? ", \(viewModel.activeFilterCount) active" : "")")
                    .accessibilityHint("Double tap to open search filters")
                }
                .padding(.horizontal, 12)
                .padding(.top, 8) // Just below status bar (safe area is respected)

                // Location filter bubbles for map view
                if hasLocationFilters {
                    mapLocationFilterBubbles
                }

                // Active filters badge (v215 - below location bubbles)
                if viewModel.activeFilterCount > 0 && !hasLocationFilters {
                    HStack {
                        Button {
                            showingAdvancedFilters = true
                        } label: {
                            HStack(spacing: 4) {
                                Image(systemName: "line.3.horizontal.decrease.circle.fill")
                                    .font(.system(size: 14))
                                Text("\(viewModel.activeFilterCount) filter\(viewModel.activeFilterCount == 1 ? "" : "s")")
                                    .font(.caption)
                                    .fontWeight(.medium)
                            }
                            .foregroundStyle(.white)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .background(AppColors.brandTeal)
                            .clipShape(Capsule())
                        }
                        Spacer()
                    }
                    .padding(.horizontal, 12)
                    .padding(.top, 4)
                }

                // Right-side floating controls (Redfin-style)
                HStack {
                    Spacer()
                    floatingMapControls
                        .padding(.trailing, 12)
                }
                .padding(.top, 8)

                Spacer()

                // Bottom controls
                HStack(alignment: .bottom) {
                    // Bottom left - Property count or "No results" message (50% transparent)
                    if !viewModel.properties.isEmpty {
                        resultsCountBadge
                            .opacity(0.5)
                    } else if !viewModel.isLoading {
                        // No results message
                        HStack(spacing: 6) {
                            Image(systemName: "magnifyingglass")
                                .font(.footnote.weight(.medium))
                            Text("No results in this area")
                                .font(.footnote.weight(.medium))
                        }
                        .foregroundStyle(.white)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background(Color.black.opacity(0.6))
                        .clipShape(Capsule())
                    }

                    Spacer()

                    // Bottom right - Save Search button
                    saveSearchButton
                }
                .padding(.horizontal, 12)
                .padding(.bottom, 16) // Just above tab bar
            }

            // School info card overlay
            if let school = selectedSchool {
                VStack {
                    Spacer()
                    SchoolInfoCard(school: school) {
                        selectedSchool = nil
                    }
                    .padding(.horizontal)
                    .padding(.bottom, 100) // Above tab bar
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
        }
        .animation(.easeInOut(duration: 0.25), value: selectedSchool?.id)
        .onAppear {
            // Auto-center on user location on first map launch
            if !hasLoadedMapFirstTime {
                hasLoadedMapFirstTime = true
                isRequestingFirstLoadLocation = true
                // Trigger location centering with delay to let map initialize
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                    NotificationCenter.default.post(name: .centerOnUserLocation, object: nil)
                }
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .locationPermissionDenied)) { _ in
            // If location permission denied on first launch, default to Boston
            if isRequestingFirstLoadLocation {
                isRequestingFirstLoadLocation = false
                // Default to Greater Boston area
                viewModel.targetMapRegion = MKCoordinateRegion(
                    center: CLLocationCoordinate2D(latitude: 42.3601, longitude: -71.0589),
                    span: MKCoordinateSpan(latitudeDelta: 0.15, longitudeDelta: 0.15)
                )
                viewModel.animateMapRegion = true
            }
        }
    }

    // MARK: - First Launch Auto-Location

    @AppStorage("hasLoadedMapFirstTime") private var hasLoadedMapFirstTime = false
    @State private var isRequestingFirstLoadLocation = false

    // MARK: - Unified Map Controls Panel (bottom right)

    @State private var mapType: MKMapType = .standard
    @State private var showDrawingTools = false
    @State private var showSchools = false
    @State private var showTransit = false
    @State private var selectedSchool: MapSchool?
    @State private var currentMapBounds: MapBounds?

    // MARK: - Floating Map Controls (Redfin-style)

    private var floatingMapControls: some View {
        VStack(spacing: 12) {
            // Map type toggle
            Button {
                mapType = mapType == .standard ? .satellite : .standard
                NotificationCenter.default.post(name: .mapTypeChanged, object: mapType)
            } label: {
                Image(systemName: mapType == .satellite ? "map.fill" : "square.stack.3d.up")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundStyle(mapType == .satellite ? AppColors.brandTeal : .primary)
                    .frame(width: 44, height: 44)
                    .background(.ultraThinMaterial)
                    .clipShape(Circle())
            }
            .accessibilityLabel(mapType == .satellite ? "Standard map view" : "Satellite view")
            .accessibilityHint("Double tap to switch map style")

            // Draw search toggle
            Button {
                showDrawingTools.toggle()
                NotificationCenter.default.post(name: .toggleDrawingTools, object: showDrawingTools)
            } label: {
                Image(systemName: showDrawingTools ? "xmark" : "hand.draw")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundStyle(showDrawingTools ? .red : .primary)
                    .frame(width: 44, height: 44)
                    .background(.ultraThinMaterial)
                    .clipShape(Circle())
            }
            .accessibilityLabel(showDrawingTools ? "Close drawing tools" : "Draw search area")
            .accessibilityHint("Double tap to \(showDrawingTools ? "exit" : "enable") custom area drawing")

            // Schools toggle
            Button {
                showSchools.toggle()
                if showSchools {
                    let bounds = currentMapBounds ?? MapBounds(
                        north: 42.5,
                        south: 42.2,
                        east: -70.8,
                        west: -71.2
                    )
                    viewModel.fetchMapSchools(for: bounds)
                } else {
                    viewModel.mapSchools = []
                    selectedSchool = nil
                }
            } label: {
                Image(systemName: showSchools ? "graduationcap.fill" : "graduationcap")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundStyle(showSchools ? AppColors.brandTeal : .primary)
                    .frame(width: 44, height: 44)
                    .background(.ultraThinMaterial)
                    .clipShape(Circle())
            }
            .accessibilityLabel(showSchools ? "Hide schools" : "Show schools")
            .accessibilityHint("Double tap to \(showSchools ? "hide" : "show") nearby schools on map")

            // Transit toggle (MBTA stations)
            Button {
                showTransit.toggle()
                if showTransit {
                    let bounds = currentMapBounds ?? MapBounds(
                        north: 42.5,
                        south: 42.2,
                        east: -70.8,
                        west: -71.2
                    )
                    let region = MKCoordinateRegion(
                        center: CLLocationCoordinate2D(
                            latitude: (bounds.north + bounds.south) / 2,
                            longitude: (bounds.east + bounds.west) / 2
                        ),
                        span: MKCoordinateSpan(
                            latitudeDelta: bounds.north - bounds.south,
                            longitudeDelta: bounds.east - bounds.west
                        )
                    )
                    viewModel.fetchMapTransitStations(for: region)
                } else {
                    viewModel.mapTransitStations = []
                }
            } label: {
                Image(systemName: showTransit ? "tram.fill" : "tram")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundStyle(showTransit ? AppColors.brandTeal : .primary)
                    .frame(width: 44, height: 44)
                    .background(.ultraThinMaterial)
                    .clipShape(Circle())
            }
            .accessibilityLabel(showTransit ? "Hide transit" : "Show transit")
            .accessibilityHint("Double tap to \(showTransit ? "hide" : "show") MBTA stations on map")

            // My location button
            Button {
                NotificationCenter.default.post(name: .centerOnUserLocation, object: nil)
            } label: {
                Image(systemName: "location.fill")
                    .font(.system(size: 18, weight: .medium))
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 44, height: 44)
                    .background(.ultraThinMaterial)
                    .clipShape(Circle())
            }
            .accessibilityLabel("My location")
            .accessibilityHint("Double tap to center map on your current location")
        }
        .shadow(color: .black.opacity(0.15), radius: 8, y: 4)
    }

    // MARK: - Save Search Button

    private var saveSearchButton: some View {
        Button {
            showingSaveSearch = true
        } label: {
            HStack(spacing: 8) {
                Image(systemName: "bookmark.fill")
                    .font(.system(size: 14, weight: .semibold))
                Text("Save Search")
                    .font(.subheadline)
                    .fontWeight(.semibold)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(AppColors.brandTeal)
            .clipShape(Capsule())
            .shadow(color: AppColors.shadowMedium, radius: 8, y: 4)
        }
        .accessibilityLabel("Save search")
        .accessibilityHint("Double tap to save current search filters")
    }

    private var mapSearchBar: some View {
        HStack(spacing: 12) {
            // Search field
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass")
                    .foregroundStyle(.secondary)

                TextField("Search location...", text: $viewModel.searchText)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($isSearchFocused)
                    .onChange(of: isSearchFocused) { focused in
                        if focused {
                            showingSearchAutocomplete = true
                        }
                    }
                    .onSubmit {
                        viewModel.performSearch(viewModel.searchText)
                        showingSearchAutocomplete = false
                        isSearchFocused = false
                    }

                if !viewModel.searchText.isEmpty {
                    Button {
                        viewModel.searchText = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .padding(10)
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .shadow(color: AppColors.shadowLight, radius: 4, y: 2)
        }
        .padding(.horizontal)
        .padding(.top, 8)
    }

    private var activeFiltersBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(viewModel.activeFilterChips) { chip in
                    FilterChipView(chip: chip) {
                        viewModel.removeFilterChip(chip.id)
                    }
                }

                if viewModel.activeFilterCount > 0 {
                    Button("Clear All") {
                        viewModel.clearFilters()
                    }
                    .font(.caption)
                    .foregroundStyle(.red)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(Capsule())
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 8)
        }
    }

    // MARK: - Quick Filter Presets

    private var quickFilterPresetsBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                // New This Week
                FilterPresetChip(
                    label: "New This Week",
                    icon: "sparkles",
                    isActive: viewModel.filters.newListing
                ) {
                    viewModel.toggleNewListingPreset()
                }

                // Price Reduced
                FilterPresetChip(
                    label: "Price Reduced",
                    icon: "arrow.down.circle",
                    isActive: viewModel.filters.priceReduced
                ) {
                    viewModel.togglePriceReducedPreset()
                }

                // Open Houses
                FilterPresetChip(
                    label: "Open Houses",
                    icon: "calendar",
                    isActive: viewModel.filters.openHouseOnly
                ) {
                    viewModel.toggleOpenHousePreset()
                }

                // Virtual Tour
                FilterPresetChip(
                    label: "Virtual Tour",
                    icon: "video.fill",
                    isActive: viewModel.filters.hasVirtualTour
                ) {
                    viewModel.toggleVirtualTourPreset()
                }

                // Waterfront (popular in Boston area)
                FilterPresetChip(
                    label: "Waterfront",
                    icon: "water.waves",
                    isActive: viewModel.filters.hasWaterfront
                ) {
                    viewModel.toggleWaterfrontPreset()
                }

                // Pool
                FilterPresetChip(
                    label: "Pool",
                    icon: "figure.pool.swim",
                    isActive: viewModel.filters.hasPool
                ) {
                    viewModel.togglePoolPreset()
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 8)
        }
    }

    // MARK: - Location Filter Bubbles

    private var hasLocationFilters: Bool {
        !viewModel.filters.cities.isEmpty || !viewModel.filters.zips.isEmpty || !viewModel.filters.neighborhoods.isEmpty || (viewModel.filters.streetName != nil && !viewModel.filters.streetName!.isEmpty)
    }

    private var mapLocationFilterBubbles: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                // Cities
                ForEach(Array(viewModel.filters.cities), id: \.self) { city in
                    LocationFilterBubble(
                        text: city,
                        type: "City",
                        onRemove: {
                            viewModel.filters.cities.remove(city)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // ZIPs
                ForEach(Array(viewModel.filters.zips), id: \.self) { zip in
                    LocationFilterBubble(
                        text: zip,
                        type: "ZIP",
                        onRemove: {
                            viewModel.filters.zips.remove(zip)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // Neighborhoods
                ForEach(Array(viewModel.filters.neighborhoods), id: \.self) { neighborhood in
                    LocationFilterBubble(
                        text: neighborhood,
                        type: "Neighborhood",
                        onRemove: {
                            viewModel.filters.neighborhoods.remove(neighborhood)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // Street Name
                if let streetName = viewModel.filters.streetName, !streetName.isEmpty {
                    LocationFilterBubble(
                        text: streetName,
                        type: "Street",
                        onRemove: {
                            viewModel.filters.streetName = nil
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
        }
    }

    private var listLocationFilterBubbles: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                // Cities
                ForEach(Array(viewModel.filters.cities), id: \.self) { city in
                    LocationFilterBubble(
                        text: city,
                        type: "City",
                        onRemove: {
                            viewModel.filters.cities.remove(city)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // ZIPs
                ForEach(Array(viewModel.filters.zips), id: \.self) { zip in
                    LocationFilterBubble(
                        text: zip,
                        type: "ZIP",
                        onRemove: {
                            viewModel.filters.zips.remove(zip)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // Neighborhoods
                ForEach(Array(viewModel.filters.neighborhoods), id: \.self) { neighborhood in
                    LocationFilterBubble(
                        text: neighborhood,
                        type: "Neighborhood",
                        onRemove: {
                            viewModel.filters.neighborhoods.remove(neighborhood)
                            // FIX: Clear boundaries synchronously if no location filters remain
                            if viewModel.filters.cities.isEmpty && viewModel.filters.neighborhoods.isEmpty && viewModel.filters.zips.isEmpty {
                                viewModel.cityBoundaries = []
                                viewModel.boundariesVersion += 1
                            } else {
                                viewModel.updateCityBoundaries()
                            }
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }

                // Street Name
                if let streetName = viewModel.filters.streetName, !streetName.isEmpty {
                    LocationFilterBubble(
                        text: streetName,
                        type: "Street",
                        onRemove: {
                            viewModel.filters.streetName = nil
                            Task {
                                await viewModel.search()
                            }
                        }
                    )
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 8)
        }
        .background(Color(.systemBackground))
    }

    private var resultsCountBadge: some View {
        HStack(spacing: 4) {
            Image(systemName: "house.fill")
                .font(.caption)
            Text("\(viewModel.totalResults) properties")
                .font(.caption)
                .fontWeight(.medium)
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 12)
        .padding(.vertical, 6)
        .background(AppColors.brandTeal)
        .clipShape(Capsule())
        .shadow(color: AppColors.shadowLight, radius: 4, y: 2)
        .accessibilityLabel("\(viewModel.totalResults) properties found")
    }

    // MARK: - List View

    private var listView: some View {
        VStack(spacing: 0) {
            // Search bar
            listSearchBar

            // Location filter bubbles
            if hasLocationFilters {
                listLocationFilterBubbles
            }

            // Quick filter presets
            quickFilterPresetsBar

            // Active filters
            if viewModel.activeFilterCount > 0 {
                activeFiltersBar
            }

            // Results header
            resultsHeader

            // Property list - mode-dependent rendering (v216)
            ScrollView {
                switch listViewMode {
                case .card:
                    // Full-width cards (default)
                    LazyVStack(spacing: 12) {
                        ForEach(viewModel.properties) { property in
                            NavigationLink {
                                PropertyDetailView(propertyId: property.id)
                            } label: {
                                PropertyCard(
                                    property: property,
                                    onFavoriteTap: {
                                        Task {
                                            await viewModel.toggleFavorite(for: property)
                                        }
                                    },
                                    onHideTap: {
                                        Task {
                                            await viewModel.toggleHidden(for: property)
                                        }
                                    },
                                    isFavoriteLoading: viewModel.favoriteLoadingIds.contains(property.id),
                                    isHideLoading: viewModel.hiddenLoadingIds.contains(property.id)
                                )
                            }
                            .buttonStyle(.plain)
                            .onAppear {
                                if property.id == viewModel.properties.last?.id {
                                    Task {
                                        await viewModel.loadMore()
                                    }
                                }
                            }
                        }

                        if viewModel.isLoadingMore {
                            ProgressView()
                                .padding()
                        }
                    }
                    .padding(.vertical)

                case .grid:
                    // 2-column grid with CompactPropertyCard
                    LazyVGrid(columns: [
                        GridItem(.flexible(), spacing: 12),
                        GridItem(.flexible(), spacing: 12)
                    ], spacing: 12) {
                        ForEach(viewModel.properties) { property in
                            NavigationLink {
                                PropertyDetailView(propertyId: property.id)
                            } label: {
                                CompactPropertyCard(
                                    property: property,
                                    onFavoriteTap: {
                                        Task {
                                            await viewModel.toggleFavorite(for: property)
                                        }
                                    },
                                    onHideTap: {
                                        Task {
                                            await viewModel.toggleHidden(for: property)
                                        }
                                    },
                                    isFavoriteLoading: viewModel.favoriteLoadingIds.contains(property.id),
                                    isHideLoading: viewModel.hiddenLoadingIds.contains(property.id)
                                )
                            }
                            .buttonStyle(.plain)
                            .onAppear {
                                if property.id == viewModel.properties.last?.id {
                                    Task {
                                        await viewModel.loadMore()
                                    }
                                }
                            }
                        }

                        if viewModel.isLoadingMore {
                            ProgressView()
                                .gridCellColumns(2)
                                .padding()
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical)

                case .compact:
                    // Compact row list
                    LazyVStack(spacing: 0) {
                        ForEach(viewModel.properties) { property in
                            VStack(spacing: 0) {
                                NavigationLink {
                                    PropertyDetailView(propertyId: property.id)
                                } label: {
                                    PropertyRow(
                                        property: property,
                                        onFavoriteTap: {
                                            Task {
                                                await viewModel.toggleFavorite(for: property)
                                            }
                                        },
                                        onHideTap: {
                                            Task {
                                                await viewModel.toggleHidden(for: property)
                                            }
                                        },
                                        isFavoriteLoading: viewModel.favoriteLoadingIds.contains(property.id),
                                        isHideLoading: viewModel.hiddenLoadingIds.contains(property.id)
                                    )
                                }
                                .buttonStyle(.plain)

                                Divider()
                            }
                            .onAppear {
                                if property.id == viewModel.properties.last?.id {
                                    Task {
                                        await viewModel.loadMore()
                                    }
                                }
                            }
                        }

                        if viewModel.isLoadingMore {
                            ProgressView()
                                .padding()
                        }
                    }
                    .padding(.vertical)
                }
            }
            .refreshable {
                await viewModel.refresh()
            }
        }
    }

    private var listSearchBar: some View {
        HStack(spacing: 12) {
            // Tappable search bar that opens modal (v217: improved tap area)
            Button {
                showingSearchAutocomplete = true
            } label: {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass")
                        .foregroundStyle(.secondary)

                    Text(viewModel.searchText.isEmpty ? "City, ZIP, Address, or MLS #" : viewModel.searchText)
                        .foregroundStyle(viewModel.searchText.isEmpty ? .secondary : .primary)
                        .lineLimit(1)

                    Spacer()

                    if !viewModel.searchText.isEmpty {
                        Button {
                            viewModel.searchText = ""
                            viewModel.filters.searchText = nil
                            Task {
                                await viewModel.search()
                            }
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .contentShape(Rectangle()) // v217: Make entire area tappable
            }
            .buttonStyle(.plain)
            .padding(12)
            .background(Color(.secondarySystemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .contentShape(Rectangle()) // v217: Ensure tap works on background too
        }
        .padding(.horizontal)
        .padding(.vertical, 8)
    }

    private var resultsHeader: some View {
        HStack {
            Text("\(viewModel.totalResults) properties")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            Spacer()

            sortMenu
        }
        .padding(.horizontal)
        .padding(.bottom, 8)
    }

    private var sortMenu: some View {
        Menu {
            ForEach(SortOption.allCases, id: \.self) { option in
                Button {
                    viewModel.setSort(option)
                } label: {
                    HStack {
                        Text(option.displayName)
                        if viewModel.filters.sort == option {
                            Image(systemName: "checkmark")
                        }
                    }
                }
            }
        } label: {
            HStack(spacing: 4) {
                Text(viewModel.filters.sort.displayName)
                    .font(.subheadline)
                Image(systemName: "chevron.down")
                    .font(.caption)
            }
            .foregroundStyle(AppColors.brandTeal)
        }
        .accessibilityLabel("Sort by \(viewModel.filters.sort.displayName)")
        .accessibilityHint("Double tap to change sort order")
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        // Only show toolbar items when NOT in map mode (map mode has its own overlay controls)
        if !viewModel.isMapMode {
            ToolbarItemGroup(placement: .topBarTrailing) {
                // View mode toggle (v216)
                Menu {
                    ForEach(ListViewMode.allCases, id: \.self) { mode in
                        Button {
                            listViewModeRaw = mode.rawValue
                        } label: {
                            Label(mode.rawValue, systemImage: mode.icon)
                        }
                    }
                } label: {
                    Image(systemName: listViewMode.icon)
                }
                .accessibilityLabel("View mode: \(listViewMode.rawValue)")
                .accessibilityHint("Double tap to change list layout")

                // Save search button
                Button {
                    showingSaveSearch = true
                } label: {
                    Image(systemName: "bookmark")
                }
                .accessibilityLabel("Save search")
                .accessibilityHint("Double tap to save current search filters")

                // Filter button
                Button {
                    showingAdvancedFilters = true
                } label: {
                    Image(systemName: "slider.horizontal.3")
                        .overlay(alignment: .topTrailing) {
                            if viewModel.activeFilterCount > 0 {
                                Circle()
                                    .fill(Color.red)
                                    .frame(width: 8, height: 8)
                                    .offset(x: 4, y: -4)
                            }
                        }
                }
                .accessibilityLabel("Filters\(viewModel.activeFilterCount > 0 ? ", \(viewModel.activeFilterCount) active" : "")")
                .accessibilityHint("Double tap to open search filters")

                // Map button
                Button {
                    withAnimation {
                        viewModel.setMapMode(true)
                    }
                } label: {
                    Image(systemName: "map")
                }
                .accessibilityLabel("Switch to map view")
                .accessibilityHint("Double tap to view properties on a map")
            }
        }
    }
}

// Note: FilterChipView is defined in AdvancedFilterModal.swift

// MARK: - Filter Preset Chip

struct FilterPresetChip: View {
    let label: String
    let icon: String
    let isActive: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon)
                    .font(.system(size: 12, weight: .semibold))

                Text(label)
                    .font(.subheadline)
                    .fontWeight(.medium)
            }
            .foregroundStyle(isActive ? .white : AppColors.brandTeal)
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(
                Capsule()
                    .fill(isActive ? AppColors.brandTeal : Color.clear)
            )
            .overlay(
                Capsule()
                    .stroke(AppColors.brandTeal, lineWidth: 1.5)
            )
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.15), value: isActive)
        .accessibilityLabel("\(label) filter\(isActive ? ", active" : "")")
        .accessibilityHint("Double tap to \(isActive ? "remove" : "apply") \(label.lowercased()) filter")
        .accessibilityAddTraits(isActive ? [.isSelected] : [])
    }
}

// MARK: - Notification Names for Map Controls

extension Notification.Name {
    static let mapTypeChanged = Notification.Name("mapTypeChanged")
    static let centerOnUserLocation = Notification.Name("centerOnUserLocation")
    static let toggleDrawingTools = Notification.Name("toggleDrawingTools")
    static let toggleHeatmap = Notification.Name("toggleHeatmap")
    static let clearCityBoundaries = Notification.Name("clearCityBoundaries")
    static let locationPermissionDenied = Notification.Name("locationPermissionDenied")
    static let permissionsOnboardingCompleted = Notification.Name("permissionsOnboardingCompleted")
}

// MARK: - Preview

#Preview {
    PropertySearchView()
}
