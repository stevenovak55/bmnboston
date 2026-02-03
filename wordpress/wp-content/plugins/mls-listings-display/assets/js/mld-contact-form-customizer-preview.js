/**
 * MLD Contact Form Customizer Preview
 *
 * Handles live preview updates in the WordPress Customizer.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

(function($) {
    'use strict';

    // Helper to update CSS variable
    function updateCSSVariable(variable, value) {
        document.documentElement.style.setProperty(variable, value);
    }

    // Color settings
    var colorSettings = [
        'bg_color',
        'text_color',
        'label_color',
        'border_color',
        'focus_color',
        'button_bg',
        'button_hover_bg',
        'button_text',
        'error_color',
        'success_color'
    ];

    colorSettings.forEach(function(setting) {
        wp.customize('mld_cf_' + setting, function(value) {
            value.bind(function(newval) {
                updateCSSVariable('--mld-cf-' + setting.replace(/_/g, '-'), newval);
            });
        });
    });

    // Pixel-based settings
    var pixelSettings = [
        'label_size',
        'input_size',
        'button_size',
        'form_padding',
        'field_gap',
        'input_padding_v',
        'input_padding_h',
        'button_radius',
        'form_radius'
    ];

    pixelSettings.forEach(function(setting) {
        wp.customize('mld_cf_' + setting, function(value) {
            value.bind(function(newval) {
                updateCSSVariable('--mld-cf-' + setting.replace(/_/g, '-'), newval + 'px');
            });
        });
    });

    // Non-pixel settings
    wp.customize('mld_cf_font_family', function(value) {
        value.bind(function(newval) {
            updateCSSVariable('--mld-cf-font-family', newval);
        });
    });

    wp.customize('mld_cf_label_weight', function(value) {
        value.bind(function(newval) {
            updateCSSVariable('--mld-cf-label-weight', newval);
        });
    });

    // Border toggle
    wp.customize('mld_cf_show_border', function(value) {
        value.bind(function(newval) {
            if (newval) {
                $('.mld-contact-form').css('border', '1px solid var(--mld-cf-border-color)');
            } else {
                $('.mld-contact-form').css('border', 'none');
            }
        });
    });

    // Shadow setting
    wp.customize('mld_cf_form_shadow', function(value) {
        value.bind(function(newval) {
            var shadows = {
                'none': 'none',
                'small': '0 1px 3px rgba(0, 0, 0, 0.1)',
                'medium': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
                'large': '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)'
            };
            $('.mld-contact-form').css('box-shadow', shadows[newval] || 'none');
        });
    });

})(jQuery);
