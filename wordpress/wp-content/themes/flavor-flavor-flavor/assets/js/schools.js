/**
 * Schools Browse Page JavaScript
 *
 * Handles filtering, sorting, and pagination for the school districts browse page
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

(function() {
    'use strict';

    /**
     * Schools filter controller
     */
    const SchoolsFilter = {
        /**
         * Current filter state
         */
        filters: {
            grade: '',
            city: '',
            min_score: 0,
            max_score: 100,
            sort: 'rank',
            page: 1
        },

        /**
         * Initialize the filter system
         */
        init: function() {
            this.parseUrlParams();
            this.bindEvents();
            this.updateUI();
        },

        /**
         * Parse URL parameters into filter state
         */
        parseUrlParams: function() {
            const params = new URLSearchParams(window.location.search);

            if (params.has('grade')) {
                this.filters.grade = params.get('grade');
            }
            if (params.has('city')) {
                this.filters.city = params.get('city');
            }
            if (params.has('min_score')) {
                this.filters.min_score = parseInt(params.get('min_score'), 10) || 0;
            }
            if (params.has('max_score')) {
                this.filters.max_score = parseInt(params.get('max_score'), 10) || 100;
            }
            if (params.has('sort')) {
                this.filters.sort = params.get('sort');
            }
            if (params.has('pg')) {
                this.filters.page = parseInt(params.get('pg'), 10) || 1;
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Grade filter buttons
            const gradeButtons = document.querySelectorAll('.bne-grade-btn');
            gradeButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const grade = btn.dataset.grade || '';
                    self.setGradeFilter(grade);

                    // Update hidden input
                    const hiddenInput = document.getElementById('grade-filter');
                    if (hiddenInput) {
                        hiddenInput.value = grade;
                    }

                    // Update active state
                    gradeButtons.forEach(b => b.classList.remove('bne-grade-btn--active'));
                    btn.classList.add('bne-grade-btn--active');
                });
            });

            // Sort dropdown
            const sortSelect = document.getElementById('sort-filter');
            if (sortSelect) {
                sortSelect.addEventListener('change', (e) => {
                    self.setSortFilter(e.target.value);
                });
            }

            // City search input - submit on enter
            const cityInput = document.getElementById('city-filter');
            if (cityInput) {
                cityInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        // Let form submit naturally
                    }
                });
            }

            // Form submission - use native form
            const form = document.querySelector('.bne-districts-filters__form');
            if (form) {
                // Form already has action and method, let it work naturally
            }

            // Pagination links - let them work naturally (they have correct hrefs)
        },

        /**
         * Set grade filter
         */
        setGradeFilter: function(grade) {
            // Toggle behavior - if same grade is clicked, clear it
            if (this.filters.grade === grade) {
                this.filters.grade = '';
            } else {
                this.filters.grade = grade;
            }
            this.filters.page = 1; // Reset to first page
            this.applyFilters();
        },

        /**
         * Set city filter
         */
        setCityFilter: function(city) {
            this.filters.city = city.trim();
            this.filters.page = 1;
            this.applyFilters();
        },

        /**
         * Set sort filter
         */
        setSortFilter: function(sort) {
            this.filters.sort = sort;
            this.filters.page = 1;
            this.applyFilters();
        },

        /**
         * Set page filter
         */
        setPageFilter: function(page) {
            this.filters.page = page;
            this.applyFilters();
        },

        /**
         * Clear all filters
         */
        clearFilters: function() {
            this.filters = {
                grade: '',
                city: '',
                min_score: 0,
                max_score: 100,
                sort: 'rank',
                page: 1
            };
            this.applyFilters();
        },

        /**
         * Apply filters by updating URL
         */
        applyFilters: function() {
            const params = new URLSearchParams();

            if (this.filters.grade) {
                params.set('grade', this.filters.grade);
            }
            if (this.filters.city) {
                params.set('city', this.filters.city);
            }
            if (this.filters.min_score > 0) {
                params.set('min_score', this.filters.min_score);
            }
            if (this.filters.max_score < 100) {
                params.set('max_score', this.filters.max_score);
            }
            if (this.filters.sort && this.filters.sort !== 'rank') {
                params.set('sort', this.filters.sort);
            }
            if (this.filters.page > 1) {
                params.set('pg', this.filters.page);
            }

            // Build new URL
            const baseUrl = window.location.pathname;
            const queryString = params.toString();
            const newUrl = queryString ? `${baseUrl}?${queryString}` : baseUrl;

            // Navigate to new URL
            window.location.href = newUrl;
        },

        /**
         * Update UI elements to reflect current filter state
         */
        updateUI: function() {
            // Update grade button active states
            const gradeButtons = document.querySelectorAll('.bne-grade-btn');
            gradeButtons.forEach(btn => {
                const grade = btn.dataset.grade || '';
                if (grade === this.filters.grade) {
                    btn.classList.add('bne-grade-btn--active');
                    btn.setAttribute('aria-pressed', 'true');
                } else {
                    btn.classList.remove('bne-grade-btn--active');
                    btn.setAttribute('aria-pressed', 'false');
                }
            });

            // Update hidden grade input
            const gradeInput = document.getElementById('grade-filter');
            if (gradeInput) {
                gradeInput.value = this.filters.grade;
            }

            // Update sort dropdown
            const sortSelect = document.getElementById('sort-filter');
            if (sortSelect) {
                sortSelect.value = this.filters.sort;
            }

            // Update city input
            const cityInput = document.getElementById('city-filter');
            if (cityInput && this.filters.city) {
                cityInput.value = this.filters.city;
            }
        }
    };

    /**
     * City/District Autocomplete
     */
    const CityAutocomplete = {
        input: null,
        dropdown: null,
        debounceTimer: null,
        selectedIndex: -1,
        suggestions: [],

        init: function() {
            this.input = document.getElementById('city-filter');
            if (!this.input) return;

            this.createDropdown();
            this.bindEvents();
        },

        createDropdown: function() {
            // Create dropdown container
            this.dropdown = document.createElement('div');
            this.dropdown.className = 'bne-autocomplete-dropdown';
            this.dropdown.style.display = 'none';

            // Insert after input
            this.input.parentNode.style.position = 'relative';
            this.input.parentNode.appendChild(this.dropdown);
        },

        bindEvents: function() {
            const self = this;

            // Input events
            this.input.addEventListener('input', function() {
                self.onInput();
            });

            this.input.addEventListener('keydown', function(e) {
                self.onKeyDown(e);
            });

            this.input.addEventListener('focus', function() {
                if (self.suggestions.length > 0) {
                    self.showDropdown();
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!self.input.contains(e.target) && !self.dropdown.contains(e.target)) {
                    self.hideDropdown();
                }
            });
        },

        onInput: function() {
            const self = this;
            const query = this.input.value.trim();

            // Clear previous timer
            if (this.debounceTimer) {
                clearTimeout(this.debounceTimer);
            }

            // Need at least 2 characters
            if (query.length < 2) {
                this.hideDropdown();
                return;
            }

            // Debounce API calls
            this.debounceTimer = setTimeout(function() {
                self.fetchSuggestions(query);
            }, 200);
        },

        onKeyDown: function(e) {
            if (this.suggestions.length === 0) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.selectedIndex = Math.min(this.selectedIndex + 1, this.suggestions.length - 1);
                    this.updateSelection();
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                    this.updateSelection();
                    break;

                case 'Enter':
                    if (this.selectedIndex >= 0) {
                        e.preventDefault();
                        this.selectSuggestion(this.selectedIndex);
                    }
                    break;

                case 'Escape':
                    this.hideDropdown();
                    break;
            }
        },

        fetchSuggestions: function(query) {
            const self = this;
            const ajaxUrl = (typeof bneSchoolsData !== 'undefined' && bneSchoolsData.ajaxUrl)
                ? bneSchoolsData.ajaxUrl
                : '/wp-admin/admin-ajax.php';

            fetch(ajaxUrl + '?action=bmn_district_autocomplete&term=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.length > 0) {
                        self.suggestions = data.data;
                        self.renderDropdown();
                        self.showDropdown();
                    } else {
                        self.suggestions = [];
                        self.hideDropdown();
                    }
                })
                .catch(err => {
                    console.error('Autocomplete error:', err);
                    self.hideDropdown();
                });
        },

        renderDropdown: function() {
            const self = this;
            this.dropdown.innerHTML = '';
            this.selectedIndex = -1;

            this.suggestions.forEach(function(item, index) {
                const div = document.createElement('div');
                div.className = 'bne-autocomplete-item';
                div.dataset.index = index;

                // Grade badge
                const gradeLetter = (item.letter_grade || 'N/A').charAt(0);
                const gradeClass = 'bne-grade--' + gradeLetter.toLowerCase();

                div.innerHTML = `
                    <span class="bne-autocomplete-grade ${gradeClass}">${item.letter_grade || 'N/A'}</span>
                    <span class="bne-autocomplete-label">${self.escapeHtml(item.label)}</span>
                `;

                div.addEventListener('click', function() {
                    self.selectSuggestion(index);
                });

                div.addEventListener('mouseenter', function() {
                    self.selectedIndex = index;
                    self.updateSelection();
                });

                self.dropdown.appendChild(div);
            });
        },

        updateSelection: function() {
            const items = this.dropdown.querySelectorAll('.bne-autocomplete-item');
            items.forEach((item, index) => {
                if (index === this.selectedIndex) {
                    item.classList.add('bne-autocomplete-item--selected');
                } else {
                    item.classList.remove('bne-autocomplete-item--selected');
                }
            });
        },

        selectSuggestion: function(index) {
            const item = this.suggestions[index];
            if (item) {
                this.input.value = item.value;
                this.hideDropdown();

                // Trigger form submit or filter update
                SchoolsFilter.setCityFilter(item.value);
            }
        },

        showDropdown: function() {
            this.dropdown.style.display = 'block';
        },

        hideDropdown: function() {
            this.dropdown.style.display = 'none';
            this.selectedIndex = -1;
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    /**
     * District card interactions
     */
    const DistrictCards = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Make entire card clickable
            const cards = document.querySelectorAll('.bne-district-card');
            cards.forEach(card => {
                const link = card.querySelector('.bne-district-card__link');
                if (link) {
                    card.addEventListener('click', (e) => {
                        // Don't trigger if clicking on an actual link
                        if (e.target.tagName === 'A') return;
                        link.click();
                    });
                    card.style.cursor = 'pointer';
                }
            });
        }
    };

    /**
     * Smooth scroll for anchor links
     */
    const SmoothScroll = {
        init: function() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
        }
    };

    /**
     * District boundary map controller
     */
    const DistrictMap = {
        map: null,
        boundaryPolygon: null,

        init: function() {
            const container = document.getElementById('district-boundary-map');
            if (!container) return;

            const geojsonData = container.dataset.geojson;
            if (!geojsonData) {
                container.innerHTML = '<p class="bne-district-map__error">District boundary data not available.</p>';
                return;
            }

            // Wait for Google Maps to fully load (check for Map constructor specifically)
            if (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.Map !== 'function') {
                // Try again in 500ms if Google Maps isn't ready
                setTimeout(() => this.init(), 500);
                return;
            }

            this.renderMap(container, geojsonData);
        },

        renderMap: function(container, geojsonString) {
            try {
                const geojson = JSON.parse(geojsonString);

                // Create map with default center
                const mapOptions = {
                    zoom: 11,
                    center: { lat: 42.3601, lng: -71.0589 }, // Boston default
                    mapTypeId: 'roadmap',
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    zoomControl: true,
                    styles: this.getMapStyles()
                };

                // Clear placeholder
                container.innerHTML = '';

                this.map = new google.maps.Map(container, mapOptions);

                // Parse GeoJSON and extract coordinates
                const coordinates = this.extractCoordinates(geojson);

                if (coordinates.length === 0) {
                    container.innerHTML = '<p class="bne-district-map__error">Unable to parse district boundary.</p>';
                    return;
                }

                // Create polygon
                this.boundaryPolygon = new google.maps.Polygon({
                    paths: coordinates,
                    strokeColor: '#2563eb',
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: '#3b82f6',
                    fillOpacity: 0.15
                });

                this.boundaryPolygon.setMap(this.map);

                // Fit map to polygon bounds
                this.fitToBounds(coordinates);

            } catch (e) {
                console.error('Error rendering district map:', e);
                container.innerHTML = '<p class="bne-district-map__error">Error loading district boundary map.</p>';
            }
        },

        extractCoordinates: function(geojson) {
            let coordinates = [];

            // Handle different GeoJSON structures
            if (geojson.type === 'FeatureCollection' && geojson.features) {
                // Get first feature's geometry
                if (geojson.features.length > 0) {
                    return this.extractCoordinates(geojson.features[0]);
                }
            } else if (geojson.type === 'Feature' && geojson.geometry) {
                return this.extractCoordinates(geojson.geometry);
            } else if (geojson.type === 'Polygon' && geojson.coordinates) {
                // Polygon - first array is outer ring
                coordinates = geojson.coordinates[0].map(coord => ({
                    lat: coord[1],
                    lng: coord[0]
                }));
            } else if (geojson.type === 'MultiPolygon' && geojson.coordinates) {
                // MultiPolygon - use first polygon's outer ring
                if (geojson.coordinates.length > 0 && geojson.coordinates[0].length > 0) {
                    coordinates = geojson.coordinates[0][0].map(coord => ({
                        lat: coord[1],
                        lng: coord[0]
                    }));
                }
            }

            return coordinates;
        },

        fitToBounds: function(coordinates) {
            const bounds = new google.maps.LatLngBounds();
            coordinates.forEach(coord => {
                bounds.extend(new google.maps.LatLng(coord.lat, coord.lng));
            });
            this.map.fitBounds(bounds, { padding: 20 });
        },

        getMapStyles: function() {
            // Subtle, clean map style
            return [
                {
                    featureType: 'poi',
                    elementType: 'labels',
                    stylers: [{ visibility: 'off' }]
                },
                {
                    featureType: 'transit',
                    elementType: 'labels',
                    stylers: [{ visibility: 'off' }]
                }
            ];
        }
    };

    /**
     * School location map controller
     */
    const SchoolMap = {
        map: null,
        marker: null,

        init: function() {
            const container = document.getElementById('school-location-map');
            if (!container) return;

            const lat = parseFloat(container.dataset.lat);
            const lng = parseFloat(container.dataset.lng);
            const name = container.dataset.name || 'School';

            if (isNaN(lat) || isNaN(lng)) {
                container.innerHTML = '<p class="bne-school-map__error">Location data not available.</p>';
                return;
            }

            // Wait for Google Maps to fully load (check for Map constructor specifically)
            if (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.Map !== 'function') {
                // Try again in 500ms if Google Maps isn't ready
                setTimeout(() => this.init(), 500);
                return;
            }

            this.renderMap(container, lat, lng, name);
        },

        renderMap: function(container, lat, lng, name) {
            try {
                const center = { lat: lat, lng: lng };

                const mapOptions = {
                    zoom: 15,
                    center: center,
                    mapTypeId: 'roadmap',
                    mapTypeControl: false,
                    streetViewControl: true,
                    fullscreenControl: true,
                    zoomControl: true,
                    styles: this.getMapStyles()
                };

                // Clear placeholder
                container.innerHTML = '';

                this.map = new google.maps.Map(container, mapOptions);

                // Add marker for school
                this.marker = new google.maps.Marker({
                    position: center,
                    map: this.map,
                    title: name,
                    animation: google.maps.Animation.DROP
                });

                // Add info window
                const infoWindow = new google.maps.InfoWindow({
                    content: `<div style="font-weight:600;padding:4px 8px;">${this.escapeHtml(name)}</div>`
                });

                this.marker.addListener('click', () => {
                    infoWindow.open(this.map, this.marker);
                });

            } catch (e) {
                console.error('Error rendering school map:', e);
                container.innerHTML = '<p class="bne-school-map__error">Error loading location map.</p>';
            }
        },

        getMapStyles: function() {
            return [
                {
                    featureType: 'poi',
                    elementType: 'labels',
                    stylers: [{ visibility: 'off' }]
                },
                {
                    featureType: 'transit',
                    elementType: 'labels',
                    stylers: [{ visibility: 'off' }]
                }
            ];
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    /**
     * Initialize on DOM ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize on schools browse page
        if (document.querySelector('.bne-schools-browse-page')) {
            SchoolsFilter.init();
            CityAutocomplete.init();
            DistrictCards.init();
        }

        // Initialize smooth scroll on all school pages
        if (document.querySelector('.bne-schools-browse-page, .bne-district-detail-page, .bne-school-detail-page')) {
            SmoothScroll.init();
        }

        // Initialize district map on district detail pages
        if (document.querySelector('.bne-district-detail-page')) {
            DistrictMap.init();
        }

        // Initialize school map on school detail pages
        if (document.querySelector('.bne-school-detail-page')) {
            SchoolMap.init();
        }
    });

    // Also try to init maps when Google Maps loads
    window.initDistrictMap = function() {
        if (document.querySelector('.bne-district-detail-page')) {
            DistrictMap.init();
        }
        if (document.querySelector('.bne-school-detail-page')) {
            SchoolMap.init();
        }
    };

    // Expose for debugging
    window.BNESchools = {
        SchoolsFilter: SchoolsFilter,
        CityAutocomplete: CityAutocomplete,
        DistrictCards: DistrictCards,
        DistrictMap: DistrictMap,
        SchoolMap: SchoolMap
    };

})();
