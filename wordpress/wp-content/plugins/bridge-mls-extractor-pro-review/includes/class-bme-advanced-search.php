<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Search System with Autocomplete and Smart Filtering
 * Version: 1.0.0 (Advanced Search & Filters)
 */
class BME_Advanced_Search {
    
    private $db_manager;
    private $cache_manager;
    private $search_analytics = [];
    
    public function __construct(BME_Database_Manager $db_manager, BME_Cache_Manager $cache_manager) {
        $this->db_manager = $db_manager;
        $this->cache_manager = $cache_manager;
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_bme_autocomplete', [$this, 'ajax_autocomplete_search']);
        add_action('wp_ajax_nopriv_bme_autocomplete', [$this, 'ajax_autocomplete_search']);
        
        add_action('wp_ajax_bme_advanced_search', [$this, 'ajax_advanced_search']);
        add_action('wp_ajax_nopriv_bme_advanced_search', [$this, 'ajax_advanced_search']);
        
        add_action('wp_ajax_bme_get_filter_options', [$this, 'ajax_get_filter_options']);
        add_action('wp_ajax_nopriv_bme_get_filter_options', [$this, 'ajax_get_filter_options']);
        
        // Note: Saved search AJAX handlers are in BME_Saved_Searches class
        
        // Frontend search form
        add_shortcode('bme_advanced_search', [$this, 'render_advanced_search_form']);
        
        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_search_assets']);
    }
    
    /**
     * Enqueue search-related assets
     */
    public function enqueue_search_assets() {
        if (is_admin()) return;
        
        wp_enqueue_script(
            'bme-advanced-search',
            BME_PLUGIN_URL . 'assets/js/advanced-search.js',
            ['jquery', 'jquery-ui-autocomplete'],
            BME_PRO_VERSION ?? '1.0',
            true
        );
        
        wp_enqueue_style(
            'bme-advanced-search',
            BME_PLUGIN_URL . 'assets/css/advanced-search.css',
            [],
            BME_PRO_VERSION ?? '1.0'
        );
        
        wp_localize_script('bme-advanced-search', 'bme_search_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bme_search_nonce'),
            'strings' => [
                'no_results' => __('No results found', 'bridge-mls-extractor-pro'),
                'loading' => __('Loading...', 'bridge-mls-extractor-pro'),
                'search_saved' => __('Search saved successfully', 'bridge-mls-extractor-pro'),
                'min_chars' => __('Please enter at least 2 characters', 'bridge-mls-extractor-pro')
            ]
        ]);
    }
    
    /**
     * AJAX: Autocomplete search
     */
    public function ajax_autocomplete_search() {
        check_ajax_referer('bme_search_nonce', 'nonce');
        
        $query = sanitize_text_field($_POST['query'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'all');
        
        if (strlen($query) < 2) {
            wp_send_json_error(__('Query too short', 'bridge-mls-extractor-pro'));
        }
        
        $suggestions = $this->get_autocomplete_suggestions($query, $type);
        
        wp_send_json_success($suggestions);
    }
    
    /**
     * Get autocomplete suggestions
     */
    private function get_autocomplete_suggestions($query, $type) {
        $cache_key = "autocomplete_{$type}_{$query}";
        
        return $this->cache_manager->get($cache_key, function() use ($query, $type) {
            global $wpdb;
            
            $suggestions = [];
            $listings_table = $this->db_manager->get_table('listings');
            $location_table = $this->db_manager->get_table('listing_location');
            $agents_table = $this->db_manager->get_table('agents');
            
            switch ($type) {
                case 'address':
                    $suggestions = $this->get_address_suggestions($query);
                    break;
                    
                case 'city':
                    $suggestions = $this->get_city_suggestions($query);
                    break;
                    
                case 'agent':
                    $suggestions = $this->get_agent_suggestions($query);
                    break;
                    
                case 'mls_id':
                    $suggestions = $this->get_mls_id_suggestions($query);
                    break;
                    
                case 'all':
                default:
                    $suggestions = array_merge(
                        $this->get_address_suggestions($query, 3),
                        $this->get_city_suggestions($query, 3),
                        $this->get_agent_suggestions($query, 2)
                    );
                    break;
            }
            
            // Limit total suggestions
            return array_slice($suggestions, 0, 10);
            
        }, 300); // 5-minute cache
    }
    
    /**
     * Get address autocomplete suggestions
     */
    private function get_address_suggestions($query, $limit = 5) {
        global $wpdb;
        
        $location_table = $this->db_manager->get_table('listing_location');
        $listings_table = $this->db_manager->get_table('listings');
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT 
                CONCAT(ll.unparsed_address, ', ', ll.city, ', ', ll.state_or_province) as display_text,
                ll.unparsed_address as address,
                ll.city,
                ll.state_or_province,
                COUNT(*) as count
            FROM {$location_table} ll
            INNER JOIN {$listings_table} l ON ll.listing_id = l.listing_id
            WHERE ll.unparsed_address LIKE %s
                AND l.standard_status IN ('Active', 'Pending')
            GROUP BY ll.unparsed_address, ll.city, ll.state_or_province
            ORDER BY count DESC, ll.unparsed_address
            LIMIT %d
        ", '%' . $wpdb->esc_like($query) . '%', $limit));
        
        $suggestions = [];
        foreach ($results as $result) {
            $suggestions[] = [
                'type' => 'address',
                'value' => $result->display_text,
                'label' => $result->display_text . " ({$result->count} listings)",
                'data' => [
                    'address' => $result->address,
                    'city' => $result->city,
                    'state' => $result->state_or_province,
                    'count' => $result->count
                ]
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Get city autocomplete suggestions
     */
    private function get_city_suggestions($query, $limit = 5) {
        global $wpdb;
        
        $location_table = $this->db_manager->get_table('listing_location');
        $listings_table = $this->db_manager->get_table('listings');
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                ll.city,
                ll.state_or_province,
                COUNT(*) as count
            FROM {$location_table} ll
            INNER JOIN {$listings_table} l ON ll.listing_id = l.listing_id
            WHERE ll.city LIKE %s
                AND l.standard_status IN ('Active', 'Pending')
            GROUP BY ll.city, ll.state_or_province
            ORDER BY count DESC, ll.city
            LIMIT %d
        ", '%' . $wpdb->esc_like($query) . '%', $limit));
        
        $suggestions = [];
        foreach ($results as $result) {
            $display_text = $result->city . ', ' . $result->state_or_province;
            $suggestions[] = [
                'type' => 'city',
                'value' => $display_text,
                'label' => $display_text . " ({$result->count} listings)",
                'data' => [
                    'city' => $result->city,
                    'state' => $result->state_or_province,
                    'count' => $result->count
                ]
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Get agent autocomplete suggestions
     */
    private function get_agent_suggestions($query, $limit = 5) {
        global $wpdb;
        
        $agents_table = $this->db_manager->get_table('agents');
        $listings_table = $this->db_manager->get_table('listings');
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                a.agent_first_name,
                a.agent_last_name,
                a.agent_mls_id,
                COUNT(l.id) as listing_count
            FROM {$agents_table} a
            LEFT JOIN {$listings_table} l ON a.agent_mls_id = l.list_agent_mls_id
                AND l.standard_status IN ('Active', 'Pending')
            WHERE CONCAT(a.agent_first_name, ' ', a.agent_last_name) LIKE %s
                OR a.agent_mls_id LIKE %s
            GROUP BY a.agent_mls_id
            ORDER BY listing_count DESC, a.agent_last_name
            LIMIT %d
        ", '%' . $wpdb->esc_like($query) . '%', '%' . $wpdb->esc_like($query) . '%', $limit));
        
        $suggestions = [];
        foreach ($results as $result) {
            $display_name = trim($result->agent_first_name . ' ' . $result->agent_last_name);
            $suggestions[] = [
                'type' => 'agent',
                'value' => $display_name,
                'label' => $display_name . " (ID: {$result->agent_mls_id}, {$result->listing_count} listings)",
                'data' => [
                    'agent_name' => $display_name,
                    'agent_id' => $result->agent_mls_id,
                    'listing_count' => $result->listing_count
                ]
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Get MLS ID suggestions
     */
    private function get_mls_id_suggestions($query, $limit = 5) {
        global $wpdb;
        
        $listings_table = $this->db_manager->get_table('listings');
        $location_table = $this->db_manager->get_table('listing_location');
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                l.listing_id,
                l.list_price,
                l.standard_status,
                ll.unparsed_address,
                ll.city
            FROM {$listings_table} l
            LEFT JOIN {$location_table} ll ON l.listing_id = ll.listing_id
            WHERE l.listing_id LIKE %s
            ORDER BY l.modification_timestamp DESC
            LIMIT %d
        ", $wpdb->esc_like($query) . '%', $limit));
        
        $suggestions = [];
        foreach ($results as $result) {
            $price_formatted = $result->list_price ? '$' . number_format($result->list_price) : 'Price not available';
            $address = $result->unparsed_address ? substr($result->unparsed_address, 0, 50) : 'Address not available';
            
            $suggestions[] = [
                'type' => 'mls_id',
                'value' => $result->listing_id,
                'label' => "MLS #{$result->listing_id} - {$price_formatted} - {$address}",
                'data' => [
                    'listing_id' => $result->listing_id,
                    'price' => $result->list_price,
                    'status' => $result->standard_status,
                    'address' => $result->unparsed_address,
                    'city' => $result->city
                ]
            ];
        }
        
        return $suggestions;
    }

    /**
     * Sanitize search parameters to prevent SQL injection
     */
    private function sanitize_search_params($params) {
        if (!is_array($params)) {
            return [];
        }

        $sanitized = [];

        // Text fields
        $text_fields = ['keyword', 'city', 'state', 'postal_code', 'subdivision', 'mls_area', 'property_type', 'property_sub_type', 'agent_name', 'office_name', 'listing_id'];
        foreach ($text_fields as $field) {
            if (isset($params[$field])) {
                $sanitized[$field] = sanitize_text_field($params[$field]);
            }
        }

        // Numeric fields
        $numeric_fields = ['min_price', 'max_price', 'min_beds', 'max_beds', 'min_baths', 'max_baths', 'min_sqft', 'max_sqft', 'min_lot_size', 'max_lot_size', 'min_year', 'max_year'];
        foreach ($numeric_fields as $field) {
            if (isset($params[$field])) {
                $sanitized[$field] = intval($params[$field]);
            }
        }

        // Array fields
        $array_fields = ['status', 'features', 'cities', 'property_types'];
        foreach ($array_fields as $field) {
            if (isset($params[$field]) && is_array($params[$field])) {
                $sanitized[$field] = array_map('sanitize_text_field', $params[$field]);
            }
        }

        // Boolean fields
        $bool_fields = ['has_photos', 'has_virtual_tour', 'has_open_house', 'is_new', 'is_reduced'];
        foreach ($bool_fields as $field) {
            if (isset($params[$field])) {
                $sanitized[$field] = filter_var($params[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Special handling for sort
        if (isset($params['sort_by'])) {
            $allowed_sorts = ['price_asc', 'price_desc', 'date_asc', 'date_desc', 'beds_asc', 'beds_desc', 'sqft_asc', 'sqft_desc'];
            if (in_array($params['sort_by'], $allowed_sorts)) {
                $sanitized['sort_by'] = $params['sort_by'];
            }
        }

        return $sanitized;
    }

    /**
     * AJAX: Advanced search
     */
    public function ajax_advanced_search() {
        check_ajax_referer('bme_search_nonce', 'nonce');

        // Properly sanitize search parameters
        $search_params = $this->sanitize_search_params($_POST['search_params'] ?? []);
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 20))); // Limit to max 100 per page
        
        $results = $this->perform_advanced_search($search_params, $page, $per_page);
        
        // Log search for analytics
        $this->log_search_query($search_params, $results['total_found']);
        
        wp_send_json_success($results);
    }
    
    /**
     * Perform advanced search
     */
    public function perform_advanced_search($params, $page = 1, $per_page = 20) {
        global $wpdb;
        
        $cache_key = 'advanced_search_' . md5(serialize($params) . $page . $per_page);
        
        return $this->cache_manager->get($cache_key, function() use ($params, $page, $per_page) {
            // Build complex query
            $query_parts = $this->build_search_query($params);
            
            // Add pagination
            $offset = ($page - 1) * $per_page;
            $query_parts['limit'] = "LIMIT {$offset}, {$per_page}";
            
            // Execute main search query
            $search_sql = implode(' ', [
                $query_parts['select'],
                $query_parts['from'],
                $query_parts['joins'],
                $query_parts['where'],
                $query_parts['group_by'],
                $query_parts['order_by'],
                $query_parts['limit']
            ]);
            
            $results = $wpdb->get_results($search_sql, ARRAY_A);
            
            // Get total count
            $count_sql = str_replace($query_parts['select'], 'SELECT COUNT(DISTINCT l.id)', 
                         str_replace($query_parts['limit'], '', $search_sql));
            $total_found = (int) $wpdb->get_var($count_sql);
            
            // Enhance results with additional data
            $enhanced_results = array_map([$this, 'enhance_search_result'], $results);
            
            return [
                'results' => $enhanced_results,
                'total_found' => $total_found,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total_found / $per_page),
                'search_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ];
            
        }, 300); // 5-minute cache
    }
    
    /**
     * Build search query from parameters
     */
    private function build_search_query($params) {
        global $wpdb;
        
        $listings_table = $this->db_manager->get_table('listings');
        $location_table = $this->db_manager->get_table('listing_location');
        $details_table = $this->db_manager->get_table('listing_details');
        $features_table = $this->db_manager->get_table('listing_features');
        $financial_table = $this->db_manager->get_table('listing_financial');
        
        $query_parts = [
            'select' => "SELECT DISTINCT 
                l.*,
                ll.unparsed_address, ll.city, ll.state_or_province, ll.postal_code,
                ll.latitude, ll.longitude,
                ld.bedrooms_total, ld.bathrooms_total_integer, ld.building_area_total,
                ld.lot_size_area, ld.year_built,
                lfi.tax_annual_amount, lfi.mlspin_list_price_per_sqft",
            'from' => "FROM {$listings_table} l",
            'joins' => "
                LEFT JOIN {$location_table} ll ON l.listing_id = ll.listing_id
                LEFT JOIN {$details_table} ld ON l.listing_id = ld.listing_id
                LEFT JOIN {$features_table} lf ON l.listing_id = lf.listing_id  
                LEFT JOIN {$financial_table} lfi ON l.listing_id = lfi.listing_id",
            'where' => "WHERE 1=1",
            'group_by' => "",
            'order_by' => "ORDER BY l.modification_timestamp DESC",
            'limit' => ""
        ];
        
        $where_conditions = [];
        
        // Text search
        if (!empty($params['keyword'])) {
            $keyword = sanitize_text_field($params['keyword']);
            $where_conditions[] = $wpdb->prepare("
                (l.public_remarks LIKE %s 
                 OR ll.unparsed_address LIKE %s 
                 OR ll.city LIKE %s
                 OR l.listing_id LIKE %s)",
                '%' . $wpdb->esc_like($keyword) . '%',
                '%' . $wpdb->esc_like($keyword) . '%', 
                '%' . $wpdb->esc_like($keyword) . '%',
                '%' . $wpdb->esc_like($keyword) . '%'
            );
        }
        
        // Status filter
        if (!empty($params['status']) && is_array($params['status'])) {
            $status_placeholders = implode(',', array_fill(0, count($params['status']), '%s'));
            $where_conditions[] = $wpdb->prepare("l.standard_status IN ({$status_placeholders})", $params['status']);
        } else {
            // Default to active listings only
            $where_conditions[] = "l.standard_status IN ('Active', 'Pending')";
        }
        
        // Property type filter
        if (!empty($params['property_type']) && is_array($params['property_type'])) {
            $type_placeholders = implode(',', array_fill(0, count($params['property_type']), '%s'));
            $where_conditions[] = $wpdb->prepare("l.property_type IN ({$type_placeholders})", $params['property_type']);
        }
        
        // Price range
        if (!empty($params['price_min'])) {
            $where_conditions[] = $wpdb->prepare("l.list_price >= %d", intval($params['price_min']));
        }
        if (!empty($params['price_max'])) {
            $where_conditions[] = $wpdb->prepare("l.list_price <= %d", intval($params['price_max']));
        }
        
        // Bedrooms/Bathrooms
        if (!empty($params['bedrooms_min'])) {
            $where_conditions[] = $wpdb->prepare("ld.bedrooms_total >= %d", intval($params['bedrooms_min']));
        }
        if (!empty($params['bathrooms_min'])) {
            $where_conditions[] = $wpdb->prepare("ld.bathrooms_total_integer >= %d", intval($params['bathrooms_min']));
        }
        
        // Square footage
        if (!empty($params['sqft_min'])) {
            $where_conditions[] = $wpdb->prepare("ld.building_area_total >= %d", intval($params['sqft_min']));
        }
        if (!empty($params['sqft_max'])) {
            $where_conditions[] = $wpdb->prepare("ld.building_area_total <= %d", intval($params['sqft_max']));
        }
        
        // Location filters
        if (!empty($params['city'])) {
            if (is_array($params['city'])) {
                $city_placeholders = implode(',', array_fill(0, count($params['city']), '%s'));
                $where_conditions[] = $wpdb->prepare("ll.city IN ({$city_placeholders})", $params['city']);
            } else {
                $where_conditions[] = $wpdb->prepare("ll.city = %s", sanitize_text_field($params['city']));
            }
        }
        
        if (!empty($params['state'])) {
            $where_conditions[] = $wpdb->prepare("ll.state_or_province = %s", sanitize_text_field($params['state']));
        }
        
        if (!empty($params['zip_code'])) {
            $where_conditions[] = $wpdb->prepare("ll.postal_code = %s", sanitize_text_field($params['zip_code']));
        }
        
        // Year built range
        if (!empty($params['year_built_min'])) {
            $where_conditions[] = $wpdb->prepare("ld.year_built >= %d", intval($params['year_built_min']));
        }
        if (!empty($params['year_built_max'])) {
            $where_conditions[] = $wpdb->prepare("ld.year_built <= %d", intval($params['year_built_max']));
        }
        
        // Agent filter
        if (!empty($params['agent_id'])) {
            $where_conditions[] = $wpdb->prepare("l.list_agent_mls_id = %s", sanitize_text_field($params['agent_id']));
        }
        
        // Days on market
        if (!empty($params['days_on_market_max'])) {
            $where_conditions[] = $wpdb->prepare(
                "DATEDIFF(NOW(), l.original_entry_timestamp) <= %d", 
                intval($params['days_on_market_max'])
            );
        }
        
        // Geographic search (radius)
        if (!empty($params['lat']) && !empty($params['lng']) && !empty($params['radius'])) {
            $lat = floatval($params['lat']);
            $lng = floatval($params['lng']);
            $radius = floatval($params['radius']);
            
            // Use Haversine formula for radius search
            $where_conditions[] = $wpdb->prepare("
                (3959 * acos(
                    cos(radians(%f)) * cos(radians(ll.latitude)) * 
                    cos(radians(ll.longitude) - radians(%f)) + 
                    sin(radians(%f)) * sin(radians(ll.latitude))
                )) <= %f",
                $lat, $lng, $lat, $radius
            );
        }
        
        // Apply all where conditions
        if (!empty($where_conditions)) {
            $query_parts['where'] .= ' AND ' . implode(' AND ', $where_conditions);
        }
        
        // Sorting
        if (!empty($params['sort_by'])) {
            switch ($params['sort_by']) {
                case 'price_asc':
                    $query_parts['order_by'] = "ORDER BY l.list_price ASC";
                    break;
                case 'price_desc':
                    $query_parts['order_by'] = "ORDER BY l.list_price DESC";
                    break;
                case 'newest':
                    $query_parts['order_by'] = "ORDER BY l.original_entry_timestamp DESC";
                    break;
                case 'oldest':
                    $query_parts['order_by'] = "ORDER BY l.original_entry_timestamp ASC";
                    break;
                case 'sqft_desc':
                    $query_parts['order_by'] = "ORDER BY ld.building_area_total DESC";
                    break;
                case 'updated':
                default:
                    $query_parts['order_by'] = "ORDER BY l.modification_timestamp DESC";
                    break;
            }
        }
        
        return $query_parts;
    }
    
    /**
     * Enhance search result with additional data
     */
    private function enhance_search_result($result) {
        // Add formatted price
        $result['formatted_price'] = $result['list_price'] ? '$' . number_format($result['list_price']) : 'Price upon request';
        
        // Add days on market
        if ($result['original_entry_timestamp']) {
            $result['days_on_market'] = floor((time() - strtotime($result['original_entry_timestamp'])) / (24 * 60 * 60));
        }
        
        // Add price per square foot
        if ($result['building_area_total'] && $result['list_price']) {
            $result['price_per_sqft'] = round($result['list_price'] / $result['building_area_total'], 2);
            $result['formatted_price_per_sqft'] = '$' . number_format($result['price_per_sqft'], 2);
        }
        
        // Add full address
        $address_parts = array_filter([
            $result['unparsed_address'],
            $result['city'],
            $result['state_or_province'],
            $result['postal_code']
        ]);
        $result['full_address'] = implode(', ', $address_parts);
        
        return $result;
    }
    
    
    /**
     * Log search query for analytics
     */
    private function log_search_query($params, $result_count) {
        $search_log = get_option('bme_search_analytics', []);
        
        $search_entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'params' => $params,
            'result_count' => $result_count,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        
        $search_log[] = $search_entry;
        
        // Keep only last 1000 searches
        if (count($search_log) > 1000) {
            $search_log = array_slice($search_log, -1000);
        }
        
        update_option('bme_search_analytics', $search_log);
    }
    
    /**
     * Render advanced search form shortcode
     */
    public function render_advanced_search_form($atts) {
        $atts = shortcode_atts([
            'style' => 'default',
            'show_map' => true,
            'columns' => 3
        ], $atts);
        
        ob_start();
        ?>
        <div class="bme-advanced-search-form" data-style="<?php echo esc_attr($atts['style']); ?>">
            <form id="bme-search-form" class="bme-search-form">
                <div class="bme-search-row">
                    <div class="bme-search-field">
                        <label for="bme-keyword"><?php _e('Search', 'bridge-mls-extractor-pro'); ?></label>
                        <input type="text" id="bme-keyword" name="keyword" 
                               placeholder="<?php _e('Address, City, MLS ID, or Keywords', 'bridge-mls-extractor-pro'); ?>"
                               class="bme-autocomplete" />
                    </div>
                    
                    <div class="bme-search-field">
                        <label for="bme-property-type"><?php _e('Property Type', 'bridge-mls-extractor-pro'); ?></label>
                        <select id="bme-property-type" name="property_type[]" multiple>
                            <option value=""><?php _e('Any Property Type', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Residential"><?php _e('Residential', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Condominium"><?php _e('Condominium', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Townhouse"><?php _e('Townhouse', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Commercial"><?php _e('Commercial', 'bridge-mls-extractor-pro'); ?></option>
                        </select>
                    </div>
                    
                    <div class="bme-search-field">
                        <label for="bme-status"><?php _e('Status', 'bridge-mls-extractor-pro'); ?></label>
                        <select id="bme-status" name="status[]" multiple>
                            <option value="Active" selected><?php _e('Active', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Pending"><?php _e('Pending', 'bridge-mls-extractor-pro'); ?></option>
                            <option value="Sold"><?php _e('Sold', 'bridge-mls-extractor-pro'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="bme-search-row">
                    <div class="bme-search-field">
                        <label><?php _e('Price Range', 'bridge-mls-extractor-pro'); ?></label>
                        <div class="bme-price-range">
                            <input type="number" name="price_min" placeholder="<?php _e('Min Price', 'bridge-mls-extractor-pro'); ?>" />
                            <span class="bme-range-separator">-</span>
                            <input type="number" name="price_max" placeholder="<?php _e('Max Price', 'bridge-mls-extractor-pro'); ?>" />
                        </div>
                    </div>
                    
                    <div class="bme-search-field">
                        <label for="bme-bedrooms"><?php _e('Min Bedrooms', 'bridge-mls-extractor-pro'); ?></label>
                        <select id="bme-bedrooms" name="bedrooms_min">
                            <option value=""><?php _e('Any', 'bridge-mls-extractor-pro'); ?></option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?>+</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="bme-search-field">
                        <label for="bme-bathrooms"><?php _e('Min Bathrooms', 'bridge-mls-extractor-pro'); ?></label>
                        <select id="bme-bathrooms" name="bathrooms_min">
                            <option value=""><?php _e('Any', 'bridge-mls-extractor-pro'); ?></option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?>+</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="bme-search-advanced" style="display: none;">
                    <div class="bme-search-row">
                        <div class="bme-search-field">
                            <label><?php _e('Square Footage', 'bridge-mls-extractor-pro'); ?></label>
                            <div class="bme-sqft-range">
                                <input type="number" name="sqft_min" placeholder="<?php _e('Min Sq Ft', 'bridge-mls-extractor-pro'); ?>" />
                                <span class="bme-range-separator">-</span>
                                <input type="number" name="sqft_max" placeholder="<?php _e('Max Sq Ft', 'bridge-mls-extractor-pro'); ?>" />
                            </div>
                        </div>
                        
                        <div class="bme-search-field">
                            <label><?php _e('Year Built', 'bridge-mls-extractor-pro'); ?></label>
                            <div class="bme-year-range">
                                <input type="number" name="year_built_min" placeholder="<?php _e('From', 'bridge-mls-extractor-pro'); ?>" />
                                <span class="bme-range-separator">-</span>
                                <input type="number" name="year_built_max" placeholder="<?php _e('To', 'bridge-mls-extractor-pro'); ?>" />
                            </div>
                        </div>
                        
                        <div class="bme-search-field">
                            <label for="bme-days-on-market"><?php _e('Max Days on Market', 'bridge-mls-extractor-pro'); ?></label>
                            <input type="number" id="bme-days-on-market" name="days_on_market_max" 
                                   placeholder="<?php _e('Days', 'bridge-mls-extractor-pro'); ?>" />
                        </div>
                    </div>
                </div>
                
                <div class="bme-search-actions">
                    <button type="submit" class="bme-btn bme-btn-primary">
                        <?php _e('Search Properties', 'bridge-mls-extractor-pro'); ?>
                    </button>
                    <button type="button" class="bme-btn bme-btn-secondary" id="bme-advanced-toggle">
                        <?php _e('Advanced Options', 'bridge-mls-extractor-pro'); ?>
                    </button>
                    <button type="button" class="bme-btn bme-btn-link" id="bme-save-search">
                        <?php _e('Save Search', 'bridge-mls-extractor-pro'); ?>
                    </button>
                </div>
            </form>
            
            <div id="bme-search-results" class="bme-search-results">
                <!-- Results will be loaded here -->
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}