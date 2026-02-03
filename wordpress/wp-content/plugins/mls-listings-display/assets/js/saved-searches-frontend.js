/**
 * MLS Saved Searches Frontend JavaScript
 * @param $
 */
(function ($) {
  'use strict';

  const MLD_SavedSearchesFrontend = {
    currentSearchId: null,

    init() {
      this.bindEvents();
      this.loadUserSearches();
    },

    bindEvents() {
      // Modal controls
      $('.mld-modal-close, .mld-cancel-btn').on('click', () => this.closeModal());
      $(window).on('click', (e) => {
        if ($(e.target).hasClass('mld-modal')) {
          this.closeModal();
        }
      });

      // Edit form
      $('#mld-edit-search-form').on('submit', (e) => this.handleEditSubmit(e));

      // Dynamic event handlers
      $(document).on('click', '.mld-edit-search', (e) => this.openEditModal(e));
      $(document).on('click', '.mld-delete-search', (e) => this.deleteSearch(e));
      $(document).on('click', '.mld-toggle-status', (e) => this.toggleStatus(e));
      $(document).on('click', '.mld-view-search', (e) => this.viewSearch(e));
      $(document).on('click', '.mld-share-search', (e) => this.shareSearch(e));
    },

    loadUserSearches() {
      $.post(
        mldSavedSearches.ajaxUrl,
        {
          action: 'mld_get_user_searches',
          nonce: mldSavedSearches.nonce,
        },
        (response) => {
          $('.mld-loading').hide();

          if (response.success && response.data.searches.length > 0) {
            this.renderSearches(response.data.searches);
            $('#mld-searches-list').show();
          } else {
            $('#mld-no-searches').show();
          }
        }
      ).fail(() => {
        $('.mld-loading').hide();
        $('#mld-no-searches').show();
        this.showError(mldSavedSearches.strings.error);
      });
    },

    renderSearches(searches) {
      const container = $('#mld-searches-list');
      container.empty();

      searches.forEach((search) => {
        const searchCard = this.createSearchCard(search);
        container.append(searchCard);
      });
    },

    createSearchCard(search) {
      const filters = search.filters_decoded || search.filters || {};
      const filterSummary = this.getFilterSummary(filters);
      const statusClass = search.is_active == 1 ? 'active' : 'inactive';
      const statusText = search.is_active == 1 ? 'Active' : 'Inactive';
      // Use the saved search_url directly (like admin does) or build one from filters
      const searchUrl = search.search_url || this.buildSearchUrl(filters);

      return `
                <div class="mld-search-card" data-search-id="${search.id}">
                    <div class="mld-search-header">
                        <h3>${this.escapeHtml(search.name)}</h3>
                        <span class="mld-search-status ${statusClass}">${statusText}</span>
                    </div>

                    <div class="mld-search-details">
                        <div class="mld-search-filters">
                            <strong>Filters:</strong> ${filterSummary}
                        </div>

                        <div class="mld-search-meta">
                            <span><strong>Created:</strong> ${search.created_at_formatted}</span>
                            <span><strong>Last Run:</strong> ${search.last_run_formatted}</span>
                            <span><strong>Notifications:</strong> ${this.formatFrequency(search.notification_frequency)}</span>
                        </div>
                    </div>

                    <div class="mld-search-actions">
                        <button class="mld-button mld-button-small mld-view-search"
                                data-search-id="${search.id}"
                                data-url="${this.escapeHtml(searchUrl)}">
                            View Results
                        </button>
                        <button class="mld-button mld-button-small mld-share-search"
                                data-search-id="${search.id}"
                                data-name="${this.escapeHtml(search.name)}"
                                data-url="${this.escapeHtml(searchUrl)}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;">
                                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                            Share
                        </button>
                        <button class="mld-button mld-button-small mld-edit-search"
                                data-search-id="${search.id}">
                            Edit
                        </button>
                        <button class="mld-button mld-button-small mld-toggle-status"
                                data-search-id="${search.id}"
                                data-status="${search.is_active}">
                            ${search.is_active == 1 ? 'Deactivate' : 'Activate'}
                        </button>
                        <button class="mld-button mld-button-small mld-button-danger mld-delete-search"
                                data-search-id="${search.id}">
                            Delete
                        </button>
                    </div>
                </div>
            `;
    },

    getFilterSummary(filters) {
      const parts = [];

      // Handle various city filter formats
      const cities = filters.City || filters.city || filters.selected_cities || filters.keyword_City;
      if (cities) {
        const cityList = Array.isArray(cities) ? cities.join(', ') : cities;
        parts.push(`City: ${cityList}`);
      }

      // Handle price filters (various key formats)
      const minPrice = filters.price_min || filters.min_price;
      const maxPrice = filters.price_max || filters.max_price;
      if (minPrice || maxPrice) {
        const min = minPrice ? '$' + this.numberWithCommas(minPrice) : 'Any';
        const max = maxPrice ? '$' + this.numberWithCommas(maxPrice) : 'Any';
        parts.push(`Price: ${min} - ${max}`);
      }

      // Handle beds (various formats)
      const beds = filters.beds || filters.BedroomsTotal;
      if (beds) {
        const bedCount = Array.isArray(beds) ? beds.join(', ') : beds;
        parts.push(`Beds: ${bedCount}+`);
      }

      // Handle baths
      const baths = filters.baths_min || filters.baths || filters.BathroomsTotalInteger;
      if (baths) parts.push(`Baths: ${baths}+`);

      // Handle property type
      const propType = filters.PropertyType || filters.property_type;
      if (propType) parts.push(`Type: ${propType}`);

      // Handle square feet
      const sqft = filters.sqft_min || filters.square_feet || filters.LivingArea;
      if (sqft) parts.push(`Min Sq Ft: ${this.numberWithCommas(sqft)}`);

      // Handle status
      const status = filters.status;
      if (status && Array.isArray(status)) {
        parts.push(`Status: ${status.join(', ')}`);
      }

      return parts.length > 0 ? parts.join(', ') : 'No specific filters';
    },

    /**
     * Build search URL from filters (fallback if search_url is empty)
     * Handles both iOS format (snake_case) and web format (PascalCase/different keys)
     */
    buildSearchUrl(filters) {
      const params = new URLSearchParams();

      // City - handle iOS (city), web (City), and other formats
      const cities = filters.City || filters.city || filters.selected_cities || filters.keyword_City;
      if (cities) {
        params.set('City', Array.isArray(cities) ? cities.join(',') : cities);
      }

      // Price - handle iOS (min_price/max_price) and web (price_min/price_max)
      const minPrice = filters.price_min || filters.min_price;
      const maxPrice = filters.price_max || filters.max_price;
      if (minPrice) params.set('price_min', minPrice);
      if (maxPrice) params.set('price_max', maxPrice);

      // Beds - handle both formats
      const beds = filters.beds || filters.BedroomsTotal;
      if (beds) {
        params.set('beds', Array.isArray(beds) ? beds.join(',') : beds);
      }

      // Baths - handle iOS (baths) and web (baths_min)
      const baths = filters.baths_min || filters.baths || filters.min_baths || filters.BathroomsTotalInteger;
      if (baths) params.set('baths_min', baths);

      // Property Type - handle iOS (property_type) and web (PropertyType)
      const propType = filters.PropertyType || filters.property_type;
      if (propType) {
        params.set('PropertyType', Array.isArray(propType) ? propType.join(',') : propType);
      }

      // Status - handle array or string
      const status = filters.status;
      if (status) {
        params.set('status', Array.isArray(status) ? status.join(',') : status);
      }

      // Square feet - handle both formats
      const sqftMin = filters.sqft_min || filters.min_sqft || filters.square_feet || filters.LivingArea;
      const sqftMax = filters.sqft_max || filters.max_sqft;
      if (sqftMin) params.set('sqft_min', sqftMin);
      if (sqftMax) params.set('sqft_max', sqftMax);

      // Year built - handle both formats
      const yearMin = filters.year_built_min || filters.min_year_built;
      const yearMax = filters.year_built_max || filters.max_year_built;
      if (yearMin) params.set('year_built_min', yearMin);
      if (yearMax) params.set('year_built_max', yearMax);

      // Neighborhoods
      const neighborhoods = filters.neighborhood || filters.neighborhoods || filters.subdivision_name;
      if (neighborhoods) {
        params.set('neighborhood', Array.isArray(neighborhoods) ? neighborhoods.join(',') : neighborhoods);
      }

      // ZIP codes
      const zips = filters.zip || filters.zips || filters.postal_code;
      if (zips) {
        params.set('zip', Array.isArray(zips) ? zips.join(',') : zips);
      }

      // Special filters
      if (filters.price_reduced === true || filters.price_reduced === 'true' || filters.price_reduced === 1) {
        params.set('price_reduced', 'true');
      }
      if (filters.open_house_only === true || filters.open_house_only === 'true' || filters.open_house_only === 1) {
        params.set('open_house_only', 'true');
      }
      if (filters.new_listing_days) {
        params.set('new_listing_days', filters.new_listing_days);
      }

      const queryString = params.toString();
      return '/search/' + (queryString ? '#' + queryString : '');
    },

    formatFrequency(frequency) {
      const frequencies = {
        never: 'Never',
        instant: 'Instant (5 min)',
        fifteen_min: 'Every 15 min',
        hourly: 'Hourly',
        daily: 'Daily at 9 AM',
        weekly: 'Weekly on Mondays',
      };
      return frequencies[frequency] || frequency;
    },

    openEditModal(e) {
      e.preventDefault();
      const searchId = $(e.currentTarget).data('search-id');
      const searchCard = $(e.currentTarget).closest('.mld-search-card');

      // Get search data from the card
      const searchName = searchCard.find('h3').text();
      const isActive = searchCard.find('.mld-search-status').hasClass('active');
      const frequency = searchCard
        .find('.mld-search-meta span:contains("Notifications")')
        .text()
        .replace('Notifications: ', '')
        .toLowerCase();

      // Populate form
      $('#edit-search-id').val(searchId);
      $('#edit-search-name').val(searchName);
      $('#edit-is-active').prop('checked', isActive);

      // Set frequency - map display text back to value
      const frequencyMap = {
        never: 'never',
        instant: 'instant',
        'instant (5 min)': 'instant',
        'every 15 min': 'fifteen_min',
        fifteen_min: 'fifteen_min',
        hourly: 'hourly',
        daily: 'daily',
        weekly: 'weekly',
      };
      $('#edit-notification-frequency').val(frequencyMap[frequency] || 'fifteen_min');

      $('#mld-edit-search-modal').show();
    },

    handleEditSubmit(e) {
      e.preventDefault();

      const data = {
        action: 'mld_update_search',
        nonce: mldSavedSearches.nonce,
        search_id: $('#edit-search-id').val(),
        name: $('#edit-search-name').val(),
        notification_frequency: $('#edit-notification-frequency').val(),
        is_active: $('#edit-is-active').is(':checked') ? 1 : 0,
      };

      const $submitBtn = $('#mld-edit-search-form button[type="submit"]');
      const originalText = $submitBtn.text();
      $submitBtn.text(mldSavedSearches.strings.saving).prop('disabled', true);

      $.post(mldSavedSearches.ajaxUrl, data, (response) => {
        $submitBtn.text(originalText).prop('disabled', false);

        if (response.success) {
          // Analytics: Track saved search edit (v6.38.0)
          document.dispatchEvent(new CustomEvent('mld:saved_search_edit', {
            detail: { searchId: data.search_id, changes: ['name', 'notification_frequency', 'is_active'] }
          }));
          this.closeModal();
          this.showSuccess(response.data.message);
          this.loadUserSearches();
        } else {
          this.showError(response.data || mldSavedSearches.strings.error);
        }
      }).fail(() => {
        $submitBtn.text(originalText).prop('disabled', false);
        this.showError(mldSavedSearches.strings.error);
      });
    },

    deleteSearch(e) {
      e.preventDefault();

      if (!confirm(mldSavedSearches.strings.confirmDelete)) {
        return;
      }

      const searchId = $(e.currentTarget).data('search-id');
      const $btn = $(e.currentTarget);
      const originalText = $btn.text();

      $btn.text('Deleting...').prop('disabled', true);

      $.post(
        mldSavedSearches.ajaxUrl,
        {
          action: 'mld_delete_search',
          nonce: mldSavedSearches.nonce,
          search_id: searchId,
        },
        (response) => {
          if (response.success) {
            // Analytics: Track saved search delete (v6.38.0)
            document.dispatchEvent(new CustomEvent('mld:saved_search_delete', {
              detail: { searchId: searchId }
            }));
            this.showSuccess(response.data.message);
            $btn.closest('.mld-search-card').fadeOut(() => {
              this.loadUserSearches();
            });
          } else {
            $btn.text(originalText).prop('disabled', false);
            this.showError(response.data || mldSavedSearches.strings.error);
          }
        }
      ).fail(() => {
        $btn.text(originalText).prop('disabled', false);
        this.showError(mldSavedSearches.strings.error);
      });
    },

    toggleStatus(e) {
      e.preventDefault();

      const searchId = $(e.currentTarget).data('search-id');
      const $btn = $(e.currentTarget);
      const originalText = $btn.text();

      $btn.text('Updating...').prop('disabled', true);

      $.post(
        mldSavedSearches.ajaxUrl,
        {
          action: 'mld_toggle_search_status',
          nonce: mldSavedSearches.nonce,
          search_id: searchId,
        },
        (response) => {
          if (response.success) {
            // Analytics: Track alert toggle (v6.38.0)
            document.dispatchEvent(new CustomEvent('mld:alert_toggle', {
              detail: { searchId: searchId, enabled: response.data.is_active }
            }));
            this.showSuccess(response.data.message);
            this.loadUserSearches();
          } else {
            $btn.text(originalText).prop('disabled', false);
            this.showError(response.data || mldSavedSearches.strings.error);
          }
        }
      ).fail(() => {
        $btn.text(originalText).prop('disabled', false);
        this.showError(mldSavedSearches.strings.error);
      });
    },

    viewSearch(e) {
      e.preventDefault();

      const searchId = $(e.currentTarget).data('search-id');

      // Analytics: Track saved search view (v6.38.0)
      document.dispatchEvent(new CustomEvent('mld:saved_search_view', {
        detail: { searchId: searchId, resultCount: 0 }
      }));

      // Use the saved search_url directly (like admin does)
      const url = $(e.currentTarget).data('url');

      if (url && url !== '#' && url !== '') {
        window.open(url, '_blank');
      } else {
        // Fallback: redirect to search page
        window.location.href = '/search/';
      }
    },

    /**
     * Share saved search (v6.57.0 - iOS alignment)
     */
    shareSearch(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const searchId = $btn.data('search-id');
      const searchName = $btn.data('name');
      const searchPath = $btn.data('url');

      // Build full URL for sharing
      const fullUrl = window.location.origin + searchPath;
      const shareTitle = `Property Search: ${searchName}`;
      const shareText = `Check out this property search: ${searchName}`;

      // Analytics: Track saved search share (v6.57.0)
      document.dispatchEvent(new CustomEvent('mld:saved_search_share', {
        detail: { searchId: searchId, method: 'unknown' }
      }));

      // Try Web Share API first (mobile-friendly)
      if (navigator.share) {
        navigator.share({
          title: shareTitle,
          text: shareText,
          url: fullUrl
        }).then(() => {
          this.showSuccess('Search shared successfully!');
        }).catch((err) => {
          // User cancelled or error - fall back to copy
          if (err.name !== 'AbortError') {
            this.copyToClipboard(fullUrl, searchName);
          }
        });
      } else {
        // Fallback: show share options modal or copy to clipboard
        this.showShareOptions(fullUrl, searchName, shareText);
      }
    },

    /**
     * Show share options for desktop (v6.57.0)
     */
    showShareOptions(url, name, text) {
      // Create share modal if not exists
      let $modal = $('#mld-share-modal');
      if ($modal.length === 0) {
        $modal = $(`
          <div id="mld-share-modal" class="mld-modal mld-share-modal">
            <div class="mld-modal-content mld-share-content">
              <button class="mld-modal-close">&times;</button>
              <h3 class="mld-share-title">Share This Search</h3>
              <div class="mld-share-url-box">
                <input type="text" id="mld-share-url" readonly>
                <button class="mld-button mld-copy-url" id="mld-copy-share-url">Copy</button>
              </div>
              <div class="mld-share-buttons">
                <a class="mld-share-btn mld-share-email" title="Share via Email">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                  </svg>
                  Email
                </a>
                <a class="mld-share-btn mld-share-sms" title="Share via Text">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                  </svg>
                  Text
                </a>
              </div>
            </div>
          </div>
        `);
        $('body').append($modal);

        // Event handlers
        $modal.find('.mld-modal-close').on('click', () => $modal.hide());
        $modal.on('click', (e) => {
          if ($(e.target).hasClass('mld-modal')) {
            $modal.hide();
          }
        });
        $('#mld-copy-share-url').on('click', () => {
          const urlInput = $('#mld-share-url');
          urlInput.select();
          document.execCommand('copy');
          this.showSuccess('Link copied to clipboard!');
          $modal.hide();
        });
      }

      // Set URL in input
      $('#mld-share-url').val(url);

      // Set share links
      const emailSubject = encodeURIComponent(`Property Search: ${name}`);
      const emailBody = encodeURIComponent(`${text}\n\n${url}`);
      $modal.find('.mld-share-email').attr('href', `mailto:?subject=${emailSubject}&body=${emailBody}`);

      // SMS link (works on mobile)
      const smsBody = encodeURIComponent(`${text} ${url}`);
      $modal.find('.mld-share-sms').attr('href', `sms:?body=${smsBody}`);

      $modal.show();
    },

    /**
     * Copy URL to clipboard (v6.57.0)
     */
    copyToClipboard(url, name) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
          this.showSuccess('Link copied to clipboard!');
        }).catch(() => {
          this.showShareOptions(url, name, `Check out this search: ${name}`);
        });
      } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = url;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        this.showSuccess('Link copied to clipboard!');
      }
    },

    closeModal() {
      $('.mld-modal').hide();
    },

    showSuccess(message) {
      MLD_Utils.notification.success(message);
    },

    showError(message) {
      MLD_Utils.notification.error(message);
    },

    escapeHtml(text) {
      return MLD_Utils.escapeHtml(text);
    },

    numberWithCommas(x) {
      return MLD_Utils.numberWithCommas(x);
    },
  };

  // Initialize when ready
  $(document).ready(() => {
    if ($('#mld-saved-searches-container').length > 0) {
      MLD_SavedSearchesFrontend.init();
    }

    if ($('#mld-saved-properties-container').length > 0) {
      MLD_SavedPropertiesFrontend.init();
    }
  });

  // Saved Properties Frontend Handler
  const MLD_SavedPropertiesFrontend = {
    init() {
      this.bindEvents();
      this.loadSavedProperties();
    },

    bindEvents() {
      $(document).on('click', '.mld-remove-property', (e) => this.removeProperty(e));
      $(document).on('click', '.mld-view-property', (e) => this.viewProperty(e));
    },

    loadSavedProperties() {
      $.post(
        mldSavedSearches.ajaxUrl,
        {
          action: 'mld_get_saved_properties',
          nonce: mldSavedSearches.nonce,
        },
        (response) => {
          $('.mld-loading').hide();

          if (response.success) {
            // Handle multiple response formats:
            // - response.data.saved_properties (legacy format)
            // - response.data.properties.liked (new format)
            let properties = [];

            if (response.data.saved_properties && response.data.saved_properties.length > 0) {
              properties = response.data.saved_properties;
            } else if (response.data.properties && response.data.properties.liked && response.data.properties.liked.length > 0) {
              properties = response.data.properties.liked;
            }

            if (properties.length > 0) {
              this.renderProperties(properties);
              $('#mld-properties-grid').show();
            } else {
              $('#mld-no-properties').show();
            }
          } else {
            $('#mld-no-properties').show();
          }
        }
      ).fail(() => {
        $('.mld-loading').hide();
        $('#mld-no-properties').show();
        this.showError(mldSavedSearches.strings.error);
      });
    },

    renderProperties(properties) {
      const container = $('#mld-properties-grid');
      container.empty();

      properties.forEach((property) => {
        const propertyCard = this.createPropertyCard(property);
        container.append(propertyCard);
      });
    },

    createPropertyCard(property) {
      const price = property.ListPrice
        ? '$' + this.numberWithCommas(property.ListPrice)
        : 'Price Not Available';
      const address = property.full_address || 'Address Not Available';
      const imageUrl =
        property.featured_image_url ||
        '/wp-content/plugins/mls-listings-display/assets/images/no-image.jpg';
      const beds = property.BedroomsTotal || 0;
      const baths = property.BathroomsTotalInteger || 0;
      const sqft = property.LivingArea || 0;

      return `
                <div class="mld-property-card" data-listing-id="${property.listing_id}">
                    <div class="mld-property-image">
                        <img src="${imageUrl}" alt="${this.escapeHtml(address)}" loading="lazy">
                        <div class="mld-property-price">${price}</div>
                    </div>
                    
                    <div class="mld-property-details">
                        <h4 class="mld-property-address">${this.escapeHtml(address)}</h4>
                        
                        <div class="mld-property-stats">
                            ${beds > 0 ? `<span>${beds} beds</span>` : ''}
                            ${baths > 0 ? `<span>${baths} baths</span>` : ''}
                            ${sqft > 0 ? `<span>${this.numberWithCommas(sqft)} sqft</span>` : ''}
                        </div>
                        
                        <div class="mld-property-actions">
                            <a href="/property/${property.listing_id}/" class="mld-button mld-button-primary mld-button-small mld-view-property">
                                View Details
                            </a>
                            <button class="mld-button mld-button-secondary mld-button-small mld-remove-property" 
                                    data-listing-id="${property.listing_id}">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            `;
    },

    removeProperty(e) {
      e.preventDefault();

      const listingId = $(e.currentTarget).data('listing-id');
      const $card = $(e.currentTarget).closest('.mld-property-card');

      if (!confirm('Are you sure you want to remove this property from your saved list?')) {
        return;
      }

      $.post(
        mldSavedSearches.ajaxUrl,
        {
          action: 'mld_remove_saved_property',
          nonce: mldSavedSearches.nonce,
          listing_id: listingId,
        },
        (response) => {
          if (response.success) {
            $card.fadeOut(() => {
              $card.remove();

              // Check if any properties remain
              if ($('.mld-property-card').length === 0) {
                $('#mld-properties-grid').hide();
                $('#mld-no-properties').show();
              }
            });

            this.showSuccess(response.data.message);
          } else {
            this.showError(response.data || mldSavedSearches.strings.error);
          }
        }
      ).fail(() => {
        this.showError(mldSavedSearches.strings.error);
      });
    },

    viewProperty(e) {
      // Link naturally navigates to property page
    },

    showSuccess(message) {
      MLD_SavedSearchesFrontend.showSuccess(message);
    },

    showError(message) {
      MLD_SavedSearchesFrontend.showError(message);
    },

    escapeHtml(text) {
      return MLD_SavedSearchesFrontend.escapeHtml(text);
    },

    numberWithCommas(x) {
      return MLD_SavedSearchesFrontend.numberWithCommas(x);
    },
  };
})(jQuery);
