/**
 * MLD Map Core Module
 * This is the main application object that initializes the map and manages state.
 * Version 4.1
 *
 * Version 4.1 Changes (2025-09-29):
 * - Fixed draw area touch functionality on mobile devices
 * - Improved getTouchPoint() with fallback coordinate calculation
 * - Enhanced touch event handling with proper preventDefault and stopPropagation
 * - Added isDrawingActive flag to track drawing state
 * - Added calculateDistance() helper for smoother drawing
 * - Fixed map control restoration after drawing
 *
 * Version 4.0 Changes:
 * - Removed background cache system (allListingsCache, BATCH_SIZE, CACHE_EXPIRATION)
 * - Improved map idle event handling with proper bounds validation
 * - Added retry logic for initial load (up to 3 attempts)
 * - Reduced debounce delays for better responsiveness (500ms -> 250ms)
 * - Fixed isInitialLoad flag management
 * - Removed deprecated focus view functionality
 * - Optimized for handling 10,000+ listings
 */

// Performance tracking system
const MLD_Performance = {
  startTime: performance.now(),
  marks: {},

  mark(label) {
    const now = performance.now();
    const elapsed = (now - this.startTime).toFixed(2);
    this.marks[label] = elapsed;
    // DEBUG: commented
  },

  measure(label, startMark, endMark) {
    const start = parseFloat(this.marks[startMark] || 0);
    const end = parseFloat(this.marks[endMark] || (performance.now() - this.startTime));
    const duration = (end - start).toFixed(2);
    // DEBUG: commented
    return duration;
  },

  summary() {
    // DEBUG: commented
    const total = ((performance.now() - this.startTime) / 1000).toFixed(2);
    // DEBUG: commented

    // Check optimization status
    const hasPreloadedCities = bmeMapData && bmeMapData.available_cities && Array.isArray(bmeMapData.available_cities);
    const citiesCount = hasPreloadedCities ? bmeMapData.available_cities.length : 0;
    // DEBUG: commented

    // Show detailed timeline with deltas
    let prevTime = 0;
    const milestones = [
      'MLD Init Started',
      'Map Created',
      'Geolocation Success',
      'Geocoding Complete',
      'City Check Started',
      'City Check Complete',
      'Loading Listings',
      'AJAX Response Received',
      'Rendering Started',
      'Markers Rendered',
      'Sidebar Updated',
      'Listings Loaded'
    ];

    milestones.forEach(milestone => {
      if (this.marks[milestone]) {
        const elapsed = this.marks[milestone];
        const delta = elapsed - prevTime;
        // DEBUG: commented
        prevTime = elapsed;
      }
    });

    // Identify bottlenecks
    // DEBUG: commented
    if (this.marks['City Check Complete'] && this.marks['City Check Started']) {
      const cityCheckTime = this.marks['City Check Complete'] - this.marks['City Check Started'];
      const isOptimized = cityCheckTime < 50; // Should be <50ms with pre-loaded cities
      // DEBUG: commented
    }
    if (this.marks['Listings Loaded'] && this.marks['Loading Listings']) {
      const loadTime = this.marks['Listings Loaded'] - this.marks['Loading Listings'];
      // DEBUG: commented
    }

    // Show optimization recommendations if needed
    if (total > 2) {
      // DEBUG: commented
      if (!hasPreloadedCities) {
        // DEBUG: commented
      }
      if (this.marks['City Check Complete'] && this.marks['City Check Started']) {
        const cityCheckTime = this.marks['City Check Complete'] - this.marks['City Check Started'];
        if (cityCheckTime > 500) {
          // DEBUG: commented
        }
      }
    } else {
      // DEBUG: commented
    }
  }
};
const MLD_Map_App = {
  // --- State Properties ---
  isInitialized: false,
  map: null,
  markers: [],
  openPopupIds: new Set(),
  autocompleteRequest: null,
  debounceTimer: null,
  countUpdateTimer: null,
  isInitialLoad: true,
  isRecovering: false, // v6.24.4: Track if mobile recovery fetch is in progress
  hasUrlFilters: false, // Flag to track if the page loaded with filters in the URL
  isAdjustingMapBounds: false, // Flag to prevent duplicate refreshMapListings during fitMapToBounds
  hasInitialFetch: false, // Flag to track if we've done the first fetch
  isLoadingAfterGeolocation: false, // Flag to prevent idle event from interfering with geolocation load
  lastMapState: { lat: 0, lng: 0, zoom: 0 },
  AdvancedMarkerElement: null,
  markerLibraryReady: false, // v6.20.14: Track when marker library is fully loaded
  selectedPropertyType: 'Residential',
  selectedListingType: 'for_sale', // v6.59.0: Track Buy/Rent selection (for_sale or for_rent)
  keywordFilters: {},
  modalFilters: {},
  // Focus mode removed - using modal instead
  // isUnitFocusMode: false,
  isNearbySearchActive: false, // To track the state of the nearby search toggle
  // Properties for single property redirect feature
  isSpecificPropertySearch: false, // Track if searching for specific property (MLS or exact address)
  lastSearchType: null, // Store the type of last search (e.g., 'MLS Number', 'Address')
  // focusedListings: [],
  openPopoutWindows: {},
  subtypeCustomizations: {},
  priceSliderData: { min: 0, display_max: 0, distribution: [], outlier_count: 0 },

  // --- Drawing Properties ---
  isDrawingMode: false,
  drawnPolygons: [],
  currentDrawingPolygon: null,
  currentEditingPolygon: null,
  mapboxDraw: null, // For Mapbox drawing

  // --- Mobile Drawing Properties ---
  isFreehandDrawing: false,
  freehandPath: [],
  freehandPolyline: null,

  // --- Desktop Drawing Properties ---
  isClickDrawing: false,
  clickDrawingPath: [],
  clickDrawingPolygon: null,
  clickDrawingListener: null,
  doubleClickListener: null,
  clickDrawingMarkers: [],

  /**
   * Main entry point. Called on document ready and after AJAX calls.
   */
  init() {
    // Prevent multiple initializations
    if (this.isInitialized) {
      return;
    }

    MLD_Performance.mark('MLD Init Started');

    const mapContainer = document.getElementById('bme-map-container');

    // Clean up any leftover redirect messages (e.g., if user hit back button)
    jQuery('#bme-redirect-loading').remove();

    if (
      !mapContainer ||
      this.isInitialized ||
      mapContainer.classList.contains('mld-map-initialized')
    ) {
      return;
    }

    if (typeof bmeMapData === 'undefined') {
      MLDLogger.error('MLD Error: Map data (bmeMapData) is not available.');
      return;
    }

    // Initialize resize functionality for half-map view
    MLD_Core.initListingsResize();

    // Initialize city boundaries module
    if (typeof MLD_CityBoundaries !== 'undefined') {
      MLD_CityBoundaries.init();
    }

    // Mark as initialized to prevent re-initialization
    this.isInitialized = true;

    if (bmeMapData.provider === 'google') {
      this.waitForGoogleMaps();
    } else {
      this.run();
    }
  },

  /**
   * Polls to check if the Google Maps API and its libraries are loaded.
   */
  waitForGoogleMaps() {
    const self = this;
    const interval = setInterval(function () {
      if (
        typeof google !== 'undefined' &&
        typeof google.maps !== 'undefined' &&
        typeof google.maps.marker !== 'undefined'
      ) {
        clearInterval(interval);
        self.run();
      }
    }, 100);
  },

  /**
   * Waits for map to be fully ready and stable before refreshing listings
   * This ensures bounds are accurately calculated during state restoration
   */
  waitForMapReadyAndRefresh(provider, targetCenter = null) {
    const self = this;
    let attempts = 0;
    const maxAttempts = 50; // 5 seconds total

    function checkMapReady() {
      attempts++;

      try {
        const bounds = MLD_Core.getMapBounds();
        const currentCenter = self.map ? MLD_Core.getNormalizedCenter(self.map) : null;

        // Check if map is ready and bounds are valid
        if (bounds && bounds.north && bounds.south && bounds.east && bounds.west && currentCenter) {

          // Verify the map is actually positioned correctly (if we have a target)
          let positionCorrect = true;
          if (targetCenter) {
            const latDiff = Math.abs(currentCenter.lat - targetCenter.lat);
            const lngDiff = Math.abs(currentCenter.lng - targetCenter.lng);
            positionCorrect = latDiff < 0.001 && lngDiff < 0.001; // Within ~100m tolerance

            if (!positionCorrect) {
              MLDLogger.debug(`Map position not settled yet. Target: ${targetCenter.lat}, ${targetCenter.lng}, Current: ${currentCenter.lat}, ${currentCenter.lng}`);
            }
          }

          // Additional provider-specific checks
          let isReady = false;

          if (provider === 'google') {
            // For Google Maps, check if map has settled and position is correct
            isReady = self.map && typeof self.map.getBounds === 'function' && positionCorrect;
          } else if (provider === 'mapbox') {
            // For Mapbox, check if map has loaded and is stable
            isReady = self.map && self.map.loaded() && !self.map.isMoving() && positionCorrect;
          }

          if (isReady) {
            // State restoration confirmed - map ready
            if (typeof MLD_API !== 'undefined' && typeof MLD_API.refreshMapListings === 'function') {
              // Calling refreshMapListings for state restoration
              // Set flag to indicate this is a state restoration call
              window.MLD_StateRestorationInProgress = true;
              // Also set the app flag to prevent fitMapToBounds
              self.isRestoringVisitorState = true;
              MLD_API.refreshMapListings(true);
              // Clear flags after a longer delay to prevent auto-zoom
              setTimeout(() => {
                window.MLD_StateRestorationInProgress = false;
                self.isRestoringVisitorState = false;
                // State restoration flags cleared
              }, 2000); // Longer delay to prevent fitMapToBounds interference
            }
            return;
          }
        }

        // If not ready and we haven't exceeded max attempts, retry
        if (attempts < maxAttempts) {
          setTimeout(checkMapReady, 100);
        } else {
          MLDLogger.warning(`Map did not become ready within timeout after ${attempts} attempts, forcing refresh anyway`);
          MLDLogger.debug('Final bounds:', bounds);
          MLDLogger.debug('Final center:', currentCenter);
          if (typeof MLD_API !== 'undefined' && typeof MLD_API.refreshMapListings === 'function') {
            MLD_API.refreshMapListings(true);
          }
        }
      } catch (error) {
        MLDLogger.error('Error checking map readiness:', error);
        if (attempts < maxAttempts) {
          setTimeout(checkMapReady, 100);
        }
      }
    }

    // Start checking after a short initial delay
    setTimeout(checkMapReady, 200);
  },

  /**
   * Contains the entire map application logic.
   * Only called once the necessary APIs are ready.
   */
  run() {
    const mapContainer = document.getElementById('bme-map-container');
    mapContainer.classList.add('mld-map-initialized');
    this.isInitialized = true;
    this.subtypeCustomizations = bmeMapData.subtype_customizations || {};
    this.modalFilters = MLD_Filters.getModalDefaults();

    document.body.classList.add('mld-map-active');

    this.initMap();
    MLD_Core.initViewModeToggle();

    // Prevent zoom on mobile devices after initialization
    if (window.innerWidth <= 768) {
      setTimeout(() => {
        MLD_Core.preventMobileZoom();
      }, 500);
    }

    // v6.24.4: Setup mobile recovery system for handling empty map state
    this.setupMobileRecovery();
  },


  /**
   * Initializes the map instance, defaulting to Boston.
   */
  async initMap() {
    MLD_Performance.mark('Map Init Started');
    const mapContainer = document.getElementById('bme-map-container');
    // We'll set the center after we get listings
    const bostonCenterGoogle = { lat: 42.3601, lng: -71.0589 };
    const bostonCenterMapbox = [-71.0589, 42.3601];
    const initialZoom = 13;

    // Always start fresh - visitor state persistence removed
    MLD_Performance.mark('Fresh Session Start');

    // Default settings - disable nearby by default
    jQuery('#bme-nearby-toggle').prop('checked', false);
    this.isNearbySearchActive = false;
    this.pendingGeolocation = false;

    if (bmeMapData.provider === 'google') {
      try {
        const { Map } = await google.maps.importLibrary('maps');
        const markerLibrary = await google.maps.importLibrary('marker');

        this.AdvancedMarkerElement = markerLibrary.AdvancedMarkerElement;
        this.markerLibraryReady = true;  // v6.20.14: Signal that markers can now be created

        MLD_Performance.mark('Creating Map');
        // Always default to Boston
        const mapCenter = bostonCenterGoogle;
        const mapZoom = initialZoom;

        this.map = new Map(mapContainer, {
          center: mapCenter,
          zoom: mapZoom,
          mapId: 'BME_MAP_ID',
          gestureHandling: 'greedy',
          fullscreenControl: false,
          mapTypeControl: false, // Disable default control - we'll use custom
          streetViewControl: false,
          zoomControl: true, // Enable zoom controls
          zoomControlOptions: {
            position: google.maps.ControlPosition.RIGHT_BOTTOM
          },
          minZoom: 9, // Prevent zooming out beyond level 9
          // Disable POI clicks
          clickableIcons: false,
          // Disable default UI except zoom
          disableDefaultUI: false,
          // Note: When using mapId, styles must be configured in Google Cloud Console
        });

        // No restoration - always fresh start

        // Create overlay for coordinate conversion
        this.overlay = new google.maps.OverlayView();
        this.overlay.draw = function () {};
        this.overlay.setMap(this.map);
        MLD_Performance.mark('Map Created');

        // Mobile container stability detection (v6.14.11, improved v6.20.13)
        // Track when map container has stable dimensions for reliable bounds calculation
        const isMobileDevice = window.innerWidth <= 768;
        const mapInitStartTime = performance.now();  // Diagnostic timing

        // v6.20.13: Check IMMEDIATELY if container already has dimensions
        // This fixes the race condition where ResizeObserver never fires because container already has dimensions
        const containerRect = mapContainer.getBoundingClientRect();
        if (containerRect.width > 0 && containerRect.height > 0) {
          this.containerReady = true;
        } else if (isMobileDevice) {
          // Container doesn't have dimensions yet, set up observer
          this.containerReady = false;

          if (window.ResizeObserver) {
            const resizeObserver = new ResizeObserver((entries) => {
              const entry = entries[0];
              if (entry.contentRect.width > 0 && entry.contentRect.height > 0) {
                this.containerReady = true;
                resizeObserver.disconnect();
                MLDLogger.debug('Map container dimensions stable', {
                  width: entry.contentRect.width,
                  height: entry.contentRect.height
                });
              }
            });
            resizeObserver.observe(mapContainer);
          }

          // v6.20.13: Add timeout fallback - force containerReady after 2 seconds on mobile
          // This prevents infinite waiting if ResizeObserver never fires
          setTimeout(() => {
            if (!this.containerReady) {
              this.containerReady = true;
              MLDLogger.warning('Mobile container ready forced after 2s timeout');
            }
          }, 2000);
        } else {
          // Desktop is always ready
          this.containerReady = true;
        }

        let idleTimeout = null;
        let idleCount = 0;
        let initialFetchAttempts = 0;
        // Mobile-adaptive retry settings (v6.14.11)
        const maxInitialAttempts = isMobileDevice ? 8 : 3;  // 8 retries for mobile (2.8s), 3 for desktop (1.5s)
        const retryDelay = isMobileDevice ? 350 : 500;  // Faster retries on mobile

        this.map.addListener('idle', () => {
          idleCount++;
          const elapsedMs = Math.round(performance.now() - mapInitStartTime);
          const bounds = MLD_Core.getMapBounds();

          MLDLogger.debug('Map idle event #' + idleCount, {
            isInitialLoad: this.isInitialLoad,
            hasInitialFetch: this.hasInitialFetch,
            bounds: bounds,
          });

          // Check if we need to trigger geolocation on first load
          if (this.pendingGeolocation && idleCount === 1) {
            MLD_Performance.mark('Starting Geolocation');
            this.pendingGeolocation = false;

            // Prevent initial listings load while we check location
            this.hasInitialFetch = true;
            this.isInitialLoad = false;

            // Set a timeout to ensure pins load even if geolocation fails (v5.0)
            // This prevents the "no pins on mobile" issue when geolocation hangs
            // Note: v6.24.4 mobile recovery system provides additional fallback coverage
            this.geolocationTimeout = setTimeout(() => {
              const markerCount = typeof MLD_Markers !== 'undefined' &&
                                  typeof MLD_Markers.getMarkerCount === 'function'
                                  ? MLD_Markers.getMarkerCount()
                                  : (this.markers ? this.markers.length : 0);
              if (markerCount === 0) {
                MLDLogger.warning('Geolocation timeout (5s) - loading default listings');
                MLD_API.refreshMapListings(true);
              }
            }, 5000); // 5 second timeout

            // v6.24.4: Removed redundant 8-second fallback - mobile recovery system handles edge cases

            // Immediately check location (don't wait)
            MLD_Core.attemptGeolocationWithCityDetection();

            // Return to prevent any listings from loading until location is checked
            return;
          }

          // During initial load, ensure we get valid bounds before fetching
          if (this.isInitialLoad) {
            // Note: bounds already retrieved above in idle event handler

            // If no bounds and we haven't exceeded attempts, retry
            if (!bounds && initialFetchAttempts < maxInitialAttempts) {
              initialFetchAttempts++;
              MLDLogger.debug('No bounds available, retry attempt ' + initialFetchAttempts + '/' + maxInitialAttempts);
              clearTimeout(idleTimeout);
              idleTimeout = setTimeout(() => {
                google.maps.event.trigger(this.map, 'idle');
              }, retryDelay);  // Use adaptive delay (350ms mobile, 500ms desktop)
              return;
            }

            // Only fetch once during initial load when we have bounds
            if (!this.hasInitialFetch && (bounds || initialFetchAttempts >= maxInitialAttempts)) {
              this.hasInitialFetch = true;
              clearTimeout(idleTimeout);

              // Store reference to map app for nested callback
              const mapApp = this;
              const fetchElapsedMs = elapsedMs;

              idleTimeout = setTimeout(() => {
                MLDLogger.debug(
                  'Initial fetch triggered',
                  bounds ? 'with bounds' : 'without bounds (fallback)'
                );
                MLD_API.refreshMapListings(true);

                // v6.24.4: Removed 3.5s mobile safety net - replaced by setupMobileRecovery() system
                // which provides smarter recovery using Page Visibility API and consolidated 4s timeout
              }, 100); // Faster initial load
            }
            return;
          }

          // After initial load is complete, normal behavior with debouncing
          // Skip if we're loading after geolocation to avoid interference
          // v6.57.0: Also skip if auto-search is disabled
          if (!this.isLoadingAfterGeolocation && this.autoSearchEnabled !== false) {
            clearTimeout(idleTimeout);
            // Adaptive debounce: longer on mobile for reliability (v5.0)
            const debounceTime = window.innerWidth <= 768 ? 400 : 250;
            idleTimeout = setTimeout(() => {
              // Analytics: Track map zoom/pan (v6.38.0)
              const center = this.map.getCenter();
              const zoom = this.map.getZoom();
              const bounds = MLD_Core.getMapBounds();
              document.dispatchEvent(new CustomEvent('mld:map_zoom', {
                detail: { zoomLevel: zoom, bounds: bounds }
              }));
              document.dispatchEvent(new CustomEvent('mld:map_pan', {
                detail: { centerLat: center.lat(), centerLng: center.lng() }
              }));

              MLD_API.refreshMapListings(false);
            }, debounceTime);
          }
        });

        // Map position saving removed - no visitor state persistence

      } catch (error) {
        MLDLogger.error('Error loading Google Maps libraries:', error);
        mapContainer.innerHTML =
          '<p>Error: Could not load the map. Please check the API key and console for details.</p>';
        return;
      }
    } else {
      // Mapbox
      mapboxgl.accessToken = bmeMapData.mapbox_key;

      // Always default to Boston
      let mapCenter = bostonCenterMapbox;
      let mapZoom = initialZoom;

      this.map = new mapboxgl.Map({
        container: 'bme-map-container',
        style: 'mapbox://styles/mapbox/streets-v11',
        center: mapCenter,
        zoom: mapZoom,
      });

      // Add only zoom controls (no compass/rotation)
      this.map.addControl(new mapboxgl.NavigationControl({
        showCompass: false,
        showZoom: true,
        visualizePitch: false
      }), 'bottom-right');
      let idleTimeout = null;
      let idleCount = 0;
      let initialFetchAttempts = 0;
      const maxInitialAttempts = 3;

      this.map.on('idle', () => {
        idleCount++;
        MLDLogger.debug('Map idle event #' + idleCount, {
          isInitialLoad: this.isInitialLoad,
          hasInitialFetch: this.hasInitialFetch,
          bounds: MLD_Core.getMapBounds(),
        });

        // Check if we need to trigger geolocation on first load
        if (this.pendingGeolocation && idleCount === 1) {
          this.pendingGeolocation = false;
          // Mark that we've handled initial fetch to prevent automatic refresh
          this.hasInitialFetch = true;
          this.isInitialLoad = false;

          // Set a timeout to ensure pins load even if geolocation fails (v5.0)
          this.geolocationTimeout = setTimeout(() => {
            if (!this.markers || this.markers.length === 0) {
              MLDLogger.warning('Geolocation timeout (5s) - loading default listings');
              MLD_API.refreshMapListings(true);
            }
          }, 5000);

          setTimeout(() => {
            MLD_Core.attemptGeolocationWithCityDetection();
          }, 500);
          return;
        }

        // During initial load, ensure we get valid bounds before fetching
        if (this.isInitialLoad) {
          const bounds = MLD_Core.getMapBounds();

          // If no bounds and we haven't exceeded attempts, retry
          if (!bounds && initialFetchAttempts < maxInitialAttempts) {
            initialFetchAttempts++;
            MLDLogger.debug('No bounds available, retry attempt', initialFetchAttempts);
            clearTimeout(idleTimeout);
            idleTimeout = setTimeout(() => {
              this.map.fire('idle');
            }, 500);
            return;
          }

          // Only fetch once during initial load when we have bounds
          if (!this.hasInitialFetch && (bounds || initialFetchAttempts >= maxInitialAttempts)) {
            this.hasInitialFetch = true;
            clearTimeout(idleTimeout);
            idleTimeout = setTimeout(() => {
              MLDLogger.debug(
                'Initial fetch triggered',
                bounds ? 'with bounds' : 'without bounds (fallback)'
              );
              MLD_API.refreshMapListings(true);
            }, 100); // Faster initial load
          }
          return;
        }

        // After initial load is complete, normal behavior with debouncing
        // Skip if we're adjusting bounds or loading after geolocation to prevent duplicate calls
        // v6.57.0: Also skip if auto-search is disabled
        if (!this.isAdjustingMapBounds && !this.isLoadingAfterGeolocation && this.autoSearchEnabled !== false) {
          clearTimeout(idleTimeout);
          // Adaptive debounce: longer on mobile for reliability (v5.0)
          const debounceTime = window.innerWidth <= 768 ? 400 : 250;
          idleTimeout = setTimeout(() => {
            // Double-check flags in case they changed
            if (!this.isAdjustingMapBounds && !this.isLoadingAfterGeolocation && this.autoSearchEnabled !== false) {
              // Analytics: Track map zoom/pan for Mapbox (v6.38.0)
              const center = this.map.getCenter();
              const zoom = this.map.getZoom();
              const bounds = MLD_Core.getMapBounds();
              document.dispatchEvent(new CustomEvent('mld:map_zoom', {
                detail: { zoomLevel: zoom, bounds: bounds }
              }));
              document.dispatchEvent(new CustomEvent('mld:map_pan', {
                detail: { centerLat: center.lat, centerLng: center.lng }
              }));

              MLD_API.refreshMapListings(false);
            }
          }, debounceTime);
        }
      });

      // Map position saving removed - no visitor state persistence
    }

    this.postInitSetup();
    this.initDrawingTools();
  },

  /**
   * Runs setup tasks after the map has been initialized.
   */
  postInitSetup() {
    MLD_Filters.initSearchAndFilters();
    MLD_Core.initEventDelegation();
    MLD_Filters.initPriceSlider();

    // Filter restoration removed - always start fresh
    if (false) { // Disabled visitor state restoration
      try {
        // Restore keyword filters for MLD_Map_App
        if (this.pendingFilterRestore.keywords) {
          MLD_Map_App.keywordFilters = {};
          for (const key in this.pendingFilterRestore.keywords) {
            const savedFilter = this.pendingFilterRestore.keywords[key];
            // Convert arrays back to Sets
            if (Array.isArray(savedFilter)) {
              MLD_Map_App.keywordFilters[key] = new Set(savedFilter);
            } else {
              MLD_Map_App.keywordFilters[key] = new Set();
            }
          }
          // DEBUG: commented

          // Specifically log city filters to debug the issue
          if (MLD_Map_App.keywordFilters.City) {
            // DEBUG: commented
          }
        }

        // Restore keyword filters for MLD_Filters
        if (this.pendingFilterRestore.mldKeywords && typeof MLD_Filters !== 'undefined') {
          if (!MLD_Filters.keywordFilters) MLD_Filters.keywordFilters = {};
          for (const key in this.pendingFilterRestore.mldKeywords) {
            const savedFilter = this.pendingFilterRestore.mldKeywords[key];
            // Convert arrays back to Sets
            if (Array.isArray(savedFilter)) {
              MLD_Filters.keywordFilters[key] = new Set(savedFilter);
            } else {
              MLD_Filters.keywordFilters[key] = new Set();
            }
          }
          // DEBUG: commented
        }

        // Restore modal filters
        if (this.pendingFilterRestore.modal) {
          MLD_Map_App.modalFilters = { ...MLD_Filters.getModalDefaults(), ...this.pendingFilterRestore.modal };
        }

        // Restore status filter (always present)
        if (this.pendingFilterRestore.status) {
          MLD_Map_App.modalFilters.status = this.pendingFilterRestore.status;
        }

        // Restore polygon shapes if they exist
        // DEBUG: commented
        // DEBUG: commented

        if (this.pendingFilterRestore.polygonShapes && Array.isArray(this.pendingFilterRestore.polygonShapes)) {
          // DEBUG: commented
          // DEBUG: commented

          // Initialize drawnPolygons array if it doesn't exist
          if (typeof MLD_Map_App.drawnPolygons === 'undefined') {
            MLD_Map_App.drawnPolygons = [];
          }

          // Clear existing polygons first
          if (typeof MLD_Map_App.clearAllPolygons === 'function') {
            MLD_Map_App.clearAllPolygons();
          } else {
            // Manual clear if function doesn't exist
            MLD_Map_App.drawnPolygons = [];
          }

          // Restore saved polygons - wait for map to be ready
          const self = this;  // Preserve context for setTimeout
          const polygonShapes = this.pendingFilterRestore.polygonShapes;  // Copy reference

          setTimeout(() => {
            if (!self.map) {
              // DEBUG: commented
              return;
            }

            // DEBUG: commented

            polygonShapes.forEach((savedPolygon, index) => {
              if (savedPolygon.coordinates && Array.isArray(savedPolygon.coordinates)) {
                // DEBUG: commented

                // Create actual Google Maps Polygon if using Google
                if (bmeMapData.provider === 'google') {
                  // Convert coordinates to Google Maps LatLng objects
                  const paths = savedPolygon.coordinates.map(coord => {
                    return new google.maps.LatLng(coord[0], coord[1]);
                  });

                  // DEBUG: commented

                  try {
                    // Create the polygon on the map
                    const googlePolygon = new google.maps.Polygon({
                      paths: paths,
                      strokeColor: '#2196F3',
                      strokeOpacity: 0.8,
                      strokeWeight: 2,
                      fillColor: '#2196F3',
                      fillOpacity: 0.15,
                      clickable: true,
                      editable: false,
                      draggable: false,
                      map: self.map
                    });

                    // Store polygon data
                    const polygonData = {
                      id: savedPolygon.id || (`restored_polygon_${index}_${Date.now()}`),
                      coordinates: savedPolygon.coordinates,
                      googlePolygon: googlePolygon,
                      mapboxId: null
                    };

                    MLD_Map_App.drawnPolygons.push(polygonData);
                    // DEBUG: commented
                  } catch (error) {
                    // ERROR: commented
                  }
                } else if (bmeMapData.provider === 'mapbox' && MLD_Map_App.mapboxDraw) {
                  // For Mapbox, add to draw control
                  const feature = {
                    type: 'Feature',
                    geometry: {
                      type: 'Polygon',
                      coordinates: [savedPolygon.coordinates.map(coord => [coord[1], coord[0]])]
                    }
                  };
                  const featureId = MLD_Map_App.mapboxDraw.add(feature);

                  const polygonData = {
                    id: savedPolygon.id || (`restored_polygon_${index}_${Date.now()}`),
                    coordinates: savedPolygon.coordinates,
                    googlePolygon: null,
                    mapboxId: featureId[0]
                  };

                  MLD_Map_App.drawnPolygons.push(polygonData);
                  // DEBUG: commented
                }
              }
            });

            // DEBUG: commented

            // Update polygon UI and apply filters
            if (typeof MLD_Map_App.updatePolygonUI === 'function') {
              MLD_Map_App.updatePolygonUI();
            }
            if (typeof MLD_Map_App.applyPolygonFilters === 'function') {
              MLD_Map_App.applyPolygonFilters();
            }
          }, 2500);  // Increased timeout to ensure map is fully initialized
        }

        // Update the UI to reflect restored filters (with safety checks)
        if (typeof MLD_Filters.restoreModalUIToState === 'function') {
          MLD_Filters.restoreModalUIToState();
        }
        if (typeof MLD_Filters.renderFilterTags === 'function') {
          MLD_Filters.renderFilterTags();
        }

        // DEBUG: commented

        /**
         * VISITOR STATE RESTORATION FIX (October 2025)
         *
         * This section was enhanced to properly restore spatial filters and ensure
         * they are actually applied to the listings query. Previously, city boundaries
         * were drawn but city filters weren't activated, leading to incomplete restoration.
         *
         * The fix includes three critical steps in sequence:
         */
        setTimeout(() => {
          // Step 1: Update city boundaries on the map
          if (typeof MLD_CityBoundaries !== 'undefined' && typeof MLD_CityBoundaries.updateBoundariesFromFilters === 'function') {
            // DEBUG: commented
            MLD_CityBoundaries.updateBoundariesFromFilters();
          }

          // Step 2: CRITICAL FIX - Refresh listings to apply restored filters
          // This was missing and caused city boundaries to appear without listings
          if (typeof MLD_API !== 'undefined' && typeof MLD_API.refreshMapListings === 'function') {
            // DEBUG: commented
            MLD_API.refreshMapListings(true);  // true = force refresh
          }

          // Step 3: Update URL hash to reflect the restored state
          if (typeof MLD_Core !== 'undefined' && typeof MLD_Core.updateUrlHash === 'function') {
            // DEBUG: commented
            MLD_Core.updateUrlHash();
          }
        }, 1000);
      } catch (error) {
        // ERROR: commented
      }

      // Clear the pending restore
      this.pendingFilterRestore = null;

      // Clear the visitor state restoration flag after a delay to allow initial listings to load
      setTimeout(() => {
        this.isRestoringVisitorState = false;
        // DEBUG: commented
      }, 3000);
    }

    // View state restoration removed - always start fresh
    if (false) { // Disabled visitor state restoration
      // DEBUG: commented

      // For now, just restore scroll position without changing view mode
      // View mode changes can be problematic during initial load
      if (this.pendingViewRestore.scrollPosition > 0) {
        setTimeout(() => {
          jQuery(window).scrollTop(this.pendingViewRestore.scrollPosition);
          jQuery('#bme-listings-container').scrollTop(this.pendingViewRestore.scrollPosition);
          // DEBUG: commented
        }, 2000);
      }

      // Clear the pending restore
      this.pendingViewRestore = null;
      // DEBUG: commented
    }

    // Setup satellite toggle in Map Options panel
    // Using a standalone function to avoid context issues
    (function () {
      const app = MLD_Map_App;
      if (!app.map) return;

      // Use the new toggle switch in Map Options
      const satelliteToggle = document.getElementById('bme-satellite-toggle');
      if (satelliteToggle) {
        // Hide the entire control item if not Google Maps
        const controlItem = satelliteToggle.closest('.bme-control-item');
        if (bmeMapData.provider !== 'google' && controlItem) {
          controlItem.style.display = 'none';
          return;
        }

        // Add change handler for the toggle switch
        satelliteToggle.addEventListener('change', function () {
          if (app.map && bmeMapData.provider === 'google') {
            const isSatellite = this.checked;
            app.map.setMapTypeId(isSatellite ? 'satellite' : 'roadmap');
          }
        });

        // Set initial state to unchecked (road map view)
        satelliteToggle.checked = false;
      }
    })();

    // Auto-Search Toggle (v6.57.0 - iOS alignment)
    (function () {
      const app = MLD_Map_App;
      const autoSearchToggle = document.getElementById('bme-auto-search-toggle');
      if (autoSearchToggle) {
        // Initialize auto-search state (default: enabled)
        app.autoSearchEnabled = true;

        autoSearchToggle.addEventListener('change', function () {
          app.autoSearchEnabled = this.checked;
          MLDLogger.info('Auto-search ' + (app.autoSearchEnabled ? 'enabled' : 'disabled'));
        });

        // Set initial state to checked (auto-search enabled)
        autoSearchToggle.checked = true;
      }
    })();

    // My Location GPS Button (v6.57.0 - iOS alignment)
    (function () {
      const app = MLD_Map_App;
      const myLocationBtn = document.getElementById('bme-my-location-btn');
      if (myLocationBtn) {
        myLocationBtn.addEventListener('click', function () {
          if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser');
            return;
          }

          // Add loading state
          myLocationBtn.classList.add('loading');
          myLocationBtn.disabled = true;

          navigator.geolocation.getCurrentPosition(
            function (position) {
              const lat = position.coords.latitude;
              const lng = position.coords.longitude;

              // Center map on user location
              if (app.map && bmeMapData.provider === 'google') {
                app.map.setCenter({ lat: lat, lng: lng });
                app.map.setZoom(14);
              } else if (app.map && bmeMapData.provider === 'mapbox') {
                app.map.flyTo({ center: [lng, lat], zoom: 14 });
              }

              // Remove loading state
              myLocationBtn.classList.remove('loading');
              myLocationBtn.disabled = false;

              MLDLogger.info('Centered on user location: ' + lat + ', ' + lng);
            },
            function (error) {
              // Remove loading state
              myLocationBtn.classList.remove('loading');
              myLocationBtn.disabled = false;

              let message = 'Unable to retrieve your location';
              switch (error.code) {
                case error.PERMISSION_DENIED:
                  message = 'Location access was denied. Please enable location permissions.';
                  break;
                case error.POSITION_UNAVAILABLE:
                  message = 'Location information is unavailable.';
                  break;
                case error.TIMEOUT:
                  message = 'Location request timed out.';
                  break;
              }
              alert(message);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
          );
        });
      }
    })();

    // Restore filters from URL if present (for sharing links)
    // This only restores explicitly shared URL parameters, not saved state
    this.hasUrlFilters = MLD_Core.restoreStateFromUrl();

    // State persistence removed - always use default property type

    MLD_Core.setupPropertyTypeSelectors();
    // v6.59.0: Initialize property type options based on listing type
    // This ensures correct options show whether from URL or default state
    if (!this.hasUrlFilters) {
      MLD_Filters.updatePropertyTypeOptions(this.selectedListingType || 'for_sale');
    }
    jQuery('#bme-property-type-select').val(this.selectedPropertyType).trigger('change', [true]);
  },

  /**
   * Initialize drawing tools based on map provider
   */
  initDrawingTools() {
    // Always ensure Reset button starts hidden
    jQuery('#bme-reset-button').hide().addClass('force-hidden');

    // Update polygon UI to ensure proper button states
    this.updatePolygonUI();

    // Hide map controls initially if on mobile and in list view
    if (window.innerWidth <= 768) {
      const currentView = jQuery('#bme-half-map-wrapper').hasClass('view-mode-map')
        ? 'map'
        : 'list';
      this.toggleMapControlsVisibility(currentView);
    }

    if (bmeMapData.provider === 'google') {
      // Custom polygon drawing implementation
      MLDLogger.info('Polygon drawing initialized');
    } else {
      // Initialize Mapbox Draw
      if (typeof MapboxDraw !== 'undefined') {
        this.mapboxDraw = new MapboxDraw({
          displayControlsDefault: false,
          controls: {
            polygon: true,
            trash: true,
          },
          styles: [
            {
              id: 'gl-draw-polygon-fill',
              type: 'fill',
              filter: ['all', ['==', '$type', 'Polygon']],
              paint: {
                'fill-color': '#2196F3',
                'fill-opacity': 0.2,
              },
            },
            {
              id: 'gl-draw-polygon-stroke',
              type: 'line',
              filter: ['all', ['==', '$type', 'Polygon']],
              layout: {
                'line-cap': 'round',
                'line-join': 'round',
              },
              paint: {
                'line-color': '#2196F3',
                'line-width': 2,
              },
            },
          ],
        });
        this.map.addControl(this.mapboxDraw, 'top-left');

        // Add event listeners
        this.map.on('draw.create', (e) => {
          this.handleMapboxPolygonComplete(e.features[0]);
        });

        this.map.on('draw.delete', (e) => {
          this.handleMapboxPolygonDelete(e.features[0]);
        });
      }
    }
  },

  /**
   * Toggle drawing mode on/off
   */
  toggleDrawingMode() {
    // Don't toggle, let enter/exit functions handle the state
    if (!this.isDrawingMode) {
      this.enterDrawingMode();
    } else {
      this.exitDrawingMode();
    }
  },

  /**
   * Start drawing a new shape
   */
  startNewShape() {
    const $ = jQuery;
    const isMobile = window.innerWidth <= 768;

    // Show complete shape button only on desktop
    if (!isMobile) {
      $('#bme-complete-shape-button').show();
    }

    if (bmeMapData.provider === 'google') {
      // Use appropriate drawing method based on device
      if (isMobile) {
        this.startFreehandDrawing();
      } else {
        this.startClickBasedDrawing();
      }
    } else if (this.mapboxDraw) {
      this.mapboxDraw.changeMode('draw_polygon');
    }
  },

  /**
   * Complete the current shape being drawn
   */
  completeCurrentShape() {
    const $ = jQuery;

    // Hide complete shape button, show draw button
    $('#bme-complete-shape-button').hide();

    // If we're editing a polygon, disable editing
    if (this.currentEditingPolygon) {
      if (bmeMapData.provider === 'google' && this.currentEditingPolygon.googlePolygon) {
        this.currentEditingPolygon.googlePolygon.setEditable(false);
      }
      this.currentEditingPolygon = null;
    }

    if (bmeMapData.provider === 'google') {
      // Cancel freehand drawing if active
      if (this.isFreehandDrawing) {
        this.cancelFreehandDrawing();
      }
      // Complete click-based drawing if active
      if (this.isClickDrawing) {
        this.completeClickDrawing();
        return; // Exit early since completeClickDrawing() handles cleanup and exit
      }
    } else if (this.mapboxDraw) {
      this.mapboxDraw.changeMode('simple_select');
    }

    // Show clear shapes button if we have shapes
    if (this.drawnPolygons.length > 0) {
      $('#bme-reset-button').show();
    }

    // Exit drawing mode after completing a shape
    this.exitDrawingMode();
  },

  /**
   * Save shape name
   * @param $input
   */
  saveShapeName($input) {
    const $ = jQuery;
    const newName = $input.val().trim() || 'Shape';
    const $name = $input.siblings('.bme-polygon-name');
    const polygonId = $input.closest('.bme-polygon-item').data('polygon-id');

    // Update the polygon data
    const polygon = this.drawnPolygons.find((p) => p.id === polygonId);
    if (polygon) {
      polygon.name = newName;
    }

    $name.text(newName).show();
    $input.hide();

    // Update URL with polygon data
    MLD_Core.updateUrlHash();
  },

  /**
   * Enter drawing mode
   */
  enterDrawingMode() {
    const $ = jQuery;
    const isMobile = window.innerWidth <= 768;

    // Set drawing mode to true
    this.isDrawingMode = true;

    // Analytics: Track draw mode start (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:map_draw_start', {
      detail: {}
    }));

    // Hide all markers
    MLD_Markers.clearMarkers();

    // Show drawing UI
    $('#bme-drawing-panel').addClass('active');
    // Update toggle state
    $('#bme-draw-toggle').addClass('active');

    // Show reset button if we have shapes
    if (this.drawnPolygons.length > 0) {
      $('#bme-reset-button').show();
    }

    // Enable drawing
    if (bmeMapData.provider === 'google') {
      // Use appropriate drawing method based on device
      const isMobile = window.innerWidth <= 768;
      if (isMobile) {
        this.startFreehandDrawing();
      } else {
        this.startClickBasedDrawing();
      }
    } else if (this.mapboxDraw) {
      // Enable mapbox draw
    }

    // Show existing polygons if any
    this.showExistingPolygons();
  },

  /**
   * Exit drawing mode
   */
  exitDrawingMode() {
    const $ = jQuery;

    // Set drawing mode to false
    this.isDrawingMode = false;

    // Hide drawing UI
    $('#bme-drawing-panel').removeClass('active');
    // Update toggle state
    $('#bme-draw-toggle').removeClass('active');
    $('#bme-complete-shape-button').hide();
    $('#bme-reset-button').hide();

    // Disable drawing
    if (bmeMapData.provider === 'google') {
      // Cancel any active freehand drawing
      if (this.isFreehandDrawing) {
        this.cancelFreehandDrawing();
      }
      // Cancel any active click-based drawing
      if (this.isClickDrawing) {
        this.cleanupClickDrawing();
      }
    } else if (this.mapboxDraw) {
      this.mapboxDraw.changeMode('simple_select');
    }

    // Refresh listings with polygon filter if any polygons exist
    if (this.drawnPolygons.length > 0) {
      MLD_API.refreshMapListings(true);
    } else {
      // Show markers again if no polygons
      MLD_API.refreshMapListings(false);
    }
  },

  /**
   * Handle polygon completion (Google Maps)
   * @param polygon
   */
  handlePolygonComplete(polygon) {
    const isMobile = window.innerWidth <= 768;

    // Extract coordinates
    const path = polygon.getPath();
    const coordinates = [];

    for (let i = 0; i < path.getLength(); i++) {
      const latLng = path.getAt(i);
      coordinates.push([latLng.lat(), latLng.lng()]);
    }

    // Store polygon data
    const polygonData = {
      id: 'polygon_' + Date.now(),
      name: 'Shape ' + (this.drawnPolygons.length + 1),
      coordinates,
      googlePolygon: polygon,
      mapboxId: null,
    };

    this.drawnPolygons.push(polygonData);

    // Analytics: Track draw complete (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:map_draw_complete', {
      detail: {
        vertexCount: coordinates.length,
        polygonId: polygonData.id
      }
    }));

    // Add edit listener (desktop only)
    if (!isMobile) {
      google.maps.event.addListener(polygon, 'click', () => {
        this.editPolygon(polygonData.id);
      });
    }

    // Update UI and filters
    this.updatePolygonUI();
    this.applyPolygonFilters();

    // Visitor state persistence removed - polygons are not saved between sessions

    // Exit drawing mode after completing a shape (both mobile and desktop)
    this.exitDrawingMode();
  },

  /**
   * Handle polygon completion (Mapbox)
   * @param feature
   */
  handleMapboxPolygonComplete(feature) {
    // Extract coordinates
    const coordinates = feature.geometry.coordinates[0].map((coord) => [coord[1], coord[0]]); // Convert to lat,lng

    // Store polygon data
    const polygonData = {
      id: feature.id,
      coordinates,
      googlePolygon: null,
      mapboxId: feature.id,
    };

    this.drawnPolygons.push(polygonData);

    // Analytics: Track draw complete for Mapbox (v6.38.0)
    document.dispatchEvent(new CustomEvent('mld:map_draw_complete', {
      detail: {
        vertexCount: coordinates.length,
        polygonId: polygonData.id
      }
    }));

    // Update UI and filters
    this.updatePolygonUI();
    this.applyPolygonFilters();

    // Visitor state persistence removed - polygons are not saved between sessions
  },

  /**
   * Handle polygon deletion (Mapbox)
   * @param feature
   */
  handleMapboxPolygonDelete(feature) {
    this.drawnPolygons = this.drawnPolygons.filter((p) => p.mapboxId !== feature.id);
    this.updatePolygonUI();
    this.applyPolygonFilters();

    // Visitor state persistence removed - polygon updates are not saved
  },

  /**
   * Delete a polygon by ID
   * @param polygonId
   */
  deletePolygon(polygonId) {
    const polygonIndex = this.drawnPolygons.findIndex((p) => p.id === polygonId);
    if (polygonIndex === -1) return;

    const polygonData = this.drawnPolygons[polygonIndex];

    // Remove from map
    if (bmeMapData.provider === 'google' && polygonData.googlePolygon) {
      polygonData.googlePolygon.setMap(null);
    } else if (this.mapboxDraw && polygonData.mapboxId) {
      this.mapboxDraw.delete(polygonData.mapboxId);
    }

    // Remove from array
    this.drawnPolygons.splice(polygonIndex, 1);

    // Update UI and filters
    this.updatePolygonUI();
    this.applyPolygonFilters();

    // Hide clear button if no shapes left
    if (this.drawnPolygons.length === 0) {
      jQuery('#bme-reset-button').hide();
    }

    // Visitor state persistence removed - polygon updates are not saved
  },

  /**
   * Edit a polygon
   * @param polygonId
   */
  editPolygon(polygonId) {
    const polygon = this.drawnPolygons.find((p) => p.id === polygonId);
    if (!polygon) return;

    if (bmeMapData.provider === 'google' && polygon.googlePolygon) {
      // Enable editing for the polygon
      polygon.googlePolygon.setEditable(true);

      // Show complete shape button
      jQuery('#bme-complete-shape-button').show();

      // Store current editing polygon
      this.currentEditingPolygon = polygon;

      // Listen for path changes
      if (!polygon.pathListener) {
        polygon.pathListener = google.maps.event.addListener(
          polygon.googlePolygon.getPath(),
          'set_at',
          () => {
            this.updatePolygonCoordinates(polygon);
          }
        );
        polygon.insertListener = google.maps.event.addListener(
          polygon.googlePolygon.getPath(),
          'insert_at',
          () => {
            this.updatePolygonCoordinates(polygon);
          }
        );
      }
    }
    // Note: Mapbox editing support not implemented
  },

  /**
   * Update polygon coordinates after editing
   * @param polygon
   */
  updatePolygonCoordinates(polygon) {
    if (bmeMapData.provider === 'google' && polygon.googlePolygon) {
      const path = polygon.googlePolygon.getPath();
      const coordinates = [];

      for (let i = 0; i < path.getLength(); i++) {
        const latLng = path.getAt(i);
        coordinates.push([latLng.lat(), latLng.lng()]);
      }

      polygon.coordinates = coordinates;
      this.applyPolygonFilters();
    }
  },

  /**
   * Clear all polygons
   */
  clearAllPolygons() {
    // Remove all polygons from map
    this.drawnPolygons.forEach((polygonData) => {
      if (bmeMapData.provider === 'google' && polygonData.googlePolygon) {
        polygonData.googlePolygon.setMap(null);
      }
    });

    if (this.mapboxDraw) {
      this.mapboxDraw.deleteAll();
    }

    // Clear array - IMPORTANT: must be before any filter updates
    this.drawnPolygons = [];

    // Update UI first
    this.updatePolygonUI();

    // Hide reset button
    jQuery('#bme-reset-button').hide().addClass('force-hidden');

    // Always exit drawing mode when clearing shapes
    if (this.isDrawingMode) {
      this.exitDrawingMode();
    } else {
      // Even if not in drawing mode, ensure toggle state is correct
      jQuery('#bme-draw-toggle').removeClass('active');
    }

    // Update filters and URL after clearing polygons
    // Force refresh to ensure server gets updated filters
    this.applyPolygonFilters();
    MLD_Core.updateUrlHash();
  },

  /**
   * Update polygon UI list
   */
  updatePolygonUI() {
    const $ = jQuery;
    const $list = $('#bme-polygon-list');

    if (this.drawnPolygons.length === 0) {
      $list.html('<p class="bme-no-polygons">No shapes drawn yet</p>');
      $('#bme-reset-button').hide().addClass('force-hidden');
      return;
    }

    $('#bme-reset-button').show().removeClass('force-hidden');

    let html = '';
    this.drawnPolygons.forEach((polygon, index) => {
      const name = polygon.name || `Shape ${index + 1}`;
      html += `
				<div class="bme-polygon-item" data-polygon-id="${polygon.id}">
					<span class="bme-polygon-name">${name}</span>
					<input type="text" class="bme-polygon-name-input" value="${name}" style="display: none;">
					<button class="bme-polygon-delete" title="Delete shape">×</button>
				</div>
			`;
    });

    $list.html(html);

    // Add delete handlers
    $list.find('.bme-polygon-delete').on('click', function (e) {
      e.stopPropagation();
      const polygonId = $(this).closest('.bme-polygon-item').data('polygon-id');
      MLD_Map_App.deletePolygon(polygonId);
    });
  },

  /**
   * Apply polygon filters - Order-Independent Spatial Filtering
   *
   * CRITICAL FIX (October 2025): This method was modified to support OR logic
   * between city filters and polygon shapes. Previously, drawing a polygon
   * would clear ALL location filters, causing order-dependent behavior.
   *
   * Before Fix:
   * - Select city → Draw shape: City filter deleted ❌
   * - Draw shape → Select city: Both preserved ✅
   *
   * After Fix:
   * - Select city → Draw shape: Both preserved ✅
   * - Draw shape → Select city: Both preserved ✅
   *
   * The key change: City filters are NO LONGER cleared when polygons are drawn,
   * allowing them to be combined with OR logic in the backend query.
   */
  applyPolygonFilters() {
    if (this.drawnPolygons.length > 0) {
      /**
       * IMPORTANT: City filters are now preserved for OR logic combination
       *
       * We still clear other location filters to prevent conflicts, but City
       * and Neighborhood filters are preserved because they support OR logic
       * with polygon shapes in the backend query system.
       */
      const locationFilters = [
        // 'City',  // REMOVED (Oct 2025) - now preserved for OR logic with polygons
        // 'Neighborhood',  // Also preserved for OR logic
        'Building Name',
        'MLS Area Major',
        'MLS Area Minor',
        'Postal Code',
        'Street Name',
        'Address',
      ];
      locationFilters.forEach((filter) => {
        delete MLD_Map_App.keywordFilters[filter];
      });

      // Debug logging to verify City filters are preserved
      // DEBUG: commented
      // DEBUG: commented
    }

    // Always update filter tags and URL (to add or remove polygon filter)
    MLD_Filters.renderFilterTags();
    MLD_Core.updateUrlHash();

    // Always refresh listings (the API will check for polygon filters)
    MLD_API.refreshMapListings(true);
  },

  /**
   * Show existing polygons on map
   */
  showExistingPolygons() {
    if (bmeMapData.provider === 'google') {
      this.drawnPolygons.forEach((polygonData) => {
        if (polygonData.googlePolygon) {
          polygonData.googlePolygon.setMap(this.map);
        }
      });
    }
    // Mapbox polygons are automatically shown
  },

  /**
   * Get polygon coordinates for filtering
   */
  getPolygonCoordinates() {
    // Return coordinates only if there are valid polygons
    if (!this.drawnPolygons || this.drawnPolygons.length === 0) {
      return null;
    }
    return this.drawnPolygons.map((p) => p.coordinates);
  },

  /**
   * Start freehand drawing for mobile
   */
  startFreehandDrawing() {
    if (!this.map || this.isFreehandDrawing) return;

    const $ = jQuery;
    this.isFreehandDrawing = true;
    this.freehandPath = [];
    this.isDrawingActive = false; // Track if actually drawing

    // Change map dragging to false to capture touch events
    this.map.setOptions({
      draggable: false,
      zoomControl: false,
      scrollwheel: false,
      disableDoubleClickZoom: true
    });

    // Show drawing instruction
    const instructionHtml =
      '<div id="bme-drawing-instruction" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px; border-radius: 8px; z-index: 10000; text-align: center;"><p style="margin: 0; font-size: 18px;">Draw on the map with your finger</p><p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.8;">Lift finger to complete shape</p></div>';
    $('body').append(instructionHtml);

    // Fade out instruction after 2 seconds
    setTimeout(() => {
      $('#bme-drawing-instruction').fadeOut(500, function () {
        $(this).remove();
      });
    }, 2000);

    // Initialize the polyline for visual feedback
    if (bmeMapData.provider === 'google') {
      this.freehandPolyline = new google.maps.Polyline({
        path: [],
        strokeColor: '#2196F3',
        strokeOpacity: 0.8,
        strokeWeight: 3,
        map: this.map,
      });
    }

    // Get map container
    const mapDiv = document.getElementById('bme-map-container');

    // Touch event handlers
    const handleTouchStart = (e) => {
      if (!this.isFreehandDrawing) return;
      e.preventDefault();
      e.stopPropagation();

      this.isDrawingActive = true;
      const touch = e.touches[0];
      const point = this.getTouchPoint(touch);
      if (point) {
        this.freehandPath = [point];
        if (this.freehandPolyline) {
          this.freehandPolyline.setPath(this.freehandPath);
        }
      }
    };

    const handleTouchMove = (e) => {
      if (!this.isFreehandDrawing || !this.isDrawingActive) return;
      e.preventDefault();
      e.stopPropagation();

      const touch = e.touches[0];
      const point = this.getTouchPoint(touch);
      if (point && this.freehandPath.length > 0) {
        // Add point only if it's far enough from the last point (reduces jitter)
        const lastPoint = this.freehandPath[this.freehandPath.length - 1];
        const distance = this.calculateDistance(lastPoint, point);
        if (distance > 0.00001) { // Minimum distance threshold
          this.freehandPath.push(point);
          if (this.freehandPolyline) {
            this.freehandPolyline.setPath(this.freehandPath);
          }
        }
      }
    };

    const handleTouchEnd = (e) => {
      if (!this.isFreehandDrawing || !this.isDrawingActive) return;
      e.preventDefault();
      e.stopPropagation();

      this.isDrawingActive = false;

      // Need at least 3 points for a polygon
      if (this.freehandPath.length < 3) {
        this.cancelFreehandDrawing();
        return;
      }

      // Complete the polygon
      this.completeFreehandDrawing();
    };

    // Add event listeners
    mapDiv.addEventListener('touchstart', handleTouchStart, { passive: false });
    mapDiv.addEventListener('touchmove', handleTouchMove, { passive: false });
    mapDiv.addEventListener('touchend', handleTouchEnd, { passive: false });

    // Store handlers for cleanup
    this.freehandHandlers = {
      touchstart: handleTouchStart,
      touchmove: handleTouchMove,
      touchend: handleTouchEnd,
    };
  },

  /**
   * Calculate distance between two points
   * @param {google.maps.LatLng} point1
   * @param {google.maps.LatLng} point2
   */
  calculateDistance(point1, point2) {
    if (!point1 || !point2) return 0;
    const lat1 = point1.lat();
    const lng1 = point1.lng();
    const lat2 = point2.lat();
    const lng2 = point2.lng();
    return Math.sqrt(Math.pow(lat2 - lat1, 2) + Math.pow(lng2 - lng1, 2));
  },

  /**
   * Get map coordinates from touch point
   * @param touch
   */
  getTouchPoint(touch) {
    if (!this.map || !touch) return null;

    const mapDiv = document.getElementById('bme-map-container');
    const rect = mapDiv.getBoundingClientRect();
    const x = touch.clientX - rect.left;
    const y = touch.clientY - rect.top;

    if (bmeMapData.provider === 'google') {
      // Try using overlay projection first
      if (this.overlay && this.overlay.getProjection) {
        const projection = this.overlay.getProjection();
        if (projection) {
          const point = new google.maps.Point(x, y);
          const latLng = projection.fromContainerPixelToLatLng(point);
          return latLng;
        }
      }

      // Fallback: Calculate using map bounds and container size
      const bounds = this.map.getBounds();
      if (!bounds) return null;

      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();

      // Calculate the lat/lng based on pixel position
      const latRange = ne.lat() - sw.lat();
      const lngRange = ne.lng() - sw.lng();

      const lat = ne.lat() - (y / mapDiv.offsetHeight) * latRange;
      const lng = sw.lng() + (x / mapDiv.offsetWidth) * lngRange;

      return new google.maps.LatLng(lat, lng);
    }

    return null;
  },

  /**
   * Complete freehand drawing and create polygon
   */
  completeFreehandDrawing() {
    if (!this.isFreehandDrawing || this.freehandPath.length < 3) return;

    // Create polygon from path
    if (bmeMapData.provider === 'google') {
      const polygon = new google.maps.Polygon({
        paths: this.freehandPath,
        fillColor: '#2196F3',
        fillOpacity: 0.2,
        strokeColor: '#2196F3',
        strokeWeight: 2,
        clickable: true,
        editable: false,
        map: this.map,
      });

      // Handle the polygon completion
      this.handlePolygonComplete(polygon);
    }

    // Cleanup
    this.cleanupFreehandDrawing();
  },

  /**
   * Cancel freehand drawing
   */
  cancelFreehandDrawing() {
    this.cleanupFreehandDrawing();
  },

  /**
   * Cleanup freehand drawing
   */
  cleanupFreehandDrawing() {
    this.isFreehandDrawing = false;
    this.isDrawingActive = false;
    this.freehandPath = [];

    // Remove polyline
    if (this.freehandPolyline) {
      this.freehandPolyline.setMap(null);
      this.freehandPolyline = null;
    }

    // Re-enable map dragging and controls
    if (this.map) {
      this.map.setOptions({
        draggable: true,
        zoomControl: true,
        scrollwheel: true,
        disableDoubleClickZoom: false
      });
    }

    // Remove event listeners
    const mapDiv = document.getElementById('bme-map-container');
    if (mapDiv && this.freehandHandlers) {
      mapDiv.removeEventListener('touchstart', this.freehandHandlers.touchstart, { passive: false });
      mapDiv.removeEventListener('touchmove', this.freehandHandlers.touchmove, { passive: false });
      mapDiv.removeEventListener('touchend', this.freehandHandlers.touchend, { passive: false });
      this.freehandHandlers = null;
    }

    // Exit drawing mode
    this.exitDrawingMode();
  },

  /**
   * Start click-based drawing for desktop
   */
  startClickBasedDrawing() {
    if (!this.map) return;

    // Initialize drawing state
    this.isClickDrawing = true;
    this.clickDrawingPath = [];
    this.clickDrawingPolygon = null;

    // Show the Complete Shape button for desktop
    jQuery('#bme-complete-shape-button').show();

    // Show drawing instruction for desktop
    const instruction =
      '<div id="bme-drawing-instruction" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px; border-radius: 8px; z-index: 10000; text-align: center; max-width: 350px;"><p style="margin: 0; font-size: 18px; font-weight: bold;">Click to Draw Polygon</p><p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">• Click points to draw<br>• Need minimum 3 points<br>• Double-click to finish<br>• Or use "Complete Shape" button</p></div>';

    jQuery('body').append(instruction);

    // Hide instruction after 4 seconds
    setTimeout(() => {
      jQuery('#bme-drawing-instruction').fadeOut(500, function () {
        jQuery(this).remove();
      });
    }, 4000);

    // Add click listener to map
    this.clickDrawingListener = google.maps.event.addListener(this.map, 'click', (event) => {
      this.addClickPoint(event.latLng);
    });

    // Disable double-click zoom first
    this.map.setOptions({ disableDoubleClickZoom: true });

    // Add double-click listener to complete polygon
    this.doubleClickListener = google.maps.event.addListener(this.map, 'dblclick', (event) => {
      // Prevent default behavior
      if (event.domEvent) {
        event.domEvent.preventDefault();
        event.domEvent.stopPropagation();
      }

      // Only complete if we're in click drawing mode
      if (this.isClickDrawing) {
        this.completeClickDrawing();
      }
    });

    // Change cursor to crosshair
    this.map.setOptions({ draggableCursor: 'crosshair' });
  },

  /**
   * Add a point to the click-based drawing
   */
  addClickPoint(latLng) {
    if (!this.isClickDrawing) return;

    this.clickDrawingPath.push(latLng);

    // Initialize markers array if not exists
    if (!this.clickDrawingMarkers) {
      this.clickDrawingMarkers = [];
    }

    // Add a visual marker for the clicked point using modern AdvancedMarkerElement
    this.createClickMarker(latLng, this.clickDrawingPath.length);

    // Remove previous polygon if exists
    if (this.clickDrawingPolygon) {
      this.clickDrawingPolygon.setMap(null);
    }

    // Create/update polygon with current path
    if (this.clickDrawingPath.length >= 2) {
      this.clickDrawingPolygon = new google.maps.Polygon({
        paths: this.clickDrawingPath,
        fillColor: '#2196F3',
        fillOpacity: 0.15,
        strokeColor: '#2196F3',
        strokeWeight: 2,
        clickable: false,
        editable: false,
        draggable: false,
      });
      this.clickDrawingPolygon.setMap(this.map);
    }


    // Show progress feedback
    if (this.clickDrawingPath.length === 1) {
      this.showDrawingFeedback('Point 1 added. Need 2 more points minimum.');
    } else if (this.clickDrawingPath.length === 2) {
      this.showDrawingFeedback('Point 2 added. Need 1 more point minimum.');
    } else if (this.clickDrawingPath.length === 3) {
      this.showDrawingFeedback('Minimum 3 points reached! Double-click or use Complete Shape button to finish.');
    }
  },

  /**
   * Complete click-based drawing
   */
  completeClickDrawing() {
    if (!this.isClickDrawing || this.clickDrawingPath.length < 3) {

      // Show user-friendly message
      const $ = jQuery;
      const message = `<div id="bme-drawing-error" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(220, 53, 69, 0.9); color: white; padding: 15px; border-radius: 8px; z-index: 10001; text-align: center;">
        <p style="margin: 0;">Need at least 3 points to create a polygon</p>
        <p style="margin: 5px 0 0 0; font-size: 14px;">You have ${this.clickDrawingPath.length} point${this.clickDrawingPath.length === 1 ? '' : 's'}</p>
      </div>`;

      $('body').append(message);
      setTimeout(() => {
        $('#bme-drawing-error').fadeOut(500, function() { $(this).remove(); });
      }, 3000);

      return;
    }

    // Remove temporary markers and polygon since we're completing the polygon
    if (this.clickDrawingMarkers) {
      this.clickDrawingMarkers.forEach(marker => {
        if (marker.map) {
          // AdvancedMarkerElement
          marker.map = null;
        } else if (marker.setMap) {
          // Fallback circle
          marker.setMap(null);
        }
      });
      this.clickDrawingMarkers = [];
    }

    // Remove the temporary drawing polygon
    if (this.clickDrawingPolygon) {
      this.clickDrawingPolygon.setMap(null);
      this.clickDrawingPolygon = null;
    }

    // Create a real Google Maps Polygon from the clicked points
    const realPolygon = new google.maps.Polygon({
      paths: this.clickDrawingPath,
      strokeColor: '#2196F3',
      strokeOpacity: 0.8,
      strokeWeight: 2,
      fillColor: '#2196F3',
      fillOpacity: 0.15,
      clickable: true,
      editable: false,
      draggable: false,
      map: this.map
    });

    // Handle the completed polygon
    this.handlePolygonComplete(realPolygon);

    // Clean up
    this.cleanupClickDrawing();
  },

  /**
   * Cancel click-based drawing
   */
  cancelClickDrawing() {
    this.cleanupClickDrawing();
    this.exitDrawingMode();
  },

  /**
   * Clean up click-based drawing
   */
  cleanupClickDrawing() {
    this.isClickDrawing = false;

    // Remove polygon
    if (this.clickDrawingPolygon) {
      this.clickDrawingPolygon.setMap(null);
      this.clickDrawingPolygon = null;
    }

    // Remove click markers (both AdvancedMarkerElement and fallback circles)
    if (this.clickDrawingMarkers) {
      this.clickDrawingMarkers.forEach(marker => {
        if (marker.map) {
          // AdvancedMarkerElement
          marker.map = null;
        } else if (marker.setMap) {
          // Fallback circle
          marker.setMap(null);
        }
      });
      this.clickDrawingMarkers = [];
    }

    // Remove listeners
    if (this.clickDrawingListener) {
      google.maps.event.removeListener(this.clickDrawingListener);
      this.clickDrawingListener = null;
    }
    if (this.doubleClickListener) {
      google.maps.event.removeListener(this.doubleClickListener);
      this.doubleClickListener = null;
    }

    // Reset cursor and re-enable double-click zoom
    if (this.map) {
      this.map.setOptions({
        draggableCursor: null,
        disableDoubleClickZoom: false
      });
    }

    // Clear path
    this.clickDrawingPath = [];

    // Remove any instruction and feedback
    jQuery('#bme-drawing-instruction').remove();
    jQuery('#bme-drawing-feedback').remove();
  },

  /**
   * Create a modern click marker using AdvancedMarkerElement
   */
  async createClickMarker(latLng, pointNumber) {
    if (!this.clickDrawingMarkers) {
      this.clickDrawingMarkers = [];
    }

    try {
      // Import the marker library if not already imported
      if (!this.markerLibrary) {
        this.markerLibrary = await google.maps.importLibrary('marker');
      }

      // Create a custom pin element
      const pinElement = new this.markerLibrary.PinElement({
        scale: 0.8,
        background: '#2196F3',
        borderColor: '#1976D2',
        glyphColor: 'white',
        glyph: pointNumber.toString(),
      });

      // Create the advanced marker
      const marker = new this.markerLibrary.AdvancedMarkerElement({
        map: this.map,
        position: latLng,
        content: pinElement.element,
        title: `Point ${pointNumber}`,
      });

      this.clickDrawingMarkers.push(marker);

    } catch (error) {
      // Fallback to circle marker if AdvancedMarkerElement fails
      this.createFallbackCircleMarker(latLng, pointNumber);
    }
  },

  /**
   * Fallback circle marker using Circle API
   */
  createFallbackCircleMarker(latLng, pointNumber) {
    const circle = new google.maps.Circle({
      map: this.map,
      center: latLng,
      radius: 8, // 8 meter radius
      fillColor: '#2196F3',
      fillOpacity: 1,
      strokeColor: '#1976D2',
      strokeWeight: 2,
      clickable: false,
    });

    if (!this.clickDrawingMarkers) {
      this.clickDrawingMarkers = [];
    }
    this.clickDrawingMarkers.push(circle);
  },

  /**
   * Show drawing feedback message
   */
  showDrawingFeedback(message) {
    const $ = jQuery;

    // Remove existing feedback
    $('#bme-drawing-feedback').remove();

    // Show new feedback
    const feedback = `<div id="bme-drawing-feedback" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.8); color: white; padding: 10px 20px; border-radius: 20px; z-index: 10002; font-size: 14px; text-align: center;">
      ${message}
    </div>`;

    $('body').append(feedback);

    // Auto-hide after 3 seconds
    setTimeout(() => {
      $('#bme-drawing-feedback').fadeOut(500, function() { $(this).remove(); });
    }, 3000);
  },

  /**
   * Toggle map controls visibility based on view mode
   * @param viewMode
   */
  toggleMapControlsVisibility(viewMode) {
    const $ = jQuery;
    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
      const $mapControls = $('#bme-map-controls');

      if (viewMode === 'map') {
        // In map view: show map controls container
        $mapControls.removeClass('force-hidden').show();

        // Use updatePolygonUI to manage button visibility (same as desktop)
        this.updatePolygonUI();
      } else {
        // In list view: hide map controls
        $mapControls.addClass('force-hidden').hide();

        // Also hide drawing panel in non-map views
        $('#bme-drawing-panel').removeClass('active');
      }
    }
  },

  // ==========================================================================
  // Mobile Recovery System (v6.24.4)
  // Handles race conditions where map loads without markers on mobile devices
  // ==========================================================================

  /**
   * v6.24.4: Mobile Recovery System
   * Sets up visibility-based recovery to handle cases where map loads without markers
   * Uses Page Visibility API and pageshow event for reliable detection
   */
  setupMobileRecovery() {
    const isMobile = window.innerWidth <= 768;
    if (!isMobile) {
      return;
    }

    // Single consolidated recovery check function
    const recoveryCheck = (source) => {
      const markerCount = typeof MLD_Markers !== 'undefined' && typeof MLD_Markers.getMarkerCount === 'function'
        ? MLD_Markers.getMarkerCount()
        : 0;

      if (markerCount === 0 && !this.isRecovering) {
        this.isRecovering = true;
        this.showLoadingIndicator();
        MLD_API.refreshMapListings(true);

        // Clear recovery flag after fetch should have completed
        setTimeout(() => {
          this.isRecovering = false;
          this.hideLoadingIndicator();
        }, 5000);
      } else if (markerCount > 0) {
        // Markers present, hide any loading indicator
        this.hideLoadingIndicator();
      }
    };

    // Use Page Visibility API for recovery (handles tab switching)
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        setTimeout(() => recoveryCheck('visibilitychange'), 500);
      }
    });

    // Handle browser back/forward cache (bfcache)
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        setTimeout(() => recoveryCheck('pageshow-bfcache'), 500);
      }
    });

    // Single 2.5-second safety net (replaces 3.5s, 5s, 8s trio)
    // Triggers shortly after normal load time (1-2s) to catch race conditions
    setTimeout(() => recoveryCheck('2.5s-safety-net'), 2500);
  },

  /**
   * v6.24.4: Show loading indicator on map
   * Displays a non-intrusive loading message when recovery fetch is triggered
   */
  showLoadingIndicator() {
    if (document.getElementById('mld-mobile-loading')) return;

    const mapContainer = document.getElementById('bme-map-container');
    if (!mapContainer) return;

    const indicator = document.createElement('div');
    indicator.id = 'mld-mobile-loading';
    indicator.innerHTML = '<span>Loading listings...</span>';
    mapContainer.appendChild(indicator);
  },

  /**
   * v6.24.4: Hide loading indicator
   */
  hideLoadingIndicator() {
    const indicator = document.getElementById('mld-mobile-loading');
    if (indicator) {
      indicator.remove();
    }
  },
};

/**
 * MLD Core Utility Module
 * Contains general helper functions and event handlers for the map application.
 */
const MLD_Core = {
  setupPropertyTypeSelectors() {
    const $ = jQuery;
    const originalSelect = $('#bme-property-type-select');
    const mobileContainer = $('#bme-property-type-mobile-container');
    if (!originalSelect.length || !mobileContainer.length) return;
    const clonedSelect = originalSelect
      .clone()
      .attr('id', 'bme-property-type-select-modal')
      .removeClass('bme-control-select');
    mobileContainer.append(clonedSelect);
    originalSelect.on('change', function () {
      clonedSelect.val($(this).val());
    });
    clonedSelect.on('change', function () {
      originalSelect.val($(this).val()).trigger('change');
    });
  },

  centerOnUserLocation() {
    const app = MLD_Map_App;
    const $ = jQuery;


    if (!navigator.geolocation) {
      alert('Geolocation is not supported by your browser.');
      $('#bme-nearby-toggle').prop('checked', false);
      return;
    }


    navigator.geolocation.getCurrentPosition(
      async (position) => {
        const isMapbox = bmeMapData.provider === 'mapbox';
        const center = isMapbox
          ? [position.coords.longitude, position.coords.latitude]
          : { lat: position.coords.latitude, lng: position.coords.longitude };

        app.isNearbySearchActive = true;

        MLDLogger.debug('Manual nearby location obtained', {
          lat: position.coords.latitude,
          lng: position.coords.longitude
        });

        // Use Google Geocoding to get the city (same as auto-detection)
        if (bmeMapData.provider === 'google' && window.google && window.google.maps) {
          try {
            const geocoder = new google.maps.Geocoder();
            const latlng = { lat: position.coords.latitude, lng: position.coords.longitude };

            geocoder.geocode({ location: latlng }, async (results, status) => {

              if (status === 'OK' && results[0]) {
                let detectedCity = null;
                let detectedState = null;

                // Extract city and state from geocode results
                // Try multiple strategies to find the city
                for (let i = 0; i < Math.min(results.length, 3); i++) {
                  for (let j = 0; j < results[i].address_components.length; j++) {
                    const component = results[i].address_components[j];
                    const types = component.types;

                    // Primary city detection
                    if (types.includes('locality') && !detectedCity) {
                      detectedCity = component.long_name;
                      MLDLogger.debug('Found city in locality', { city: detectedCity });
                    }

                    // Fallback to neighborhood or sublocality if no locality found
                    if (!detectedCity && (types.includes('neighborhood') ||
                        types.includes('sublocality') ||
                        types.includes('sublocality_level_1'))) {
                      // This might be a neighborhood, we'll check the parent components
                      continue;
                    }

                    // Alternative: postal_town (used in some areas)
                    if (!detectedCity && types.includes('postal_town')) {
                      detectedCity = component.long_name;
                      MLDLogger.debug('Found city in postal_town', { city: detectedCity });
                    }

                    // Get state
                    if (types.includes('administrative_area_level_1')) {
                      detectedState = component.short_name;
                    }
                  }

                  if (detectedCity) break;
                }

                // Log all address components for debugging
                if (!detectedCity && results[0]) {
                  MLDLogger.debug('Could not find city, full geocode result:', {
                    formatted_address: results[0].formatted_address,
                    components: results[0].address_components.map(c => ({
                      long_name: c.long_name,
                      types: c.types
                    }))
                  });
                }

                MLDLogger.debug('Detected location (manual)', { city: detectedCity, state: detectedState });

                if (detectedCity) {
                  // Check if the detected city exists in our database
                  const cityCheck = await MLD_Core.checkCityInDatabase(detectedCity);

                  if (cityCheck.exists) {
                    MLDLogger.debug('City found in database, adding to filters (manual)', {
                      city: detectedCity,
                      exactName: cityCheck.exactName,
                      count: cityCheck.count
                    });

                    // Use the exact city name from the database
                    const cityToAdd = cityCheck.exactName || detectedCity;

                    // Add city to filters - need to update both app and MLD_Filters
                    if (!app.keywordFilters['City']) {
                      app.keywordFilters['City'] = new Set();
                    }
                    app.keywordFilters['City'].add(cityToAdd);

                    // Also update MLD_Filters.keywordFilters
                    if (typeof MLD_Filters !== 'undefined') {

                      // Initialize keywordFilters if it doesn't exist
                      if (!MLD_Filters.keywordFilters) {
                        MLD_Filters.keywordFilters = {};
                      }

                      // Ensure City filter is a Set (it might exist as something else)
                      if (!MLD_Filters.keywordFilters['City'] || !(MLD_Filters.keywordFilters['City'] instanceof Set)) {
                        MLD_Filters.keywordFilters['City'] = new Set();
                      }
                      MLD_Filters.keywordFilters['City'].add(cityToAdd);

                      // Update the visual filter tags if the method exists
                      if (typeof MLD_Filters.renderFilterTags === 'function') {
                        MLD_Filters.renderFilterTags();
                      } else if (typeof MLD_Filters.updateFilterTags === 'function') {
                        MLD_Filters.updateFilterTags();
                      } else {
                      }

                      // Also update the city filter checkboxes if visible
                      const cityCheckboxes = document.querySelectorAll(`.filter-checkbox[data-filter="City"][value="${cityToAdd}"]`);
                      cityCheckboxes.forEach(checkbox => {
                        checkbox.checked = true;
                      });
                      if (cityCheckboxes.length > 0) {
                      }
                    } else {
                    }

                    // Update boundaries if city boundaries module is available
                    if (typeof MLD_CityBoundaries !== 'undefined') {
                      // DEBUG: commented
                      // Add delay to ensure map and filters are ready and listings are loaded
                      setTimeout(() => {
                        MLD_CityBoundaries.updateBoundariesFromFilters();
                      }, 1500);
                    } else {
                      // WARN: commented
                    }

                    // Set zoom and center with city filter
                    app.map.setZoom(15);
                    app.map.setCenter(center);
                    MLD_Markers.createUserLocationMarker(center, isMapbox);

                    // Refresh listings with city filter (delay for map readiness)
                    app.isLoadingAfterGeolocation = true;
                    setTimeout(() => {
                      MLD_API.refreshMapListings(true);
                      // Flag will be cleared after fitMapToBounds completes
                    }, 250);
                  } else {
                    MLDLogger.debug('City not in database, using nearby mode only (manual)', { city: detectedCity });

                    // Set zoom and center without city filter
                    app.map.setZoom(15);
                    app.map.setCenter(center);
                    MLD_Markers.createUserLocationMarker(center, isMapbox);

                    // Delay and set flag to prevent interference
                    app.isLoadingAfterGeolocation = true;
                    setTimeout(() => {
                      MLD_API.refreshMapListings(true);
                      // Flag will be cleared after fitMapToBounds completes
                    }, 250);
                  }
                } else {
                  // Could not detect city, just use location
                  app.map.setZoom(15);
                  app.map.setCenter(center);
                  MLD_Markers.createUserLocationMarker(center, isMapbox);

                  // Delay and set flag to prevent interference
                  app.isLoadingAfterGeolocation = true;
                  setTimeout(() => {
                    MLD_API.refreshMapListings(true);
                    // Flag will be cleared after fitMapToBounds completes
                  }, 250);
                }
              } else {
                // No geocoding results
                app.map.setZoom(15);
                app.map.setCenter(center);
                MLD_Markers.createUserLocationMarker(center, isMapbox);

                // Delay and set flag to prevent interference
                app.isLoadingAfterGeolocation = true;
                setTimeout(() => {
                  MLD_API.refreshMapListings(true);
                  // Flag will be cleared after fitMapToBounds completes
                }, 250);
              }
            });
          } catch (error) {
            MLDLogger.error('Error during geocoding (manual)', error);
            // Fallback to just using location
            app.map.setZoom(15);
            app.map.setCenter(center);
            MLD_Markers.createUserLocationMarker(center, isMapbox);

            // Delay and set flag to prevent interference
            app.isLoadingAfterGeolocation = true;
            setTimeout(() => {
              MLD_API.refreshMapListings(true);
              // Flag will be cleared after fitMapToBounds completes
            }, 250);
          }
        } else {
          // Not Google Maps or geocoding not available, just use location
          app.map.setZoom(15);
          app.map.setCenter(center);
          MLD_Markers.createUserLocationMarker(center, isMapbox);

          // Delay and set flag to prevent interference
          app.isLoadingAfterGeolocation = true;
          setTimeout(() => {
            MLD_API.refreshMapListings(true);
            // Flag will be cleared after fitMapToBounds completes
          }, 250);
        }
      },
      (error) => {
        app.isNearbySearchActive = false;

        // Provide specific error messages based on error code
        let errorMessage = '';
        switch(error.code) {
          case error.PERMISSION_DENIED:
            errorMessage = 'Location access was denied. Please enable location permissions for this site in your browser settings.';
            MLDLogger.debug('Geolocation permission denied');
            break;
          case error.POSITION_UNAVAILABLE:
            errorMessage = 'Location information is unavailable. Please check your device settings and try again.';
            MLDLogger.debug('Geolocation position unavailable');
            break;
          case error.TIMEOUT:
            errorMessage = 'Location request timed out. Please try again.';
            MLDLogger.debug('Geolocation request timed out');
            break;
          default:
            errorMessage = 'Unable to retrieve your location. Please try again or check your browser settings.';
            MLDLogger.debug('Geolocation unknown error', error);
        }

        alert(errorMessage);
        $('#bme-nearby-toggle').prop('checked', false);
        MLD_Markers.removeUserLocationMarker();
      },
      {
        enableHighAccuracy: false,
        timeout: 10000,
        maximumAge: 60000
      }
    );
  },

  /**
   * Attempts geolocation with city detection and filtering
   * Used on first page load to auto-enable nearby and detect city
   */
  attemptGeolocationWithCityDetection() {
    const app = MLD_Map_App;
    const $ = jQuery;

    MLD_Performance.mark('Geolocation Started');

    if (!navigator.geolocation) {
      MLDLogger.debug('Geolocation not supported, defaulting to Boston');
      this.setDefaultBostonView();
      return;
    }

    MLDLogger.debug('Attempting geolocation with city detection');

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        MLD_Performance.mark('Geolocation Success');
        const isMapbox = bmeMapData.provider === 'mapbox';
        const center = isMapbox
          ? [position.coords.longitude, position.coords.latitude]
          : { lat: position.coords.latitude, lng: position.coords.longitude };

        MLDLogger.debug('Geolocation successful', {
          lat: position.coords.latitude,
          lng: position.coords.longitude
        });

        // Use Google Geocoding to get the city
        if (bmeMapData.provider === 'google' && window.google && window.google.maps) {
          MLD_Performance.mark('Geocoding Started');
          try {
            const geocoder = new google.maps.Geocoder();
            const latlng = { lat: position.coords.latitude, lng: position.coords.longitude };

            // v6.20.13: Add geocoder timeout to prevent hanging on slow mobile networks
            let geocoderCompleted = false;
            const geocoderTimeoutId = setTimeout(() => {
              if (!geocoderCompleted) {
                MLDLogger.warning('Geocoder timeout after 3s, defaulting to Boston');
                geocoderCompleted = true; // Prevent callback from running if it comes later

                // Fallback: disable nearby and load all listings
                $('#bme-nearby-toggle').prop('checked', false);
                app.isNearbySearchActive = false;
                const bostonCenter = { lat: 42.3601, lng: -71.0589 };
                app.map.setCenter(bostonCenter);
                app.map.setZoom(11);
                MLD_API.refreshMapListings(true);
              }
            }, 3000); // 3 second geocoder timeout

            geocoder.geocode({ location: latlng }, async (results, status) => {
              // v6.20.13: Check if we already timed out
              if (geocoderCompleted) {
                return;
              }
              clearTimeout(geocoderTimeoutId);
              geocoderCompleted = true;

              MLD_Performance.mark('Geocoding Complete');

              if (status === 'OK' && results[0]) {
                let detectedCity = null;
                let detectedState = null;

                // Extract city and state from geocode results
                // Try multiple strategies to find the city

                for (let i = 0; i < Math.min(results.length, 3); i++) {

                  for (let j = 0; j < results[i].address_components.length; j++) {
                    const component = results[i].address_components[j];
                    const types = component.types;

                    // Primary city detection
                    if (types.includes('locality') && !detectedCity) {
                      detectedCity = component.long_name;
                      MLDLogger.debug('Found city in locality', { city: detectedCity });
                    }

                    // Alternative: sublocality_level_1 (sometimes used for cities)
                    if (!detectedCity && types.includes('sublocality_level_1')) {
                      detectedCity = component.long_name;
                      MLDLogger.debug('Found city in sublocality_level_1', { city: detectedCity });
                    }

                    // Alternative: postal_town (used in some areas)
                    if (!detectedCity && types.includes('postal_town')) {
                      detectedCity = component.long_name;
                      MLDLogger.debug('Found city in postal_town', { city: detectedCity });
                    }

                    // Get state
                    if (types.includes('administrative_area_level_1')) {
                      detectedState = component.short_name;
                    }
                  }

                  if (detectedCity) break;
                }

                // Log all address components for debugging
                if (!detectedCity && results[0]) {
                  results[0].address_components.forEach(comp => {
                    // DEBUG: commented
                  });

                  MLDLogger.debug('Could not find city, full geocode result:', {
                    formatted_address: results[0].formatted_address,
                    components: results[0].address_components.map(c => ({
                      long_name: c.long_name,
                      types: c.types
                    }))
                  });
                }

                MLDLogger.debug('Detected location', { city: detectedCity, state: detectedState });

                if (detectedCity) {
                  MLD_Performance.mark('City Check Started');

                  // Check if the detected city exists in our database
                  const cityCheck = await this.checkCityInDatabase(detectedCity);
                  MLD_Performance.mark('City Check Complete');


                  if (cityCheck.exists) {
                    MLDLogger.debug('City found in database, adding to filters', {
                      city: detectedCity,
                      exactName: cityCheck.exactName,
                      count: cityCheck.count
                    });

                    // Use the exact city name from the database
                    const cityToAdd = cityCheck.exactName || detectedCity;

                    // Add city to filters - need to update both app and MLD_Filters
                    if (!app.keywordFilters['City']) {
                      app.keywordFilters['City'] = new Set();
                    }
                    app.keywordFilters['City'].add(cityToAdd);

                    // Also update MLD_Filters.keywordFilters
                    if (typeof MLD_Filters !== 'undefined') {

                      // Initialize keywordFilters if it doesn't exist
                      if (!MLD_Filters.keywordFilters) {
                        MLD_Filters.keywordFilters = {};
                      }

                      // Ensure City filter is a Set (it might exist as something else)
                      if (!MLD_Filters.keywordFilters['City'] || !(MLD_Filters.keywordFilters['City'] instanceof Set)) {
                        MLD_Filters.keywordFilters['City'] = new Set();
                      }
                      MLD_Filters.keywordFilters['City'].add(cityToAdd);

                      // Update the visual filter tags if the method exists
                      if (typeof MLD_Filters.renderFilterTags === 'function') {
                        MLD_Filters.renderFilterTags();
                      } else if (typeof MLD_Filters.updateFilterTags === 'function') {
                        MLD_Filters.updateFilterTags();
                      } else {
                      }

                      // Also update the city filter checkboxes if visible
                      const cityCheckboxes = document.querySelectorAll(`.filter-checkbox[data-filter="City"][value="${cityToAdd}"]`);
                      cityCheckboxes.forEach(checkbox => {
                        checkbox.checked = true;
                      });
                      if (cityCheckboxes.length > 0) {
                      }
                    } else {
                    }

                    // Center map on user location with zoom
                    app.isNearbySearchActive = true;
                    app.map.setZoom(15);
                    app.map.setCenter(center);
                    MLD_Markers.createUserLocationMarker(center, isMapbox);

                    // Visitor state persistence removed - user location is not saved

                    // Refresh listings with the city filter (delay for map readiness)
                    app.isLoadingAfterGeolocation = true; // Prevent idle event interference
                    setTimeout(() => {
                      MLD_Performance.mark('Loading Listings');
                      // DEBUG: commented
                      // DEBUG: commented
                      // DEBUG: commented
                      if (typeof MLD_API !== 'undefined' && MLD_API.refreshMapListings) {
                        // DEBUG: commented
                        MLDLogger.debug('Calling MLD_API.refreshMapListings with city filter');
                        MLD_API.refreshMapListings(true);
                        // Flag will be cleared after fitMapToBounds completes
                      } else {
                        // DEBUG: commented
                        MLDLogger.error('MLD_API not available when trying to load listings!');
                        app.isLoadingAfterGeolocation = false;
                      }
                    }, 250);

                    // Update boundaries if city boundaries module is available
                    if (typeof MLD_CityBoundaries !== 'undefined') {
                      // DEBUG: commented
                      // Add delay to ensure map and filters are ready and listings are loaded
                      setTimeout(() => {
                        MLD_CityBoundaries.updateBoundariesFromFilters();
                      }, 1500);
                    } else {
                      // WARN: commented
                    }
                  } else {
                    MLDLogger.debug('City not in database, loading all listings', { city: detectedCity });

                    // City not in database, disable nearby mode and load all listings
                    $('#bme-nearby-toggle').prop('checked', false);
                    app.isNearbySearchActive = false;

                    // Remove user location marker
                    MLD_Markers.removeUserLocationMarker();

                    // Center map on Boston with wider view for all listings
                    const bostonCenter = isMapbox
                      ? [-71.0589, 42.3601]
                      : { lat: 42.3601, lng: -71.0589 };
                    app.map.setCenter(bostonCenter);
                    app.map.setZoom(11);

                    // Load all listings (delay for map readiness)
                    app.isLoadingAfterGeolocation = true; // Prevent idle event interference
                    setTimeout(() => {
                      MLD_Performance.mark('Loading Listings');
                      // DEBUG: commented
                      // DEBUG: commented
                      if (typeof MLD_API !== 'undefined' && MLD_API.refreshMapListings) {
                        // DEBUG: commented
                        MLDLogger.debug('Calling MLD_API.refreshMapListings - city not in database');
                        MLD_API.refreshMapListings(true);
                        // Flag will be cleared after fitMapToBounds completes
                      } else {
                        // DEBUG: commented
                        MLDLogger.error('MLD_API not available when trying to load listings!');
                        app.isLoadingAfterGeolocation = false;
                      }
                    }, 250);
                  }
                } else {
                  // Could not detect city from geocoding
                  MLDLogger.debug('Could not detect city, loading all listings');

                  // Disable nearby and load all listings
                  $('#bme-nearby-toggle').prop('checked', false);
                  app.isNearbySearchActive = false;

                  // Center map on Boston with wider view
                  const bostonCenter = isMapbox
                    ? [-71.0589, 42.3601]
                    : { lat: 42.3601, lng: -71.0589 };
                  app.map.setCenter(bostonCenter);
                  app.map.setZoom(11);

                  // Load all listings
                  MLD_API.refreshMapListings(true);
                }
              } else {
                // Geocoding failed
                MLDLogger.debug('Geocoding failed, loading all listings');

                // Disable nearby and load all listings
                $('#bme-nearby-toggle').prop('checked', false);
                app.isNearbySearchActive = false;

                // Center map on Boston with wider view
                const bostonCenter = isMapbox
                  ? [-71.0589, 42.3601]
                  : { lat: 42.3601, lng: -71.0589 };
                app.map.setCenter(bostonCenter);
                app.map.setZoom(11);

                // Load all listings
                MLD_API.refreshMapListings(true);
              }
            });
          } catch (error) {
            MLDLogger.error('Error during geocoding', error);

            // Error during geocoding, disable nearby and load all listings
            $('#bme-nearby-toggle').prop('checked', false);
            app.isNearbySearchActive = false;

            // Center map on Boston with wider view
            const bostonCenter = isMapbox
              ? [-71.0589, 42.3601]
              : { lat: 42.3601, lng: -71.0589 };
            app.map.setCenter(bostonCenter);
            app.map.setZoom(11);

            // Load all listings
            MLD_API.refreshMapListings(true);
          }
        } else {
          // Not Google Maps or geocoding not available

          // Disable nearby and load all listings
          $('#bme-nearby-toggle').prop('checked', false);
          app.isNearbySearchActive = false;

          // Center map on Boston with wider view
          const bostonCenter = isMapbox
            ? [-71.0589, 42.3601]
            : { lat: 42.3601, lng: -71.0589 };
          app.map.setCenter(bostonCenter);
          app.map.setZoom(11);

          // Load all listings
          MLD_API.refreshMapListings(true);
        }
      },
      (error) => {
        // Geolocation failed or denied, default to Boston
        MLDLogger.debug('Geolocation denied or failed, defaulting to Boston');
        this.setDefaultBostonView();
      },
      {
        timeout: 5000,
        maximumAge: 0,
        enableHighAccuracy: false
      }
    );
  },

  /**
   * Check if a city exists in our database
   */
  async checkCityInDatabase(cityName) {
    const checkStart = performance.now();

    // FIRST: Check pre-loaded cities list for instant lookup
    if (bmeMapData.available_cities && Array.isArray(bmeMapData.available_cities)) {
      const cityLower = cityName.toLowerCase();
      const foundCity = bmeMapData.available_cities.find(city =>
        city.name_lower === cityLower
      );

      if (foundCity) {
        const checkTime = performance.now() - checkStart;
        // DEBUG: commented
        MLDLogger.debug('City found in pre-loaded list', {
          city: cityName,
          exactName: foundCity.name,
          count: foundCity.count,
          time: checkTime.toFixed(0) + 'ms'
        });

        const result = {
          exists: true,
          exactName: foundCity.name,
          count: foundCity.count
        };

        // Also cache in sessionStorage for consistency
        const cacheKey = `mld_city_${cityLower}`;
        sessionStorage.setItem(cacheKey, JSON.stringify(result));

        return result;
      } else {
        // City not found in pre-loaded list
        const checkTime = performance.now() - checkStart;
        // DEBUG: commented
        MLDLogger.debug('City not found in pre-loaded list', { city: cityName });
        return { exists: false };
      }
    }

    // FALLBACK: Check sessionStorage cache (for backward compatibility)
    const cacheKey = `mld_city_${cityName.toLowerCase()}`;
    const cached = sessionStorage.getItem(cacheKey);
    if (cached) {
      try {
        const cachedData = JSON.parse(cached);
        const checkTime = performance.now() - checkStart;
        // DEBUG: commented
        MLDLogger.debug('Using cached city check result', cachedData);
        return cachedData;
      } catch (e) {
        // Invalid cache, continue
      }
    }

    // FINAL FALLBACK: AJAX call (only if pre-loaded list not available)
    MLDLogger.warning('Pre-loaded cities not available, falling back to AJAX');

    return new Promise((resolve) => {
      MLDLogger.debug('Checking city in database via AJAX', { city: cityName });

      // Use the correct ajax URL and nonce
      const ajaxUrl = bmeMapData.ajaxUrl || bmeMapData.ajax_url || '/wp-admin/admin-ajax.php';
      const nonce = bmeMapData.nonce || bmeMapData.security || '';

      jQuery.ajax({
        url: ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'check_city_exists',
          security: nonce,
          city: cityName
        },
        success: function(response) {
          if (response.success && response.data) {
            const checkTime = performance.now() - checkStart;
            // DEBUG: commented
            MLDLogger.debug('City check response', response.data);

            const result = {
              exists: response.data.exists,
              exactName: response.data.exact_name,
              count: response.data.count
            };

            // Cache the result
            sessionStorage.setItem(cacheKey, JSON.stringify(result));
            resolve(result);
          } else {
            MLDLogger.info('City check failed', response);
            resolve({ exists: false });
          }
        },
        error: function(xhr, status, error) {
          if (xhr.responseText && (xhr.responseText.includes('<!DOCTYPE') || xhr.responseText.includes('<html'))) {
            MLDLogger.error('Received HTML instead of JSON. AJAX endpoint not reached correctly.');
          }
          MLDLogger.error('Failed to check city in database', { status, error });
          resolve({ exists: false });
        }
      });
    });
  },

  /**
   * Set default view to Boston when geolocation fails
   */
  setDefaultBostonView() {
    const app = MLD_Map_App;
    const $ = jQuery;

    MLDLogger.debug('Setting default Boston view');

    // Disable nearby toggle
    $('#bme-nearby-toggle').prop('checked', false);
    app.isNearbySearchActive = false;

    // Center map on Boston
    const isMapbox = bmeMapData.provider === 'mapbox';
    const bostonCenter = isMapbox
      ? [-71.0589, 42.3601]
      : { lat: 42.3601, lng: -71.0589 };

    app.map.setCenter(bostonCenter);
    app.map.setZoom(11);

    // Remove any user location marker
    MLD_Markers.removeUserLocationMarker();

    // Refresh listings
    MLD_API.refreshMapListings(true);
  },

  debounce(func, delay) {
    let timeout;
    return function (...args) {
      const context = this;
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(context, args), delay);
    };
  },

  slugify(text) {
    if (typeof text !== 'string') return '';
    return text.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
  },

  formatCurrency(value) {
    const num = Number(value);
    if (isNaN(num)) return '';
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(num);
  },

  formatPrice(price) {
    price = parseFloat(price);
    if (isNaN(price)) return '';
    if (price < 10000) return `$${parseInt(price).toLocaleString('en-US')}`;
    if (price < 1000000) return `$${Math.round(price / 1000)}k`;
    return `$${(price / 1000000).toFixed(price < 10000000 ? 2 : 1)}m`;
  },

  getNormalizedCenter(mapInstance) {
    const center = mapInstance.getCenter();
    if (typeof center.lat === 'function') return { lat: center.lat(), lng: center.lng() };
    return { lat: center.lat, lng: center.lng };
  },

  getMapBounds() {
    const map = MLD_Map_App.map;
    if (!map) return null;

    // On mobile, also check if container is ready (v6.14.11)
    // This prevents returning invalid bounds before viewport has stabilized
    const isMobile = window.innerWidth <= 768;
    if (isMobile && MLD_Map_App.containerReady === false) {
      MLDLogger.debug('getMapBounds: Container not ready yet, returning null');
      return null;
    }

    try {
      if (bmeMapData.provider === 'google') {
        const b = map.getBounds();
        if (!b) return null;
        const ne = b.getNorthEast();
        const sw = b.getSouthWest();
        return { north: ne.lat(), south: sw.lat(), east: ne.lng(), west: sw.lng() };
      }
      const b = map.getBounds();
      if (!b) return null;
      return { north: b.getNorth(), south: b.getSouth(), east: b.getEast(), west: b.getWest() };
    } catch (e) {
      MLDLogger.error('Error getting map bounds:', e);
      return null;
    }
  },

  handleResize() {
    const map = MLD_Map_App.map;
    if (!map) return;
    if (bmeMapData.provider === 'google') {
      const center = map.getCenter();
      google.maps.event.trigger(map, 'resize');
      map.setCenter(center);
    } else {
      map.resize();
    }
    MLD_API.refreshMapListings(false);
  },

  /**
   * Initialize the resize handler for the listings column
   */
  initListingsResize() {
    const $ = jQuery;
    const handle = $('#bme-resize-handle');
    const listingsContainer = $('#bme-listings-list-container');
    const wrapper = $('#bme-half-map-wrapper');

    if (!handle.length || !listingsContainer.length) return;

    let isResizing = false;
    let startX = 0;
    let startWidth = 0;
    const minWidth = 280;
    const maxWidthPercent = 0.8;

    // State persistence removed - resize width is not saved
    // Always show animation and tooltip
    handle.addClass('first-load');

    // Show tooltip briefly
    const tooltip = handle.find('.bme-resize-handle-tooltip');
    setTimeout(() => {
      tooltip.css('opacity', '1');
      setTimeout(() => {
        tooltip.css('opacity', '');
      }, 3000);
    }, 500);

    // Remove animation class after it completes
    setTimeout(() => {
      handle.removeClass('first-load');
    }, 4000);

    handle.on('mousedown touchstart', function (e) {
      isResizing = true;
      startX = e.type.includes('touch') ? e.originalEvent.touches[0].pageX : e.pageX;
      startWidth = listingsContainer.outerWidth();
      $('body').css('cursor', 'col-resize');
      $('body').css('user-select', 'none');
      e.preventDefault();
    });

    $(document).on('mousemove touchmove', function (e) {
      if (!isResizing) return;

      const currentX = e.type.includes('touch') ? e.originalEvent.touches[0].pageX : e.pageX;
      const wrapperWidth = wrapper.width();
      const maxWidth = wrapperWidth * maxWidthPercent;
      const diff = startX - currentX;
      let newWidth = startWidth + diff;

      // Enforce min/max constraints
      newWidth = Math.max(minWidth, Math.min(newWidth, maxWidth));

      listingsContainer.css('flex', `0 0 ${newWidth}px`);
      MLD_Core.updateListingsColumns(newWidth);

      // Trigger map resize
      if (MLD_Map_App.map) {
        MLD_Core.handleResize();
      }
    });

    $(document).on('mouseup touchend', function () {
      if (isResizing) {
        isResizing = false;
        $('body').css('cursor', '');
        $('body').css('user-select', '');

        // State persistence removed - resize width is not saved
      }
    });

    // Update columns on window resize
    $(window).on(
      'resize',
      MLD_Core.debounce(function () {
        const width = listingsContainer.outerWidth();
        MLD_Core.updateListingsColumns(width);
      }, 250)
    );
  },

  /**
   * Update the number of columns in the listings grid based on container width
   * @param width
   */
  updateListingsColumns(width) {
    const $ = jQuery;
    const container = $('#bme-listings-list-container');
    if (!container.length) return;

    // Calculate number of columns based on width
    // Each card needs minimum 280px
    let cols = 1;
    if (width >= 590) cols = 2;
    if (width >= 890) cols = 3;
    if (width >= 1190) cols = 4;

    container.attr('data-cols', cols);
  },

  initEventDelegation() {
    const $ = jQuery;
    $('body').on('click', '.bme-card-image a, .bme-view-details-btn', (e) => e.stopPropagation());
    $('body').on('click', '.bme-popout-btn', function (e) {
      e.stopPropagation();
      const listingData = $(this).closest('.bme-popup-card-wrapper').data('listingData');
      if (listingData) {
        MLD_Core.openPropertyInNewWindow(listingData);
        MLD_Markers.closeListingPopup(listingData.ListingId);
      }
    });
    // Handle View Photos button click
    $('body').on('click', '.bme-view-photos-btn', function (e) {
      e.stopPropagation();
      e.preventDefault();
      const listingId = $(this).data('listing-id');
      if (listingId && typeof MLD_Gallery !== 'undefined') {
        MLD_Gallery.open(listingId);
      }
    });

    // Handle Favorite button click (v6.31.9)
    $('body').on('click', '.bme-favorite-btn', function (e) {
      e.stopPropagation();
      e.preventDefault();
      const $btn = $(this);
      const mlsNumber = $btn.data('mls');
      if (!mlsNumber) return;

      $btn.addClass('loading');

      $.ajax({
        url: bmeMapData.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_save_property',
          nonce: bmeMapData.nonce,
          mls_number: mlsNumber,
          action_type: 'toggle'
        },
        success: function (response) {
          $btn.removeClass('loading');
          if (response.success) {
            const heartFilledIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314z"/></svg>';
            const heartOutlineIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01L8 2.748zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143c.06.055.119.112.176.171a3.12 3.12 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15z"/></svg>';
            if (response.data.is_saved) {
              $btn.addClass('saved').html(heartFilledIcon);
            } else {
              $btn.removeClass('saved').html(heartOutlineIcon);
            }
          }
        },
        error: function () {
          $btn.removeClass('loading');
        }
      });
    });

    // Handle Hide button click (v6.31.9)
    $('body').on('click', '.bme-hide-btn', function (e) {
      e.stopPropagation();
      e.preventDefault();
      const $btn = $(this);
      const mlsNumber = $btn.data('mls');
      if (!mlsNumber) return;

      $btn.addClass('loading');
      const $card = $btn.closest('.bme-listing-card');

      $.ajax({
        url: bmeMapData.ajaxUrl,
        type: 'POST',
        data: {
          action: 'mld_hide_property',
          nonce: bmeMapData.nonce,
          mls_number: mlsNumber
        },
        success: function (response) {
          $btn.removeClass('loading');
          if (response.success && response.data.is_hidden) {
            // Animate card out and remove from DOM
            $card.css({
              transition: 'opacity 0.3s, transform 0.3s',
              opacity: 0,
              transform: 'scale(0.9)'
            });
            setTimeout(function () {
              $card.remove();
            }, 300);
          }
        },
        error: function () {
          $btn.removeClass('loading');
        }
      });
    });

    window.addEventListener('beforeunload', () => {
      for (const id in MLD_Map_App.openPopoutWindows) {
        if (MLD_Map_App.openPopoutWindows[id] && !MLD_Map_App.openPopoutWindows[id].closed)
          MLD_Map_App.openPopoutWindows[id].close();
      }
    });
    $('#bme-nearby-toggle').on('change', function () {
      const isChecked = $(this).is(':checked');

      if (isChecked) {
        MLD_Core.centerOnUserLocation();
      } else {
        // Turn off nearby mode
        MLD_Map_App.isNearbySearchActive = false;
        MLD_Markers.removeUserLocationMarker();

        // Clear any city filter that was added by nearby detection
        const app = MLD_Map_App;

        // Track if we had a city filter
        let hadCityFilter = false;

        // Clear from app.keywordFilters
        if (app.keywordFilters && app.keywordFilters['City']) {
          hadCityFilter = app.keywordFilters['City'].size > 0;
          delete app.keywordFilters['City'];
        }

        // Clear from MLD_Filters.keywordFilters
        if (typeof MLD_Filters !== 'undefined' && MLD_Filters.keywordFilters && MLD_Filters.keywordFilters['City']) {
          hadCityFilter = hadCityFilter || MLD_Filters.keywordFilters['City'].size > 0;
          delete MLD_Filters.keywordFilters['City'];
        }

        // If we had a city filter, update the display and refresh
        if (hadCityFilter) {

          // Update the visual filter tags
          if (typeof MLD_Filters !== 'undefined' && typeof MLD_Filters.renderFilterTags === 'function') {
            MLD_Filters.renderFilterTags();
          }

          // Uncheck city checkboxes
          const cityCheckboxes = document.querySelectorAll('.filter-checkbox[data-filter="City"]:checked');
          cityCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
          });

          // Update city boundaries if available
          if (typeof MLD_CityBoundaries !== 'undefined' && typeof MLD_CityBoundaries.updateBoundariesFromFilters === 'function') {
            MLD_CityBoundaries.updateBoundariesFromFilters();
          }

          // Refresh the map listings without the city filter
          MLD_API.refreshMapListings(true);
        }
      }

      // Visitor state persistence removed - nearby toggle state is not saved
    });

    // Drawing mode toggle handler
    $('body').on('click', '#bme-draw-toggle', function (e) {
      e.preventDefault();

      // Check if we're in list view and switch to map view if needed
      const $wrapper = $('#bme-half-map-wrapper');
      const $mapViewBtn = $('.bme-view-mode-btn[data-mode="map"]');
      const $drawToggle = $('#bme-draw-toggle');
      const isListView = $wrapper.hasClass('list-view') ||
                         $wrapper.hasClass('view-mode-list') ||
                         $('.bme-view-mode-btn[data-mode="list"]').hasClass('active');

      if (isListView && !$drawToggle.hasClass('active')) {
        // Switch to map view when activating draw in list view
        MLDLogger.info('Switching to map view for draw functionality');
        $mapViewBtn.trigger('click');
      }

      MLD_Map_App.toggleDrawingMode();
    });

    // Reset button handler (replaces clear shapes)
    $('body').on('click', '#bme-reset-button', function (e) {
      e.preventDefault();
      if (confirm('Are you sure you want to reset all drawn shapes?')) {
        MLD_Map_App.clearAllPolygons();
      }
    });

    // Complete shape button handler
    $('body').on('click', '#bme-complete-shape-button', function (e) {
      e.preventDefault();
      MLD_Map_App.completeCurrentShape();
    });

    // Edit shape name
    $('body').on('click', '.bme-polygon-name', function (e) {
      e.stopPropagation();
      const $name = $(this);
      const $input = $name.siblings('.bme-polygon-name-input');
      $name.hide();
      $input.show().focus().select();
    });

    // Save shape name on blur or enter
    $('body').on('blur', '.bme-polygon-name-input', function () {
      MLD_Map_App.saveShapeName($(this));
    });

    $('body').on('keypress', '.bme-polygon-name-input', function (e) {
      if (e.which === 13) {
        e.preventDefault();
        $(this).blur();
      }
    });

    window.addEventListener('resize', MLD_Core.debounce(MLD_Core.handleResize, 250));
  },

  updateModalVisibility() {
    const $ = jQuery;
    const rentalTypes = ['Residential Lease', 'Commercial Lease'];
    const saleTypes = [
      'Residential',
      'Residential Income',
      'Commercial Sale',
      'Business Opportunity',
      'Land',
    ];

    if (rentalTypes.includes(MLD_Map_App.selectedPropertyType)) {
      // For rentals: show rental filters, disable non-Active status options
      $('#bme-rental-filters').show();
      // Hide all status options except Active
      $('#bme-filter-status-dropdown .bme-select-option').each(function () {
        const $checkbox = $(this).find('input[type="checkbox"]');
        if ($checkbox.val() !== 'Active') {
          $(this).hide();
          $checkbox.prop('checked', false).prop('disabled', true);
        } else {
          $(this).show();
          $checkbox.prop('checked', true).prop('disabled', false);
        }
      });
      // Force status to Active only for rentals
      MLD_Map_App.modalFilters.status = ['Active'];
      // Update display text
      $('#bme-filter-status-display .bme-select-text').text('Active');
    } else if (saleTypes.includes(MLD_Map_App.selectedPropertyType)) {
      // For sales: hide rental filters, show all status options
      $('#bme-rental-filters').hide();
      $('#bme-filter-status-dropdown .bme-select-option').show();
      $('#bme-filter-status-dropdown input[type="checkbox"]').prop('disabled', false);
      // Keep current selection or default to Active
      const hasSelection =
        $('#bme-filter-status-dropdown input[type="checkbox"]:checked').length > 0;
      if (!hasSelection) {
        $('#bme-filter-status-dropdown input[value="Active"]').prop('checked', true);
        $('#bme-filter-status-display .bme-select-text').text('Active');
      }
    } else {
      // For other types
      $('#bme-rental-filters').hide();
      $('#bme-filter-status-dropdown .bme-select-option').show();
      $('#bme-filter-status-dropdown input[type="checkbox"]').prop('disabled', false);
    }
  },

  restoreStateFromUrl() {
    const hash = window.location.hash.substring(1);
    if (!hash) return false;
    const params = new URLSearchParams(hash);
    if ([...params.keys()].length === 0) return false;
    const newKeywordFilters = {};
    const newModalFilters = MLD_Filters.getModalDefaults();
    const defaultFilters = MLD_Filters.getModalDefaults();
    for (const [key, value] of params.entries()) {
      // Split by comma for filters that support multiple values
      // Note: Address and Street Address should NOT be split (they contain commas)
      const values = value.split(',');

      if (key === 'PropertyType') MLD_Map_App.selectedPropertyType = value;
      else if (key === 'listing_type') {
        // v6.59.0: Restore listing type toggle from URL
        MLD_Map_App.selectedListingType = value;
      }
      else if (
        [
          'City',
          'Building',
          'Neighborhood',
          'Postal Code',
          'Street Name',
          'Street Address',
          'MLS Number',
          'Address',
        ].includes(key)
      ) {
        // Address and Street Address can contain commas (e.g., "99 Grove St, Reading MA 01867")
        // so they should NOT be split by comma - treat as single value
        if (key === 'Address' || key === 'Street Address') {
          newKeywordFilters[key] = new Set([value]);
        } else {
          // Other filters can have multiple comma-separated values
          newKeywordFilters[key] = new Set(values);
        }
      }
      else if (key === 'direct_property_selection' && value === 'true') {
        // Set flag for direct property lookup (specific address or MLS #)
        MLD_Map_App.isSpecificPropertySearch = true;
      }
      else if (key === 'polygon_shapes') {
        // Restore polygon shapes from URL
        try {
          const polygonStrings = value.split('|');
          polygonStrings.forEach((polygonString) => {
            const coordinates = JSON.parse(decodeURIComponent(polygonString));
            if (Array.isArray(coordinates) && coordinates.length >= 3) {
              // Create polygon on map
              if (bmeMapData.provider === 'google') {
                const path = coordinates.map((coord) => new google.maps.LatLng(coord[0], coord[1]));
                const polygon = new google.maps.Polygon({
                  paths: path,
                  fillColor: '#2196F3',
                  fillOpacity: 0.2,
                  strokeColor: '#2196F3',
                  strokeWeight: 2,
                  clickable: true,
                });
                polygon.setMap(MLD_Map_App.map);

                const polygonData = {
                  id: 'polygon_' + Date.now() + '_' + Math.random(),
                  coordinates,
                  googlePolygon: polygon,
                  mapboxId: null,
                };

                MLD_Map_App.drawnPolygons.push(polygonData);

                // Add click listener for deletion
                google.maps.event.addListener(polygon, 'click', () => {
                  MLD_Map_App.deletePolygon(polygonData.id);
                });
              }
              // Note: Mapbox polygon restoration not implemented
            }
          });

          if (MLD_Map_App.drawnPolygons.length > 0) {
            MLD_Map_App.updatePolygonUI();
          }
        } catch (e) {
          MLDLogger.error('Error restoring polygon shapes from URL:', e);
        }
      } else if (defaultFilters.hasOwnProperty(key)) {
        if (typeof defaultFilters[key] === 'boolean') newModalFilters[key] = value === 'true';
        else if (Array.isArray(defaultFilters[key])) newModalFilters[key] = values;
        else newModalFilters[key] = value;
      }
    }
    MLD_Map_App.keywordFilters = newKeywordFilters;
    MLD_Map_App.modalFilters = newModalFilters;
    MLD_Filters.renderFilterTags();
    MLD_Filters.restoreModalUIToState();

    // v6.59.0: Update listing type toggle UI based on restored state
    const $ = jQuery;
    const listingType = MLD_Map_App.selectedListingType || 'for_sale';
    $('.mld-listing-type-btn').removeClass('active').attr('aria-pressed', 'false');
    $(`.mld-listing-type-btn[data-value="${listingType}"]`).addClass('active').attr('aria-pressed', 'true');
    MLD_Filters.updatePropertyTypeOptions(listingType);

    return true;
  },

  updateUrlHash() {
    const params = new URLSearchParams();
    const combined = MLD_Filters.getCombinedFilters();
    for (const key in combined) {
      const value = combined[key];
      if (Array.isArray(value) || value instanceof Set) {
        if (Array.from(value).length > 0) params.set(key, Array.from(value).join(','));
      } else if (value) {
        params.set(key, value.toString());
      }
    }

    // Add polygon shapes to URL
    if (MLD_Map_App.drawnPolygons.length > 0) {
      const polygonStrings = MLD_Map_App.drawnPolygons.map((p) =>
        encodeURIComponent(JSON.stringify(p.coordinates))
      );
      params.set('polygon_shapes', polygonStrings.join('|'));
    }

    const newHash = '#' + params.toString();
    if (window.location.hash !== newHash) history.replaceState(null, '', newHash);
  },

  // Focus mode functions removed - using modal instead

  updateSidebarList(listings) {
    const $ = jQuery;
    const container = $('#bme-listings-list-container .bme-listings-grid');
    if (container.length === 0) return;
    container.empty();
    if (!listings || listings.length === 0) {
      container.html('<p class="bme-list-placeholder">No listings found.</p>');
      return;
    }
    listings.forEach((listing) => {
      const card = $(this.createCardHTML(listing, 'sidebar'));
      card
        .on('mouseenter', () => MLD_Markers.highlightMarker(listing.ListingId, 'hover'))
        .on('mouseleave', () => {
          MLD_Markers.highlightMarker(listing.ListingId, 'none');
          MLD_Markers.reapplyActiveHighlights();
        });
      container.append(card);
    });

    // Trigger event for advanced features to add their buttons
    $(document).trigger('mld:listingsLoaded');
  },

  updateListingCountIndicator(showing, total) {
    const $indicator = jQuery('#bme-listings-count-indicator');
    if (total > 0) $indicator.text(`Showing ${showing} of ${total} Listings`).show();
    else $indicator.hide();
  },

  panTo(listing) {
    const lat = parseFloat(listing.Latitude);
    const lng = parseFloat(listing.Longitude);

    if (isNaN(lat) || isNaN(lng)) {
      MLDLogger.warning('Invalid coordinates for listing:', listing);
      return;
    }

    const pos = { lat, lng };
    if (bmeMapData.provider === 'google') MLD_Map_App.map.panTo(pos);
    else MLD_Map_App.map.panTo([pos.lng, pos.lat]);
  },

  fitMapToBounds(listings) {
    const map = MLD_Map_App.map;
    if (bmeMapData.provider === 'google') {
      const bounds = new google.maps.LatLngBounds();
      let validBoundsFound = false;
      listings.forEach((l) => {
        const lat = parseFloat(l.Latitude);
        const lng = parseFloat(l.Longitude);
        if (!isNaN(lat) && !isNaN(lng)) {
          bounds.extend(new google.maps.LatLng(lat, lng));
          validBoundsFound = true;
        }
      });
      if (validBoundsFound && !bounds.isEmpty()) {
        try {
          map.fitBounds(bounds);
        } catch (e) {
          MLDLogger.error('Error fitting bounds:', e);
          // Fallback to default location if bounds are invalid
          map.setCenter({ lat: 42.3601, lng: -71.0589 }); // Boston
          map.setZoom(10);
        }
      } else {
        // No valid coordinates found, set default location
        MLDLogger.warning('No valid coordinates found in listings, using default location');
        map.setCenter({ lat: 42.3601, lng: -71.0589 }); // Boston
        map.setZoom(10);
      }
    } else {
      const bounds = new mapboxgl.LngLatBounds();
      let validBoundsFound = false;
      listings.forEach((l) => {
        const lat = parseFloat(l.Latitude);
        const lng = parseFloat(l.Longitude);
        if (!isNaN(lat) && !isNaN(lng)) {
          bounds.extend([lng, lat]);
          validBoundsFound = true;
        }
      });
      if (validBoundsFound && !bounds.isEmpty()) {
        map.fitBounds(bounds, { padding: 100 });
      } else {
        // No valid coordinates found, set default location
        MLDLogger.warning('No valid coordinates found in listings, using default location');
        map.setCenter([-71.0589, 42.3601]); // Boston (lng, lat for Mapbox)
        map.setZoom(10);
      }
    }

    // Clear URL filters flag but let the API response handler clear isInitialLoad
    if (MLD_Map_App.hasUrlFilters) {
      MLD_Map_App.hasUrlFilters = false;
    }
  },

  openPropertyInNewWindow(listing) {
    const app = MLD_Map_App;
    const listingId = listing.ListingId;
    if (app.openPopoutWindows[listingId] && !app.openPopoutWindows[listingId].closed) {
      app.openPopoutWindows[listingId].focus();
      return;
    }
    // Set height to accommodate all content including listings with extra rows
    const features =
      'width=480,height=750,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes';
    const newWindow = window.open('', listingId, features);
    if (!newWindow) {
      alert('Please allow pop-ups for this website.');
      return;
    }
    app.openPopoutWindows[listingId] = newWindow;
    let styles = '';
    Array.from(document.styleSheets).forEach((sheet) => {
      try {
        if (sheet.href) styles += `<link rel="stylesheet" href="${sheet.href}">`;
      } catch (e) {
        MLDLogger.warning('Could not access stylesheet due to CORS policy: ', sheet.href);
      }
    });
    const popoutHTML = this.createCardHTML(listing, 'window');
    newWindow.document
      .write(`<html><head><title>${listing.StreetNumber} ${listing.StreetName} - Property Details</title>${styles}<style>
			body { 
				padding: 20px; 
				background-color: #f0f2f5; 
				margin: 0;
				overflow-y: auto;
				min-height: 100vh;
				box-sizing: border-box;
			} 
			.bme-listing-card { 
				box-shadow: none; 
				border: none; 
				margin-bottom: 30px; /* Extra space at bottom */
				height: auto !important; /* Ensure card expands to content */
				max-width: 100%;
			}
			/* Hide popout button and close button in popup window */
			.bme-popout-btn,
			.bme-popup-close { 
				display: none !important; 
			}
			/* Ensure details section is fully visible */
			.bme-card-details {
				padding-bottom: 20px;
			}
		</style></head><body>${popoutHTML}</body></html>`);
    newWindow.document.close();
    newWindow.addEventListener('beforeunload', () => {
      MLD_Markers.highlightMarker(listingId, 'none');
      delete app.openPopoutWindows[listingId];
    });
  },

  /**
   * Generates the HTML for a listing card.
   * @param listing
   * @param context
   */
  createCardHTML(listing, context = 'sidebar') {
    // The photo_url is now a direct property on the listing object.
    const photo = listing.photo_url || 'https://placehold.co/420x280/eee/ccc?text=No+Image';

    const addressLine1 = `${listing.StreetNumber || ''} ${listing.StreetName || ''}`.trim();
    const addressLine2 = `${listing.City}, ${listing.StateOrProvince} ${listing.PostalCode}`;
    const fullAddress = `${addressLine1}${listing.UnitNumber ? ' #' + listing.UnitNumber : ''}, ${addressLine2}`;

    const totalBaths =
      (parseInt(listing.BathroomsFull) || 0) + (parseInt(listing.BathroomsHalf) || 0) * 0.5;

    const isPriceDrop = parseFloat(listing.OriginalListPrice) > parseFloat(listing.ListPrice);

    // Check if this is a lease/rental property
    const propertyTypeRaw = listing.PropertyType || listing.property_type || '';
    const isLease = ['Residential Lease', 'Commercial Lease'].includes(propertyTypeRaw);

    // Calculate monthly mortgage estimate (20% down, 7% rate, 30yr)
    // Skip for lease properties - they already show price per month
    const listPrice = parseInt(listing.ListPrice) || 0;
    let monthlyEstimate = '';
    if (listPrice > 0 && !isLease) {
      const downPayment = listPrice * 0.20;
      const loanAmount = listPrice - downPayment;
      const monthlyRate = 0.07 / 12;
      const numPayments = 30 * 12;
      const monthlyPayment = loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, numPayments)) / (Math.pow(1 + monthlyRate, numPayments) - 1);
      monthlyEstimate = `Est. $${Math.round(monthlyPayment).toLocaleString()}/mo`;
    }

    // Calculate price reduction amount
    let priceReductionText = '';
    if (isPriceDrop) {
      const reduction = parseFloat(listing.OriginalListPrice) - parseFloat(listing.ListPrice);
      if (reduction >= 1000000) {
        priceReductionText = `-$${(reduction / 1000000).toFixed(1)}M`;
      } else if (reduction >= 1000) {
        priceReductionText = `-$${Math.round(reduction / 1000)}K`;
      } else {
        priceReductionText = `-$${Math.round(reduction).toLocaleString()}`;
      }
    }

    // Days on market
    const dom = listing.DaysOnMarket || listing.dom || listing.DOM || null;
    const daysOnMarketText = dom ? `${dom} days on market` : '';

    // Property type badge - use subtype for more specific display
    const propertySubType = listing.PropertySubType || listing.property_sub_type || listing.property_subtype || '';
    const propertyType = listing.PropertyType || listing.property_type || '';
    let propertyTypeBadge = '';
    const typeLabel = propertySubType || propertyType;
    if (typeLabel) {
      propertyTypeBadge = `<div class="bme-property-type-badge">${typeLabel}</div>`;
    }

    // Property highlights
    let highlightsHTML = '';
    const highlights = [];
    if (listing.PoolPrivateYN === 'Y' || listing.PoolPrivateYN === true || listing.pool === 'Y') highlights.push({icon: '🏊', label: 'Pool'});
    if (listing.WaterfrontYN === 'Y' || listing.WaterfrontYN === true || listing.waterfront === 'Y') highlights.push({icon: '🌊', label: 'Waterfront'});
    if (listing.View && listing.View.toLowerCase().includes('water')) highlights.push({icon: '👀', label: 'View'});
    if ((listing.GarageSpaces && parseInt(listing.GarageSpaces) > 0) || listing.garage === 'Y') highlights.push({icon: '🚗', label: 'Garage'});
    if (listing.FireplacesTotal && parseInt(listing.FireplacesTotal) > 0) highlights.push({icon: '🔥', label: 'Fireplace'});
    if (highlights.length > 0) {
      highlightsHTML = '<div class="bme-card-highlights">' + highlights.map(h => `<span class="bme-highlight-icon" title="${h.label}">${h.icon}</span>`).join('') + '</div>';
    }

    let tagsHTML = '';
    const openHouseData =
      typeof listing.OpenHouseData === 'string'
        ? JSON.parse(listing.OpenHouseData || '[]')
        : listing.OpenHouseData || [];

    // Check if property has open houses for price icon
    const hasOpenHouse = Array.isArray(openHouseData) && openHouseData.length > 0;
    const openHouseIcon = hasOpenHouse ?
      '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="margin-left: 6px; vertical-align: middle; opacity: 0.8;"><path d="M8.707 1.5a1 1 0 0 0-1.414 0L.646 8.146a.5.5 0 0 0 .708.708L2 8.207V13.5A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V8.207l.646.647a.5.5 0 0 0 .708-.708L13 5.793V2.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1.293L8.707 1.5ZM13 7.207V13.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V7.207l5-5 5 5Z"/><path d="M6 12.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5V15H9v-1.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5V15H6v-2.5Z"/></svg>'
      : '';
    // Add /mo suffix for lease properties
    const priceSuffix = isLease ? '/mo' : '';
    const price = `$${parseInt(listing.ListPrice).toLocaleString('en-US')}${priceSuffix}${openHouseIcon}`;

    if (Array.isArray(openHouseData) && openHouseData.length > 0) {
      const now = new Date();
      const timeZone = 'America/New_York';

      // Process all open houses with proper dates
      const processedOpenHouses = openHouseData.map((oh) => ({
        ...oh,
        startDateTime: new Date(
          oh.OpenHouseStartTime.endsWith('Z') ? oh.OpenHouseStartTime : oh.OpenHouseStartTime + 'Z'
        ),
        endDateTime: new Date(
          oh.OpenHouseEndTime.endsWith('Z') ? oh.OpenHouseEndTime : oh.OpenHouseEndTime + 'Z'
        ),
      }));

      // Find if there's an open house happening right now
      const currentOpenHouse = processedOpenHouses.find(
        (oh) => oh.startDateTime <= now && oh.endDateTime > now
      );

      if (currentOpenHouse) {
        // Open house is happening now - show "Open Now Until X"
        const endTime = currentOpenHouse.endDateTime
          .toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            timeZone,
            hour12: true,
          })
          .replace(' ', '');
        tagsHTML += `<div class="bme-card-tag open-house open-now">OPEN NOW UNTIL ${endTime}</div>`;
      } else {
        // No current open house - show next upcoming one
        const upcoming = processedOpenHouses
          .filter((oh) => oh.startDateTime > now)
          .sort((a, b) => a.startDateTime - b.startDateTime);
        if (upcoming.length > 0) {
          const nextOpenHouse = upcoming[0];
          const ohStart = nextOpenHouse.startDateTime;
          const day = ohStart
            .toLocaleDateString('en-US', { weekday: 'short', timeZone })
            .toUpperCase();
          const startTime = ohStart
            .toLocaleTimeString('en-US', {
              hour: 'numeric',
              minute: '2-digit',
              timeZone,
              hour12: true,
            })
            .replace(' ', '');
          tagsHTML += `<div class="bme-card-tag open-house">OPEN ${day}, ${startTime}</div>`;
        }
      }
    }
    if (isPriceDrop) tagsHTML += `<div class="bme-card-tag price-drop">${priceReductionText || 'Price Drop'}</div>`;
    if (listing.StandardStatus && listing.StandardStatus !== 'Active') {
      const statusClass =
        listing.StandardStatus.toLowerCase() === 'closed'
          ? 'sold'
          : listing.StandardStatus.toLowerCase() === 'pending'
            ? 'pending'
            : '';
      tagsHTML += `<div class="bme-card-tag status ${statusClass}">${listing.StandardStatus}</div>`;
    }

    // "Recommended by [Agent]" badge for shared properties (v6.35.9 - Sprint 3 Property Sharing)
    let fromAgentBadgeHTML = '';
    if (listing.is_shared_by_agent) {
      const agentName = listing.shared_by_agent_name || 'Agent';
      const agentPhoto = listing.shared_by_agent_photo || '';
      const photoHTML = agentPhoto
        ? `<img src="${agentPhoto}" alt="${agentName}" class="mld-agent-badge-photo" onerror="this.style.display='none'">`
        : '';
      fromAgentBadgeHTML = `<div class="mld-from-agent-badge">${photoHTML}<span>Recommended by ${agentName}</span></div>`;
    }

    // District school grade badge (v6.31.14 - moved to details section for cleaner layout)
    // Kept separate from tags to avoid overlap with action buttons
    let schoolBadgeHTML = '';
    if (listing.district_grade) {
      const grade = listing.district_grade; // Full grade: A+, A, A-, B+, etc.
      const gradeClass = grade.charAt(0).toLowerCase(); // a, b, c, etc.
      let badgeText = grade;
      if (listing.district_percentile !== null && listing.district_percentile !== undefined) {
        const topPercent = 100 - parseInt(listing.district_percentile);
        badgeText += ` top ${topPercent}%`;
      }
      schoolBadgeHTML = `<div class="bme-card-school-info grade-${gradeClass}"><span class="bme-school-icon">🎓</span> ${badgeText} Schools</div>`;
    } else if (listing.BestSchoolGrade) {
      // Fallback to old format for backwards compatibility
      const grade = listing.BestSchoolGrade.charAt(0).toUpperCase();
      const gradeClass = grade.toLowerCase();
      schoolBadgeHTML = `<div class="bme-card-school-info grade-${gradeClass}"><span class="bme-school-icon">🎓</span> ${grade} Schools</div>`;
    }

    let secondaryInfoHTML = '';
    if (listing.AssociationFee && parseFloat(listing.AssociationFee) > 0) {
      const frequency = (listing.AssociationFeeFrequency || 'Monthly').slice(0, 2).toLowerCase();
      secondaryInfoHTML += `<span>$${parseInt(listing.AssociationFee).toLocaleString()}/${frequency} HOA</span>`;
    }
    if (listing.GarageSpaces && parseInt(listing.GarageSpaces) > 0) {
      secondaryInfoHTML += `<span>${listing.GarageSpaces} Garage ${parseInt(listing.GarageSpaces) > 1 ? 'Spaces' : 'Space'}</span>`;
    }

    let cardControls = '';
    let imageHTML = `<img src="${photo}" alt="${fullAddress}" loading="lazy" onerror="this.onerror=null;this.src='https://placehold.co/420x280/eee/ccc?text=No+Image';">`;
    let detailsButtonHTML = '';

    if (context === 'sidebar')
      imageHTML = `<a href="/property/${listing.ListingId}">${imageHTML}</a>`;
    else if (context === 'popup' || context === 'window') {
      if (context === 'popup') {
        const popoutIcon =
          '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 1h6v6h-1V2.707L5.354 9.354l-.708-.708L11.293 2H6V1z"/><path d="M2 3.5A1.5 1.5 0 0 1 3.5 2H5v1H3.5a.5.5 0 0 0-.5.5v10a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5V11h1v2.5a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 2 13.5V3.5z"/></svg>';
        cardControls = `<button class="bme-popout-btn" title="Pop out card">${popoutIcon}</button>`;
      }
      detailsButtonHTML = `<a href="/property/${listing.ListingId}" class="bme-view-details-btn">View Details</a>`;
    }

    // Favorite and hide buttons (v6.31.12 - top-right corner icons, shifted left for popups)
    // Only show for logged-in users (bmeMapData.isUserLoggedIn set by PHP)
    const showActionButtons = typeof bmeMapData !== 'undefined' && bmeMapData.isUserLoggedIn;
    const heartIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 1.314C12.438-3.248 23.534 4.735 8 15-7.534 4.736 3.562-3.248 8 1.314z"/></svg>';
    const heartOutlineIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01L8 2.748zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143c.06.055.119.112.176.171a3.12 3.12 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15z"/></svg>';
    const closeIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>';

    // For popup/window cards, shift action buttons left to avoid close button overlap
    const isPopupContext = context === 'popup' || context === 'window';
    const actionButtonsStyle = isPopupContext ? 'style="right: 52px !important;"' : '';

    const actionButtonsHTML = showActionButtons ? `
      <div class="bme-card-actions" ${actionButtonsStyle}>
        <button class="bme-hide-btn" data-mls="${listing.ListingId}" title="Hide property">${closeIcon}</button>
        <button class="bme-favorite-btn" data-mls="${listing.ListingId}" title="Save property">${heartOutlineIcon}</button>
      </div>` : '';

    return `
			<div class="bme-listing-card" data-listing-id="${listing.ListingId}">
				<div class="bme-card-image">
					${imageHTML}
					<div class="bme-card-image-overlay">
						<div class="bme-card-tags">${tagsHTML}</div>
						${propertyTypeBadge}
						${actionButtonsHTML}
						${fromAgentBadgeHTML}
						<button class="bme-view-photos-btn" data-listing-id="${listing.ListingId}" title="View all photos">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
								<path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>
								<path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/>
							</svg>
							<span>View all photos</span>
						</button>
						 ${cardControls}
					</div>
				</div>
				<div class="bme-card-details">
					<div class="bme-card-header">
						<div class="bme-card-price">${price}</div>
						${monthlyEstimate ? `<div class="bme-card-monthly-estimate">${monthlyEstimate}</div>` : ''}
					</div>
					<div class="bme-card-specs">
						<span><strong>${listing.BedroomsTotal || 0}</strong> bds</span>
						<span class="bme-spec-divider"></span>
						<span><strong>${totalBaths}</strong> ba</span>
						<span class="bme-spec-divider"></span>
						<span><strong>${parseInt(listing.LivingArea || 0).toLocaleString()}</strong> sqft</span>
					</div>
					<div class="bme-card-address">
						<p>${addressLine1}${listing.UnitNumber ? ` #${listing.UnitNumber}` : ''}, ${listing.City}</p>
					</div>
					<div class="bme-card-meta">
						<span class="bme-card-mls">MLS# ${listing.ListingId}</span>
						${daysOnMarketText ? `<span class="bme-card-dom">${daysOnMarketText}</span>` : ''}
					</div>
					${highlightsHTML}
					${schoolBadgeHTML}
					${secondaryInfoHTML ? `<div class="bme-card-secondary-info">${secondaryInfoHTML}</div>` : ''}
					${detailsButtonHTML}
				</div>
			</div>`;
  },

  getIconForType(type) {
    const icons = {
      'Single Family':
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
      Condominium:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"></path><path d="M20 17v-5"></path><path d="M4 17v-5"></path><path d="M12 12V2l-7 5v5l7-5z"></path><path d="M20 12V2l-7 5v5l7-5z"></path><path d="M4 12V2l7 5v5l-7-5z"></path></svg>',
      Townhouse:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V8.2c0-.4.2-.8.5-1L10 3l6.5 4.2c.3.2.5.6.5 1V22"/><path d="M14 14v-3.1c0-.4.2-.8.5-1L20 6l-6.5-4.2c-.3-.2-.5-.6-.5-1V-3"/><path d="M10 22v-2.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 .5.5V22"/><path d="M10 14H4v8h6Z"/></svg>',
      Apartment:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 3v18"/><path d="M17 3v18"/><path d="M3 7h18"/><path d="M3 12h18"/><path d="M3 17h18"/></svg>',
      'Stock Cooperative':
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/></svg>',
      'Multi-Family':
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M9 15h6"></path><path d="M12 12v6"></path></svg>',
      'Mobile Home':
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17v-2.1c0-.6.4-1.2 1-1.4l7-3.5c.6-.3 1.4-.3 2 0l7 3.5c.6.2 1 .8 1 1.4V17"/><path d="M22 17H2"/><path d="M2 17v2a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-2"/><circle cx="8" cy="20" r="1"/><circle cx="16" cy="20" r="1"/></svg>',
      Farm: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"/><path d="M10 5V2"/><path d="M14 5V2"/><path d="M10 19v-5"/><path d="M14 19v-5"/></svg>',
      Parking:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V6h6.5a4.5 4.5 0 0 1 0 9H9Z"/></svg>',
      Commercial:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12h-8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8Z"/><path d="M7 21h10"/><path d="M12 3v9"/><path d="M19 12v9H5v-9Z"/></svg>',
      Default:
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 8v4l2 2"/></svg>',
    };
    const lowerType = type.toLowerCase();
    if (lowerType.includes('condo')) return icons.Condominium;
    if (lowerType.includes('single family')) return icons['Single Family'];
    if (lowerType.includes('apartment')) return icons.Apartment;
    if (
      lowerType.includes('townhouse') ||
      lowerType.includes('attached') ||
      lowerType.includes('duplex') ||
      lowerType.includes('condex')
    )
      return icons.Townhouse;
    if (lowerType.includes('family') || lowerType.includes('units')) return icons['Multi-Family'];
    if (lowerType.includes('cooperative')) return icons['Stock Cooperative'];
    if (lowerType.includes('mobile')) return icons['Mobile Home'];
    if (
      lowerType.includes('farm') ||
      lowerType.includes('equestrian') ||
      lowerType.includes('agriculture')
    )
      return icons.Farm;
    if (lowerType.includes('parking')) return icons.Parking;
    if (lowerType.includes('commercial')) return icons.Commercial;
    return icons.Default;
  },

  /**
   * Initialize view mode toggle for mobile
   */
  initViewModeToggle() {
    const $ = jQuery;
    const $toggle = $('#bme-view-mode-toggle');
    const $mapWrapper = $('.bme-map-ui-wrapper.bme-map-half');
    const $listingsContainer = $('#bme-listings-list-container');
    const $resizeHandle = $('#bme-resize-handle');

    if (!$toggle.length || window.innerWidth > 768) return;

    // Don't move elements - let CSS handle the display

    // State persistence removed - always default to list view on mobile
    const defaultMode = 'list';
    this.setViewMode(defaultMode);

    // Don't trigger extra refresh - the map idle event will handle it
    // if (savedMode === 'list' && MLD_Map_App.isInitialLoad) {
    //     // Force a refresh with filters only
    //     setTimeout(() => {
    //         MLD_API.refreshMapListings(true);
    //     }, 100);
    // }

    // Handle view mode button clicks
    $toggle.on('click', '.bme-view-mode-btn', function () {
      const mode = $(this).data('mode');
      MLD_Core.setViewMode(mode);
    });
  },

  /**
   * Set the view mode (list, map, or split)
   * @param mode
   */
  setViewMode(mode) {
    const $ = jQuery;
    const $toggle = $('#bme-view-mode-toggle');
    const $mapWrapper = $('.bme-map-ui-wrapper.bme-map-half');
    const $listingsContainer = $('#bme-listings-list-container');
    const $resizeHandle = $('#bme-resize-handle');
    const $halfMapWrapper = $('#bme-half-map-wrapper');
    const $mapControls = $('#bme-map-controls');

    // Update active button
    $toggle.find('.bme-view-mode-btn').removeClass('active');
    $toggle.find(`[data-mode="${mode}"]`).addClass('active');

    // Apply view mode
    $halfMapWrapper.removeClass('view-mode-list view-mode-map view-mode-split');
    $halfMapWrapper.addClass(`view-mode-${mode}`);

    // Only handle list and map modes on mobile
    if (window.innerWidth <= 768) {
      // Use the dedicated function to handle visibility
      MLD_Map_App.toggleMapControlsVisibility(mode);

      // Ensure map renders correctly when switching to map view
      if (mode === 'map' && MLD_Map_App.map) {
        setTimeout(() => {
          if (bmeMapData.provider === 'google' && google.maps.event) {
            google.maps.event.trigger(MLD_Map_App.map, 'resize');
          } else if (MLD_Map_App.map.resize) {
            // Mapbox
            MLD_Map_App.map.resize();
          }
        }, 100);
      } else if (mode === 'list') {
        // Exit drawing mode if active when switching to list
        if (MLD_Map_App.isDrawingMode) {
          MLD_Map_App.exitDrawingMode();
        }
      }
      // Map stays initialized in background for both views
    }

    // State persistence removed - view mode is not saved

    // Trigger resize event
    MLD_Core.handleResize();
  },

  /**
   * Prevent mobile zoom behaviors
   */
  preventMobileZoom() {
    // Update or add viewport meta tag
    let viewport = document.querySelector('meta[name="viewport"]');
    if (viewport) {
      viewport.content =
        'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
    } else {
      viewport = document.createElement('meta');
      viewport.name = 'viewport';
      viewport.content =
        'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
      document.head.appendChild(viewport);
    }

    // Prevent double-tap zoom on document
    let lastTouchEnd = 0;
    document.addEventListener(
      'touchend',
      function (e) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
          e.preventDefault();
        }
        lastTouchEnd = now;
      },
      false
    );
  },
};

const MLD_Utils = {
  get_field_label(key) {
    if (bmeMapData && bmeMapData.field_labels && bmeMapData.field_labels[key]) {
      return bmeMapData.field_labels[key];
    }
    if (typeof key !== 'string') return '';
    return key
      .replace(/YN$|_FLAG$|Enabled$/, '')
      .replace(/([A-Z])/g, ' $1')
      .replace(/^MLSPIN /, '')
      .replace(/^Has/, '')
      .trim();
  },
};

// Expose MLD_Map_App globally for Google Maps callback
window.MLD_Map_App = MLD_Map_App;
window.MLD_Core = MLD_Core;
// MLD_API is exposed in map-api.js
// MLD_Filters is exposed in map-filters.js
if (typeof MLD_Filters !== 'undefined') {
  window.MLD_Filters = MLD_Filters;
}
// Note: MLD_Markers is exposed in map-markers.js
// Note: MLD_Gallery is exposed in map-gallery.js
window.MLD_Utils = MLD_Utils;

(function ($) {
  // Only initialize if not using Google Maps with async loading
  if (typeof bmeMapData !== 'undefined' && bmeMapData.provider !== 'google') {
    $(document).ready(() => MLD_Map_App.init());
  }
  // Removed ajaxComplete handler that was causing multiple initializations
})(jQuery);

// Add global function to reset first load detection for testing
window.resetMLDFirstLoad = function() {
  sessionStorage.removeItem('mld_map_loaded');
  sessionStorage.removeItem('mld_navigated_away');
};

// Display debugging info on load
