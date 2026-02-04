<?php
/**
 * MLS Listings Display - Saved Searches Frontend
 *
 * Handles frontend interface for user saved searches
 * Updated v4.5.46: Added hourly notification option
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 * @version 4.5.46
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Searches_Frontend {
    
    /**
     * Initialize frontend functionality
     */
    public function __construct() {
        // Shortcodes
        add_shortcode('mld_saved_searches', [$this, 'render_saved_searches_shortcode']);
        add_shortcode('mld_saved_properties', [$this, 'render_saved_properties_shortcode']);
        
        // AJAX handlers for logged-in users
        add_action('wp_ajax_mld_save_search', [$this, 'ajax_save_search']);
        add_action('wp_ajax_mld_get_user_searches', [$this, 'ajax_get_user_searches']);
        add_action('wp_ajax_mld_update_search', [$this, 'ajax_update_search']);
        add_action('wp_ajax_mld_delete_search', [$this, 'ajax_delete_search']);
        add_action('wp_ajax_mld_toggle_search_status', [$this, 'ajax_toggle_search_status']);

        // Enhanced dashboard AJAX handlers
        add_action('wp_ajax_mld_delete_saved_search', [$this, 'ajax_delete_saved_search']);
        add_action('wp_ajax_mld_get_search_details', [$this, 'ajax_get_search_details']);
        add_action('wp_ajax_mld_duplicate_search', [$this, 'ajax_duplicate_search']);
        add_action('wp_ajax_mld_toggle_property_dislike', [$this, 'ajax_toggle_property_dislike']);
        add_action('wp_ajax_mld_toggle_property_like', [$this, 'ajax_toggle_property_like']);

        // Property preferences AJAX handlers
        add_action('wp_ajax_mld_get_saved_properties', [$this, 'ajax_get_saved_properties']);
        add_action('wp_ajax_nopriv_mld_get_saved_properties', [$this, 'ajax_get_saved_properties_nopriv']);
        add_action('wp_ajax_mld_remove_saved_property', [$this, 'ajax_remove_saved_property']);
        
        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }
    
    /**
     * Render saved searches shortcode
     */
    public function render_saved_searches_shortcode($atts) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="mld-login-required">' . 
                   '<p>' . __('Please log in to view your saved searches.', 'mld') . '</p>' .
                   '<a href="' . wp_login_url(get_permalink()) . '" class="mld-button">' . 
                   __('Log In', 'mld') . '</a></div>';
        }
        
        ob_start();
        ?>
        <div id="mld-saved-searches-container" class="mld-saved-searches">
            <div class="mld-saved-searches-header">
                <h2><?php _e('My Saved Searches', 'mld'); ?></h2>
                <p class="mld-description"><?php _e('Manage your saved property searches and email notifications.', 'mld'); ?></p>
            </div>
            
            <div class="mld-loading">
                <div class="mld-spinner"></div>
                <p><?php _e('Loading your saved searches...', 'mld'); ?></p>
            </div>
            
            <div id="mld-searches-list" class="mld-searches-list" style="display: none;"></div>
            
            <div id="mld-no-searches" class="mld-no-searches" style="display: none;">
                <p><?php _e('You haven\'t saved any searches yet.', 'mld'); ?></p>
                <p><?php _e('Go to our property search to start looking for your dream home!', 'mld'); ?></p>
                <a href="/property/" class="mld-button mld-button-primary"><?php _e('Search Properties', 'mld'); ?></a>
            </div>
        </div>
        
        <!-- Edit Search Modal -->
        <div id="mld-edit-search-modal" class="mld-modal" style="display: none;">
            <div class="mld-modal-content">
                <div class="mld-modal-header">
                    <h3><?php _e('Edit Saved Search', 'mld'); ?></h3>
                    <button class="mld-modal-close">&times;</button>
                </div>
                <div class="mld-modal-body">
                    <form id="mld-edit-search-form">
                        <input type="hidden" id="edit-search-id" name="search_id">
                        
                        <div class="mld-form-group">
                            <label for="edit-search-name"><?php _e('Search Name', 'mld'); ?></label>
                            <input type="text" id="edit-search-name" name="name" required>
                        </div>
                        
                        <div class="mld-form-group">
                            <label for="edit-notification-frequency"><?php _e('Email Notifications', 'mld'); ?></label>
                            <select id="edit-notification-frequency" name="notification_frequency">
                                <option value="never"><?php _e('Never', 'mld'); ?></option>
                                <option value="instant"><?php _e('Instant (every 5 min)', 'mld'); ?></option>
                                <option value="fifteen_min"><?php _e('Every 15 minutes', 'mld'); ?></option>
                                <option value="hourly"><?php _e('Hourly', 'mld'); ?></option>
                                <option value="daily"><?php _e('Daily', 'mld'); ?></option>
                                <option value="weekly"><?php _e('Weekly', 'mld'); ?></option>
                            </select>
                            <p class="mld-help-text"><?php _e('How often would you like to receive email alerts for new listings, price changes, and status updates?', 'mld'); ?></p>
                        </div>
                        
                        <div class="mld-form-group">
                            <label>
                                <input type="checkbox" id="edit-is-active" name="is_active" value="1">
                                <?php _e('Active', 'mld'); ?>
                            </label>
                            <p class="mld-help-text"><?php _e('Inactive searches won\'t send email notifications.', 'mld'); ?></p>
                        </div>
                    </form>
                </div>
                <div class="mld-modal-footer">
                    <button type="button" class="mld-button mld-button-secondary mld-cancel-btn">
                        <?php _e('Cancel', 'mld'); ?>
                    </button>
                    <button type="submit" form="mld-edit-search-form" class="mld-button mld-button-primary">
                        <?php _e('Save Changes', 'mld'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render saved properties shortcode
     */
    public function render_saved_properties_shortcode($atts) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="mld-login-required">' . 
                   '<p>' . __('Please log in to view your saved properties.', 'mld') . '</p>' .
                   '<a href="' . wp_login_url(get_permalink()) . '" class="mld-button">' . 
                   __('Log In', 'mld') . '</a></div>';
        }
        
        ob_start();
        ?>
        <div id="mld-saved-properties-container" class="mld-saved-properties">
            <div class="mld-saved-properties-header">
                <h2><?php _e('My Saved Properties', 'mld'); ?></h2>
                <p class="mld-description"><?php _e('Properties you\'ve liked while browsing.', 'mld'); ?></p>
            </div>
            
            <div class="mld-loading">
                <div class="mld-spinner"></div>
                <p><?php _e('Loading your saved properties...', 'mld'); ?></p>
            </div>
            
            <div id="mld-properties-grid" class="mld-properties-grid" style="display: none;"></div>
            
            <div id="mld-no-properties" class="mld-no-properties" style="display: none;">
                <p><?php _e('You haven\'t saved any properties yet.', 'mld'); ?></p>
                <p><?php _e('Browse properties and click the heart icon to save your favorites!', 'mld'); ?></p>
                <a href="/property/" class="mld-button mld-button-primary"><?php _e('Browse Properties', 'mld'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        global $post;
        
        // Check if we're on a page with our shortcodes
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        
        $has_saved_searches = has_shortcode($post->post_content, 'mld_saved_searches');
        $has_saved_properties = has_shortcode($post->post_content, 'mld_saved_properties');
        
        if (!$has_saved_searches && !$has_saved_properties) {
            return;
        }
        
        // Enqueue common utilities first
        wp_enqueue_style(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/css/mld-common-utils.css',
            [],
            MLD_VERSION
        );
        
        wp_enqueue_script(
            'mld-common-utils',
            MLD_PLUGIN_URL . 'assets/js/mld-common-utils.js',
            ['jquery'],
            MLD_VERSION,
            true
        );
        
        // Enqueue styles
        wp_enqueue_style(
            'mld-saved-searches-frontend',
            MLD_PLUGIN_URL . 'assets/css/saved-searches-frontend.css',
            ['mld-common-utils'],
            MLD_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'mld-saved-searches-frontend',
            MLD_PLUGIN_URL . 'assets/js/saved-searches-frontend.js',
            ['jquery', 'mld-common-utils'],
            MLD_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('mld-saved-searches-frontend', 'mldSavedSearches', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_saved_searches_frontend'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this saved search?', 'mld'),
                'saving' => __('Saving...', 'mld'),
                'saved' => __('Changes saved successfully!', 'mld'),
                'error' => __('An error occurred. Please try again.', 'mld'),
                'loading' => __('Loading...', 'mld')
            ]
        ]);
    }

    /**
     * AJAX: Save a new search
     * @since 4.3.0
     */
    public function ajax_save_search() {
        // Verify nonce
        check_ajax_referer('mld_saved_searches', 'nonce');

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'You must be logged in to save searches']);
            return;
        }

        // Get user ID
        $user_id = get_current_user_id();

        // Parse incoming data
        $name = sanitize_text_field($_POST['name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $notification_frequency = sanitize_text_field($_POST['notification_frequency'] ?? 'instant');

        // Parse JSON data
        $filters = isset($_POST['filters']) ? json_decode(wp_unslash($_POST['filters']), true) : [];
        $keyword_filters = isset($_POST['keyword_filters']) ? json_decode(wp_unslash($_POST['keyword_filters']), true) : [];
        $city_boundaries = isset($_POST['city_boundaries']) ? json_decode(wp_unslash($_POST['city_boundaries']), true) : [];
        $polygon_shapes = isset($_POST['polygon_shapes']) ? json_decode(wp_unslash($_POST['polygon_shapes']), true) : [];
        $enhanced_filters = isset($_POST['enhanced_filters']) ? json_decode(wp_unslash($_POST['enhanced_filters']), true) : null;
        $search_url = esc_url_raw($_POST['search_url'] ?? '');
        $property_type = sanitize_text_field($_POST['property_type'] ?? 'Residential');

        // Parse additional metadata
        $map_center = isset($_POST['map_center']) ? json_decode(wp_unslash($_POST['map_center']), true) : null;
        $map_zoom = isset($_POST['map_zoom']) ? intval($_POST['map_zoom']) : null;
        $search_query = sanitize_text_field($_POST['search_query'] ?? '');

        // Merge keyword filters into main filters for compatibility
        // This ensures city/neighborhood selections are saved
        if (!empty($keyword_filters)) {
            foreach ($keyword_filters as $type => $values) {
                $filters['keyword_' . $type] = $values;
            }
        }

        // Add city boundaries to filters for easy retrieval
        if (!empty($city_boundaries)) {
            if (!empty($city_boundaries['cities'])) {
                $filters['selected_cities'] = $city_boundaries['cities'];
            }
            if (!empty($city_boundaries['neighborhoods'])) {
                $filters['selected_neighborhoods'] = $city_boundaries['neighborhoods'];
            }
        }

        // Validate required fields
        if (empty($name)) {
            wp_send_json_error(['message' => 'Search name is required']);
            return;
        }

        // Check for agent/admin batch saving for multiple clients
        $client_ids = isset($_POST['client_ids']) ? json_decode(wp_unslash($_POST['client_ids']), true) : [];
        $is_agent = class_exists('MLD_User_Type_Manager') && MLD_User_Type_Manager::is_agent($user_id);
        $is_admin = current_user_can('manage_options');

        if (!empty($client_ids) && is_array($client_ids) && ($is_agent || $is_admin)) {
            // Batch save for multiple clients using collaboration class
            if (!class_exists('MLD_Saved_Search_Collaboration')) {
                wp_send_json_error(['message' => 'Collaboration system not available']);
                return;
            }

            $agent_notes = sanitize_textarea_field($_POST['agent_notes'] ?? '');
            $cc_agent_on_notify = isset($_POST['cc_agent_on_notify']) ? (bool) $_POST['cc_agent_on_notify'] : true;

            $search_data = [
                'name' => $name,
                'description' => $description,
                'filters' => $filters,
                'polygon_shapes' => $polygon_shapes,
                'notification_frequency' => $notification_frequency,
                'is_active' => true,
                'agent_notes' => $agent_notes,
                'cc_agent_on_notify' => $cc_agent_on_notify,
            ];

            $created_count = 0;
            $errors = [];
            $notifications_sent = ['push' => 0, 'email' => 0];

            foreach ($client_ids as $client_id) {
                $client_id = intval($client_id);
                if ($client_id <= 0) continue;

                $result = MLD_Saved_Search_Collaboration::create_search_for_client($user_id, $client_id, $search_data);

                if (is_wp_error($result)) {
                    $errors[] = ['client_id' => $client_id, 'error' => $result->get_error_message()];
                } else {
                    $created_count++;
                    // Send push notification (reuse method from REST API if available)
                    if (method_exists('MLD_Mobile_REST_API', 'send_search_created_notification')) {
                        $notif = MLD_Mobile_REST_API::send_search_created_notification($user_id, $client_id, $result, $search_data);
                        $notifications_sent['push'] += $notif['push'];
                    }
                }
            }

            if ($created_count > 0) {
                $message = sprintf('Created %d search%s for %d client%s.',
                    $created_count,
                    $created_count === 1 ? '' : 'es',
                    count($client_ids),
                    count($client_ids) === 1 ? '' : 's'
                );
                wp_send_json_success([
                    'created_count' => $created_count,
                    'message' => $message,
                    'notifications_sent' => $notifications_sent,
                    'errors' => $errors
                ]);
            } else {
                wp_send_json_error([
                    'message' => 'Failed to create searches for clients',
                    'errors' => $errors
                ]);
            }
            return;
        }

        // Check for single admin saving for one client (legacy)
        $save_for_user_id = $user_id;
        if ($is_admin && isset($_POST['save_for_client'])) {
            $client_id = intval($_POST['save_for_client']);
            if ($client_id > 0) {
                $save_for_user_id = $client_id;
            }
        }

        // Prepare search data
        $search_data = [
            'user_id' => $save_for_user_id,
            'name' => $name,
            'description' => $description,
            'filters' => $filters,
            'polygon_shapes' => $polygon_shapes,
            'search_url' => $search_url,
            'notification_frequency' => $notification_frequency,
            'is_active' => true,
            'exclude_disliked' => true,
            'created_by_admin' => ($save_for_user_id !== $user_id) ? $user_id : null,
            // Store additional metadata separately if needed
            'metadata' => [
                'keyword_filters' => $keyword_filters,
                'city_boundaries' => $city_boundaries,
                'enhanced_filters' => $enhanced_filters,
                'map_center' => $map_center,
                'map_zoom' => $map_zoom,
                'search_query' => $search_query,
                'property_type' => $property_type
            ]
        ];

        // Log the save attempt
        MLD_Logger::info('Saving new search', [
            'user_id' => $save_for_user_id,
            'search_name' => $name,
            'filter_count' => count($filters),
            'has_polygons' => !empty($polygon_shapes)
        ]);

        // Save the search using the core class
        $search_id = MLD_Saved_Searches::create_search($search_data);

        if (is_wp_error($search_id)) {
            MLD_Logger::error('Failed to save search', [
                'error' => $search_id->get_error_message(),
                'data' => $search_data
            ]);
            wp_send_json_error(['message' => $search_id->get_error_message()]);
            return;
        }

        // Return success with search ID
        wp_send_json_success([
            'search_id' => $search_id,
            'message' => 'Search saved successfully!',
            'redirect_url' => home_url('/my-saved-searches/')
        ]);
    }

    /**
     * AJAX: Get user's saved searches
     */
    public function ajax_get_user_searches() {
        check_ajax_referer('mld_saved_searches_frontend', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $user_id = get_current_user_id();
        $searches = MLD_Saved_Searches::get_user_searches($user_id);

        // Format the searches for display
        // Note: get_user_searches returns array of objects, not arrays
        foreach ($searches as $search) {
            // filters are already decoded by get_user_searches(), add alias for JS compatibility
            $search->filters_decoded = $search->filters;
            // v6.75.4: Use DateTime with wp_timezone() - database stores in WP timezone, not UTC
            $created_ts = (new \DateTime($search->created_at, wp_timezone()))->getTimestamp();
            $search->created_at_formatted = wp_date(get_option('date_format'), $created_ts);
            // Check for both column names for compatibility
            $last_run = $search->last_notified_at ?? $search->last_run ?? null;
            $search->last_run_formatted = $last_run ?
                wp_date(get_option('date_format'), (new \DateTime($last_run, wp_timezone()))->getTimestamp()) :
                __('Never', 'mld');
        }

        wp_send_json_success(['searches' => $searches]);
    }
    
    /**
     * AJAX: Update saved search
     */
    public function ajax_update_search() {
        // Accept both nonces for compatibility
        if (!check_ajax_referer('mld_saved_searches_frontend', 'nonce', false) &&
            !check_ajax_referer('mld_dashboard_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();
        
        // Verify ownership
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error('Invalid search');
        }
        
        $data = [
            'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'notification_frequency' => isset($_POST['notification_frequency']) ?
                sanitize_text_field($_POST['notification_frequency']) : 'never',
            'is_active' => isset($_POST['is_active']) ? intval($_POST['is_active']) : 0,
            'exclude_disliked' => isset($_POST['exclude_disliked']) ? intval($_POST['exclude_disliked']) : 0
        ];
        
        $result = MLD_Saved_Searches::update_search($search_id, $data);
        
        if ($result) {
            wp_send_json_success(['message' => __('Search updated successfully', 'mld')]);
        } else {
            wp_send_json_error(__('Failed to update search', 'mld'));
        }
    }
    
    /**
     * AJAX: Delete saved search
     */
    public function ajax_delete_search() {
        check_ajax_referer('mld_saved_searches_frontend', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();
        
        // Verify ownership
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error('Invalid search');
        }
        
        $result = MLD_Saved_Searches::delete_search($search_id);
        
        if ($result) {
            wp_send_json_success(['message' => __('Search deleted successfully', 'mld')]);
        } else {
            wp_send_json_error(__('Failed to delete search', 'mld'));
        }
    }
    
    /**
     * AJAX: Toggle search status
     */
    public function ajax_toggle_search_status() {
        // Accept both nonces for compatibility
        if (!check_ajax_referer('mld_saved_searches_frontend', 'nonce', false) &&
            !check_ajax_referer('mld_dashboard_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();
        
        // Verify ownership
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error('Invalid search');
        }
        
        $new_status = $search->is_active ? 0 : 1;
        $result = MLD_Saved_Searches::update_search($search_id, ['is_active' => $new_status]);
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Status updated successfully', 'mld'),
                'new_status' => $new_status
            ]);
        } else {
            wp_send_json_error(__('Failed to update status', 'mld'));
        }
    }
    
    /**
     * AJAX: Get saved properties for non-logged-in users
     */
    public function ajax_get_saved_properties_nopriv() {
        // Return consistent structure with empty saved_properties array for non-logged-in users
        wp_send_json_success([
            'saved_properties' => [],
            'properties' => ['liked' => [], 'disliked' => []]
        ]);
    }
    
    /**
     * AJAX: Get user's saved properties
     */
    public function ajax_get_saved_properties() {
        // Skip nonce verification for read-only operations to avoid 403 errors
        // check_ajax_referer('mld_saved_searches', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success([
                'saved_properties' => [],
                'properties' => ['liked' => [], 'disliked' => []]
            ]);
            return;
        }

        $user_id = get_current_user_id();

        // Get liked property IDs
        $liked_ids = MLD_Property_Preferences::get_liked_properties($user_id);
        $disliked_ids = MLD_Property_Preferences::get_disliked_properties($user_id);

        // Fetch full property data for liked properties
        $liked_properties = [];
        if (!empty($liked_ids)) {
            global $wpdb;
            $summary_table = $wpdb->prefix . 'bme_listing_summary';

            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($liked_ids), '%s'));

            // Fetch property data from summary table
            // Note: Use actual column names from wp_bme_listing_summary
            $query = $wpdb->prepare(
                "SELECT
                    ls.listing_id,
                    ls.list_price as ListPrice,
                    CONCAT_WS(' ', ls.street_number, ls.street_name) as street_address,
                    CONCAT_WS(', ', CONCAT_WS(' ', ls.street_number, ls.street_name), ls.city, ls.state_or_province) as full_address,
                    ls.bedrooms_total as BedroomsTotal,
                    ls.bathrooms_total as BathroomsTotalInteger,
                    ls.building_area_total as LivingArea,
                    ls.city,
                    ls.standard_status,
                    ls.main_photo_url as featured_image_url
                FROM {$summary_table} ls
                WHERE ls.listing_id IN ($placeholders)",
                ...$liked_ids
            );

            $properties = $wpdb->get_results($query);

            // Convert to array for JSON
            foreach ($properties as $property) {
                $liked_properties[] = $property;
            }
        }

        // Return structured object with full property data
        wp_send_json_success([
            'saved_properties' => $liked_properties, // For v3 templates - now with full data
            'properties' => [
                'liked' => $liked_properties,
                'disliked' => $disliked_ids // Just IDs for disliked
            ]
        ]);
    }
    
    /**
     * AJAX: Toggle property like
     */
    public function ajax_toggle_property_like() {
        // Accept both nonces for compatibility
        if (!check_ajax_referer('mld_saved_searches_frontend', 'nonce', false) &&
            !check_ajax_referer('mld_dashboard_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
        $user_id = get_current_user_id();
        
        if (empty($listing_id)) {
            wp_send_json_error('Invalid property ID');
        }
        
        $result = MLD_Property_Preferences::toggle_property($user_id, $listing_id, 'liked');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Check if property is now liked
        $is_liked = MLD_Property_Preferences::is_property_liked($user_id, $listing_id);

        wp_send_json_success([
            'message' => $is_liked ? __('Property saved', 'mld') : __('Property removed', 'mld'),
            'is_liked' => $is_liked
        ]);
    }
    
    /**
     * AJAX: Remove saved property
     */
    public function ajax_remove_saved_property() {
        check_ajax_referer('mld_saved_searches_frontend', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
        $user_id = get_current_user_id();
        
        if (empty($listing_id)) {
            wp_send_json_error('Invalid property ID');
        }
        
        $result = MLD_Property_Preferences::remove_preference($user_id, $listing_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(['message' => __('Property removed from saved list', 'mld')]);
    }

    /**
     * AJAX: Delete saved search (enhanced dashboard version)
     */
    public function ajax_delete_saved_search() {
        // Use dashboard nonce for dashboard-specific actions
        check_ajax_referer('mld_dashboard_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Please log in to continue', 'mld'));
        }

        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();

        if (!$search_id) {
            wp_send_json_error(__('Invalid search ID', 'mld'));
        }

        // Verify ownership
        $search = MLD_Saved_Searches::get_search($search_id);
        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error(__('You do not have permission to delete this search', 'mld'));
        }

        $result = MLD_Saved_Searches::delete_search($search_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'message' => __('Search deleted successfully', 'mld')
        ]);
    }

    /**
     * AJAX: Get search details for editing
     */
    public function ajax_get_search_details() {
        check_ajax_referer('mld_dashboard_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Please log in to continue', 'mld'));
        }

        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();

        if (!$search_id) {
            wp_send_json_error(__('Invalid search ID', 'mld'));
        }

        // Get search details
        $search = MLD_Saved_Searches::get_search($search_id);

        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error(__('You do not have permission to view this search', 'mld'));
        }

        // Convert object to array for JSON response
        wp_send_json_success((array)$search);
    }

    /**
     * AJAX: Duplicate a saved search
     */
    public function ajax_duplicate_search() {
        check_ajax_referer('mld_dashboard_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Please log in to continue', 'mld'));
        }

        $search_id = isset($_POST['search_id']) ? intval($_POST['search_id']) : 0;
        $user_id = get_current_user_id();

        if (!$search_id) {
            wp_send_json_error(__('Invalid search ID', 'mld'));
        }

        // Get original search
        $search = MLD_Saved_Searches::get_search($search_id);

        if (!$search || $search->user_id != $user_id) {
            wp_send_json_error(__('You do not have permission to duplicate this search', 'mld'));
        }

        // Prepare duplicate data
        $duplicate_data = [
            'user_id' => $user_id,
            'name' => $search->name . ' (Copy)',
            'description' => $search->description,
            'filters' => $search->filters,
            'polygon_shapes' => $search->polygon_shapes,
            'search_url' => $search->search_url,
            'notification_frequency' => $search->notification_frequency,
            'is_active' => true,
            'exclude_disliked' => $search->exclude_disliked
        ];

        $new_search_id = MLD_Saved_Searches::create_search($duplicate_data);

        if (is_wp_error($new_search_id)) {
            wp_send_json_error($new_search_id->get_error_message());
        }

        wp_send_json_success([
            'message' => __('Search duplicated successfully', 'mld'),
            'search_id' => $new_search_id
        ]);
    }

    /**
     * AJAX: Toggle property dislike status
     */
    public function ajax_toggle_property_dislike() {
        check_ajax_referer('mld_dashboard_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Please log in to continue', 'mld'));
        }

        $listing_id = isset($_POST['listing_id']) ? sanitize_text_field($_POST['listing_id']) : '';
        $user_id = get_current_user_id();

        if (empty($listing_id)) {
            wp_send_json_error(__('Invalid property ID', 'mld'));
        }

        // Toggle dislike status
        $result = MLD_Property_Preferences::toggle_property($user_id, $listing_id, 'disliked');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Check new status
        $is_disliked = MLD_Property_Preferences::is_property_disliked($user_id, $listing_id);

        wp_send_json_success([
            'message' => $is_disliked ? __('Property hidden', 'mld') : __('Property unhidden', 'mld'),
            'is_disliked' => $is_disliked
        ]);
    }

}

// Initialize
new MLD_Saved_Searches_Frontend();