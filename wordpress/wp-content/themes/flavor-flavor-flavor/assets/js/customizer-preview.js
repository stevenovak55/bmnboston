/**
 * Hero Section Customizer Live Preview
 *
 * Handles instant preview updates for hero section customizer settings.
 * Uses the WordPress Customizer API with postMessage transport.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 * @since 1.3.9
 */

(function($) {
    'use strict';

    /**
     * Helper function to update a CSS custom property
     *
     * @param {string} property - CSS variable name (without --)
     * @param {string|number} value - New value
     * @param {string} unit - Optional unit to append (px, vh, em, %)
     */
    function updateCssVariable(property, value, unit) {
        var fullValue = unit ? value + unit : value;
        document.documentElement.style.setProperty('--' + property, fullValue);
    }

    // ==========================================================================
    // Hero Section Layout Settings
    // ==========================================================================

    wp.customize('bne_hero_min_height', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-min-height', newval, 'vh');
        });
    });

    wp.customize('bne_hero_padding_top', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-padding-top', newval, 'px');
        });
    });

    wp.customize('bne_hero_padding_bottom', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-padding-bottom', newval, 'px');
        });
    });

    wp.customize('bne_hero_margin_top', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-margin-top', newval, 'px');
        });
    });

    wp.customize('bne_hero_margin_bottom', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-margin-bottom', newval, 'px');
        });
    });

    // ==========================================================================
    // Hero Image Settings
    // ==========================================================================

    wp.customize('bne_hero_image_height_desktop', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-height-desktop', newval, 'vh');
        });
    });

    wp.customize('bne_hero_image_height_mobile', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-height-mobile', newval, 'vh');
        });
    });

    wp.customize('bne_hero_image_max_width', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-max-width', newval, '%');
        });
    });

    wp.customize('bne_hero_image_object_fit', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-object-fit', newval, '');
        });
    });

    wp.customize('bne_hero_image_padding', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-padding', newval, 'px');
        });
    });

    wp.customize('bne_hero_image_margin', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-margin', newval, 'px');
        });
    });

    wp.customize('bne_hero_image_offset', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-image-offset', newval, 'px');
        });
    });

    // ==========================================================================
    // Hero Typography Settings
    // ==========================================================================

    wp.customize('bne_hero_name_font_size', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-name-font-size', newval, 'px');
        });
    });

    wp.customize('bne_hero_name_font_weight', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-name-font-weight', newval, '');
        });
    });

    wp.customize('bne_hero_name_letter_spacing', function(value) {
        value.bind(function(newval) {
            // Convert 0-20 to 0em-0.2em
            var emValue = (newval / 100).toFixed(2);
            updateCssVariable('bne-hero-name-letter-spacing', emValue, 'em');
        });
    });

    wp.customize('bne_hero_title_font_size', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-title-font-size', newval, 'px');
        });
    });

    wp.customize('bne_hero_license_font_size', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-license-font-size', newval, 'px');
        });
    });

    wp.customize('bne_hero_contact_font_size', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-contact-font-size', newval, 'px');
        });
    });

    // ==========================================================================
    // Hero Content Area Settings
    // ==========================================================================

    wp.customize('bne_hero_content_max_width', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-content-max-width', newval, 'px');
        });
    });

    wp.customize('bne_hero_content_padding', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-content-padding', newval, 'px');
        });
    });

    // ==========================================================================
    // Hero Search Form Settings
    // ==========================================================================

    wp.customize('bne_hero_search_max_width', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-search-max-width', newval, '%');
        });
    });

    wp.customize('bne_hero_search_padding', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-search-padding', newval, 'px');
        });
    });

    wp.customize('bne_hero_search_border_radius', function(value) {
        value.bind(function(newval) {
            updateCssVariable('bne-hero-search-border-radius', newval, 'px');
        });
    });

})(jQuery);
