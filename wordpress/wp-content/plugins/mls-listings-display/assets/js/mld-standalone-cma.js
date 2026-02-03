/**
 * MLD Standalone CMA JavaScript
 *
 * Handles form submission, Google Places Autocomplete, and UI interactions
 * for the standalone CMA feature.
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.17.0
 */

(function($) {
    'use strict';

    /**
     * Standalone CMA Handler
     */
    var StandaloneCMA = {
        /**
         * Configuration from wp_localize_script
         */
        config: window.mldStandaloneCMA || {},

        /**
         * Google Places Autocomplete instance
         */
        autocomplete: null,

        /**
         * Track if address has been validated
         */
        addressValidated: false,

        /**
         * Track if form is being submitted (prevent double submission)
         */
        isSubmitting: false,

        /**
         * Initialize the handler
         */
        init: function() {
            var self = this;

            this.bindEvents();
            this.updateSubmitButton();

            // Listen for Google Maps ready event
            document.addEventListener('googleMapsReady', function() {
                self.initGooglePlacesAutocomplete();
            });

            // Also try immediately in case Google Maps was already loaded
            // (e.g., by another script on the page)
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                this.initGooglePlacesAutocomplete();
            } else {
                // Give it a moment to load, then start polling
                setTimeout(function() {
                    self.initGooglePlacesAutocomplete();
                }, 1000);
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Form submission
            $('#mld-standalone-cma-form').on('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.handleFormSubmit();
                return false; // Extra protection against native form submission
            });

            // Input changes - update submit button state
            $('#mld-standalone-cma-form input, #mld-standalone-cma-form select').on('change input', function() {
                self.updateSubmitButton();
            });

            // Manual entry toggle
            $('#scma-show-manual').on('click', function(e) {
                e.preventDefault();
                $('#scma-manual-entry').slideDown();
            });

            // Manual geocode button
            $('#scma-geocode-btn').on('click', function() {
                self.geocodeManualAddress();
            });

            // Edit form submission (view page)
            $('#mld-scma-edit-form').on('submit', function(e) {
                e.preventDefault();
                self.handleEditSubmit();
            });

            // Claim CMA button
            $('#scma-claim-btn').on('click', function() {
                self.handleClaimCMA($(this).data('session-id'));
            });
        },

        /**
         * Initialize Google Places Autocomplete
         */
        initGooglePlacesAutocomplete: function() {
            var self = this;
            var input = document.getElementById('scma-address-autocomplete');

            if (!input) {
                return;
            }

            // Check if Google Maps API key is available
            if (!this.config.googleMapsKey) {
                this.showAddressStatus('error', 'Google Maps API key not configured. Please check plugin settings.');
                $('#scma-manual-entry').show();
                return;
            }

            // Check if Google Maps API is loaded
            if (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.places === 'undefined') {
                // Try again in 500ms, max 20 attempts (10 seconds)
                if (!this.autocompleteAttempts) {
                    this.autocompleteAttempts = 0;
                }
                this.autocompleteAttempts++;

                if (this.autocompleteAttempts < 20) {
                    setTimeout(function() {
                        self.initGooglePlacesAutocomplete();
                    }, 500);
                } else {
                    console.error('Google Places API failed to load after 10 seconds');
                    this.showAddressStatus('error', 'Could not load Google Maps. You can enter the address manually below.');
                    $('#scma-manual-entry').show();
                }
                return;
            }

            // Create autocomplete instance
            this.autocomplete = new google.maps.places.Autocomplete(input, {
                types: ['address'],
                componentRestrictions: { country: 'us' },
                fields: ['address_components', 'geometry', 'formatted_address']
            });

            // Listen for place selection
            this.autocomplete.addListener('place_changed', function() {
                self.handlePlaceSelect();
            });

            // Prevent form submission on Enter in autocomplete field
            $(input).on('keydown', function(e) {
                if (e.keyCode === 13) {
                    e.preventDefault();
                }
            });
        },

        /**
         * Handle place selection from autocomplete
         */
        handlePlaceSelect: function() {
            var place = this.autocomplete.getPlace();

            if (!place.geometry) {
                this.showAddressStatus('error', 'Could not find coordinates for this address. Please try again or enter manually.');
                $('#scma-manual-entry').slideDown();
                this.addressValidated = false;
                this.updateSubmitButton();
                return;
            }

            // Extract address components
            var addressData = this.parseAddressComponents(place);

            // Use formatted_address as fallback if street_address is empty
            var streetAddress = addressData.street_address;
            if (!streetAddress && place.formatted_address) {
                // Extract just the street part from formatted address (before first comma)
                streetAddress = place.formatted_address.split(',')[0].trim();
            }
            if (!streetAddress) {
                // Last resort - use the input value
                streetAddress = $('#scma-address-autocomplete').val().split(',')[0].trim();
            }

            // Update hidden fields
            $('#scma-address').val(streetAddress);
            $('#scma-lat').val(place.geometry.location.lat());
            $('#scma-lng').val(place.geometry.location.lng());
            $('#scma-city').val(addressData.city);
            $('#scma-state').val(addressData.state);
            $('#scma-postal-code').val(addressData.postal_code);

            // Show success status
            var displayAddress = streetAddress + (addressData.city ? ', ' + addressData.city : '') + (addressData.state ? ', ' + addressData.state : '');
            this.showAddressStatus('success', 'Address verified: ' + displayAddress);

            // Hide manual entry if visible
            $('#scma-manual-entry').slideUp();

            this.addressValidated = true;
            this.updateSubmitButton();
        },

        /**
         * Parse address components from Google Places result
         */
        parseAddressComponents: function(place) {
            var result = {
                street_number: '',
                route: '',
                street_address: '',
                city: '',
                state: '',
                postal_code: ''
            };

            if (place.address_components) {
                place.address_components.forEach(function(component) {
                    var types = component.types;

                    if (types.includes('street_number')) {
                        result.street_number = component.long_name;
                    }
                    if (types.includes('route')) {
                        result.route = component.short_name;
                    }
                    if (types.includes('locality')) {
                        result.city = component.long_name;
                    }
                    if (types.includes('administrative_area_level_1')) {
                        result.state = component.short_name;
                    }
                    if (types.includes('postal_code')) {
                        result.postal_code = component.long_name;
                    }
                });
            }

            // Combine street number and route
            result.street_address = result.street_number + ' ' + result.route;
            result.street_address = result.street_address.trim();

            return result;
        },

        /**
         * Geocode manually entered address
         */
        geocodeManualAddress: function() {
            var self = this;
            var address = $('#scma-manual-address').val();
            var city = $('#scma-manual-city').val();
            var state = $('#scma-manual-state').val();
            var zip = $('#scma-manual-zip').val();

            if (!address || !city) {
                this.showAddressStatus('error', 'Please enter street address and city.');
                return;
            }

            var fullAddress = address + ', ' + city + ', ' + state;
            if (zip) {
                fullAddress += ' ' + zip;
            }

            // Show loading
            $('#scma-geocode-btn').prop('disabled', true).text('Verifying...');

            // Use Google Geocoding API
            if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
                var geocoder = new google.maps.Geocoder();

                geocoder.geocode({ address: fullAddress }, function(results, status) {
                    $('#scma-geocode-btn').prop('disabled', false).text('Verify Address & Get Coordinates');

                    if (status === 'OK' && results[0]) {
                        var location = results[0].geometry.location;

                        // Update hidden fields
                        $('#scma-address').val(address);
                        $('#scma-lat').val(location.lat());
                        $('#scma-lng').val(location.lng());
                        $('#scma-city').val(city);
                        $('#scma-state').val(state);
                        $('#scma-postal-code').val(zip);

                        // Also update the autocomplete field
                        $('#scma-address-autocomplete').val(fullAddress);

                        self.showAddressStatus('success', 'Address verified successfully!');
                        self.addressValidated = true;
                        self.updateSubmitButton();
                    } else {
                        self.showAddressStatus('error', 'Could not verify this address. Please check and try again.');
                        self.addressValidated = false;
                        self.updateSubmitButton();
                    }
                });
            } else {
                $('#scma-geocode-btn').prop('disabled', false).text('Verify Address & Get Coordinates');
                this.showAddressStatus('error', 'Google Maps API not available. Please try again later.');
            }
        },

        /**
         * Show address verification status
         */
        showAddressStatus: function(type, message) {
            var $status = $('#scma-address-status');
            $status.removeClass('status-success status-error')
                   .addClass('status-' + type)
                   .show();
            $status.find('.status-icon').html(type === 'success' ? '&#10004;' : '&#10008;');
            $status.find('.status-text').text(message);
        },

        /**
         * Update submit button state
         */
        updateSubmitButton: function() {
            var isValid = this.validateForm();
            $('#scma-submit-btn').prop('disabled', !isValid);
        },

        /**
         * Validate the form
         */
        validateForm: function() {
            // Check address is validated
            if (!this.addressValidated) {
                return false;
            }

            // Check required fields
            var beds = parseInt($('#scma-beds').val()) || 0;
            var baths = parseFloat($('#scma-baths').val()) || 0;
            var sqft = parseInt($('#scma-sqft').val()) || 0;
            var price = parseFloat($('#scma-price').val()) || 0;

            if (beds <= 0 || baths <= 0 || sqft <= 0 || price <= 0) {
                return false;
            }

            return true;
        },

        /**
         * Handle form submission
         */
        handleFormSubmit: function() {
            var self = this;

            // Prevent double submission
            if (this.isSubmitting) {
                return;
            }

            if (!this.validateForm()) {
                this.showFormError('Please fill in all required fields and verify the address.');
                return;
            }

            // Set submission lock
            this.isSubmitting = true;

            // Collect form data
            var formData = {
                action: 'mld_create_standalone_cma',
                nonce: this.config.nonce,
                address: $('#scma-address').val(),
                city: $('#scma-city').val(),
                state: $('#scma-state').val(),
                postal_code: $('#scma-postal-code').val(),
                lat: $('#scma-lat').val(),
                lng: $('#scma-lng').val(),
                beds: $('#scma-beds').val(),
                baths: $('#scma-baths').val(),
                sqft: $('#scma-sqft').val(),
                price: $('#scma-price').val(),
                property_type: $('#scma-property-type').val(),
                year_built: $('#scma-year-built').val(),
                garage_spaces: $('#scma-garage').val(),
                pool: $('#scma-pool').is(':checked') ? 1 : 0,
                waterfront: $('#scma-waterfront').is(':checked') ? 1 : 0,
                road_type: $('#scma-road-type').val(),
                property_condition: $('#scma-condition').val(),
                session_name: $('#scma-session-name').val(),
                description: $('#scma-description').val()
            };

            // Show loading state
            var $btn = $('#scma-submit-btn');
            $btn.prop('disabled', true);
            $btn.find('.btn-text').hide();
            $btn.find('.btn-loading').show();

            // Clear previous errors
            this.hideFormError();

            // Submit via AJAX
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // Redirect to the new CMA page
                        if (response.data.redirect_url) {
                            window.location.href = response.data.redirect_url;
                            return; // Ensure nothing else executes
                        } else {
                            console.error('No redirect_url in response!');
                            self.showFormError('CMA created but redirect URL missing.');
                            self.isSubmitting = false; // Reset submission lock
                            $btn.prop('disabled', false);
                            $btn.find('.btn-text').show();
                            $btn.find('.btn-loading').hide();
                        }
                    } else {
                        self.showFormError(response.data.message || 'Failed to create CMA. Please try again.');
                        self.isSubmitting = false; // Reset submission lock
                        $btn.prop('disabled', false);
                        $btn.find('.btn-text').show();
                        $btn.find('.btn-loading').hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    console.error('XHR:', xhr.responseText);
                    self.showFormError('An error occurred. Please try again.');
                    self.isSubmitting = false; // Reset submission lock
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').show();
                    $btn.find('.btn-loading').hide();
                }
            });
        },

        /**
         * Show form error
         */
        showFormError: function(message) {
            $('#scma-form-error').text(message).show();
        },

        /**
         * Hide form error
         */
        hideFormError: function() {
            $('#scma-form-error').hide();
        },

        /**
         * Handle edit form submission (view page)
         */
        handleEditSubmit: function() {
            var self = this;
            var $form = $('#mld-scma-edit-form');
            var sessionId = $form.find('input[name="session_id"]').val();

            var formData = {
                action: 'mld_update_standalone_cma',
                nonce: this.config.nonce,
                session_id: sessionId,
                session_name: $form.find('#edit-session-name').val(),
                description: $form.find('#edit-description').val()
            };

            var $btn = $form.find('button[type="submit"]');
            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // Reload page to show updated data
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Failed to update CMA.');
                        $btn.prop('disabled', false).text('Save Changes');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text('Save Changes');
                }
            });
        },

        /**
         * Handle CMA claim
         */
        handleClaimCMA: function(sessionId) {
            var self = this;
            var $btn = $('#scma-claim-btn');

            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mld_claim_standalone_cma',
                    nonce: this.config.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to update UI
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Failed to save CMA to your account.');
                        $btn.prop('disabled', false).text('Save to My CMAs');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text('Save to My CMAs');
                }
            });
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Only initialize if we're on a standalone CMA page
        if ($('.mld-standalone-cma-wrapper').length > 0) {
            StandaloneCMA.init();
        }
    });

})(jQuery);
