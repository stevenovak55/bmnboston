<?php
/**
 * Handles rewrite rules and template redirects.
 * v3.0.0
 * - Simplified to only use V3 templates
 * - Removed all V1 and V2 template logic
 * - Kept device detection for mobile/desktop V3 templates
 */
class MLD_Rewrites {

    /**
     * Device detector instance
     */
    private $device_detector;

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rules' ], 1 );  // Higher priority
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_filter( 'template_include', [ $this, 'template_include' ], 999 );  // Lower priority to ensure it runs
        add_filter( 'request', [ $this, 'handle_property_request' ], 1 );  // Filter request very early
        add_action( 'parse_request', [ $this, 'parse_property_request' ], 1 );  // Add parse_request handler
        add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ], 1 );  // Hook into pre_get_posts
        add_filter( '404_template', [ $this, 'handle_404' ], 1 );  // Hook into 404 template

        // Initialize device detector
        add_action( 'init', [ $this, 'init_device_detector' ], 2 );
    }
    
    /**
     * Initialize device detector
     */
    public function init_device_detector() {
        if ( class_exists( 'MLD_Device_Detector' ) ) {
            $this->device_detector = MLD_Device_Detector::get_instance();
        }
    }

    /**
     * Ensure device detector is initialized
     */
    private function ensure_device_detector() {
        if ( !$this->device_detector && class_exists( 'MLD_Device_Detector' ) ) {
            $this->device_detector = MLD_Device_Detector::get_instance();
        }
    }

    /**
     * Add rewrite rules for the single property page.
     */
    public function add_rewrite_rules() {
        // Single rule that handles both formats
        // Matches both /property/12345/ and /property/anything-ending-with-12345/
        add_rewrite_rule(
            '^property/([^/]+)/?$',
            'index.php?mls_number=$matches[1]',
            'top'
        );
    }

    /**
     * Add custom query variables.
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'mls_number';
        return $vars;
    }

    /**
     * Handle property requests very early in the request cycle
     */
    public function handle_property_request( $query_vars ) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if this is a property URL
        if ( preg_match( '#^/property/([^/]+)/?$#', $request_uri, $matches ) ) {
            $potential_mls = $matches[1];

            // Set the mls_number query var for any property URL
            if ( !isset( $query_vars['mls_number'] ) ) {
                $query_vars['mls_number'] = $potential_mls;
                // Clear other query vars that might interfere
                unset( $query_vars['pagename'] );
                unset( $query_vars['name'] );
                unset( $query_vars['error'] );

                if ( class_exists( 'MLD_Logger' ) ) {
                    MLD_Logger::debug( 'Property request filtered', [
                        'mls_number' => $potential_mls,
                        'request_uri' => $request_uri
                    ]);
                }
            }
        }

        return $query_vars;
    }

    /**
     * Parse property requests to ensure they're handled correctly
     */
    public function parse_property_request( $wp ) {
        if ( isset( $wp->query_vars['mls_number'] ) && ! empty( $wp->query_vars['mls_number'] ) ) {

            // Force WordPress to recognize this as a valid request
            $wp->query_vars['error'] = '';
            $wp->query_vars['pagename'] = '';
            $wp->query_vars['page'] = '';
            $wp->query_vars['name'] = '';

            // Mark as found to prevent 404
            global $wp_query;
            if ( $wp_query ) {
                $wp_query->is_404 = false;
                $wp_query->is_page = false;
                $wp_query->is_single = false;
                $wp_query->is_singular = true;
            }
        }
    }

    /**
     * Hook into pre_get_posts to ensure our query is processed
     */
    public function pre_get_posts( $query ) {
        if ( ! is_admin() && $query->is_main_query() ) {
            $mls_number = get_query_var( 'mls_number' );
            if ( $mls_number ) {
                $query->is_404 = false;
                $query->is_page = false;
                $query->is_single = false;
                $query->is_singular = true;
            }
        }
    }

    /**
     * Handle 404 template to check if it's actually a property page
     */
    public function handle_404( $template ) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if this is a property URL
        if ( preg_match( '#^/property/([^/]+)/?$#', $request_uri, $matches ) ) {
            // Set the query var manually since WordPress doesn't have it
            set_query_var( 'mls_number', $matches[1] );

            // This should be a property page, use our template instead
            return $this->template_include( '' );
        }

        return $template;
    }

    /**
     * Load the single property template if the query var is set.
     */
    public function template_include( $template ) {
        $mls_number_raw = get_query_var( 'mls_number' );


        if ( $mls_number_raw ) {
            // Log for debugging
            if (class_exists('MLD_Logger')) {
                MLD_Logger::debug('Property URL accessed', ['raw_mls' => $mls_number_raw]);
            }

            // Always extract the listing ID - handle both formats
            // Make sure URL helper is loaded
            if (!class_exists('MLD_URL_Helper')) {
                require_once MLD_PLUGIN_PATH . 'includes/class-mld-url-helper.php';
            }

            // Extract listing ID - this handles both numeric and slug formats
            $mls_number = MLD_URL_Helper::extract_listing_id_from_slug($mls_number_raw);

            if (!$mls_number) {
                // If extraction failed, try using raw value
                $mls_number = $mls_number_raw;
            }

            if (class_exists('MLD_Logger')) {
                MLD_Logger::debug('Processing listing ID', ['raw' => $mls_number_raw, 'extracted' => $mls_number]);
            }

            // Ensure device detector is initialized
            $this->ensure_device_detector();

            // Determine device-specific templates
            $template_suffix = '';

            if ( $this->device_detector ) {
                $template_suffix = $this->device_detector->get_template_suffix();
            }
            
            // Always enqueue jQuery
            wp_enqueue_script('jquery');
            
            
            // Enqueue map scripts for property pages
            $settings = get_option('mld_settings', []);
            $google_key = $settings['mld_google_maps_api_key'] ?? '';

            // Always use Google Maps (Mapbox removed for performance optimization)
            // Check if Google Maps is not already registered or enqueued
            if ( !empty($google_key) && !wp_script_is('google-maps-api', 'registered') && !wp_script_is('google-maps-api', 'enqueued') ) {
                // Load Google Maps asynchronously with callback
                $callback_name = 'initGoogleMapsAPI';
                $google_maps_url = "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=marker,geometry&loading=async&callback={$callback_name}";

                // Register and enqueue script
                wp_register_script( 'google-maps-api', $google_maps_url, [], null, true );
                wp_enqueue_script( 'google-maps-api' );

                add_filter( 'script_loader_tag', function( $tag, $handle ) {
                    if ( 'google-maps-api' === $handle ) {
                        return str_replace( ' src', ' async defer src', $tag );
                    }
                    return $tag;
                }, 10, 2 );

                // Add initialization callback
                wp_add_inline_script( 'google-maps-api', '
                    window.initGoogleMapsAPI = function() {
                        // Trigger custom event
                        window.dispatchEvent(new Event("googleMapsReady"));

                        // Call any existing initialization functions
                        if (typeof window.initPropertyMapModules === "function") {
                            window.initPropertyMapModules();
                        }
                        if (typeof window.initV3GoogleMapCallback === "function") {
                            window.initV3GoogleMapCallback();
                        }
                        if (typeof window.initMobileGoogleMaps === "function") {
                            window.initMobileGoogleMaps();
                        }
                    };
                ', 'before' );
            }
            
            // Get listing data
            $listing = MLD_Query::get_listing_details($mls_number);

            if (!$listing) {
                wp_die('Property not found', 404);
            }

            // Initialize SEO with property data
            $seo = new MLD_SEO();
            $seo->set_property_data($listing);

            // Add canonical URL to prevent duplicate content
            if (class_exists('MLD_URL_Helper')) {
                $canonical_url = MLD_URL_Helper::get_canonical_url($listing);
                if (!empty($canonical_url)) {
                    add_action('wp_head', function() use ($canonical_url) {
                        echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
                    }, 1);
                }
            }
            
            // Get photos
            $photos = $listing['Media'] ?? [];
            
            // Get coordinates
            $lat = $listing['latitude'] ?? $listing['Latitude'] ?? null;
            $lng = $listing['longitude'] ?? $listing['Longitude'] ?? null;
            
            // Determine which V3 template to use based on device
            if ($template_suffix === '-mobile') {
                // Use V3 mobile template
                $v3_mobile_template = MLD_PLUGIN_PATH . 'templates/single-property-mobile-v3.php';
                
                // Ensure jQuery is loaded first
                wp_enqueue_script('jquery');

                // Only enqueue Google Maps API if not already loaded anywhere
                if (!wp_script_is('google-maps-api', 'enqueued') &&
                    !wp_script_is('google-maps-api', 'registered') &&
                    !empty($google_key)) {
                    // Check if Google Maps is already in the page (from other sources)
                    global $wp_scripts;
                    $maps_loaded = false;
                    if (isset($wp_scripts->registered)) {
                        foreach ($wp_scripts->registered as $handle => $script) {
                            if (strpos($script->src, 'maps.googleapis.com/maps/api/js') !== false) {
                                $maps_loaded = true;
                                break;
                            }
                        }
                    }

                    // Google Maps should already be registered/enqueued above
                    // This section is now redundant but kept for reference
                    if (!$maps_loaded && !wp_script_is('google-maps-api', 'enqueued')) {
                        // Google Maps will be loaded by the main handler above
                        // Just ensure the callback function is available
                        wp_add_inline_script('jquery', '
                            window.initMobileGoogleMaps = function() {
                                console.log("[MLD Mobile] Google Maps API loaded successfully");
                                window.googleMapsReady = true;
                            };
                        ', 'after');
                    }
                }

                // Enqueue V3 mobile assets in proper dependency order
                wp_enqueue_style('mld-facts-features-v2', MLD_PLUGIN_URL . 'assets/css/facts-features-v2.css', [], filemtime(MLD_PLUGIN_PATH . 'assets/css/facts-features-v2.css'));
                wp_enqueue_style('mld-property-mobile-v3', MLD_PLUGIN_URL . 'assets/css/property-mobile-v3.css', ['mld-facts-features-v2'], MLD_VERSION);

                // Load core JavaScript in proper order
                wp_enqueue_script('mld-logger', MLD_PLUGIN_URL . 'assets/js/mld-logger.js', ['jquery'], MLD_VERSION, true);
                wp_enqueue_script('mld-mobile-core-classes', MLD_PLUGIN_URL . 'assets/js/mobile-core-classes.js', ['jquery', 'mld-logger'], MLD_VERSION, true);

                // Load image fallback system
                wp_enqueue_script('mld-mobile-image-fallback', MLD_PLUGIN_URL . 'assets/js/mobile-image-fallback.js', [], MLD_VERSION, true);

                wp_enqueue_script('mld-property-mobile-v3', MLD_PLUGIN_URL . 'assets/js/property-mobile-v3.js', ['jquery', 'mld-logger', 'mld-mobile-core-classes'], MLD_VERSION, true);

                // Load calculator for mobile property pages
                wp_enqueue_script('mld-property-calculator-mobile-v3', MLD_PLUGIN_URL . 'assets/js/property-calculator-mobile-v3.js', [], MLD_VERSION, true);

                // Enqueue module scripts (now depends on core classes)
                wp_enqueue_script('mld-modules-init', MLD_PLUGIN_URL . 'assets/js/modules/mld-modules-init.js', ['jquery', 'mld-logger', 'mld-mobile-core-classes'], MLD_VERSION, true);

                // Load additional mobile-specific modules
                wp_enqueue_script('mld-mobile-enhancements', MLD_PLUGIN_URL . 'assets/js/modules/mld-mobile-enhancements.js', ['mld-mobile-core-classes'], MLD_VERSION, true);

                // Enqueue comparable sales script for mobile (v6.13.15)
                wp_enqueue_script('mld-comparable-sales', MLD_PLUGIN_URL . 'assets/js/mld-comparable-sales.js', ['jquery'], MLD_VERSION, true);
                wp_localize_script('mld-comparable-sales', 'mldAjax', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mld_ajax_nonce'),
                    'roadTypePremium' => floatval(get_option('mld_cma_road_type_discount', 25))
                ]);

                // v6.25.9: Removed mobile-direct-fix.js and mobile-ultimate-fix.js
                // These were overriding property-mobile-v3.js with hardcoded 3 positions
                // Bottom sheet is now fully managed by property-mobile-v3.js with 2 positions
                
                // Pass data to V3 mobile script
                wp_localize_script('mld-property-mobile-v3', 'mldPropertyData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mld_ajax_nonce'),
                    'propertyId' => $mls_number,
                    'address' => $listing['unparsed_address'] ?? '',
                    'coordinates' => ($lat && $lng) ? ['lat' => (float)$lat, 'lng' => (float)$lng] : null,
                    'price' => (int)($listing['list_price'] ?? 0),
                    'photos' => array_column($photos, 'MediaURL'),
                    'mapProvider' => 'google', // Always Google Maps (Mapbox removed)
                    'googleKey' => $google_key
                ]);
                
                // Pass settings
                wp_localize_script('mld-property-mobile-v3', 'mldSettings', MLD_Settings::get_js_settings());
                
                // Pass map data
                wp_localize_script('mld-property-mobile-v3', 'bmeMapData', [
                    'mapProvider' => 'google', // Always Google Maps (Mapbox removed)
                    'google_key' => $google_key
                ]);
                
                // Pass Walk Score settings
                wp_localize_script('mld-property-mobile-v3', 'mldWalkScoreSettings', [
                    'enabled' => MLD_Settings::is_walk_score_enabled(),
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mld-walk-score')
                ]);
                
                return $v3_mobile_template;
            } else {
                // Use V3 desktop template
                $v3_desktop_template = MLD_PLUGIN_PATH . 'templates/single-property-desktop-v3.php';

                // Google Maps API should already be registered/enqueued by the main handler
                // Just make sure it's enqueued if registered
                if (!wp_script_is('google-maps-api', 'enqueued') && wp_script_is('google-maps-api', 'registered')) {
                    wp_enqueue_script('google-maps-api');
                }

                // Enqueue V3 desktop assets
                wp_enqueue_style('mld-facts-features-v2', MLD_PLUGIN_URL . 'assets/css/facts-features-v2.css', [], filemtime(MLD_PLUGIN_PATH . 'assets/css/facts-features-v2.css'));
                wp_enqueue_style('mld-property-desktop-v3', MLD_PLUGIN_URL . 'assets/css/property-desktop-v3.css', ['mld-facts-features-v2'], MLD_VERSION);
                wp_enqueue_script('mld-logger', MLD_PLUGIN_URL . 'assets/js/mld-logger.js', ['jquery'], MLD_VERSION, true);

                // Always enqueue property desktop script (handles images, scrolling, etc.)
                wp_enqueue_script('mld-property-desktop-v3', MLD_PLUGIN_URL . 'assets/js/property-desktop-v3.js', ['jquery', 'mld-logger'], MLD_VERSION, true);

                // Enqueue module scripts
                wp_enqueue_script('mld-modules-init', MLD_PLUGIN_URL . 'assets/js/modules/mld-modules-init.js', ['jquery', 'mld-logger'], MLD_VERSION, true);

                // Enqueue comparable sales script
                wp_enqueue_script('mld-comparable-sales', MLD_PLUGIN_URL . 'assets/js/mld-comparable-sales.js', ['jquery'], MLD_VERSION, true);
                wp_localize_script('mld-comparable-sales', 'mldAjax', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mld_ajax_nonce'),
                    'roadTypePremium' => floatval(get_option('mld_cma_road_type_discount', 25))
                ]);

                // Pass data to V3 script (always needed for property functionality)
                wp_localize_script('mld-property-desktop-v3', 'mldPropertyData', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mld_ajax_nonce'),
                    'propertyId' => $mls_number,
                    'address' => $listing['unparsed_address'] ?? '',
                    'coordinates' => ($lat && $lng) ? ['lat' => (float)$lat, 'lng' => (float)$lng] : null,
                    'price' => (int)($listing['list_price'] ?? 0),
                    'photos' => array_column($photos, 'MediaURL'),
                    'mapProvider' => 'google', // Always Google Maps (Mapbox removed)
                    'googleKey' => $google_key
                ]);
                
                // Add settings for modules
                wp_localize_script('mld-modules-init', 'mldSettings', MLD_Settings::get_js_settings());
                
                return $v3_desktop_template;
            }
        }
        return $template;
    }

    /**
     * Register rewrite rules on activation.
     * Note: Actual flush happens via transient in MLD_Activator to ensure
     * all rules (including sitemap, state pages, etc.) are registered first.
     */
    public static function activate() {
        $rewrites = new self();
        $rewrites->add_rewrite_rules();
        // flush_rewrite_rules() is handled by mld_maybe_flush_rewrite_rules() on init
    }

    /**
     * Flush rewrite rules on deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Render view switcher for mobile/desktop
     */
    public function render_view_switcher() {
        if ( !$this->device_detector ) {
            return;
        }
        
        $links = $this->device_detector->get_view_switcher_links();
        $current_view = $this->device_detector->get_view_mode();
        ?>
        <div class="mld-view-switcher">
            <span class="mld-view-label">View:</span>
            <?php if ( $current_view === 'mobile' ) : ?>
                <a href="<?php echo esc_url( $links['desktop'] ); ?>" class="mld-view-link">Desktop Site</a>
            <?php else : ?>
                <a href="<?php echo esc_url( $links['mobile'] ); ?>" class="mld-view-link">Mobile Site</a>
            <?php endif; ?>
            <?php if ( $this->device_detector->get_user_preference() ) : ?>
                <span class="mld-view-separator">|</span>
                <a href="<?php echo esc_url( $links['auto'] ); ?>" class="mld-view-link mld-view-auto">Auto-detect</a>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get device detector instance (for use by other classes)
     */
    public function get_device_detector() {
        return $this->device_detector;
    }
    
    /**
     * Check if should load mobile view
     */
    public function is_mobile_view() {
        return $this->device_detector && $this->device_detector->is_mobile_view();
    }
    
    /**
     * Check if should load desktop view
     */
    public function is_desktop_view() {
        return !$this->device_detector || $this->device_detector->is_desktop_view();
    }
    
}
