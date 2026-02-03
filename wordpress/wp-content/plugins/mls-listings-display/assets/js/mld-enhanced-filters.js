/**
 * Enhanced Filters UI Components for MLS Listings Display
 *
 * Provides interactive filter interface with multi-range sliders,
 * map-based polygon drawing, and advanced filter builder.
 *
 * @param $
 * @package
 * @since 3.0
 */

(function ($) {
  'use strict';

  /**
   * Enhanced Filters Class
   */
  class MLDEnhancedFilters {
    constructor(options = {}) {
      this.options = {
        container: '.mld-enhanced-filters',
        mapContainer: '.mld-map-container',
        apiUrl: mld_ajax.ajax_url,
        nonce: mld_ajax.nonce,
        ...options,
      };

      this.filters = {};
      this.map = null;
      this.drawingManager = null;
      this.polygons = [];
      this.circles = [];
      this.rangeSliders = {};
      this.filterPresets = {};

      this.init();
    }

    /**
     * Initialize the enhanced filters
     */
    init() {
      this.bindEvents();
      this.initializeRangeSliders();
      this.initializeMap();
      this.loadFilterPresets();
      this.setupAutocomplete();
      this.initializeTabs();
      this.loadSavedFilters();
    }

    /**
     * Bind events
     */
    bindEvents() {
      const self = this;

      // Filter form submission
      $(document).on('submit', '.mld-filter-form', function (e) {
        e.preventDefault();
        self.applyFilters();
      });

      // Clear all filters
      $(document).on('click', '.mld-clear-filters', function (e) {
        e.preventDefault();
        self.clearAllFilters();
      });

      // Filter preset selection
      $(document).on('change', '.mld-filter-preset-select', function () {
        const presetId = $(this).val();
        if (presetId) {
          self.applyFilterPreset(presetId);
        }
      });

      // Add/remove keyword exclusions
      $(document).on('click', '.mld-add-keyword', function () {
        self.addKeywordField();
      });

      $(document).on('click', '.mld-remove-keyword', function () {
        $(this).closest('.mld-keyword-item').remove();
      });

      // Add/remove address exclusions
      $(document).on('click', '.mld-add-address', function () {
        self.addAddressField();
      });

      $(document).on('click', '.mld-remove-address', function () {
        $(this).closest('.mld-address-item').remove();
      });

      // Toggle filter sections
      $(document).on('click', '.mld-filter-section-toggle', function () {
        const section = $(this).data('section');
        self.toggleFilterSection(section);
      });

      // Save custom filter preset
      $(document).on('click', '.mld-save-preset', function () {
        self.showSavePresetModal();
      });

      // Real-time filter count updates
      $(document).on('change', '.mld-filter-input', function () {
        self.updateFilterCount();
      });

      // Map drawing controls
      $(document).on('click', '.mld-drawing-control', function () {
        const tool = $(this).data('tool');
        self.activateDrawingTool(tool);
      });

      // Clear map drawings
      $(document).on('click', '.mld-clear-drawings', function () {
        self.clearMapDrawings();
      });

      // Neighborhood search
      $(document).on('input', '.mld-neighborhood-search', function () {
        const query = $(this).val();
        if (query.length >= 2) {
          self.searchNeighborhoods(query);
        }
      });

      // ZIP code search
      $(document).on('input', '.mld-zipcode-search', function () {
        const query = $(this).val();
        if (query.length >= 2) {
          self.searchZipCodes(query);
        }
      });

      // Commute destination input
      $(document).on('input', '.mld-commute-address', function () {
        const address = $(this).val();
        if (address.length >= 5) {
          self.geocodeAddress(address);
        }
      });

      // Advanced/Basic toggle
      $(document).on('click', '.mld-toggle-advanced', function () {
        self.toggleAdvancedFilters();
      });
    }

    /**
     * Initialize range sliders
     */
    initializeRangeSliders() {
      const self = this;

      // Price range slider
      this.initRangeSlider('.mld-price-range', {
        min: 0,
        max: 5000000,
        step: 25000,
        format: 'currency',
      });

      // Square footage slider
      this.initRangeSlider('.mld-sqft-range', {
        min: 0,
        max: 10000,
        step: 100,
        format: 'number',
      });

      // Lot size slider
      this.initRangeSlider('.mld-lot-size-range', {
        min: 0,
        max: 10,
        step: 0.25,
        format: 'decimal',
      });

      // Year built slider
      const currentYear = new Date().getFullYear();
      this.initRangeSlider('.mld-year-built-range', {
        min: 1900,
        max: currentYear,
        step: 1,
        format: 'year',
      });

      // HOA fees slider
      this.initRangeSlider('.mld-hoa-range', {
        min: 0,
        max: 2000,
        step: 25,
        format: 'currency',
      });
    }

    /**
     * Initialize individual range slider
     * @param selector
     * @param options
     */
    initRangeSlider(selector, options) {
      const $container = $(selector);
      if (!$container.length) return;

      const sliderId = selector.replace('.', '') + '-slider';
      const $slider = $('<div>').attr('id', sliderId).addClass('mld-range-slider');
      const $minInput = $container.find('.mld-range-min');
      const $maxInput = $container.find('.mld-range-max');
      const $display = $container.find('.mld-range-display');

      $container.append($slider);

      // Initialize noUiSlider if available
      if (typeof noUiSlider !== 'undefined') {
        const slider = document.getElementById(sliderId);

        noUiSlider.create(slider, {
          start: [options.min, options.max],
          connect: true,
          range: {
            min: options.min,
            max: options.max,
          },
          step: options.step,
          format: {
            to: (value) => this.formatValue(value, options.format),
            from: (value) => parseFloat(value.replace(/[^0-9.-]/g, '')),
          },
        });

        slider.noUiSlider.on('update', (values, handle) => {
          const min = values[0];
          const max = values[1];

          $minInput.val(min);
          $maxInput.val(max);
          $display.text(`${min} - ${max}`);
        });

        // Update slider when inputs change
        $minInput.on('change', () => {
          slider.noUiSlider.set([$minInput.val(), null]);
        });

        $maxInput.on('change', () => {
          slider.noUiSlider.set([null, $maxInput.val()]);
        });

        this.rangeSliders[selector] = slider;
      } else {
        // Fallback for browsers without noUiSlider
        this.initFallbackRangeInputs($container, options);
      }
    }

    /**
     * Initialize fallback range inputs
     * @param $container
     * @param options
     */
    initFallbackRangeInputs($container, options) {
      const $minInput = $container.find('.mld-range-min');
      const $maxInput = $container.find('.mld-range-max');
      const $display = $container.find('.mld-range-display');

      $minInput.attr({
        type: 'number',
        min: options.min,
        max: options.max,
        step: options.step,
      });

      $maxInput.attr({
        type: 'number',
        min: options.min,
        max: options.max,
        step: options.step,
      });

      const updateDisplay = () => {
        const min = this.formatValue($minInput.val() || options.min, options.format);
        const max = this.formatValue($maxInput.val() || options.max, options.format);
        $display.text(`${min} - ${max}`);
      };

      $minInput.on('change', updateDisplay);
      $maxInput.on('change', updateDisplay);

      updateDisplay();
    }

    /**
     * Format value based on type
     * @param value
     * @param format
     */
    formatValue(value, format) {
      const numValue = parseFloat(value);

      switch (format) {
        case 'currency':
          return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
          }).format(numValue);

        case 'decimal':
          return numValue.toFixed(2);

        case 'year':
          return Math.round(numValue).toString();

        case 'number':
        default:
          return new Intl.NumberFormat('en-US').format(Math.round(numValue));
      }
    }

    /**
     * Initialize map for geographic filters
     */
    initializeMap() {
      const $mapContainer = $(this.options.mapContainer);
      if (!$mapContainer.length) return;

      const mapElement = $mapContainer[0];

      // Try Google Maps first, fall back to Leaflet
      if (typeof google !== 'undefined' && google.maps) {
        this.initGoogleMap(mapElement);
      } else if (typeof L !== 'undefined') {
        this.initLeafletMap(mapElement);
      } else {
        // Show message about map requirements
        $mapContainer.html(
          '<div class="mld-map-notice">Map functionality requires Google Maps API or Leaflet library.</div>'
        );
      }
    }

    /**
     * Initialize Google Maps
     * @param mapElement
     */
    initGoogleMap(mapElement) {
      const self = this;

      this.map = new google.maps.Map(mapElement, {
        center: { lat: 39.8283, lng: -98.5795 }, // Center of US
        zoom: 4,
        mapTypeId: google.maps.MapTypeId.ROADMAP,
      });

      // Initialize drawing manager
      this.drawingManager = new google.maps.drawing.DrawingManager({
        drawingMode: null,
        drawingControl: true,
        drawingControlOptions: {
          position: google.maps.ControlPosition.TOP_CENTER,
          drawingModes: [
            google.maps.drawing.OverlayType.POLYGON,
            google.maps.drawing.OverlayType.CIRCLE,
          ],
        },
        polygonOptions: {
          fillColor: '#3498db',
          fillOpacity: 0.3,
          strokeColor: '#2980b9',
          strokeWeight: 2,
          clickable: false,
          editable: true,
        },
        circleOptions: {
          fillColor: '#e74c3c',
          fillOpacity: 0.3,
          strokeColor: '#c0392b',
          strokeWeight: 2,
          clickable: false,
          editable: true,
        },
      });

      this.drawingManager.setMap(this.map);

      // Handle completed drawings
      google.maps.event.addListener(this.drawingManager, 'polygoncomplete', function (polygon) {
        self.addPolygon(polygon);
      });

      google.maps.event.addListener(this.drawingManager, 'circlecomplete', function (circle) {
        self.addCircle(circle);
      });
    }

    /**
     * Initialize Leaflet map
     * @param mapElement
     */
    initLeafletMap(mapElement) {
      const self = this;

      this.map = L.map(mapElement).setView([39.8283, -98.5795], 4);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
      }).addTo(this.map);

      // Initialize drawing controls if Leaflet.Draw is available
      if (typeof L.Control.Draw !== 'undefined') {
        const drawnItems = new L.FeatureGroup();
        this.map.addLayer(drawnItems);

        const drawControl = new L.Control.Draw({
          edit: {
            featureGroup: drawnItems,
          },
          draw: {
            polygon: true,
            circle: true,
            marker: false,
            polyline: false,
            rectangle: false,
            circlemarker: false,
          },
        });

        this.map.addControl(drawControl);

        // Handle drawing events
        this.map.on('draw:created', function (event) {
          const layer = event.layer;
          drawnItems.addLayer(layer);

          if (event.layerType === 'polygon') {
            self.addLeafletPolygon(layer);
          } else if (event.layerType === 'circle') {
            self.addLeafletCircle(layer);
          }
        });
      }
    }

    /**
     * Add Google Maps polygon
     * @param polygon
     */
    addPolygon(polygon) {
      const coordinates = [];
      const path = polygon.getPath();

      for (let i = 0; i < path.getLength(); i++) {
        const point = path.getAt(i);
        coordinates.push({
          lat: point.lat(),
          lng: point.lng(),
        });
      }

      this.polygons.push({
        type: 'polygon',
        coordinates,
        googlePolygon: polygon,
      });

      this.updateGeographicFilters();
    }

    /**
     * Add Google Maps circle
     * @param circle
     */
    addCircle(circle) {
      const center = circle.getCenter();
      const radiusMiles = circle.getRadius() * 0.000621371; // Convert meters to miles

      this.circles.push({
        type: 'radius',
        center_lat: center.lat(),
        center_lng: center.lng(),
        radius_miles: radiusMiles,
        googleCircle: circle,
      });

      this.updateGeographicFilters();
    }

    /**
     * Add Leaflet polygon
     * @param layer
     */
    addLeafletPolygon(layer) {
      const coordinates = [];
      const latLngs = layer.getLatLngs()[0];

      latLngs.forEach(function (latLng) {
        coordinates.push({
          lat: latLng.lat,
          lng: latLng.lng,
        });
      });

      this.polygons.push({
        type: 'polygon',
        coordinates,
        leafletLayer: layer,
      });

      this.updateGeographicFilters();
    }

    /**
     * Add Leaflet circle
     * @param layer
     */
    addLeafletCircle(layer) {
      const center = layer.getLatLng();
      const radiusMiles = layer.getRadius() * 0.000621371; // Convert meters to miles

      this.circles.push({
        type: 'radius',
        center_lat: center.lat,
        center_lng: center.lng,
        radius_miles: radiusMiles,
        leafletLayer: layer,
      });

      this.updateGeographicFilters();
    }

    /**
     * Update geographic filters based on map drawings
     */
    updateGeographicFilters() {
      const geographicFilters = [];

      // Add polygons
      this.polygons.forEach(function (polygon) {
        geographicFilters.push(polygon);
      });

      // Add circles
      this.circles.forEach(function (circle) {
        geographicFilters.push(circle);
      });

      this.filters.geographic = geographicFilters;
      this.updateFilterSummary();
    }

    /**
     * Clear map drawings
     */
    clearMapDrawings() {
      // Clear Google Maps drawings
      if (this.drawingManager) {
        this.polygons.forEach(function (polygon) {
          if (polygon.googlePolygon) {
            polygon.googlePolygon.setMap(null);
          }
        });

        this.circles.forEach(function (circle) {
          if (circle.googleCircle) {
            circle.googleCircle.setMap(null);
          }
        });
      }

      // Clear Leaflet drawings
      if (this.map && typeof L !== 'undefined') {
        this.polygons.forEach(function (polygon) {
          if (polygon.leafletLayer) {
            polygon.leafletLayer.remove();
          }
        });

        this.circles.forEach(function (circle) {
          if (circle.leafletLayer) {
            circle.leafletLayer.remove();
          }
        });
      }

      this.polygons = [];
      this.circles = [];
      this.updateGeographicFilters();
    }

    /**
     * Load filter presets
     */
    loadFilterPresets() {
      const self = this;

      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_get_filter_presets',
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success) {
            self.filterPresets = response.data.presets;
            self.renderFilterPresets();
          }
        },
      });
    }

    /**
     * Render filter presets
     */
    renderFilterPresets() {
      const $presetSelect = $('.mld-filter-preset-select');
      if (!$presetSelect.length) return;

      $presetSelect.empty().append('<option value="">Select a preset...</option>');

      Object.keys(this.filterPresets).forEach(function (presetId) {
        const preset = this.filterPresets[presetId];
        $presetSelect.append(`<option value="${presetId}">${preset.name}</option>`);
      });
    }

    /**
     * Apply filter preset
     * @param presetId
     */
    applyFilterPreset(presetId) {
      const preset = this.filterPresets[presetId];
      if (!preset) return;

      // Clear current filters
      this.clearAllFilters();

      // Apply preset filters
      Object.keys(preset.filters).forEach(function (filterName) {
        const filterValue = preset.filters[filterName];
        this.setFilterValue(filterName, filterValue);
      });

      // Update UI
      this.updateFilterSummary();
      this.updateFilterCount();
    }

    /**
     * Set filter value
     * @param filterName
     * @param filterValue
     */
    setFilterValue(filterName, filterValue) {
      const $filterContainer = $(`.mld-filter-${filterName}`);

      if (typeof filterValue === 'object' && filterValue !== null) {
        // Handle range filters
        if (filterValue.min !== undefined) {
          $filterContainer.find('.mld-range-min').val(filterValue.min);
        }
        if (filterValue.max !== undefined) {
          $filterContainer.find('.mld-range-max').val(filterValue.max);
        }

        // Update range slider
        const slider = this.rangeSliders[`.mld-${filterName}-range`];
        if (slider && slider.noUiSlider) {
          slider.noUiSlider.set([filterValue.min, filterValue.max]);
        }
      } else if (Array.isArray(filterValue)) {
        // Handle multiselect filters
        filterValue.forEach(function (value) {
          $filterContainer.find(`input[value="${value}"]`).prop('checked', true);
        });
      } else {
        // Handle single value filters
        $filterContainer.find('input, select').val(filterValue);
      }

      this.filters[filterName] = filterValue;
    }

    /**
     * Setup autocomplete for location fields
     */
    setupAutocomplete() {
      const self = this;

      // Neighborhood autocomplete
      $('.mld-neighborhood-search').each(function () {
        const $input = $(this);

        $input.autocomplete({
          source(request, response) {
            self.searchNeighborhoods(request.term, response);
          },
          minLength: 2,
          select(event, ui) {
            self.addNeighborhoodFilter(ui.item.value);
          },
        });
      });

      // ZIP code autocomplete
      $('.mld-zipcode-search').each(function () {
        const $input = $(this);

        $input.autocomplete({
          source(request, response) {
            self.searchZipCodes(request.term, response);
          },
          minLength: 2,
          select(event, ui) {
            self.addZipCodeFilter(ui.item.value);
          },
        });
      });
    }

    /**
     * Search neighborhoods
     * @param query
     * @param callback
     */
    searchNeighborhoods(query, callback) {
      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_search_neighborhoods',
          search: query,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success && callback) {
            const items = response.data.neighborhoods.map(function (neighborhood) {
              return {
                label: neighborhood,
                value: neighborhood,
              };
            });
            callback(items);
          }
        },
      });
    }

    /**
     * Search ZIP codes
     * @param query
     * @param callback
     */
    searchZipCodes(query, callback) {
      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_get_zip_codes',
          search: query,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success && callback) {
            const items = response.data.zip_codes.map(function (zipCode) {
              return {
                label: zipCode,
                value: zipCode,
              };
            });
            callback(items);
          }
        },
      });
    }

    /**
     * Geocode address
     * @param address
     */
    geocodeAddress(address) {
      const self = this;

      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_geocode_address',
          address,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success) {
            const location = response.data;
            self.setCommuteDestination(location.lat, location.lng, location.formatted_address);
          }
        },
      });
    }

    /**
     * Set commute destination
     * @param lat
     * @param lng
     * @param address
     */
    setCommuteDestination(lat, lng, address) {
      $('.mld-commute-lat').val(lat);
      $('.mld-commute-lng').val(lng);
      $('.mld-commute-address-display').text(address);

      // Add marker to map if available
      this.addCommuteMarker(lat, lng, address);
    }

    /**
     * Add commute marker to map
     * @param lat
     * @param lng
     * @param address
     */
    addCommuteMarker(lat, lng, address) {
      if (!this.map) return;

      // Remove existing commute marker
      if (this.commuteMarker) {
        if (typeof google !== 'undefined' && this.commuteMarker.setMap) {
          this.commuteMarker.setMap(null);
        } else if (typeof L !== 'undefined' && this.commuteMarker.remove) {
          this.commuteMarker.remove();
        }
      }

      // Add new marker
      if (typeof google !== 'undefined' && google.maps) {
        this.commuteMarker = new google.maps.Marker({
          position: { lat, lng },
          map: this.map,
          title: address,
          icon: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png',
        });
      } else if (typeof L !== 'undefined') {
        this.commuteMarker = L.marker([lat, lng]).addTo(this.map).bindPopup(address);
      }
    }

    /**
     * Initialize tabs
     */
    initializeTabs() {
      $('.mld-filter-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();

        const $tab = $(this);
        const target = $tab.data('tab');

        // Update active tab
        $tab.addClass('nav-tab-active').siblings().removeClass('nav-tab-active');

        // Show/hide tab content
        $('.mld-filter-tab-content').removeClass('active');
        $(`.mld-filter-tab-content[data-tab="${target}"]`).addClass('active');
      });
    }

    /**
     * Add keyword field
     */
    addKeywordField() {
      const $container = $('.mld-keyword-exclusions');
      const $newField = $(`
                <div class="mld-keyword-item">
                    <input type="text" class="mld-keyword-input" placeholder="Enter keyword to exclude...">
                    <button type="button" class="mld-remove-keyword">&times;</button>
                </div>
            `);

      $container.append($newField);
      $newField.find('.mld-keyword-input').focus();
    }

    /**
     * Add address field
     */
    addAddressField() {
      const $container = $('.mld-address-exclusions');
      const $newField = $(`
                <div class="mld-address-item">
                    <input type="text" class="mld-address-input" placeholder="Enter address or street to exclude...">
                    <button type="button" class="mld-remove-address">&times;</button>
                </div>
            `);

      $container.append($newField);
      $newField.find('.mld-address-input').focus();
    }

    /**
     * Toggle filter section
     * @param section
     */
    toggleFilterSection(section) {
      const $section = $(`.mld-filter-section-${section}`);
      const $toggle = $(`.mld-filter-section-toggle[data-section="${section}"]`);

      if ($section.hasClass('collapsed')) {
        $section.removeClass('collapsed');
        $toggle.text('−');
      } else {
        $section.addClass('collapsed');
        $toggle.text('+');
      }
    }

    /**
     * Toggle advanced filters
     */
    toggleAdvancedFilters() {
      const $advanced = $('.mld-advanced-filters');
      const $toggle = $('.mld-toggle-advanced');

      if ($advanced.hasClass('hidden')) {
        $advanced.removeClass('hidden');
        $toggle.text('Hide Advanced Filters');
      } else {
        $advanced.addClass('hidden');
        $toggle.text('Show Advanced Filters');
      }
    }

    /**
     * Update filter count
     */
    updateFilterCount() {
      // This would make an AJAX call to get result count
      // For now, just show a placeholder
      $('.mld-filter-count').show().text('Updating...');

      // Debounce the actual count update
      clearTimeout(this.countUpdateTimer);
      this.countUpdateTimer = setTimeout(() => {
        this.getFilterCount();
      }, 500);
    }

    /**
     * Get filter count from server
     */
    getFilterCount() {
      const filters = this.collectFilters();

      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_get_filter_count',
          filters,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success) {
            $('.mld-filter-count').show().text(`${response.data.count} properties found`);
          }
        },
        error() {
          $('.mld-filter-count').hide();
        }
      });
    }

    /**
     * Collect all filter values
     */
    collectFilters() {
      const filters = {
        enhanced: {},
        geographic: this.filters.geographic || [],
        exclusions: {},
      };

      // Collect enhanced filters
      $('.mld-filter-input').each(function () {
        const $input = $(this);
        const name = $input.data('filter');
        const value = $input.val();

        if (value) {
          filters.enhanced[name] = value;
        }
      });

      // Collect range filters
      Object.keys(this.rangeSliders).forEach(function (selector) {
        const slider = this.rangeSliders[selector];
        if (slider && slider.noUiSlider) {
          const values = slider.noUiSlider.get();
          const filterName = selector.replace('.mld-', '').replace('-range', '');
          filters.enhanced[filterName] = {
            min: values[0],
            max: values[1],
          };
        }
      });

      // Collect exclusions
      const keywords = [];
      $('.mld-keyword-input').each(function () {
        const value = $(this).val().trim();
        if (value) {
          keywords.push(value);
        }
      });
      if (keywords.length) {
        filters.exclusions.keywords = keywords;
      }

      const addresses = [];
      $('.mld-address-input').each(function () {
        const value = $(this).val().trim();
        if (value) {
          addresses.push(value);
        }
      });
      if (addresses.length) {
        filters.exclusions.addresses = addresses;
      }

      return filters;
    }

    /**
     * Apply filters
     */
    applyFilters() {
      const filters = this.collectFilters();

      // Analytics: Track search execute (v6.38.0)
      document.dispatchEvent(new CustomEvent('mld:search_execute', {
        detail: {
          filters: filters,
          resultCount: 0, // Will be updated after search completes
          searchType: 'enhanced'
        }
      }));

      // Trigger custom event for other components
      $(document).trigger('mld:filters-applied', [filters]);

      // If this is part of a saved search, update it
      if (this.options.searchId) {
        this.saveSearchFilters(filters);
      }
    }

    /**
     * Clear all filters
     */
    clearAllFilters() {
      // Clear form inputs
      $('.mld-filter-form')[0].reset();

      // Reset range sliders
      Object.keys(this.rangeSliders).forEach(function (selector) {
        const slider = this.rangeSliders[selector];
        if (slider && slider.noUiSlider) {
          const range = slider.noUiSlider.options.range;
          slider.noUiSlider.set([range.min, range.max]);
        }
      });

      // Clear map drawings
      this.clearMapDrawings();

      // Clear city and neighborhood boundaries
      if (typeof MLD_CityBoundaries !== 'undefined') {
        MLD_CityBoundaries.clearBoundaries();
      }

      // Clear dynamic fields
      $('.mld-keyword-item, .mld-address-item').remove();

      // Reset filters object
      this.filters = {};

      // Update UI
      this.updateFilterSummary();
      this.updateFilterCount();
    }

    /**
     * Update filter summary
     */
    updateFilterSummary() {
      const $summary = $('.mld-filter-summary');
      if (!$summary.length) return;

      const filters = this.collectFilters();
      const summaryItems = [];

      // Add enhanced filter summaries
      Object.keys(filters.enhanced).forEach(function (filterName) {
        const value = filters.enhanced[filterName];
        if (typeof value === 'object' && value.min && value.max) {
          summaryItems.push(`${filterName}: ${value.min} - ${value.max}`);
        } else {
          summaryItems.push(`${filterName}: ${value}`);
        }
      });

      // Add geographic summaries
      if (filters.geographic && filters.geographic.length) {
        summaryItems.push(`Geographic filters: ${filters.geographic.length} areas`);
      }

      // Add exclusion summaries
      Object.keys(filters.exclusions).forEach(function (exclusionType) {
        const exclusions = filters.exclusions[exclusionType];
        if (Array.isArray(exclusions)) {
          summaryItems.push(`Exclude ${exclusionType}: ${exclusions.length} items`);
        }
      });

      if (summaryItems.length) {
        $summary.html(`<strong>Active Filters:</strong> ${summaryItems.join(', ')}`).show();
      } else {
        $summary.hide();
      }
    }

    /**
     * Load saved filters
     */
    loadSavedFilters() {
      if (!this.options.searchId) return;

      const self = this;

      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_get_saved_search_filters',
          search_id: this.options.searchId,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success && response.data.filters) {
            self.applySavedFilters(response.data.filters);
          }
        },
      });
    }

    /**
     * Apply saved filters
     * @param filters
     */
    applySavedFilters(filters) {
      // Apply enhanced filters
      if (filters.enhanced) {
        Object.keys(filters.enhanced).forEach(function (filterName) {
          this.setFilterValue(filterName, filters.enhanced[filterName]);
        });
      }

      // Apply geographic filters
      if (filters.geographic && filters.geographic.length) {
        this.filters.geographic = filters.geographic;
        // Note: Drawing these on the map would require more complex state restoration
      }

      // Apply exclusions
      if (filters.exclusions) {
        this.applyExclusionFilters(filters.exclusions);
      }

      this.updateFilterSummary();
      this.updateFilterCount();
    }

    /**
     * Apply exclusion filters
     * @param exclusions
     */
    applyExclusionFilters(exclusions) {
      // Apply keyword exclusions
      if (exclusions.keywords) {
        exclusions.keywords.forEach(function (keyword) {
          this.addKeywordField();
          $('.mld-keyword-input').last().val(keyword);
        });
      }

      // Apply address exclusions
      if (exclusions.addresses) {
        exclusions.addresses.forEach(function (address) {
          this.addAddressField();
          $('.mld-address-input').last().val(address);
        });
      }
    }

    /**
     * Save search filters
     * @param filters
     */
    saveSearchFilters(filters) {
      $.ajax({
        url: this.options.apiUrl,
        type: 'POST',
        data: {
          action: 'mld_save_search_filters',
          search_id: this.options.searchId,
          filters,
          nonce: this.options.nonce,
        },
        success(response) {
          if (response.success) {
            // Show success message
            $('.mld-filter-message').html(
              '<div class="notice notice-success"><p>Filters saved successfully!</p></div>'
            );
          }
        },
      });
    }

    /**
     * Show save preset modal
     */
    showSavePresetModal() {
      const filters = this.collectFilters();

      // Create modal HTML
      const modalHtml = `
                <div class="mld-modal-overlay">
                    <div class="mld-modal">
                        <div class="mld-modal-header">
                            <h3>Save Filter Preset</h3>
                            <button class="mld-modal-close">&times;</button>
                        </div>
                        <div class="mld-modal-body">
                            <form class="mld-save-preset-form">
                                <p>
                                    <label for="preset-name">Preset Name:</label>
                                    <input type="text" id="preset-name" name="preset_name" required>
                                </p>
                                <p>
                                    <label for="preset-description">Description (optional):</label>
                                    <textarea id="preset-description" name="preset_description" rows="3"></textarea>
                                </p>
                                <p>
                                    <label>
                                        <input type="checkbox" name="preset_public" value="1">
                                        Make this preset public
                                    </label>
                                </p>
                            </form>
                        </div>
                        <div class="mld-modal-footer">
                            <button type="button" class="button button-secondary mld-modal-cancel">Cancel</button>
                            <button type="button" class="button button-primary mld-save-preset-confirm">Save Preset</button>
                        </div>
                    </div>
                </div>
            `;

      $('body').append(modalHtml);

      // Bind modal events
      $('.mld-modal-close, .mld-modal-cancel').on('click', function () {
        $('.mld-modal-overlay').remove();
      });

      $('.mld-save-preset-confirm').on('click', function () {
        const formData = $('.mld-save-preset-form').serialize();
        // Save the preset with current filters
        // Implementation would go here
        $('.mld-modal-overlay').remove();
      });
    }
  }

  // Initialize when document is ready
  $(document).ready(function () {
    if ($('.mld-enhanced-filters').length) {
      new MLDEnhancedFilters();
    }
  });

  // Export for global access
  window.MLDEnhancedFilters = MLDEnhancedFilters;
})(jQuery);
