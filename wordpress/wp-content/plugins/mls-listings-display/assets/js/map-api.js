/**
 * MLD Map API Module
 * Version 5.0
 *
 * Version 5.0 Changes (2025-11-26):
 * - Added request queue with coalescing to prevent lost filter changes
 * - Added adaptive throttling based on device/connection speed
 * - Added request ID tracking to detect stale responses
 * - Added loading state indicators (spinner, error messages)
 * - Added automatic network retry with exponential backoff
 * - Improved mobile reliability with longer intervals
 *
 * Version 4.0 Changes:
 * - Removed background cache system (fetchAllListingsInBatches)
 * - Added request management with cancellation and throttling
 * - Minimum request interval: 200ms
 * - Added retry logic for bounds validation
 * - Improved error handling for aborted requests
 * - Optimized for viewport-based loading instead of full cache
 */
const MLD_API = {
  // Request management
  pendingRequest: null,
  lastRequestTime: 0,
  minRequestInterval: 200, // Base interval - adjusted by getAdaptiveInterval()

  // Request queue for coalescing (v5.0)
  pendingParams: null,        // Latest params waiting to be sent
  queueTimer: null,           // Timer for queue processing
  requestId: 0,               // Unique ID for tracking requests

  // The background cache system has been removed to improve performance with large datasets.
  // Instead, we now use smart viewport-based loading with zoom-based limits.

  /**
   * Get adaptive request interval based on device and connection
   * Mobile devices and slow connections get longer intervals for reliability
   */
  getAdaptiveInterval() {
    const isMobile = window.innerWidth <= 768;
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const isSlowConnection = connection &&
        (connection.effectiveType === '2g' ||
         connection.effectiveType === 'slow-2g' ||
         connection.saveData === true);

    if (isSlowConnection) return 500;  // 500ms for slow connections
    if (isMobile) return 300;          // 300ms for mobile
    return 200;                        // 200ms for desktop
  },

  /**
   * Show loading indicator on the map
   */
  showLoading() {
    const loader = document.getElementById('mld-map-loading');
    if (loader) {
      loader.style.display = 'flex';
    }
  },

  /**
   * Hide loading indicator
   */
  hideLoading() {
    const loader = document.getElementById('mld-map-loading');
    if (loader) {
      loader.style.display = 'none';
    }
  },

  /**
   * Show error message to user
   * @param {string} message - Error message to display
   * @param {number} duration - How long to show (ms), 0 = permanent until dismissed
   */
  showError(message, duration = 5000) {
    // Remove any existing error
    this.hideError();

    const errorDiv = document.createElement('div');
    errorDiv.id = 'mld-map-error';
    errorDiv.className = 'mld-map-error';
    errorDiv.innerHTML = `
      <span class="mld-error-text">${message}</span>
      <button class="mld-error-dismiss" onclick="MLD_API.hideError()" aria-label="Dismiss">&times;</button>
    `;

    const mapContainer = document.getElementById('bme-map-container');
    if (mapContainer) {
      mapContainer.appendChild(errorDiv);
    }

    if (duration > 0) {
      setTimeout(() => this.hideError(), duration);
    }
  },

  /**
   * Hide error message
   */
  hideError() {
    const errorDiv = document.getElementById('mld-map-error');
    if (errorDiv) {
      errorDiv.remove();
    }
  },

  fetchDynamicFilterOptions() {
    // Add loading state to filter sections
    const $ = jQuery;
    $(
      '#bme-filter-home-type, #bme-filter-structure-type, #bme-filter-architectural-style, #bme-filter-amenities'
    ).addClass('bme-loading');

    const allBooleanFilters = Object.keys(MLD_Filters.getModalDefaults()).filter(
      (k) => typeof MLD_Filters.getModalDefaults()[k] === 'boolean'
    );
    // Get current state including all selections
    const currentState = MLD_Filters.getModalState(true);

    // Only exclude the filters we're about to populate (not structure_type or architectural_style)
    // This way structure_type affects style counts and vice versa
    const filtersToExclude = ['home_type', 'status'];

    // For boolean filters, exclude all except open_house_only and show_sold
    const booleanFiltersToExclude = allBooleanFilters.filter(
      (f) => f !== 'open_house_only' && f !== 'show_sold'
    );

    // Build context filters that include structure_type and architectural_style
    const contextFilters = MLD_Filters.getCombinedFilters(currentState, [
      ...filtersToExclude,
      ...booleanFiltersToExclude,
    ]);

    // Debug logging for polygon filters
    if (contextFilters.polygon_shapes && contextFilters.polygon_shapes.length > 0) {
      // Debug: Fetching filter options with polygon shapes
    }

    jQuery
      .post(bmeMapData.ajax_url, {
        action: 'get_filter_options',
        security: bmeMapData.security,
        filters: JSON.stringify(contextFilters),
      })
      .done(function (response) {
        if (response.success && response.data) {
          MLD_Filters.populateHomeTypes(response.data.PropertySubType || []);
          MLD_Filters.populateStatusTypes(response.data.StandardStatus || []);
          MLD_Filters.populateDynamicCheckboxes(
            '#bme-filter-structure-type',
            response.data.StructureType || []
          );
          MLD_Filters.populateDynamicCheckboxes(
            '#bme-filter-architectural-style',
            response.data.ArchitecturalStyle || []
          );
          MLD_Filters.populateDynamicAmenityCheckboxes(response.data.amenities || {});
        }
      })
      .fail(function () {
        MLDLogger.error('Failed to fetch dynamic filter options.');
      })
      .always(function () {
        // Remove loading state
        jQuery(
          '#bme-filter-home-type, #bme-filter-structure-type, #bme-filter-architectural-style, #bme-filter-amenities'
        ).removeClass('bme-loading');
      });

    // Fetch price distribution with its own context
    this.fetchPriceDistribution();
  },

  fetchPriceDistribution() {
    // Exclude only price fields to get a dynamic range based on all other active filters.
    const contextFilters = MLD_Filters.getCombinedFilters(MLD_Filters.getModalState(true), [
      'price_min',
      'price_max',
    ]);
    jQuery
      .post(bmeMapData.ajax_url, {
        action: 'get_price_distribution',
        security: bmeMapData.security,
        filters: JSON.stringify(contextFilters),
      })
      .done(function (response) {
        if (response.success && response.data) {
          MLD_Map_App.priceSliderData = response.data;
          MLD_Filters.updatePriceSliderUI();
        }
      })
      .fail(function () {
        MLDLogger.error('Failed to fetch price distribution data.');
      });
  },

  updateFilterCount() {
    const tempFilters = MLD_Filters.getModalState(true);
    const combined = MLD_Filters.getCombinedFilters(tempFilters);

    // Add loading state to the apply button
    const $applyBtn = jQuery('#bme-apply-filters-btn');
    const originalText = $applyBtn.text();
    $applyBtn.prop('disabled', true).addClass('bme-loading');

    jQuery
      .post(bmeMapData.ajax_url, {
        action: 'get_filtered_count',
        security: bmeMapData.security,
        filters: JSON.stringify(combined),
      })
      .done(function (response) {
        if (response.success) {
          jQuery('#bme-apply-filters-btn').text(`See ${response.data} Listings`);
        }
      })
      .fail(function (xhr, status, error) {
        MLDLogger.error('Failed to update filter count.');
        jQuery('#bme-apply-filters-btn').text(`See Listings`);
      })
      .always(function () {
        jQuery('#bme-apply-filters-btn').prop('disabled', false).removeClass('bme-loading');
      });
  },

  refreshMapListings(forceRefresh = false, retryCount = 0, fitToResults = false) {
    MLDLogger.debug('refreshMapListings called', { forceRefresh, retryCount, fitToResults });

    // Store fitToResults flag for use in AJAX callback
    // This is more reliable than the global flag which can get out of sync
    this._pendingFitToResults = fitToResults;
    const app = MLD_Map_App;

    if (app.isUnitFocusMode) {
      MLDLogger.debug('Skipping refresh - unit focus mode active');
      return;
    }

    // Cancel any pending request
    if (this.pendingRequest && this.pendingRequest.abort) {
      MLDLogger.debug('Cancelling pending request');
      this.pendingRequest.abort();
      this.pendingRequest = null;
    }

    // Throttle requests with queue-based coalescing (v5.0)
    // Instead of dropping throttled requests, queue them to preserve filter changes
    const now = Date.now();
    const adaptiveInterval = this.getAdaptiveInterval();

    if (!app.isInitialLoad && !forceRefresh) {
      const timeSinceLastRequest = now - this.lastRequestTime;

      if (timeSinceLastRequest < adaptiveInterval) {
        // Queue the request params instead of dropping
        // This ensures filter changes aren't lost during rapid interactions
        this.pendingParams = { forceRefresh, retryCount, fitToResults };

        // Schedule queue processing if not already scheduled
        if (!this.queueTimer) {
          const waitTime = adaptiveInterval - timeSinceLastRequest;
          MLDLogger.debug(`Queueing request, will process in ${waitTime}ms`);

          this.queueTimer = setTimeout(() => {
            this.queueTimer = null;
            if (this.pendingParams) {
              const params = this.pendingParams;
              this.pendingParams = null;
              MLDLogger.debug('Processing queued request', params);
              this.refreshMapListings(params.forceRefresh, params.retryCount, params.fitToResults);
            }
          }, waitTime);
        } else {
          MLDLogger.debug('Request queued (timer already pending)');
        }
        return;
      }
    }

    const currentZoom = app.map ? app.map.getZoom() : 13;
    const currentCenter = app.map
      ? MLD_Core.getNormalizedCenter(app.map)
      : { lat: 42.3601, lng: -71.0589 };
    const isInitial = app.isInitialLoad;
    const bounds = MLD_Core.getMapBounds();

    // DEBUG: commented

    // If we can't get bounds and it's not a forced refresh, retry after a short delay
    if (!bounds && !forceRefresh && retryCount < 3) {
      // DEBUG: commented
      MLDLogger.warning(`Unable to get map bounds, retrying... (attempt ${retryCount + 1})`);
      setTimeout(
        () => {
          this.refreshMapListings(forceRefresh, retryCount + 1);
        },
        100 + retryCount * 50
      ); // Faster retry: 100ms, 150ms, 200ms
      return;
    }

    MLDLogger.debug('refreshMapListings called:', {
      forceRefresh,
      isInitial,
      bounds: bounds ? bounds : 'not available',
      center: currentCenter,
      zoom: currentZoom,
      retryCount,
    });

    // Special logging for state restoration debugging
    if (forceRefresh) {
      // Force refresh triggered for state restoration
    }

    if (!forceRefresh) {
      // More significant thresholds to reduce unnecessary updates
      const centerChanged =
        Math.abs(currentCenter.lat - app.lastMapState.lat) > 0.001 ||
        Math.abs(currentCenter.lng - app.lastMapState.lng) > 0.001;
      const zoomChanged = Math.abs(currentZoom - app.lastMapState.zoom) >= 1; // Only update on full zoom level changes

      MLDLogger.debug('Map state check', {
        currentCenter,
        lastMapState: app.lastMapState,
        centerChanged,
        zoomChanged
      });

      if (!centerChanged && !zoomChanged) {
        MLDLogger.debug('Map state not changed significantly, skipping refresh');
        return;
      }
    }

    app.lastMapState = { lat: currentCenter.lat, lng: currentCenter.lng, zoom: currentZoom };

    // The server now returns optimized results based on zoom level

    const combinedFilters = MLD_Filters.getCombinedFilters();
    const hasFilters = Object.keys(combinedFilters).length > 0;

    // Debug: Log combined filters to console
    if (hasFilters) {
      // Debug: Combined filters being sent to server
    }

    // Debug the state restoration logic
    // More robust state restoration detection:
    // 1. Original logic: forceRefresh && !isInitial
    // 2. Fallback: forceRefresh from waitForMapReadyAndRefresh (has specific source)
    const isFromStateRestoration = window.MLD_StateRestorationInProgress || false;
    const isStateRestoration = (forceRefresh && !isInitial) || isFromStateRestoration;

    if (forceRefresh) {
        // Force refresh logic - state restoration processing
    }

    let requestData = {
      action: 'get_map_listings',
      security: bmeMapData.security,
      is_new_filter: forceRefresh && hasFilters,
      is_state_restoration: isStateRestoration, // Flag for state restoration to bypass cache
      is_initial_load: isInitial,
      zoom: currentZoom,
    };

    // On initial load, always treat as new filter to get all listings
    // Do NOT add bounds on initial load - we need all listings to determine where to center
    if (isInitial) {
      requestData.is_new_filter = true;
      // Skip adding bounds - fetch all listings for initial load
    } else if (!requestData.is_new_filter) {
      // Always use bounds for viewport-based loading when not applying new filters
      // This ensures we only load what's visible, even with filters active
      if (bounds) {
        requestData = { ...requestData, ...bounds };
      } else if (window.innerWidth <= 768 && hasFilters) {
        // On mobile in list view with filters, treat as filter request
        requestData.is_new_filter = true;
      } else {
        // Fallback: if no bounds available after retries, log warning but continue
        MLDLogger.warning('No bounds available for map query, fetching without spatial filter');
      }
    }

    // Special case: For state restoration, ALWAYS include bounds regardless of other flags
    // BUT NOT when fitToResults is requested (user selected a filter and wants to see all matches)
    if (isStateRestoration && bounds && !fitToResults) {
      // Adding bounds to state restoration request
      requestData = { ...requestData, ...bounds };
    }

    // Additional fallback: If we have bounds and this is a forced refresh (not initial), add bounds
    // This handles cases where isStateRestoration detection might fail across environments
    // BUT NOT when:
    // - is_new_filter is true (applying a new filter, need all matching results)
    // - fitToResults is true (user selected from autocomplete, need all matching results)
    if (forceRefresh && !isInitial && bounds && !requestData.north && !requestData.is_new_filter && !fitToResults) {
      // Adding bounds to force refresh request (but not on initial load or new filter)
      requestData = { ...requestData, ...bounds };
    }

    if (hasFilters) {
      requestData.filters = JSON.stringify(combinedFilters);
    }

    // Track request time
    this.lastRequestTime = Date.now();

    // Generate unique request ID for tracking (v5.0)
    this.requestId++;
    const currentRequestId = this.requestId;
    requestData.request_id = currentRequestId;

    MLDLogger.debug('Making AJAX request with data:', { ...requestData, requestId: currentRequestId });

    // Debug log for zoom 14-15 issue
    if (currentZoom >= 14 && currentZoom <= 15) {
      MLDLogger.debug('MLD Debug - Zoom', currentZoom, 'request data:', requestData);
      if (combinedFilters && combinedFilters.City) {
        MLDLogger.debug('MLD Debug - City filter active:', combinedFilters.City);
      }
    }

    // Show loading indicator (v5.0)
    this.showLoading();

    // Store the request so we can cancel it if needed
    this.pendingRequest = jQuery
      .post(bmeMapData.ajax_url, requestData)
      .done((response) => {
        this.pendingRequest = null;

        // Check if this response is stale (a newer request was made) (v5.0)
        if (currentRequestId !== this.requestId) {
          MLDLogger.debug('Ignoring stale response', { received: currentRequestId, current: this.requestId });
          return;
        }

        // Hide loading indicator
        this.hideLoading();
        // Mark when response is received for performance tracking
        if (window.MLD_Performance) {
          MLD_Performance.mark('AJAX Response Received');
        }
        MLDLogger.debug('AJAX response received:', { success: response.success, listings: response.data ? response.data.listings?.length : 0 });

        // AJAX response received successfully

        if (response.success && response.data) {
          const listings = response.data.listings || [];
          const total = response.data.total || 0;

          // Check if this is a specific property search with only one result
          // If so, redirect directly to the property details page
          if (app.isSpecificPropertySearch && listings.length === 1) {
            const listing = listings[0];
            const listingId = listing.listing_id || listing.ListingId;

            if (listingId) {
              // Clear the search flags
              app.isSpecificPropertySearch = false;
              app.lastSearchType = null;

              // Show a brief loading message
              const $ = jQuery;
              const loadingHtml =
                '<div id="bme-redirect-loading" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 9999; transition: opacity 0.3s ease;">Redirecting to property details...</div>';
              $('body').append(loadingHtml);

              // Auto-remove the message after 2 seconds in case user hits back button
              setTimeout(() => {
                $('#bme-redirect-loading').fadeOut(300, function () {
                  $(this).remove();
                });
              }, 2000);

              // Redirect to property details page
              setTimeout(() => {
                window.location.href = '/property/' + listingId + '/';
              }, 500);
              return; // Exit early, no need to render map markers
            }
          }

          // Clear the specific property search flags if we didn't redirect
          app.isSpecificPropertySearch = false;
          app.lastSearchType = null;

          // Mark start of rendering
          if (window.MLD_Performance) {
            MLD_Performance.mark('Rendering Started');
          }

          MLD_Markers.renderNewMarkers(listings);

          if (window.MLD_Performance) {
            MLD_Performance.mark('Markers Rendered');
          }

          MLD_Core.updateSidebarList(listings);

          if (window.MLD_Performance) {
            MLD_Performance.mark('Sidebar Updated');
          }

          MLD_Core.updateListingCountIndicator(listings.length, total);

          // Dispatch search event for analytics tracking (v6.44.0)
          // Only dispatch when a filter was applied (forceRefresh) or on initial search
          if ((forceRefresh || isInitial) && hasFilters) {
            document.dispatchEvent(new CustomEvent('mld:search_execute', {
              detail: {
                filters: combinedFilters,
                count: total
              }
            }));
          }

          // Skip fitMapToBounds when in nearby mode or restoring visitor state - preserve user's position
          // Only fit bounds for non-nearby searches or when explicitly needed
          // EXCEPTION: When shouldFitToFilterResults is true (user applied autocomplete filter), ALWAYS fit bounds
          // This overrides isNearbySearchActive and isRestoringVisitorState for explicit filter changes
          const isFirstLoad = !app.markers || app.markers.length === 0;
          const fitFlagWasSet = app.shouldFitToFilterResults === true;
          // Also check the direct parameter passed through refreshMapListings
          const fitParamWasSet = MLD_API._pendingFitToResults === true;
          const hasFilterThatRequiresFit = fitFlagWasSet || fitParamWasSet;

          // Priority order:
          // 1. If hasFilterThatRequiresFit is true, ALWAYS fit bounds (user explicitly changed filters)
          // 2. Otherwise, use the normal logic (forceRefresh/isInitial/isFirstLoad with nearby/restore checks)
          const shouldFitBounds = listings.length > 0 && (
            hasFilterThatRequiresFit ||
            ((forceRefresh || isInitial || isFirstLoad) && !app.isNearbySearchActive && !app.isRestoringVisitorState)
          );

          // Clear the fit flags after checking them (one-time use per filter application)
          if (app.shouldFitToFilterResults) {
            app.shouldFitToFilterResults = false;
          }
          if (MLD_API._pendingFitToResults) {
            MLD_API._pendingFitToResults = false;
          }

          if (shouldFitBounds) {
            // Temporarily disable map change events to prevent duplicate refreshMapListings calls
            // during fitMapToBounds which changes zoom/center
            app.isAdjustingMapBounds = true;
            MLD_Core.fitMapToBounds(listings);
            // Re-enable after a short delay to allow map to settle
            setTimeout(() => {
              app.isAdjustingMapBounds = false;
              // Also clear the geolocation flag if it's still set
              if (app.isLoadingAfterGeolocation) {
                app.isLoadingAfterGeolocation = false;
              }
            }, 100);
          } else {
            // fitMapToBounds skipped - log the reason
            let skipReason = [];
            if (!forceRefresh) skipReason.push('not force refresh');
            if (listings.length === 0) skipReason.push('no listings');
            if (app.isNearbySearchActive) skipReason.push('nearby mode active');
            if (app.isRestoringVisitorState) skipReason.push('restoring visitor state');
            // DEBUG: commented

            // If we're not fitting bounds (including nearby mode), clear the flags anyway
            if (app.isLoadingAfterGeolocation) {
              setTimeout(() => {
                app.isLoadingAfterGeolocation = false;
              }, 100);
            }
            if (app.isAdjustingMapBounds) {
              app.isAdjustingMapBounds = false;
            }
          }

          // Always mark when listings are loaded for performance tracking
          if (window.MLD_Performance) {
            if (app.isInitialLoad) {
              MLD_Performance.mark('Listings Loaded');
              MLD_Performance.summary();
            } else {
              MLD_Performance.mark('Listings Refreshed');
            }
          }

          // Clear initial load flag after successful first fetch
          if (app.isInitialLoad) {
            MLDLogger.debug('Initial load complete, clearing flag');
            app.isInitialLoad = false;
          }

          // Cache refresh removed - no longer needed with smart viewport loading
        } else {
          MLDLogger.error('Failed to get map listings:', response.data);
          MLD_Core.updateListingCountIndicator(0, 0);
        }

        // Debug log for zoom 14-15 success
        if (currentZoom >= 14 && currentZoom <= 15 && response.success) {
          const listings = response.data.listings || [];
          MLDLogger.debug(
            'MLD Debug - Zoom',
            currentZoom,
            'SUCCESS: returned',
            listings.length,
            'listings'
          );
          if (combinedFilters && combinedFilters.City) {
            MLDLogger.debug('MLD Debug - With city filter:', combinedFilters.City);
          }
        }
      })
      .fail((xhr, status) => {
        this.pendingRequest = null;

        // Hide loading indicator
        this.hideLoading();

        if (status === 'abort') {
          MLDLogger.debug('Request was aborted');
          return;
        }

        MLDLogger.error('AJAX request to get map listings failed:', { status, responseText: xhr.responseText?.substring(0, 200) });

        // Enhanced error logging for zoom 14-15
        if (currentZoom >= 14 && currentZoom <= 15) {
          MLDLogger.error('MLD Debug - Zoom', currentZoom, 'FAILED with status:', xhr.status);
          MLDLogger.error('MLD Debug - Response:', xhr.responseJSON || xhr.responseText);
          if (combinedFilters && combinedFilters.City) {
            MLDLogger.error('MLD Debug - City filter was:', combinedFilters.City);
          }
        }

        // Auto-retry on network errors with exponential backoff (v5.0)
        // This improves reliability on mobile devices with spotty connections
        if (status === 'timeout' || status === 'error' || xhr.status === 0) {
          const maxRetries = 2;
          const currentRetry = retryCount || 0;

          if (currentRetry < maxRetries) {
            const retryDelay = 1000 * (currentRetry + 1); // 1s, 2s
            MLDLogger.warning(`Network error, retrying in ${retryDelay}ms (attempt ${currentRetry + 1}/${maxRetries})`);

            // Show brief loading message during retry
            this.showLoading();

            setTimeout(() => {
              this.refreshMapListings(forceRefresh, currentRetry + 1, fitToResults);
            }, retryDelay);
            return;
          }

          // All retries exhausted - show user-friendly error
          this.showError('Unable to load listings. Please check your connection and try again.', 8000);
        } else {
          // Server error (not network) - show brief error
          this.showError('Error loading listings. Please try again.', 5000);
        }

        MLD_Core.updateListingCountIndicator(0, 0);
      });
  },

  fetchAutocompleteSuggestions(term, suggestionsId) {
    const app = MLD_Map_App;
    if (app.autocompleteRequest) app.autocompleteRequest.abort();
    app.autocompleteRequest = jQuery
      .post(bmeMapData.ajax_url, {
        action: 'get_autocomplete_suggestions',
        security: bmeMapData.security,
        term,
      })
      .done(function (response) {
        if (response.success && response.data) {
          MLD_Filters.renderAutocompleteSuggestions(response.data, suggestionsId);
        }
      })
      .fail(function (xhr, status, error) {
        if (status !== 'abort') {
          MLDLogger.error('Autocomplete suggestion request failed:', error);
        }
      });
  },
};

// Expose globally
window.MLD_API = MLD_API;
