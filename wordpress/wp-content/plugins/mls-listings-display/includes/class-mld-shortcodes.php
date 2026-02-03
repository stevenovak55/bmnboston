<?php
/**
 * Defines the shortcodes used by the plugin.
 *
 * @package MLS_Listings_Display
 */
class MLD_Shortcodes {

    /**
     * Device detector instance
     */
    private $device_detector;

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_map_assets' ] );
        add_filter( 'script_loader_tag', [ $this, 'add_defer_attribute' ], 10, 2 );
        add_shortcode( 'bme_listings_map_view', [ $this, 'render_map_view' ] );
        add_shortcode( 'bme_listings_half_map_view', [ $this, 'render_half_map_view' ] );
        add_shortcode( 'mld_map_full', [ $this, 'render_map_view' ] ); // Alias for consistency
        add_shortcode( 'mld_map_half', [ $this, 'render_half_map_view' ] ); // Alias for consistency
        add_shortcode( 'mld_user_dashboard', [ $this, 'render_user_dashboard' ] ); // User dashboard for saved searches

        // Additional shortcodes
        add_shortcode( 'mld_listing_cards', [ $this, 'render_listing_cards' ] ); // Listing cards with infinite scroll
        add_shortcode( 'mld_contact_form', [ $this, 'render_contact_form' ] ); // Universal contact forms (v6.21.0)

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
     * Render contact form shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Form HTML
     * @since 6.21.0
     */
    public function render_contact_form( $atts ) {
        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[mld_contact_form]';
        }

        // Parse attributes
        $atts = shortcode_atts( [
            'id' => 0,
            'class' => '',
        ], $atts, 'mld_contact_form' );

        $form_id = absint( $atts['id'] );

        if ( ! $form_id ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p class="mld-cf-error">' . esc_html__( 'Contact form ID is required. Usage: [mld_contact_form id="1"]', 'mls-listings-display' ) . '</p>';
            }
            return '';
        }

        // Check if renderer class exists
        if ( ! class_exists( 'MLD_Contact_Form_Renderer' ) ) {
            $renderer_path = MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-renderer.php';
            if ( file_exists( $renderer_path ) ) {
                require_once $renderer_path;
            } else {
                if ( current_user_can( 'manage_options' ) ) {
                    return '<p class="mld-cf-error">' . esc_html__( 'Contact form renderer not found.', 'mls-listings-display' ) . '</p>';
                }
                return '';
            }
        }

        // Enqueue frontend assets
        if ( ! is_admin() ) {
            // Enqueue frontend CSS
            wp_enqueue_style(
                'mld-contact-form-frontend',
                MLD_PLUGIN_URL . 'assets/css/mld-contact-form-frontend.css',
                [ 'mld-design-tokens' ],
                MLD_VERSION
            );

            // Enqueue frontend JavaScript
            wp_enqueue_script(
                'mld-contact-form-handler',
                MLD_PLUGIN_URL . 'assets/js/mld-contact-form-handler.js',
                [ 'jquery' ],
                MLD_VERSION,
                true
            );

            // Enqueue conditional logic handler (v6.22.0)
            wp_enqueue_script(
                'mld-contact-form-conditional',
                MLD_PLUGIN_URL . 'assets/js/mld-contact-form-conditional.js',
                [ 'jquery', 'mld-contact-form-handler' ],
                MLD_VERSION,
                true
            );

            // Enqueue multi-step form handler (v6.23.0)
            wp_enqueue_script(
                'mld-contact-form-multistep',
                MLD_PLUGIN_URL . 'assets/js/mld-contact-form-multistep.js',
                [ 'jquery', 'mld-contact-form-handler' ],
                MLD_VERSION,
                true
            );

            // Enqueue file upload handler (v6.24.0)
            wp_enqueue_script(
                'mld-contact-form-upload',
                MLD_PLUGIN_URL . 'assets/js/mld-contact-form-upload.js',
                [ 'jquery', 'mld-contact-form-handler' ],
                MLD_VERSION,
                true
            );

            // Localize script with AJAX data
            wp_localize_script( 'mld-contact-form-handler', 'mldContactForm', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'mld_contact_form_nonce' ),
                'strings' => [
                    'submitting' => __( 'Sending...', 'mls-listings-display' ),
                    'success' => __( 'Thank you! Your message has been sent.', 'mls-listings-display' ),
                    'error' => __( 'There was a problem sending your message. Please try again.', 'mls-listings-display' ),
                    'required' => __( 'This field is required.', 'mls-listings-display' ),
                    'invalidEmail' => __( 'Please enter a valid email address.', 'mls-listings-display' ),
                    'invalidPhone' => __( 'Please enter a valid phone number.', 'mls-listings-display' ),
                    'minLength' => __( 'Please enter at least %d characters.', 'mls-listings-display' ),
                    'maxLength' => __( 'Please enter no more than %d characters.', 'mls-listings-display' ),
                ]
            ] );

            // Add Customizer inline styles
            $renderer = new MLD_Contact_Form_Renderer();
            $customizer_css = $renderer->get_customizer_inline_styles();
            if ( ! empty( $customizer_css ) ) {
                wp_add_inline_style( 'mld-contact-form-frontend', $customizer_css );
            }
        }

        // Render the form
        $renderer = new MLD_Contact_Form_Renderer();
        return $renderer->render_form( $form_id, $atts );
    }

    /**
     * Enqueue scripts and styles for the map view.
     */
    public function enqueue_map_assets() {
        global $post;

        // Check if we're on a city page (dynamically generated, not a real post)
        $is_city_page = get_query_var('mld_city_page', false);

        // Check if post has map shortcodes
        $has_map_shortcode = is_a( $post, 'WP_Post' ) && (
            has_shortcode( $post->post_content, 'bme_listings_map_view' ) ||
            has_shortcode( $post->post_content, 'bme_listings_half_map_view' ) ||
            has_shortcode( $post->post_content, 'mld_map_full' ) ||
            has_shortcode( $post->post_content, 'mld_map_half' )
        );

        if ( $has_map_shortcode || $is_city_page ) {

            // v6.20.20: CRITICAL FIX - Prevent page caching for map pages
            // This fixes the mobile first-load issue where stale cached pages were served
            // Send headers to prevent caching at all levels (browser, CDN, edge cache)
            if (!headers_sent()) {
                // Standard cache-control headers
                header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');

                // Kinsta-specific header to bypass edge cache
                header('X-Kinsta-Cache: BYPASS');

                // Cloudflare bypass
                header('CDN-Cache-Control: no-store');
                header('Cloudflare-CDN-Cache-Control: no-store');
            }

            // Also tell WordPress caching plugins not to cache this page
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            if (!defined('DONOTCACHEOBJECT')) {
                define('DONOTCACHEOBJECT', true);
            }
            if (!defined('DONOTCACHEDB')) {
                define('DONOTCACHEDB', true);
            }

            $options = get_option( 'mld_settings' );
            $google_key = $options['mld_google_maps_api_key'] ?? '';

            // Always use Google Maps (Mapbox removed for performance optimization)
            // Only enqueue if not already registered/enqueued to prevent duplicate loading
            if (!wp_script_is('google-maps-api', 'registered') && !wp_script_is('google-maps-api', 'enqueued')) {
                $google_maps_url = "https://maps.googleapis.com/maps/api/js?key={$google_key}&libraries=marker,geometry&loading=async&callback=initGoogleMapsAPI";
                wp_register_script( 'google-maps-api', $google_maps_url, ['jquery'], null, true );
            }
            wp_enqueue_script( 'google-maps-api' );

            // Add initialization callback for Google Maps with robust dependency checking
            wp_add_inline_script( 'google-maps-api', '
                window.initGoogleMapsAPI = function() {
                    var attempts = 0;
                    var maxAttempts = 50; // 5 seconds total

                    function tryInit() {
                        attempts++;

                        // Check if all dependencies are ready
                        if (window.MLD_Map_App &&
                            typeof window.MLD_Map_App.init === "function" &&
                            typeof bmeMapData !== "undefined" &&
                            typeof jQuery !== "undefined") {

                            console.log("MLD: Initializing map after", attempts, "attempts");
                            window.MLD_Map_App.init();
                            return;
                        }

                        // If dependencies not ready and we haven\'t exceeded max attempts, retry
                        if (attempts < maxAttempts) {
                            setTimeout(tryInit, 100);
                        } else {
                            console.error("MLD: Failed to initialize map - dependencies not loaded after 5 seconds");
                            console.log("Available:", {
                                MLD_Map_App: !!window.MLD_Map_App,
                                init_function: typeof window.MLD_Map_App?.init,
                                bmeMapData: typeof bmeMapData,
                                jQuery: typeof jQuery
                            });
                        }
                    }

                    tryInit();
                };
            ', 'before' );

            // FontAwesome removed for performance (58K savings)
            // Only loaded on user dashboard where it's actually used
            wp_enqueue_style( 'mld-main-css', MLD_PLUGIN_URL . 'assets/css/main.css', ['mld-design-tokens'], MLD_VERSION );
            wp_enqueue_style( 'mld-skeleton-loaders', MLD_PLUGIN_URL . 'assets/css/mld-skeleton-loaders.css', ['mld-main-css'], MLD_VERSION );
            wp_enqueue_style( 'mld-enhanced-filters', MLD_PLUGIN_URL . 'assets/css/mld-enhanced-filters.css', ['mld-main-css'], MLD_VERSION );

            // Add mobile CSS and JS if on mobile device
            if ( $this->device_detector && $this->device_detector->is_mobile_view() ) {
                wp_enqueue_style( 'mld-search-mobile-css', MLD_PLUGIN_URL . 'assets/css/search-mobile.css', ['mld-main-css'], MLD_VERSION );
                // Add mobile-specific search initialization
                wp_enqueue_script( 'mld-search-mobile-init', MLD_PLUGIN_URL . 'assets/js/search-mobile-init.js', ['jquery', 'mld-map-core'], MLD_VERSION, true );
            }
            
            // Dependencies now only include Google Maps (Mapbox removed)
            $dependencies = ['jquery', 'google-maps-api'];

            // Load logger first
            wp_enqueue_script( 'mld-logger', MLD_PLUGIN_URL . 'assets/js/mld-logger.js', ['jquery'], MLD_VERSION, true );

            wp_enqueue_script( 'mld-map-api', MLD_PLUGIN_URL . 'assets/js/map-api.js', array_merge($dependencies, ['mld-logger']), MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-filters', MLD_PLUGIN_URL . 'assets/js/map-filters.js', ['mld-map-api'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-core', MLD_PLUGIN_URL . 'assets/js/map-core.js', ['mld-map-api'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-markers', MLD_PLUGIN_URL . 'assets/js/map-markers.js', ['mld-map-core', 'mld-map-filters'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-gallery', MLD_PLUGIN_URL . 'assets/js/map-gallery.js', ['mld-map-core', 'mld-map-filters'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-city-boundaries', MLD_PLUGIN_URL . 'assets/js/map-city-boundaries.js', ['mld-map-core', 'mld-map-filters'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-schools', MLD_PLUGIN_URL . 'assets/js/map-schools.js', ['mld-map-core'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-map-controls-panel', MLD_PLUGIN_URL . 'assets/js/map-controls-panel.js', ['jquery', 'mld-map-core'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-script-loader', MLD_PLUGIN_URL . 'assets/js/mld-script-loader.js', ['mld-map-api', 'mld-map-filters', 'mld-map-core'], MLD_VERSION, true );
            
            // Add multi-unit modal scripts and styles
            wp_enqueue_style( 'mld-map-multi-unit-modal', MLD_PLUGIN_URL . 'assets/css/map-multi-unit-modal.css', [], MLD_VERSION );
            wp_enqueue_script( 'mld-map-multi-unit-modal', MLD_PLUGIN_URL . 'assets/js/map-multi-unit-modal.js', ['jquery', 'mld-map-markers'], MLD_VERSION, true );

            // Add saved searches functionality
            wp_enqueue_style( 'mld-saved-searches', MLD_PLUGIN_URL . 'assets/css/mld-saved-searches.css', [], MLD_VERSION );
            wp_enqueue_style( 'mld-saved-searches-mobile-fix', MLD_PLUGIN_URL . 'assets/css/mld-saved-searches-mobile-fix.css', ['mld-saved-searches'], MLD_VERSION );
            wp_enqueue_script( 'mld-saved-searches', MLD_PLUGIN_URL . 'assets/js/mld-saved-searches.js', ['jquery', 'mld-map-core'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-saved-searches-mobile-fix', MLD_PLUGIN_URL . 'assets/js/mld-saved-searches-mobile-fix.js', ['jquery', 'mld-saved-searches'], MLD_VERSION, true );
            wp_enqueue_script( 'mld-mobile-scroll-behavior', MLD_PLUGIN_URL . 'assets/js/mobile-scroll-behavior.js', ['jquery'], MLD_VERSION, true );

            // Removed safari-state-manager.js - state persistence has been disabled

            // Get URL settings
            $settings = get_option('mld_settings', []);
            $saved_searches_url = !empty($settings['saved_searches_url']) ?
                home_url(ltrim($settings['saved_searches_url'], '/')) :
                home_url('/saved-search/');
            $login_url = !empty($settings['login_url']) ?
                home_url(ltrim($settings['login_url'], '/')) :
                home_url('/wp-login.php');
            $register_url = !empty($settings['register_url']) ?
                home_url(ltrim($settings['register_url'], '/')) :
                home_url('/register/');

            // Get agent status and clients for agent-created searches feature
            $is_agent = false;
            $agent_clients = [];
            $user_id = get_current_user_id();
            if ($user_id && class_exists('MLD_User_Type_Manager') && MLD_User_Type_Manager::is_agent($user_id)) {
                $is_agent = true;
                if (class_exists('MLD_Agent_Client_Manager')) {
                    $clients_result = MLD_Agent_Client_Manager::get_agent_clients($user_id);
                    if (!is_wp_error($clients_result) && !empty($clients_result)) {
                        $agent_clients = array_map(function($c) {
                            // Handle both array and object formats
                            if (is_array($c)) {
                                return [
                                    'id' => $c['client_id'] ?? $c['id'] ?? 0,
                                    'name' => $c['display_name'] ?? $c['user_email'] ?? '',
                                    'email' => $c['user_email'] ?? ''
                                ];
                            } else {
                                return [
                                    'id' => $c->client_id ?? $c->id ?? 0,
                                    'name' => $c->display_name ?? $c->user_email ?? '',
                                    'email' => $c->user_email ?? ''
                                ];
                            }
                        }, $clients_result);
                    }
                }
            }

            wp_localize_script( 'mld-saved-searches', 'mldUserData', [
                'isLoggedIn' => is_user_logged_in(),
                'isAdmin' => current_user_can('manage_options'),
                'isAgent' => $is_agent,
                'clients' => $agent_clients,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mld_saved_searches'),
                'userId' => get_current_user_id(),
                'savedSearchesUrl' => $saved_searches_url,
                'loginUrl' => $login_url,
                'registerUrl' => $register_url
            ]);

            $boolean_fields = [
                'open_house_only', 'SpaYN', 'WaterfrontYN', 'ViewYN', 'MLSPIN_WATERVIEW_FLAG',
                'PropertyAttachedYN', 'MLSPIN_LENDER_OWNED', 'SeniorCommunityYN',
                'MLSPIN_OUTDOOR_SPACE_AVAILABLE', 'MLSPIN_DPR_Flag', 'CoolingYN',
                'price_reduced'  // v6.58.0 - Quick filter preset
            ];
            $field_labels = [];
            foreach ($boolean_fields as $field) {
                $field_labels[$field] = MLD_Utils::get_field_label($field);
            }

            // Get list of available cities for instant client-side lookup
            $available_cities = $this->get_available_cities_cached();

            wp_localize_script( 'mld-map-api', 'bmeMapData', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ), // Add camelCase version for compatibility
                'security'   => wp_create_nonce( 'bme_map_nonce' ),
                'nonce'      => wp_create_nonce( 'mld_ajax_nonce' ), // Use mld_ajax_nonce for favorite/hide buttons
                'provider'   => 'google', // Always Google Maps (Mapbox removed for performance)
                'google_key' => $google_key,
                'subtype_customizations' => get_option('mld_subtype_customizations', []),
                'field_labels' => $field_labels,
                'available_cities' => $available_cities, // Add cities for instant lookup
                'isUserLoggedIn' => is_user_logged_in() // v6.31.9: For favorite/hide buttons
            ]);
            
            // Add debug configuration for logger
            wp_localize_script( 'mld-logger', 'mldConfig', [
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bme_map_nonce')
            ]);
        }
    }

    /**
     * Adds async and defer attributes to script tags for performance.
     */
    public function add_defer_attribute( $tag, $handle ) {
        // List of non-critical scripts that can be deferred
        // These scripts are map-related and don't block initial page rendering
        $deferred_scripts = [
            'google-maps-api',
            'mld-logger',
            'mld-map-api',
            'mld-map-filters',
            'mld-map-core',
            'mld-map-markers',
            'mld-map-gallery',
            'mld-map-city-boundaries',
            'mld-map-schools',
            'mld-map-controls-panel',
            'mld-script-loader',
            'mld-map-multi-unit-modal',
            'mld-search-mobile-init'
        ];

        if ( in_array($handle, $deferred_scripts, true) ) {
            // Add defer attribute for better performance
            // Google Maps already has async in URL, others get defer
            if ($handle === 'google-maps-api') {
                return str_replace( ' src', ' defer src', $tag );
            }
            return str_replace( ' src', ' defer src', $tag );
        }
        return $tag;
    }

    /**
     * Render the full-screen map view.
     * 
     * @since 3.0.0
     * @return string The rendered map view HTML
     */
    public function render_map_view( $atts = [] ): string {
        // Parse shortcode attributes with all available filter parameters
        $atts = shortcode_atts( [
            // Location filters
            'city' => '',
            'neighborhood' => '',
            'postal_code' => '',
            'street_name' => '',
            'building' => '',
            'address' => '',
            'mls_number' => '',

            // Property type filters
            'property_type' => '', // Residential, Commercial, etc.
            'home_type' => '', // Single Family, Condo, etc. (PropertySubType)
            'structure_type' => '', // Comma-separated list
            'architectural_style' => '', // Comma-separated list

            // Price filters
            'price_min' => '',
            'price_max' => '',

            // Size filters
            'beds' => '', // Comma-separated list or range (e.g., "2,3,4" or "2+")
            'baths_min' => '',
            'sqft_min' => '',
            'sqft_max' => '',
            'lot_size_min' => '',
            'lot_size_max' => '',

            // Year and level filters
            'year_built_min' => '',
            'year_built_max' => '',
            'entry_level_min' => '',
            'entry_level_max' => '',

            // Parking filters
            'garage_spaces_min' => '',
            'parking_total_min' => '',

            // Status filter
            'status' => 'Active', // Comma-separated: Active,Under Agreement,Sold,etc.

            // Boolean filters (yes/no)
            'spa' => '',
            'waterfront' => '',
            'view' => '',
            'waterview' => '',
            'attached' => '',
            'lender_owned' => '',
            'available_now' => '',
            'senior_community' => '',
            'outdoor_space' => '',
            'dpr' => '',
            'laundry_in_unit' => '',
            'pets_allowed' => '',
            'cooling' => '',
            'heating' => '',
            'basement' => '',
            'fireplace' => '',
            'garage' => '',
            'pool' => '',
            'horses' => '',

            // Date filters
            'available_by' => '',
            'open_house_only' => '',

            // Map settings
            'center_lat' => '',
            'center_lng' => '',
            'zoom' => '',
            'polygon_shapes' => '', // JSON encoded polygon coordinates

            // Display settings
            'show_filters' => 'yes',
            'show_search' => 'yes',
            'show_sidebar' => 'yes',
            'default_view' => 'map', // map or list
            'max_results' => '',
        ], $atts, 'bme_listings_map_view' );

        // Process and validate filters
        $filters = $this->process_map_filters( $atts );

        // Store filters for template use without injecting JavaScript
        // The template can access these via $filters variable

        $template_path = MLD_PLUGIN_PATH . 'templates/views/full-map.php';

        if (!file_exists($template_path)) {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error('Map template not found', ['path' => $template_path]);
            }
            return '<div class="mld-error">Map template not found.</div>';
        }

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Render the half map, half list view.
     * 
     * @since 3.0.0
     * @return string The rendered half-map view HTML
     */
    public function render_half_map_view( $atts = [] ): string {
        // Parse shortcode attributes with all available filter parameters
        $atts = shortcode_atts( [
            // Location filters
            'city' => '',
            'neighborhood' => '',
            'postal_code' => '',
            'street_name' => '',
            'building' => '',
            'address' => '',
            'mls_number' => '',

            // Property type filters
            'property_type' => '', // Residential, Commercial, etc.
            'home_type' => '', // Single Family, Condo, etc. (PropertySubType)
            'structure_type' => '', // Comma-separated list
            'architectural_style' => '', // Comma-separated list

            // Price filters
            'price_min' => '',
            'price_max' => '',

            // Size filters
            'beds' => '', // Comma-separated list or range (e.g., "2,3,4" or "2+")
            'baths_min' => '',
            'sqft_min' => '',
            'sqft_max' => '',
            'lot_size_min' => '',
            'lot_size_max' => '',

            // Year and level filters
            'year_built_min' => '',
            'year_built_max' => '',
            'entry_level_min' => '',
            'entry_level_max' => '',

            // Parking filters
            'garage_spaces_min' => '',
            'parking_total_min' => '',

            // Status filter
            'status' => 'Active', // Comma-separated: Active,Under Agreement,Sold,etc.

            // Boolean filters (yes/no)
            'spa' => '',
            'waterfront' => '',
            'view' => '',
            'waterview' => '',
            'attached' => '',
            'lender_owned' => '',
            'available_now' => '',
            'senior_community' => '',
            'outdoor_space' => '',
            'dpr' => '',
            'laundry_in_unit' => '',
            'pets_allowed' => '',
            'cooling' => '',
            'heating' => '',
            'basement' => '',
            'fireplace' => '',
            'garage' => '',
            'pool' => '',
            'horses' => '',

            // Date filters
            'available_by' => '',
            'open_house_only' => '',

            // Map settings
            'center_lat' => '',
            'center_lng' => '',
            'zoom' => '',
            'polygon_shapes' => '', // JSON encoded polygon coordinates

            // Display settings
            'show_filters' => 'yes',
            'show_search' => 'yes',
            'show_sidebar' => 'yes',
            'default_view' => 'split', // split, map, or list
            'max_results' => '',
        ], $atts, 'bme_listings_half_map_view' );

        // Process and validate filters
        $filters = $this->process_map_filters( $atts );

        // Store filters for template use without injecting JavaScript
        // The template can access these via $filters variable

        $template_path = MLD_PLUGIN_PATH . 'templates/views/half-map.php';

        if (!file_exists($template_path)) {
            if (class_exists('MLD_Logger')) {
                MLD_Logger::error('Half-map template not found', ['path' => $template_path]);
            }
            return '<div class="mld-error">Half-map template not found.</div>';
        }

        ob_start();
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Add async/defer attributes to improve JavaScript loading performance
     */
    public function add_async_defer_attributes( $tag, $handle ) {
        // List of scripts that can be deferred for better performance
        $defer_scripts = [
            'mld-main',
            'mld-modules-init',
            'leaflet-markercluster'
        ];
        
        // List of scripts that can be loaded asynchronously
        $async_scripts = [
            'mld-logger'
        ];
        
        if ( in_array( $handle, $defer_scripts ) ) {
            return str_replace( '<script ', '<script defer ', $tag );
        }
        
        if ( in_array( $handle, $async_scripts ) ) {
            return str_replace( '<script ', '<script async ', $tag );
        }
        
        return $tag;
    }

    
    /**
     * Add resource hints for better performance
     */
    public function add_resource_hints() {
        echo '<link rel="preload" href="' . esc_url(MLD_PLUGIN_URL . 'assets/js/leaflet.js') . '" as="script">' . "\n";
        echo '<link rel="preload" href="' . esc_url(MLD_PLUGIN_URL . 'assets/css/leaflet.css') . '" as="style">' . "\n";
        echo '<link rel="dns-prefetch" href="//maps.googleapis.com">' . "\n";
    }

    /**
     * Render user dashboard shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Dashboard HTML
     */
    public function render_user_dashboard( $atts ) {
        // DEPRECATED in v6.34.0: This shortcode now redirects to the Vue.js dashboard.
        // Use [mld_client_dashboard] instead.
        // The old PHP dashboard templates (user-dashboard.php, user-dashboard-enhanced.php)
        // and their assets are deprecated and will be removed in a future version.

        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[mld_user_dashboard]';
        }

        // Redirect to Vue.js dashboard
        if ( class_exists( 'MLD_Client_Dashboard' ) ) {
            return MLD_Client_Dashboard::render_shortcode( $atts );
        }

        // Fallback if class not available
        return '<div class="mld-error">Dashboard not available. Please contact support.</div>';
    }

    /**
     * Render search form shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Search form HTML
     */
    public function render_search_form( $atts ) {
        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[mld_search]';
        }

        // Parse attributes
        $atts = shortcode_atts( [
            'style' => 'horizontal', // horizontal, vertical, compact
            'show_advanced' => 'false',
            'redirect_to' => '', // URL to redirect after search
            'show_city' => 'yes',
            'show_price' => 'yes',
            'show_beds' => 'yes',
            'show_property_type' => 'yes',
        ], $atts, 'bmls_search' );

        // Get redirect URL - default to property search page
        $settings = get_option( 'mld_settings', [] );
        $redirect_url = $atts['redirect_to'] ?:
                       (!empty($settings['search_page_url']) ? home_url($settings['search_page_url']) : home_url('/property-search/'));

        // Enqueue styles
        if ( ! is_admin() ) {
            wp_enqueue_style(
                'mld-search-form',
                MLD_PLUGIN_URL . 'assets/css/mld-search-form.css',
                [],
                MLD_VERSION
            );
        }

        ob_start();
        ?>
        <div class="mld-search-form-wrapper mld-search-<?php echo esc_attr($atts['style']); ?>">
            <form class="mld-search-form" method="get" action="<?php echo esc_url($redirect_url); ?>">

                <?php if ($atts['show_city'] === 'yes'): ?>
                <div class="mld-search-field">
                    <label for="mld-search-city">Location</label>
                    <input type="text" name="city" id="mld-search-city" placeholder="City or ZIP code">
                </div>
                <?php endif; ?>

                <?php if ($atts['show_price'] === 'yes'): ?>
                <div class="mld-search-field mld-price-range">
                    <label>Price Range</label>
                    <div class="mld-price-inputs">
                        <input type="number" name="min_price" placeholder="Min Price" step="10000">
                        <span class="mld-price-separator">to</span>
                        <input type="number" name="max_price" placeholder="Max Price" step="10000">
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($atts['show_beds'] === 'yes'): ?>
                <div class="mld-search-field mld-beds-baths">
                    <label>Beds & Baths</label>
                    <div class="mld-beds-baths-inputs">
                        <select name="min_beds">
                            <option value="">Beds</option>
                            <option value="1">1+</option>
                            <option value="2">2+</option>
                            <option value="3">3+</option>
                            <option value="4">4+</option>
                            <option value="5">5+</option>
                        </select>
                        <select name="min_baths">
                            <option value="">Baths</option>
                            <option value="1">1+</option>
                            <option value="2">2+</option>
                            <option value="3">3+</option>
                            <option value="4">4+</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($atts['show_property_type'] === 'yes'): ?>
                <div class="mld-search-field">
                    <label for="mld-search-type">Property Type</label>
                    <select name="property_type" id="mld-search-type">
                        <option value="">All Types</option>
                        <option value="Single Family">Single Family</option>
                        <option value="Condo">Condo</option>
                        <option value="Multi-Family">Multi-Family</option>
                        <option value="Townhouse">Townhouse</option>
                        <option value="Land">Land</option>
                        <option value="Commercial">Commercial</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mld-search-submit">
                    <button type="submit" class="mld-search-button">
                        <span class="dashicons dashicons-search"></span>
                        Search Properties
                    </button>
                </div>

                <?php if ($atts['show_advanced'] === 'true'): ?>
                <div class="mld-search-advanced">
                    <a href="<?php echo esc_url($redirect_url); ?>" class="mld-advanced-search-link">
                        Advanced Search <span class="dashicons dashicons-arrow-right-alt"></span>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render property listings grid shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Listings grid HTML
     */
    public function render_listings_grid( $atts ) {
        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[bmls_listings]';
        }

        // Parse attributes
        $atts = shortcode_atts( [
            'per_page' => 12,
            'layout' => 'grid', // grid or list
            'sort_by' => 'newest', // price_asc, price_desc, newest, oldest
            'city' => '',
            'min_price' => '',
            'max_price' => '',
            'property_type' => '',
            'min_beds' => '',
            'min_baths' => '',
        ], $atts, 'bmls_listings' );

        // Enqueue styles
        if ( ! is_admin() ) {
            wp_enqueue_style(
                'mld-listings-grid',
                MLD_PLUGIN_URL . 'assets/css/mld-listings-grid.css',
                [],
                MLD_VERSION
            );

            wp_enqueue_script(
                'mld-listings-grid',
                MLD_PLUGIN_URL . 'assets/js/mld-listings-grid.js',
                ['jquery'],
                MLD_VERSION,
                true
            );

            wp_localize_script( 'mld-listings-grid', 'mldListingsData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'mld_listings_nonce' ),
            ]);
        }

        // Get current page
        $paged = get_query_var('paged') ? get_query_var('paged') : 1;

        // Build query parameters
        $query_args = [
            'per_page' => intval($atts['per_page']),
            'page' => $paged,
            'sort_by' => $atts['sort_by']
        ];

        // Add filters if provided
        if (!empty($atts['city'])) $query_args['city'] = $atts['city'];
        if (!empty($atts['min_price'])) $query_args['min_price'] = $atts['min_price'];
        if (!empty($atts['max_price'])) $query_args['max_price'] = $atts['max_price'];
        if (!empty($atts['property_type'])) $query_args['property_type'] = $atts['property_type'];
        if (!empty($atts['min_beds'])) $query_args['min_beds'] = $atts['min_beds'];
        if (!empty($atts['min_baths'])) $query_args['min_baths'] = $atts['min_baths'];

        // Get listings using MLD_Query
        $query = new MLD_Query();
        $listings = $query->get_listings($query_args);
        $total = $query->get_total_count();

        ob_start();
        ?>
        <div class="mld-listings-wrapper">
            <div class="mld-listings-header">
                <div class="mld-listings-count">
                    <?php echo sprintf(__('Showing %d of %d properties', 'mld'), count($listings), $total); ?>
                </div>
                <div class="mld-listings-controls">
                    <div class="mld-layout-toggle">
                        <button class="mld-layout-btn <?php echo $atts['layout'] === 'grid' ? 'active' : ''; ?>" data-layout="grid">
                            <span class="dashicons dashicons-grid-view"></span>
                        </button>
                        <button class="mld-layout-btn <?php echo $atts['layout'] === 'list' ? 'active' : ''; ?>" data-layout="list">
                            <span class="dashicons dashicons-list-view"></span>
                        </button>
                    </div>
                    <select class="mld-sort-select" data-current="<?php echo esc_attr($atts['sort_by']); ?>">
                        <option value="newest" <?php selected($atts['sort_by'], 'newest'); ?>>Newest First</option>
                        <option value="oldest" <?php selected($atts['sort_by'], 'oldest'); ?>>Oldest First</option>
                        <option value="price_asc" <?php selected($atts['sort_by'], 'price_asc'); ?>>Price: Low to High</option>
                        <option value="price_desc" <?php selected($atts['sort_by'], 'price_desc'); ?>>Price: High to Low</option>
                    </select>
                </div>
            </div>

            <div class="mld-listings-container mld-layout-<?php echo esc_attr($atts['layout']); ?>" data-page="<?php echo $paged; ?>">
                <?php if (!empty($listings)): ?>
                    <?php foreach ($listings as $listing): ?>
                        <div class="mld-listing-item">
                            <div class="mld-listing-image">
                                <?php
                                $image_url = !empty($listing['Media']) && !empty($listing['Media'][0]['MediaURL'])
                                           ? $listing['Media'][0]['MediaURL']
                                           : MLD_PLUGIN_URL . 'assets/images/no-image.jpg';
                                ?>
                                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($listing['UnparsedAddress'] ?? ''); ?>">
                                <div class="mld-listing-price">
                                    $<?php echo number_format($listing['ListPrice'] ?? 0); ?>
                                </div>
                            </div>
                            <div class="mld-listing-details">
                                <h3 class="mld-listing-address">
                                    <?php echo esc_html($listing['UnparsedAddress'] ?? 'Address Not Available'); ?>
                                </h3>
                                <div class="mld-listing-info">
                                    <?php if (!empty($listing['BedroomsTotal'])): ?>
                                        <span><strong><?php echo $listing['BedroomsTotal']; ?></strong> Beds</span>
                                    <?php endif; ?>
                                    <?php if (!empty($listing['BathroomsFull'])): ?>
                                        <span><strong><?php echo $listing['BathroomsFull']; ?></strong> Baths</span>
                                    <?php endif; ?>
                                    <?php if (!empty($listing['LivingArea'])): ?>
                                        <span><strong><?php echo number_format($listing['LivingArea']); ?></strong> Sq Ft</span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?php echo home_url('/property/' . $listing['ListingId']); ?>" class="mld-listing-link">
                                    View Details <span class="dashicons dashicons-arrow-right-alt"></span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mld-no-listings">
                        <p><?php _e('No properties found matching your criteria.', 'mld'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total > $atts['per_page']): ?>
            <div class="mld-pagination">
                <?php
                $total_pages = ceil($total / $atts['per_page']);
                $base_url = get_permalink();

                for ($i = 1; $i <= $total_pages; $i++) {
                    $url = $i > 1 ? add_query_arg('paged', $i, $base_url) : $base_url;
                    $active = $i == $paged ? 'active' : '';
                    echo '<a href="' . esc_url($url) . '" class="mld-page-link ' . $active . '">' . $i . '</a>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render featured properties shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string Featured properties HTML
     */
    public function render_featured_properties( $atts ) {
        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[bmls_featured]';
        }

        // Parse attributes
        $atts = shortcode_atts( [
            'count' => 6,
            'layout' => 'carousel', // carousel or grid
            'city' => '',
            'min_price' => '',
            'max_price' => '',
            'property_type' => '',
        ], $atts, 'bmls_featured' );

        // Enqueue styles
        if ( ! is_admin() ) {
            wp_enqueue_style(
                'mld-featured-properties',
                MLD_PLUGIN_URL . 'assets/css/mld-featured-properties.css',
                [],
                MLD_VERSION
            );

            if ($atts['layout'] === 'carousel') {
                wp_enqueue_script(
                    'mld-featured-carousel',
                    MLD_PLUGIN_URL . 'assets/js/mld-featured-carousel.js',
                    ['jquery'],
                    MLD_VERSION,
                    true
                );
            }
        }

        // Build query parameters for featured/newest properties
        $query_args = [
            'per_page' => intval($atts['count']),
            'page' => 1,
            'sort_by' => 'newest'
        ];

        // Add filters if provided
        if (!empty($atts['city'])) $query_args['city'] = $atts['city'];
        if (!empty($atts['min_price'])) $query_args['min_price'] = $atts['min_price'];
        if (!empty($atts['max_price'])) $query_args['max_price'] = $atts['max_price'];
        if (!empty($atts['property_type'])) $query_args['property_type'] = $atts['property_type'];

        // Get listings using MLD_Query
        $query = new MLD_Query();
        $listings = $query->get_listings($query_args);

        ob_start();
        ?>
        <div class="mld-featured-wrapper">
            <div class="mld-featured-header">
                <h2 class="mld-featured-title"><?php _e('Featured Properties', 'mld'); ?></h2>
            </div>

            <div class="mld-featured-container mld-featured-<?php echo esc_attr($atts['layout']); ?>">
                <?php if (!empty($listings)): ?>
                    <?php if ($atts['layout'] === 'carousel'): ?>
                        <div class="mld-featured-carousel">
                            <button class="mld-carousel-prev" aria-label="Previous">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </button>
                            <div class="mld-carousel-track">
                    <?php endif; ?>

                    <?php foreach ($listings as $listing): ?>
                        <div class="mld-featured-item">
                            <div class="mld-featured-image">
                                <?php
                                $image_url = !empty($listing['Media']) && !empty($listing['Media'][0]['MediaURL'])
                                           ? $listing['Media'][0]['MediaURL']
                                           : MLD_PLUGIN_URL . 'assets/images/no-image.jpg';
                                ?>
                                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($listing['UnparsedAddress'] ?? ''); ?>">
                                <div class="mld-featured-badge">Featured</div>
                                <div class="mld-featured-price">
                                    $<?php echo number_format($listing['ListPrice'] ?? 0); ?>
                                </div>
                            </div>
                            <div class="mld-featured-details">
                                <h3 class="mld-featured-address">
                                    <?php echo esc_html($listing['UnparsedAddress'] ?? 'Address Not Available'); ?>
                                </h3>
                                <div class="mld-featured-info">
                                    <?php if (!empty($listing['BedroomsTotal'])): ?>
                                        <span><?php echo $listing['BedroomsTotal']; ?> Beds</span>
                                    <?php endif; ?>
                                    <?php if (!empty($listing['BathroomsFull'])): ?>
                                        <span><?php echo $listing['BathroomsFull']; ?> Baths</span>
                                    <?php endif; ?>
                                    <?php if (!empty($listing['LivingArea'])): ?>
                                        <span><?php echo number_format($listing['LivingArea']); ?> Sq Ft</span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?php echo home_url('/property/' . $listing['ListingId']); ?>" class="mld-featured-link">
                                    View Property
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($atts['layout'] === 'carousel'): ?>
                            </div>
                            <button class="mld-carousel-next" aria-label="Next">
                                <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mld-no-featured">
                        <p><?php _e('No featured properties available at this time.', 'mld'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Process map filter attributes into the format expected by the JavaScript
     *
     * @param array $atts Shortcode attributes
     * @return array Processed filters
     */
    private function process_map_filters( $atts ) {
        $filters = [];

        // Location filters (keyword filters in JS)
        if ( ! empty( $atts['city'] ) ) {
            $filters['City'] = explode( ',', $atts['city'] );
        }
        if ( ! empty( $atts['neighborhood'] ) ) {
            $filters['Neighborhood'] = explode( ',', $atts['neighborhood'] );
        }
        if ( ! empty( $atts['postal_code'] ) ) {
            $filters['Postal Code'] = explode( ',', $atts['postal_code'] );
        }
        if ( ! empty( $atts['street_name'] ) ) {
            $filters['Street Name'] = explode( ',', $atts['street_name'] );
        }
        if ( ! empty( $atts['building'] ) ) {
            $filters['Building'] = explode( ',', $atts['building'] );
        }
        if ( ! empty( $atts['address'] ) ) {
            $filters['Address'] = explode( ',', $atts['address'] );
        }
        if ( ! empty( $atts['mls_number'] ) ) {
            $filters['MLS Number'] = explode( ',', $atts['mls_number'] );
        }

        // Property type (special handling)
        if ( ! empty( $atts['property_type'] ) ) {
            $filters['PropertyType'] = $atts['property_type'];
        }

        // Modal filters
        $modal_filters = [];

        // Home type (PropertySubType)
        if ( ! empty( $atts['home_type'] ) ) {
            $modal_filters['home_type'] = explode( ',', $atts['home_type'] );
        }

        // Structure and style
        if ( ! empty( $atts['structure_type'] ) ) {
            $modal_filters['structure_type'] = explode( ',', $atts['structure_type'] );
        }
        if ( ! empty( $atts['architectural_style'] ) ) {
            $modal_filters['architectural_style'] = explode( ',', $atts['architectural_style'] );
        }

        // Price
        if ( ! empty( $atts['price_min'] ) ) {
            $modal_filters['price_min'] = intval( $atts['price_min'] );
        }
        if ( ! empty( $atts['price_max'] ) ) {
            $modal_filters['price_max'] = intval( $atts['price_max'] );
        }

        // Beds (special handling for array or "X+" format)
        if ( ! empty( $atts['beds'] ) ) {
            if ( strpos( $atts['beds'], '+' ) !== false ) {
                // Handle "2+" format
                $modal_filters['beds'] = [ str_replace( '+', '', $atts['beds'] ) . '+' ];
            } else {
                // Handle comma-separated list
                $modal_filters['beds'] = explode( ',', $atts['beds'] );
            }
        }

        // Numeric filters
        $numeric_filters = [
            'baths_min', 'sqft_min', 'sqft_max', 'lot_size_min', 'lot_size_max',
            'year_built_min', 'year_built_max', 'entry_level_min', 'entry_level_max',
            'garage_spaces_min', 'parking_total_min'
        ];

        foreach ( $numeric_filters as $filter ) {
            if ( ! empty( $atts[ $filter ] ) ) {
                $modal_filters[ $filter ] = is_numeric( $atts[ $filter ] ) ?
                    floatval( $atts[ $filter ] ) : $atts[ $filter ];
            }
        }

        // Status (default to Active if not specified)
        $modal_filters['status'] = ! empty( $atts['status'] ) ?
            explode( ',', $atts['status'] ) : [ 'Active' ];

        // Boolean filters - map shortcode attribute names to JS filter names
        $boolean_mappings = [
            'spa' => 'SpaYN',
            'waterfront' => 'WaterfrontYN',
            'view' => 'ViewYN',
            'waterview' => 'MLSPIN_WATERVIEW_FLAG',
            'attached' => 'PropertyAttachedYN',
            'lender_owned' => 'MLSPIN_LENDER_OWNED',
            'available_now' => 'MLSPIN_AvailableNow',
            'senior_community' => 'SeniorCommunityYN',
            'outdoor_space' => 'MLSPIN_OUTDOOR_SPACE_AVAILABLE',
            'dpr' => 'MLSPIN_DPR_Flag',
            'laundry_in_unit' => 'LaundryInUnit',
            'pets_allowed' => 'PetsAllowed',
            'cooling' => 'Cooling',
            'heating' => 'Heating',
            'basement' => 'BasementYN',
            'fireplace' => 'FireplaceYN',
            'garage' => 'GarageYN',
            'pool' => 'PoolPrivateYN',
            'horses' => 'HorseYN',
            'open_house_only' => 'open_house_only'
        ];

        foreach ( $boolean_mappings as $attr => $filter_name ) {
            if ( ! empty( $atts[ $attr ] ) ) {
                $value = strtolower( $atts[ $attr ] );
                if ( $value === 'yes' || $value === 'true' || $value === '1' ) {
                    $modal_filters[ $filter_name ] = true;
                }
            }
        }

        // Date filters
        if ( ! empty( $atts['available_by'] ) ) {
            $modal_filters['available_by'] = $atts['available_by'];
        }

        // Map settings
        if ( ! empty( $atts['center_lat'] ) && ! empty( $atts['center_lng'] ) ) {
            $filters['mapCenter'] = [
                'lat' => floatval( $atts['center_lat'] ),
                'lng' => floatval( $atts['center_lng'] )
            ];
        }
        if ( ! empty( $atts['zoom'] ) ) {
            $filters['mapZoom'] = intval( $atts['zoom'] );
        }
        if ( ! empty( $atts['polygon_shapes'] ) ) {
            // Decode JSON polygon data
            $polygons = json_decode( $atts['polygon_shapes'], true );
            if ( $polygons ) {
                $filters['polygon_shapes'] = $polygons;
            }
        }

        // Display settings
        $filters['displaySettings'] = [
            'showFilters' => $atts['show_filters'] === 'yes',
            'showSearch' => $atts['show_search'] === 'yes',
            'showSidebar' => $atts['show_sidebar'] === 'yes',
            'defaultView' => $atts['default_view']
        ];

        if ( ! empty( $atts['max_results'] ) ) {
            $filters['maxResults'] = intval( $atts['max_results'] );
        }

        // Add modal filters if any exist
        if ( ! empty( $modal_filters ) ) {
            $filters['modalFilters'] = $modal_filters;
        }

        return $filters;
    }

    /**
     * Get cached list of available cities for instant client-side lookup
     *
     * @return array List of cities with counts
     * @since 4.5.35
     */
    private function get_available_cities_cached() {
        // Try to get from cache first (24 hour cache)
        $cache_key = 'mld_available_cities_list';
        $cached_cities = get_transient($cache_key);

        if ($cached_cities !== false) {
            return $cached_cities;
        }

        // Get cities from database
        global $wpdb;

        // Try to get the data provider instance
        $provider = null;
        if (class_exists('MLD_BME_Data_Provider')) {
            $provider = MLD_BME_Data_Provider::get_instance();
        }

        if (!$provider || !$provider->is_available()) {
            // Fallback: return empty array if provider not available
            // This happens when Bridge MLS plugin is not active
            return [];
        }

        $tables = $provider->get_tables();

        // Check if we got valid tables
        if (empty($tables) || !isset($tables['listings']) || !isset($tables['listing_location'])) {
            // Tables not available, return empty array
            return [];
        }

        // Query to get all cities with active listings and their counts
        // Using optimized query structure from the AJAX handler
        $cities = $wpdb->get_results(
            "SELECT ll.city as name,
                    COUNT(DISTINCT ll.listing_id) as count
             FROM {$tables['listing_location']} ll
             WHERE ll.city IS NOT NULL
             AND ll.city != ''
             AND EXISTS (
                 SELECT 1 FROM {$tables['listings']} l
                 WHERE l.listing_id = ll.listing_id
                 AND l.standard_status = 'Active'
                 LIMIT 1
             )
             GROUP BY ll.city
             ORDER BY ll.city ASC",
            ARRAY_A
        );

        // Create a simple array of city names for faster JavaScript lookup
        // Include both exact names and lowercase versions for case-insensitive matching
        $city_list = [];
        if ($cities && is_array($cities)) {
            foreach ($cities as $city) {
                if (!empty($city['name'])) {
                    $city_list[] = [
                        'name' => $city['name'],
                        'name_lower' => strtolower($city['name']),
                        'count' => intval($city['count'])
                    ];
                }
            }
        }

        // Cache for 24 hours
        set_transient($cache_key, $city_list, 24 * HOUR_IN_SECONDS);

        return $city_list;
    }

    /**
     * Render listing cards shortcode with infinite scroll support
     *
     * Supports all 19+ filter options from the search pages and displays
     * property cards with optional infinite scroll pagination.
     *
     * @param array $atts Shortcode attributes
     * @return string Listing cards HTML
     * @since 6.11.21
     */
    public function render_listing_cards( $atts ) {
        // Don't process during admin save operations
        if ( is_admin() && ! wp_doing_ajax() ) {
            return '[mld_listing_cards]';
        }

        // Parse all supported attributes
        $atts = shortcode_atts( [
            // Location Filters
            'city'               => '',
            'postal_code'        => '',
            'street_name'        => '',
            'listing_id'         => '',
            'neighborhood'       => '',

            // Property Type Filters
            'property_type'      => '',      // Residential, Commercial, Land
            'home_type'          => '',      // Single Family, Condo, etc. (PropertySubType)
            'structure_type'     => '',      // Detached, Attached
            'architectural_style'=> '',      // Colonial, Cape, Ranch

            // Status
            'status'             => 'Active', // Active, Under Agreement, Sold

            // Price/Size Filters
            'price_min'          => '',
            'price_max'          => '',
            'beds'               => '',      // Comma-separated: "2,3,4" or "3+"
            'baths_min'          => '',
            'sqft_min'           => '',
            'sqft_max'           => '',
            'lot_size_min'       => '',
            'lot_size_max'       => '',
            'year_built_min'     => '',
            'year_built_max'     => '',

            // Amenities (boolean filters)
            'garage_spaces_min'  => '',
            'has_pool'           => '',
            'has_fireplace'      => '',
            'has_basement'       => '',
            'pet_friendly'       => '',
            'open_house_only'    => '',
            // New amenity filters for full parity with Half Map Search
            'waterfront'         => '',
            'view'               => '',
            'spa'                => '',
            'has_hoa'            => '',
            'senior_community'   => '',
            'horse_property'     => '',

            // Agent Filters
            'agent_ids'          => '',      // Generic agent filter - matches listing agent, buyer agent, or team member
            'listing_agent_id'   => '',      // Seller's agent (list agent MLS ID)
            'buyer_agent_id'     => '',      // Buyer's agent MLS ID

            // Display Options
            'per_page'           => 12,
            'columns'            => 3,       // 2, 3, or 4
            'sort_by'            => 'newest', // newest, oldest, price_asc, price_desc
            'infinite_scroll'    => 'yes',
            'show_count'         => 'yes',
            'show_sort'          => 'yes',
            'show_view_mode'     => 'yes',   // Show Card/Grid/Compact toggle (v6.57.0)
            'default_view'       => 'card',  // card, grid, or compact
        ], $atts, 'mld_listing_cards' );

        // Generate unique ID for this instance
        $unique_id = 'mld-cards-' . wp_rand( 1000, 9999 );

        // Build filters array for query
        $filters = $this->build_listing_cards_filters( $atts );

        // Get data provider
        $provider = null;
        if ( class_exists( 'MLD_BME_Data_Provider' ) ) {
            $provider = MLD_BME_Data_Provider::get_instance();
        }

        if ( ! $provider || ! $provider->is_available() ) {
            return '<div class="mld-listing-cards-error">Property data is currently unavailable.</div>';
        }

        // Get listings
        $per_page = absint( $atts['per_page'] );
        $per_page = min( $per_page, 50 ); // Cap at 50 per page

        $listings = $provider->get_listings( $filters, $per_page, 0 );
        $total = $provider->get_listing_count( $filters );

        // Enqueue assets
        if ( ! is_admin() ) {
            wp_enqueue_style(
                'mld-listings-grid',
                MLD_PLUGIN_URL . 'assets/css/mld-listings-grid.css',
                [ 'mld-design-tokens' ],
                MLD_VERSION
            );

            // Always load the listings grid JS for favorite/hide/share/view mode
            wp_enqueue_script(
                'mld-listings-grid',
                MLD_PLUGIN_URL . 'assets/js/mld-listings-grid.js',
                [ 'jquery' ],
                MLD_VERSION,
                true
            );

            wp_localize_script( 'mld-listings-grid', 'mldListingsData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'mld_ajax_nonce' ),
            ]);

            // Load infinite scroll JS when enabled
            if ( $atts['infinite_scroll'] === 'yes' || $atts['show_sort'] === 'yes' ) {
                wp_enqueue_script(
                    'mld-listings-infinite',
                    MLD_PLUGIN_URL . 'assets/js/mld-listings-infinite.js',
                    [ 'jquery', 'mld-listings-grid' ],
                    MLD_VERSION,
                    true
                );

                wp_localize_script( 'mld-listings-infinite', 'mldCardsData', [
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'mld_cards_nonce' ),
                ]);
            }
        }

        // Determine columns class
        $columns = absint( $atts['columns'] );
        $columns = in_array( $columns, [ 2, 3, 4 ] ) ? $columns : 3;

        // Prepare data attributes for infinite scroll
        $data_attrs = [
            'data-filters'      => esc_attr( wp_json_encode( $filters ) ),
            'data-page'         => '1',
            'data-per-page'     => esc_attr( $per_page ),
            'data-total'        => esc_attr( $total ),
            'data-has-more'     => ( $total > $per_page ) ? 'true' : 'false',
            'data-sort'         => esc_attr( $atts['sort_by'] ),
            'data-default-view' => esc_attr( $atts['default_view'] ),
        ];

        $data_string = '';
        foreach ( $data_attrs as $key => $value ) {
            $data_string .= " {$key}=\"{$value}\"";
        }

        ob_start();

        // Define view mode icons as SVG
        $card_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="18" rx="1"/></svg>';
        $grid_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';
        $compact_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/></svg>';
        ?>
        <div class="mld-listing-cards-wrapper" id="<?php echo esc_attr( $unique_id ); ?>"<?php echo $data_string; ?>>
            <?php if ( $atts['show_count'] === 'yes' || $atts['show_sort'] === 'yes' || $atts['show_view_mode'] === 'yes' ) : ?>
            <div class="mld-cards-header">
                <?php if ( $atts['show_count'] === 'yes' ) : ?>
                <div class="mld-cards-count">
                    Showing <span class="mld-shown-count"><?php echo count( $listings ); ?></span>
                    of <span class="mld-total-count"><?php echo number_format( $total ); ?></span> properties
                </div>
                <?php endif; ?>

                <div class="mld-cards-controls">
                    <?php if ( $atts['show_view_mode'] === 'yes' ) : ?>
                    <div class="mld-view-mode-toggle" data-grid-id="<?php echo esc_attr( $unique_id ); ?>">
                        <button type="button" class="mld-view-mode-btn <?php echo $atts['default_view'] === 'card' ? 'active' : ''; ?>" data-view="card" title="Card View">
                            <?php echo $card_icon; ?>
                        </button>
                        <button type="button" class="mld-view-mode-btn <?php echo $atts['default_view'] === 'grid' ? 'active' : ''; ?>" data-view="grid" title="Grid View">
                            <?php echo $grid_icon; ?>
                        </button>
                        <button type="button" class="mld-view-mode-btn <?php echo $atts['default_view'] === 'compact' ? 'active' : ''; ?>" data-view="compact" title="Compact View">
                            <?php echo $compact_icon; ?>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if ( $atts['show_sort'] === 'yes' ) : ?>
                    <select class="mld-cards-sort" data-grid-id="<?php echo esc_attr( $unique_id ); ?>">
                        <option value="newest" <?php selected( $atts['sort_by'], 'newest' ); ?>>Newest First</option>
                        <option value="oldest" <?php selected( $atts['sort_by'], 'oldest' ); ?>>Oldest First</option>
                        <option value="price_asc" <?php selected( $atts['sort_by'], 'price_asc' ); ?>>Price: Low to High</option>
                        <option value="price_desc" <?php selected( $atts['sort_by'], 'price_desc' ); ?>>Price: High to Low</option>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Build view class based on default view
            $view_class = '';
            if ( $atts['default_view'] === 'grid' ) {
                $view_class = 'mld-view-grid';
            } elseif ( $atts['default_view'] === 'compact' ) {
                $view_class = 'mld-view-compact';
            }
            ?>
            <div class="mld-cards-grid mld-cols-<?php echo esc_attr( $columns ); ?> <?php echo esc_attr( $view_class ); ?>">
                <?php if ( ! empty( $listings ) ) : ?>
                    <?php foreach ( $listings as $listing ) : ?>
                        <?php include MLD_PLUGIN_PATH . 'templates/partials/listing-card.php'; ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mld-cards-no-results">
                        <p>No properties found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $atts['infinite_scroll'] === 'yes' && $total > $per_page ) : ?>
            <div class="mld-cards-loading" style="display: none;">
                <div class="mld-cards-spinner"></div>
                <span>Loading more properties...</span>
            </div>
            <div class="mld-cards-end" style="display: none;">
                <p>You've reached the end of the listings.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build filters array from shortcode attributes for listing cards
     *
     * @param array $atts Shortcode attributes
     * @return array Filters for data provider
     * @since 6.11.21
     */
    private function build_listing_cards_filters( $atts ) {
        $filters = [];

        // Status filter (default Active)
        if ( ! empty( $atts['status'] ) ) {
            $statuses = array_map( 'trim', explode( ',', $atts['status'] ) );
            $filters['status'] = $statuses;
        }

        // Location filters
        if ( ! empty( $atts['city'] ) ) {
            $filters['city'] = array_map( 'trim', explode( ',', $atts['city'] ) );
        }
        if ( ! empty( $atts['postal_code'] ) ) {
            $filters['postal_code'] = array_map( 'trim', explode( ',', $atts['postal_code'] ) );
        }
        if ( ! empty( $atts['street_name'] ) ) {
            $filters['street_name'] = array_map( 'trim', explode( ',', $atts['street_name'] ) );
        }
        if ( ! empty( $atts['listing_id'] ) ) {
            $filters['listing_id'] = array_map( 'trim', explode( ',', $atts['listing_id'] ) );
        }
        if ( ! empty( $atts['neighborhood'] ) ) {
            $filters['neighborhood'] = array_map( 'trim', explode( ',', $atts['neighborhood'] ) );
        }

        // Property type filters
        if ( ! empty( $atts['property_type'] ) ) {
            $filters['property_type'] = $atts['property_type'];
        }
        if ( ! empty( $atts['home_type'] ) ) {
            $filters['home_type'] = array_map( 'trim', explode( ',', $atts['home_type'] ) );
        }
        if ( ! empty( $atts['structure_type'] ) ) {
            $filters['structure_type'] = array_map( 'trim', explode( ',', $atts['structure_type'] ) );
        }
        if ( ! empty( $atts['architectural_style'] ) ) {
            $filters['architectural_style'] = array_map( 'trim', explode( ',', $atts['architectural_style'] ) );
        }

        // Price filters (data provider expects min_price/max_price)
        if ( ! empty( $atts['price_min'] ) ) {
            $filters['min_price'] = absint( $atts['price_min'] );
        }
        if ( ! empty( $atts['price_max'] ) ) {
            $filters['max_price'] = absint( $atts['price_max'] );
        }

        // Beds filter (supports "2,3,4" or "3+") - data provider expects min_beds
        if ( ! empty( $atts['beds'] ) ) {
            if ( strpos( $atts['beds'], '+' ) !== false ) {
                $filters['min_beds'] = absint( str_replace( '+', '', $atts['beds'] ) );
            } else {
                // For specific bed counts, use minimum of the list
                $bed_values = array_map( 'absint', explode( ',', $atts['beds'] ) );
                $filters['min_beds'] = min( $bed_values );
            }
        }

        // Baths filter - data provider expects min_baths
        if ( ! empty( $atts['baths_min'] ) ) {
            $filters['min_baths'] = floatval( $atts['baths_min'] );
        }

        // Size filters - data provider expects min_sqft/max_sqft
        if ( ! empty( $atts['sqft_min'] ) ) {
            $filters['min_sqft'] = absint( $atts['sqft_min'] );
        }
        if ( ! empty( $atts['sqft_max'] ) ) {
            $filters['max_sqft'] = absint( $atts['sqft_max'] );
        }
        if ( ! empty( $atts['lot_size_min'] ) ) {
            $filters['lot_size_min'] = floatval( $atts['lot_size_min'] );
        }
        if ( ! empty( $atts['lot_size_max'] ) ) {
            $filters['lot_size_max'] = floatval( $atts['lot_size_max'] );
        }

        // Year built filters
        if ( ! empty( $atts['year_built_min'] ) ) {
            $filters['year_built_min'] = absint( $atts['year_built_min'] );
        }
        if ( ! empty( $atts['year_built_max'] ) ) {
            $filters['year_built_max'] = absint( $atts['year_built_max'] );
        }

        // Amenity/feature filters
        if ( ! empty( $atts['garage_spaces_min'] ) ) {
            $filters['garage_spaces_min'] = absint( $atts['garage_spaces_min'] );
        }

        // Boolean amenity filters
        // Use string 'yes' for consistency with data provider expectations
        // Includes new filters for full parity with Half Map Search
        $boolean_filters = [
            'has_pool', 'has_fireplace', 'has_basement', 'pet_friendly', 'open_house_only',
            'waterfront', 'view', 'spa', 'has_hoa', 'senior_community', 'horse_property'
        ];
        foreach ( $boolean_filters as $filter ) {
            if ( ! empty( $atts[ $filter ] ) ) {
                $value = strtolower( $atts[ $filter ] );
                if ( $value === 'yes' || $value === 'true' || $value === '1' ) {
                    $filters[ $filter ] = 'yes';  // Use 'yes' for consistency
                }
            }
        }

        // Agent filters
        // agent_ids: Generic filter that matches listing agent, buyer agent, or team member (OR logic)
        if ( ! empty( $atts['agent_ids'] ) ) {
            $filters['agent_ids'] = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $atts['agent_ids'] ) ) );
        }
        // Specific agent filters (listing agent = seller's agent, buyer agent = buyer's agent)
        if ( ! empty( $atts['listing_agent_id'] ) ) {
            $filters['listing_agent_id'] = sanitize_text_field( $atts['listing_agent_id'] );
        }
        if ( ! empty( $atts['buyer_agent_id'] ) ) {
            $filters['buyer_agent_id'] = sanitize_text_field( $atts['buyer_agent_id'] );
        }

        // Sort order
        $sort_by = $atts['sort_by'];
        switch ( $sort_by ) {
            case 'price_asc':
                $filters['orderby'] = 'list_price';
                $filters['order'] = 'ASC';
                break;
            case 'price_desc':
                $filters['orderby'] = 'list_price';
                $filters['order'] = 'DESC';
                break;
            case 'oldest':
                $filters['orderby'] = 'modification_timestamp';
                $filters['order'] = 'ASC';
                break;
            case 'newest':
            default:
                $filters['orderby'] = 'modification_timestamp';
                $filters['order'] = 'DESC';
                break;
        }

        return $filters;
    }
}
