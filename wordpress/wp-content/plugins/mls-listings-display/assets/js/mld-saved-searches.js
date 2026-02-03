/**
 * MLD Saved Searches Frontend JavaScript
 *
 * Handles saved search functionality including saving current searches,
 * managing property preferences, and authentication flows
 * Updated v4.5.46: Added hourly notification option
 *
 * @param $
 * @since 3.2.0
 * @version 4.5.46
 */

(function ($) {
  'use strict';

  const MLD_SavedSearches = {
    // State management
    isInitialized: false,
    userPreferences: {
      liked: [],
      disliked: [],
    },
    isAdmin: false,
    isAgent: false,
    clients: [],

    /**
     * Initialize saved searches functionality
     */
    init() {
      if (this.isInitialized) return;

      // Check if user data is available
      if (typeof mldUserData !== 'undefined') {
        this.isAdmin = mldUserData.isAdmin || false;
        this.isAgent = mldUserData.isAgent || false;
        // Agents and admins can save for clients
        if ((this.isAdmin || this.isAgent) && mldUserData.clients) {
          this.clients = mldUserData.clients;
        }
      }

      // Load user preferences if logged in
      if (this.isUserLoggedIn()) {
        this.loadPropertyPreferences();
      }

      // Add save search button to map interface
      this.addSaveSearchButton();

      // Initialize property preference buttons
      this.initPropertyPreferenceButtons();

      // Bind events
      this.bindEvents();

      this.isInitialized = true;
    },

    /**
     * Check if user is logged in
     */
    isUserLoggedIn() {
      return typeof mldUserData !== 'undefined' && mldUserData.isLoggedIn;
    },

    /**
     * Add save search button to map interface
     */
    addSaveSearchButton() {
      // Find the filters button and add save search button next to it
      const $filtersButton = $('#bme-filters-button');
      if ($filtersButton.length) {
        // Check if button already exists
        if ($('#bme-save-search-btn').length === 0) {
          const $saveButton = $('<button>', {
            id: 'bme-save-search-btn',
            class: 'bme-control-button',
            html: '<span class="bme-icon-save">💾</span><span> Save Search</span>',
            title: 'Save current search criteria',
          });

          $filtersButton.after($saveButton);
        }
      }
    },

    /**
     * Initialize property preference buttons on cards
     */
    initPropertyPreferenceButtons() {
      // This will be called when property cards are rendered
      // We'll hook into the existing card rendering system
      $(document).on('mld:propertyCardRendered', function (e, data) {
        MLD_SavedSearches.addPreferenceButtons(data.cardElement, data.listing);
      });
    },

    /**
     * Add preference buttons to a property card
     * @param cardElement
     * @param listing
     */
    addPreferenceButtons(cardElement, listing) {
      const $card = $(cardElement);
      const listingId = listing.listing_id || listing.ListingId;

      // Check if buttons already exist
      if ($card.find('.bme-preference-buttons').length) return;

      // Create preference buttons container
      const $buttonsContainer = $('<div>', {
        class: 'bme-preference-buttons',
      });

      // Like button
      const isLiked = this.userPreferences.liked.includes(listingId);
      const $likeButton = $('<button>', {
        class: 'bme-like-btn' + (isLiked ? ' active' : ''),
        'data-listing-id': listingId,
        html: '<i class="' + (isLiked ? 'fas' : 'far') + ' fa-heart"></i>',
        title: isLiked ? 'Remove from saved' : 'Save property',
      });

      // Dislike button
      const isDisliked = this.userPreferences.disliked.includes(listingId);
      const $dislikeButton = $('<button>', {
        class: 'bme-dislike-btn' + (isDisliked ? ' active' : ''),
        'data-listing-id': listingId,
        html: '<i class="fas fa-thumbs-down"></i>',
        title: isDisliked ? 'Unhide property' : 'Hide from searches',
      });

      $buttonsContainer.append($likeButton, $dislikeButton);

      // Add to card (position depends on card layout)
      const $cardImage = $card.find('.bme-card-image, .property-image').first();
      if ($cardImage.length) {
        $cardImage.append($buttonsContainer);
      } else {
        $card.prepend($buttonsContainer);
      }
    },

    /**
     * Bind event handlers
     */
    bindEvents() {
      // Save search button
      $(document).on('click', '#bme-save-search-btn', function (e) {
        e.preventDefault();
        MLD_SavedSearches.handleSaveSearch();
      });

      // Property preference buttons
      $(document).on('click', '.bme-like-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const listingId = $(e.currentTarget).data('listing-id');
        MLD_SavedSearches.togglePropertyLike(listingId);
      });

      $(document).on('click', '.bme-dislike-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const listingId = $(e.currentTarget).data('listing-id');
        MLD_SavedSearches.togglePropertyDislike(listingId);
      });

      // Modal events
      $(document).on('click', '#bme-save-search-modal .bme-modal-close', function () {
        MLD_SavedSearches.closeSaveSearchModal();
      });

      $(document).on('submit', '#bme-save-search-form', function (e) {
        e.preventDefault();
        MLD_SavedSearches.submitSaveSearch();
      });

      // Save For toggle (agent/admin multi-client selection)
      $(document).on('change', 'input[name="save_for_type"]', function () {
        if ($(this).val() === 'clients') {
          $('#client-selection-section').slideDown();
        } else {
          $('#client-selection-section').slideUp();
          // Uncheck all client checkboxes when switching back
          $('input[name="client_ids[]"]').prop('checked', false);
        }
      });

      // Select all / Deselect all clients
      $(document).on('click', '#select-all-clients', function (e) {
        e.preventDefault();
        $('input[name="client_ids[]"]').prop('checked', true);
      });

      $(document).on('click', '#deselect-all-clients', function (e) {
        e.preventDefault();
        $('input[name="client_ids[]"]').prop('checked', false);
      });
    },

    /**
     * Capture current search filters and state
     * Returns complete search data ready to be saved
     * @since 4.3.0
     */
    captureCurrentSearch() {
      // Get current filters using the same method as URL updates
      const filters = typeof MLD_Filters !== 'undefined' ? MLD_Filters.getCombinedFilters() : {};

      // Capture keyword filters (City, Neighborhood, Address, etc.)
      const keywordFilters = {};
      if (typeof MLD_Map_App !== 'undefined' && MLD_Map_App.keywordFilters) {
        for (const type in MLD_Map_App.keywordFilters) {
          if (MLD_Map_App.keywordFilters[type].size > 0) {
            keywordFilters[type] = Array.from(MLD_Map_App.keywordFilters[type]);
          }
        }
      }

      // Capture city and neighborhood boundaries state
      const cityBoundaries = {};
      if (typeof MLD_CityBoundaries !== 'undefined') {
        // Get currently selected cities
        if (MLD_CityBoundaries.currentCities && MLD_CityBoundaries.currentCities.size > 0) {
          cityBoundaries.cities = Array.from(MLD_CityBoundaries.currentCities);
        }
        // Get currently selected neighborhoods (if tracked)
        if (MLD_CityBoundaries.currentNeighborhoods && MLD_CityBoundaries.currentNeighborhoods.size > 0) {
          cityBoundaries.neighborhoods = Array.from(MLD_CityBoundaries.currentNeighborhoods);
        }
      }

      // Get polygon shapes if any are drawn
      const polygonShapes =
        typeof MLD_Map_App !== 'undefined' && MLD_Map_App.drawnPolygons
          ? MLD_Map_App.drawnPolygons.map((polygon) => ({
              type: 'polygon',
              coordinates: polygon.coordinates,
              name: polygon.name || null,
            }))
          : [];

      // Build search URL that can restore this exact search
      const searchUrl = window.location.origin + window.location.pathname + window.location.hash;

      // Get property type selection
      const propertyType =
        typeof MLD_Map_App !== 'undefined'
          ? MLD_Map_App.selectedPropertyType || 'Residential'
          : 'Residential';

      // Capture enhanced filters if available
      let enhancedFilters = null;
      if (typeof MLDEnhancedFilters !== 'undefined' && typeof MLDEnhancedFilters.collectFilters === 'function') {
        enhancedFilters = MLDEnhancedFilters.collectFilters();
      }

      // Compile complete search data
      const searchData = {
        filters,
        keyword_filters: keywordFilters,
        city_boundaries: cityBoundaries,
        polygon_shapes: polygonShapes,
        search_url: searchUrl,
        property_type: propertyType,
        enhanced_filters: enhancedFilters,

        // Additional metadata
        map_center:
          typeof MLD_Map_App !== 'undefined' && MLD_Map_App.map
            ? {
                lat: MLD_Map_App.map.getCenter().lat(),
                lng: MLD_Map_App.map.getCenter().lng(),
              }
            : null,
        map_zoom:
          typeof MLD_Map_App !== 'undefined' && MLD_Map_App.map ? MLD_Map_App.map.getZoom() : null,

        // Search context
        search_query: $('#bme-search-input').val() || '',
        total_results:
          typeof MLD_Map_App !== 'undefined' && MLD_Map_App.allListings
            ? MLD_Map_App.allListings.length
            : 0,
      };

      MLDLogger.debug('MLD: Captured search data:', searchData);
      return searchData;
    },

    /**
     * Handle save search button click
     */
    handleSaveSearch() {
      if (!this.isUserLoggedIn()) {
        this.showLoginPrompt('save searches');
        return;
      }

      // Capture current search state before showing modal
      this.pendingSearchData = this.captureCurrentSearch();

      // Check if search has any filters or criteria
      const hasFilters = Object.keys(this.pendingSearchData.filters).length > 0;
      const hasPolygons = this.pendingSearchData.polygon_shapes.length > 0;
      const hasQuery = this.pendingSearchData.search_query !== '';

      if (!hasFilters && !hasPolygons && !hasQuery) {
        alert('Please add some search criteria before saving (filters, location, or search terms)');
        return;
      }

      this.showSaveSearchModal();
    },

    /**
     * Generate search name based on filters
     * Only includes: City/cities, price, beds, baths
     * @param filters
     */
    generateSearchName(filters) {
      const parts = [];

      // City/Location (first)
      if (filters.City) {
        const cities = Array.isArray(filters.City) ? filters.City : [filters.City];
        if (cities.length === 1) {
          parts.push(cities[0]);
        } else if (cities.length === 2) {
          parts.push(cities.join(' & '));
        } else if (cities.length <= 4) {
          parts.push(cities.slice(0, 3).join(', '));
        } else {
          parts.push(`${cities.length} Cities`);
        }
      }

      // Price range
      if (filters.ListPrice_min || filters.ListPrice_max) {
        const min = filters.ListPrice_min;
        const max = filters.ListPrice_max;

        if (max && !min) {
          // Under X
          if (max >= 1000000) {
            parts.push(`Under $${(max / 1000000).toFixed(1).replace(/\.0$/, '')}M`);
          } else {
            parts.push(`Under $${Math.round(max / 1000)}k`);
          }
        } else if (min && !max) {
          // Over X
          if (min >= 1000000) {
            parts.push(`Over $${(min / 1000000).toFixed(1).replace(/\.0$/, '')}M`);
          } else {
            parts.push(`Over $${Math.round(min / 1000)}k`);
          }
        } else if (min && max) {
          // Range
          const minStr =
            min >= 1000000
              ? `$${(min / 1000000).toFixed(1).replace(/\.0$/, '')}M`
              : `$${Math.round(min / 1000)}k`;
          const maxStr =
            max >= 1000000
              ? `$${(max / 1000000).toFixed(1).replace(/\.0$/, '')}M`
              : `$${Math.round(max / 1000)}k`;
          parts.push(`${minStr}-${maxStr}`);
        }
      }

      // Bedrooms
      if (filters.BedroomsTotal_min || filters.BedroomsTotal) {
        const beds = filters.BedroomsTotal || filters.BedroomsTotal_min;
        parts.push(`${beds}+ Beds`);
      }

      // Bathrooms
      if (filters.BathroomsTotalInteger_min || filters.BathroomsTotalInteger) {
        const baths = filters.BathroomsTotalInteger || filters.BathroomsTotalInteger_min;
        parts.push(`${baths}+ Baths`);
      }

      // If no filters, use default
      if (parts.length === 0) {
        return 'My Property Search';
      }

      // Join parts with spaces
      return parts.join(' ');
    },

    /**
     * Show save search modal
     */
    showSaveSearchModal() {
      // Remove existing modal if any
      $('#bme-save-search-modal').remove();

      // Get current filters
      const filters = typeof MLD_Filters !== 'undefined' ? MLD_Filters.getCombinedFilters() : {};
      const polygons =
        typeof MLD_Map_App !== 'undefined' ? MLD_Map_App.getPolygonCoordinates() : [];

      // Create modal HTML with modern design
      let modalHtml = `
            <div id="bme-save-search-modal" class="bme-modal-overlay">
                <div class="bme-modal-content bme-modal-modern">
                    <div class="bme-modal-header">
                        <div class="bme-modal-header-content">
                            <span class="bme-modal-icon">🔖</span>
                            <div>
                                <h3>Save Your Search</h3>
                                <p class="bme-modal-subtitle">Get notified when new properties match your criteria</p>
                            </div>
                        </div>
                        <button class="bme-modal-close" aria-label="Close modal">
                            <span>✕</span>
                        </button>
                    </div>

                    <div class="bme-modal-body">
                        <form id="bme-save-search-form">
                            <div class="bme-form-group">
                                <label for="search-name">
                                    <span class="bme-icon">🏷️</span> Search Name
                                    <span class="bme-required">*</span>
                                </label>
                                <input type="text" id="search-name" name="name" required
                                       placeholder="e.g., 3BR Homes in Boston under $500k"
                                       class="bme-form-control"
                                       value="${this.generateSearchName(filters)}">
                                <small class="bme-form-hint">Choose a name that helps you remember this search</small>
                            </div>

                            <div class="bme-form-group">
                                <label for="search-description">
                                    <span class="bme-icon">💬</span> Description
                                    <span class="bme-optional">(optional)</span>
                                </label>
                                <textarea id="search-description" name="description" rows="3"
                                          placeholder="Add notes about what you're looking for..."
                                          class="bme-form-control"></textarea>
                            </div>

                            <div class="bme-form-group">
                                <label for="notification-frequency">
                                    <span class="bme-icon">📧</span> Email Notifications
                                </label>
                                <select id="notification-frequency" name="frequency" class="bme-form-control bme-select-tall">
                                    <option value="fifteen_min" selected>⚡ Every 15 min - New listings, price & status changes</option>
                                    <option value="instant">🚀 Instant (5 min) - Fastest alerts</option>
                                    <option value="hourly">⏰ Hourly - Every hour summary</option>
                                    <option value="daily">📅 Daily - Morning summary email</option>
                                    <option value="weekly">📆 Weekly - Weekly roundup</option>
                                    <option value="never">🔕 Never - No email notifications</option>
                                </select>
                                <small class="bme-form-hint">Get alerts for new listings, price changes, and status updates</small>
                            </div>`;

      // Add client selector for agents and admins (multi-select)
      if ((this.isAdmin || this.isAgent) && this.clients.length > 0) {
        let clientCheckboxes = '';
        this.clients.forEach((client) => {
          clientCheckboxes += `
            <label class="bme-client-checkbox">
              <input type="checkbox" name="client_ids[]" value="${client.id}">
              <span class="bme-client-name">${client.name}</span>
              <small class="bme-client-email">${client.email}</small>
            </label>`;
        });

        modalHtml += `
                        <div class="bme-form-group bme-save-for-section">
                            <label>Save For</label>
                            <div class="bme-save-for-options">
                                <label class="bme-radio-label">
                                    <input type="radio" name="save_for_type" value="myself" checked>
                                    <span>Save for myself</span>
                                </label>
                                <label class="bme-radio-label">
                                    <input type="radio" name="save_for_type" value="clients">
                                    <span>Save for client(s)</span>
                                </label>
                            </div>
                        </div>

                        <div id="client-selection-section" class="bme-form-group" style="display:none;">
                            <div class="bme-select-all-row">
                                <a href="#" id="select-all-clients">Select All</a>
                                <span class="bme-separator">|</span>
                                <a href="#" id="deselect-all-clients">Deselect All</a>
                            </div>
                            <div class="bme-client-checkboxes">
                                ${clientCheckboxes}
                            </div>
                            <div class="bme-agent-note-field">
                                <label for="agent-note">Note to clients (optional)</label>
                                <textarea id="agent-note" rows="2" placeholder="Why you're recommending this search..."></textarea>
                            </div>
                            <label class="bme-cc-toggle">
                                <input type="checkbox" id="cc-agent-notify" checked>
                                <span>CC me on new listing notifications</span>
                            </label>
                        </div>`;
      }

      modalHtml += `
                            <div class="bme-form-actions">
                                <button type="submit" class="bme-btn bme-btn-primary bme-btn-large">
                                    <span class="bme-icon">💾</span>
                                    <span>Save Search</span>
                                </button>
                                <button type="button" class="bme-btn bme-btn-secondary bme-modal-close">
                                    <span class="bme-icon">✕</span> Cancel
                                </button>
                            </div>
                        </form>

                        <div class="bme-modal-footer">
                            <div class="bme-saved-searches-link">
                                <span class="bme-icon-list">☰</span>
                                <span>Already have saved searches?</span>
                                <a href="${typeof mldUserData !== 'undefined' && mldUserData.savedSearchesUrl ? mldUserData.savedSearchesUrl : '/saved-search/'}" class="bme-link">View Your Saved Searches</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;

      // Add modal to page
      $('body').append(modalHtml);

      // Focus on name field
      $('#search-name').focus();
    },

    /**
     * Submit save search form
     * @updated 4.3.0 - Now uses captured search data
     */
    submitSaveSearch() {
      const $form = $('#bme-save-search-form');
      const $submitBtn = $form.find('button[type="submit"]');

      // Disable submit button
      $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

      // Use previously captured search data or capture now
      const searchData = this.pendingSearchData || this.captureCurrentSearch();

      // Build form data for AJAX
      const formData = {
        action: 'mld_save_search',
        nonce:
          typeof mldUserData !== 'undefined'
            ? mldUserData.nonce
            : typeof mldPropertyData !== 'undefined'
              ? mldPropertyData.nonce
              : '',
        name: $('#search-name').val(),
        description: $('#search-description').val(),
        notification_frequency: $('#notification-frequency').val(),

        // Use captured search data
        filters: JSON.stringify(searchData.filters),
        keyword_filters: JSON.stringify(searchData.keyword_filters),
        city_boundaries: JSON.stringify(searchData.city_boundaries),
        polygon_shapes: JSON.stringify(searchData.polygon_shapes),
        search_url: searchData.search_url,
        property_type: searchData.property_type,
        enhanced_filters: JSON.stringify(searchData.enhanced_filters),

        // Additional metadata
        map_center: JSON.stringify(searchData.map_center),
        map_zoom: searchData.map_zoom,
        search_query: searchData.search_query,
      };

      // Add client IDs if saving for clients (agent/admin multi-select)
      if (this.isAdmin || this.isAgent) {
        const saveForType = $('input[name="save_for_type"]:checked').val();
        if (saveForType === 'clients') {
          const clientIds = $('input[name="client_ids[]"]:checked')
            .map(function () {
              return $(this).val();
            })
            .get();

          if (clientIds.length > 0) {
            formData.client_ids = JSON.stringify(clientIds);
            formData.agent_notes = $('#agent-note').val() || '';
            formData.cc_agent_on_notify = $('#cc-agent-notify').is(':checked') ? 1 : 0;
          }
        }
      }

      // Submit via AJAX - use mldUserData which we're localizing
      const ajaxUrl =
        typeof mldUserData !== 'undefined'
          ? mldUserData.ajaxUrl
          : typeof mldPropertyData !== 'undefined'
            ? mldPropertyData.ajaxUrl
            : '/wp-admin/admin-ajax.php';

      $.post(ajaxUrl, formData)
        .done(function (response) {
          if (response.success) {
            MLD_SavedSearches.showSuccessMessage('Search saved successfully!');
            MLD_SavedSearches.closeSaveSearchModal();

            // Trigger event for other components
            $(document).trigger('mld:searchSaved', [response.data]);
          } else {
            MLD_SavedSearches.showErrorMessage(response.data.message || 'Failed to save search');
          }
        })
        .fail(function (xhr, status, error) {
          MLD_SavedSearches.showErrorMessage('An error occurred. Please try again.');
        })
        .always(function () {
          $submitBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Search');
        });
    },

    /**
     * Close save search modal
     */
    closeSaveSearchModal() {
      jQuery('#bme-save-search-modal').fadeOut(200, function () {
        jQuery(this).remove();
      });
    },

    /**
     * Load property preferences
     */
    loadPropertyPreferences() {
      const ajaxUrl =
        typeof mldUserData !== 'undefined' ? mldUserData.ajaxUrl : '/wp-admin/admin-ajax.php';
      const nonce = typeof mldUserData !== 'undefined' ? mldUserData.nonce : '';

      $.post(ajaxUrl, {
        action: 'mld_get_saved_properties',
        nonce,
      }).done((response) => {
        if (response.success && response.data) {
          // Properly structure the preferences object
          if (response.data.properties) {
            // If properties is already structured with liked/disliked
            if (response.data.properties.liked && response.data.properties.disliked) {
              this.userPreferences = response.data.properties;
            } else if (Array.isArray(response.data.properties)) {
              // Legacy format - convert array to structured object
              this.userPreferences = {
                liked: response.data.properties.filter(p => p.preference_type === 'liked').map(p => p.listing_id),
                disliked: response.data.properties.filter(p => p.preference_type === 'disliked').map(p => p.listing_id)
              };
            }
          } else {
            // Initialize with empty arrays if no data
            this.userPreferences = {
              liked: [],
              disliked: []
            };
          }

          // Update any existing property cards
          this.updatePropertyCards();
        }
      }).fail((xhr, status, error) => {
        MLDLogger.error('Failed to load property preferences', { xhr, status, error });
      });
    },

    /**
     * Toggle property like
     * @param listingId
     */
    togglePropertyLike(listingId) {
      if (!this.isUserLoggedIn()) {
        this.showLoginPrompt('save properties');
        return;
      }

      const $button = $(`.bme-like-btn[data-listing-id="${listingId}"]`);

      // Optimistic UI update
      $button.toggleClass('active');
      const isActive = $button.hasClass('active');
      $button.find('i').toggleClass('far fas');

      // Send AJAX request
      const ajaxUrl =
        typeof mldUserData !== 'undefined' ? mldUserData.ajaxUrl : '/wp-admin/admin-ajax.php';
      const nonce = typeof mldUserData !== 'undefined' ? mldUserData.nonce : '';

      $.post(ajaxUrl, {
        action: 'mld_toggle_property_like',
        nonce,
        listing_id: listingId,
      })
        .done((response) => {
          if (response.success) {
            // Update preferences
            if (response.data.preference === 'liked') {
              if (!this.userPreferences.liked.includes(listingId)) {
                this.userPreferences.liked.push(listingId);
              }
              // Remove from disliked if present
              this.userPreferences.disliked = this.userPreferences.disliked.filter(
                (id) => id !== listingId
              );
            } else {
              this.userPreferences.liked = this.userPreferences.liked.filter(
                (id) => id !== listingId
              );
            }

            // Update dislike button if needed
            const $dislikeBtn = $(`.bme-dislike-btn[data-listing-id="${listingId}"]`);
            if ($dislikeBtn.hasClass('active')) {
              $dislikeBtn.removeClass('active');
            }

            this.showSuccessMessage(response.data.message);
          } else {
            // Revert UI on error
            $button.toggleClass('active');
            $button.find('i').toggleClass('far fas');
            this.showErrorMessage(response.data.message || 'Failed to update preference');
          }
        })
        .fail(() => {
          // Revert UI on error
          $button.toggleClass('active');
          $button.find('i').toggleClass('far fas');
          this.showErrorMessage('An error occurred. Please try again.');
        });
    },

    /**
     * Toggle property dislike
     * @param listingId
     */
    togglePropertyDislike(listingId) {
      if (!this.isUserLoggedIn()) {
        this.showLoginPrompt('hide properties');
        return;
      }

      const $button = $(`.bme-dislike-btn[data-listing-id="${listingId}"]`);

      // Optimistic UI update
      $button.toggleClass('active');

      // Send AJAX request
      const ajaxUrl =
        typeof mldUserData !== 'undefined' ? mldUserData.ajaxUrl : '/wp-admin/admin-ajax.php';
      const nonce = typeof mldUserData !== 'undefined' ? mldUserData.nonce : '';

      $.post(ajaxUrl, {
        action: 'mld_toggle_property_dislike',
        nonce,
        listing_id: listingId,
      })
        .done((response) => {
          if (response.success) {
            // Update preferences
            if (response.data.preference === 'disliked') {
              if (!this.userPreferences.disliked.includes(listingId)) {
                this.userPreferences.disliked.push(listingId);
              }
              // Remove from liked if present
              this.userPreferences.liked = this.userPreferences.liked.filter(
                (id) => id !== listingId
              );

              // Hide the property card with animation
              $button.closest('.bme-property-card, .property-card').fadeOut(300);
            } else {
              this.userPreferences.disliked = this.userPreferences.disliked.filter(
                (id) => id !== listingId
              );
            }

            // Update like button if needed
            const $likeBtn = $(`.bme-like-btn[data-listing-id="${listingId}"]`);
            if ($likeBtn.hasClass('active')) {
              $likeBtn.removeClass('active').find('i').removeClass('fas').addClass('far');
            }

            this.showSuccessMessage(response.data.message);
          } else {
            // Revert UI on error
            $button.toggleClass('active');
            this.showErrorMessage(response.data.message || 'Failed to update preference');
          }
        })
        .fail(() => {
          // Revert UI on error
          $button.toggleClass('active');
          this.showErrorMessage('An error occurred. Please try again.');
        });
    },

    /**
     * Update existing property cards with preference states
     */
    updatePropertyCards() {
      // Ensure userPreferences is properly structured
      if (!this.userPreferences || typeof this.userPreferences !== 'object') {
        this.userPreferences = {
          liked: [],
          disliked: []
        };
      }

      // Ensure liked and disliked arrays exist
      if (!Array.isArray(this.userPreferences.liked)) {
        this.userPreferences.liked = [];
      }
      if (!Array.isArray(this.userPreferences.disliked)) {
        this.userPreferences.disliked = [];
      }

      // Update like buttons
      this.userPreferences.liked.forEach((listingId) => {
        $(`.bme-like-btn[data-listing-id="${listingId}"]`)
          .addClass('active')
          .find('i')
          .removeClass('far')
          .addClass('fas');
      });

      // Update dislike buttons and hide cards
      this.userPreferences.disliked.forEach((listingId) => {
        $(`.bme-dislike-btn[data-listing-id="${listingId}"]`)
          .addClass('active')
          .closest('.bme-property-card, .property-card')
          .hide();
      });
    },

    /**
     * Show login prompt
     * @param action
     */
    showLoginPrompt(action) {
      // Remove existing prompt if any
      $('#bme-login-prompt').remove();

      // Get URLs from settings or use defaults
      const loginUrl =
        typeof mldUserData !== 'undefined' && mldUserData.loginUrl
          ? mldUserData.loginUrl
          : '/wp-login.php';
      const registerUrl =
        typeof mldUserData !== 'undefined' && mldUserData.registerUrl
          ? mldUserData.registerUrl
          : '/signup/';

      const promptHtml = `
            <div id="bme-login-prompt" class="bme-modal-overlay">
                <div class="bme-modal-content bme-login-prompt bme-modal-modern">
                    <div class="bme-modal-header">
                        <div class="bme-modal-header-content">
                            <span class="bme-modal-icon">🔐</span>
                            <div>
                                <h3>Login Required</h3>
                                <p class="bme-modal-subtitle">Access your account to ${action}</p>
                            </div>
                        </div>
                        <button class="bme-modal-close" aria-label="Close modal">
                            <span>✕</span>
                        </button>
                    </div>
                    <div class="bme-modal-body">
                        <p class="bme-login-message">To ${action}, you'll need to log in to your account or create a new one.</p>
                        <div class="bme-login-actions">
                            <a href="${loginUrl}" class="bme-btn bme-btn-primary bme-btn-large">
                                <span class="bme-icon">👤</span>
                                <span>Log In to Your Account</span>
                            </a>
                            <div class="bme-divider">
                                <span>or</span>
                            </div>
                            <a href="${registerUrl}" class="bme-btn bme-btn-secondary bme-btn-large">
                                <span class="bme-icon">✨</span>
                                <span>Create New Account</span>
                            </a>
                        </div>
                        <p class="bme-login-benefits">
                            <strong>Benefits of creating an account:</strong><br>
                            • Save your favorite searches<br>
                            • Get instant property alerts<br>
                            • Save and compare properties<br>
                            • Access exclusive listings
                        </p>
                    </div>
                </div>
            </div>`;

      $('body').append(promptHtml);

      // Close button handler
      $('#bme-login-prompt .bme-modal-close').on('click', () => {
        $('#bme-login-prompt').fadeOut(200, function () {
          $(this).remove();
        });
      });
    },

    /**
     * Show success message
     * @param message
     */
    showSuccessMessage(message) {
      this.showToast(message, 'success');
    },

    /**
     * Show error message
     * @param message
     */
    showErrorMessage(message) {
      this.showToast(message, 'error');
    },

    /**
     * Show toast notification
     * @param message
     * @param type
     */
    showToast(message, type = 'info') {
      // Remove existing toasts
      $('.bme-toast').remove();

      const toastHtml = `
            <div class="bme-toast bme-toast-${type}">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            </div>`;

      const $toast = $(toastHtml).appendTo('body');

      // Animate in
      setTimeout(() => {
        $toast.addClass('show');
      }, 10);

      // Auto-hide after 3 seconds
      setTimeout(() => {
        $toast.removeClass('show');
        setTimeout(() => {
          $toast.remove();
        }, 300);
      }, 3000);
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    // Wait a bit for the map to initialize
    setTimeout(function () {
      MLD_SavedSearches.init();
    }, 1000);
  });

  // Also initialize after map is ready
  $(window).on('mld:mapInitialized', function () {
    MLD_SavedSearches.init();
  });

  // Try initialization after Google Maps is ready
  $(window).on('googleMapsReady', function () {
    setTimeout(function () {
      MLD_SavedSearches.init();
    }, 500);
  });

  // Make MLD_SavedSearches available globally
  window.MLD_SavedSearches = MLD_SavedSearches;
})(jQuery);

// Initialize when DOM is ready
jQuery(document).ready(function ($) {
  // Wait a bit for map components to load
  setTimeout(function () {
    if (typeof MLD_SavedSearches !== 'undefined') {
      MLD_SavedSearches.init();
    }
  }, 1000);
});
