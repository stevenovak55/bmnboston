jQuery(document).ready(function($) {

    // Market Calculator Form
    $('#mld-market-calculator-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $results = $('#market-calculator-results');
        var $data = $('#market-calculator-data');
        var $button = $form.find('button[type="submit"]');

        $button.prop('disabled', true).text('Calculating...');
        $results.hide();

        $.ajax({
            url: mldCmaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_get_market_adjustments',
                nonce: mldCmaAdmin.nonce,
                city: $form.find('[name="city"]').val(),
                state: $form.find('[name="state"]').val(),
                property_type: $form.find('[name="property_type"]').val(),
                months: $form.find('[name="months"]').val()
            },
            success: function(response) {
                if (response.success) {
                    var html = '';
                    var adjustments = response.data.adjustments;

                    for (var key in adjustments) {
                        var value = adjustments[key];
                        var displayValue = '';

                        if (typeof value === 'number') {
                            displayValue = '$' + value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        } else {
                            displayValue = value || '(no data)';
                        }

                        var label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                        html += '<div class="mld-adjustment-item">';
                        html += '<span class="mld-adjustment-label">' + label + ':</span>';
                        html += '<span class="mld-adjustment-value">' + displayValue + '</span>';
                        html += '</div>';
                    }

                    $data.html(html);
                    $results.show().removeClass('error').addClass('success');
                } else {
                    $data.html('<p class="error">' + response.data + '</p>');
                    $results.show().removeClass('success').addClass('error');
                }
            },
            error: function() {
                $data.html('<p class="error">An error occurred. Please try again.</p>');
                $results.show().removeClass('success').addClass('error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Calculate Market Data');
            }
        });
    });

    // Adjustment Overrides Form
    $('#mld-adjustment-overrides-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $message = $('#overrides-save-message');
        var $button = $form.find('button[type="submit"]');

        $button.prop('disabled', true).text('Saving...');
        $message.hide();

        $.ajax({
            url: mldCmaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_save_adjustment_overrides',
                nonce: mldCmaAdmin.nonce,
                price_per_sqft: $form.find('[name="price_per_sqft"]').val(),
                garage_first: $form.find('[name="garage_first"]').val(),
                garage_additional: $form.find('[name="garage_additional"]').val(),
                pool: $form.find('[name="pool"]').val(),
                bedroom: $form.find('[name="bedroom"]').val(),
                bathroom: $form.find('[name="bathroom"]').val(),
                waterfront: $form.find('[name="waterfront"]').val(),
                year_built_rate: $form.find('[name="year_built_rate"]').val(),
                location_rate: $form.find('[name="location_rate"]').val(),
                road_type_discount: $form.find('[name="road_type_discount"]').val()
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<p><strong>✓ ' + response.data.message + '</strong></p>');
                    $message.show().removeClass('error').addClass('success');
                } else {
                    $message.html('<p><strong>✗ Error:</strong> ' + response.data + '</p>');
                    $message.show().removeClass('success').addClass('error');
                }
            },
            error: function() {
                $message.html('<p><strong>✗ Error:</strong> An error occurred. Please try again.</p>');
                $message.show().removeClass('success').addClass('error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Save Overrides');
            }
        });
    });

    // Reset Overrides Button
    $('#reset-overrides-btn').on('click', function() {
        if (!confirm('Reset all overrides to auto-calculated values?')) {
            return;
        }

        var $button = $(this);
        var $message = $('#overrides-save-message');

        $button.prop('disabled', true).text('Resetting...');
        $message.hide();

        $.ajax({
            url: mldCmaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_reset_adjustment_overrides',
                nonce: mldCmaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Clear all form fields
                    $('#mld-adjustment-overrides-form').find('input[type="number"]').each(function() {
                        if ($(this).attr('name') === 'road_type_discount') {
                            $(this).val('25');
                        } else {
                            $(this).val('');
                        }
                    });

                    $message.html('<p><strong>✓ ' + response.data.message + '</strong></p>');
                    $message.show().removeClass('error').addClass('success');
                } else {
                    $message.html('<p><strong>✗ Error:</strong> ' + response.data + '</p>');
                    $message.show().removeClass('success').addClass('error');
                }
            },
            error: function() {
                $message.html('<p><strong>✗ Error:</strong> An error occurred. Please try again.</p>');
                $message.show().removeClass('success').addClass('error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Reset to Auto-Calculated');
            }
        });
    });

    // Test Email Form
    $('#mld-test-email-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $results = $('#test-email-results');
        var $button = $form.find('button[type="submit"]');

        $button.prop('disabled', true).text('Sending...');
        $results.hide();

        $.ajax({
            url: mldCmaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'mld_test_cma_email',
                nonce: mldCmaAdmin.nonce,
                email: $form.find('[name="email"]').val(),
                listing_id: $form.find('[name="listing_id"]').val()
            },
            success: function(response) {
                if (response.success) {
                    $results.html('<p><strong>✓ Email sent successfully!</strong></p><p>' + response.data.message + '</p>');
                    $results.show().removeClass('error').addClass('success');
                } else {
                    // Handle both string and object error responses
                    var errorMsg = typeof response.data === 'object' && response.data.message
                        ? response.data.message
                        : (typeof response.data === 'string' ? response.data : 'Unknown error');
                    $results.html('<p><strong>✗ Email failed:</strong> ' + errorMsg + '</p>');
                    $results.show().removeClass('success').addClass('error');
                }
            },
            error: function(xhr, status, error) {
                $results.html('<p><strong>✗ Error ' + xhr.status + ':</strong> ' + xhr.statusText + '</p><pre>' + xhr.responseText.substring(0, 500) + '</pre>');
                $results.show().removeClass('success').addClass('error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Send Test Email');
            }
        });
    });

});
