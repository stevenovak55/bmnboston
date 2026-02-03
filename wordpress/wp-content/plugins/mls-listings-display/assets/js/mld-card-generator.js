/**
 * MLD Card Generator - Admin Shortcode Generator
 *
 * Provides interactive shortcode generation for [mld_listing_cards].
 *
 * @package MLS_Listings_Display
 * @since 6.11.21
 */
(function($) {
    'use strict';

    const CardGenerator = {
        // Default values (same as PHP shortcode defaults)
        defaults: {
            status: ['Active'],
            property_type: '',
            home_type: [],          // Changed to array for multi-select
            structure_type: [],     // Changed to array for multi-select
            architectural_style: [], // Changed to array for multi-select
            city: '',
            postal_code: '',
            neighborhood: '',
            street_name: '',
            listing_id: '',
            price_min: '',
            price_max: '',
            beds: '',
            baths_min: '',
            sqft_min: '',
            sqft_max: '',
            lot_size_min: '',
            lot_size_max: '',
            year_built_min: '',
            year_built_max: '',
            garage_spaces_min: '',
            has_pool: '',
            has_fireplace: '',
            has_basement: '',
            pet_friendly: '',
            open_house_only: '',
            // New amenity filters for full parity with Half Map Search
            waterfront: '',
            view: '',
            spa: '',
            has_hoa: '',
            senior_community: '',
            horse_property: '',
            agent_ids: '',
            listing_agent_id: '',
            buyer_agent_id: '',
            per_page: '12',
            columns: '3',
            sort_by: 'newest',
            infinite_scroll: 'yes',
            show_count: 'yes',
            show_sort: 'yes'
        },

        // Current filter values
        filters: {},

        // Preview state
        previewEnabled: false,
        previewLoading: false,

        /**
         * Initialize the generator
         */
        init: function() {
            // Clone defaults to filters
            this.filters = JSON.parse(JSON.stringify(this.defaults));

            this.bindEvents();
            this.initAccordions();
            this.initMultiSelects();
            this.updateShortcode();
        },

        /**
         * Initialize multi-select dropdowns
         */
        initMultiSelects: function() {
            const self = this;

            // Toggle dropdown on selected area click
            $(document).on('click', '.mld-multiselect-selected', function(e) {
                e.stopPropagation();
                const $wrapper = $(this).closest('.mld-multiselect-wrapper');
                const $dropdown = $wrapper.find('.mld-multiselect-dropdown');

                // Close other dropdowns
                $('.mld-multiselect-dropdown').not($dropdown).slideUp(150);

                // Toggle this dropdown
                $dropdown.slideToggle(150);

                // Focus search input
                if ($dropdown.is(':visible')) {
                    $dropdown.find('.mld-multiselect-search').focus();
                }
            });

            // Close dropdowns when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.mld-multiselect-wrapper').length) {
                    $('.mld-multiselect-dropdown').slideUp(150);
                }
            });

            // Search filtering within dropdown
            $(document).on('input', '.mld-multiselect-search', function() {
                const searchTerm = $(this).val().toLowerCase();
                const $options = $(this).siblings('.mld-multiselect-options').find('.mld-multiselect-option');

                $options.each(function() {
                    const text = $(this).text().toLowerCase();
                    $(this).toggle(text.includes(searchTerm));
                });
            });

            // Checkbox change within multi-select
            $(document).on('change', '.mld-multiselect-option input[type="checkbox"]', function() {
                const $wrapper = $(this).closest('.mld-multiselect-wrapper');
                const filterKey = $wrapper.data('filter-key');

                // Get all checked values
                const checkedValues = [];
                $wrapper.find('.mld-multiselect-option input:checked').each(function() {
                    checkedValues.push($(this).val());
                });

                // Update filter
                self.filters[filterKey] = checkedValues;

                // Update selected display
                self.updateMultiSelectDisplay($wrapper, checkedValues);

                // Update badges and shortcode
                self.updateBadges();
                self.updateShortcode();

                // Update preview if enabled
                if (self.previewEnabled) {
                    self.loadPreview();
                }
            });
        },

        /**
         * Update the display of selected items in a multi-select
         */
        updateMultiSelectDisplay: function($wrapper, values) {
            const $selected = $wrapper.find('.mld-multiselect-selected');

            if (values.length === 0) {
                $selected.html('<span class="mld-multiselect-placeholder">Select options...</span>');
            } else if (values.length <= 3) {
                // Show individual tags
                const tags = values.map(function(val) {
                    return '<span class="mld-multiselect-tag">' + val + '</span>';
                }).join('');
                $selected.html(tags);
            } else {
                // Show count
                $selected.html('<span class="mld-multiselect-count">' + values.length + ' selected</span>');
            }
        },

        /**
         * Reset all multi-selects to default state
         */
        resetMultiSelects: function() {
            const self = this;
            $('.mld-multiselect-wrapper').each(function() {
                const $wrapper = $(this);
                const filterKey = $wrapper.data('filter-key');

                // Uncheck all checkboxes
                $wrapper.find('.mld-multiselect-option input').prop('checked', false);

                // Clear search
                $wrapper.find('.mld-multiselect-search').val('');
                $wrapper.find('.mld-multiselect-option').show();

                // Reset display
                self.updateMultiSelectDisplay($wrapper, []);

                // Reset filter
                self.filters[filterKey] = [];
            });
        },

        /**
         * Bind all event handlers
         */
        bindEvents: function() {
            const self = this;

            // Accordion toggle
            $(document).on('click', '.mld-accordion-header', function() {
                self.toggleAccordion($(this));
            });

            // Text/number inputs (exclude multi-select wrappers - they have their own handler)
            $(document).on('input change', '[data-filter-key]', function(e) {
                // Skip multi-select wrappers - they're handled by initMultiSelects()
                if ($(this).hasClass('mld-multiselect-wrapper')) {
                    return;
                }

                const key = $(this).data('filter-key');
                const value = self.getInputValue($(this));
                self.updateFilter(key, value);
            });

            // Status checkboxes (special handling for array)
            $(document).on('change', 'input[name="status[]"]', function() {
                const checked = [];
                $('input[name="status[]"]:checked').each(function() {
                    checked.push($(this).val());
                });
                self.updateFilter('status', checked);
            });

            // Chip buttons (beds)
            $(document).on('click', '.mld-chip', function() {
                const $group = $(this).closest('.mld-chip-group');
                const key = $group.data('filter-key');
                const value = $(this).data('value');

                // Toggle active state
                $group.find('.mld-chip').removeClass('active');
                $(this).addClass('active');

                // Update filter (add + for beds)
                self.updateFilter(key, value ? value + '+' : '');
            });

            // Generate shortcode button
            $('#mld-generate-shortcode').on('click', function() {
                self.updateShortcode();
                self.showNotice('Shortcode generated!', 'success');
            });

            // Copy to clipboard
            $('#mld-copy-shortcode').on('click', function() {
                self.copyToClipboard();
            });

            // Reset filters
            $('#mld-reset-filters').on('click', function() {
                self.resetFilters();
            });

            // Preview toggle
            $('#mld-preview-toggle').on('change', function() {
                self.togglePreview($(this).is(':checked'));
            });

            // Price input formatting
            $('.mld-price-input').on('blur', function() {
                self.formatPrice($(this));
            });
        },

        /**
         * Initialize accordion states
         */
        initAccordions: function() {
            // First section is open by default (handled in HTML)
            // Close all other content sections
            $('.mld-accordion-section').not('[data-section="basic"]').find('.mld-accordion-content').hide();
        },

        /**
         * Toggle accordion section
         */
        toggleAccordion: function($header) {
            const $section = $header.closest('.mld-accordion-section');
            const $content = $section.find('.mld-accordion-content');
            const isExpanded = $header.attr('aria-expanded') === 'true';

            // Toggle
            $header.attr('aria-expanded', !isExpanded);
            $content.slideToggle(200);

            // Rotate icon
            $header.find('.mld-accordion-icon').toggleClass('rotated');
        },

        /**
         * Get value from an input element
         */
        getInputValue: function($input) {
            if ($input.is(':checkbox')) {
                // For yes/no checkboxes, return 'yes' when checked, empty string when unchecked
                // Empty string matches the default and won't be included in shortcode
                return $input.is(':checked') ? 'yes' : '';
            }
            if ($input.is(':radio')) {
                return $input.is(':checked') ? $input.val() : null;
            }
            return $input.val().trim();
        },

        /**
         * Update a filter value
         */
        updateFilter: function(key, value) {
            if (value === null) return; // Skip for unchecked radios

            this.filters[key] = value;
            this.updateBadges();
            this.updateShortcode();

            // Update preview if enabled
            if (this.previewEnabled) {
                this.loadPreview();
            }
        },

        /**
         * Update filter count badges on accordion headers
         */
        updateBadges: function() {
            const self = this;
            const sectionFilters = {
                basic: ['status', 'property_type', 'price_min', 'price_max', 'beds', 'baths_min'],
                location: ['city', 'postal_code', 'neighborhood', 'street_name', 'listing_id'],
                agents: ['agent_ids', 'listing_agent_id', 'buyer_agent_id'],
                details: ['home_type', 'sqft_min', 'sqft_max', 'lot_size_min', 'lot_size_max', 'year_built_min', 'year_built_max', 'structure_type', 'architectural_style'],
                features: ['garage_spaces_min', 'has_pool', 'has_fireplace', 'has_basement', 'pet_friendly', 'open_house_only', 'waterfront', 'view', 'spa', 'has_hoa', 'senior_community', 'horse_property'],
                display: ['columns', 'per_page', 'sort_by', 'infinite_scroll', 'show_count', 'show_sort']
            };

            Object.keys(sectionFilters).forEach(function(section) {
                let count = 0;
                sectionFilters[section].forEach(function(key) {
                    const value = self.filters[key];
                    const defaultValue = self.defaults[key];

                    // Count if different from default and not empty
                    if (Array.isArray(value)) {
                        if (JSON.stringify(value) !== JSON.stringify(defaultValue)) {
                            count++;
                        }
                    } else if (value && value !== defaultValue) {
                        count++;
                    }
                });

                const $badge = $('[data-section="' + section + '"] .mld-filter-badge');
                if (count > 0) {
                    $badge.text(count).show();
                } else {
                    $badge.hide();
                }
            });
        },

        /**
         * Generate and display the shortcode
         */
        updateShortcode: function() {
            const self = this;
            const params = [];

            Object.keys(this.filters).forEach(function(key) {
                const value = self.filters[key];
                const defaultValue = self.defaults[key];

                // Skip if matches default or is empty
                if (Array.isArray(value)) {
                    if (JSON.stringify(value) === JSON.stringify(defaultValue)) {
                        return;
                    }
                    if (value.length === 0) {
                        return;
                    }
                } else {
                    if (value === defaultValue) {
                        return;
                    }
                    // Skip empty values (checkboxes return '' when unchecked)
                    if (value === '' || value === null || value === undefined) return;
                }

                // Convert string comparison for numeric defaults
                if (String(value) === String(defaultValue)) return;

                // Format the value
                let formattedValue;
                if (Array.isArray(value)) {
                    formattedValue = value.join(',');
                } else {
                    formattedValue = String(value).replace(/"/g, '\\"');
                }

                // Strip $ and commas from price values
                if (key === 'price_min' || key === 'price_max') {
                    formattedValue = formattedValue.replace(/[$,]/g, '');
                }

                if (formattedValue) {
                    params.push(key + '="' + formattedValue + '"');
                }
            });

            const shortcode = params.length > 0
                ? '[mld_listing_cards ' + params.join(' ') + ']'
                : '[mld_listing_cards]';

            $('#mld-generated-shortcode').text(shortcode);
        },

        /**
         * Copy shortcode to clipboard
         */
        copyToClipboard: function() {
            const self = this;
            const shortcode = $('#mld-generated-shortcode').text();

            // Modern Clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shortcode)
                    .then(function() {
                        self.showNotice('Shortcode copied to clipboard!', 'success');
                    })
                    .catch(function() {
                        self.fallbackCopy(shortcode);
                    });
            } else {
                this.fallbackCopy(shortcode);
            }
        },

        /**
         * Fallback copy method
         */
        fallbackCopy: function(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                this.showNotice('Shortcode copied!', 'success');
            } catch (e) {
                this.showNotice('Failed to copy. Please select and copy manually.', 'error');
            }

            document.body.removeChild(textarea);
        },

        /**
         * Reset all filters to defaults
         */
        resetFilters: function() {
            const self = this;

            // Reset filters object
            this.filters = JSON.parse(JSON.stringify(this.defaults));

            // Reset form inputs
            $('[data-filter-key]').each(function() {
                const $input = $(this);
                const key = $input.data('filter-key');
                const defaultVal = self.defaults[key];

                // Skip multi-select wrappers (handled separately)
                if ($input.hasClass('mld-multiselect-wrapper')) {
                    return;
                }

                if ($input.is(':checkbox')) {
                    if (key === 'status') {
                        // Status checkboxes handled separately
                    } else {
                        $input.prop('checked', defaultVal === 'yes');
                    }
                } else if ($input.is(':radio')) {
                    $input.prop('checked', $input.val() === defaultVal);
                } else if ($input.is('select')) {
                    $input.val(defaultVal);
                } else {
                    $input.val('');
                }
            });

            // Reset status checkboxes
            $('input[name="status[]"]').each(function() {
                $(this).prop('checked', $(this).val() === 'Active');
            });

            // Reset chip buttons
            $('.mld-chip').removeClass('active');
            $('.mld-chip-group').find('.mld-chip[data-value=""]').addClass('active');

            // Reset multi-select dropdowns
            this.resetMultiSelects();

            // Update UI
            this.updateBadges();
            this.updateShortcode();

            // Update preview if enabled
            if (this.previewEnabled) {
                this.loadPreview();
            }

            this.showNotice('Filters reset to defaults', 'info');
        },

        /**
         * Toggle preview visibility
         */
        togglePreview: function(enabled) {
            this.previewEnabled = enabled;
            const $container = $('#mld-preview-container');

            if (enabled) {
                $container.slideDown(200);
                this.loadPreview();
            } else {
                $container.slideUp(200);
            }
        },

        /**
         * Load preview via AJAX
         */
        loadPreview: function() {
            const self = this;

            if (this.previewLoading) return;
            this.previewLoading = true;

            const $cards = $('#mld-preview-cards');
            $cards.html('<div class="mld-preview-loading"><span class="spinner is-active"></span><p>Loading preview...</p></div>');

            // Build filters for preview (strip price formatting)
            const previewFilters = JSON.parse(JSON.stringify(this.filters));
            if (previewFilters.price_min) {
                previewFilters.price_min = previewFilters.price_min.replace(/[$,]/g, '');
            }
            if (previewFilters.price_max) {
                previewFilters.price_max = previewFilters.price_max.replace(/[$,]/g, '');
            }

            $.ajax({
                url: mldGeneratorData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_preview_listing_cards',
                    nonce: mldGeneratorData.nonce,
                    filters: JSON.stringify(previewFilters)
                },
                success: function(response) {
                    if (response.success) {
                        self.renderPreview(response.data);
                    } else {
                        $cards.html('<div class="mld-preview-error">Unable to load preview</div>');
                    }
                },
                error: function() {
                    $cards.html('<div class="mld-preview-error">Preview request failed</div>');
                },
                complete: function() {
                    self.previewLoading = false;
                }
            });
        },

        /**
         * Render preview cards
         */
        renderPreview: function(data) {
            const $cards = $('#mld-preview-cards');

            // Update counts
            $('#mld-preview-shown').text(data.listings.length);
            $('#mld-preview-total').text(data.total.toLocaleString());

            if (data.listings.length === 0) {
                $cards.html('<div class="mld-preview-empty">No properties match your criteria</div>');
                return;
            }

            // Build cards HTML
            let html = '';
            data.listings.forEach(function(listing) {
                const price = '$' + parseInt(listing.price).toLocaleString();
                const photoUrl = listing.photo_url || 'https://placehold.co/400x280/eee/ccc?text=No+Image';

                html += '<div class="mld-preview-card">';
                html += '<div class="mld-preview-image">';
                html += '<img src="' + photoUrl + '" alt="' + listing.address + '" loading="lazy">';
                html += '<span class="mld-preview-price">' + price + '</span>';
                html += '<span class="mld-preview-status mld-status-' + listing.status.toLowerCase().replace(' ', '-') + '">' + listing.status + '</span>';
                html += '</div>';
                html += '<div class="mld-preview-details">';
                html += '<div class="mld-preview-specs">';
                if (listing.beds) html += '<span>' + listing.beds + ' bd</span>';
                if (listing.baths) html += '<span>' + listing.baths + ' ba</span>';
                if (listing.sqft) html += '<span>' + parseInt(listing.sqft).toLocaleString() + ' sqft</span>';
                html += '</div>';
                html += '<div class="mld-preview-address">' + listing.address + '</div>';
                html += '<div class="mld-preview-city">' + listing.city + ', ' + listing.state + '</div>';
                html += '</div>';
                html += '</div>';
            });

            $cards.html(html);
        },

        /**
         * Format price input with $ and commas
         */
        formatPrice: function($input) {
            let value = $input.val().replace(/[^0-9]/g, '');
            if (value) {
                value = '$' + parseInt(value).toLocaleString();
                $input.val(value);
            }
        },

        /**
         * Show notification
         */
        showNotice: function(message, type) {
            const $notice = $('#mld-copy-notice');
            $notice.removeClass('success error info').addClass(type);
            $notice.text(message).fadeIn(200);

            setTimeout(function() {
                $notice.fadeOut(200);
            }, 3000);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on the card generator tab
        if ($('.mld-card-generator-wrap').length) {
            CardGenerator.init();
        }
    });

    // Expose globally for debugging
    window.MLDCardGenerator = CardGenerator;

})(jQuery);
