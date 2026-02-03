<?php
/**
 * flavor flavor flavor Theme Functions
 *
 * @package flavor_flavor_flavor
 * @version 1.3.9
 */

if (!defined('ABSPATH')) {
    exit;
}

// Theme version constant
define('BNE_THEME_VERSION', '1.5.10');
define('BNE_THEME_DIR', get_stylesheet_directory());
define('BNE_THEME_URI', get_stylesheet_directory_uri());

/**
 * Autoload theme classes from inc/ directory
 */
function bne_autoload_classes() {
    $classes = array(
        'class-theme-upgrader.php',
        'class-theme-setup.php',
        'class-custom-post-types.php',
        'class-mls-helpers.php',
        'class-section-manager.php',
        'class-section-manager-admin.php',
        'class-section-order-control.php',
        'class-bne-lead-tools.php',
        'class-bne-landing-page-seo.php',
        'class-bmn-schools-helpers.php',
    );

    foreach ($classes as $class_file) {
        $file_path = BNE_THEME_DIR . '/inc/' . $class_file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}
add_action('after_setup_theme', 'bne_autoload_classes', 1);

/**
 * Initialize theme upgrader early
 * Must run before other theme initialization
 */
function bne_init_upgrader() {
    if (class_exists('BNE_Theme_Upgrader')) {
        BNE_Theme_Upgrader::init();
    }
}
add_action('after_setup_theme', 'bne_init_upgrader', 2);

/**
 * Initialize theme classes
 */
function bne_init_theme() {
    // Initialize Theme Setup
    if (class_exists('BNE_Theme_Setup')) {
        BNE_Theme_Setup::init();
    }

    // Initialize Custom Post Types
    if (class_exists('BNE_Custom_Post_Types')) {
        BNE_Custom_Post_Types::init();
    }

    // Initialize Section Manager
    if (class_exists('BNE_Section_Manager')) {
        BNE_Section_Manager::init();
    }

    // Initialize Section Manager Admin
    if (class_exists('BNE_Section_Manager_Admin')) {
        BNE_Section_Manager_Admin::init();
    }

    // Initialize Lead Tools
    if (class_exists('BNE_Lead_Tools')) {
        BNE_Lead_Tools::init();
    }

    // Initialize Landing Page SEO
    if (class_exists('BNE_Landing_Page_SEO')) {
        BNE_Landing_Page_SEO::init();
    }
}
add_action('after_setup_theme', 'bne_init_theme', 10);

/**
 * Hide WordPress admin bar for all users
 *
 * Hides the admin bar for everyone including administrators.
 * Admins can access the dashboard via the profile dropdown menu.
 *
 * @since 1.5.3
 */
function bne_hide_admin_bar_for_all() {
    show_admin_bar(false);
}
add_action('after_setup_theme', 'bne_hide_admin_bar_for_all');

/**
 * Customize WordPress Login Page
 *
 * Replaces the default WordPress logo with the site logo,
 * updates the logo link to point to the homepage, and
 * applies custom styling to match the site design.
 *
 * @since 1.5.2
 */
function bne_custom_login_styles() {
    $custom_logo_id = get_theme_mod('custom_logo');
    $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'medium') : '';

    // Fallback if no custom logo is set
    if (empty($logo_url)) {
        $logo_url = get_stylesheet_directory_uri() . '/assets/images/logo.png';
    }
    ?>
    <style type="text/css">
        body.login {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }

        #login h1 a {
            background-image: url('<?php echo esc_url($logo_url); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            width: 100%;
            max-width: 320px;
            height: 80px;
            margin-bottom: 20px;
            filter: brightness(0) invert(1);
        }

        .login form {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .login form .input,
        .login form input[type="text"],
        .login form input[type="password"] {
            border-radius: 6px;
            border: 1px solid #ddd;
            padding: 10px 12px;
        }

        .login form .input:focus,
        .login form input[type="text"]:focus,
        .login form input[type="password"]:focus {
            border-color: #4a60a1;
            box-shadow: 0 0 0 2px rgba(74, 96, 161, 0.2);
        }

        .wp-core-ui .button-primary {
            background: linear-gradient(135deg, #4a60a1 0%, #3a4d8a 100%);
            border: none;
            border-radius: 6px;
            padding: 8px 20px;
            height: auto;
            text-shadow: none;
            box-shadow: 0 2px 8px rgba(74, 96, 161, 0.3);
            transition: all 0.2s ease;
        }

        .wp-core-ui .button-primary:hover,
        .wp-core-ui .button-primary:focus {
            background: linear-gradient(135deg, #3a4d8a 0%, #4a60a1 100%);
            box-shadow: 0 4px 16px rgba(74, 96, 161, 0.4);
        }

        .login #nav a,
        .login #backtoblog a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
        }

        .login #nav a:hover,
        .login #backtoblog a:hover {
            color: #ffffff;
        }

        .login .message,
        .login .success {
            border-left-color: #4a60a1;
            border-radius: 6px;
        }
    </style>
    <?php
}
add_action('login_enqueue_scripts', 'bne_custom_login_styles');

/**
 * Change login logo URL to homepage
 */
function bne_login_logo_url() {
    return home_url('/');
}
add_filter('login_headerurl', 'bne_login_logo_url');

/**
 * Change login logo title to site name
 */
function bne_login_logo_title() {
    return get_bloginfo('name');
}
add_filter('login_headertext', 'bne_login_logo_title');

/**
 * Get user avatar URL
 *
 * Returns the custom profile photo from mld_agent_profiles if available,
 * otherwise falls back to Gravatar.
 *
 * @param int $user_id WordPress user ID
 * @param int $size    Avatar size in pixels
 * @return string Avatar URL
 */
function bne_get_user_avatar_url($user_id, $size = 40) {
    global $wpdb;

    // Check for custom photo in MLD agent profiles table
    $table_name = $wpdb->prefix . 'mld_agent_profiles';

    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        DB_NAME,
        $table_name
    ));

    if ($table_exists) {
        $photo_url = $wpdb->get_var($wpdb->prepare(
            "SELECT photo_url FROM {$table_name} WHERE user_id = %d AND photo_url IS NOT NULL AND photo_url != ''",
            $user_id
        ));

        if (!empty($photo_url)) {
            return $photo_url;
        }
    }

    // Fall back to Gravatar
    return get_avatar_url($user_id, array('size' => $size));
}

/**
 * Enqueue parent theme styles
 */
function bne_enqueue_parent_styles() {
    // Parent theme style (GeneratePress)
    wp_enqueue_style(
        'generatepress-parent',
        get_template_directory_uri() . '/style.css',
        array(),
        BNE_THEME_VERSION
    );
}
add_action('wp_enqueue_scripts', 'bne_enqueue_parent_styles', 5);

/**
 * Enqueue theme scripts and styles
 */
function bne_enqueue_scripts() {
    // Roboto Font - Self-hosted
    wp_enqueue_style(
        'bne-fonts',
        BNE_THEME_URI . '/assets/css/fonts.css',
        array(),
        BNE_THEME_VERSION
    );

    // Child theme style
    wp_enqueue_style(
        'bne-theme-style',
        get_stylesheet_uri(),
        array('generatepress-parent'),
        BNE_THEME_VERSION
    );

    // Components CSS (header, footer, shared components)
    wp_enqueue_style(
        'bne-components-style',
        BNE_THEME_URI . '/assets/css/components.css',
        array('bne-theme-style'),
        BNE_THEME_VERSION
    );

    // Mobile Drawer CSS (all pages)
    wp_enqueue_style(
        'bne-mobile-drawer-style',
        BNE_THEME_URI . '/assets/css/mobile-drawer.css',
        array('bne-components-style'),
        BNE_THEME_VERSION
    );

    // Mobile Drawer JS (all pages)
    wp_enqueue_script(
        'bne-mobile-drawer-script',
        BNE_THEME_URI . '/assets/js/mobile-drawer.js',
        array(),
        BNE_THEME_VERSION,
        true
    );

    // Profile Dropdown JS (all pages)
    $profile_dropdown_js = "
    (function() {
        'use strict';
        document.addEventListener('DOMContentLoaded', function() {
            var toggle = document.querySelector('.bne-profile__toggle');
            var dropdown = document.querySelector('.bne-profile__dropdown');

            if (!toggle || !dropdown) return;

            // Toggle dropdown on click
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', !isExpanded);
                dropdown.setAttribute('aria-hidden', isExpanded);
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                    toggle.setAttribute('aria-expanded', 'false');
                    dropdown.setAttribute('aria-hidden', 'true');
                }
            });

            // Close dropdown on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    toggle.setAttribute('aria-expanded', 'false');
                    dropdown.setAttribute('aria-hidden', 'true');
                    toggle.focus();
                }
            });
        });
    })();
    ";
    wp_add_inline_script('bne-mobile-drawer-script', $profile_dropdown_js);

    // Homepage-specific assets
    if (is_front_page()) {
        // Homepage CSS
        wp_enqueue_style(
            'bne-homepage-style',
            BNE_THEME_URI . '/assets/css/homepage.css',
            array('bne-components-style'),
            BNE_THEME_VERSION
        );

        // Swiper CSS - Self-hosted
        wp_enqueue_style(
            'swiper',
            BNE_THEME_URI . '/assets/vendor/swiper/swiper-bundle.min.css',
            array(),
            '11.0.0'
        );

        // Swiper JS - Self-hosted
        wp_enqueue_script(
            'swiper',
            BNE_THEME_URI . '/assets/vendor/swiper/swiper-bundle.min.js',
            array(),
            '11.0.0',
            true
        );

        // Homepage JS
        wp_enqueue_script(
            'bne-homepage-script',
            BNE_THEME_URI . '/assets/js/homepage.js',
            array('swiper'),
            BNE_THEME_VERSION,
            true
        );

        // Localize script with theme data
        wp_localize_script('bne-homepage-script', 'bneTheme', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bne_theme_nonce'),
            'homeUrl' => home_url('/'),
        ));

        // Lead Tools CSS
        wp_enqueue_style(
            'bne-lead-tools-style',
            BNE_THEME_URI . '/assets/css/lead-tools.css',
            array('bne-homepage-style'),
            BNE_THEME_VERSION
        );

        // Lead Tools JS
        wp_enqueue_script(
            'bne-lead-tools-script',
            BNE_THEME_URI . '/assets/js/lead-tools.js',
            array('bne-homepage-script'),
            BNE_THEME_VERSION,
            true
        );

        // Localize Lead Tools script
        wp_localize_script('bne-lead-tools-script', 'bneLeadTools', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bne_lead_tools_nonce'),
            'defaultRate' => floatval(get_theme_mod('bne_default_mortgage_rate', 6.5)),
            'defaultTerm' => 30,
            'defaultTax' => floatval(get_theme_mod('bne_default_property_tax_rate', 1.2)),
            'defaultInsurance' => intval(get_theme_mod('bne_default_home_insurance', 1200)),
        ));
    }
}
add_action('wp_enqueue_scripts', 'bne_enqueue_scripts', 10);

/**
 * Resource hints removed in v1.2.8
 * All external CDN dependencies (Google Fonts, jsDelivr) are now self-hosted.
 * No external resource hints needed.
 */

/**
 * Add defer attribute to scripts
 */
function bne_defer_scripts($tag, $handle, $src) {
    $defer_scripts = array('swiper', 'bne-homepage-script');

    if (in_array($handle, $defer_scripts)) {
        return str_replace(' src', ' defer src', $tag);
    }

    return $tag;
}
add_filter('script_loader_tag', 'bne_defer_scripts', 10, 3);

/**
 * Template helper function to get template part with args
 */
function bne_get_template_part($slug, $name = null, $args = array()) {
    if ($args && is_array($args)) {
        extract($args);
    }

    $templates = array();
    if ($name) {
        $templates[] = "{$slug}-{$name}.php";
    }
    $templates[] = "{$slug}.php";

    foreach ($templates as $template) {
        $template_path = BNE_THEME_DIR . '/' . $template;
        if (file_exists($template_path)) {
            include $template_path;
            return;
        }
    }
}

/**
 * Get homepage section content
 */
function bne_get_homepage_section($section_name, $args = array()) {
    $template_path = BNE_THEME_DIR . '/template-parts/homepage/section-' . $section_name . '.php';

    if (file_exists($template_path)) {
        if ($args && is_array($args)) {
            extract($args);
        }
        include $template_path;
    }
}

/**
 * Get all homepage sections in configured order
 *
 * Returns sections array with order, visibility, and content.
 * Used by front-page.php for dynamic section rendering.
 *
 * @return array
 */
function bne_get_homepage_sections() {
    if (class_exists('BNE_Section_Manager')) {
        return BNE_Section_Manager::get_sections();
    }

    // Fallback to default order if class not available
    return array(
        array('id' => 'hero', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'analytics', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'market-analytics', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'services', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'neighborhoods', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'listings', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'cma-request', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'property-alerts', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'schedule-showing', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'mortgage-calc', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'about', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'cities', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'testimonials', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'team', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
        array('id' => 'blog', 'type' => 'builtin', 'enabled' => true, 'override_html' => ''),
    );
}

/**
 * Check if current page is a property-related page (search or detail)
 *
 * @return bool
 */
function bne_is_property_page() {
    // Property Search page (page ID 76)
    if (is_page(76) || is_page('property-search')) {
        return true;
    }

    // Property detail pages (URL contains /property/)
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if (strpos($request_uri, '/property/') !== false) {
        return true;
    }

    // MLS plugin map views
    if (is_page_template('template-full-map.php') || is_page_template('template-half-map.php')) {
        return true;
    }

    // Check for MLD shortcodes on current page
    global $post;
    if ($post && is_a($post, 'WP_Post')) {
        if (has_shortcode($post->post_content, 'bme_listings_map_view') ||
            has_shortcode($post->post_content, 'bme_listings_half_map_view') ||
            has_shortcode($post->post_content, 'mld_map_full') ||
            has_shortcode($post->post_content, 'mld_map_half')) {
            return true;
        }
    }

    return false;
}

/**
 * Add body class for property pages (to hide headers)
 */
function bne_property_page_body_class($classes) {
    if (bne_is_property_page()) {
        $classes[] = 'bne-no-header';
        $classes[] = 'bne-fullscreen-page';
    }
    return $classes;
}
add_filter('body_class', 'bne_property_page_body_class');

/**
 * Add body class for landing pages (solid header styling)
 */
function bne_landing_page_body_class($classes) {
    $is_landing_page = is_page_template('page-neighborhood.php')
        || is_page_template('page-school-district.php')
        || get_query_var('bne_city')
        || get_query_var('bne_city_index');

    if ($is_landing_page) {
        $classes[] = 'bne-landing-page-body';
    }
    return $classes;
}
add_filter('body_class', 'bne_landing_page_body_class');

/**
 * Remove GeneratePress header elements on property pages
 */
function bne_remove_generatepress_header() {
    if (bne_is_property_page()) {
        // Remove GeneratePress header
        remove_action('generate_header', 'generate_construct_header');
        // Remove entry header (page title)
        remove_action('generate_after_entry_header', 'generate_post_meta');
        add_filter('generate_show_title', '__return_false');
    }
}
add_action('wp', 'bne_remove_generatepress_header');

/**
 * Add inline CSS to hide headers on property pages
 */
function bne_property_page_inline_css() {
    if (bne_is_property_page()) {
        ?>
        <style id="bne-no-header-css">
            /* Hide all headers on property pages */
            .bne-no-header .bne-header,
            .bne-no-header #masthead,
            .bne-no-header .site-header,
            .bne-no-header .entry-header,
            .bne-no-header .page-header {
                display: none !important;
            }

            /* Hide footer on property pages for full-screen experience */
            .bne-no-header .bne-footer,
            .bne-no-header #colophon,
            .bne-no-header .site-footer {
                display: none !important;
            }

            /* Make content full height */
            .bne-fullscreen-page .site-content,
            .bne-fullscreen-page .content-area,
            .bne-fullscreen-page .inside-article,
            .bne-fullscreen-page main {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }

            .bne-fullscreen-page .site {
                padding-top: 0 !important;
            }

            /* Remove GeneratePress container constraints */
            .bne-fullscreen-page .grid-container {
                max-width: 100% !important;
                padding: 0 !important;
            }

            .bne-fullscreen-page .separate-containers .inside-article {
                padding: 0 !important;
            }
        </style>
        <?php
    }
}
add_action('wp_head', 'bne_property_page_inline_css', 100);

/**
 * AJAX handler for hero search autocomplete
 * Uses MLD's query class to get suggestions without nonce requirement
 * This is public data so no authentication needed
 */
function bne_hero_search_autocomplete() {
    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';

    if (strlen($term) < 2) {
        wp_send_json_success([]);
        return;
    }

    // Check if MLD_Query class exists
    if (!class_exists('MLD_Query')) {
        wp_send_json_error('MLD plugin not available');
        return;
    }

    try {
        $suggestions = MLD_Query::get_autocomplete_suggestions($term);
        wp_send_json_success($suggestions);
    } catch (Exception $e) {
        wp_send_json_error('Search error: ' . $e->getMessage());
    }
}
add_action('wp_ajax_bne_hero_autocomplete', 'bne_hero_search_autocomplete');
add_action('wp_ajax_nopriv_bne_hero_autocomplete', 'bne_hero_search_autocomplete');

/**
 * Get neighborhood data for landing pages
 *
 * Queries BME database for listing stats and neighborhood info
 *
 * @param string $neighborhood_name Neighborhood or city name
 * @return array Neighborhood data including listing stats
 */
function bne_get_neighborhood_data($neighborhood_name) {
    global $wpdb;

    if (empty($neighborhood_name)) {
        return array();
    }

    // Default data structure
    $data = array(
        'name' => $neighborhood_name,
        'state' => 'MA',
        'listing_count' => 0,
        'median_price' => 0,
        'avg_dom' => 0,
        'price_range_min' => 0,
        'price_range_max' => 0,
        'latitude' => 0,
        'longitude' => 0,
        'image' => '',
        'listings' => array(),
        'nearby' => array(),
    );

    // Check if BME tables exist
    $summary_table = $wpdb->prefix . 'bme_listing_summary';
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $summary_table
    ));

    if (!$table_exists) {
        return $data;
    }

    // Get listing stats for this city
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*) as listing_count,
            AVG(list_price) as avg_price,
            MIN(list_price) as min_price,
            MAX(list_price) as max_price,
            AVG(days_on_market) as avg_dom,
            AVG(latitude) as avg_lat,
            AVG(longitude) as avg_lng
        FROM {$summary_table}
        WHERE city = %s
        AND standard_status = 'Active'
        AND list_price > 0",
        $neighborhood_name
    ), ARRAY_A);

    if ($stats && $stats['listing_count'] > 0) {
        $data['listing_count'] = intval($stats['listing_count']);
        $data['median_price'] = round(floatval($stats['avg_price']), 0);
        $data['avg_dom'] = round(floatval($stats['avg_dom']), 0);
        $data['price_range_min'] = round(floatval($stats['min_price']), 0);
        $data['price_range_max'] = round(floatval($stats['max_price']), 0);
        $data['latitude'] = floatval($stats['avg_lat']);
        $data['longitude'] = floatval($stats['avg_lng']);
    }

    // Get sample listings for the grid (limit 12)
    $listings = $wpdb->get_results($wpdb->prepare(
        "SELECT
            listing_id,
            CONCAT(street_number, ' ', street_name) as street_address,
            city,
            state_or_province as state,
            postal_code,
            list_price,
            bedrooms_total as beds,
            bathrooms_total as baths,
            building_area_total as living_area,
            days_on_market,
            latitude,
            longitude,
            main_photo_url as photo,
            modification_timestamp
        FROM {$summary_table}
        WHERE city = %s
        AND standard_status = 'Active'
        AND list_price > 0
        ORDER BY modification_timestamp DESC
        LIMIT 12",
        $neighborhood_name
    ), ARRAY_A);

    if ($listings) {
        $data['listings'] = $listings;

        // Use first listing's photo as neighborhood image if available
        foreach ($listings as $listing) {
            if (!empty($listing['photo'])) {
                $data['image'] = $listing['photo'];
                break;
            }
        }
    }

    // Get nearby cities (within 10 miles, different from current)
    if ($data['latitude'] && $data['longitude']) {
        $nearby = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city as name,
                COUNT(*) as listing_count,
                AVG(latitude) as lat,
                AVG(longitude) as lng,
                (
                    3959 * acos(
                        cos(radians(%f)) * cos(radians(AVG(latitude))) *
                        cos(radians(AVG(longitude)) - radians(%f)) +
                        sin(radians(%f)) * sin(radians(AVG(latitude)))
                    )
                ) AS distance
            FROM {$summary_table}
            WHERE city != %s
            AND standard_status = 'Active'
            AND latitude IS NOT NULL
            AND longitude IS NOT NULL
            GROUP BY city
            HAVING distance < 10
            ORDER BY listing_count DESC
            LIMIT 6",
            $data['latitude'],
            $data['longitude'],
            $data['latitude'],
            $neighborhood_name
        ), ARRAY_A);

        if ($nearby) {
            foreach ($nearby as &$city) {
                $city['url'] = home_url('/real-estate/' . sanitize_title($city['name']) . '/');
            }
            $data['nearby'] = $nearby;
        }
    }

    // Add data freshness fields for GEO (Generative Engine Optimization)
    $data['data_freshness'] = current_time('mysql');
    $data['freshness_display'] = wp_date('M j, Y \a\t g:i A');
    $data['newest_listing'] = !empty($listings) && !empty($listings[0]['modification_timestamp'])
        ? $listings[0]['modification_timestamp']
        : null;
    $data['listing_type'] = 'sale'; // Default to sale for basic function

    // Allow filtering of neighborhood data
    $data = apply_filters('bne_neighborhood_data', $data, $neighborhood_name);

    return $data;
}

/**
 * Generate natural language prose market summary for GEO optimization
 *
 * Creates a 3-4 sentence description of the market conditions
 * using data from MLD analytics system.
 *
 * @param array $data Neighborhood data from bne_get_neighborhood_data()
 * @return string Natural language market summary
 * @since 1.3.9
 */
function bne_generate_market_prose($data) {
    $name = $data['name'] ?? 'this area';
    $state = $data['state'] ?? 'MA';
    $listing_type = $data['listing_type'] ?? 'sale';
    $is_rental = ($listing_type === 'lease');

    // Initialize analytics data
    $heat = array();
    $supply = array();

    // Use MLD analytics for rich data if available
    if (class_exists('MLD_Extended_Analytics')) {
        $heat = MLD_Extended_Analytics::get_market_heat_index($name, $state, 'Residential');
        $supply = MLD_Extended_Analytics::get_supply_demand_metrics($name, $state, 'Residential');
    }

    // Build detailed 3-4 sentence prose
    $sentences = array();

    // Sentence 1: Inventory overview
    $count = $data['listing_count'] ?? 0;
    $median = $data['median_price'] ?? 0;

    // Format price appropriately - rentals typically show monthly rate
    if ($is_rental) {
        $price_fmt = '$' . number_format($median) . '/month';
        $price_label = 'median rent';
    } else {
        if ($median >= 1000000) {
            $price_fmt = '$' . number_format($median / 1000000, 1) . 'M';
        } elseif ($median >= 1000) {
            $price_fmt = '$' . number_format($median / 1000) . 'K';
        } else {
            $price_fmt = '$' . number_format($median);
        }
        $price_label = 'median listing price';
    }

    $type_word = $is_rental ? 'apartments and homes for rent' : 'homes for sale';
    $sentences[] = sprintf(
        "The %s %s market currently offers %d %s with a %s of %s.",
        $name,
        $is_rental ? 'rental' : 'real estate',
        $count,
        $type_word,
        $price_label,
        $price_fmt
    );

    // Sentence 2: Market temperature (from heat index if available)
    if (!empty($heat['classification'])) {
        $heat_index = $heat['heat_index'] ?? 50;
        if ($is_rental) {
            switch ($heat['classification']) {
                case 'Hot':
                    $market_desc = "a competitive rental market favoring landlords";
                    break;
                case 'Cold':
                    $market_desc = "a renter-friendly market with more negotiating power";
                    break;
                default:
                    $market_desc = "a balanced rental market";
            }
        } else {
            switch ($heat['classification']) {
                case 'Hot':
                    $market_desc = "a competitive seller's market";
                    break;
                case 'Cold':
                    $market_desc = "a buyer-friendly market";
                    break;
                default:
                    $market_desc = "a balanced market";
            }
        }
        $sentences[] = sprintf(
            "With a market heat index of %d, this is %s.",
            $heat_index,
            $market_desc
        );
    }

    // Sentence 3: DOM and velocity
    $avg_dom = $data['avg_dom'] ?? 0;
    if ($avg_dom > 0) {
        if ($is_rental) {
            if ($avg_dom < 14) {
                $speed_desc = 'quickly, often within days';
            } elseif ($avg_dom < 30) {
                $speed_desc = 'at a steady pace';
            } else {
                $speed_desc = 'with more time for renters to decide';
            }
            $sentences[] = sprintf(
                "Rentals typically get leased %s, averaging %d days on market.",
                $speed_desc,
                $avg_dom
            );
        } else {
            if ($avg_dom < 21) {
                $speed_desc = 'quickly, often within weeks';
            } elseif ($avg_dom < 45) {
                $speed_desc = 'at a moderate pace';
            } else {
                $speed_desc = 'with more time for buyer consideration';
            }
            $sentences[] = sprintf(
                "Properties typically sell %s, averaging %d days on market.",
                $speed_desc,
                $avg_dom
            );
        }
    }

    // Sentence 4: Supply context or price range
    if (!empty($supply['months_of_supply']) && !$is_rental) {
        // Months of supply is more relevant for sales market
        $mos = floatval($supply['months_of_supply']);
        if ($mos < 3) {
            $supply_desc = 'limited inventory creating competition';
        } elseif ($mos > 6) {
            $supply_desc = 'ample inventory giving buyers options';
        } else {
            $supply_desc = 'healthy inventory levels';
        }
        $sentences[] = sprintf(
            "Current supply stands at %.1f months, indicating %s.",
            $mos,
            $supply_desc
        );
    } elseif (!empty($data['price_range_min']) && !empty($data['price_range_max'])) {
        // Price range sentence
        if ($is_rental) {
            $min_price = '$' . number_format($data['price_range_min']);
            $max_price = '$' . number_format($data['price_range_max']);
            $sentences[] = sprintf(
                "Monthly rents range from %s to %s, accommodating various budgets and lifestyle needs.",
                $min_price,
                $max_price
            );
        } else {
            $min_price = $data['price_range_min'] >= 1000000
                ? '$' . number_format($data['price_range_min'] / 1000000, 1) . 'M'
                : '$' . number_format($data['price_range_min'] / 1000) . 'K';
            $max_price = $data['price_range_max'] >= 1000000
                ? '$' . number_format($data['price_range_max'] / 1000000, 1) . 'M'
                : '$' . number_format($data['price_range_max'] / 1000) . 'K';
            $sentences[] = sprintf(
                "Prices range from %s to %s, offering options for various budgets.",
                $min_price,
                $max_price
            );
        }
    }

    return implode(' ', $sentences);
}

/**
 * Enqueue landing page assets
 */
function bne_enqueue_landing_page_assets() {
    // Check if we're on a landing page template OR auto-generated city page
    $is_landing_page = is_page_template('page-neighborhood.php')
        || is_page_template('page-school-district.php')
        || get_query_var('bne_city')  // Auto-generated city pages
        || get_query_var('bne_city_index');  // City index page

    if ($is_landing_page) {
        wp_enqueue_style(
            'bne-landing-pages-style',
            BNE_THEME_URI . '/assets/css/landing-pages.css',
            array('bne-components-style'),
            BNE_THEME_VERSION
        );

        wp_enqueue_script(
            'bne-landing-pages-script',
            BNE_THEME_URI . '/assets/js/landing-pages.js',
            array(),
            BNE_THEME_VERSION,
            true
        );

        wp_localize_script('bne-landing-pages-script', 'bneLandingPages', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bne_landing_pages_nonce'),
            'searchUrl' => home_url('/property-search/'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'bne_enqueue_landing_page_assets', 15);

/**
 * Enqueue school page assets
 *
 * Loads CSS and JS for school browse, district detail, and school detail pages
 */
function bne_enqueue_school_page_assets() {
    // Check if we're on a schools page
    $is_schools_browse = get_query_var('bmn_schools_browse');
    $is_district_page = get_query_var('bmn_district_page');
    $is_school_page = get_query_var('bmn_school_page');
    $is_schools_page = $is_schools_browse || $is_district_page || $is_school_page;

    if ($is_schools_page) {
        // Schools CSS
        wp_enqueue_style(
            'bne-schools-style',
            BNE_THEME_URI . '/assets/css/schools.css',
            array('bne-components-style'),
            BNE_THEME_VERSION
        );

        // Dependencies for schools JS
        $js_deps = array();

        // Load Google Maps for district and school detail pages (for maps)
        if ($is_district_page || $is_school_page) {
            // Get Google Maps API key from MLS plugin settings
            $mld_options = get_option('mld_settings', array());
            $google_key = isset($mld_options['mld_google_maps_api_key']) ? $mld_options['mld_google_maps_api_key'] : '';

            if ($google_key) {
                // Register Google Maps with a unique handle to avoid conflicts
                $maps_handle = 'bne-google-maps-api';
                if (!wp_script_is($maps_handle, 'registered')) {
                    // Note: Do NOT use loading=async with callback - it causes race conditions
                    // where google.maps exists but google.maps.Map is undefined
                    $google_maps_url = "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=geometry&callback=initDistrictMap";
                    wp_register_script($maps_handle, $google_maps_url, array(), null, true);
                }
                wp_enqueue_script($maps_handle);
                $js_deps[] = $maps_handle;
            }
        }

        // Schools JS
        wp_enqueue_script(
            'bne-schools-script',
            BNE_THEME_URI . '/assets/js/schools.js',
            $js_deps,
            BNE_THEME_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('bne-schools-script', 'bneSchools', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bne_schools_nonce'),
            'browseUrl' => home_url('/schools/'),
        ));
    }
}
add_action('wp_enqueue_scripts', 'bne_enqueue_school_page_assets', 15);

/**
 * Enqueue assets for MA School Districts Guide page
 *
 * @since 1.5.9
 */
function bne_enqueue_schools_guide_assets() {
    // Check if using the schools guide template
    if (is_page_template('page-schools-guide.php')) {
        wp_enqueue_style(
            'bne-schools-guide-style',
            BNE_THEME_URI . '/assets/css/page-schools-guide.css',
            array('bne-components-style'),
            BNE_THEME_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'bne_enqueue_schools_guide_assets', 15);

/**
 * Handle School District Guide download lead tracking
 *
 * Logs leads who download the free PDF guide and notifies the team.
 *
 * @since 1.5.9
 */
function bne_track_guide_download() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bne_guide_download')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }

    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $source = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'bottom_form';

    if (empty($email)) {
        wp_send_json_error(array('message' => 'Email is required'));
        return;
    }

    // Log the lead to the database (creates table if needed)
    global $wpdb;
    $table_name = $wpdb->prefix . 'bne_guide_leads';

    // Create table if it doesn't exist (includes source and phone columns)
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        phone_opt_in TINYINT(1) DEFAULT 0,
        source VARCHAR(50) DEFAULT 'bottom_form',
        guide_name VARCHAR(255) DEFAULT 'MA School District Guide 2026',
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY email (email)
    ) {$wpdb->get_charset_collate()};");

    // Add columns if they don't exist (for existing tables)
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL AFTER email");
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS phone_opt_in TINYINT(1) DEFAULT 0 AFTER phone");
    $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'bottom_form' AFTER phone_opt_in");

    // Insert the lead
    $wpdb->insert(
        $table_name,
        array(
            'name' => $name,
            'email' => $email,
            'source' => $source,
            'guide_name' => 'MA School District Guide 2026',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => current_time('mysql'),
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s')
    );

    // Send notification email to team
    $admin_email = 'contact@bmnboston.com';
    $subject = 'New Guide Download: ' . ($name ?: 'Unknown');
    $message = "A new lead has downloaded the MA School District Guide:\n\n";
    $message .= "Name: " . ($name ?: '(not provided)') . "\n";
    $message .= "Email: {$email}\n";
    $message .= "Source: {$source}\n";
    $message .= "Time: " . current_time('F j, Y g:i a') . "\n\n";
    $message .= "This lead is interested in buying a home in a top school district.\n";
    $message .= "Consider following up with personalized recommendations.\n\n";
    $message .= "-- BMN Boston Website";

    wp_mail($admin_email, $subject, $message);

    wp_send_json_success(array('message' => 'Lead tracked successfully'));
}
add_action('wp_ajax_bne_track_guide_download', 'bne_track_guide_download');
add_action('wp_ajax_nopriv_bne_track_guide_download', 'bne_track_guide_download');

/**
 * Capture phone number for guide leads (progressive profiling)
 *
 * @since 1.6.0
 */
function bne_capture_guide_phone() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bne_guide_download')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';

    if (empty($email) || empty($phone)) {
        wp_send_json_error(array('message' => 'Email and phone are required'));
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bne_guide_leads';

    // Update the most recent lead with this email
    $wpdb->update(
        $table_name,
        array(
            'phone' => $phone,
            'phone_opt_in' => 1,
        ),
        array('email' => $email),
        array('%s', '%d'),
        array('%s')
    );

    // Send notification about phone capture
    $admin_email = 'contact@bmnboston.com';
    $subject = 'Guide Lead Added Phone: ' . $phone;
    $message = "A guide lead has opted in for text notifications:\n\n";
    $message .= "Email: {$email}\n";
    $message .= "Phone: {$phone}\n";
    $message .= "Time: " . current_time('F j, Y g:i a') . "\n\n";
    $message .= "This is a high-intent lead - they want listing updates via text.\n\n";
    $message .= "-- BMN Boston Website";

    wp_mail($admin_email, $subject, $message);

    wp_send_json_success(array('message' => 'Phone captured successfully'));
}
add_action('wp_ajax_bne_capture_guide_phone', 'bne_capture_guide_phone');
add_action('wp_ajax_nopriv_bne_capture_guide_phone', 'bne_capture_guide_phone');

/**
 * Get school district data for landing pages
 *
 * Queries BME database for homes near schools in a district
 *
 * @param string $district_name School district name
 * @return array District data including listing stats
 */
function bne_get_school_district_data($district_name) {
    global $wpdb;

    if (empty($district_name)) {
        return array();
    }

    // Default data structure
    $data = array(
        'name' => $district_name,
        'state' => 'MA',
        'listing_count' => 0,
        'median_price' => 0,
        'avg_dom' => 0,
        'price_range_min' => 0,
        'price_range_max' => 0,
        'latitude' => 0,
        'longitude' => 0,
        'image' => '',
        'listings' => array(),
        'nearby' => array(),
        'schools' => array(),
    );

    // Check if BME tables exist
    $summary_table = $wpdb->prefix . 'bme_listing_summary';
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $summary_table
    ));

    if (!$table_exists) {
        return $data;
    }

    // Try to match district name to cities (common pattern: "City Public Schools")
    $city_name = preg_replace('/\s*(Public\s+)?Schools?\s*$/i', '', $district_name);
    $city_name = trim($city_name);

    // Get listing stats for cities matching this district
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*) as listing_count,
            AVG(list_price) as avg_price,
            MIN(list_price) as min_price,
            MAX(list_price) as max_price,
            AVG(days_on_market) as avg_dom,
            AVG(latitude) as avg_lat,
            AVG(longitude) as avg_lng
        FROM {$summary_table}
        WHERE city LIKE %s
        AND standard_status = 'Active'
        AND list_price > 0",
        '%' . $wpdb->esc_like($city_name) . '%'
    ), ARRAY_A);

    if ($stats && $stats['listing_count'] > 0) {
        $data['listing_count'] = intval($stats['listing_count']);
        $data['median_price'] = round(floatval($stats['avg_price']), 0);
        $data['avg_dom'] = round(floatval($stats['avg_dom']), 0);
        $data['price_range_min'] = round(floatval($stats['min_price']), 0);
        $data['price_range_max'] = round(floatval($stats['max_price']), 0);
        $data['latitude'] = floatval($stats['avg_lat']);
        $data['longitude'] = floatval($stats['avg_lng']);
    }

    // Get sample listings for the grid (limit 12)
    $listings = $wpdb->get_results($wpdb->prepare(
        "SELECT
            listing_id,
            CONCAT(street_number, ' ', street_name) as street_address,
            city,
            state_or_province as state,
            postal_code,
            list_price,
            bedrooms_total as beds,
            bathrooms_total as baths,
            building_area_total as living_area,
            days_on_market,
            latitude,
            longitude,
            main_photo_url as photo
        FROM {$summary_table}
        WHERE city LIKE %s
        AND standard_status = 'Active'
        AND list_price > 0
        ORDER BY modification_timestamp DESC
        LIMIT 12",
        '%' . $wpdb->esc_like($city_name) . '%'
    ), ARRAY_A);

    if ($listings) {
        $data['listings'] = $listings;

        // Use first listing's photo as district image if available
        foreach ($listings as $listing) {
            if (!empty($listing['photo'])) {
                $data['image'] = $listing['photo'];
                break;
            }
        }
    }

    // Mock school data (would be from external API in production)
    $data['schools'] = array(
        array(
            'name' => $district_name . ' High School',
            'type' => 'High School',
            'rating' => rand(6, 10),
            'grades' => '9-12',
        ),
        array(
            'name' => $district_name . ' Middle School',
            'type' => 'Middle School',
            'rating' => rand(6, 10),
            'grades' => '6-8',
        ),
        array(
            'name' => $district_name . ' Elementary',
            'type' => 'Elementary School',
            'rating' => rand(6, 10),
            'grades' => 'K-5',
        ),
    );

    // Allow filtering of district data
    $data = apply_filters('bne_school_district_data', $data, $district_name);

    return $data;
}

/**
 * Get available filter options from the database
 *
 * Returns dynamic filter options based on actual data in the system
 *
 * @param string $city Optional city to scope filters
 * @param string $listing_type 'sale' or 'lease'
 * @return array Filter options
 */
function bne_get_filter_options($location_name = '', $listing_type = 'sale', $location_type = 'city') {
    global $wpdb;

    $summary_table = $wpdb->prefix . 'bme_listing_summary';
    $location_table = $wpdb->prefix . 'bme_listing_location';
    $cache_key = 'bne_filter_options_' . md5($location_name . '_' . $listing_type . '_' . $location_type);

    // Try to get from cache first (15 minutes)
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    // Determine if we need to join location table (for neighborhoods)
    $from_clause = "FROM {$summary_table} s";
    $join_clause = '';

    if ($location_type === 'neighborhood') {
        $join_clause = "JOIN {$location_table} loc ON s.listing_id = loc.listing_id";
    }

    // Build WHERE clause
    $where = "WHERE s.standard_status = 'Active'";

    if ($listing_type === 'sale') {
        $where .= " AND s.property_type IN ('Residential', 'Residential Income', 'Land')";
        $where .= " AND s.list_price >= 50000"; // Exclude very low prices (parking, etc.)
    } elseif ($listing_type === 'lease') {
        $where .= " AND s.property_type IN ('Residential Lease', 'Commercial Lease')";
    }

    if (!empty($location_name)) {
        if ($location_type === 'neighborhood') {
            $where .= $wpdb->prepare(" AND loc.mls_area_major = %s", $location_name);
        } else {
            $where .= $wpdb->prepare(" AND s.city = %s", $location_name);
        }
    }

    $options = array(
        'property_types' => array(),
        'property_sub_types' => array(),
        'bedrooms' => array(),
        'bathrooms' => array(),
        'price_ranges' => array(),
        'features' => array(),
        'cities' => array(),
        'stats' => array(),
    );

    // Get property types with counts
    $types = $wpdb->get_results(
        "SELECT s.property_type, COUNT(*) as count
         {$from_clause}
         {$join_clause}
         {$where}
         GROUP BY s.property_type
         ORDER BY count DESC",
        ARRAY_A
    );

    foreach ($types as $type) {
        if (!empty($type['property_type'])) {
            $options['property_types'][] = array(
                'value' => $type['property_type'],
                'label' => $type['property_type'],
                'count' => intval($type['count']),
            );
        }
    }

    // Get property sub-types with counts
    $sub_types = $wpdb->get_results(
        "SELECT s.property_sub_type, COUNT(*) as count
         {$from_clause}
         {$join_clause}
         {$where}
         AND s.property_sub_type IS NOT NULL
         GROUP BY s.property_sub_type
         ORDER BY count DESC
         LIMIT 15",
        ARRAY_A
    );

    foreach ($sub_types as $sub_type) {
        if (!empty($sub_type['property_sub_type'])) {
            $options['property_sub_types'][] = array(
                'value' => $sub_type['property_sub_type'],
                'label' => $sub_type['property_sub_type'],
                'count' => intval($sub_type['count']),
            );
        }
    }

    // Get bedroom options with counts (limit to realistic values)
    $bedrooms = $wpdb->get_results(
        "SELECT s.bedrooms_total as beds, COUNT(*) as count
         {$from_clause}
         {$join_clause}
         {$where}
         AND s.bedrooms_total IS NOT NULL
         AND s.bedrooms_total BETWEEN 0 AND 10
         GROUP BY s.bedrooms_total
         ORDER BY s.bedrooms_total",
        ARRAY_A
    );

    foreach ($bedrooms as $bed) {
        $label = intval($bed['beds']) === 0 ? 'Studio' : $bed['beds'] . '+';
        $options['bedrooms'][] = array(
            'value' => intval($bed['beds']),
            'label' => $label,
            'count' => intval($bed['count']),
        );
    }

    // Get bathroom options with counts (limit to realistic values)
    $bathrooms = $wpdb->get_results(
        "SELECT FLOOR(s.bathrooms_total) as baths, COUNT(*) as count
         {$from_clause}
         {$join_clause}
         {$where}
         AND s.bathrooms_total IS NOT NULL
         AND s.bathrooms_total BETWEEN 1 AND 6
         GROUP BY FLOOR(s.bathrooms_total)
         ORDER BY baths",
        ARRAY_A
    );

    foreach ($bathrooms as $bath) {
        $options['bathrooms'][] = array(
            'value' => intval($bath['baths']),
            'label' => $bath['baths'] . '+',
            'count' => intval($bath['count']),
        );
    }

    // Get dynamic price ranges based on actual data distribution
    $price_stats = $wpdb->get_row(
        "SELECT
            MIN(s.list_price) as min_price,
            MAX(s.list_price) as max_price,
            AVG(s.list_price) as avg_price,
            COUNT(*) as total
         {$from_clause}
         {$join_clause}
         {$where}
         AND s.list_price > 0",
        ARRAY_A
    );

    if ($price_stats) {
        $options['stats'] = array(
            'min_price' => intval($price_stats['min_price']),
            'max_price' => intval($price_stats['max_price']),
            'avg_price' => intval($price_stats['avg_price']),
            'total_listings' => intval($price_stats['total']),
        );

        // Generate smart price ranges based on data
        $options['price_ranges'] = bne_generate_price_ranges(
            intval($price_stats['min_price']),
            intval($price_stats['max_price']),
            $listing_type
        );
    }

    // Get feature counts
    $features = $wpdb->get_row(
        "SELECT
            SUM(CASE WHEN s.has_pool = 1 THEN 1 ELSE 0 END) as pool_count,
            SUM(CASE WHEN s.has_fireplace = 1 THEN 1 ELSE 0 END) as fireplace_count,
            SUM(CASE WHEN s.has_basement = 1 THEN 1 ELSE 0 END) as basement_count,
            SUM(CASE WHEN s.garage_spaces > 0 THEN 1 ELSE 0 END) as garage_count,
            SUM(CASE WHEN s.pet_friendly = 1 THEN 1 ELSE 0 END) as pet_count
         {$from_clause}
         {$join_clause}
         {$where}",
        ARRAY_A
    );

    if ($features) {
        if ($features['pool_count'] > 0) {
            $options['features'][] = array('value' => 'pool', 'label' => 'Pool', 'count' => intval($features['pool_count']));
        }
        if ($features['fireplace_count'] > 0) {
            $options['features'][] = array('value' => 'fireplace', 'label' => 'Fireplace', 'count' => intval($features['fireplace_count']));
        }
        if ($features['basement_count'] > 0) {
            $options['features'][] = array('value' => 'basement', 'label' => 'Basement', 'count' => intval($features['basement_count']));
        }
        if ($features['garage_count'] > 0) {
            $options['features'][] = array('value' => 'garage', 'label' => 'Garage', 'count' => intval($features['garage_count']));
        }
        if ($features['pet_count'] > 0) {
            $options['features'][] = array('value' => 'pets', 'label' => 'Pet Friendly', 'count' => intval($features['pet_count']));
        }
    }

    // Get top cities if not scoped to a specific location
    if (empty($location_name)) {
        $cities = $wpdb->get_results(
            "SELECT s.city, COUNT(*) as count
             {$from_clause}
             {$join_clause}
             {$where}
             AND s.city IS NOT NULL
             GROUP BY s.city
             ORDER BY count DESC
             LIMIT 25",
            ARRAY_A
        );

        foreach ($cities as $c) {
            if (!empty($c['city'])) {
                $options['cities'][] = array(
                    'value' => $c['city'],
                    'label' => $c['city'],
                    'count' => intval($c['count']),
                    'url' => home_url('/real-estate/' . sanitize_title($c['city']) . '/'),
                );
            }
        }
    }

    // Cache for 15 minutes
    set_transient($cache_key, $options, 15 * MINUTE_IN_SECONDS);

    return $options;
}

/**
 * Generate smart price ranges based on actual data
 *
 * @param int $min Minimum price in data
 * @param int $max Maximum price in data
 * @param string $type 'sale' or 'lease'
 * @return array Price range options
 */
function bne_generate_price_ranges($min, $max, $type = 'sale') {
    $ranges = array();

    if ($type === 'lease') {
        // Rental price ranges
        $breakpoints = array(1000, 1500, 2000, 2500, 3000, 3500, 4000, 5000, 6000, 8000, 10000);
    } else {
        // Sale price ranges
        $breakpoints = array(
            200000, 300000, 400000, 500000, 600000, 750000,
            1000000, 1250000, 1500000, 2000000, 3000000, 5000000
        );
    }

    // Filter to relevant breakpoints
    $relevant = array_filter($breakpoints, function($bp) use ($min, $max) {
        return $bp >= $min * 0.5 && $bp <= $max * 1.5;
    });

    // Add min option
    $ranges[] = array(
        'min' => 0,
        'max' => 0,
        'label' => 'Any Price',
    );

    $prev = 0;
    foreach ($relevant as $bp) {
        if ($type === 'lease') {
            $label = '$' . number_format($bp) . '/mo';
        } else {
            $label = '$' . bne_format_price_short($bp);
        }

        $ranges[] = array(
            'min' => $prev,
            'max' => $bp,
            'label' => $prev > 0 ? bne_format_price_short($prev) . ' - ' . $label : 'Up to ' . $label,
        );
        $prev = $bp;
    }

    // Add max option
    if ($prev > 0) {
        $ranges[] = array(
            'min' => $prev,
            'max' => 0,
            'label' => '$' . bne_format_price_short($prev) . '+',
        );
    }

    return $ranges;
}

/**
 * Format price in short form (e.g., 500K, 1.5M)
 *
 * @param int $price Price to format
 * @return string Formatted price
 */
function bne_format_price_short($price) {
    if ($price >= 1000000) {
        return number_format($price / 1000000, 1) . 'M';
    } elseif ($price >= 1000) {
        return number_format($price / 1000) . 'K';
    }
    return number_format($price);
}

/**
 * Get enhanced neighborhood data with filter support
 *
 * @param string $neighborhood_name City/neighborhood name
 * @param array $filters Active filters
 * @return array Enhanced neighborhood data
 */
function bne_get_neighborhood_data_filtered($neighborhood_name, $filters = array(), $location_type = 'city') {
    global $wpdb;

    if (empty($neighborhood_name)) {
        return array();
    }

    $summary_table = $wpdb->prefix . 'bme_listing_summary';
    $location_table = $wpdb->prefix . 'bme_listing_location';

    // Default data structure
    $data = array(
        'name' => $neighborhood_name,
        'state' => 'MA',
        'listing_count' => 0,
        'median_price' => 0,
        'avg_price' => 0,
        'avg_dom' => 0,
        'price_range_min' => 0,
        'price_range_max' => 0,
        'avg_sqft' => 0,
        'avg_price_per_sqft' => 0,
        'latitude' => 0,
        'longitude' => 0,
        'image' => '',
        'listings' => array(),
        'nearby' => array(),
        'filter_options' => array(),
        'active_filters' => $filters,
        'location_type' => $location_type,
    );

    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $summary_table));
    if (!$table_exists) {
        return $data;
    }

    // Determine if we need to join location table (for neighborhoods)
    $join_clause = '';
    $table_alias = 's';

    if ($location_type === 'neighborhood') {
        $join_clause = "JOIN {$location_table} loc ON s.listing_id = loc.listing_id";
    }

    // Build WHERE clause with filters
    if ($location_type === 'neighborhood') {
        // Filter by mls_area_major for neighborhoods
        $where_parts = array(
            $wpdb->prepare("loc.mls_area_major = %s", $neighborhood_name),
            "s.standard_status = 'Active'",
            "s.list_price > 0",
        );
    } else {
        // Filter by city for cities
        $where_parts = array(
            $wpdb->prepare("s.city = %s", $neighborhood_name),
            "s.standard_status = 'Active'",
            "s.list_price > 0",
        );
    }

    // Default to sale listings (exclude leases)
    $listing_type = isset($filters['listing_type']) ? $filters['listing_type'] : 'sale';
    if ($listing_type === 'sale') {
        $where_parts[] = "s.property_type IN ('Residential', 'Residential Income', 'Land')";
        $where_parts[] = "s.list_price >= 50000"; // Exclude parking spots, etc.
    } elseif ($listing_type === 'lease') {
        $where_parts[] = "s.property_type IN ('Residential Lease')";
    }

    // Apply property type filter
    if (!empty($filters['property_type'])) {
        $where_parts[] = $wpdb->prepare("s.property_type = %s", $filters['property_type']);
    }

    // Apply property sub-type filter
    if (!empty($filters['property_sub_type'])) {
        $where_parts[] = $wpdb->prepare("s.property_sub_type = %s", $filters['property_sub_type']);
    }

    // Apply bedroom filter
    if (!empty($filters['min_beds'])) {
        $where_parts[] = $wpdb->prepare("s.bedrooms_total >= %d", intval($filters['min_beds']));
    }

    // Apply bathroom filter
    if (!empty($filters['min_baths'])) {
        $where_parts[] = $wpdb->prepare("s.bathrooms_total >= %d", intval($filters['min_baths']));
    }

    // Apply price filters
    if (!empty($filters['min_price'])) {
        $where_parts[] = $wpdb->prepare("s.list_price >= %d", intval($filters['min_price']));
    }
    if (!empty($filters['max_price'])) {
        $where_parts[] = $wpdb->prepare("s.list_price <= %d", intval($filters['max_price']));
    }

    // Apply feature filters
    if (!empty($filters['features']) && is_array($filters['features'])) {
        foreach ($filters['features'] as $feature) {
            switch ($feature) {
                case 'pool':
                    $where_parts[] = "s.has_pool = 1";
                    break;
                case 'fireplace':
                    $where_parts[] = "s.has_fireplace = 1";
                    break;
                case 'basement':
                    $where_parts[] = "s.has_basement = 1";
                    break;
                case 'garage':
                    $where_parts[] = "s.garage_spaces > 0";
                    break;
                case 'pets':
                    $where_parts[] = "s.pet_friendly = 1";
                    break;
            }
        }
    }

    $where = implode(' AND ', $where_parts);

    // Get listing stats
    $stats = $wpdb->get_row(
        "SELECT
            COUNT(*) as listing_count,
            AVG(s.list_price) as avg_price,
            MIN(s.list_price) as min_price,
            MAX(s.list_price) as max_price,
            AVG(s.days_on_market) as avg_dom,
            AVG(s.building_area_total) as avg_sqft,
            AVG(s.price_per_sqft) as avg_price_sqft,
            AVG(s.latitude) as avg_lat,
            AVG(s.longitude) as avg_lng
        FROM {$summary_table} s
        {$join_clause}
        WHERE {$where}",
        ARRAY_A
    );

    if ($stats && $stats['listing_count'] > 0) {
        $data['listing_count'] = intval($stats['listing_count']);
        $data['avg_price'] = round(floatval($stats['avg_price']), 0);
        $data['median_price'] = $data['avg_price']; // Approximation
        $data['avg_dom'] = round(floatval($stats['avg_dom']), 0);
        $data['price_range_min'] = round(floatval($stats['min_price']), 0);
        $data['price_range_max'] = round(floatval($stats['max_price']), 0);
        $data['avg_sqft'] = round(floatval($stats['avg_sqft']), 0);
        $data['avg_price_per_sqft'] = round(floatval($stats['avg_price_sqft']), 0);
        $data['latitude'] = floatval($stats['avg_lat']);
        $data['longitude'] = floatval($stats['avg_lng']);
    }

    // Determine sort order
    $order_by = "s.modification_timestamp DESC";
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'price_asc':
                $order_by = "s.list_price ASC";
                break;
            case 'price_desc':
                $order_by = "s.list_price DESC";
                break;
            case 'newest':
                $order_by = "s.listing_contract_date DESC, s.modification_timestamp DESC";
                break;
            case 'beds_desc':
                $order_by = "s.bedrooms_total DESC";
                break;
            case 'sqft_desc':
                $order_by = "s.building_area_total DESC";
                break;
        }
    }

    // Get listings with pagination support
    $per_page = isset($filters['per_page']) ? intval($filters['per_page']) : 12;
    $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
    $offset = ($page - 1) * $per_page;

    $listings = $wpdb->get_results(
        "SELECT
            s.listing_id,
            s.listing_key,
            CONCAT(s.street_number, ' ', s.street_name) as street_address,
            s.unit_number,
            s.city,
            s.state_or_province as state,
            s.postal_code,
            s.list_price,
            s.original_list_price,
            s.bedrooms_total as beds,
            s.bathrooms_total as baths,
            s.building_area_total as living_area,
            s.lot_size_acres,
            s.year_built,
            s.days_on_market,
            s.property_type,
            s.property_sub_type,
            s.latitude,
            s.longitude,
            s.main_photo_url as photo,
            s.photo_count,
            s.has_pool,
            s.has_fireplace,
            s.has_basement,
            s.garage_spaces,
            s.listing_contract_date,
            s.modification_timestamp
        FROM {$summary_table} s
        {$join_clause}
        WHERE {$where}
        ORDER BY {$order_by}
        LIMIT {$per_page} OFFSET {$offset}",
        ARRAY_A
    );

    if ($listings) {
        $data['listings'] = $listings;

        // Use first listing's photo as neighborhood image if available
        foreach ($listings as $listing) {
            if (!empty($listing['photo'])) {
                $data['image'] = $listing['photo'];
                break;
            }
        }
    }

    // Calculate pagination
    $data['pagination'] = array(
        'current_page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($data['listing_count'] / $per_page),
        'total_listings' => $data['listing_count'],
    );

    // Get filter options for this city
    $data['filter_options'] = bne_get_filter_options($neighborhood_name, $listing_type, $location_type);

    // Get nearby cities
    if ($data['latitude'] && $data['longitude']) {
        $nearby_where = $listing_type === 'sale'
            ? "AND property_type IN ('Residential', 'Residential Income', 'Land')"
            : "AND property_type IN ('Residential Lease')";

        $nearby = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city as name,
                COUNT(*) as listing_count,
                AVG(list_price) as avg_price,
                AVG(latitude) as lat,
                AVG(longitude) as lng,
                (
                    3959 * acos(
                        cos(radians(%f)) * cos(radians(AVG(latitude))) *
                        cos(radians(AVG(longitude)) - radians(%f)) +
                        sin(radians(%f)) * sin(radians(AVG(latitude)))
                    )
                ) AS distance
            FROM {$summary_table}
            WHERE city != %s
            AND standard_status = 'Active'
            AND latitude IS NOT NULL
            AND longitude IS NOT NULL
            AND list_price > 50000
            {$nearby_where}
            GROUP BY city
            HAVING distance < 15
            ORDER BY listing_count DESC
            LIMIT 8",
            $data['latitude'],
            $data['longitude'],
            $data['latitude'],
            $neighborhood_name
        ), ARRAY_A);

        if ($nearby) {
            foreach ($nearby as &$city) {
                $city['url'] = home_url('/real-estate/' . sanitize_title($city['name']) . '/');
                $city['avg_price'] = round(floatval($city['avg_price']), 0);
            }
            $data['nearby'] = $nearby;
        }
    }

    // Add data freshness fields for GEO (Generative Engine Optimization)
    $data['data_freshness'] = current_time('mysql');
    $data['freshness_display'] = wp_date('M j, Y \a\t g:i A');
    $data['newest_listing'] = !empty($listings) && !empty($listings[0]['modification_timestamp'])
        ? $listings[0]['modification_timestamp']
        : null;
    $data['listing_type'] = $listing_type;

    return apply_filters('bne_neighborhood_data_filtered', $data, $neighborhood_name, $filters);
}

/* ==========================================================================
   Auto-Generated City/Neighborhood Pages (v1.3.3)
   ========================================================================== */

/**
 * Get all cities with active listings from the database
 *
 * @param int $min_listings Minimum number of listings required
 * @return array Array of city data
 */
function bne_get_all_cities($min_listings = 1) {
    global $wpdb;

    $cache_key = 'bne_all_cities_' . $min_listings;
    $cities = get_transient($cache_key);

    if ($cities === false) {
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $cities = $wpdb->get_results($wpdb->prepare(
            "SELECT
                city as name,
                COUNT(*) as listing_count,
                ROUND(AVG(list_price), 0) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(latitude) as latitude,
                AVG(longitude) as longitude
            FROM {$summary_table}
            WHERE standard_status = 'Active'
                AND city IS NOT NULL
                AND city != ''
                AND list_price >= 50000
            GROUP BY city
            HAVING listing_count >= %d
            ORDER BY listing_count DESC",
            $min_listings
        ), ARRAY_A);

        if ($cities) {
            foreach ($cities as &$city) {
                $city['slug'] = sanitize_title($city['name']);
                $city['url'] = home_url('/' . $city['slug'] . '/');
                $city['rentals_url'] = home_url('/' . $city['slug'] . '/rentals/');
            }
            // Cache for 1 hour
            set_transient($cache_key, $cities, HOUR_IN_SECONDS);
        }
    }

    return $cities ?: array();
}

/**
 * Check if a city slug exists in the database
 *
 * @param string $slug City slug to check
 * @return string|false City name if exists, false otherwise
 */
function bne_city_exists($slug) {
    $cities = bne_get_all_cities();

    foreach ($cities as $city) {
        if ($city['slug'] === $slug) {
            return $city['name'];
        }
    }

    return false;
}

/**
 * Get all neighborhoods with active listings
 *
 * Neighborhoods are stored in mls_area_major field in wp_bme_listing_location
 *
 * @param int $min_listings Minimum number of listings required
 * @return array Array of neighborhood data
 */
function bne_get_all_neighborhoods($min_listings = 1) {
    global $wpdb;

    $cache_key = 'bne_all_neighborhoods_' . $min_listings;
    $neighborhoods = get_transient($cache_key);

    if ($neighborhoods === false) {
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $listings_table = $wpdb->prefix . 'bme_listings';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $neighborhoods = $wpdb->get_results($wpdb->prepare(
            "SELECT
                loc.mls_area_major as name,
                loc.city as city,
                COUNT(*) as listing_count,
                ROUND(AVG(s.list_price), 0) as avg_price,
                MIN(s.list_price) as min_price,
                MAX(s.list_price) as max_price,
                AVG(loc.latitude) as latitude,
                AVG(loc.longitude) as longitude
            FROM {$location_table} loc
            JOIN {$listings_table} li ON loc.listing_id = li.listing_id
            JOIN {$summary_table} s ON li.listing_id = s.listing_id
            WHERE li.standard_status = 'Active'
                AND loc.mls_area_major IS NOT NULL
                AND loc.mls_area_major != ''
                AND s.list_price >= 50000
            GROUP BY loc.mls_area_major, loc.city
            HAVING listing_count >= %d
            ORDER BY listing_count DESC",
            $min_listings
        ), ARRAY_A);

        if ($neighborhoods) {
            foreach ($neighborhoods as &$neighborhood) {
                $neighborhood['slug'] = sanitize_title($neighborhood['name']);
                $neighborhood['url'] = home_url('/' . $neighborhood['slug'] . '/');
                $neighborhood['rentals_url'] = home_url('/' . $neighborhood['slug'] . '/rentals/');
                $neighborhood['type'] = 'neighborhood';
            }
            // Cache for 1 hour
            set_transient($cache_key, $neighborhoods, HOUR_IN_SECONDS);
        }
    }

    return $neighborhoods ?: array();
}

/**
 * Check if a neighborhood slug exists in the database
 *
 * @param string $slug Neighborhood slug to check
 * @return array|false Neighborhood data array if exists, false otherwise
 */
function bne_neighborhood_exists($slug) {
    $neighborhoods = bne_get_all_neighborhoods();

    foreach ($neighborhoods as $neighborhood) {
        if ($neighborhood['slug'] === $slug) {
            return $neighborhood;
        }
    }

    return false;
}

/**
 * Check if a location slug exists (city or neighborhood)
 *
 * @param string $slug Location slug to check
 * @return array|false Location data if exists, false otherwise
 */
function bne_location_exists($slug) {
    // Check cities first (they're more common/important)
    $city_name = bne_city_exists($slug);
    if ($city_name !== false) {
        return array(
            'name' => $city_name,
            'slug' => $slug,
            'type' => 'city',
        );
    }

    // Check neighborhoods
    $neighborhood = bne_neighborhood_exists($slug);
    if ($neighborhood !== false) {
        return array(
            'name' => $neighborhood['name'],
            'slug' => $slug,
            'type' => 'neighborhood',
            'city' => $neighborhood['city'],
        );
    }

    return false;
}

/**
 * Add custom rewrite rules for auto-generated city/neighborhood pages
 *
 * URL Structure: /{location-slug}/ (e.g., /boston/, /back-bay/)
 * Uses 'top' priority - template_redirect checks for real pages first
 */
function bne_add_city_rewrite_rules() {
    // URL pattern: /{location-slug}/rentals/ - for rental listings
    // Excludes slugs with dots (e.g., robots.txt, sitemap.xml)
    add_rewrite_rule(
        '^([^/.]+)/rentals/?$',
        'index.php?bne_city=$matches[1]&bne_listing_type=lease',
        'top'
    );

    // URL pattern: /{location-slug}/ - for sale listings (default)
    // Excludes slugs with dots (e.g., robots.txt, sitemap.xml, favicon.ico)
    // This prevents matching file requests that should be handled by WordPress core
    add_rewrite_rule(
        '^([^/.]+)/?$',
        'index.php?bne_city=$matches[1]',
        'top'
    );
}
add_action('init', 'bne_add_city_rewrite_rules');

/**
 * Register custom query variables
 */
function bne_register_query_vars($vars) {
    $vars[] = 'bne_city';
    $vars[] = 'bne_listing_type';
    return $vars;
}
add_filter('query_vars', 'bne_register_query_vars');

/**
 * Load neighborhood template for city/neighborhood pages
 *
 * Priority 5 ensures this runs before WordPress 404 handling
 */
function bne_city_template_redirect() {
    $location_slug = get_query_var('bne_city');

    if (empty($location_slug)) {
        return;
    }

    // Skip files with extensions (e.g., robots.txt, sitemap.xml, favicon.ico)
    // These should be handled by WordPress core, not our city pages
    if (strpos($location_slug, '.') !== false) {
        return;
    }

    // Check if a real WordPress page exists with this slug
    $page = get_page_by_path($location_slug);
    if ($page) {
        // Page exists - check if it uses the neighborhood template
        $page_template = get_post_meta($page->ID, '_wp_page_template', true);
        if ($page_template === 'page-neighborhood.php') {
            // Set up location data for the template
            global $bne_current_city;
            $location = bne_location_exists($location_slug);
            if ($location) {
                $bne_current_city = array(
                    'slug' => $location_slug,
                    'name' => $location['name'],
                    'type' => $location['type'],
                    'city' => isset($location['city']) ? $location['city'] : $location['name'],
                    'listing_type' => get_query_var('bne_listing_type', 'sale'),
                );
            }

            // Fix the query to load the actual page
            global $wp_query;
            $wp_query = new WP_Query(array(
                'page_id' => $page->ID,
            ));

            // Load the template
            $template = locate_template('page-neighborhood.php');
            if ($template) {
                include $template;
                exit;
            }
        }

        // Page exists but doesn't use neighborhood template - properly load the page
        global $wp_query, $post;
        $wp_query = new WP_Query(array(
            'page_id' => $page->ID,
        ));
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        $wp_query->is_home = false;
        $post = $page;
        setup_postdata($post);

        // Load the appropriate template for this page
        $template = get_page_template();
        if ($template) {
            include $template;
            exit;
        }
        return;
    }

    // Check if a public post exists with this slug (only posts/pages that have front-end URLs)
    global $wpdb;
    $public_post_types = get_post_types(array('public' => true), 'names');
    $placeholders = implode(',', array_fill(0, count($public_post_types), '%s'));
    $query_args = array_merge(array($location_slug), array_values($public_post_types));

    $post_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ($placeholders) LIMIT 1",
        $query_args
    ));
    if ($post_exists) {
        // Post exists - properly load it
        global $wp_query, $post;
        $post = get_post($post_exists);
        $wp_query = new WP_Query(array(
            'p' => $post_exists,
            'post_type' => $post->post_type,
        ));
        $wp_query->is_single = ($post->post_type === 'post');
        $wp_query->is_singular = true;
        $wp_query->is_home = false;
        setup_postdata($post);

        // Load the appropriate template
        $template = get_single_template();
        if ($template) {
            include $template;
            exit;
        }
        return;
    }

    // Check if location exists (city or neighborhood)
    $location = bne_location_exists($location_slug);

    if ($location === false) {
        // Location doesn't exist, let WordPress handle 404
        return;
    }

    // Set up the location data for the template
    global $bne_current_city;
    $bne_current_city = array(
        'slug' => $location_slug,
        'name' => $location['name'],
        'type' => $location['type'],  // 'city' or 'neighborhood'
        'city' => isset($location['city']) ? $location['city'] : $location['name'],
        'listing_type' => get_query_var('bne_listing_type', 'sale'),
    );

    // Load the neighborhood template
    $template = locate_template('page-neighborhood.php');

    if ($template) {
        // Prevent WordPress from querying for posts
        global $wp_query;
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        $wp_query->is_singular = true;

        include $template;
        exit;
    }
}
add_action('template_redirect', 'bne_city_template_redirect', 5);

/**
 * Redirect /schools-guide/ to the actual schools guide page
 *
 * The schools guide page has a long URL (/massachusetts-school-districts-home-buying/)
 * This redirect provides a shorter, memorable URL for marketing purposes.
 *
 * @since 1.5.9
 */
function bne_schools_guide_redirect() {
    // Check if we're on /schools-guide/ path
    $request_uri = $_SERVER['REQUEST_URI'];

    if (preg_match('#^/schools-guide/?$#i', $request_uri)) {
        wp_redirect(home_url('/massachusetts-school-districts-home-buying/'), 301);
        exit;
    }
}
add_action('template_redirect', 'bne_schools_guide_redirect', 1);

/**
 * Prevent canonical redirect for special WordPress files (robots.txt, sitemap.xml, etc.)
 *
 * The catch-all city rewrite rule matches URLs like /robots.txt which causes
 * WordPress to add a trailing slash redirect. This filter prevents that redirect
 * for URLs that contain file extensions.
 *
 * @param string $redirect_url The redirect URL
 * @param string $requested_url The original requested URL
 * @return string|false The redirect URL or false to prevent redirect
 */
function bne_prevent_file_redirect($redirect_url, $requested_url) {
    $city_slug = get_query_var('bne_city');

    // If this looks like a file (has an extension), prevent the redirect
    if (!empty($city_slug) && strpos($city_slug, '.') !== false) {
        return false;
    }

    return $redirect_url;
}
add_filter('redirect_canonical', 'bne_prevent_file_redirect', 5, 2);

/**
 * Modify neighborhood template to use auto-generated city data
 */
function bne_get_current_city_slug() {
    global $bne_current_city;

    if (!empty($bne_current_city['slug'])) {
        return $bne_current_city['slug'];
    }

    // Fallback to query var
    $city_slug = get_query_var('bne_city');
    if (!empty($city_slug)) {
        return $city_slug;
    }

    // Fallback to page slug
    global $post;
    if ($post) {
        return $post->post_name;
    }

    return '';
}

/**
 * Get current city name for templates
 */
function bne_get_current_city_name() {
    global $bne_current_city;

    if (!empty($bne_current_city['name'])) {
        return $bne_current_city['name'];
    }

    $slug = bne_get_current_city_slug();
    if ($slug) {
        $city_name = bne_city_exists($slug);
        if ($city_name) {
            return $city_name;
        }
        // Convert slug to display name
        return ucwords(str_replace('-', ' ', $slug));
    }

    return '';
}

/**
 * Generate sitemap entries for all city pages
 * Integrates with popular SEO plugins (Yoast, Rank Math, etc.)
 */
function bne_add_city_pages_to_sitemap($url_list) {
    $cities = bne_get_all_cities(1);

    foreach ($cities as $city) {
        $url_list[] = array(
            'loc' => $city['url'],
            'lastmod' => current_time('c'),
            'changefreq' => 'daily',
            'priority' => 0.8,
        );
    }

    return $url_list;
}

/**
 * Add city pages to Yoast SEO sitemap
 */
function bne_yoast_sitemap_index($sitemaps) {
    $sitemaps[] = array(
        'loc' => home_url('/city-sitemap.xml'),
    );
    return $sitemaps;
}

/**
 * City sitemap XML generation
 * REMOVED in v1.3.8 - Now handled by MLD Plugin (class-mld-sitemap-generator.php)
 * The MLD plugin generates a comprehensive city sitemap including:
 * - SEO URLs: /homes-for-sale-in-boston-ma/
 * - Theme URLs: /boston/
 * - Rental URLs: /boston/rentals/
 */

/**
 * Flush rewrite rules on theme activation
 */
function bne_flush_rewrite_rules_on_activation() {
    bne_add_city_rewrite_rules();
    flush_rewrite_rules();
}
add_action('after_switch_theme', 'bne_flush_rewrite_rules_on_activation');

/**
 * Admin notice to flush rewrite rules after theme update
 */
function bne_maybe_flush_rewrite_rules() {
    $version_key = 'bne_theme_rewrite_version';
    $current_version = '1.3.5';  // Added neighborhood support via mls_area_major
    $stored_version = get_option($version_key);

    if ($stored_version !== $current_version) {
        flush_rewrite_rules();
        update_option($version_key, $current_version);
    }
}
add_action('admin_init', 'bne_maybe_flush_rewrite_rules');

/**
 * Add cities index page rewrite rule
 * Lists all available city pages at /cities/
 */
function bne_add_city_index_rewrite() {
    add_rewrite_rule(
        '^cities/?$',
        'index.php?bne_city_index=1',
        'top'
    );
}
add_action('init', 'bne_add_city_index_rewrite');

/**
 * Register city index query var
 */
function bne_register_city_index_var($vars) {
    $vars[] = 'bne_city_index';
    return $vars;
}
add_filter('query_vars', 'bne_register_city_index_var');

/* ==========================================================================
   Hero Section Customizer CSS Variables (v1.3.9)
   Outputs dynamic CSS variables for hero section customization
   ========================================================================== */

/**
 * Output hero section customizer CSS variables
 *
 * Generates inline CSS with CSS custom properties based on customizer values.
 * These variables are used in homepage.css for hero styling.
 *
 * @since 1.3.9
 */
function bne_hero_customizer_css() {
    // Only output on front page
    if (!is_front_page()) {
        return;
    }

    // Get customizer values with defaults
    $hero_min_height = get_theme_mod('bne_hero_min_height', 100);
    $hero_padding_top = get_theme_mod('bne_hero_padding_top', 0);
    $hero_padding_bottom = get_theme_mod('bne_hero_padding_bottom', 0);
    $hero_margin_top = get_theme_mod('bne_hero_margin_top', 0);
    $hero_margin_bottom = get_theme_mod('bne_hero_margin_bottom', 0);

    $hero_image_height_desktop = get_theme_mod('bne_hero_image_height_desktop', 100);
    $hero_image_height_mobile = get_theme_mod('bne_hero_image_height_mobile', 40);
    $hero_image_max_width = get_theme_mod('bne_hero_image_max_width', 50);
    $hero_image_object_fit = get_theme_mod('bne_hero_image_object_fit', 'contain');
    $hero_image_padding = get_theme_mod('bne_hero_image_padding', 0);
    $hero_image_margin = get_theme_mod('bne_hero_image_margin', 0);
    $hero_image_offset = get_theme_mod('bne_hero_image_offset', 0);

    $hero_name_font_size = get_theme_mod('bne_hero_name_font_size', 52);
    $hero_name_font_weight = get_theme_mod('bne_hero_name_font_weight', '400');
    $hero_name_letter_spacing = get_theme_mod('bne_hero_name_letter_spacing', 8);
    $hero_title_font_size = get_theme_mod('bne_hero_title_font_size', 14);
    $hero_license_font_size = get_theme_mod('bne_hero_license_font_size', 14);
    $hero_contact_font_size = get_theme_mod('bne_hero_contact_font_size', 14);

    $hero_content_max_width = get_theme_mod('bne_hero_content_max_width', 500);
    $hero_content_padding = get_theme_mod('bne_hero_content_padding', 32);

    $hero_search_max_width = get_theme_mod('bne_hero_search_max_width', 100);
    $hero_search_padding = get_theme_mod('bne_hero_search_padding', 32);
    $hero_search_border_radius = get_theme_mod('bne_hero_search_border_radius', 12);

    // Calculate letter spacing (convert 0-20 to 0em-0.2em)
    $letter_spacing_em = $hero_name_letter_spacing / 100;

    ?>
    <style id="bne-hero-customizer-css">
        :root {
            /* Hero Section Layout */
            --bne-hero-min-height: <?php echo intval($hero_min_height); ?>vh;
            --bne-hero-padding-top: <?php echo intval($hero_padding_top); ?>px;
            --bne-hero-padding-bottom: <?php echo intval($hero_padding_bottom); ?>px;
            --bne-hero-margin-top: <?php echo intval($hero_margin_top); ?>px;
            --bne-hero-margin-bottom: <?php echo intval($hero_margin_bottom); ?>px;

            /* Hero Image */
            --bne-hero-image-height-desktop: <?php echo intval($hero_image_height_desktop); ?>vh;
            --bne-hero-image-height-mobile: <?php echo intval($hero_image_height_mobile); ?>vh;
            --bne-hero-image-max-width: <?php echo intval($hero_image_max_width); ?>%;
            --bne-hero-image-object-fit: <?php echo esc_attr($hero_image_object_fit); ?>;
            --bne-hero-image-padding: <?php echo intval($hero_image_padding); ?>px;
            --bne-hero-image-margin: <?php echo intval($hero_image_margin); ?>px;
            --bne-hero-image-offset: <?php echo intval($hero_image_offset); ?>px;

            /* Hero Typography */
            --bne-hero-name-font-size: <?php echo intval($hero_name_font_size); ?>px;
            --bne-hero-name-font-weight: <?php echo esc_attr($hero_name_font_weight); ?>;
            --bne-hero-name-letter-spacing: <?php echo number_format($letter_spacing_em, 2); ?>em;
            --bne-hero-title-font-size: <?php echo intval($hero_title_font_size); ?>px;
            --bne-hero-license-font-size: <?php echo intval($hero_license_font_size); ?>px;
            --bne-hero-contact-font-size: <?php echo intval($hero_contact_font_size); ?>px;

            /* Hero Content Area */
            --bne-hero-content-max-width: <?php echo intval($hero_content_max_width); ?>px;
            --bne-hero-content-padding: <?php echo intval($hero_content_padding); ?>px;

            /* Hero Search Form */
            --bne-hero-search-max-width: <?php echo intval($hero_search_max_width); ?>%;
            --bne-hero-search-padding: <?php echo intval($hero_search_padding); ?>px;
            --bne-hero-search-border-radius: <?php echo intval($hero_search_border_radius); ?>px;
        }
    </style>
    <?php
}
add_action('wp_head', 'bne_hero_customizer_css', 50);

/**
 * Enqueue customizer preview JavaScript
 *
 * Loads the live preview script that handles instant updates
 * when customizer settings are changed.
 *
 * @since 1.3.9
 */
function bne_customizer_preview_scripts() {
    $script_path = BNE_THEME_DIR . '/assets/js/customizer-preview.js';
    $version = file_exists($script_path) ? filemtime($script_path) : BNE_THEME_VERSION;

    wp_enqueue_script(
        'bne-customizer-preview',
        BNE_THEME_URI . '/assets/js/customizer-preview.js',
        array('customize-preview', 'jquery'),
        $version,
        true
    );
}
add_action('customize_preview_init', 'bne_customizer_preview_scripts');

/**
 * Output Open Graph meta tags for homepage
 *
 * Uses the hero image (agent photo) from customizer settings as the
 * og:image for better social media link previews.
 *
 * @since 1.4.2
 */
function bne_homepage_open_graph_tags() {
    // Only on front page
    if (!is_front_page()) {
        return;
    }

    // Get customizer values
    $agent_photo = get_theme_mod('bne_agent_photo', '');
    $agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
    $site_name = get_bloginfo('name');
    $site_description = get_bloginfo('description');
    $home_url = home_url('/');

    // Build title and description
    $og_title = $agent_name . ' - ' . $site_name;
    $og_description = !empty($site_description) ? $site_description : sprintf('%s - Real Estate Agent', $agent_name);

    ?>
    <!-- BNE Homepage Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo esc_attr($og_title); ?>">
    <meta property="og:description" content="<?php echo esc_attr($og_description); ?>">
    <meta property="og:url" content="<?php echo esc_url($home_url); ?>">
    <meta property="og:site_name" content="<?php echo esc_attr($site_name); ?>">
    <?php if (!empty($agent_photo)) : ?>
    <meta property="og:image" content="<?php echo esc_url($agent_photo); ?>">
    <meta property="og:image:alt" content="<?php echo esc_attr($agent_name); ?>">
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($og_title); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr($og_description); ?>">
    <?php if (!empty($agent_photo)) : ?>
    <meta name="twitter:image" content="<?php echo esc_url($agent_photo); ?>">
    <?php endif; ?>
    <!-- /BNE Homepage Open Graph -->
    <?php
}
add_action('wp_head', 'bne_homepage_open_graph_tags', 5);

/**
 * Remove entry meta (date/author) from pages
 *
 * GeneratePress displays the date and author byline on pages by default.
 * This removes that meta information from pages while keeping it on posts.
 *
 * @since 1.4.8
 */
function bne_remove_page_entry_meta() {
    // Only remove on pages (not posts or custom post types)
    if (is_page()) {
        // Remove the entire entry meta section
        remove_action('generate_after_entry_title', 'generate_post_meta');

        // Also remove individual meta elements in case theme uses them separately
        remove_action('generate_after_entry_title', 'generate_posted_on');
        remove_action('generate_after_entry_title', 'generate_post_date');
    }
}
add_action('wp', 'bne_remove_page_entry_meta');

/* ==========================================================================
   White Label WordPress Admin (v1.5.4)
   Removes all WordPress branding from admin interface
   ========================================================================== */

/**
 * Remove WordPress logo and menu from admin bar
 *
 * Removes the "W" logo dropdown with "About WordPress", "WordPress.org",
 * "Documentation", "Support", and "Feedback" links.
 *
 * @since 1.5.4
 */
function bne_remove_wp_logo_from_admin_bar($wp_admin_bar) {
    // Remove WordPress logo and all its children
    $wp_admin_bar->remove_node('wp-logo');
}
add_action('admin_bar_menu', 'bne_remove_wp_logo_from_admin_bar', 999);

/**
 * Remove WordPress dashboard widgets
 *
 * Removes default WordPress widgets like:
 * - At a Glance
 * - WordPress Events and News
 * - Quick Draft
 * - Activity
 * - Welcome Panel
 *
 * @since 1.5.4
 */
function bne_remove_wp_dashboard_widgets() {
    global $wp_meta_boxes;

    // Remove WordPress News/Events widget
    remove_meta_box('dashboard_primary', 'dashboard', 'side');

    // Remove Quick Draft widget
    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');

    // Remove At a Glance widget
    remove_meta_box('dashboard_right_now', 'dashboard', 'normal');

    // Remove Activity widget
    remove_meta_box('dashboard_activity', 'dashboard', 'normal');

    // Remove incoming links widget (deprecated but may still exist)
    remove_meta_box('dashboard_incoming_links', 'dashboard', 'normal');

    // Remove plugins widget
    remove_meta_box('dashboard_plugins', 'dashboard', 'normal');

    // Remove secondary widget area
    remove_meta_box('dashboard_secondary', 'dashboard', 'side');

    // Remove Site Health Status
    remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
}
add_action('wp_dashboard_setup', 'bne_remove_wp_dashboard_widgets');

/**
 * Add custom BMN platform dashboard widgets
 *
 * @since 1.5.5
 */
function bne_add_dashboard_widgets() {
    // Platform Overview - main stats
    wp_add_dashboard_widget(
        'bne_platform_overview',
        'Platform Overview',
        'bne_platform_overview_widget'
    );

    // Agents & Clients
    wp_add_dashboard_widget(
        'bne_agents_clients',
        'Agents & Clients',
        'bne_agents_clients_widget'
    );

    // Upcoming Appointments
    wp_add_dashboard_widget(
        'bne_appointments',
        'Upcoming Appointments',
        'bne_appointments_widget'
    );

    // Team/Staff
    wp_add_dashboard_widget(
        'bne_team',
        'Team Members',
        'bne_team_widget'
    );

    // Recent Activity
    wp_add_dashboard_widget(
        'bne_activity',
        'Recent Activity',
        'bne_activity_widget'
    );
}
add_action('wp_dashboard_setup', 'bne_add_dashboard_widgets');

/**
 * Platform Overview Widget
 * Shows key metrics: listings, users, searches, favorites
 *
 * @since 1.5.5
 */
function bne_platform_overview_widget() {
    global $wpdb;

    // Get stats
    $active_listings = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary WHERE standard_status = 'Active'");
    $total_users = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_user_types");
    $saved_searches = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_saved_searches");
    $favorites = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_favorites");
    $activity_24h = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_client_activity WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    ?>
    <style>
        .bne-dashboard-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; }
        .bne-stat-card { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; padding: 15px; text-align: center; border-left: 4px solid #4a60a1; }
        .bne-stat-number { font-size: 28px; font-weight: 700; color: #1a1a2e; line-height: 1.2; }
        .bne-stat-label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; }
        .bne-stat-card.highlight { border-left-color: #28a745; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); }
        .bne-stat-card.warning { border-left-color: #ffc107; }
    </style>
    <div class="bne-dashboard-stats">
        <div class="bne-stat-card highlight">
            <div class="bne-stat-number"><?php echo number_format($active_listings); ?></div>
            <div class="bne-stat-label">Active Listings</div>
        </div>
        <div class="bne-stat-card">
            <div class="bne-stat-number"><?php echo number_format($total_users); ?></div>
            <div class="bne-stat-label">Platform Users</div>
        </div>
        <div class="bne-stat-card">
            <div class="bne-stat-number"><?php echo number_format($saved_searches); ?></div>
            <div class="bne-stat-label">Saved Searches</div>
        </div>
        <div class="bne-stat-card">
            <div class="bne-stat-number"><?php echo number_format($favorites); ?></div>
            <div class="bne-stat-label">Favorites</div>
        </div>
        <div class="bne-stat-card">
            <div class="bne-stat-number"><?php echo number_format($activity_24h); ?></div>
            <div class="bne-stat-label">Activity (24h)</div>
        </div>
    </div>
    <?php
}

/**
 * Agents & Clients Widget
 * Shows agent profiles and their clients
 *
 * @since 1.5.5
 */
function bne_agents_clients_widget() {
    global $wpdb;

    $agents = $wpdb->get_results("
        SELECT
            ap.id,
            ap.user_id,
            ap.display_name,
            ap.email,
            ap.photo_url,
            (SELECT COUNT(*) FROM {$wpdb->prefix}mld_agent_client_relationships acr WHERE acr.agent_id = ap.user_id AND acr.status = 'active') as client_count
        FROM {$wpdb->prefix}mld_agent_profiles ap
        ORDER BY ap.display_name
        LIMIT 10
    ");

    $total_clients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_agent_client_relationships WHERE status = 'active'");

    ?>
    <style>
        .bne-agents-list { margin: 0; padding: 0; list-style: none; }
        .bne-agent-item { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
        .bne-agent-item:last-child { border-bottom: none; }
        .bne-agent-avatar { width: 40px; height: 40px; border-radius: 50%; margin-right: 12px; object-fit: cover; background: #e9ecef; }
        .bne-agent-info { flex: 1; }
        .bne-agent-name { font-weight: 600; color: #1a1a2e; margin: 0; }
        .bne-agent-email { font-size: 12px; color: #666; }
        .bne-agent-clients { background: #4a60a1; color: #fff; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .bne-summary-row { display: flex; justify-content: space-between; padding: 10px 0; border-top: 2px solid #eee; margin-top: 10px; font-weight: 600; }
    </style>
    <ul class="bne-agents-list">
        <?php foreach ($agents as $agent) :
            $avatar = !empty($agent->photo_url) ? $agent->photo_url : get_avatar_url($agent->user_id, array('size' => 40));
        ?>
        <li class="bne-agent-item">
            <img src="<?php echo esc_url($avatar); ?>" alt="" class="bne-agent-avatar">
            <div class="bne-agent-info">
                <p class="bne-agent-name"><?php echo esc_html($agent->display_name); ?></p>
                <span class="bne-agent-email"><?php echo esc_html($agent->email); ?></span>
            </div>
            <span class="bne-agent-clients"><?php echo intval($agent->client_count); ?> clients</span>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="bne-summary-row">
        <span>Total Agents: <?php echo count($agents); ?></span>
        <span>Total Clients: <?php echo intval($total_clients); ?></span>
    </div>
    <?php
}

/**
 * Appointments Widget
 * Shows upcoming appointments
 *
 * @since 1.5.5
 */
function bne_appointments_widget() {
    global $wpdb;

    $upcoming = $wpdb->get_results("
        SELECT
            a.id,
            a.client_name,
            a.client_email,
            a.start_time,
            a.end_time,
            a.status,
            at.name as type_name,
            s.name as staff_name
        FROM {$wpdb->prefix}snab_appointments a
        LEFT JOIN {$wpdb->prefix}snab_appointment_types at ON a.appointment_type_id = at.id
        LEFT JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
        WHERE a.start_time >= NOW() AND a.status IN ('confirmed', 'pending')
        ORDER BY a.start_time ASC
        LIMIT 5
    ");

    $today_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snab_appointments WHERE DATE(start_time) = CURDATE() AND status IN ('confirmed', 'pending')");
    $week_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snab_appointments WHERE start_time >= NOW() AND start_time <= DATE_ADD(NOW(), INTERVAL 7 DAY) AND status IN ('confirmed', 'pending')");

    ?>
    <style>
        .bne-appt-list { margin: 0; padding: 0; list-style: none; }
        .bne-appt-item { padding: 12px 0; border-bottom: 1px solid #eee; }
        .bne-appt-item:last-child { border-bottom: none; }
        .bne-appt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .bne-appt-client { font-weight: 600; color: #1a1a2e; }
        .bne-appt-status { font-size: 11px; padding: 3px 8px; border-radius: 10px; text-transform: uppercase; }
        .bne-appt-status.confirmed { background: #d4edda; color: #155724; }
        .bne-appt-status.pending { background: #fff3cd; color: #856404; }
        .bne-appt-details { font-size: 13px; color: #666; }
        .bne-appt-time { color: #4a60a1; font-weight: 500; }
        .bne-appt-stats { display: flex; gap: 20px; padding: 12px 0; border-top: 2px solid #eee; margin-top: 10px; }
        .bne-appt-stat { text-align: center; flex: 1; }
        .bne-appt-stat-num { font-size: 24px; font-weight: 700; color: #4a60a1; }
        .bne-appt-stat-label { font-size: 11px; color: #666; text-transform: uppercase; }
        .bne-no-items { color: #666; font-style: italic; padding: 20px 0; text-align: center; }
    </style>

    <?php if (empty($upcoming)) : ?>
        <p class="bne-no-items">No upcoming appointments</p>
    <?php else : ?>
        <ul class="bne-appt-list">
            <?php foreach ($upcoming as $appt) : ?>
            <li class="bne-appt-item">
                <div class="bne-appt-header">
                    <span class="bne-appt-client"><?php echo esc_html($appt->client_name); ?></span>
                    <span class="bne-appt-status <?php echo esc_attr($appt->status); ?>"><?php echo esc_html($appt->status); ?></span>
                </div>
                <div class="bne-appt-details">
                    <span class="bne-appt-time"><?php
                        $appt_date = new DateTime($appt->start_time, wp_timezone());
                        echo wp_date('M j, g:i A', $appt_date->getTimestamp());
                    ?></span>
                    &bull; <?php echo esc_html($appt->type_name); ?>
                    <?php if ($appt->staff_name) : ?>&bull; <?php echo esc_html($appt->staff_name); ?><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="bne-appt-stats">
        <div class="bne-appt-stat">
            <div class="bne-appt-stat-num"><?php echo intval($today_count); ?></div>
            <div class="bne-appt-stat-label">Today</div>
        </div>
        <div class="bne-appt-stat">
            <div class="bne-appt-stat-num"><?php echo intval($week_count); ?></div>
            <div class="bne-appt-stat-label">This Week</div>
        </div>
    </div>
    <p style="margin: 10px 0 0; text-align: center;">
        <a href="<?php echo admin_url('admin.php?page=snab-appointments'); ?>" class="button button-primary">View All Appointments</a>
    </p>
    <?php
}

/**
 * Team Members Widget
 * Shows active staff members
 *
 * @since 1.5.5
 */
function bne_team_widget() {
    global $wpdb;

    $staff = $wpdb->get_results("
        SELECT
            s.id,
            s.name,
            s.email,
            s.photo_url,
            s.is_active,
            (SELECT COUNT(*) FROM {$wpdb->prefix}snab_appointments a WHERE a.staff_id = s.id AND a.start_time >= NOW() AND a.status = 'confirmed') as upcoming_appts
        FROM {$wpdb->prefix}snab_staff s
        WHERE s.is_active = 1
        ORDER BY s.name
    ");

    ?>
    <style>
        .bne-team-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .bne-team-card { background: #f8f9fa; border-radius: 8px; padding: 15px; text-align: center; border: 1px solid #e9ecef; }
        .bne-team-photo { width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 10px; object-fit: cover; background: #dee2e6; }
        .bne-team-name { font-weight: 600; color: #1a1a2e; margin: 0 0 5px; }
        .bne-team-email { font-size: 12px; color: #666; word-break: break-all; }
        .bne-team-badge { display: inline-block; background: #4a60a1; color: #fff; padding: 3px 10px; border-radius: 10px; font-size: 11px; margin-top: 8px; }
    </style>
    <div class="bne-team-grid">
        <?php foreach ($staff as $member) :
            $photo = !empty($member->photo_url) ? $member->photo_url : 'https://www.gravatar.com/avatar/?d=mp&s=60';
        ?>
        <div class="bne-team-card">
            <img src="<?php echo esc_url($photo); ?>" alt="" class="bne-team-photo">
            <p class="bne-team-name"><?php echo esc_html($member->name); ?></p>
            <span class="bne-team-email"><?php echo esc_html($member->email); ?></span>
            <?php if ($member->upcoming_appts > 0) : ?>
                <span class="bne-team-badge"><?php echo intval($member->upcoming_appts); ?> upcoming</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p style="margin: 15px 0 0; text-align: center;">
        <a href="<?php echo admin_url('admin.php?page=snab-staff'); ?>" class="button">Manage Team</a>
    </p>
    <?php
}

/**
 * Recent Activity Widget
 * Shows recent platform activity
 *
 * @since 1.5.5
 */
function bne_activity_widget() {
    global $wpdb;

    // Get recent activity
    $activities = $wpdb->get_results("
        SELECT
            ca.id,
            ca.user_id,
            ca.activity_type,
            ca.listing_id,
            ca.created_at,
            u.display_name as user_name
        FROM {$wpdb->prefix}mld_client_activity ca
        LEFT JOIN {$wpdb->users} u ON ca.user_id = u.ID
        ORDER BY ca.created_at DESC
        LIMIT 10
    ");

    // Activity type labels and icons
    $activity_labels = array(
        'property_view' => array('label' => 'Viewed property', 'icon' => '👁'),
        'favorite_add' => array('label' => 'Added to favorites', 'icon' => '❤️'),
        'favorite_remove' => array('label' => 'Removed from favorites', 'icon' => '💔'),
        'search' => array('label' => 'Searched', 'icon' => '🔍'),
        'share' => array('label' => 'Shared property', 'icon' => '📤'),
        'contact' => array('label' => 'Contacted agent', 'icon' => '📧'),
    );

    ?>
    <style>
        .bne-activity-list { margin: 0; padding: 0; list-style: none; max-height: 300px; overflow-y: auto; }
        .bne-activity-item { display: flex; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .bne-activity-item:last-child { border-bottom: none; }
        .bne-activity-icon { width: 24px; text-align: center; margin-right: 10px; }
        .bne-activity-content { flex: 1; }
        .bne-activity-user { font-weight: 600; color: #1a1a2e; }
        .bne-activity-action { color: #666; }
        .bne-activity-time { font-size: 11px; color: #999; margin-top: 2px; }
        .bne-activity-listing { color: #4a60a1; text-decoration: none; }
        .bne-activity-listing:hover { text-decoration: underline; }
    </style>

    <?php if (empty($activities)) : ?>
        <p class="bne-no-items">No recent activity</p>
    <?php else : ?>
        <ul class="bne-activity-list">
            <?php foreach ($activities as $activity) :
                $info = isset($activity_labels[$activity->activity_type])
                    ? $activity_labels[$activity->activity_type]
                    : array('label' => $activity->activity_type, 'icon' => '📌');
                $user_name = $activity->user_name ?: 'Anonymous';
                $activity_date = new DateTime($activity->created_at, wp_timezone());
                $time_ago = human_time_diff($activity_date->getTimestamp(), current_time('timestamp')) . ' ago';
            ?>
            <li class="bne-activity-item">
                <span class="bne-activity-icon"><?php echo $info['icon']; ?></span>
                <div class="bne-activity-content">
                    <span class="bne-activity-user"><?php echo esc_html($user_name); ?></span>
                    <span class="bne-activity-action"><?php echo esc_html($info['label']); ?></span>
                    <?php if ($activity->listing_id) : ?>
                        <a href="<?php echo home_url('/property/' . $activity->listing_id . '/'); ?>" class="bne-activity-listing" target="_blank">#<?php echo esc_html($activity->listing_id); ?></a>
                    <?php endif; ?>
                    <div class="bne-activity-time"><?php echo esc_html($time_ago); ?></div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
}

/**
 * Remove WordPress welcome panel
 *
 * @since 1.5.4
 */
function bne_remove_welcome_panel() {
    remove_action('welcome_panel', 'wp_welcome_panel');
}
add_action('admin_init', 'bne_remove_welcome_panel');

/**
 * Disable welcome panel for all users
 *
 * @since 1.5.4
 */
function bne_disable_welcome_panel_for_all() {
    if (get_user_meta(get_current_user_id(), 'show_welcome_panel', true) == 1) {
        update_user_meta(get_current_user_id(), 'show_welcome_panel', 0);
    }
}
add_action('admin_init', 'bne_disable_welcome_panel_for_all');

/**
 * Remove WordPress footer text in admin
 *
 * Replaces "Thank you for creating with WordPress" with custom branding.
 * Uses priority 999 to override Kinsta's footer filter.
 *
 * @since 1.5.4
 */
function bne_admin_footer_text() {
    return '<span id="footer-thankyou">BMN Boston Real Estate Platform</span>';
}
add_filter('admin_footer_text', 'bne_admin_footer_text', 999);

/**
 * Remove WordPress version from admin footer
 *
 * @since 1.5.4
 */
function bne_remove_wp_version_footer() {
    return '';
}
add_filter('update_footer', 'bne_remove_wp_version_footer', 999);

/**
 * Custom admin CSS to hide WordPress branding
 *
 * Hides any remaining WordPress logos, icons, and branding elements.
 * Replaces with site favicon where appropriate.
 *
 * @since 1.5.4
 */
function bne_admin_white_label_css() {
    $site_icon_url = get_site_icon_url(32);
    if (empty($site_icon_url)) {
        $site_icon_url = 'https://bmnboston.com/wp-content/uploads/2025/12/cropped-BMN-icon-32x32.png';
    }
    ?>
    <style type="text/css">
        /* Hide WordPress logo in admin bar (backup if menu removal fails) */
        #wpadminbar #wp-admin-bar-wp-logo {
            display: none !important;
        }

        /* Hide WordPress logo in admin menu collapse button */
        #adminmenu .wp-menu-image.dashicons-wordpress,
        #adminmenu .wp-menu-image.dashicons-wordpress-alt {
            display: none !important;
        }

        /* Replace site icon in admin bar with custom favicon */
        #wpadminbar #wp-admin-bar-site-name > .ab-item:before {
            background-image: url('<?php echo esc_url($site_icon_url); ?>') !important;
            background-size: 20px 20px !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            content: '' !important;
            width: 20px !important;
            height: 20px !important;
            display: inline-block !important;
            vertical-align: middle !important;
            margin-right: 6px !important;
        }

        /* Hide WordPress logo in update notices */
        .update-nag .dashicons-wordpress,
        .updated .dashicons-wordpress {
            display: none !important;
        }

        /* Hide WordPress logo in About page */
        .about-wrap .wp-badge {
            display: none !important;
        }

        /* Hide "WordPress" text in page titles */
        .wrap h1:contains('WordPress') {
            /* Note: :contains is not standard CSS, handled via JS if needed */
        }

        /* Style the collapse button without WP icon */
        #collapse-button {
            background: none !important;
        }

        #collapse-button .collapse-button-icon:before {
            content: '\f148' !important; /* Dashicons left arrow */
        }

        .folded #collapse-button .collapse-button-icon:before {
            content: '\f139' !important; /* Dashicons right arrow */
        }

        /* Hide WordPress.org links in plugin/theme screens */
        .plugin-card .column-description .plugin-card-bottom .column-compatibility {
            /* Keep compatibility info but could hide WP.org stuff */
        }

        /* Customize admin bar appearance */
        #wpadminbar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%) !important;
        }

        #wpadminbar .ab-top-menu > li > .ab-item,
        #wpadminbar .quicklinks .ab-top-menu > li > .ab-item {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        #wpadminbar .ab-top-menu > li:hover > .ab-item,
        #wpadminbar .quicklinks .ab-top-menu > li:hover > .ab-item {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #ffffff !important;
        }

        /* Admin menu styling */
        #adminmenuback,
        #adminmenuwrap,
        #adminmenu {
            background: #1a1a2e !important;
        }

        #adminmenu a {
            color: rgba(255, 255, 255, 0.85) !important;
        }

        #adminmenu .wp-has-current-submenu .wp-submenu,
        #adminmenu .wp-has-current-submenu.opensub .wp-submenu {
            background: #16213e !important;
        }

        #adminmenu li.current a.menu-top,
        #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu {
            background: linear-gradient(135deg, #4a60a1 0%, #3a4d8a 100%) !important;
        }
    </style>
    <?php
}
add_action('admin_head', 'bne_admin_white_label_css');

/**
 * Remove WordPress references from admin page titles
 *
 * @since 1.5.4
 */
function bne_admin_title($admin_title, $title) {
    // Replace "WordPress" with site name in admin titles
    return str_replace(' ‹ WordPress', '', $admin_title);
}
add_filter('admin_title', 'bne_admin_title', 10, 2);

/**
 * Remove WordPress Help tabs
 *
 * Removes the Help tab from admin screens that contains WordPress documentation links.
 *
 * @since 1.5.4
 */
function bne_remove_wp_help_tabs() {
    $screen = get_current_screen();
    if ($screen) {
        $screen->remove_help_tabs();
    }
}
add_action('admin_head', 'bne_remove_wp_help_tabs');

/**
 * Hide WordPress update notices for non-admins
 *
 * Only shows WordPress core update notices to super admins.
 *
 * @since 1.5.4
 */
function bne_hide_update_notices() {
    if (!current_user_can('update_core')) {
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag', 10);
    }
}
add_action('admin_head', 'bne_hide_update_notices');

/**
 * Customize the "Howdy" greeting in admin bar
 *
 * @since 1.5.4
 */
function bne_admin_bar_greeting($wp_admin_bar) {
    $my_account = $wp_admin_bar->get_node('my-account');
    if ($my_account) {
        $current_user = wp_get_current_user();
        $display_name = $current_user->display_name;

        // Replace "Howdy, Name" with just the name
        $wp_admin_bar->add_node(array(
            'id'    => 'my-account',
            'title' => $display_name . $my_account->meta['html'],
            'href'  => $my_account->href,
            'meta'  => $my_account->meta,
        ));
    }
}
add_action('admin_bar_menu', 'bne_admin_bar_greeting', 11);

/**
 * Remove WordPress version meta tag from frontend
 *
 * @since 1.5.4
 */
remove_action('wp_head', 'wp_generator');

/**
 * Remove "Powered by WordPress" from RSS feeds
 *
 * @since 1.5.4
 */
function bne_remove_wp_from_rss() {
    return 'BMN Boston Real Estate';
}
add_filter('the_generator', 'bne_remove_wp_from_rss');
