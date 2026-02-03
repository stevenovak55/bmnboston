<?php
/**
 * MLS Listings Display - Standalone CMA Pages
 *
 * Handles URL routing and template loading for standalone CMA pages.
 * Allows users to create CMAs by manually entering property details
 * without needing an existing MLS listing.
 *
 * URL Patterns:
 * - /cma/              → New CMA entry page (shows modal)
 * - /cma/{slug}/       → View existing standalone CMA
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 6.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Standalone_CMA_Pages {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Device detector instance
     */
    private $device_detector;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'add_rewrite_rules'), 1);
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('template_include', array($this, 'template_include'), 998);
        add_filter('request', array($this, 'handle_cma_request'), 1);
        add_action('parse_request', array($this, 'parse_cma_request'), 1);
        add_action('pre_get_posts', array($this, 'pre_get_posts'), 1);

        // Initialize device detector
        add_action('init', array($this, 'init_device_detector'), 2);

        // Enqueue assets for standalone CMA pages
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add body class for standalone CMA pages
        add_filter('body_class', array($this, 'add_body_class'));
    }

    /**
     * Initialize device detector
     */
    public function init_device_detector() {
        if (class_exists('MLD_Device_Detector')) {
            $this->device_detector = MLD_Device_Detector::get_instance();
        }
    }

    /**
     * Add rewrite rules for standalone CMA pages
     */
    public function add_rewrite_rules() {
        // Route: /cma/{slug}/ - View existing standalone CMA
        add_rewrite_rule(
            '^cma/([a-zA-Z0-9-]+)/?$',
            'index.php?mld_standalone_cma=1&cma_slug=$matches[1]',
            'top'
        );

        // Route: /cma/ - New CMA entry page
        add_rewrite_rule(
            '^cma/?$',
            'index.php?mld_standalone_cma=1&cma_new=1',
            'top'
        );
    }

    /**
     * Add custom query variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'mld_standalone_cma';
        $vars[] = 'cma_slug';
        $vars[] = 'cma_new';
        return $vars;
    }

    /**
     * Handle CMA requests early in the request cycle
     */
    public function handle_cma_request($query_vars) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Remove query string for matching
        $request_path = parse_url($request_uri, PHP_URL_PATH);

        // Check if this is a CMA URL with slug
        if (preg_match('#^/cma/([a-zA-Z0-9-]+)/?$#', $request_path, $matches)) {
            $slug = $matches[1];

            if (!isset($query_vars['mld_standalone_cma'])) {
                $query_vars['mld_standalone_cma'] = '1';
                $query_vars['cma_slug'] = $slug;

                // Clear other query vars that might interfere
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Standalone CMA] Request filtered for slug: ' . $slug);
                }
            }
        }
        // Check if this is the new CMA entry URL
        elseif (preg_match('#^/cma/?$#', $request_path)) {
            if (!isset($query_vars['mld_standalone_cma'])) {
                $query_vars['mld_standalone_cma'] = '1';
                $query_vars['cma_new'] = '1';

                // Clear other query vars that might interfere
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Standalone CMA] New CMA entry page request');
                }
            }
        }

        return $query_vars;
    }

    /**
     * Parse CMA requests to ensure they're handled correctly
     */
    public function parse_cma_request($wp) {
        if (isset($wp->query_vars['mld_standalone_cma']) && $wp->query_vars['mld_standalone_cma'] == '1') {
            // Force WordPress to recognize this as a valid request
            $wp->query_vars['error'] = '';
            $wp->query_vars['pagename'] = '';
            $wp->query_vars['page'] = '';
            $wp->query_vars['name'] = '';

            // Mark as found to prevent 404
            global $wp_query;
            if ($wp_query) {
                $wp_query->is_404 = false;
                $wp_query->is_page = false;
                $wp_query->is_single = false;
                $wp_query->is_singular = true;
            }
        }
    }

    /**
     * Handle pre_get_posts for CMA pages
     */
    public function pre_get_posts($query) {
        if (!$query->is_main_query()) {
            return;
        }

        $mld_standalone_cma = get_query_var('mld_standalone_cma');

        if ($mld_standalone_cma == '1') {
            // Prevent WordPress from treating this as a 404
            $query->is_404 = false;
            $query->is_singular = true;
            $query->is_page = false;
            $query->is_single = false;
        }
    }

    /**
     * Load the appropriate template for standalone CMA pages
     */
    public function template_include($template) {
        $mld_standalone_cma = get_query_var('mld_standalone_cma');

        if ($mld_standalone_cma != '1') {
            return $template;
        }

        $cma_slug = get_query_var('cma_slug');
        $cma_new = get_query_var('cma_new');

        // Check if this is a new CMA or existing one
        if ($cma_new == '1') {
            // New CMA - load the entry template
            $new_template = MLD_PLUGIN_PATH . 'templates/standalone-cma-new.php';

            if (file_exists($new_template)) {
                // Set page title
                add_filter('pre_get_document_title', function() {
                    return 'Create Standalone CMA | ' . get_bloginfo('name');
                });

                return $new_template;
            }
        } elseif (!empty($cma_slug)) {
            // Existing CMA - load the view template
            $session = MLD_CMA_Sessions::get_session_by_slug($cma_slug);

            if ($session) {
                // Store session data for the template
                set_query_var('cma_session', $session);

                // Set page title based on session data
                $address = $session['subject_property_data']['address'] ?? 'Standalone CMA';
                add_filter('pre_get_document_title', function() use ($address) {
                    return 'CMA: ' . $address . ' | ' . get_bloginfo('name');
                });

                $view_template = MLD_PLUGIN_PATH . 'templates/standalone-cma.php';

                if (file_exists($view_template)) {
                    return $view_template;
                }
            } else {
                // Session not found - show 404
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return get_404_template();
            }
        }

        return $template;
    }

    /**
     * Enqueue assets for standalone CMA pages
     */
    public function enqueue_assets() {
        $mld_standalone_cma = get_query_var('mld_standalone_cma');

        if ($mld_standalone_cma != '1') {
            return;
        }

        // Enqueue standalone CMA CSS
        $css_file = MLD_PLUGIN_PATH . 'assets/css/standalone-cma.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'mld-standalone-cma',
                MLD_PLUGIN_URL . 'assets/css/standalone-cma.css',
                array(),
                MLD_VERSION
            );
        }

        // Enqueue property-analytics CSS for CMA features (weight controls, etc.) - v6.20.1
        // This CSS is normally only loaded on property pages, but standalone CMA needs it too
        wp_enqueue_style(
            'mld-design-tokens',
            MLD_PLUGIN_URL . 'assets/css/design-tokens.css',
            array(),
            MLD_VERSION
        );

        wp_enqueue_style(
            'mld-property-analytics',
            MLD_PLUGIN_URL . 'assets/css/property-analytics.css',
            array('mld-design-tokens'),
            MLD_VERSION
        );

        // Enqueue standalone CMA JavaScript
        $js_file = MLD_PLUGIN_PATH . 'assets/js/mld-standalone-cma.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'mld-standalone-cma',
                MLD_PLUGIN_URL . 'assets/js/mld-standalone-cma.js',
                array('jquery'),
                MLD_VERSION,
                true
            );

            // Get Google Maps API key using the proper method
            $google_maps_key = '';
            if (class_exists('MLD_Settings')) {
                $google_maps_key = MLD_Settings::get_google_maps_api_key();
            }

            // Localize script with AJAX data
            wp_localize_script('mld-standalone-cma', 'mldStandaloneCMA', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mld_ajax_nonce'),
                'homeUrl' => home_url('/'),
                'cmaBaseUrl' => home_url('/cma/'),
                'isLoggedIn' => is_user_logged_in(),
                'userId' => get_current_user_id(),
                'googleMapsKey' => $google_maps_key,
            ));
        }

        // Enqueue Google Maps API for Places Autocomplete
        $maps_key = '';
        if (class_exists('MLD_Settings')) {
            $maps_key = MLD_Settings::get_google_maps_api_key();
        }

        if (!empty($maps_key)) {
            // Check if Google Maps is already enqueued with a different handle
            $google_maps_loaded = wp_script_is('google-maps-api', 'registered') ||
                                  wp_script_is('google-maps-api', 'enqueued') ||
                                  wp_script_is('google-maps-places', 'registered');

            // Only enqueue if not already loaded
            if (!$google_maps_loaded) {
                $callback_name = 'initStandaloneCMAGoogleMaps';
                $google_maps_url = 'https://maps.googleapis.com/maps/api/js?key=' . $maps_key . '&libraries=places,marker,geometry&loading=async&callback=' . $callback_name;

                wp_register_script('google-maps-places', $google_maps_url, array(), null, true);
                wp_enqueue_script('google-maps-places');

                // Add async/defer attributes
                add_filter('script_loader_tag', function($tag, $handle) {
                    if ($handle === 'google-maps-places') {
                        return str_replace(' src', ' async defer src', $tag);
                    }
                    return $tag;
                }, 10, 2);

                // Add initialization callback
                wp_add_inline_script('google-maps-places', '
                    window.' . $callback_name . ' = function() {
                        // Trigger custom event when Google Maps is ready
                        var event = new CustomEvent("googleMapsReady");
                        document.dispatchEvent(event);
                        console.log("Google Maps API loaded and ready for Standalone CMA");
                    };
                ', 'before');
            }
        }

        // Also enqueue the main comparable sales script (needed for CMA section)
        // Note: comparable-sales CSS is inline in mld-comparable-sales-display.php
        wp_enqueue_script(
            'mld-comparable-sales',
            MLD_PLUGIN_URL . 'assets/js/mld-comparable-sales.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        wp_localize_script('mld-comparable-sales', 'mldAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_ajax_nonce'),
        ));
    }

    /**
     * Add body class for standalone CMA pages
     */
    public function add_body_class($classes) {
        $mld_standalone_cma = get_query_var('mld_standalone_cma');

        if ($mld_standalone_cma == '1') {
            $classes[] = 'mld-standalone-cma-page';

            $cma_new = get_query_var('cma_new');
            if ($cma_new == '1') {
                $classes[] = 'mld-standalone-cma-new';
            } else {
                $classes[] = 'mld-standalone-cma-view';
            }
        }

        return $classes;
    }

    /**
     * Check if current page is a standalone CMA page
     */
    public static function is_standalone_cma_page() {
        return get_query_var('mld_standalone_cma') == '1';
    }

    /**
     * Get current CMA session if viewing one
     */
    public static function get_current_session() {
        $slug = get_query_var('cma_slug');
        if (!empty($slug)) {
            return MLD_CMA_Sessions::get_session_by_slug($slug);
        }
        return null;
    }

    /**
     * Flush rewrite rules (call on plugin activation)
     */
    public static function flush_rewrite_rules() {
        $instance = self::get_instance();
        $instance->add_rewrite_rules();
        flush_rewrite_rules();
    }
}

// Initialize the class
add_action('plugins_loaded', function() {
    MLD_Standalone_CMA_Pages::get_instance();
}, 20);
