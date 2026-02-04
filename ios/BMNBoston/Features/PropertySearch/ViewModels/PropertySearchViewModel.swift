//
//  PropertySearchViewModel.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Comprehensive view model matching MLD WordPress plugin
//

import Foundation
import os.log
import Combine
import MapKit

@MainActor
class PropertySearchViewModel: ObservableObject {
    // MARK: - Published Properties

    @Published var properties: [Property] = []
    @Published var isLoading = false
    @Published var isLoadingMore = false
    @Published var isLoadingCount = false
    @Published var errorMessage: String?
    @Published var filters = PropertySearchFilters()
    @Published var totalResults = 0
    @Published var hasMorePages = true
    @Published var matchingCount: Int?
    @Published var priceStats: PriceStatistics?

    // Version tracking for map selection clearing
    @Published var propertiesVersion: Int = 0

    // Search
    @Published var searchText: String = ""
    @Published var isSearching: Bool = false
    @Published var searchSuggestions: [SearchSuggestion] = []
    @Published var recentSearches: [RecentSearch] = []
    // v321: Track autocomplete query version to prevent stale results from overwriting newer ones
    private var autocompleteQueryVersion: Int = 0
    private var lastAutocompleteQuery: String = ""

    // Saved Searches (server-synced)
    @Published var savedSearches: [SavedSearch] = []
    @Published var savedSearchSyncState: SavedSearchSyncState = .idle

    enum SavedSearchSyncState: Equatable {
        case idle
        case loading
        case saving
        case error(String)
    }

    /// Search state for differentiating between empty results and errors
    enum SearchState: Equatable {
        case idle
        case loading
        case success(count: Int)
        case empty  // Search completed with zero results
        case error(message: String)
    }

    /// Current state of the property search
    @Published var searchState: SearchState = .idle

    // User Preferences
    @Published var preferences: UserPropertyPreferences = UserPropertyPreferences()

    // Favorite Properties
    @Published var favoriteProperties: [Property] = []
    @Published var isLoadingFavorites = false

    // Hidden Properties
    @Published var hiddenProperties: [Property] = []
    @Published var isLoadingHidden = false

    // Loading states for individual property actions (favorite/hide toggles)
    // Tracks property IDs that are currently being processed
    @Published var favoriteLoadingIds: Set<String> = []
    @Published var hiddenLoadingIds: Set<String> = []

    // Map State
    @Published var isMapMode: Bool = false
    @Published var mapBounds: MapBounds?
    @Published var targetMapRegion: MKCoordinateRegion?  // For auto-zoom on location filter
    @Published var animateMapRegion: Bool = true  // Whether to animate the region change
    private var savedMapRegion: MKCoordinateRegion?  // Preserves region when switching between list/map
    private var preSearchMapRegion: MKCoordinateRegion?  // Saves region before search to restore on 0 results
    @Published var cityBoundaries: [MKPolygon] = []  // City boundary overlays
    @Published var boundariesVersion: Int = 0  // Incremented when boundaries change to force UI update
    @Published var neighborhoodAnalytics: [NeighborhoodAnalytics] = []  // City price overlays for zoomed-out view
    @Published var mapSchools: [MapSchool] = []  // School pins for map overlay
    @Published var mapTransitStations: [TransitStation] = []  // Transit station pins for map overlay
    @Published var mapTransitRoutes: [TransitRoute] = []  // Transit route polylines for map overlay
    @Published var transitRoutesVersion: Int = 0  // Incremented when routes change to force UI update

    // Multi-shape polygon storage for saved searches
    // Stores all drawn shapes (from multi-shape draw search) to be saved with search
    @Published var polygonShapes: [[CLLocationCoordinate2D]] = []

    // Track if current search is for exact address/MLS (for auto-select single result)
    @Published var isExactMatchSearch: Bool = false

    // Recently viewed property IDs for visual indicator on map pins
    // Using Array to maintain insertion order for FIFO trimming
    @Published var recentlyViewedIds: [String] = []
    private let recentlyViewedKey = "com.bmnboston.recentlyViewedIds"

    // Property to navigate to from push notification
    @Published var pendingPropertyNavigation: Property?

    // MARK: - Private Properties

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "PropertySearchViewModel")
    private var searchTask: Task<Void, Never>?
    private var countTask: Task<Void, Never>?
    private var suggestionsTask: Task<Void, Never>?
    // v6.68.12: Separate task for direct navigation to prevent cancellation by map bounds search
    private var navigationTask: Task<Void, Never>?

    // All properties loaded from API (for client-side bounds filtering)
    private var allProperties: [Property] = []

    // Retry configuration uses SearchConstants

    // Request throttling (adaptive like web)
    private var lastRequestTime: Date = .distantPast
    private var requestInterval: TimeInterval {
        // Adaptive: slower on mobile data
        return 0.3 // 300ms for mobile
    }

    // Combine subscriptions
    private var cancellables = Set<AnyCancellable>()

    // Timestamp of last polygon search completion
    // Used to prevent bounds-based search from overriding polygon results during map zoom animation
    private var lastPolygonSearchTime: Date?

    // Cooldown period after polygon search during which bounds updates are ignored
    // This allows the map zoom animation to complete without triggering new searches
    private let polygonSearchCooldownSeconds: TimeInterval = 3.0

    // Timestamp of last location filter search (city/neighborhood/zip/address/streetName)
    // Used to prevent bounds-based search from overriding location filter results during map animation
    private var lastLocationFilterSearchTime: Date?

    // Cooldown period after location filter search during which bounds updates are ignored
    // This allows the map zoom animation to complete without triggering new searches
    private let locationFilterCooldownSeconds: TimeInterval = 3.0

    // MARK: - Search Task Helper

    /// Cancel any in-flight search, run the provided closure to modify filters, then trigger a new search.
    /// This reduces code duplication across filter toggle and modification methods.
    ///
    /// Usage:
    /// ```
    /// cancelAndSearch {
    ///     filters.priceReduced.toggle()
    /// }
    /// ```
    private func cancelAndSearch(_ modifyFilters: () -> Void) {
        searchTask?.cancel()
        modifyFilters()
        searchTask = Task {
            await search()
        }
    }

    // MARK: - Initialization

    init() {
        loadPreferences()
        loadRecentlyViewedIds()
        setupSearchTextObserver()
        setupNotificationObservers()
        // Saved searches are loaded on-demand when user accesses them
        // or after login via loadSavedSearchesFromServer()
    }

    deinit {
        // Cancel all running tasks to prevent work after deallocation
        searchTask?.cancel()
        countTask?.cancel()
        suggestionsTask?.cancel()
        navigationTask?.cancel()
        // AnyCancellable automatically cancels on deallocation,
        // but explicit cleanup ensures immediate cancellation
        cancellables.removeAll()
    }

    private func setupNotificationObservers() {
        #if DEBUG
        debugLog("🔔 PropertySearchViewModel: Setting up notification observers")
        #endif

        // Listen for navigation to saved search (from push notifications)
        NotificationCenter.default.publisher(for: .navigateToSavedSearch)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] notification in
                #if DEBUG
                debugLog("🔔 Received .navigateToSavedSearch notification")
                #endif
                guard let searchId = notification.userInfo?["search_id"] as? Int else { return }
                Task { @MainActor in
                    await self?.navigateToSavedSearch(id: searchId)
                }
            }
            .store(in: &cancellables)

        // Listen for navigation to property (from push notifications)
        NotificationCenter.default.publisher(for: .navigateToProperty)
            .receive(on: DispatchQueue.main)
            .sink { [weak self] notification in
                #if DEBUG
                debugLog("🔔 Received .navigateToProperty notification: \(notification.userInfo ?? [:])")
                #endif
                let listingId = notification.userInfo?["listing_id"] as? String
                let listingKey = notification.userInfo?["listing_key"] as? String
                #if DEBUG
                debugLog("🔔 Extracted - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
                #endif
                Task { @MainActor in
                    await self?.navigateToProperty(listingId: listingId, listingKey: listingKey)
                }
            }
            .store(in: &cancellables)
    }

    /// Navigate to a saved search by ID (called from push notification tap)
    func navigateToSavedSearch(id: Int) async {
        // First, ensure saved searches are loaded
        if savedSearches.isEmpty {
            await loadSavedSearchesFromServer()
        }

        // Find the saved search
        if let search = savedSearches.first(where: { $0.id == id }) {
            applySavedSearch(search)
        } else {
            // Search not found in list - try fetching it directly
            logger.warning("Saved search \(id) not found in local list")
        }
    }

    /// Navigate to a property by listing ID or listing key (called from push notification tap)
    func navigateToProperty(listingId: String?, listingKey: String?) async {
        print("🔍 [navigateToProperty] START - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
        #if DEBUG
        debugLog("🔔 navigateToProperty called - listingId: \(listingId ?? "nil"), listingKey: \(listingKey ?? "nil")")
        #endif

        guard listingId != nil || listingKey != nil else {
            print("🔍 [navigateToProperty] ABORT - no listingId or listingKey")
            logger.warning("navigateToProperty called without listingId or listingKey")
            return
        }

        do {
            // v6.68.6: The propertyDetail endpoint expects listing_key (hash), not listing_id (MLS number)
            // Prioritize listingKey since it's the correct identifier for the endpoint
            if let listingKey = listingKey, !listingKey.isEmpty {
                print("🔍 [navigateToProperty] Fetching property by listing_key: \(listingKey)")
                #if DEBUG
                debugLog("🔔 Fetching property by listing_key: \(listingKey)")
                #endif
                let property: Property = try await APIClient.shared.request(.propertyDetail(id: listingKey))
                print("🔍 [navigateToProperty] Got property: \(property.id) - \(property.address)")
                #if DEBUG
                debugLog("🔔 Got property: \(property.id) - \(property.address)")
                #endif
                pendingPropertyNavigation = property
                print("🔍 [navigateToProperty] Set pendingPropertyNavigation to: \(property.id)")
                #if DEBUG
                debugLog("🔔 Set pendingPropertyNavigation")
                #endif
                logger.info("Fetched property by listing_key \(listingKey) for navigation")
            } else if let listingId = listingId {
                // Fall back to search by MLS number if no listing_key available
                #if DEBUG
                debugLog("🔔 Fetching property by MLS number via search: \(listingId)")
                #endif
                var searchFilters = PropertySearchFilters()
                searchFilters.mlsNumber = listingId
                searchFilters.perPage = 1
                let data: PropertyListData = try await APIClient.shared.request(
                    .properties(filters: searchFilters)
                )
                if let property = data.listings.first {
                    pendingPropertyNavigation = property
                    logger.info("Fetched property by MLS number \(listingId) for navigation")
                } else {
                    logger.warning("Property not found for MLS number: \(listingId)")
                }
            }
        } catch {
            logger.error("Failed to fetch property for navigation: \(error.localizedDescription)")
        }
    }

    private func setupSearchTextObserver() {
        // Watch for searchText changes and trigger autocomplete
        $searchText
            .debounce(for: .milliseconds(SearchConstants.searchDebounceMs), scheduler: RunLoop.main)
            .removeDuplicates()
            .sink { [weak self] text in
                self?.fetchSuggestions(for: text)
            }
            .store(in: &cancellables)
    }

    // MARK: - Search Methods

    /// Apply filters directly from the modal and trigger search.
    /// This bypasses @Binding timing issues by receiving filters as a parameter.
    /// Apply filters directly and trigger search
    func applyFiltersAndSearch(_ newFilters: PropertySearchFilters) {
        // Apply the filters directly
        filters = newFilters

        // Cancel any existing search and start a new one
        searchTask?.cancel()
        searchTask = Task {
            await search()
        }
    }

    func search(retryCount: Int = 0) async {
        // Note: Don't cancel searchTask here - callers handle cancellation before creating new tasks
        // Cancelling here would cancel the task that called this method

        filters.page = 1
        isLoading = true
        errorMessage = nil
        searchState = .loading

        // Save current map region before search (to restore if 0 results)
        preSearchMapRegion = savedMapRegion

        // Track if this is an exact match search (address or MLS number)
        isExactMatchSearch = filters.address != nil || filters.mlsNumber != nil

        // Create a copy of filters for API call
        var apiFilters = filters
        apiFilters.perPage = SearchConstants.mapPerPage

        // Keep map bounds as a filter in both list and map mode
        // User can clear by searching for a new location or clearing filters

        #if DEBUG
        debugLog("🔍 SEARCH STARTING - isMapMode: \(isMapMode), bounds: \(apiFilters.mapBounds != nil), openHouse: \(filters.openHouseOnly), priceReduced: \(filters.priceReduced), exactMatch: \(isExactMatchSearch)")
        #endif

        do {
            let data: PropertyListData = try await APIClient.shared.request(
                .properties(filters: apiFilters)
            )

            #if DEBUG
            debugLog("🔍 SEARCH GOT RESULTS: \(data.listings.count) properties, total: \(data.total ?? 0)")
            #endif

            // Check if task was cancelled during API call
            guard !Task.isCancelled else {
                logger.debug("Search task cancelled, discarding results")
                return
            }

            // If no results, restore the map region to prevent zoom-out (without animation)
            if data.listings.isEmpty, let regionToRestore = preSearchMapRegion {
                animateMapRegion = false  // No animation to prevent flash
                targetMapRegion = regionToRestore
            }

            // Store all properties with user preferences applied
            let prefilteredProperties = applyUserPreferences(to: data.listings)
            allProperties = prefilteredProperties
            properties = prefilteredProperties
            totalResults = data.total ?? data.listings.count
            matchingCount = data.total ?? data.listings.count

            hasMorePages = data.hasMorePages
            priceStats = data.priceStats

            // Increment version to notify map to clear selection
            propertiesVersion += 1

            // Add to recent searches
            addRecentSearch()

            // Track search for analytics (Sprint 5)
            await trackSearch(resultCount: data.total ?? data.listings.count)

            // Update search state based on results
            if properties.isEmpty {
                searchState = .empty
            } else {
                searchState = .success(count: totalResults)
            }

            logger.info("Loaded \(data.listings.count) properties (total: \(data.total ?? data.listings.count))")

        } catch let error as APIError {
            // Don't retry if cancelled
            guard !Task.isCancelled else { return }

            if retryCount < SearchConstants.maxRetries && shouldRetry(error: error) {
                logger.warning("Search failed, retrying (\(retryCount + 1)/\(SearchConstants.maxRetries))")
                try? await Task.sleep(nanoseconds: SearchConstants.retryDelayNanoseconds * UInt64(retryCount + 1))
                await search(retryCount: retryCount + 1)
                return
            }
            let message = userFriendlyError(error)
            errorMessage = message
            searchState = .error(message: message)
            logger.error("Search failed: \(error.localizedDescription)")
        } catch {
            // Don't retry if cancelled
            guard !Task.isCancelled else { return }

            if retryCount < SearchConstants.maxRetries {
                logger.warning("Search failed, retrying (\(retryCount + 1)/\(SearchConstants.maxRetries))")
                try? await Task.sleep(nanoseconds: SearchConstants.retryDelayNanoseconds * UInt64(retryCount + 1))
                await search(retryCount: retryCount + 1)
                return
            }
            let message = userFriendlyError(error)
            errorMessage = message
            searchState = .error(message: message)
            logger.error("Search failed: \(error.localizedDescription)")
        }

        isLoading = false
    }

    /// Convert API errors to user-friendly messages
    private func userFriendlyError(_ error: Error) -> String {
        if let apiError = error as? APIError {
            switch apiError {
            case .unauthorized:
                return "Please log in to continue"
            case .rateLimited:
                return "Too many requests. Please wait a moment."
            case .serverError:
                return "Our servers are having issues. Please try again."
            case .networkError:
                return "No internet connection. Please check your network."
            default:
                return "Something went wrong. Please try again."
            }
        }
        if (error as NSError).code == NSURLErrorNotConnectedToInternet ||
           (error as NSError).code == NSURLErrorNetworkConnectionLost {
            return "No internet connection. Please check your network."
        }
        return "Unable to load properties. Please try again."
    }

    /// Search multiple polygon shapes and combine results (OR logic)
    /// Each shape is searched separately and results are deduplicated by property ID
    func searchMultipleShapes(_ shapes: [[CLLocationCoordinate2D]]) async {
        guard !shapes.isEmpty else { return }

        filters.page = 1
        isLoading = true
        errorMessage = nil
        searchState = .loading

        // Save current map region before search
        preSearchMapRegion = savedMapRegion

        #if DEBUG
        debugLog("🔍 MULTI-SHAPE SEARCH: \(shapes.count) shapes")
        #endif

        // Collect all properties from all shapes
        var allFoundProperties: [Property] = []
        var seenIds = Set<String>()
        var totalFound = 0

        do {
            // Search each shape separately
            for (index, shape) in shapes.enumerated() {
                guard shape.count >= 3 else { continue }

                // Create filters for this shape
                var apiFilters = filters
                apiFilters.polygonCoordinates = shape
                apiFilters.perPage = SearchConstants.mapPerPage
                apiFilters.mapBounds = nil  // Use polygon, not bounds

                #if DEBUG
                debugLog("🔍 Searching shape \(index + 1)/\(shapes.count) with \(shape.count) vertices")
                let params = apiFilters.toDictionary()
                debugLog("🔍 API params keys: \(params.keys.sorted())")
                if let polygon = params["polygon"] as? [[String: Any]] {
                    debugLog("🔍 Polygon has \(polygon.count) points")
                } else {
                    debugLog("🔍 WARNING: No polygon in params!")
                }
                if params["bounds"] != nil {
                    debugLog("🔍 WARNING: bounds is also set!")
                }
                #endif

                let data: PropertyListData = try await APIClient.shared.request(
                    .properties(filters: apiFilters)
                )

                // Check if cancelled
                guard !Task.isCancelled else {
                    logger.debug("Multi-shape search cancelled")
                    return
                }

                // Add unique properties (deduplicate by ID)
                for property in data.listings {
                    if !seenIds.contains(property.id) {
                        seenIds.insert(property.id)
                        allFoundProperties.append(property)
                    }
                }

                totalFound += data.total ?? data.listings.count

                #if DEBUG
                debugLog("🔍 Shape \(index + 1): found \(data.listings.count) properties, unique total: \(allFoundProperties.count)")
                #endif
            }

            // If no results, restore the map region
            if allFoundProperties.isEmpty, let regionToRestore = preSearchMapRegion {
                animateMapRegion = false
                targetMapRegion = regionToRestore
            }

            // Store combined results
            allProperties = applyUserPreferences(to: allFoundProperties)
            properties = allProperties
            totalResults = allFoundProperties.count
            hasMorePages = false  // Multi-shape doesn't support pagination
            matchingCount = allFoundProperties.count

            // Store all shapes for saved search functionality
            polygonShapes = shapes

            // Keep polygon coordinates set to first shape for bounds protection
            // This prevents updateMapBounds from overriding during map zoom
            if let firstShape = shapes.first {
                filters.polygonCoordinates = firstShape
            }

            // Set timestamp to prevent bounds updates during map zoom animation
            lastPolygonSearchTime = Date()

            // Increment version to notify map to clear selection
            propertiesVersion += 1

            // Update search state
            if properties.isEmpty {
                searchState = .empty
            } else {
                searchState = .success(count: totalResults)
            }

            logger.info("Multi-shape search: found \(allFoundProperties.count) unique properties across \(shapes.count) shapes")

        } catch let error as APIError {
            guard !Task.isCancelled else { return }
            let message = userFriendlyError(error)
            errorMessage = message
            searchState = .error(message: message)
            logger.error("Multi-shape search failed: \(error.localizedDescription)")
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = "Unable to load properties. Please try again."
            searchState = .error(message: errorMessage ?? "Unknown error")
            logger.error("Multi-shape search failed: \(error.localizedDescription)")
        }

        isLoading = false
    }

    /// Clear polygon search state and trigger a bounds-based search
    /// Called when all drawn shapes are deleted or cleared
    func clearPolygonSearch() {
        // Clear polygon-related state
        filters.polygonCoordinates = nil
        polygonShapes = []
        lastPolygonSearchTime = nil

        logger.debug("Polygon search cleared - will use map bounds for next search")

        // Trigger a new search using current map bounds if available
        if let bounds = mapBounds {
            searchTask?.cancel()
            searchTask = Task {
                await searchByMapLocation()
            }
        }
    }

    func loadMore() async {
        guard !isLoadingMore, hasMorePages else { return }

        isLoadingMore = true
        filters.page += 1

        // Create API filters without map bounds
        var apiFilters = filters
        apiFilters.mapBounds = nil

        do {
            let data: PropertyListData = try await APIClient.shared.request(
                .properties(filters: apiFilters)
            )

            let newProperties = applyUserPreferences(to: data.listings)

            // Append to both allProperties and properties
            allProperties.append(contentsOf: newProperties)
            properties.append(contentsOf: newProperties)
            hasMorePages = data.hasMorePages

            logger.info("Loaded more properties, now at \(self.properties.count)")

        } catch {
            filters.page -= 1
            logger.error("Load more failed: \(error.localizedDescription)")
        }

        isLoadingMore = false
    }

    func refresh() async {
        searchTask?.cancel()
        await search()
    }

    // MARK: - Filter Methods

    func applyFilters(_ newFilters: PropertySearchFilters) {
        // Cancel any in-flight search before applying new filters
        searchTask?.cancel()

        filters = newFilters
        filters.page = 1

        // Store reference to allow cancellation
        searchTask = Task {
            await search()
        }
    }

    func clearFilters() {
        searchTask?.cancel()

        filters = PropertySearchFilters()
        mapBounds = nil
        allProperties = []

        searchTask = Task {
            await search()
        }
    }

    func removeFilterChip(_ chipId: String) {
        searchTask?.cancel()

        logger.info("REMOVE CHIP: chipId=\(chipId), cities before=\(self.filters.cities.count)")

        filters.removeFilter(chipId: chipId)

        logger.info("REMOVE CHIP: cities after removeFilter=\(self.filters.cities.count)")

        // FIX: Clear boundaries SYNCHRONOUSLY if no location filters remain
        // This fixes the race condition where async updateCityBoundaries() hasn't completed
        // by the time user switches to map mode
        if filters.cities.isEmpty && filters.neighborhoods.isEmpty && filters.zips.isEmpty {
            logger.info("No location filters remain - clearing cityBoundaries synchronously")
            cityBoundaries = []
            boundariesVersion += 1
        } else {
            // Only call async update if there are still location filters
            updateCityBoundaries()
        }

        searchTask = Task {
            await search()
        }
    }

    // MARK: - Quick Filter Presets

    func toggleNewListingPreset() {
        searchTask?.cancel()

        filters.newListing.toggle()
        if filters.newListing {
            filters.newListingDays = 7
        }

        logger.debug("New Listing preset toggled: \(self.filters.newListing), days: \(self.filters.newListingDays)")

        searchTask = Task {
            await search()
        }
    }

    func togglePriceReducedPreset() {
        cancelAndSearch {
            filters.priceReduced.toggle()
            logger.debug("Price Reduced preset toggled: \(self.filters.priceReduced)")
        }
    }

    func toggleOpenHousePreset() {
        searchTask?.cancel()

        filters.openHouseOnly.toggle()

        #if DEBUG
        debugLog("🏠 OPEN HOUSE TOGGLED: \(filters.openHouseOnly) - calling search()")
        #endif

        searchTask = Task {
            #if DEBUG
            debugLog("🏠 SEARCH TASK STARTED")
            #endif
            await search()
            #if DEBUG
            debugLog("🏠 SEARCH TASK COMPLETED")
            #endif
        }
    }

    func toggleVirtualTourPreset() {
        cancelAndSearch {
            filters.hasVirtualTour.toggle()
            logger.debug("Virtual Tour preset toggled: \(self.filters.hasVirtualTour)")
        }
    }

    func toggleWaterfrontPreset() {
        cancelAndSearch {
            filters.hasWaterfront.toggle()
            logger.debug("Waterfront preset toggled: \(self.filters.hasWaterfront)")
        }
    }

    func togglePoolPreset() {
        cancelAndSearch {
            filters.hasPool.toggle()
            logger.debug("Pool preset toggled: \(self.filters.hasPool)")
        }
    }

    // MARK: - City Boundary Visualization

    /// Update city boundary overlays based on current city filters
    func updateCityBoundaries() {
        logger.info("updateCityBoundaries called. Current cities: \(self.filters.cities.count)")
        Task {
            let boundaries = await CityBoundaryService.shared.boundariesForCities(filters.cities)
            await MainActor.run {
                self.logger.info("Setting cityBoundaries: \(boundaries.count) polygons (was \(self.cityBoundaries.count))")
                self.cityBoundaries = boundaries
                self.boundariesVersion += 1
                self.logger.info("boundariesVersion now: \(self.boundariesVersion)")
            }
        }
    }

    // MARK: - Neighborhood Analytics (for zoomed-out price overlays)

    /// Fetch neighborhood analytics for the visible map bounds
    func fetchNeighborhoodAnalytics(for bounds: MapBounds) {
        Task {
            do {
                let response: NeighborhoodAnalyticsResponse = try await APIClient.shared.request(
                    .neighborhoodAnalytics(bounds: bounds)
                )

                await MainActor.run {
                    self.neighborhoodAnalytics = response.neighborhoods
                    self.logger.info("Loaded \(response.neighborhoods.count) neighborhood analytics")
                }
            } catch {
                logger.error("Failed to fetch neighborhood analytics: \(error.localizedDescription)")
            }
        }
    }

    /// Fetch schools for map overlay when "Show Schools" toggle is enabled
    func fetchMapSchools(for bounds: MapBounds) {
        Task {
            do {
                let data = try await SchoolService.shared.fetchMapSchools(bounds: bounds)

                await MainActor.run {
                    self.mapSchools = data.schools
                    self.logger.info("Loaded \(data.schools.count) schools for map")
                }
            } catch {
                logger.error("Failed to fetch map schools: \(error.localizedDescription)")
            }
        }
    }

    /// Fetch transit stations for map overlay when "Show Transit" toggle is enabled
    func fetchMapTransitStations(for region: MKCoordinateRegion) {
        Task {
            let stations = await TransitService.shared.fetchStations(in: region)

            // Also fetch routes if not already loaded (routes don't change with region)
            let routes: [TransitRoute]
            if self.mapTransitRoutes.isEmpty {
                routes = await TransitService.shared.fetchRoutes()
            } else {
                routes = self.mapTransitRoutes
            }

            await MainActor.run {
                self.mapTransitStations = stations
                if self.mapTransitRoutes.isEmpty {
                    self.mapTransitRoutes = routes
                    self.transitRoutesVersion += 1  // Force UI update
                    print("🚇 ViewModel: Set mapTransitRoutes with \(routes.count) routes, version=\(self.transitRoutesVersion)")
                    self.logger.info("Loaded \(routes.count) transit routes for map")
                }
                print("🚇 ViewModel: Set mapTransitStations with \(stations.count) stations")
                self.logger.info("Loaded \(stations.count) transit stations for map")
            }
        }
    }

    /// Clear transit data when toggle is disabled
    func clearTransitData() {
        mapTransitStations = []
        mapTransitRoutes = []
    }

    // Fetch count for filter preview (debounced)
    func fetchMatchingCount(for previewFilters: PropertySearchFilters) {
        countTask?.cancel()

        countTask = Task {
            try? await Task.sleep(nanoseconds: SearchConstants.filterCountDebounceNanoseconds)

            guard !Task.isCancelled else { return }

            isLoadingCount = true

            do {
                var countFilters = previewFilters
                countFilters.perPage = SearchConstants.countOnlyPerPage

                let data: PropertyListData = try await APIClient.shared.request(
                    .properties(filters: countFilters)
                )

                await MainActor.run {
                    self.matchingCount = data.total ?? data.listings.count
                    self.isLoadingCount = false
                }
            } catch {
                await MainActor.run {
                    self.isLoadingCount = false
                }
            }
        }
    }

    // Fetch count for filter preview (returns value for AdvancedFilterModal)
    func fetchCount(for previewFilters: PropertySearchFilters) async -> Int? {
        do {
            var countFilters = previewFilters
            countFilters.perPage = SearchConstants.countOnlyPerPage

            let data: PropertyListData = try await APIClient.shared.request(
                .properties(filters: countFilters)
            )

            return data.total ?? data.listings.count
        } catch {
            logger.error("Failed to fetch count: \(error.localizedDescription)")
            return nil
        }
    }

    // MARK: - Search Autocomplete

    func fetchSuggestions(for query: String) {
        suggestionsTask?.cancel()

        // v321: Trim whitespace to prevent whitespace-only queries from hitting API
        let trimmedQuery = query.trimmingCharacters(in: .whitespaces)

        logger.debug("Fetching suggestions for query: '\(trimmedQuery)'")

        guard trimmedQuery.count >= SearchConstants.minAutocompleteQueryLength else {
            searchSuggestions = []
            lastAutocompleteQuery = ""
            logger.debug("Query too short, clearing suggestions")
            return
        }

        // v321: Skip duplicate queries (throttling)
        guard trimmedQuery != lastAutocompleteQuery else {
            logger.debug("Skipping duplicate autocomplete query: '\(trimmedQuery)'")
            return
        }

        // v321: Increment version and capture for this request
        autocompleteQueryVersion += 1
        let requestVersion = autocompleteQueryVersion
        lastAutocompleteQuery = trimmedQuery

        suggestionsTask = Task {
            try? await Task.sleep(nanoseconds: SearchConstants.searchDebounceNanoseconds)

            guard !Task.isCancelled else {
                logger.debug("Suggestions task cancelled after debounce")
                return
            }

            // v321: Check if this request is still the latest before making API call
            guard requestVersion == self.autocompleteQueryVersion else {
                logger.debug("Skipping stale autocomplete request for '\(trimmedQuery)' (version \(requestVersion) != \(self.autocompleteQueryVersion))")
                return
            }

            logger.debug("Making autocomplete API call for: '\(trimmedQuery)'")

            do {
                // Fetch suggestions from API
                // Production API returns: { success: true, data: [{value, type, icon}, ...] }
                // APIClient unwraps the outer { success, data } wrapper,
                // so we receive the array of suggestions directly
                print("🔍 [fetchSuggestions] Making API call for: '\(trimmedQuery)'")
                let apiSuggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(
                    .autocomplete(term: trimmedQuery)
                )
                print("🔍 [fetchSuggestions] Got \(apiSuggestions.count) raw suggestions from API")

                // Debug: print each raw suggestion
                for (index, sug) in apiSuggestions.enumerated() {
                    print("🔍 [fetchSuggestions] Raw[\(index)]: type=\(sug.type), value=\(sug.value), listing_key=\(sug.listingKey ?? "nil")")
                }

                guard !Task.isCancelled else { return }

                // v321: Verify this is still the latest request before updating UI
                guard requestVersion == self.autocompleteQueryVersion else {
                    logger.debug("Discarding stale autocomplete results for '\(trimmedQuery)' (version \(requestVersion) != \(self.autocompleteQueryVersion))")
                    return
                }

                let suggestions = apiSuggestions.map { $0.toSearchSuggestion() }
                print("🔍 [fetchSuggestions] Converted to \(suggestions.count) SearchSuggestions")
                // Debug: verify listingKey is preserved after conversion
                for (index, sug) in suggestions.enumerated() {
                    print("🔍 [fetchSuggestions] Converted[\(index)]: type=\(sug.type), listingKey=\(sug.listingKey ?? "nil")")
                }
                logger.debug("Got \(suggestions.count) suggestions from API")

                // Update on main thread - always set if we got here (not cancelled and not stale)
                await MainActor.run {
                    self.searchSuggestions = suggestions
                    self.logger.debug("Set \(suggestions.count) autocomplete suggestions for '\(query)'")
                }

            } catch {
                guard !Task.isCancelled else { return }

                // v321: Only clear on error if this is still the latest request
                guard requestVersion == self.autocompleteQueryVersion else { return }

                // Log error and show empty state (no hardcoded fallback)
                logger.warning("Autocomplete API failed: \(error.localizedDescription)")
                await MainActor.run {
                    self.searchSuggestions = []
                }
            }
        }
    }

    func applySuggestion(_ suggestion: SearchSuggestion) {
        logger.debug("Applying suggestion: \(suggestion.displayText), type: \(String(describing: suggestion.type)), value: \(suggestion.value)")

        // v6.68.11: Enhanced debug logging for direct lookup troubleshooting
        print("🔍 [applySuggestion] START - type: \(suggestion.type), value: \(suggestion.value)")
        print("🔍 [applySuggestion] listingId: \(suggestion.listingId ?? "nil")")
        print("🔍 [applySuggestion] listingKey: \(suggestion.listingKey ?? "nil")")

        searchTask?.cancel()

        // v6.68.6: For Address and MLS Number suggestions, use direct navigation if listingKey is available
        // This skips the search entirely and opens the property detail directly
        if (suggestion.type == .address || suggestion.type == .mlsNumber),
           let listingKey = suggestion.listingKey, !listingKey.isEmpty {
            print("🔍 [applySuggestion] DIRECT NAVIGATION PATH - listingKey: \(listingKey)")
            logger.info("Direct navigation to property with listingKey: \(listingKey)")

            // Clear search text since we're navigating directly
            searchText = ""
            searchSuggestions = []

            // v6.68.12: Use navigationTask (not searchTask) to prevent cancellation by map bounds search
            // When modal dismisses, map becomes visible and triggers updateMapBounds() which cancels searchTask
            navigationTask?.cancel()
            navigationTask = Task {
                await navigateToProperty(listingId: suggestion.listingId, listingKey: listingKey)
            }
            return
        }

        switch suggestion.type {
        case .city:
            filters.cities.insert(suggestion.value)
            // v336: Clear map bounds so city search isn't constrained by previous map view position
            // Without this, searching for "Reading" while Boston bounds are active returns 0 results
            filters.mapBounds = nil
            mapBounds = nil
            logger.debug("Added city filter: \(suggestion.value)")
        case .neighborhood:
            filters.neighborhoods.insert(suggestion.value)
            // v336: Clear map bounds so neighborhood search isn't constrained by previous map view
            filters.mapBounds = nil
            mapBounds = nil
            logger.debug("Added neighborhood filter: \(suggestion.value)")
        case .zip:
            filters.zips.insert(suggestion.value)
            // v336: Clear map bounds so ZIP search isn't constrained by previous map view
            filters.mapBounds = nil
            mapBounds = nil
            logger.debug("Added zip filter: \(suggestion.value)")
        case .mlsNumber:
            // v321: Reset all filters when searching for specific MLS number
            // This ensures the property is found regardless of any active filters
            let currentListingType = filters.listingType
            filters = PropertySearchFilters()
            filters.listingType = currentListingType  // Keep the listing type (Buy/Rent)
            filters.mlsNumber = suggestion.value
            filters.mapBounds = nil
            mapBounds = nil
            // Clear any active status filter constraints - let server return any status
            filters.statuses = []
            logger.debug("Set MLS filter with reset filters: \(suggestion.value)")
        case .address:
            // v321: Reset all filters when searching for specific address
            // This ensures the property is found regardless of any active filters
            let currentListingType = filters.listingType
            filters = PropertySearchFilters()
            filters.listingType = currentListingType  // Keep the listing type (Buy/Rent)
            filters.address = suggestion.value
            filters.mapBounds = nil
            mapBounds = nil
            // Clear any active status filter constraints - let server return any status
            filters.statuses = []
            logger.debug("Set address filter with reset filters: \(suggestion.value)")
        case .streetName:
            // v321: Reset all filters when searching for specific street
            // This ensures properties on the street are found regardless of any active filters
            let currentListingType = filters.listingType
            filters = PropertySearchFilters()
            filters.listingType = currentListingType  // Keep the listing type (Buy/Rent)
            filters.streetName = suggestion.value
            filters.mapBounds = nil
            mapBounds = nil
            // Clear any active status filter constraints - let server return any status
            filters.statuses = []
            logger.debug("Set street name filter with reset filters: \(suggestion.value)")
        }

        // Trigger map zoom for location-based suggestions (enterprise UX behavior)
        if [.city, .neighborhood, .zip, .address, .streetName].contains(suggestion.type) {
            Task {
                if let region = await GeocodingService.shared.regionForLocation(
                    suggestion.value,
                    type: suggestion.type
                ) {
                    await MainActor.run {
                        self.targetMapRegion = region
                        self.logger.info("Map will zoom to \(suggestion.value)")
                    }
                }
            }
        }

        // Update city boundary overlays when cities are added
        if suggestion.type == .city {
            updateCityBoundaries()
        }

        // Set cooldown to prevent bounds updates during map zoom animation
        // This fixes the race condition where updateMapBounds() overrides location filter results
        if [.city, .neighborhood, .zip, .address, .streetName].contains(suggestion.type) {
            lastLocationFilterSearchTime = Date()
        }

        logger.debug("Starting search after applying suggestion")

        searchTask = Task {
            await search()
        }
    }

    func performSearch(_ text: String) {
        searchTask?.cancel()

        filters.searchText = text

        searchTask = Task {
            await search()
        }
    }

    // MARK: - Recent Searches

    private func addRecentSearch() {
        let displayText = RecentSearch.generateDisplayText(from: filters)
        preferences.addRecentSearch(displayText: displayText, filters: filters)
        recentSearches = preferences.recentSearches
        savePreferences()
        logger.debug("Added recent search: \(displayText)")
    }

    func restoreRecentSearch(_ search: RecentSearch) {
        // Apply the saved filters
        filters = search.filters.toFilters()
        searchText = search.displayText

        // Trigger search
        Task {
            await self.search()
        }

        logger.debug("Restored recent search: \(search.displayText)")
    }

    func clearRecentSearches() {
        preferences.recentSearches = []
        recentSearches = []
        savePreferences()
    }

    // MARK: - Favorites

    func toggleFavorite(for property: Property) async {
        // Prevent duplicate taps while loading
        guard !favoriteLoadingIds.contains(property.id) else { return }

        // Mark as loading
        favoriteLoadingIds.insert(property.id)
        defer { favoriteLoadingIds.remove(property.id) }

        // Check if property is in search results
        let index = properties.firstIndex(where: { $0.id == property.id })

        // Determine current favorite state from preferences (works even if not in search results)
        let wasFavorite = preferences.likedPropertyIds.contains(property.id)

        // Update search results if property is there
        if let index = index {
            properties[index].isFavorite.toggle()
        }

        // Update preferences
        if wasFavorite {
            preferences.likedPropertyIds.remove(property.id)
        } else {
            preferences.likedPropertyIds.insert(property.id)
        }
        savePreferences()

        do {
            if wasFavorite {
                try await APIClient.shared.requestWithoutResponse(.removeFavorite(listingId: property.id))
                // Remove from favoriteProperties array immediately
                favoriteProperties.removeAll { $0.id == property.id }
                logger.info("Property unfavorited: \(property.id)")
            } else {
                try await APIClient.shared.requestWithoutResponse(.addFavorite(listingId: property.id))
                // Add to favoriteProperties array immediately
                var favoriteProperty = property
                favoriteProperty.isFavorite = true
                favoriteProperties.insert(favoriteProperty, at: 0)
                logger.info("Property favorited: \(property.id)")
            }

            // Track favorite action for analytics (Sprint 5)
            await trackFavorite(listingKey: property.id, added: !wasFavorite, city: property.city)
        } catch {
            // Revert on failure
            if let index = index {
                properties[index].isFavorite = wasFavorite
            }
            if wasFavorite {
                preferences.likedPropertyIds.insert(property.id)
            } else {
                preferences.likedPropertyIds.remove(property.id)
            }
            savePreferences()
            logger.error("Toggle favorite failed: \(error.localizedDescription)")

            // Show error toast to user so they know the action failed
            ToastManager.shared.error("Failed to save favorite. Please try again.")
        }
    }

    /// Load all favorite properties from server
    func loadFavoriteProperties(forceRefresh: Bool = false) async {
        guard !isLoadingFavorites else { return }

        isLoadingFavorites = true

        do {
            let response: FavoritesResponse = try await APIClient.shared.request(.favorites)
            favoriteProperties = response.properties.map { property in
                var p = property
                p.isFavorite = true
                return p
            }

            // Update local preferences to match server
            preferences.likedPropertyIds = Set(response.properties.map { $0.id })
            savePreferences()

            logger.info("Loaded \(response.count) favorite properties")
        } catch {
            logger.error("Failed to load favorite properties: \(error.localizedDescription)")
        }

        isLoadingFavorites = false
    }

    /// Toggle hidden state for a property (syncs with server)
    func toggleHidden(for property: Property) async {
        // Prevent duplicate taps while loading
        guard !hiddenLoadingIds.contains(property.id) else { return }

        // Mark as loading
        hiddenLoadingIds.insert(property.id)
        defer { hiddenLoadingIds.remove(property.id) }

        // Optimistic update - immediately remove from search results
        let wasHidden = preferences.hiddenPropertyIds.contains(property.id)

        if !wasHidden {
            // Hiding: remove from search results, add to hidden list
            preferences.hiddenPropertyIds.insert(property.id)
            properties.removeAll { $0.id == property.id }
            allProperties.removeAll { $0.id == property.id }
            propertiesVersion += 1
        } else {
            // Unhiding: remove from hidden IDs (will appear in next search)
            preferences.hiddenPropertyIds.remove(property.id)
        }
        savePreferences()

        // Sync with server
        do {
            if !wasHidden {
                // Hide property
                try await APIClient.shared.requestWithoutResponse(.hideProperty(listingId: property.id))
                // Add to hiddenProperties array immediately
                hiddenProperties.insert(property, at: 0)
                logger.info("Property hidden: \(property.id)")
            } else {
                // Unhide property
                try await APIClient.shared.requestWithoutResponse(.unhideProperty(listingId: property.id))
                // Remove from hiddenProperties array immediately
                hiddenProperties.removeAll { $0.id == property.id }
                logger.info("Property unhidden: \(property.id)")
            }

            // Track hidden action for analytics (Sprint 5)
            await trackHidden(listingKey: property.id, added: !wasHidden, city: property.city)
        } catch {
            // Revert on failure
            if !wasHidden {
                preferences.hiddenPropertyIds.remove(property.id)
                hiddenProperties.removeAll { $0.id == property.id }
            } else {
                preferences.hiddenPropertyIds.insert(property.id)
            }
            savePreferences()
            logger.error("Toggle hidden failed: \(error.localizedDescription)")

            // Show error toast to user so they know the action failed
            ToastManager.shared.error("Failed to update hidden status. Please try again.")
        }
    }

    /// Unhide a property by ID (syncs with server)
    func unhideProperty(id: String) async {
        // Prevent duplicate taps while loading
        guard !hiddenLoadingIds.contains(id) else { return }

        // Mark as loading
        hiddenLoadingIds.insert(id)
        defer { hiddenLoadingIds.remove(id) }

        preferences.hiddenPropertyIds.remove(id)

        // Remove from local hidden properties list
        hiddenProperties.removeAll { $0.id == id }

        savePreferences()

        // Sync with server
        do {
            try await APIClient.shared.requestWithoutResponse(.unhideProperty(listingId: id))
            logger.info("Property unhidden: \(id)")
        } catch {
            // Revert on failure
            preferences.hiddenPropertyIds.insert(id)
            savePreferences()
            logger.error("Unhide property failed: \(error.localizedDescription)")

            // Show error toast to user
            ToastManager.shared.error("Failed to unhide property. Please try again.")
        }
    }

    /// Load all hidden properties from server
    func loadHiddenProperties(forceRefresh: Bool = false) async {
        guard !isLoadingHidden else { return }

        isLoadingHidden = true

        do {
            let response: HiddenPropertiesResponse = try await APIClient.shared.request(.hidden)
            hiddenProperties = response.properties

            // Update local preferences to match server
            preferences.hiddenPropertyIds = Set(response.properties.map { $0.id })
            savePreferences()

            logger.info("Loaded \(response.count) hidden properties")
        } catch {
            logger.error("Failed to load hidden properties: \(error.localizedDescription)")
        }

        isLoadingHidden = false
    }

    // MARK: - Saved Searches

    func saveCurrentSearch(name: String, notificationFrequency: NotificationFrequency = .daily) async {
        // Create saved search on server
        savedSearchSyncState = .saving
        do {
            let created = try await SavedSearchService.shared.createSearch(
                name: name,
                description: nil,
                filters: filters,
                shapes: polygonShapes,
                frequency: notificationFrequency
            )
            savedSearches.insert(created, at: 0)
            savedSearchSyncState = .idle
        } catch {
            savedSearchSyncState = .error(error.userFriendlyMessage)
            logger.error("Failed to save search: \(error.localizedDescription)")
        }
    }

    /// Delete a saved search from server
    func deleteSavedSearch(_ search: SavedSearch) async {
        do {
            try await SavedSearchService.shared.deleteSearch(id: search.id)
            savedSearches.removeAll { $0.id == search.id }
        } catch {
            savedSearchSyncState = .error(error.userFriendlyMessage)
            logger.error("Failed to delete search: \(error.localizedDescription)")
        }
    }

    /// Duplicate a saved search with a new name
    func duplicateSavedSearch(_ search: SavedSearch) async {
        savedSearchSyncState = .saving
        do {
            // Get filters from the original search
            let originalFilters = search.toPropertySearchFilters()

            // Create a new name with "(Copy)" suffix
            let newName = "\(search.name) (Copy)"

            // Create the duplicate search
            let created = try await SavedSearchService.shared.createSearch(
                name: newName,
                description: search.description,
                filters: originalFilters,
                frequency: search.notificationFrequency
            )
            savedSearches.insert(created, at: 0)
            savedSearchSyncState = .idle
            logger.debug("Duplicated saved search '\(search.name)' as '\(newName)'")
        } catch {
            savedSearchSyncState = .error(error.userFriendlyMessage)
            logger.error("Failed to duplicate search: \(error.localizedDescription)")
        }
    }

    /// Apply a saved search's filters and perform search
    func applySavedSearch(_ search: SavedSearch) {
        searchTask?.cancel()

        // Convert server filters to PropertySearchFilters
        let newFilters = search.toPropertySearchFilters()
        logger.debug("Applying saved search '\(search.name)' with filters: propertyTypes=\(newFilters.propertyTypes), schoolGrade=\(String(describing: newFilters.schoolGrade))")
        filters = newFilters

        // Restore polygon shapes from saved search
        let savedPolygonShapes = search.toPolygonCoordinates()
        polygonShapes = savedPolygonShapes

        // Set map region based on saved search location data
        setMapRegionForSavedSearch(newFilters)

        // Load city boundaries if cities are present
        if !newFilters.cities.isEmpty {
            updateCityBoundaries()
        } else {
            // Clear boundaries if no cities
            cityBoundaries = []
            boundariesVersion += 1
        }

        // Use multi-shape search if multiple polygons, otherwise regular search
        if savedPolygonShapes.count > 1 {
            searchTask = Task {
                await self.searchMultipleShapes(savedPolygonShapes)
                logger.debug("Multi-shape search completed for saved search '\(search.name)', found \(self.properties.count) properties")
            }
        } else {
            searchTask = Task {
                await self.search()
                logger.debug("Search completed for saved search '\(search.name)', found \(self.properties.count) properties")
            }
        }
    }

    /// Set map region based on saved search filters (polygon, bounds, or city)
    private func setMapRegionForSavedSearch(_ filters: PropertySearchFilters) {
        // Priority 1: Multiple polygon shapes - zoom to fit all shapes
        if !polygonShapes.isEmpty {
            let allCoords = polygonShapes.flatMap { $0 }
            let lats = allCoords.map { $0.latitude }
            let lngs = allCoords.map { $0.longitude }

            if let minLat = lats.min(), let maxLat = lats.max(),
               let minLng = lngs.min(), let maxLng = lngs.max() {
                let center = CLLocationCoordinate2D(
                    latitude: (minLat + maxLat) / 2,
                    longitude: (minLng + maxLng) / 2
                )
                let span = MKCoordinateSpan(
                    latitudeDelta: (maxLat - minLat) * 1.3,  // 30% padding for multiple shapes
                    longitudeDelta: (maxLng - minLng) * 1.3
                )
                animateMapRegion = true
                targetMapRegion = MKCoordinateRegion(center: center, span: span)
                logger.debug("Set map region from \(self.polygonShapes.count) polygon shapes")
                return
            }
        }

        // Priority 2: Single polygon coordinates - zoom to fit the drawn area
        if let polygon = filters.polygonCoordinates, !polygon.isEmpty {
            let lats = polygon.map { $0.latitude }
            let lngs = polygon.map { $0.longitude }

            if let minLat = lats.min(), let maxLat = lats.max(),
               let minLng = lngs.min(), let maxLng = lngs.max() {
                let center = CLLocationCoordinate2D(
                    latitude: (minLat + maxLat) / 2,
                    longitude: (minLng + maxLng) / 2
                )
                let span = MKCoordinateSpan(
                    latitudeDelta: (maxLat - minLat) * 1.2,  // 20% padding
                    longitudeDelta: (maxLng - minLng) * 1.2
                )
                animateMapRegion = true
                targetMapRegion = MKCoordinateRegion(center: center, span: span)
                logger.debug("Set map region from polygon: \(polygon.count) points")
                return
            }
        }

        // Priority 2: Map bounds - zoom to the saved bounds
        if let bounds = filters.mapBounds {
            let center = CLLocationCoordinate2D(
                latitude: (bounds.north + bounds.south) / 2,
                longitude: (bounds.east + bounds.west) / 2
            )
            let span = MKCoordinateSpan(
                latitudeDelta: bounds.north - bounds.south,
                longitudeDelta: bounds.east - bounds.west
            )
            animateMapRegion = true
            targetMapRegion = MKCoordinateRegion(center: center, span: span)
            logger.debug("Set map region from bounds")
            return
        }

        // Priority 3: Cities - geocode the first city and zoom to it
        if let firstCity = filters.cities.first {
            Task {
                if let region = await GeocodingService.shared.regionForLocation(firstCity, type: .city) {
                    await MainActor.run {
                        self.animateMapRegion = true
                        self.targetMapRegion = region
                        self.logger.debug("Set map region from city: \(firstCity)")
                    }
                }
            }
        }
    }

    /// Update notification frequency for a saved search
    func updateSavedSearchNotification(_ search: SavedSearch, frequency: NotificationFrequency) async {
        do {
            let updated = try await SavedSearchService.shared.updateNotificationFrequency(
                id: search.id,
                frequency: frequency,
                currentUpdatedAt: search.updatedAt
            )
            if let index = savedSearches.firstIndex(where: { $0.id == search.id }) {
                savedSearches[index] = updated
            }
        } catch {
            if case SavedSearchError.serverConflict(let serverVersion) = error {
                // Use server version
                if let index = savedSearches.firstIndex(where: { $0.id == search.id }) {
                    savedSearches[index] = serverVersion
                }
            } else {
                savedSearchSyncState = .error(error.userFriendlyMessage)
                logger.error("Failed to update notification: \(error.localizedDescription)")
            }
        }
    }

    /// Toggle active state of a saved search
    func toggleSavedSearchActive(_ search: SavedSearch) async {
        do {
            let updated = try await SavedSearchService.shared.toggleActive(
                id: search.id,
                currentUpdatedAt: search.updatedAt
            )
            if let index = savedSearches.firstIndex(where: { $0.id == search.id }) {
                savedSearches[index] = updated
            }
        } catch {
            if case SavedSearchError.serverConflict(let serverVersion) = error {
                if let index = savedSearches.firstIndex(where: { $0.id == search.id }) {
                    savedSearches[index] = serverVersion
                }
            } else {
                savedSearchSyncState = .error(error.userFriendlyMessage)
                logger.error("Failed to toggle active: \(error.localizedDescription)")
            }
        }
    }

    /// Load saved searches from server
    func loadSavedSearchesFromServer(forceRefresh: Bool = false) async {
        savedSearchSyncState = .loading
        do {
            savedSearches = try await SavedSearchService.shared.fetchSearches(forceRefresh: forceRefresh)
            savedSearchSyncState = .idle
        } catch {
            savedSearchSyncState = .error(error.userFriendlyMessage)
            logger.error("Failed to load saved searches: \(error.localizedDescription)")
        }
    }

    /// Clear sync error state
    func clearSavedSearchError() {
        savedSearchSyncState = .idle
    }

    // MARK: - Map Methods

    /// Save the current map region without triggering a search
    /// Used to preserve region for restoration when filters return 0 results
    func saveCurrentMapRegion(_ bounds: MapBounds) {
        let latSpan = abs(bounds.north - bounds.south)
        let lngSpan = abs(bounds.east - bounds.west)

        // Skip if bounds are invalid
        guard latSpan > 0.0001 && latSpan < 180 &&
              lngSpan > 0.0001 && lngSpan < 360 else { return }

        let centerLat = (bounds.north + bounds.south) / 2
        let centerLng = (bounds.east + bounds.west) / 2
        savedMapRegion = MKCoordinateRegion(
            center: CLLocationCoordinate2D(latitude: centerLat, longitude: centerLng),
            span: MKCoordinateSpan(latitudeDelta: latSpan, longitudeDelta: lngSpan)
        )
    }

    func updateMapBounds(_ bounds: MapBounds) {
        // Skip if we're within the cooldown period after a polygon search
        // This prevents the map zoom animation from triggering a bounds search that overwrites polygon results
        if let lastSearch = lastPolygonSearchTime {
            let elapsed = Date().timeIntervalSince(lastSearch)
            if elapsed < polygonSearchCooldownSeconds {
                logger.debug("Skipping bounds update - polygon search cooldown active (\(String(format: "%.1f", elapsed))s elapsed)")
                return
            }
        }

        // Skip if we're within the cooldown period after a location filter search (city/neighborhood/zip/address/streetName)
        // This prevents the map zoom animation from triggering a bounds search that overwrites location filter results
        if let lastSearch = lastLocationFilterSearchTime {
            let elapsed = Date().timeIntervalSince(lastSearch)
            if elapsed < locationFilterCooldownSeconds {
                logger.debug("Skipping bounds update - location filter cooldown active (\(String(format: "%.1f", elapsed))s elapsed)")
                return
            }
        }

        // Skip if polygon coordinates are set - user is in draw search mode
        if let polygon = filters.polygonCoordinates, !polygon.isEmpty {
            logger.debug("Skipping bounds update - polygon filter is active")
            return
        }

        // Validate bounds - skip if invalid or too small (initial load issue)
        let latSpan = abs(bounds.north - bounds.south)
        let lngSpan = abs(bounds.east - bounds.west)

        // Skip if bounds are invalid or unreasonably small/large
        guard latSpan > 0.0001 && latSpan < 180 &&
              lngSpan > 0.0001 && lngSpan < 360 else {
            logger.debug("Skipping invalid map bounds: lat=\(latSpan), lng=\(lngSpan)")
            return
        }

        mapBounds = bounds

        // Save region for restoration when switching between list/map modes
        let centerLat = (bounds.north + bounds.south) / 2
        let centerLng = (bounds.east + bounds.west) / 2
        savedMapRegion = MKCoordinateRegion(
            center: CLLocationCoordinate2D(latitude: centerLat, longitude: centerLng),
            span: MKCoordinateSpan(latitudeDelta: latSpan, longitudeDelta: lngSpan)
        )

        // Use bounds directly - API supports bounds=south,west,north,east
        filters.mapBounds = bounds

        logger.debug("Map search: bounds=(N:\(bounds.north), S:\(bounds.south), E:\(bounds.east), W:\(bounds.west))")

        // Throttle API requests
        let now = Date()
        guard now.timeIntervalSince(lastRequestTime) >= requestInterval else { return }
        lastRequestTime = now

        searchTask?.cancel()
        searchTask = Task {
            await searchByMapLocation()
        }
    }

    // Search by map location using bounds
    func searchByMapLocation() async {
        filters.page = 1
        isLoading = true
        errorMessage = nil

        // Save current region before search (to restore if 0 results)
        let regionBeforeSearch = savedMapRegion

        var apiFilters = filters
        apiFilters.perPage = SearchConstants.mapPerPage

        do {
            let data: PropertyListData = try await APIClient.shared.request(
                .properties(filters: apiFilters)
            )

            // Check if task was cancelled during API call
            guard !Task.isCancelled else {
                logger.debug("Map search task cancelled, discarding results")
                return
            }

            // If no results, restore the map region to prevent zoom-out (without animation)
            if data.listings.isEmpty, let regionToRestore = regionBeforeSearch {
                animateMapRegion = false  // No animation to prevent flash
                targetMapRegion = regionToRestore
            }

            let prefilteredProperties = applyUserPreferences(to: data.listings)
            properties = prefilteredProperties
            allProperties = properties
            totalResults = data.total ?? data.listings.count
            matchingCount = data.total ?? data.listings.count
            hasMorePages = data.hasMorePages
            logger.info("Map search loaded \(data.listings.count) properties (total: \(data.total ?? data.listings.count))")
            priceStats = data.priceStats

            // Increment version to notify map to clear selection
            propertiesVersion += 1

        } catch let error as APIError {
            guard !Task.isCancelled else { return }
            errorMessage = error.errorDescription
            logger.error("Map search failed: \(error.localizedDescription)")
        } catch {
            guard !Task.isCancelled else { return }
            errorMessage = "Failed to load properties"
            logger.error("Map search failed: \(error.localizedDescription)")
        }

        isLoading = false
    }

    // Load more properties for map view
    func loadMoreForMap() async {
        guard hasMorePages, !isLoadingMore else { return }

        isLoadingMore = true
        filters.page += 1

        var apiFilters = filters
        apiFilters.perPage = SearchConstants.mapPerPage

        do {
            let data: PropertyListData = try await APIClient.shared.request(
                .properties(filters: apiFilters)
            )

            // Append new properties with user preferences applied
            let newProperties = applyUserPreferences(to: data.listings)
            allProperties.append(contentsOf: newProperties)
            properties.append(contentsOf: newProperties)
            hasMorePages = data.hasMorePages

            let totalCount = allProperties.count
            logger.info("Loaded more: now have \(totalCount) total properties")

        } catch {
            logger.error("Failed to load more properties: \(error.localizedDescription)")
        }

        isLoadingMore = false
    }

    func setMapMode(_ enabled: Bool) {
        isMapMode = enabled
        preferences.preferredView = enabled ? .map : .list
        savePreferences()

        // Restore map region when switching back to map mode
        // v336: Only restore saved region if no targetMapRegion is pending (e.g., from a city search)
        // This ensures that if user searched for "Reading" while in list view, the map pans to Reading
        if enabled, let savedRegion = savedMapRegion, targetMapRegion == nil {
            targetMapRegion = savedRegion
        }

        // Refresh search when switching to list mode to apply map bounds filter
        if !enabled && filters.mapBounds != nil {
            searchTask?.cancel()
            searchTask = Task {
                await search()
            }
        }
    }

    // MARK: - Sorting

    func setSort(_ option: SortOption) {
        searchTask?.cancel()

        filters.sort = option
        preferences.preferredSort = option
        savePreferences()

        searchTask = Task {
            await search()
        }
    }

    // MARK: - Helper Properties

    var activeFilterCount: Int {
        filters.activeFilterCount
    }

    var activeFilterChips: [FilterChip] {
        filters.activeFilterChips
    }

    var filterSummary: String {
        var parts: [String] = []

        if !filters.cities.isEmpty {
            parts.append(filters.cities.joined(separator: ", "))
        }

        if !filters.beds.isEmpty {
            let bedsStr = filters.beds.sorted().map { $0 == 5 ? "5+" : "\($0)" }.joined(separator: ",")
            parts.append("\(bedsStr) beds")
        }

        if let minBaths = filters.minBaths {
            parts.append("\(minBaths.formatted())+ baths")
        }

        if let maxPrice = filters.maxPrice {
            let formatter = NumberFormatter()
            formatter.numberStyle = .currency
            formatter.maximumFractionDigits = 0
            if let formatted = formatter.string(from: NSNumber(value: maxPrice)) {
                parts.append("Under \(formatted)")
            }
        }

        return parts.isEmpty ? "All Properties" : parts.joined(separator: " · ")
    }

    // MARK: - Private Methods

    private func applyUserPreferences(to listings: [Property]) -> [Property] {
        var result = listings

        // Apply hidden filter
        result = result.filter { !preferences.hiddenPropertyIds.contains($0.id) }

        // Apply favorite status
        result = result.map { property in
            var p = property
            p.isFavorite = preferences.likedPropertyIds.contains(property.id)
            return p
        }

        return result
    }

    private func shouldRetry(error: APIError) -> Bool {
        switch error {
        case .networkError:
            return true
        case .httpError(let statusCode) where statusCode >= 500:
            return true // Retry on server errors
        default:
            return false
        }
    }

    // MARK: - Persistence

    private func loadPreferences() {
        if let data = UserDefaults.standard.data(forKey: "userPropertyPreferences"),
           let prefs = try? JSONDecoder().decode(UserPropertyPreferences.self, from: data) {
            preferences = prefs
            recentSearches = prefs.recentSearches
            filters.sort = prefs.preferredSort
            isMapMode = prefs.preferredView == .map
        }
    }

    private func savePreferences() {
        if let data = try? JSONEncoder().encode(preferences) {
            UserDefaults.standard.set(data, forKey: "userPropertyPreferences")
        }
    }

    // MARK: - Recently Viewed Tracking

    /// Load recently viewed property IDs from UserDefaults
    private func loadRecentlyViewedIds() {
        if let array = UserDefaults.standard.array(forKey: recentlyViewedKey) as? [String] {
            recentlyViewedIds = array
        }
    }

    /// Save recently viewed property IDs to UserDefaults
    private func saveRecentlyViewedIds() {
        UserDefaults.standard.set(recentlyViewedIds, forKey: recentlyViewedKey)
    }

    /// Mark a property as viewed (for visual indicator on map pins)
    func markAsViewed(_ propertyId: String) {
        guard !recentlyViewedIds.contains(propertyId) else { return }
        recentlyViewedIds.append(propertyId)
        // Limit to last 500 viewed properties to avoid unbounded growth
        // Array maintains insertion order, so oldest entries are at the front
        if recentlyViewedIds.count > 500 {
            let excess = recentlyViewedIds.count - 500
            recentlyViewedIds.removeFirst(excess)
        }
        saveRecentlyViewedIds()
    }

    /// Check if a property has been viewed
    func isPropertyViewed(_ propertyId: String) -> Bool {
        return recentlyViewedIds.contains(propertyId)
    }

    // MARK: - Analytics Tracking (Sprint 5)

    private func trackSearch(resultCount: Int) async {
        // Build metadata with active filters
        var metadata: [String: Any] = [
            "result_count": resultCount,
            "listing_type": filters.listingType.rawValue
        ]

        if !filters.cities.isEmpty {
            metadata["cities"] = Array(filters.cities)
        }
        if !filters.propertyTypes.isEmpty {
            metadata["property_types"] = filters.propertyTypes.map { $0.rawValue }
        }
        if let minPrice = filters.minPrice {
            metadata["min_price"] = minPrice
        }
        if let maxPrice = filters.maxPrice {
            metadata["max_price"] = maxPrice
        }
        if !filters.beds.isEmpty {
            metadata["beds"] = filters.beds.sorted()
        }
        if let minBaths = filters.minBaths {
            metadata["min_baths"] = minBaths
        }
        if filters.mapBounds != nil {
            metadata["has_map_bounds"] = true
        }
        if !(filters.polygonCoordinates?.isEmpty ?? true) {
            metadata["has_polygon"] = true
        }

        await ActivityTracker.shared.trackSearch(
            filters: metadata,
            resultCount: resultCount
        )
    }

    private func trackFavorite(listingKey: String, added: Bool, city: String?) async {
        await ActivityTracker.shared.trackFavorite(
            listingKey: listingKey,
            added: added
        )
    }

    private func trackHidden(listingKey: String, added: Bool, city: String?) async {
        await ActivityTracker.shared.trackHidden(
            listingKey: listingKey,
            hidden: added
        )
    }

}

// MARK: - Performance Tracking (matching web MLD_Performance)

struct PerformanceTracker {
    private var marks: [String: Date] = [:]

    mutating func mark(_ label: String) {
        marks[label] = Date()
    }

    func measure(from startLabel: String, to endLabel: String) -> TimeInterval? {
        guard let start = marks[startLabel], let end = marks[endLabel] else { return nil }
        return end.timeIntervalSince(start)
    }

    func summary() -> String {
        var result = "Performance Summary:\n"
        let sortedMarks = marks.sorted { $0.value < $1.value }

        for (index, mark) in sortedMarks.enumerated() {
            if index > 0 {
                let duration = mark.value.timeIntervalSince(sortedMarks[index - 1].value)
                result += "  \(sortedMarks[index - 1].key) -> \(mark.key): \(String(format: "%.3f", duration))s\n"
            }
        }

        return result
    }
}
