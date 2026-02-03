/**
 * Map Integration for Saved Search
 * Connects the save search functionality with the map interface
 * Updated v4.5.46: Added hourly notification option
 * @param $
 */

(function ($) {
  'use strict';

  const MLDMapIntegration = {
    // Initialize
    init() {
      this.bindEvents();
      this.detectMapChanges();
    },

    // Bind events
    bindEvents() {
      // Listen for filter changes on the map
      $(document).on(
        'change',
        '.filter-input, .filter-select, .filter-checkbox',
        this.onFilterChange.bind(this)
      );

      // Listen for map bounds changes
      if (typeof google !== 'undefined' && window.map) {
        google.maps.event.addListener(window.map, 'idle', this.onMapBoundsChange.bind(this));
      }

      // Listen for draw-on-map changes
      $(document).on('polygon_complete', this.onPolygonComplete.bind(this));

      // Connect save button
      $(document).on('click', '.mld-save-search-float-btn', this.handleSaveSearch.bind(this));
    },

    // Detect map changes
    detectMapChanges() {
      // Check if filters have been applied
      const hasActiveFilters = this.hasActiveFilters();

      if (hasActiveFilters) {
        $('.mld-save-search-float-btn').fadeIn();
      }
    },

    // Handle filter change
    onFilterChange() {
      // Show save button when filters are applied
      if (this.hasActiveFilters()) {
        $('.mld-save-search-float-btn').fadeIn();
        $(document).trigger('mld_filters_applied');
      }
    },

    // Handle map bounds change
    onMapBoundsChange() {
      if (window.map) {
        const bounds = window.map.getBounds();
        if (bounds) {
          // Store bounds for save search
          window.currentMapBounds = {
            north: bounds.getNorthEast().lat(),
            south: bounds.getSouthWest().lat(),
            east: bounds.getNorthEast().lng(),
            west: bounds.getSouthWest().lng(),
          };

          // Show save button
          $('.mld-save-search-float-btn').fadeIn();
        }
      }
    },

    // Handle polygon complete
    onPolygonComplete(e, polygon) {
      // Store polygon data
      window.currentPolygon = polygon;

      // Show save button
      $('.mld-save-search-float-btn').fadeIn();
    },

    // Check if filters are active
    hasActiveFilters() {
      let hasFilters = false;

      // Check text inputs
      $('.filter-input').each(function () {
        if ($(this).val() && $(this).val().trim() !== '') {
          hasFilters = true;
          return false;
        }
      });

      // Check selects
      if (!hasFilters) {
        $('.filter-select').each(function () {
          if ($(this).val() && $(this).val() !== '' && $(this).val() !== 'all') {
            hasFilters = true;
            return false;
          }
        });
      }

      // Check checkboxes
      if (!hasFilters) {
        if ($('.filter-checkbox:checked').length > 0) {
          hasFilters = true;
        }
      }

      return hasFilters;
    },

    // Handle save search click
    handleSaveSearch() {
      const filters = this.collectCurrentFilters();

      // Open save search modal with current filters
      this.openSaveSearchModal(filters);
    },

    // Collect current filters from the map interface
    collectCurrentFilters() {
      const filters = {};

      // Collect from standard filter elements

      // City/Location
      const city = $('#city-filter, #location-filter, [name="city"]').val();
      if (city) filters.city = city;

      // Price range
      const minPrice = $('#min-price, #price-min, [name="min_price"]').val();
      const maxPrice = $('#max-price, #price-max, [name="max_price"]').val();
      if (minPrice) filters.min_price = minPrice;
      if (maxPrice) filters.max_price = maxPrice;

      // Beds and baths
      const beds = $('#beds-filter, #beds, [name="beds"]').val();
      const baths = $('#baths-filter, #baths, [name="baths"]').val();
      if (beds) filters.beds = beds;
      if (baths) filters.baths = baths;

      // Property type
      const propertyType = $('#property-type, #type-filter, [name="property_type"]').val();
      if (propertyType && propertyType !== 'all') filters.property_type = propertyType;

      // Square footage
      const minSqft = $('#min-sqft, #sqft-min, [name="min_sqft"]').val();
      const maxSqft = $('#max-sqft, #sqft-max, [name="max_sqft"]').val();
      if (minSqft) filters.min_sqft = minSqft;
      if (maxSqft) filters.max_sqft = maxSqft;

      // Year built
      const yearBuilt = $('#year-built, #year, [name="year_built"]').val();
      if (yearBuilt) filters.year_built = yearBuilt;

      // Lot size
      const lotSize = $('#lot-size, [name="lot_size"]').val();
      if (lotSize) filters.lot_size = lotSize;

      // Features (checkboxes)
      const features = [];
      $('.feature-checkbox:checked, [name="features[]"]:checked').each(function () {
        features.push($(this).val());
      });
      if (features.length > 0) filters.features = features;

      // Status
      const status = $('#status-filter, [name="status"]').val();
      if (status && status !== 'all') filters.status = status;

      // Map bounds
      if (window.currentMapBounds) {
        filters.bounds = window.currentMapBounds;
      }

      // Polygon (if drawn)
      if (window.currentPolygon) {
        filters.polygon = this.polygonToArray(window.currentPolygon);
      }

      // Keywords
      const keywords = $('#keywords, #search-keywords, [name="keywords"]').val();
      if (keywords) filters.keywords = keywords;

      // Sort order
      const sortBy = $('#sort-by, [name="sort"]').val();
      if (sortBy) filters.sort_by = sortBy;

      return filters;
    },

    // Convert polygon to array of coordinates
    polygonToArray(polygon) {
      const path = polygon.getPath();
      const coordinates = [];

      for (let i = 0; i < path.length; i++) {
        const point = path.getAt(i);
        coordinates.push({
          lat: point.lat(),
          lng: point.lng(),
        });
      }

      return coordinates;
    },

    // Open save search modal
    openSaveSearchModal(filters) {
      // Check if user is logged in
      if (!mldSavedSearch || !mldSavedSearch.is_logged_in) {
        this.showLoginPrompt();
        return;
      }

      // Create and show modal
      const modal = this.createSaveSearchModal(filters);
      $('body').append(modal);
      setTimeout(() => modal.addClass('active'), 10);
    },

    // Create save search modal
    createSaveSearchModal(filters) {
      const modalHtml = `
                <div class="mld-modal-overlay">
                    <div class="mld-modal">
                        <div class="mld-modal-header">
                            <h2 class="mld-modal-title">Save This Search</h2>
                            <button class="mld-modal-close">&times;</button>
                        </div>
                        <div class="mld-modal-body">
                            <form id="mld-save-search-form">
                                <div class="mld-form-group">
                                    <label class="mld-form-label">Search Name *</label>
                                    <input type="text" name="name" class="mld-form-input" 
                                           placeholder="e.g., 3BR Homes in Boston" required>
                                    <div class="mld-form-helper">Give your search a memorable name</div>
                                </div>
                                
                                <div class="mld-form-group">
                                    <label class="mld-form-label">Description</label>
                                    <textarea name="description" class="mld-form-textarea" 
                                              placeholder="Optional notes about what you're looking for"></textarea>
                                </div>
                                
                                <div class="mld-form-group">
                                    <label class="mld-form-label">Email Notifications</label>
                                    <select name="notification_frequency" class="mld-form-select">
                                        <option value="instant" selected>Instant - Get notified immediately</option>
                                        <option value="hourly">Hourly - Every hour summary</option>
                                        <option value="daily">Daily - Morning summary at 9 AM</option>
                                        <option value="weekly">Weekly - Monday morning digest</option>
                                        <option value="never">Never - No email notifications</option>
                                    </select>
                                    <div class="mld-form-helper">Choose how often you want to receive updates about new matching properties</div>
                                </div>
                                
                                <div class="mld-search-summary">
                                    <h4>Search Criteria:</h4>
                                    <div class="mld-criteria-preview">
                                        ${this.renderFilterSummary(filters)}
                                    </div>
                                </div>
                                
                                <input type="hidden" name="filters" value='${JSON.stringify(filters)}'>
                            </form>
                        </div>
                        <div class="mld-modal-footer">
                            <button type="button" class="mld-btn mld-btn-secondary mld-modal-close">Cancel</button>
                            <button type="button" class="mld-btn mld-btn-primary" onclick="MLDMapIntegration.submitSaveSearch()">
                                Save Search
                            </button>
                        </div>
                    </div>
                </div>
            `;

      return $(modalHtml);
    },

    // Render filter summary
    renderFilterSummary(filters) {
      const items = [];

      if (filters.city) items.push(`<span class="mld-criteria-tag">📍 ${filters.city}</span>`);

      if (filters.min_price || filters.max_price) {
        let price = '💰 ';
        if (filters.min_price && filters.max_price) {
          price += `$${this.formatNumber(filters.min_price)} - $${this.formatNumber(filters.max_price)}`;
        } else if (filters.min_price) {
          price += `$${this.formatNumber(filters.min_price)}+`;
        } else {
          price += `Under $${this.formatNumber(filters.max_price)}`;
        }
        items.push(`<span class="mld-criteria-tag">${price}</span>`);
      }

      if (filters.beds)
        items.push(`<span class="mld-criteria-tag">🛏️ ${filters.beds}+ beds</span>`);
      if (filters.baths)
        items.push(`<span class="mld-criteria-tag">🚿 ${filters.baths}+ baths</span>`);
      if (filters.property_type)
        items.push(`<span class="mld-criteria-tag">🏠 ${filters.property_type}</span>`);
      if (filters.min_sqft)
        items.push(
          `<span class="mld-criteria-tag">📐 ${this.formatNumber(filters.min_sqft)}+ sqft</span>`
        );

      if (filters.features && filters.features.length > 0) {
        items.push(`<span class="mld-criteria-tag">✨ ${filters.features.length} features</span>`);
      }

      if (filters.bounds || filters.polygon) {
        items.push(`<span class="mld-criteria-tag">🗺️ Custom area</span>`);
      }

      return items.length > 0
        ? items.join('')
        : '<span class="mld-criteria-tag">All Properties</span>';
    },

    // Submit save search
    submitSaveSearch() {
      const form = $('#mld-save-search-form');
      const formData = form.serializeArray();
      const data = {};

      formData.forEach((item) => {
        data[item.name] = item.value;
      });

      // Show loading
      const submitBtn = $('.mld-modal-footer .mld-btn-primary');
      const originalText = submitBtn.text();
      submitBtn.text('Saving...').prop('disabled', true);

      // Submit via AJAX
      $.ajax({
        url: mldSavedSearch.ajax_url,
        type: 'POST',
        data: {
          action: 'mld_save_search',
          nonce: mldSavedSearch.nonce,
          ...data,
        },
        success(response) {
          if (response.success) {
            // Close modal
            $('.mld-modal-overlay').removeClass('active');
            setTimeout(() => $('.mld-modal-overlay').remove(), 300);

            // Show success message
            MLDMapIntegration.showToast('Search saved successfully!', 'success');

            // Hide save button
            $('.mld-save-search-float-btn').fadeOut();
          } else {
            MLDMapIntegration.showToast(response.data || 'Failed to save search', 'error');
            submitBtn.text(originalText).prop('disabled', false);
          }
        },
        error() {
          MLDMapIntegration.showToast('Network error. Please try again.', 'error');
          submitBtn.text(originalText).prop('disabled', false);
        },
      });
    },

    // Show login prompt
    showLoginPrompt() {
      const modal = $(`
                <div class="mld-modal-overlay active">
                    <div class="mld-modal">
                        <div class="mld-modal-header">
                            <h2 class="mld-modal-title">Login Required</h2>
                            <button class="mld-modal-close">&times;</button>
                        </div>
                        <div class="mld-modal-body">
                            <p>You need to be logged in to save searches and receive property alerts.</p>
                            <p>Please log in or create an account to continue.</p>
                        </div>
                        <div class="mld-modal-footer">
                            <button type="button" class="mld-btn mld-btn-secondary mld-modal-close">Cancel</button>
                            <a href="/wp-login.php?redirect_to=${encodeURIComponent(window.location.href)}" 
                               class="mld-btn mld-btn-primary">Log In</a>
                        </div>
                    </div>
                </div>
            `);

      $('body').append(modal);
    },

    // Show toast notification
    showToast(message, type = 'info') {
      const toast = $(`<div class="mld-toast ${type}">${message}</div>`);
      $('body').append(toast);

      setTimeout(() => toast.addClass('show'), 10);
      setTimeout(() => {
        toast.removeClass('show');
        setTimeout(() => toast.remove(), 300);
      }, 3000);
    },

    // Format number with commas
    formatNumber(num) {
      return parseInt(num).toLocaleString();
    },
  };

  // Initialize on document ready
  $(document).ready(() => {
    // Only initialize on pages with map
    if ($('.mld-map-container, .bme-map-container, #map, .property-map').length > 0) {
      MLDMapIntegration.init();
    }
  });

  // Make available globally
  window.MLDMapIntegration = MLDMapIntegration;
})(jQuery);
