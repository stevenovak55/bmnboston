/**
 * Mobile Search Initialization
 * Ensures mobile view mode is properly initialized
 * @param $
 */

(function ($) {
  'use strict';

  // Mobile filter indicator management
  const MobileFilters = {
    getActiveFilterCount() {
      let count = 0;
      const modalFilters = window.MLD_Map_App?.modalFilters || {};
      const keywordFilters = window.MLD_Map_App?.keywordFilters || {};
      const defaults = window.MLD_Filters?.getModalDefaults
        ? window.MLD_Filters.getModalDefaults()
        : {};

      // Count keyword filters
      for (const key in keywordFilters) {
        if (keywordFilters[key] && keywordFilters[key].size > 0) {
          count += keywordFilters[key].size;
        }
      }

      // Count modal filters that differ from defaults
      if (modalFilters.price_min || modalFilters.price_max) {
        count++;
      }

      // Count beds
      if (modalFilters.beds && modalFilters.beds.length > 0) {
        count += modalFilters.beds.length;
      }

      // Count baths if different from default
      if (modalFilters.baths_min != defaults.baths_min && modalFilters.baths_min != 0) {
        count++;
      }

      // Count garage/parking if different from defaults
      if (
        modalFilters.garage_spaces_min != defaults.garage_spaces_min &&
        modalFilters.garage_spaces_min != 0
      ) {
        count++;
      }
      if (
        modalFilters.parking_total_min != defaults.parking_total_min &&
        modalFilters.parking_total_min != 0
      ) {
        count++;
      }

      // Count home types, status, structure types, styles
      ['home_type', 'status', 'structure_type', 'architectural_style'].forEach((key) => {
        if (modalFilters[key] && Array.isArray(modalFilters[key])) {
          // For status, only count if different from default ['Active']
          if (key === 'status') {
            const defaultStatus = defaults.status || ['Active'];
            if (JSON.stringify(modalFilters[key]) !== JSON.stringify(defaultStatus)) {
              count += modalFilters[key].length;
            }
          } else if (modalFilters[key].length > 0) {
            count += modalFilters[key].length;
          }
        }
      });

      // Count boolean filters
      for (const key in modalFilters) {
        if (
          typeof modalFilters[key] === 'boolean' &&
          modalFilters[key] === true &&
          defaults.hasOwnProperty(key) &&
          defaults[key] === false
        ) {
          count++;
        }
      }

      // Count range filters (sqft, lot_size, year_built, entry_level)
      const rangeFilters = ['sqft', 'lot_size', 'year_built', 'entry_level'];
      rangeFilters.forEach((base) => {
        const minKey = `${base}_min`;
        const maxKey = `${base}_max`;
        if (modalFilters[minKey] || modalFilters[maxKey]) {
          count++;
        }
      });

      return count;
    },

    getFirstFilterLabel() {
      const modalFilters = window.MLD_Map_App?.modalFilters || {};
      const keywordFilters = window.MLD_Map_App?.keywordFilters || {};
      const defaults = window.MLD_Filters?.getModalDefaults
        ? window.MLD_Filters.getModalDefaults()
        : {};

      // Check keyword filters first
      for (const key in keywordFilters) {
        if (keywordFilters[key] && keywordFilters[key].size > 0) {
          // Get first value from Set
          return Array.from(keywordFilters[key])[0];
        }
      }

      // Check price filter
      if (modalFilters.price_min || modalFilters.price_max) {
        const min = modalFilters.price_min || 0;
        const max = modalFilters.price_max || 0;
        if (min && max) return `$${min.toLocaleString()}-$${max.toLocaleString()}`;
        if (min) return `$${min.toLocaleString()}+`;
        if (max) return `Up to $${max.toLocaleString()}`;
      }

      // Check beds
      if (modalFilters.beds && modalFilters.beds.length > 0) {
        return `${modalFilters.beds[0]} Beds`;
      }

      // Check baths
      if (modalFilters.baths_min != defaults.baths_min && modalFilters.baths_min != 0) {
        return `${modalFilters.baths_min}+ Baths`;
      }

      // Check home type
      if (modalFilters.home_type && modalFilters.home_type.length > 0) {
        return modalFilters.home_type[0];
      }

      // Check status if different from default
      if (modalFilters.status && Array.isArray(modalFilters.status)) {
        const defaultStatus = defaults.status || ['Active'];
        if (JSON.stringify(modalFilters.status) !== JSON.stringify(defaultStatus)) {
          return modalFilters.status[0];
        }
      }

      // Check other filters
      for (const key in modalFilters) {
        const value = modalFilters[key];
        if (value && value !== defaults[key]) {
          if (typeof value === 'boolean' && value === true) {
            return window.MLD_Utils?.get_field_label ? window.MLD_Utils.get_field_label(key) : key;
          }
        }
      }

      return '';
    },

    updateFilterIndicator() {
      const count = this.getActiveFilterCount();
      let $indicator = $('#bme-mobile-filter-indicator');

      if (count === 0) {
        $indicator.remove();
        return;
      }

      if ($indicator.length === 0) {
        $indicator = $(
          '<div id="bme-mobile-filter-indicator" class="bme-mobile-filter-indicator"></div>'
        );
        // Try multiple possible parent elements
        const $searchWrapper = $('#bme-search-bar-wrapper, #bme-search-wrapper').first();
        if ($searchWrapper.length > 0) {
          $searchWrapper.append($indicator);
          MLDLogger.debug('Filter indicator added to search wrapper');
        } else {
          MLDLogger.error('Could not find search wrapper element');
        }
      }

      let text = this.getFirstFilterLabel();
      if (count > 1) {
        text += ` +${count - 1} more`;
      }

      $indicator.text(text);

      // Add click handler to open filters modal
      $indicator.off('click').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        MLDLogger.debug('Filter indicator clicked');
        // Open the filters modal
        $('#bme-filters-modal-overlay').css('display', 'flex');
        // Update filter count and options
        if (window.MLD_API) {
          window.MLD_API.updateFilterCount();
          window.MLD_API.fetchDynamicFilterOptions();
        }
        // Add filter tags to modal
        setTimeout(() => MobileFilters.addFilterTagsToModal(), 100);
      });
    },

    showFilterSummary() {
      MLDLogger.debug('showFilterSummary called');

      const $modal = $('<div class="bme-mobile-filter-summary-modal"></div>');
      const $content = $('<div class="bme-mobile-filter-summary-content"></div>');
      const $header = $(
        '<div class="bme-mobile-filter-summary-header"><h3>Active Filters</h3><button class="bme-close-modal">&times;</button></div>'
      );
      const $body = $('<div class="bme-mobile-filter-summary-body"></div>');
      const $footer = $(
        '<div class="bme-mobile-filter-summary-footer"><button class="bme-clear-all-filters">Clear All</button></div>'
      );

      // Get all active filters using same logic as desktop
      const filterTags = [];
      const modalFilters = window.MLD_Map_App?.modalFilters || {};
      const keywordFilters = window.MLD_Map_App?.keywordFilters || {};
      const defaults = window.MLD_Filters?.getModalDefaults
        ? window.MLD_Filters.getModalDefaults()
        : {};

      // Add keyword filters
      for (const type in keywordFilters) {
        if (keywordFilters[type] && keywordFilters[type].size > 0) {
          keywordFilters[type].forEach((value) => {
            filterTags.push({
              type,
              value,
              label: value,
            });
          });
        }
      }

      // Add price filter
      if (modalFilters.price_min || modalFilters.price_max) {
        const min = modalFilters.price_min || 0;
        const max = modalFilters.price_max || 0;
        let label = '';
        if (min && max) label = `Price: $${min.toLocaleString()}-$${max.toLocaleString()}`;
        else if (min) label = `Price: $${min.toLocaleString()}+`;
        else label = `Price: Up to $${max.toLocaleString()}`;

        filterTags.push({
          type: 'price',
          value: 'all',
          label,
        });
      }

      // Add beds
      if (modalFilters.beds && modalFilters.beds.length > 0) {
        modalFilters.beds.forEach((bed) => {
          filterTags.push({
            type: 'beds',
            value: bed,
            label: `Beds: ${bed}`,
          });
        });
      }

      // Add baths
      if (modalFilters.baths_min != defaults.baths_min && modalFilters.baths_min != 0) {
        filterTags.push({
          type: 'baths_min',
          value: modalFilters.baths_min,
          label: `Baths: ${modalFilters.baths_min}+`,
        });
      }

      // Add garage/parking
      if (
        modalFilters.garage_spaces_min != defaults.garage_spaces_min &&
        modalFilters.garage_spaces_min != 0
      ) {
        filterTags.push({
          type: 'garage_spaces_min',
          value: modalFilters.garage_spaces_min,
          label: `Garage: ${modalFilters.garage_spaces_min}+`,
        });
      }
      if (
        modalFilters.parking_total_min != defaults.parking_total_min &&
        modalFilters.parking_total_min != 0
      ) {
        filterTags.push({
          type: 'parking_total_min',
          value: modalFilters.parking_total_min,
          label: `Parking: ${modalFilters.parking_total_min}+`,
        });
      }

      // Add home types, status, structure types, architectural styles
      ['home_type', 'status', 'structure_type', 'architectural_style'].forEach((type) => {
        if (modalFilters[type] && Array.isArray(modalFilters[type])) {
          if (type === 'status') {
            const defaultStatus = defaults.status || ['Active'];
            if (JSON.stringify(modalFilters[type]) !== JSON.stringify(defaultStatus)) {
              modalFilters[type].forEach((value) => {
                filterTags.push({
                  type,
                  value,
                  label: value,
                });
              });
            }
          } else if (modalFilters[type].length > 0) {
            modalFilters[type].forEach((value) => {
              filterTags.push({
                type,
                value,
                label: value,
              });
            });
          }
        }
      });

      // Add boolean filters
      for (const key in modalFilters) {
        if (
          typeof modalFilters[key] === 'boolean' &&
          modalFilters[key] === true &&
          defaults.hasOwnProperty(key) &&
          defaults[key] === false
        ) {
          const label = window.MLD_Utils?.get_field_label
            ? window.MLD_Utils.get_field_label(key)
            : key;
          filterTags.push({
            type: key,
            value: true,
            label,
          });
        }
      }

      // Add range filters
      const rangeFilters = {
        sqft: 'Sq Ft',
        lot_size: 'Lot Size',
        year_built: 'Year Built',
        entry_level: 'Unit Level',
      };

      for (const base in rangeFilters) {
        const minKey = `${base}_min`;
        const maxKey = `${base}_max`;
        const minVal = modalFilters[minKey];
        const maxVal = modalFilters[maxKey];
        const label = rangeFilters[base];

        if (minVal && maxVal) {
          filterTags.push({
            type: minKey,
            value: `${minVal}-${maxVal}`,
            label: `${label}: ${minVal} - ${maxVal}`,
          });
        } else if (minVal) {
          filterTags.push({
            type: minKey,
            value: minVal,
            label: `${label}: ${minVal}+`,
          });
        } else if (maxVal) {
          filterTags.push({
            type: maxKey,
            value: maxVal,
            label: `${label}: Up to ${maxVal}`,
          });
        }
      }

      // Render filter tags
      filterTags.forEach((tag) => {
        const $tag = $(
          `<div class="bme-mobile-filter-tag">${tag.label} <span class="bme-remove-filter" data-type="${tag.type}" data-value="${tag.value}">&times;</span></div>`
        );
        $body.append($tag);
      });

      $content.append($header, $body, $footer);
      $modal.append($content);
      $('body').append($modal);

      // Event handlers
      $modal.find('.bme-close-modal').on('click', function () {
        $modal.remove();
      });

      $modal.find('.bme-clear-all-filters').on('click', function () {
        if (window.MLD_Filters) {
          window.MLD_Filters.clearAllFilters();
        }
        $modal.remove();
        MobileFilters.updateFilterIndicator();
      });

      $modal.find('.bme-remove-filter').on('click', function () {
        const type = $(this).data('type');
        const value = $(this).data('value');
        if (window.MLD_Filters) {
          window.MLD_Filters.removeFilter(type, value);
        }
        $(this).parent().remove();
        MobileFilters.updateFilterIndicator();

        // Close modal if no filters left
        if ($modal.find('.bme-mobile-filter-tag').length === 0) {
          $modal.remove();
        }
      });

      // Close on background click
      $modal.on('click', function (e) {
        if (e.target === this) {
          $modal.remove();
        }
      });
    },
  };

  // Wait for MLD modules to be available
  function initializeMobileView() {
    if (typeof window.MLD_Core === 'undefined' || typeof window.MLD_Map_App === 'undefined') {
      setTimeout(initializeMobileView, 100);
      return;
    }

    // Check if we're on mobile
    if (window.innerWidth <= 768) {
      MLDLogger.debug('Initializing mobile view mode');

      // Set default view mode to list on mobile
      const savedViewMode = localStorage.getItem('bme_view_mode') || 'list';

      // Wait for DOM elements to be ready
      $(document).ready(function () {
        setTimeout(function () {
          if (window.MLD_Core && typeof window.MLD_Core.setViewMode === 'function') {
            window.MLD_Core.setViewMode(savedViewMode);
          }

          // Ensure view toggle is visible
          $('#bme-view-mode-toggle').show();

          // Add mobile class to wrapper
          $('#bme-half-map-wrapper').addClass('mobile-view');

          // Set initial map controls visibility
          if (
            window.MLD_Map_App &&
            typeof window.MLD_Map_App.toggleMapControlsVisibility === 'function'
          ) {
            window.MLD_Map_App.toggleMapControlsVisibility(savedViewMode);
          }

          // Dynamically adjust list container padding
          MobileFilters.adjustListPadding();
        }, 500);
      });

      // Handle orientation changes
      $(window).on('orientationchange resize', function () {
        setTimeout(function () {
          if (window.MLD_Map_App && window.MLD_Map_App.map) {
            if (window.MLD_Map_App.map.resize) {
              window.MLD_Map_App.map.resize(); // Mapbox
            } else if (window.google && window.google.maps && window.google.maps.event) {
              google.maps.event.trigger(window.MLD_Map_App.map, 'resize'); // Google Maps
            }
          }
        }, 300);
      });

      // Save view mode preference
      $(document).on('click', '.bme-view-mode-btn', function () {
        const mode = $(this).data('mode');
        localStorage.setItem('bme_view_mode', mode);

        // Toggle map controls visibility
        if (
          window.MLD_Map_App &&
          typeof window.MLD_Map_App.toggleMapControlsVisibility === 'function'
        ) {
          window.MLD_Map_App.toggleMapControlsVisibility(mode);
        }

        // Adjust padding when switching to list view
        if (mode === 'list') {
          setTimeout(() => MobileFilters.adjustListPadding(), 300);
        }
      });

      // Mobile draw functionality is only available in map view

      // Hook into filter changes
      const originalRenderFilterTags = window.MLD_Filters?.renderFilterTags;
      if (originalRenderFilterTags) {
        window.MLD_Filters.renderFilterTags = function () {
          // Call original function
          originalRenderFilterTags.apply(this, arguments);
          // Update mobile indicator
          MobileFilters.updateFilterIndicator();
          // Adjust list padding after filter tags render
          setTimeout(() => MobileFilters.adjustListPadding(), 100);
        };
      }

      // Override centerOnUserLocation for mobile zoom level
      const originalCenterOnUserLocation = window.MLD_Core?.centerOnUserLocation;
      if (originalCenterOnUserLocation) {
        window.MLD_Core.centerOnUserLocation = function () {
          const app = window.MLD_Map_App;
          if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser.');
            $('#bme-nearby-toggle').prop('checked', false);
            return;
          }

          navigator.geolocation.getCurrentPosition(
            (position) => {
              const isMapbox = bmeMapData.provider === 'mapbox';
              const center = isMapbox
                ? [position.coords.longitude, position.coords.latitude]
                : { lat: position.coords.latitude, lng: position.coords.longitude };
              app.isNearbySearchActive = true;

              // Use zoom level 13 for mobile instead of 14
              const zoomLevel = window.innerWidth <= 768 ? 13 : 14;
              app.map.setZoom(zoomLevel);
              app.map.setCenter(center);

              if (window.MLD_Markers) {
                window.MLD_Markers.createUserLocationMarker(center, isMapbox);
              }
            },
            (error) => {
              app.isNearbySearchActive = false;

              // Provide specific error messages based on error code
              let errorMessage = '';
              switch(error.code) {
                case error.PERMISSION_DENIED:
                  errorMessage = 'Location access was denied. Please enable location permissions for this site in your browser settings.';
                  if (window.MLDLogger) {
                    window.MLDLogger.debug('Geolocation permission denied (mobile)');
                  }
                  break;
                case error.POSITION_UNAVAILABLE:
                  errorMessage = 'Location information is unavailable. Please check your device settings and try again.';
                  if (window.MLDLogger) {
                    window.MLDLogger.debug('Geolocation position unavailable (mobile)');
                  }
                  break;
                case error.TIMEOUT:
                  errorMessage = 'Location request timed out. Please try again.';
                  if (window.MLDLogger) {
                    window.MLDLogger.debug('Geolocation request timed out (mobile)');
                  }
                  break;
                default:
                  errorMessage = 'Unable to retrieve your location. Please try again or check your browser settings.';
                  if (window.MLDLogger) {
                    window.MLDLogger.debug('Geolocation unknown error (mobile)', error);
                  }
              }

              alert(errorMessage);
              $('#bme-nearby-toggle').prop('checked', false);
              if (window.MLD_Markers) {
                window.MLD_Markers.removeUserLocationMarker();
              }
            },
            {
              enableHighAccuracy: false,
              timeout: 10000,
              maximumAge: 60000
            }
          );
        };
      }

      // Initial filter indicator update
      setTimeout(function () {
        MobileFilters.updateFilterIndicator();
      }, 1000);

      // Also update on filter modal close
      $(document).on('click', '#bme-apply-filters-btn, #bme-clear-filters-btn', function () {
        setTimeout(function () {
          MobileFilters.updateFilterIndicator();
        }, 100);
      });

      // Add mobile filter tags container to modal when it opens
      $(document).on('click', '#bme-filters-button', function () {
        setTimeout(function () {
          MobileFilters.addFilterTagsToModal();
        }, 100);
      });

      // Update filter bubbles when filters change in modal
      // Listen for changes on filter inputs
      $(document).on(
        'change',
        '#bme-filters-modal-content input, #bme-filters-modal-content select',
        function () {
          setTimeout(() => MobileFilters.addFilterTagsToModal(), 100);
        }
      );

      // Listen for button clicks in filter groups
      $(document).on('click', '.bme-button-group button, .bme-checkbox-group input', function () {
        setTimeout(() => MobileFilters.addFilterTagsToModal(), 100);
      });

      // Listen for keyword filter changes
      $(document).on('keyup', '#bme-search-input-modal', function () {
        setTimeout(() => MobileFilters.addFilterTagsToModal(), 300);
      });

      // Listen for autocomplete selections
      $(document).on(
        'click',
        '#bme-autocomplete-suggestions-modal .bme-suggestion-item',
        function () {
          setTimeout(() => MobileFilters.addFilterTagsToModal(), 100);
        }
      );
    }
  }

  // Add this function outside of initializeMobileView
  MobileFilters.addFilterTagsToModal = function () {
    if (window.innerWidth > 768) return; // Only on mobile

    // Check if container already exists
    let $mobileTagsContainer = $('#bme-mobile-modal-tags');
    if ($mobileTagsContainer.length === 0) {
      // Find the search group in modal and add after it
      const $searchGroup = $('#bme-modal-search-group');
      if ($searchGroup.length > 0) {
        $mobileTagsContainer = $(
          '<div id="bme-mobile-modal-tags" class="bme-mobile-modal-tags"></div>'
        );
        $searchGroup.after($mobileTagsContainer);
      }
    }

    // Clear and rebuild tags
    $mobileTagsContainer.empty();

    const modalFilters = window.MLD_Map_App?.modalFilters || {};
    const keywordFilters = window.MLD_Map_App?.keywordFilters || {};
    const defaults = window.MLD_Filters?.getModalDefaults
      ? window.MLD_Filters.getModalDefaults()
      : {};
    const filterTags = [];

    // Build filter tags array (same logic as showFilterSummary)
    // Add keyword filters
    for (const type in keywordFilters) {
      if (keywordFilters[type] && keywordFilters[type].size > 0) {
        keywordFilters[type].forEach((value) => {
          filterTags.push({
            type,
            value,
            label: value,
          });
        });
      }
    }

    // Add all other filters using same logic as showFilterSummary
    if (modalFilters.price_min || modalFilters.price_max) {
      const min = modalFilters.price_min || 0;
      const max = modalFilters.price_max || 0;
      let label = '';
      if (min && max) label = `$${min.toLocaleString()}-$${max.toLocaleString()}`;
      else if (min) label = `$${min.toLocaleString()}+`;
      else label = `Up to $${max.toLocaleString()}`;

      filterTags.push({
        type: 'price',
        value: 'all',
        label,
      });
    }

    // Add beds
    if (modalFilters.beds && modalFilters.beds.length > 0) {
      modalFilters.beds.forEach((bed) => {
        filterTags.push({
          type: 'beds',
          value: bed,
          label: `Beds: ${bed}`,
        });
      });
    }

    // Add baths
    if (modalFilters.baths_min != defaults.baths_min && modalFilters.baths_min != 0) {
      filterTags.push({
        type: 'baths_min',
        value: modalFilters.baths_min,
        label: `Baths: ${modalFilters.baths_min}+`,
      });
    }

    // Add home types, status, etc.
    ['home_type', 'status', 'structure_type', 'architectural_style'].forEach((type) => {
      if (modalFilters[type] && Array.isArray(modalFilters[type])) {
        if (type === 'status') {
          const defaultStatus = defaults.status || ['Active'];
          if (JSON.stringify(modalFilters[type]) !== JSON.stringify(defaultStatus)) {
            modalFilters[type].forEach((value) => {
              filterTags.push({
                type,
                value,
                label: value,
              });
            });
          }
        } else if (modalFilters[type].length > 0) {
          modalFilters[type].forEach((value) => {
            filterTags.push({
              type,
              value,
              label: value,
            });
          });
        }
      }
    });

    // Show only if there are filters
    if (filterTags.length > 0) {
      filterTags.forEach((tag) => {
        const $tag = $(
          `<div class="bme-filter-tag">${tag.label} <span class="bme-filter-tag-remove" data-type="${tag.type}" data-value="${tag.value}">&times;</span></div>`
        );
        $tag.find('.bme-filter-tag-remove').on('click', function () {
          if (window.MLD_Filters) {
            window.MLD_Filters.removeFilter(tag.type, tag.value);
          }
          // Update modal tags
          setTimeout(() => MobileFilters.addFilterTagsToModal(), 100);
        });
        $mobileTagsContainer.append($tag);
      });
      $mobileTagsContainer.show();
    } else {
      $mobileTagsContainer.hide();
    }
  };

  // Function to adjust list padding dynamically
  MobileFilters.adjustListPadding = function () {
    const $topBar = $('#bme-top-bar');
    const $filterTags = $('#bme-filter-tags-container');
    const $listContainer = $('#bme-listings-list-container');

    if ($topBar.length && $listContainer.length) {
      let totalHeight = 0;

      // Get top bar height including margin/padding
      const topBarHeight = $topBar.outerHeight(true);
      totalHeight += topBarHeight || 60;

      // Get filter tags height if visible
      if ($filterTags.length && $filterTags.is(':visible') && $filterTags.children().length > 0) {
        const filterTagsHeight = $filterTags.outerHeight(true);
        totalHeight += filterTagsHeight || 0;
      }

      // Add extra buffer space
      totalHeight += 20;

      MLDLogger.debug('Adjusting list padding to:', totalHeight);
      $listContainer.css('padding-top', totalHeight + 'px');
    }
  };

  // Start initialization
  initializeMobileView();
})(jQuery);
