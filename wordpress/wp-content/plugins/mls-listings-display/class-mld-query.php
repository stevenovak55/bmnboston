<?php
/**
 * Handles all database queries for the MLS Listings Display plugin.
 * Version 4.1.2
 *
 * Version 4.1.2 Changes:
 * - Fixed issue where listings disappeared at zoom levels 14-15 when city filter + viewport bounds were combined
 * - MySQL's spatial index conflicts with city index at these zoom levels, returning 0 results
 * - Solution: Remove ST_Contains spatial filter from SQL query at zoom 14-15 with city filter
 * - Apply viewport bounds filtering in PHP after fetching city-filtered results
 * - This avoids the MySQL optimizer issue while maintaining correct results
 *
 * Version 4.1.1 Changes:
 * - Added debugging and attempted condition reordering (superseded by 4.1.2 solution)
 *
 * Version 4.1 Changes:
 * - Changed to fixed 200 listing limit regardless of zoom level for consistent performance
 * - Simplified listing limit logic by removing zoom-based conditionals
 *
 * Version 4.0 Changes:
 * - Added zoom-based listing limits for performance optimization (now removed in 4.1)
 * - Implemented priority-based sorting (Status -> Price -> Date)
 * - Added $zoom parameter to get_listings_for_map function (parameter retained for compatibility)
 * - Improved query performance for large datasets (10,000+ listings)
 */
class MLD_Query {

    /**
     * In-memory cache for table existence checks
     * Persists for the duration of the request
     * @since 6.11.4
     */
    private static $table_exists_cache = [];

    /**
     * Check if a database table exists with caching
     * Uses in-memory cache for same-request + transient for cross-request
     *
     * @param string $table_name Full table name (including prefix)
     * @return bool Whether the table exists
     * @since 6.11.4
     */
    private static function table_exists($table_name) {
        // Check in-memory cache first (fastest)
        if (isset(self::$table_exists_cache[$table_name])) {
            return self::$table_exists_cache[$table_name];
        }

        // Check transient cache (cross-request)
        $transient_key = 'mld_table_exists_' . md5($table_name);
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            $exists = ($cached === 'yes');
            self::$table_exists_cache[$table_name] = $exists;
            return $exists;
        }

        // Query the database
        global $wpdb;
        $result = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        $exists = ($result === $table_name);

        // Cache the result
        self::$table_exists_cache[$table_name] = $exists;
        set_transient($transient_key, $exists ? 'yes' : 'no', HOUR_IN_SECONDS);

        return $exists;
    }

    /**
     * Invalidate table existence cache for a specific table
     * Call this when tables are created or dropped
     *
     * @param string $table_name Full table name (including prefix)
     * @since 6.11.4
     */
    public static function invalidate_table_cache($table_name = null) {
        if ($table_name === null) {
            // Clear all cached table checks
            self::$table_exists_cache = [];
            // Clear all transients (pattern match not directly supported, so clear known ones)
            global $wpdb;
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
            delete_transient('mld_table_exists_' . md5($summary_table));
        } else {
            unset(self::$table_exists_cache[$table_name]);
            delete_transient('mld_table_exists_' . md5($table_name));
        }
    }

    private static function get_bme_tables(): ?array {
        // Use the new data provider abstraction layer
        // Load interface first, then the factory that uses it
        if (!interface_exists('MLD_Data_Provider_Interface')) {
            require_once MLD_PLUGIN_PATH . 'includes/interface-mld-data-provider.php';
        }
        if (!class_exists('MLD_Data_Provider_Factory')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-data-provider-factory.php';
        }
        
        $provider = mld_get_data_provider();
        if ($provider && $provider->is_available()) {
            return $provider->get_tables();
        }
        
        // Fallback to direct access for backward compatibility
        if (function_exists('bme_pro') && method_exists(bme_pro()->get('db'), 'get_tables')) {
            return bme_pro()->get('db')->get_tables();
        }
        
        return null;
    }

    /**
     * Check if any school quality filters are active
     * Delegates to shared query builder for consistency with REST API
     *
     * @param array $filters Filter array
     * @return bool True if school filters are active
     * @since 6.30.3
     * @since 6.30.20 Delegates to MLD_Shared_Query_Builder
     */
    private static function has_school_filters($filters) {
        return MLD_Shared_Query_Builder::has_school_filters($filters);
    }

    /**
     * Build school filter criteria array from filters
     * Delegates to shared query builder for consistency with REST API
     *
     * @param array $filters Filter array
     * @return array School criteria for BMN Schools integration
     * @since 6.30.3
     * @since 6.30.20 Delegates to MLD_Shared_Query_Builder
     */
    private static function build_school_criteria($filters) {
        return MLD_Shared_Query_Builder::build_school_criteria($filters);
    }

    /**
     * Check if any filter requires JOIN with listing_details table
     *
     * @param array $filters Filter array
     * @return bool True if details table JOIN needed
     * @since 6.30.21
     */
    private static function has_details_filters($filters) {
        $details_filters = [
            'CoolingYN', 'GarageYN', 'parking_total_min', 'AttachedGarageYN'
        ];
        foreach ($details_filters as $filter) {
            if (!empty($filters[$filter])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if any filter requires JOIN with listing_features table
     *
     * @param array $filters Filter array
     * @return bool True if features table JOIN needed
     * @since 6.30.21
     */
    private static function has_features_filters($filters) {
        $features_filters = [
            'WaterfrontYN', 'ViewYN', 'SpaYN', 'MLSPIN_WATERVIEW_FLAG',
            'MLSPIN_OUTDOOR_SPACE_AVAILABLE', 'SeniorCommunityYN'
        ];
        foreach ($features_filters as $filter) {
            if (!empty($filters[$filter])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build WHERE conditions for filters that need listing_details table
     *
     * @param array $filters Filter array
     * @return array SQL conditions
     * @since 6.30.21
     */
    private static function build_details_filter_conditions($filters) {
        global $wpdb;
        $conditions = [];

        // Cooling/AC filter
        if (!empty($filters['CoolingYN'])) {
            $conditions[] = "ld.cooling_yn = 1";
        }

        // Garage filter
        if (!empty($filters['GarageYN'])) {
            $conditions[] = "ld.garage_yn = 1";
        }

        // Attached garage filter
        if (!empty($filters['AttachedGarageYN'])) {
            $conditions[] = "ld.attached_garage_yn = 1";
        }

        // Parking total filter
        if (!empty($filters['parking_total_min']) && (int)$filters['parking_total_min'] > 0) {
            $min = (int)$filters['parking_total_min'];
            $conditions[] = $wpdb->prepare(
                "(IFNULL(ld.parking_total, 0) + IFNULL(ld.covered_spaces, 0)) >= %d",
                $min
            );
        }

        return $conditions;
    }

    /**
     * Build WHERE conditions for filters that need listing_features table
     *
     * @param array $filters Filter array
     * @return array SQL conditions
     * @since 6.30.21
     */
    private static function build_features_filter_conditions($filters) {
        $conditions = [];

        // Waterfront filter
        if (!empty($filters['WaterfrontYN'])) {
            $conditions[] = "lf.waterfront_yn = 1";
        }

        // View filter
        if (!empty($filters['ViewYN'])) {
            $conditions[] = "lf.view_yn = 1";
        }

        // Water view filter
        if (!empty($filters['MLSPIN_WATERVIEW_FLAG'])) {
            $conditions[] = "lf.mlspin_waterview_flag = 1";
        }

        // Spa filter
        if (!empty($filters['SpaYN'])) {
            $conditions[] = "lf.spa_yn = 1";
        }

        // Outdoor space filter
        if (!empty($filters['MLSPIN_OUTDOOR_SPACE_AVAILABLE'])) {
            $conditions[] = "lf.mlspin_outdoor_space_available = 1";
        }

        // Senior community filter
        if (!empty($filters['SeniorCommunityYN'])) {
            $conditions[] = "lf.senior_community_yn = 1";
        }

        return $conditions;
    }

    /**
     * Apply school filter to listings using BMN Schools integration
     *
     * @param array $listings Array of listing objects
     * @param array $filters Filter array containing school criteria
     * @return array Filtered listings
     * @since 6.30.3
     */
    private static function apply_school_filter($listings, $filters) {
        if (empty($listings) || !self::has_school_filters($filters)) {
            return $listings;
        }

        // Get BMN Schools integration
        if (!class_exists('MLD_BMN_Schools_Integration')) {
            return $listings;
        }

        $schools_integration = MLD_BMN_Schools_Integration::get_instance();
        if (!$schools_integration) {
            return $listings;
        }

        // Normalize listings to have lowercase coordinate keys for BMN Schools integration
        // The integration expects 'latitude'/'longitude', not 'Latitude'/'Longitude'
        // v6.49.6 - Handle both objects and arrays (traditional path now returns arrays)
        foreach ($listings as &$listing) {
            if (is_object($listing)) {
                if (!isset($listing->latitude) && isset($listing->Latitude)) {
                    $listing->latitude = $listing->Latitude;
                }
                if (!isset($listing->longitude) && isset($listing->Longitude)) {
                    $listing->longitude = $listing->Longitude;
                }
            } elseif (is_array($listing)) {
                if (!isset($listing['latitude']) && isset($listing['Latitude'])) {
                    $listing['latitude'] = $listing['Latitude'];
                }
                if (!isset($listing['longitude']) && isset($listing['Longitude'])) {
                    $listing['longitude'] = $listing['Longitude'];
                }
            }
        }
        unset($listing); // Break reference

        $school_criteria = self::build_school_criteria($filters);

        // Use the existing filter_properties_by_school_criteria method
        return $schools_integration->filter_properties_by_school_criteria($listings, $school_criteria);
    }

        public static function get_similar_listings($current_listing, $count = 4): array {
        global $wpdb;

        if (empty($current_listing)) return [];

        $price = (string)($current_listing['list_price'] ?? '0');
        $city = $current_listing['city'] ?? '';
        $sub_type = $current_listing['property_sub_type'] ?? '';
        $current_listing_id = $current_listing['listing_id'] ?? '';

        if (!$price || $price === '0' || !$city || !$sub_type || !$current_listing_id) {
            return [];
        }

        // Generate cache key for similar listings
        $cache_key = MLD_Query_Cache::generate_key('similar_listings', [
            'listing_id' => $current_listing_id,
            'city' => $city,
            'sub_type' => $sub_type,
            'price' => $price,
            'count' => $count
        ]);

        // Try to get from cache
        $cached = MLD_Query_Cache::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Check if summary table exists and use it for better performance
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        if (self::table_exists($summary_table)) {
            // Use optimized summary table query
            // Use BCMath for precise financial calculations
            if (function_exists('bcmul')) {
                $price_min = bcmul($price, '0.85', 2);
                $price_max = bcmul($price, '1.15', 2);
            } else {
                $price_float = (float)$price;
                $price_min = $price_float * 0.85;
                $price_max = $price_float * 1.15;
            }

            $sql = $wpdb->prepare(
                "SELECT listing_id, listing_key, list_price, standard_status, property_sub_type,
                        street_number, street_name, unit_number, city, state_or_province, postal_code,
                        bedrooms_total, bathrooms_total, bathrooms_full, bathrooms_half,
                        building_area_total as living_area,
                        main_photo_url as photo_url,
                        ABS(list_price - %f) as price_diff
                 FROM {$summary_table}
                 WHERE standard_status IN ('Active', 'Active Under Contract')
                   AND listing_id != %s
                   AND city = %s
                   AND property_sub_type = %s
                   AND list_price BETWEEN %f AND %f
                 ORDER BY price_diff ASC, modification_timestamp DESC
                 LIMIT %d",
                $price,
                $current_listing_id,
                $city,
                $sub_type,
                $price_min,
                $price_max,
                absint($count)
            );

            $results = $wpdb->get_results($sql, ARRAY_A);

            if (!empty($results)) {
                // Store in cache and return
                MLD_Query_Cache::set($cache_key, $results, 300); // Cache for 5 minutes
                return $results;
            }
        }

        // Fallback to traditional query if summary table not available
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return [];

        // Use BCMath for precise financial calculations
        if (function_exists('bcmul')) {
            $price_min = bcmul($price, '0.85', 2);
            $price_max = bcmul($price, '1.15', 2);
        } else {
            $price_float = (float)$price;
            $price_min = $price_float * 0.85;
            $price_max = $price_float * 1.15;
        }

        $sql = $wpdb->prepare(
            "SELECT l.id, l.listing_id, l.listing_key, l.list_price, l.standard_status, l.property_sub_type,
                    ll.street_number, ll.street_name, ll.unit_number, ll.city, ll.state_or_province, ll.postal_code,
                    ld.bedrooms_total, ld.bathrooms_full, ld.bathrooms_half, ld.living_area,
                    m1.media_url as photo_url,
                    ABS(l.list_price - %f) as price_diff
             FROM {$bme_tables['listings']} l
             LEFT JOIN {$bme_tables['listing_location']} ll ON l.listing_id = ll.listing_id
             LEFT JOIN {$bme_tables['listing_details']} ld ON l.listing_id = ld.listing_id
             LEFT JOIN (
                SELECT listing_id, MIN(CONCAT(LPAD(order_index, 10, '0'), '|', media_url)) as min_media
                FROM {$bme_tables['media']}
                GROUP BY listing_id
             ) m ON l.listing_id = m.listing_id
             LEFT JOIN {$bme_tables['media']} m1 ON l.listing_id = m1.listing_id
                AND CONCAT(LPAD(m1.order_index, 10, '0'), '|', m1.media_url) = m.min_media
             WHERE l.standard_status = 'Active'
               AND l.listing_id != %d
               AND ll.city = %s
               AND l.property_sub_type = %s
               AND l.list_price BETWEEN %f AND %f
             ORDER BY price_diff ASC, l.modification_timestamp DESC
             LIMIT %d",
            $price,
            $current_listing_id,
            $city,
            $sub_type,
            $price_min,
            $price_max,
            absint($count)
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Store in cache
        MLD_Query_Cache::set($cache_key, $results, 300); // Cache for 5 minutes

        return $results;
    }
    public static function get_listings_by_mls_numbers(array $mls_numbers) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables || empty($mls_numbers)) return [];

        // Generate cache key for MLS numbers lookup
        $cache_key = MLD_Query_Cache::generate_key('mls_numbers', [
            'numbers' => implode(',', $mls_numbers)
        ]);

        // Try to get from cache
        $cached = MLD_Query_Cache::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $placeholders = implode(', ', array_fill(0, count($mls_numbers), '%s'));
        
        // Optimized query without N+1 subquery pattern
        $select_fields = "l.id, l.listing_id, l.listing_key, l.list_price, l.standard_status,
                          ll.street_number, ll.street_name, ll.unit_number, ll.city, ll.state_or_province, ll.postal_code,
                          ld.bedrooms_total, ld.bathrooms_full, ld.bathrooms_half, ld.living_area,
                          m1.media_url as photo_url";

        // Optimized query with JOIN instead of subquery for media
        $sql = "
            (SELECT l.id, l.listing_id, l.listing_key, l.list_price, l.standard_status,
                    ll.street_number, ll.street_name, ll.unit_number, ll.city, ll.state_or_province, ll.postal_code,
                    ld.bedrooms_total, ld.bathrooms_full, ld.bathrooms_half, ld.living_area,
                    m1.media_url as photo_url
             FROM {$bme_tables['listings']} l
             LEFT JOIN {$bme_tables['listing_location']} ll ON l.listing_id = ll.listing_id
             LEFT JOIN {$bme_tables['listing_details']} ld ON l.listing_id = ld.listing_id
             LEFT JOIN (
                SELECT listing_id, MIN(CONCAT(LPAD(order_index, 10, '0'), '|', media_url)) as min_media
                FROM {$bme_tables['media']}
                WHERE listing_id IN ({$placeholders})
                GROUP BY listing_id
             ) m ON l.listing_id = m.listing_id
             LEFT JOIN {$bme_tables['media']} m1 ON l.listing_id = m1.listing_id
                AND CONCAT(LPAD(m1.order_index, 10, '0'), '|', m1.media_url) = m.min_media
             WHERE l.listing_id IN ({$placeholders}))
            UNION ALL
            (SELECT l.id, l.listing_id, l.listing_key, l.list_price, l.standard_status,
                    ll.street_number, ll.street_name, ll.unit_number, ll.city, ll.state_or_province, ll.postal_code,
                    ld.bedrooms_total, ld.bathrooms_full, ld.bathrooms_half, ld.living_area,
                    m1.media_url as photo_url
             FROM {$bme_tables['listings_archive']} l
             LEFT JOIN {$bme_tables['listing_location_archive']} ll ON l.listing_id = ll.listing_id
             LEFT JOIN {$bme_tables['listing_details_archive']} ld ON l.listing_id = ld.listing_id
             LEFT JOIN (
                SELECT listing_id, MIN(CONCAT(LPAD(order_index, 10, '0'), '|', media_url)) as min_media
                FROM {$bme_tables['media']}
                WHERE listing_id IN ({$placeholders})
                GROUP BY listing_id
             ) m ON l.listing_id = m.listing_id
             LEFT JOIN {$bme_tables['media']} m1 ON l.listing_id = m1.listing_id
                AND CONCAT(LPAD(m1.order_index, 10, '0'), '|', m1.media_url) = m.min_media
             WHERE l.listing_id IN ({$placeholders}))
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($mls_numbers, $mls_numbers)), ARRAY_A);

        $ordered_results = [];
        foreach ($mls_numbers as $mls) {
            foreach ($results as $result) {
                if ($result['listing_id'] === $mls) {
                    $ordered_results[] = $result;
                    break;
                }
            }
        }
        // Store in cache
        MLD_Query_Cache::set($cache_key, $ordered_results, 300); // Cache for 5 minutes

        return $ordered_results;
    }

    public static function get_all_listings_for_cache($filters = null, $page = 1, $limit = 50): array { // Optimized default limit
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return ['total' => 0, 'listings' => []];

        // Generate cache key for all listings
        $cache_key = MLD_Query_Cache::generate_key('all_listings', [
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit
        ]);

        // Try to get from cache
        $cached = MLD_Query_Cache::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $offset = ($page - 1) * $limit;
        
        $query_info = self::determine_query_tables($filters);
        $filter_conditions = self::build_filter_conditions($filters ?: []);

        // Build count query with proper price field handling
        $count_total = 0;
        foreach ($query_info['tables_to_query'] as $table_type) {
            $suffix = ($table_type === 'archive') ? '_archive' : '';
            
            // Adjust filter conditions for archive tables to use close_price instead of list_price
            $table_filter_conditions = $filter_conditions;
            if ($table_type === 'archive') {
                $table_filter_conditions = array_map(function($condition) {
                    // Use CASE statement to handle price correctly based on status
                    return str_replace('l.list_price', 
                        "(CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END)", 
                        $condition);
                }, $table_filter_conditions);
            }
            
            $from_clause = "FROM {$bme_tables['listings' . $suffix]} AS l";
            $from_clause .= self::get_minimal_joins_for_filters($table_filter_conditions, $suffix, true);
            
            $count_query = "SELECT COUNT(DISTINCT l.id) " . $from_clause;
            if (!empty($table_filter_conditions)) $count_query .= " WHERE " . implode(' AND ', $table_filter_conditions);
            
            $count_total += (int) $wpdb->get_var($count_query);
        }
        $total_listings = $count_total;

        // Build separate queries for active and archive tables
        $all_listings = [];
        
        foreach ($query_info['tables_to_query'] as $table_type) {
            $suffix = ($table_type === 'archive') ? '_archive' : '';
            
            // Use close_price only for Closed status in archive tables, list_price for all others
            $price_field = ($table_type === 'archive') 
                ? "CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END AS ListPrice" 
                : 'l.list_price AS ListPrice';
            
            // Optimized query without N+1 pattern - fetch media in batch
            $select_fields = "l.id, l.listing_id AS ListingId, ST_Y(ll.coordinates) AS Latitude, ST_X(ll.coordinates) AS Longitude, {$price_field}, l.original_list_price AS OriginalListPrice, l.standard_status AS StandardStatus, l.property_type AS PropertyType, l.property_sub_type AS PropertySubType, l.modification_timestamp,
                              l.original_entry_timestamp, l.off_market_date, l.close_date,
                              ll.street_number AS StreetNumber, ll.street_name AS StreetName, ll.unit_number AS UnitNumber, ll.city AS City, ll.state_or_province AS StateOrProvince, ll.postal_code AS PostalCode,
                              ld.bedrooms_total AS BedroomsTotal, ld.bathrooms_full AS BathroomsFull, ld.bathrooms_half AS BathroomsHalf, ld.bathrooms_total_integer AS BathroomsTotalInteger, ld.living_area AS LivingArea, ld.year_built AS YearBuilt,
                              lf.association_fee AS AssociationFee, lf.association_fee_frequency AS AssociationFeeFrequency, ld.garage_spaces AS GarageSpaces,
                              m1.media_url as photo_url";
            
            // Adjust filter conditions for archive tables to use close_price instead of list_price
            $table_filter_conditions = $filter_conditions;
            if ($table_type === 'archive') {
                $table_filter_conditions = array_map(function($condition) {
                    // Use CASE statement to handle price correctly based on status
                    return str_replace('l.list_price', 
                        "(CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END)", 
                        $condition);
                }, $table_filter_conditions);
            }
            
            // Build FROM clause with optimized media JOIN
            // Note: There's only one media table for both active and archive listings
            $media_table = $bme_tables['media'];
            $from_clause = "FROM {$bme_tables['listings' . $suffix]} AS l";
            $from_clause .= self::get_minimal_joins_for_filters($table_filter_conditions, $suffix, true);

            // Add optimized media JOIN
            $from_clause .= " LEFT JOIN (
                SELECT listing_id, MIN(CONCAT(LPAD(order_index, 10, '0'), '|', media_url)) as min_media
                FROM {$media_table}
                GROUP BY listing_id
            ) m ON l.listing_id = m.listing_id
            LEFT JOIN {$media_table} m1 ON l.listing_id = m1.listing_id
                AND CONCAT(LPAD(m1.order_index, 10, '0'), '|', m1.media_url) = m.min_media";

            $query = "SELECT " . $select_fields . " " . $from_clause;
            if (!empty($table_filter_conditions)) $query .= " WHERE " . implode(' AND ', $table_filter_conditions);
            
            // v6.49.6 - Use ARRAY_A for consistency with get_listings_for_map_traditional
            $listings_from_table = $wpdb->get_results($query, ARRAY_A);
            if ($listings_from_table) {
                $all_listings = array_merge($all_listings, $listings_from_table);
            }
        }

        // Sort all listings by modification_timestamp DESC
        // v6.49.6 - Use array syntax (listings are now arrays)
        usort($all_listings, function($a, $b) {
            return strcmp($b['modification_timestamp'], $a['modification_timestamp']);
        });

        // Apply pagination
        $listings = array_slice($all_listings, $offset, $limit);

        if (!empty($listings)) {
            try {
                // Get the MLS listing IDs (not the internal database IDs)
                // v6.49.6 - Use array_column instead of wp_list_pluck
                $listing_ids = array_column($listings, 'ListingId');
                
                // Fix SQL injection vulnerability - properly prepare the query
                if (!empty($listing_ids)) {
                    $placeholders = array_fill(0, count($listing_ids), '%d');
                    $sql = "SELECT listing_id, open_house_data FROM {$bme_tables['open_houses']} WHERE listing_id IN (" . implode(',', $placeholders) . ") AND expires_at > NOW()";
                    $prepared_sql = $wpdb->prepare($sql, ...$listing_ids);
                    $open_house_results = $wpdb->get_results($prepared_sql);
                } else {
                    $open_house_results = [];
                }
                
                $open_houses_by_id = [];
                foreach ($open_house_results as $oh) {
                    if (!isset($open_houses_by_id[$oh->listing_id])) $open_houses_by_id[$oh->listing_id] = [];
                    $decoded_data = json_decode($oh->open_house_data, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
                        $open_houses_by_id[$oh->listing_id][] = $decoded_data;
                    }
                }

                // v6.49.6 - Use array syntax (listings are now arrays)
                foreach ($listings as &$listing) {
                    // Use ListingId to match with open house data
                    $listing['OpenHouseData'] = isset($open_houses_by_id[$listing['ListingId']]) ? json_encode($open_houses_by_id[$listing['ListingId']]) : '[]';
                }
                unset($listing); // Break reference
            } catch (Exception $e) {
                // Set empty open house data for all listings as safe fallback
                foreach ($listings as &$listing) {
                    $listing['OpenHouseData'] = '[]';
                }
                unset($listing); // Break reference
            }
        }

        $result = ['total' => $total_listings, 'listings' => $listings];

        // Store in cache
        MLD_Query_Cache::set($cache_key, $result, 180); // Cache for 3 minutes

        return $result;
    }

    public static function get_price_distribution($filters = []) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return ['min' => 0, 'display_max' => 0, 'distribution' => [], 'outlier_count' => 0];

        // Generate cache key for price distribution
        $cache_key = MLD_Query_Cache::generate_key('price_distribution', $filters);

        // Try to get from cache
        $cached = MLD_Query_Cache::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $context_filters = $filters;
        unset($context_filters['price_min'], $context_filters['price_max']);
        $query_info = self::determine_query_tables($context_filters);
        $where_conditions = self::build_filter_conditions($context_filters);
        
        // Check for polygon filters in the WHERE conditions
        $has_polygon = false;
        foreach ($where_conditions as $condition) {
            if (strpos($condition, 'll.coordinates') !== false || strpos($condition, 'ST_Y(ll.coordinates)') !== false || strpos($condition, 'ST_X(ll.coordinates)') !== false) {
                $has_polygon = true;
                break;
            }
        }
        
        $price_queries = [];
        foreach ($query_info['tables_to_query'] as $type) {
            $suffix = ($type === 'archive') ? '_archive' : '';
            // Use CASE for archive tables to handle different statuses correctly
            $price_col = ($type === 'archive') 
                ? "(CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END)" 
                : 'l.list_price';
            $sql = "SELECT {$price_col} AS price FROM {$bme_tables['listings' . $suffix]} AS l ";
            $sql .= self::get_minimal_joins_for_filters($where_conditions, $suffix, $has_polygon);
            if (!empty($where_conditions)) $sql .= " WHERE " . implode(' AND ', $where_conditions);
            $price_queries[] = "($sql)";
        }
        $full_price_query = implode(" UNION ALL ", $price_queries) . " ORDER BY price ASC";
        
        $prices = $wpdb->get_col($full_price_query);
        $prices = array_filter($prices, fn($p) => $p > 0);
        
        if (empty($prices)) return ['min' => 0, 'display_max' => 0, 'distribution' => [], 'outlier_count' => 0];

        sort($prices, SORT_NUMERIC);
        $min_price = (float) $prices[0];
        $price_count = count($prices);
        $percentile_index = floor($price_count * 0.95);
        $display_max_price = (float) $prices[min($percentile_index, $price_count - 1)];
        if ($display_max_price <= $min_price && $price_count > 0) $display_max_price = (float) end($prices);

        $num_buckets = 20;
        $bucket_size = ($display_max_price - $min_price) / $num_buckets;
        if ($bucket_size <= 0) $bucket_size = 1;
        $distribution = array_fill(0, $num_buckets, 0);
        $outlier_count = 0;
        foreach ($prices as $price) {
            if ($price > $display_max_price) $outlier_count++;
            else {
                $bucket_index = floor(($price - $min_price) / $bucket_size);
                $distribution[min($bucket_index, $num_buckets - 1)]++;
            }
        }
        $result = ['min' => $min_price, 'display_max' => $display_max_price, 'distribution' => $distribution, 'outlier_count' => $outlier_count];

        // Store in cache
        MLD_Query_Cache::set($cache_key, $result, 600); // Cache for 10 minutes

        return $result;
    }

    public static function get_listing_details($listing_id) {
        global $wpdb;

        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return null;

        // Generate cache key for listing details
        $cache_key = MLD_Query_Cache::generate_key('listing_details', ['id' => $listing_id]);

        // Try to get from cache
        $cached = MLD_Query_Cache::get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $listing = $wpdb->get_row(self::build_full_listing_query($listing_id, ''), ARRAY_A);
        if (!$listing) $listing = $wpdb->get_row(self::build_full_listing_query($listing_id, '_archive'), ARRAY_A);
        if ($listing) {
            // Get media using MLS number as BIGINT
            // Use the preserved listing_id which contains the actual MLS number
            $actual_mls = $listing['listing_id_preserved'] ?? $listing['listing_id'];
            $mls_number_bigint = intval(preg_replace('/[^0-9]/', '', $actual_mls));
            
            
            $listing['Media'] = $wpdb->get_results($wpdb->prepare(
                "SELECT media_url AS MediaURL, media_category AS MediaCategory, description AS ShortDescription 
                FROM {$bme_tables['media']} 
                WHERE listing_id = %d 
                ORDER BY media_category DESC, order_index ASC", 
                $mls_number_bigint
            ), ARRAY_A);
            // Wrap in try-catch to prevent failures from breaking the entire listing
            try {
                $listing['Rooms'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$bme_tables['rooms']} WHERE listing_id = %d", $mls_number_bigint), ARRAY_A);
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Query: Failed to fetch rooms data - ' . $e->getMessage());
                }
                $listing['Rooms'] = [];
            }
            
            try {
                $open_houses_raw = $wpdb->get_results($wpdb->prepare("SELECT open_house_data FROM {$bme_tables['open_houses']} WHERE listing_id = %d AND expires_at > NOW()", $mls_number_bigint), ARRAY_A);
                $listing['OpenHouseData'] = !empty($open_houses_raw) ? array_map(fn($oh) => json_decode($oh['open_house_data'], true), $open_houses_raw) : [];
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Query: Failed to fetch open house details - ' . $e->getMessage());
                }
                $listing['OpenHouseData'] = [];
            }
        }

        // Store in cache
        if ($listing) {
            MLD_Query_Cache::set($cache_key, $listing, 600); // Cache for 10 minutes
        }

        return $listing;
    }

    private static function build_full_listing_query($listing_id, $suffix) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        return $wpdb->prepare("
            SELECT /*+ USE_INDEX(l, PRIMARY) */ l.*, 
                   l.listing_id AS listing_id_preserved,
                   ld.*, ll.*, lf.*, lfeat.*,
                   ST_X(ll.coordinates) AS Longitude, ST_Y(ll.coordinates) AS Latitude,
                   la.agent_full_name AS ListAgentFullName, la.agent_email AS ListAgentEmail, la.agent_phone AS ListAgentPhone,
                   lo.office_name AS ListOfficeName, lo.office_phone AS ListOfficePhone,
                   lo.office_address AS ListOfficeAddress, lo.office_city AS ListOfficeCity,
                   lo.office_state AS ListOfficeState, lo.office_postal_code AS ListOfficePostalCode,
                   vt.virtual_tour_link_1, vt.virtual_tour_link_2, vt.virtual_tour_link_3
            FROM {$bme_tables['listings' . $suffix]} AS l
            LEFT JOIN {$bme_tables['listing_details' . $suffix]} AS ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id
            LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id
            LEFT JOIN {$bme_tables['agents']} AS la ON l.list_agent_mls_id = la.agent_mls_id
            LEFT JOIN {$bme_tables['offices']} AS lo ON l.list_office_mls_id = lo.office_mls_id
            LEFT JOIN {$bme_tables['virtual_tours']} AS vt ON l.listing_id = vt.listing_id
            WHERE l.listing_id = %s", $listing_id);
    }

    
    /**
     * Check if summary table exists and can be used for map search
     *
     * @return bool
     * @since 5.2.0
     */
    private static function can_use_summary_for_map_search($filters) {
        global $wpdb;

        // Check if summary table exists (uses cached check)
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        if (!self::table_exists($summary_table)) {
            return false;
        }

        // Check if we're filtering for non-active statuses
        // Summary table only contains Active, Active Under Contract, and Pending
        if (!empty($filters['status'])) {
            $allowed_statuses = ['Active', 'Active Under Contract', 'Pending', 'Under Agreement'];
            $status_values = is_array($filters['status']) ? $filters['status'] : [$filters['status']];

            foreach ($status_values as $status) {
                // If user wants Sold, Expired, Withdrawn, etc., we need archive tables
                if (!in_array($status, $allowed_statuses)) {
                    return false; // Need archive tables for Closed/Sold listings
                }
            }
        }

        // Check for filters that require columns not in summary table
        // Also include filters that need to search BOTH active and archive tables
        $unsupported_filters = [
            'structure_type',        // Requires wp_bme_listing_details.structure_type
            'architectural_style',   // Requires wp_bme_listing_details.architectural_style
            'entry_level_min',       // Requires wp_bme_listing_location.entry_level
            'entry_level_max',       // Requires wp_bme_listing_location.entry_level
            'parking_total_min',     // Requires wp_bme_listing_details.parking_total + covered_spaces
            'agent_ids',             // Requires wp_bme_listings.list_agent_mls_id, buyer_agent_mls_id, mlspin_team_member
            // Specific property searches - must query both active AND archive tables
            'MLS Number',            // MLS # search needs archive for sold listings
            'ListingId',             // Alternative MLS # filter name
            'listing_id',            // Another alternative MLS # filter name
            'Street Address',        // Parsed address search (including "All Units") needs archive
            // Autocomplete filters not in summary table
            'Address',               // Requires wp_bme_listing_location.unparsed_address (also needs archive)
            'Building',              // Requires wp_bme_listing_location.building_name
            'Neighborhood',          // Requires mls_area_major, mls_area_minor, subdivision_name
            // Amenity filters (all require wp_bme_listing_features table)
            'SpaYN', 'WaterfrontYN', 'ViewYN', 'MLSPIN_WATERVIEW_FLAG', 'PropertyAttachedYN',
            'MLSPIN_LENDER_OWNED', 'CoolingYN', 'SeniorCommunityYN', 'MLSPIN_OUTDOOR_SPACE_AVAILABLE',
            'MLSPIN_DPR_Flag', 'FireplaceYN', 'GarageYN', 'PoolPrivateYN', 'HorseYN',
            'HomeWarrantyYN', 'AttachedGarageYN', 'ElectricOnPropertyYN', 'AssociationYN',
            'PetsAllowed', 'CarportYN',
            // School quality filters (v6.30.3) - require post-query filtering via BMN Schools
            'near_a_elementary', 'near_ab_elementary',
            'near_a_middle', 'near_ab_middle',
            'near_a_high', 'near_ab_high',
            'school_grade', 'school_district_id',  // v6.30.22 - Added for parity
            // v6.68.11 - Rental filters that require JOINs (parity with iOS REST API)
            'laundry_features',      // Requires wp_bme_listing_details.laundry_features
            'lease_term',            // Requires wp_bme_listing_financial.lease_term
            'available_by',          // Requires wp_bme_listing_financial.availability_date
            'available_now',         // Requires wp_bme_listing_financial.availability_date
            'MLSPIN_AvailableNow'    // Alternative filter name
        ];

        foreach ($unsupported_filters as $filter_key) {
            if (isset($filters[$filter_key]) && $filters[$filter_key] !== '' &&
                (!is_array($filters[$filter_key]) || count($filters[$filter_key]) > 0)) {
                // This filter requires columns not in summary table - use full tables
                return false;
            }
        }

        return true;
    }

    /**
     * Get listings for map using optimized summary table
     * Provides 8.5x faster performance for map searches
     *
     * @since 5.2.0
     */
    private static function get_listings_for_map_optimized($north, $south, $east, $west, $filters = [], $count_only = false) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $features_table = $wpdb->prefix . 'bme_listing_features';

        // v6.30.21 - Detect if we need JOINs for amenity filters
        $needs_details_join = self::has_details_filters($filters);
        $needs_features_join = self::has_features_filters($filters);

        // Build JOINs
        $joins = '';
        $table_alias = 's';
        if ($needs_details_join) {
            $joins .= " LEFT JOIN {$details_table} ld ON s.listing_id = ld.listing_id";
        }
        if ($needs_features_join) {
            $joins .= " LEFT JOIN {$features_table} lf ON s.listing_id = lf.listing_id";
        }

        // Start with base query - only add default status if user hasn't specified one
        // Check if user has a status filter - if so, let build_summary_filter_conditions handle it
        $has_status_filter = !empty($filters['status']);
        if (!$has_status_filter) {
            $where_clauses = ["s.standard_status IN ('Active', 'Active Under Contract', 'Pending')"];
        } else {
            $where_clauses = [];
        }

        // Apply spatial bounds
        if ($north !== null && $south !== null && $east !== null && $west !== null) {
            $where_clauses[] = $wpdb->prepare(
                "s.latitude BETWEEN %f AND %f AND s.longitude BETWEEN %f AND %f",
                $south, $north, $west, $east
            );
        }

        // Apply standard filters (from summary table)
        if (!empty($filters)) {
            $filter_conditions = self::build_summary_filter_conditions($filters);
            if (!empty($filter_conditions)) {
                // Prefix with table alias for summary columns
                $prefixed_conditions = array_map(function($cond) {
                    // Don't prefix if already has alias or is a subquery
                    if (preg_match('/^(s\.|ld\.|lf\.|listing_id IN|\()/', $cond)) {
                        return $cond;
                    }
                    // Prefix column names with s. for summary table
                    return preg_replace('/^(\w+)(\s|=|<|>|!)/', 's.$1$2', $cond);
                }, $filter_conditions);
                $where_clauses = array_merge($where_clauses, $prefixed_conditions);
            }
        }

        // v6.30.21 - Add JOIN-dependent filter conditions
        if ($needs_details_join) {
            $join_conditions = self::build_details_filter_conditions($filters);
            if (!empty($join_conditions)) {
                $where_clauses = array_merge($where_clauses, $join_conditions);
            }
        }
        if ($needs_features_join) {
            $join_conditions = self::build_features_filter_conditions($filters);
            if (!empty($join_conditions)) {
                $where_clauses = array_merge($where_clauses, $join_conditions);
            }
        }

        $where_clause = implode(' AND ', $where_clauses);

        // v6.30.10 - Check for school filters early
        $has_school_filters = self::has_school_filters($filters);

        // If only counting - need to do post-filtering for school filters
        if ($count_only && !$has_school_filters) {
            $count_query = "SELECT COUNT(*) FROM {$summary_table} s{$joins} WHERE {$where_clause}";
            return (int) $wpdb->get_var($count_query);
        }

        // Build full query with limit
        // v6.30.10 - Fetch more when school filters active (they reduce results by ~60-90%)
        $limit = $has_school_filters ? 2000 : 200;

        // v6.65.0: Prioritize exclusive listings (listing_id < 1,000,000 = exclusive)
        $query = "SELECT s.* FROM {$summary_table} s{$joins}
                WHERE {$where_clause}
                ORDER BY CASE WHEN s.listing_id < 1000000 THEN 0 ELSE 1 END, s.list_price DESC
                LIMIT {$limit}";

        $listings = $wpdb->get_results($query, ARRAY_A);

        // Get total count (before school filtering)
        $total_query = "SELECT COUNT(*) FROM {$summary_table} s{$joins} WHERE {$where_clause}";
        $total = (int) $wpdb->get_var($total_query);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD] Map search using summary table - returned ' . count($listings) . ' of ' . $total . ' total listings');
        }

        // Normalize field names for frontend compatibility
        if (!empty($listings)) {
            foreach ($listings as &$listing) {
                // Map main_photo_url to photo_url
                if (isset($listing['main_photo_url']) && !isset($listing['photo_url'])) {
                    $listing['photo_url'] = $listing['main_photo_url'];
                }

                // Map bathrooms_total to bathrooms_total_integer
                if (isset($listing['bathrooms_total']) && !isset($listing['bathrooms_total_integer'])) {
                    $listing['bathrooms_total_integer'] = $listing['bathrooms_total'];
                }

                // Ensure living_area exists
                if (!isset($listing['living_area']) && isset($listing['building_area_total'])) {
                    $listing['living_area'] = $listing['building_area_total'];
                }

                // Handle null/empty values for numeric fields
                $numeric_fields = ['bedrooms_total', 'bathrooms_total_integer', 'building_area_total', 'list_price'];
                foreach ($numeric_fields as $field) {
                    if (empty($listing[$field]) && $listing[$field] !== 0) {
                        $listing[$field] = 0;
                    }
                }

                // Ensure coordinates are floats
                if (isset($listing['latitude'])) {
                    $listing['latitude'] = (float)$listing['latitude'];
                }
                if (isset($listing['longitude'])) {
                    $listing['longitude'] = (float)$listing['longitude'];
                }
            }
            unset($listing); // break the reference
        }

        // Attach open house data to listings
        if (!empty($listings)) {
            $listing_ids = array_column($listings, 'listing_id');

            if (!empty($listing_ids)) {
                $placeholders = array_fill(0, count($listing_ids), '%d');
                $open_house_table = $wpdb->prefix . 'bme_open_houses';
                $sql = "SELECT listing_id, open_house_data
                        FROM {$open_house_table}
                        WHERE listing_id IN (" . implode(',', $placeholders) . ")
                        AND expires_at > NOW()";
                $prepared_sql = $wpdb->prepare($sql, ...$listing_ids);
                $open_house_results = $wpdb->get_results($prepared_sql);
            } else {
                $open_house_results = [];
            }

            // Group open houses by listing_id
            $open_houses_by_id = [];
            foreach ($open_house_results as $oh) {
                if (!isset($open_houses_by_id[$oh->listing_id])) {
                    $open_houses_by_id[$oh->listing_id] = [];
                }
                $decoded_data = json_decode($oh->open_house_data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
                    $open_houses_by_id[$oh->listing_id][] = $decoded_data;
                }
            }

            // Attach open house data to each listing
            foreach ($listings as &$listing) {
                $listing['open_house_data'] = isset($open_houses_by_id[$listing['listing_id']])
                    ? json_encode($open_houses_by_id[$listing['listing_id']])
                    : '[]';
            }
            unset($listing);
        }

        // Attach school grades to listings (v6.30.1 - Phase 3)
        if (!empty($listings) && class_exists('MLD_BMN_Schools_Integration')) {
            $schools_integration = MLD_BMN_Schools_Integration::get_instance();
            if ($schools_integration) {
                foreach ($listings as &$listing) {
                    if (!empty($listing['latitude']) && !empty($listing['longitude'])) {
                        // Uses grid-based caching (~0.7mi grid, 30min TTL) for performance
                        $best_grade = $schools_integration->get_best_nearby_school_grade(
                            (float)$listing['latitude'],
                            (float)$listing['longitude'],
                            1.0 // 1 mile radius for "near" school
                        );
                        $listing['best_school_grade'] = $best_grade;
                    }

                    // v6.30.8 - Add district grade for property cards
                    if (!empty($listing['city'])) {
                        $district_info = $schools_integration->get_district_grade_for_city($listing['city']);
                        if ($district_info) {
                            $listing['district_grade'] = $district_info['grade'];
                            $listing['district_percentile'] = $district_info['percentile'];
                        }
                    }
                }
                unset($listing);
            }
        }

        // v6.30.10 - Apply school filter post-processing (same as traditional function)
        // This filters out properties that don't meet school criteria
        if ($has_school_filters && !empty($listings)) {
            // Convert array listings to objects for apply_school_filter compatibility
            $listing_objects = array_map(function($listing) {
                return (object) $listing;
            }, $listings);

            $filtered_objects = self::apply_school_filter($listing_objects, $filters);

            // Convert back to arrays
            $listings = array_map(function($obj) {
                return (array) $obj;
            }, $filtered_objects);

            // Update total to reflect filtered count
            $total = count($listings);

            // If count_only, return the filtered count now
            if ($count_only) {
                return $total;
            }

            // Re-apply limit after filtering
            if (count($listings) > 200) {
                $listings = array_slice($listings, 0, 200);
            }
        }

        // Transform to PascalCase for frontend JavaScript
        $listings = array_map([self::class, 'transform_to_pascalcase'], $listings);

        return [
            'listings' => $listings,
            'total' => $total
        ];
    }

    /**
     * Build filter conditions for summary table
     *
     * Note: MLD_Shared_Query_Builder is available for future refactoring.
     * The Query class has specialized handling (street name variations, etc.)
     * that requires careful migration. For now, this method maintains its
     * existing implementation for stability.
     *
     * @since 5.2.0
     * @since 6.30.20 School helpers delegate to MLD_Shared_Query_Builder
     */
    private static function build_summary_filter_conditions($filters) {
        global $wpdb;
        $conditions = [];

        // Check if this is a specific property search that should bypass status filter
        // MLS Number searches always bypass status
        $has_mls_number = !empty($filters['MLS Number']) || !empty($filters['ListingId']) || !empty($filters['listing_id']);

        // Address searches bypass status (both single addresses AND "All Units" for multi-unit view)
        $has_address_search = false;
        $address_filters = array('Address', 'Street Address');
        foreach ($address_filters as $addr_filter) {
            if (!empty($filters[$addr_filter])) {
                $has_address_search = true;
                break;
            }
        }

        $bypass_status_filter = $has_mls_number || $has_address_search;

        if ($bypass_status_filter && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Summary Query] Bypassing status filter - MLS: ' . ($has_mls_number ? 'yes' : 'no') . ', Address: ' . ($has_address_search ? 'yes' : 'no'));
        }

        foreach ($filters as $key => $filter) {
            // Extract value from filter (handle both direct values and nested arrays)
            $value = is_array($filter) && isset($filter['value']) ? $filter['value'] : $filter;

            if (empty($value) && $value !== 0 && $value !== '0') continue;

            // Skip status filter for specific property searches
            if ($key === 'status' && $bypass_status_filter) {
                continue;
            }

            switch ($key) {
                case 'PropertyType':  // Handle PascalCase from JavaScript
                case 'property_type':
                    // Special handling: "Residential" (For Sale) includes both Residential and Residential Income
                    if ($value === 'Residential') {
                        $conditions[] = "(property_type = 'Residential' OR property_type = 'Residential Income')";
                    } else {
                        $conditions[] = $wpdb->prepare("property_type = %s", $value);
                    }
                    break;

                case 'property_sub_type':
                case 'home_type':  // Frontend sends home_type, maps to property_sub_type
                    if (is_array($value)) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("[MLD Build] Processing filter key: $key, value: " . print_r($value, true));
                        }
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $conditions[] = $wpdb->prepare("property_sub_type IN ($placeholders)", ...$value);
                    } else {
                        $conditions[] = $wpdb->prepare("property_sub_type = %s", $value);
                    }
                    break;

                case 'price':
                    $min = isset($filter['min']) ? (int)$filter['min'] : 0;
                    $max = isset($filter['max']) ? (int)$filter['max'] : PHP_INT_MAX;
                    if ($min > 0) {
                        $conditions[] = $wpdb->prepare("list_price >= %d", $min);
                    }
                    if ($max < PHP_INT_MAX) {
                        $conditions[] = $wpdb->prepare("list_price <= %d", $max);
                    }
                    break;

                case 'price_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("list_price >= %d", (int)$value);
                    }
                    break;

                case 'price_max':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("list_price <= %d", (int)$value);
                    }
                    break;

                case 'bedrooms':
                    $min = isset($filter['min']) ? (int)$filter['min'] : 0;
                    if ($min > 0) {
                        $conditions[] = $wpdb->prepare("bedrooms_total >= %d", $min);
                    }
                    break;

                case 'beds':
                    if (is_array($value) && count($value) > 0) {
                        $placeholders = implode(',', array_fill(0, count($value), '%d'));
                        $conditions[] = $wpdb->prepare("bedrooms_total IN ($placeholders)", $value);
                    }
                    break;

                // v6.72.1: Added beds_min for iOS parity - uses minimum instead of array
                case 'beds_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("bedrooms_total >= %d", (int)$value);
                    }
                    break;

                case 'bathrooms':
                    $min = isset($filter['min']) ? (float)$filter['min'] : 0;
                    if ($min > 0) {
                        $conditions[] = $wpdb->prepare("bathrooms_total >= %f", $min);
                    }
                    break;

                case 'baths_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("bathrooms_total >= %f", (float)$value);
                    }
                    break;

                case 'square_feet':
                    $min = isset($filter['min']) ? (int)$filter['min'] : 0;
                    $max = isset($filter['max']) ? (int)$filter['max'] : PHP_INT_MAX;
                    if ($min > 0) {
                        $conditions[] = $wpdb->prepare("building_area_total >= %d", $min);
                    }
                    if ($max < PHP_INT_MAX) {
                        $conditions[] = $wpdb->prepare("building_area_total <= %d", $max);
                    }
                    break;

                case 'sqft_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("building_area_total >= %d", (int)$value);
                    }
                    break;

                case 'sqft_max':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("building_area_total <= %d", (int)$value);
                    }
                    break;

                case 'status':
                    // Map user-friendly status names to actual database statuses
                    if (is_array($value)) {
                        $status_conditions = [];
                        foreach ($value as $status) {
                            if ($status === 'Under Agreement' || $status === 'Pending') {
                                // Pending/Under Agreement includes both Pending and Active Under Contract
                                $status_conditions[] = "(standard_status = 'Pending' OR standard_status = 'Active Under Contract')";
                            } elseif ($status === 'Sold') {
                                $status_conditions[] = "standard_status = 'Closed'";
                            } else {
                                $status_conditions[] = $wpdb->prepare("standard_status = %s", $status);
                            }
                        }
                        if (!empty($status_conditions)) {
                            $conditions[] = '(' . implode(' OR ', $status_conditions) . ')';
                        }
                    } else {
                        if ($value === 'Under Agreement' || $value === 'Pending') {
                            $conditions[] = "(standard_status = 'Pending' OR standard_status = 'Active Under Contract')";
                        } elseif ($value === 'Sold') {
                            $conditions[] = "standard_status = 'Closed'";
                        } else {
                            $conditions[] = $wpdb->prepare("standard_status = %s", $value);
                        }
                    }
                    break;

                case 'City':  // Handle capital C from JavaScript
                case 'city':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $conditions[] = $wpdb->prepare("city IN ($placeholders)", $value);
                    } else {
                        $conditions[] = $wpdb->prepare("city = %s", $value);
                    }
                    break;

                // MLS Number / Listing ID filter (from autocomplete)
                case 'MLS Number':
                case 'ListingId':
                case 'listing_id':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $conditions[] = $wpdb->prepare("listing_id IN ($placeholders)", $value);
                    } else {
                        $conditions[] = $wpdb->prepare("listing_id = %s", $value);
                    }
                    break;

                // Postal Code filter (from autocomplete)
                case 'Postal Code':
                case 'PostalCode':
                case 'postal_code':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $conditions[] = $wpdb->prepare("postal_code IN ($placeholders)", $value);
                    } else {
                        $conditions[] = $wpdb->prepare("postal_code = %s", $value);
                    }
                    break;

                // Street Name filter (from autocomplete) - with normalization
                case 'Street Name':
                case 'StreetName':
                case 'street_name':
                    if (is_array($value)) {
                        $street_conditions = [];
                        foreach ($value as $v) {
                            $street_variations_sql = self::get_street_name_variations_sql($v, 'street_name');
                            $street_conditions[] = $street_variations_sql;
                        }
                        if (!empty($street_conditions)) {
                            $conditions[] = '(' . implode(' OR ', $street_conditions) . ')';
                        }
                    } else {
                        $street_variations_sql = self::get_street_name_variations_sql($value, 'street_name');
                        $conditions[] = $street_variations_sql;
                    }
                    break;

                // Street Address filter (from autocomplete) - street_number + street_name
                case 'Street Address':
                    if (is_array($value)) {
                        $address_conditions = [];
                        foreach ($value as $v) {
                            // Remove "(All Units)" indicator if present
                            $v = str_replace(' (All Units)', '', $v);
                            // Parse street number and street name
                            if (preg_match('/^(\d+)\s+(.+)$/i', trim($v), $matches)) {
                                $street_number = $matches[1];
                                $street_name = $matches[2];
                                $street_variations_sql = self::get_street_name_variations_sql($street_name, 'street_name');
                                $address_conditions[] = $wpdb->prepare("street_number = %s AND {$street_variations_sql}", $street_number);
                            }
                        }
                        if (!empty($address_conditions)) {
                            $conditions[] = '(' . implode(' OR ', $address_conditions) . ')';
                        }
                    } else {
                        // Single Street Address value
                        $value = str_replace(' (All Units)', '', $value);
                        if (preg_match('/^(\d+)\s+(.+)$/i', trim($value), $matches)) {
                            $street_number = $matches[1];
                            $street_name = $matches[2];
                            $street_variations_sql = self::get_street_name_variations_sql($street_name, 'street_name');
                            $conditions[] = $wpdb->prepare("street_number = %s AND {$street_variations_sql}", $street_number);
                        }
                    }
                    break;

                case 'year_built_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("year_built >= %d", (int)$value);
                    }
                    break;

                case 'year_built_max':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("year_built <= %d", (int)$value);
                    }
                    break;

                case 'lot_size_min':
                    if ($value > 0) {
                        // v6.68.11: Input is in square feet, convert to acres (1 acre = 43560 sqft)
                        // This matches iOS REST API behavior for parity
                        $acres = (float)$value / 43560.0;
                        $conditions[] = $wpdb->prepare("lot_size_acres >= %f", $acres);
                    }
                    break;

                case 'lot_size_max':
                    if ($value > 0) {
                        // v6.68.11: Input is in square feet, convert to acres (1 acre = 43560 sqft)
                        // This matches iOS REST API behavior for parity
                        $acres = (float)$value / 43560.0;
                        $conditions[] = $wpdb->prepare("lot_size_acres <= %f", $acres);
                    }
                    break;

                case 'garage_spaces_min':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("garage_spaces >= %d", (int)$value);
                    }
                    break;

                // Boolean amenity filters (from summary table)
                case 'has_pool':
                    if ($value) {
                        $conditions[] = "has_pool = 1";
                    }
                    break;

                case 'has_fireplace':
                    if ($value) {
                        $conditions[] = "has_fireplace = 1";
                    }
                    break;

                case 'has_basement':
                    if ($value) {
                        $conditions[] = "has_basement = 1";
                    }
                    break;

                case 'pet_friendly':
                    if ($value) {
                        $conditions[] = "pet_friendly = 1";
                    }
                    break;

                case 'polygon_shapes':
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MLD Polygon] Received ' . count($value) . ' polygon(s)');
                    }
                    // Handle drawn polygon shapes for boundary filtering
                    if (is_array($value) && count($value) > 0) {
                        $polygon_conditions = [];

                        foreach ($value as $polygon_coords) {
                            if (is_array($polygon_coords) && count($polygon_coords) >= 3) {
                                // Each polygon is an array of [lat, lng] coordinates
                                // build_polygon_condition expects [[lat, lng], [lat, lng], ...]
                                $condition = self::build_summary_polygon_condition($polygon_coords);
                                if ($condition) {
                                    $polygon_conditions[] = $condition;
                                }
                            }
                        }

                        if (!empty($polygon_conditions)) {
                            // OR logic - listing can be in ANY of the drawn polygons
                            $conditions[] = '(' . implode(' OR ', $polygon_conditions) . ')';
                        }
                    }
                    break;

                case 'open_house_only':
                    if ($value) {
                        // Filter to only show listings with upcoming open houses
                        $open_house_table = $wpdb->prefix . 'bme_open_houses';
                        $conditions[] = "listing_id IN (SELECT listing_id FROM {$open_house_table} WHERE expires_at > NOW())";
                    }
                    break;

                // v6.30.19 - Price reduced filter (parity with iOS REST API)
                case 'price_reduced':
                    if ($value) {
                        $conditions[] = "(original_list_price IS NOT NULL AND original_list_price > 0 AND list_price < original_list_price)";
                    }
                    break;

                // v6.30.19 - New listing days filter (parity with iOS REST API)
                case 'new_listing_days':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare(
                            "listing_contract_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                            (int)$value
                        );
                    }
                    break;

                // v6.30.19 - Max days on market filter (parity with iOS REST API)
                // v6.57.0 - Added days_on_market_max as alias for web filter UI
                case 'max_dom':
                case 'days_on_market_max':
                    if ($value > 0) {
                        $conditions[] = $wpdb->prepare("days_on_market <= %d", (int)$value);
                    }
                    break;

                // v6.30.19 - Neighborhood filter (parity with iOS REST API)
                case 'Neighborhood':
                case 'neighborhood':
                    if (is_array($value)) {
                        $placeholders = implode(',', array_fill(0, count($value), '%s'));
                        $conditions[] = $wpdb->prepare("subdivision_name IN ($placeholders)", ...$value);
                    } else {
                        $conditions[] = $wpdb->prepare("subdivision_name = %s", $value);
                    }
                    break;

                // v6.30.21 - Virtual tour filter (parity with iOS REST API)
                case 'has_virtual_tour':
                    if ($value) {
                        $conditions[] = "(virtual_tour_url IS NOT NULL AND virtual_tour_url != '')";
                    }
                    break;

                // v6.30.21 - Map YN filter names to summary table columns
                // These are the filter values sent from the frontend checkboxes
                case 'PoolPrivateYN':
                    if ($value) {
                        $conditions[] = "has_pool = 1";
                    }
                    break;

                case 'FireplaceYN':
                    if ($value) {
                        $conditions[] = "has_fireplace = 1";
                    }
                    break;

                // v6.30.21 - Parking total filter (needs details table JOIN - handled in optimized function)
                case 'parking_total_min':
                    // Note: This requires JOIN with listing_details table
                    // Handled separately in get_listings_for_map_optimized()
                    break;

                // === RENTAL FILTERS (parity with iOS REST API) ===
                // v6.68.11: Granular pet filters for rental properties
                case 'pets_dogs':
                    if ($value) {
                        $conditions[] = "pets_dogs_allowed = 1";
                    }
                    break;

                case 'pets_cats':
                    if ($value) {
                        $conditions[] = "pets_cats_allowed = 1";
                    }
                    break;

                case 'pets_none':
                    if ($value) {
                        // No pets allowed - check for pet_friendly = 0 or NULL
                        $conditions[] = "(pet_friendly = 0 OR pet_friendly IS NULL)";
                    }
                    break;

                case 'pets_negotiable':
                    if ($value) {
                        $conditions[] = "pets_negotiable = 1";
                    }
                    break;

                // Note: laundry_features, lease_term, and availability filters require JOINs
                // They are handled in the traditional query path (see unsupported_filters list)
            }
        }

        return $conditions;
    }

public static function get_listings_for_map($north, $south, $east, $west, $filters = [], $is_new_filter = false, $count_only = false, $is_initial_load = false, $zoom = 13, $debug = false, $is_state_restoration = false) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return ['listings' => [], 'total' => 0];

        // For state restoration or any force refresh scenario, bypass cache to ensure fresh results
        $use_cache = !$is_state_restoration && !$is_new_filter;

        if ($is_state_restoration || $is_new_filter) {
        }

        if ($use_cache) {
            // Generate cache key for map listings
            $cache_params = [
                'north' => $north,
                'south' => $south,
                'east' => $east,
                'west' => $west,
                'filters' => $filters,
                'is_new_filter' => $is_new_filter,
                'count_only' => $count_only,
                'is_initial_load' => $is_initial_load,
                'zoom' => $zoom,
                'is_state_restoration' => $is_state_restoration
            ];
            $cache_key = MLD_Query_Cache::generate_key('map_listings', $cache_params);

            // Try to get from cache
            $cached = MLD_Query_Cache::get($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        } else {
        }

        
        // Check if we can use the optimized summary table
        if (self::can_use_summary_for_map_search($filters)) {
            $optimized_result = self::get_listings_for_map_optimized($north, $south, $east, $west, $filters, $count_only);

            if ($optimized_result !== false) {
                // Cache the optimized result if caching is enabled
                if ($use_cache && isset($cache_key)) {
                    MLD_Query_Cache::set($cache_key, $optimized_result, 1800); // 30 minute cache
                }

                MLD_Performance_Monitor::endTimer('get_listings_for_map');
                return $optimized_result;
            }
        }

        // Fall back to traditional multi-table query
        $result = self::get_listings_for_map_traditional(
            $north, $south, $east, $west,
            $filters, $count_only, $is_new_filter, $is_initial_load,
            $zoom, $debug, $is_state_restoration
        );

        // Store in cache with appropriate TTL (only if cache is enabled)
        if ($use_cache && is_array($result)) {
            $cache_ttl = $is_initial_load ? 300 : 180; // 5 minutes for initial load, 3 minutes for regular
            MLD_Query_Cache::set($cache_key, $result, $cache_ttl);
        }

        return $result;
    }

    /**
     * Execute traditional multi-table query for map listings
     *
     * This method handles the complex query logic when the optimized summary table
     * cannot be used (e.g., for filters requiring full table joins).
     *
     * @param float $north Northern latitude bound
     * @param float $south Southern latitude bound
     * @param float $east Eastern longitude bound
     * @param float $west Western longitude bound
     * @param array $filters Filter conditions
     * @param bool $count_only Whether to return only count
     * @param bool $is_new_filter Whether this is a new filter application
     * @param bool $is_initial_load Whether this is initial page load
     * @param int $zoom Current map zoom level
     * @param bool $debug Whether to include debug info
     * @param bool $is_state_restoration Whether restoring saved state
     * @return array|int Query results or count
     * @since 6.11.5
     */
    private static function get_listings_for_map_traditional($north, $south, $east, $west, $filters, $count_only, $is_new_filter, $is_initial_load, $zoom, $debug, $is_state_restoration) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();

        // Start performance monitoring
        MLD_Performance_Monitor::startTimer('get_listings_for_map', ['zoom' => $zoom, 'filters' => is_array($filters) ? count($filters) : 0]);

        $query_info = self::determine_query_tables($filters);
        $filter_conditions = self::build_filter_conditions($filters ?: []);

        // Build count query with proper price field handling
        $count_total = 0;
        foreach ($query_info['tables_to_query'] as $table_type) {
            $suffix = ($table_type === 'archive') ? '_archive' : '';

            // Adjust filter conditions for archive tables to use close_price instead of list_price
            $table_filter_conditions = $filter_conditions;
            if ($table_type === 'archive') {
                $table_filter_conditions = array_map(function($condition) {
                    return str_replace('l.list_price',
                        "(CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END)",
                        $condition);
                }, $table_filter_conditions);
            }

            $from_clause = "FROM {$bme_tables['listings' . $suffix]} AS l";
            $from_clause .= self::get_minimal_joins_for_filters($table_filter_conditions, $suffix, true);

            $count_query = "SELECT COUNT(DISTINCT l.id) " . $from_clause;
            if (!empty($table_filter_conditions)) $count_query .= " WHERE " . implode(' AND ', $table_filter_conditions);

            $count_total += (int) $wpdb->get_var($count_query);
        }
        $total_for_filters = $count_total;

        // For count_only without school filters, return SQL count immediately
        // School filters require post-query filtering, so we continue to fetch listings
        $has_school_filters = self::has_school_filters($filters);
        if ($count_only && !$has_school_filters) {
            return $total_for_filters;
        }

        $view_conditions = $filter_conditions;

        // Apply spatial filtering when appropriate
        $should_apply_spatial_filter = ((!$is_new_filter && !$is_initial_load) || $is_state_restoration) &&
                                     ($north !== null && $south !== null && $east !== null && $west !== null);

        if ($should_apply_spatial_filter) {
            $polygon_wkt = sprintf('POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))', $west, $north, $east, $north, $east, $south, $west, $south, $west, $north);
            $view_conditions[] = $wpdb->prepare("ST_Contains(ST_GeomFromText(%s), ll.coordinates)", $polygon_wkt);
        }

        // Build and execute queries for each table
        $all_listings = [];
        // For count_only with school filters, we need all listings for post-filtering
        // Otherwise use standard limit of 200
        $limit = ($count_only && $has_school_filters) ? 10000 : 200;
        $has_city_filter = false;
        $has_spatial_filter = false;
        $table_view_conditions = [];

        foreach ($query_info['tables_to_query'] as $table_type) {
            $suffix = ($table_type === 'archive') ? '_archive' : '';

            // Price field handling
            $price_field = ($table_type === 'archive')
                ? "CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END AS ListPrice"
                : 'l.list_price AS ListPrice';

            $is_archive_flag = ($table_type === 'archive') ? "'1' AS IsArchive" : "'0' AS IsArchive";

            $select_fields = "l.id, l.listing_id AS ListingId, {$price_field}, l.original_list_price AS OriginalListPrice, l.standard_status AS StandardStatus, l.property_type AS PropertyType, l.property_sub_type AS PropertySubType, l.listing_key, l.modification_timestamp,
                              l.original_entry_timestamp, l.off_market_date, l.close_date,
                              ll.street_number AS StreetNumber, ll.street_name AS StreetName, ll.unit_number AS UnitNumber, ll.city AS City, ll.state_or_province AS StateOrProvince, ll.postal_code AS PostalCode, ST_Y(ll.coordinates) AS Latitude, ST_X(ll.coordinates) AS Longitude, ll.entry_level AS EntryLevel,
                              ld.bedrooms_total AS BedroomsTotal, ld.bathrooms_full AS BathroomsFull, ld.bathrooms_half AS BathroomsHalf, ld.bathrooms_total_integer AS BathroomsTotalInteger, ld.living_area AS LivingArea, ld.year_built AS YearBuilt,
                              ld.lot_size_acres AS LotSizeAcres, ld.lot_size_square_feet AS LotSizeSquareFeet,
                              lf.association_fee AS AssociationFee, lf.association_fee_frequency AS AssociationFeeFrequency, ld.garage_spaces AS GarageSpaces, ld.parking_total AS ParkingTotal, ld.covered_spaces AS CoveredSpaces,
                              lfeat.waterfront_yn AS WaterfrontYN,
                              {$is_archive_flag},
                              (SELECT media_url FROM {$bme_tables['media']} m WHERE m.listing_id = l.listing_id ORDER BY m.order_index ASC LIMIT 1) as photo_url";

            // Adjust conditions for archive tables
            $table_view_conditions = $view_conditions;
            if ($table_type === 'archive') {
                $table_view_conditions = array_map(function($condition) {
                    return str_replace('l.list_price',
                        "(CASE WHEN l.standard_status = 'Closed' AND l.close_price IS NOT NULL THEN l.close_price ELSE l.list_price END)",
                        $condition);
                }, $table_view_conditions);
            }

            // Detect filter types for index optimization
            $has_city_filter = false;
            $has_spatial_filter = false;
            foreach ($table_view_conditions as $condition) {
                if (strpos($condition, '`ll`.`city`') !== false || strpos($condition, 'll.city') !== false) {
                    $has_city_filter = true;
                }
                if (strpos($condition, 'ST_Contains') !== false) {
                    $has_spatial_filter = true;
                }
            }

            // Reorder conditions for better index usage
            if ($has_city_filter && $has_spatial_filter) {
                $spatial_conditions = [];
                $other_conditions = [];
                foreach ($table_view_conditions as $condition) {
                    if (strpos($condition, 'ST_Contains') !== false) {
                        $spatial_conditions[] = $condition;
                    } else {
                        $other_conditions[] = $condition;
                    }
                }
                $table_view_conditions = array_merge($spatial_conditions, $other_conditions);
            }

            // Build FROM clause with all required joins
            $from_clause = "FROM {$bme_tables['listings' . $suffix]} AS l";
            $from_clause .= " LEFT JOIN {$bme_tables['listing_details' . $suffix]} AS ld ON l.listing_id = ld.listing_id";
            $from_clause .= " LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id";
            $from_clause .= " LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id";
            $from_clause .= " LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id";

            // Force spatial index when both city and spatial filters exist
            if ($has_spatial_filter && $has_city_filter) {
                $from_clause = str_replace(
                    "{$bme_tables['listing_location' . $suffix]} AS ll",
                    "{$bme_tables['listing_location' . $suffix]} AS ll FORCE INDEX (spatial_coordinates)",
                    $from_clause
                );
            }

            $query = "SELECT " . $select_fields . " " . $from_clause;
            if (!empty($table_view_conditions)) {
                $query .= " WHERE " . implode(' AND ', $table_view_conditions);
            }
            // v6.65.0: Prioritize exclusive listings (listing_id < 1,000,000)
            $query .= " ORDER BY CASE WHEN l.listing_id < 1000000 THEN 0 ELSE 1 END, l.list_price DESC, l.modification_timestamp DESC";
            $query .= " LIMIT " . $limit;

            // v6.49.6 - Use ARRAY_A to return arrays (fixes neighborhood filter crash)
            $listings_from_table = $wpdb->get_results($query, ARRAY_A);

            if (defined('WP_DEBUG') && WP_DEBUG && !empty($wpdb->last_error)) {
                error_log("MLD Query ERROR: " . $wpdb->last_error);
            }

            if ($listings_from_table) {
                $all_listings = array_merge($all_listings, $listings_from_table);
            }
        }

        // Sort and limit merged results
        if (count($query_info['tables_to_query']) > 1 && count($all_listings) > 0) {
            // v6.49.6 - Use array syntax (listings are now arrays, not objects)
            // v6.65.0 - Add exclusive listing priority (listing_id < 1,000,000)
            usort($all_listings, function($a, $b) {
                // Exclusive listings first (listing_id < 1,000,000)
                $exclusive_a = (isset($a['listing_id']) && intval($a['listing_id']) < 1000000) ? 0 : 1;
                $exclusive_b = (isset($b['listing_id']) && intval($b['listing_id']) < 1000000) ? 0 : 1;
                if ($exclusive_a !== $exclusive_b) {
                    return $exclusive_a - $exclusive_b;
                }

                $status_priority_a = ($a['StandardStatus'] === 'Active') ? 0 : (($a['StandardStatus'] === 'Pending') ? 1 : 2);
                $status_priority_b = ($b['StandardStatus'] === 'Active') ? 0 : (($b['StandardStatus'] === 'Pending') ? 1 : 2);
                if ($status_priority_a !== $status_priority_b) {
                    return $status_priority_a - $status_priority_b;
                }
                $price_diff = (float)$b['ListPrice'] - (float)$a['ListPrice'];
                if (abs($price_diff) > 1000) {
                    return $price_diff > 0 ? 1 : -1;
                }
                return strcmp($b['modification_timestamp'], $a['modification_timestamp']);
            });
            $listings = array_slice($all_listings, 0, $limit);
        } else {
            $listings = $all_listings;
        }

        // Attach open house data
        // v6.49.6 - Use array_column and array syntax (listings are now arrays)
        if (!empty($listings)) {
            try {
                $listing_ids = array_column($listings, 'ListingId');
                $id_placeholders = implode(',', array_fill(0, count($listing_ids), '%d'));

                $open_house_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT listing_id, open_house_data FROM {$bme_tables['open_houses']} WHERE listing_id IN ($id_placeholders) AND expires_at > NOW()",
                    ...$listing_ids
                ));

                $open_houses_by_id = [];
                foreach ($open_house_results as $oh) {
                    if (!isset($open_houses_by_id[$oh->listing_id])) $open_houses_by_id[$oh->listing_id] = [];
                    $decoded_data = json_decode($oh->open_house_data, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_data)) {
                        $open_houses_by_id[$oh->listing_id][] = $decoded_data;
                    }
                }

                foreach ($listings as &$listing) {
                    $listing['OpenHouseData'] = isset($open_houses_by_id[$listing['ListingId']]) ? json_encode($open_houses_by_id[$listing['ListingId']]) : '[]';
                }
                unset($listing); // Break reference
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Query: Failed to fetch open house data - ' . $e->getMessage());
                }
                foreach ($listings as &$listing) {
                    $listing['OpenHouseData'] = '[]';
                }
                unset($listing); // Break reference
            }
        }

        // Apply school filter post-processing if school filters are active (v6.30.3)
        if ($has_school_filters && !empty($listings)) {
            $listings = self::apply_school_filter($listings, $filters);

            // If count_only with school filters, return the filtered count
            if ($count_only) {
                MLD_Performance_Monitor::endTimer('get_listings_for_map');
                return count($listings);
            }

            // Update total to reflect school-filtered count
            $total_for_filters = count($listings);

            // Apply limit after school filtering
            if (count($listings) > 200) {
                $listings = array_slice($listings, 0, 200);
            }
        }

        MLD_Performance_Monitor::endTimer('get_listings_for_map');

        $result = ['listings' => $listings, 'total' => $total_for_filters];

        if ($debug) {
            $result['debug'] = [
                'has_city_filter' => $has_city_filter,
                'has_spatial_filter' => $has_spatial_filter,
                'forced_spatial_index' => ($has_city_filter && $has_spatial_filter),
                'zoom' => $zoom,
                'conditions' => $table_view_conditions,
                'bounds' => ['north' => $north, 'south' => $south, 'east' => $east, 'west' => $west]
            ];
        }

        return $result;
    }

    /**
     * Build SQL condition for GeoJSON geometry (districts, neighborhoods, etc.)
     * Delegates to MLD_Spatial_Filter_Service for implementation
     *
     * @param array $geojson GeoJSON geometry object
     * @param string $table_alias Table alias for coordinates column (default: 'll')
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5 - Refactored to use MLD_Spatial_Filter_Service
     */
    private static function build_geojson_condition($geojson, $table_alias = 'll') {
        return MLD_Spatial_Filter_Service::get_instance()->build_geojson_condition($geojson, $table_alias);
    }

    /**
     * Build SQL condition for point-in-polygon check using summary table columns
     * Delegates to MLD_Spatial_Filter_Service for implementation
     *
     * @param array $polygon_coords Array of [lat, lng] coordinate pairs
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5 - Refactored to use MLD_Spatial_Filter_Service
     */
    private static function build_summary_polygon_condition($polygon_coords) {
        return MLD_Spatial_Filter_Service::get_instance()->build_summary_polygon_condition($polygon_coords);
    }

    /**
     * Build SQL condition for point-in-polygon check
     * Delegates to MLD_Spatial_Filter_Service for implementation
     *
     * @param array $polygon_coords Array of [lat, lng] coordinate pairs
     * @param string $table_alias Table alias for coordinates column (default: 'll')
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5 - Refactored to use MLD_Spatial_Filter_Service
     */
    private static function build_polygon_condition($polygon_coords, $table_alias = 'll') {
        return MLD_Spatial_Filter_Service::get_instance()->build_polygon_condition($polygon_coords, $table_alias);
    }
    
    private static function build_aggregate_query($select, $wheres, $tables_to_query, $include_joins = false) {
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return "";
        $queries = [];
        foreach ($tables_to_query as $type) {
            $suffix = ($type === 'archive') ? '_archive' : '';
            $from_clause = "FROM {$bme_tables['listings' . $suffix]} AS l";
            if ($include_joins) $from_clause .= self::get_minimal_joins_for_filters($wheres, $suffix, true);
            $query = (strpos($select, "COUNT") !== false ? "SELECT " . $select : $select) . " " . $from_clause;
            if (!empty($wheres)) $query .= " WHERE " . implode(' AND ', $wheres);
            $queries[] = "($query)";
        }
        if (strpos($select, "COUNT") !== false) {
            $count_queries = str_replace("COUNT(DISTINCT l.id)", "COUNT(DISTINCT l.id) as total", $queries);
            return "SELECT SUM(total) FROM (" . implode(" UNION ALL ", $count_queries) . ") AS counts";
        }
        return implode(" UNION ALL ", $queries);
    }

    private static function determine_query_tables($filters) {
        // Check if this is a property search that needs both tables
        // MLS Number searches always need both tables
        $has_mls_number = !empty($filters['MLS Number']) || !empty($filters['ListingId']);

        // Address searches need both tables (includes single address AND "(All Units)" for multi-unit)
        $has_address_search = false;
        $address_filters = array('Address', 'Street Address');
        foreach ($address_filters as $addr_filter) {
            if (!empty($filters[$addr_filter])) {
                $has_address_search = true;
                break;
            }
        }

        // For MLS Number or Address searches, always query both tables
        if ($has_mls_number || $has_address_search) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log('[MLD Query] Property search detected - querying both active and archive tables');
            }
            return ['tables_to_query' => ['active', 'archive']];
        }

        if (!empty($filters['status']) && is_array($filters['status'])) {
            $has_active = false; $has_archive = false;
            // Map user-friendly status names to actual database statuses
            $archive_statuses = ['Closed', 'Expired', 'Withdrawn', 'Pending', 'Canceled', 'Active Under Contract'];

            foreach ($filters['status'] as $status) {
                // Handle mapped statuses
                if ($status === 'Under Agreement') {
                    // Under Agreement includes Pending and Active Under Contract (archive)
                    $has_archive = true;
                } elseif ($status === 'Sold') {
                    // Sold maps to Closed (archive)
                    $has_archive = true;
                } elseif (in_array($status, $archive_statuses)) {
                    $has_archive = true;
                } else {
                    $has_active = true;
                }
            }
            if ($has_active && !$has_archive) return ['tables_to_query' => ['active']];
            if (!$has_active && $has_archive) return ['tables_to_query' => ['archive']];
        }
        return ['tables_to_query' => ['active', 'archive']];
    }
    
    private static function build_filter_conditions($filters, $exclude_keys = []) {
        global $wpdb;
        $conditions = [];
        $filter_map = [
            'PropertyType' => ['alias' => 'l', 'column' => 'property_type', 'type' => 'property_type'], 'price_min' => ['alias' => 'l', 'column' => 'list_price', 'compare' => '>='],
            'price_max' => ['alias' => 'l', 'column' => 'list_price', 'compare' => '<='], 'beds' => ['alias' => 'ld', 'column' => 'bedrooms_total'],
            'beds_min' => ['alias' => 'ld', 'column' => 'bedrooms_total', 'compare' => '>='],  // v6.72.1: Min-only to align with iOS
            'baths_min' => ['alias' => 'ld', 'column' => 'bathrooms_total_integer', 'compare' => '>='], 'home_type' => ['alias' => 'l', 'column' => 'property_sub_type'],
            'status' => ['alias' => 'l', 'column' => 'standard_status', 'type' => 'status_mapping'], 'sqft_min' => ['alias' => 'ld', 'column' => 'living_area', 'compare' => '>='],
            'sqft_max' => ['alias' => 'ld', 'column' => 'living_area', 'compare' => '<='], 'year_built_min' => ['alias' => 'ld', 'column' => 'year_built', 'compare' => '>='],
            'year_built_max' => ['alias' => 'ld', 'column' => 'year_built', 'compare' => '<='], 'garage_spaces_min' => ['alias' => 'ld', 'column' => 'garage_spaces', 'compare' => '>='],
            'City' => ['alias' => 'll', 'column' => 'city'], 
            'PostalCode' => ['alias' => 'll', 'column' => 'postal_code'], 
            'Postal Code' => ['alias' => 'll', 'column' => 'postal_code'],  // Support autocomplete format
            'StreetName' => ['alias' => 'll', 'column' => 'street_name'],
            'Street Name' => ['alias' => 'll', 'column' => 'street_name', 'type' => 'street_name_normalized'],  // Support autocomplete format with normalization
            'ListingId' => ['alias' => 'l', 'column' => 'listing_id'],
            'MLS Number' => ['alias' => 'l', 'column' => 'listing_id'],     // Support autocomplete format
            'Address' => ['alias' => 'll', 'column' => 'unparsed_address'], // Support autocomplete format
            'Street Address' => ['alias' => 'll', 'column' => 'STREET_ADDRESS', 'type' => 'street_address'], // Street number + name search
            'Building' => ['alias' => 'll', 'column' => 'building_name'],   // Support autocomplete format
            'Neighborhood' => ['alias' => 'll', 'column' => 'NEIGHBORHOOD', 'type' => 'neighborhood'], // Special handling for mls_area_major, mls_area_minor, and subdivision_name 
            'structure_type' => ['alias' => 'ld', 'column' => 'structure_type', 'type' => 'multi'],
            'architectural_style' => ['alias' => 'ld', 'column' => 'architectural_style', 'type' => 'multi'],
            'lot_size_min' => ['alias' => 'ld', 'column' => 'lot_size_square_feet', 'compare' => '>='],
            'lot_size_max' => ['alias' => 'ld', 'column' => 'lot_size_square_feet', 'compare' => '<='],
            'entry_level_min' => ['alias' => 'll', 'column' => 'entry_level', 'compare' => '>=', 'type' => 'numeric_string'],
            'entry_level_max' => ['alias' => 'll', 'column' => 'entry_level', 'compare' => '<=', 'type' => 'numeric_string'],
            'parking_total_min' => ['alias' => 'ld', 'column' => 'COMBINED_PARKING', 'compare' => '>=', 'type' => 'combined_parking'],
            'SpaYN' => ['alias' => 'lfeat', 'column' => 'spa_yn', 'type' => 'bool'],
            'WaterfrontYN' => ['alias' => 'lfeat', 'column' => 'waterfront_yn', 'type' => 'bool'], 
            'ViewYN' => ['alias' => 'lfeat', 'column' => 'view_yn', 'type' => 'bool'],
            'MLSPIN_WATERVIEW_FLAG' => ['alias' => 'lfeat', 'column' => 'mlspin_waterview_flag', 'type' => 'bool'], 
            'PropertyAttachedYN' => ['alias' => 'ld', 'column' => 'property_attached_yn', 'type' => 'bool'],
            'MLSPIN_LENDER_OWNED' => ['alias' => 'lf', 'column' => 'mlspin_lender_owned', 'type' => 'bool'], 
            'CoolingYN' => ['alias' => 'ld', 'column' => 'cooling_yn', 'type' => 'bool'],
            'SeniorCommunityYN' => ['alias' => 'lfeat', 'column' => 'senior_community_yn', 'type' => 'bool'],
            'MLSPIN_OUTDOOR_SPACE_AVAILABLE' => ['alias' => 'lfeat', 'column' => 'mlspin_outdoor_space_available', 'type' => 'bool'],
            'MLSPIN_DPR_Flag' => ['alias' => 'lf', 'column' => 'mlspin_dpr_flag', 'type' => 'bool'],
            'FireplaceYN' => ['alias' => 'ld', 'column' => 'fireplace_yn', 'type' => 'bool'],
            'GarageYN' => ['alias' => 'ld', 'column' => 'garage_yn', 'type' => 'bool'],
            'PoolPrivateYN' => ['alias' => 'lfeat', 'column' => 'pool_private_yn', 'type' => 'bool'],
            'HorseYN' => ['alias' => 'lfeat', 'column' => 'horse_yn', 'type' => 'bool'],
            'HomeWarrantyYN' => ['alias' => 'ld', 'column' => 'home_warranty_yn', 'type' => 'bool'],
            'AttachedGarageYN' => ['alias' => 'ld', 'column' => 'attached_garage_yn', 'type' => 'bool'],
            'ElectricOnPropertyYN' => ['alias' => 'ld', 'column' => 'electric_on_property_yn', 'type' => 'bool'],
            'AssociationYN' => ['alias' => 'lf', 'column' => 'association_yn', 'type' => 'bool'],
            'PetsAllowed' => ['alias' => 'lfeat', 'column' => 'pets_allowed', 'type' => 'bool'],
            'CarportYN' => ['alias' => 'ld', 'column' => 'carport_yn', 'type' => 'bool'],
            // Agent filter
            'agent_ids' => ['special' => true, 'type' => 'agent'],
            // Special polygon filter (handled separately below)
            'polygon_shapes' => ['special' => true],
        ];
        /**
         * SPATIAL FILTERING SYSTEM - OR Logic Implementation
         *
         * As of October 2025, the system supports three types of spatial filters with OR logic:
         * 1. City filters - Pre-defined city boundaries
         * 2. Neighborhood filters - MLS area major/minor and subdivision data
         * 3. Polygon shapes - User-drawn custom polygons
         *
         * These filters are processed separately and combined with OR logic to provide
         * union behavior: listings appear if they match ANY spatial criteria.
         *
         * This approach ensures order-independent behavior - whether users select
         * cities first or draw shapes first, both filters are preserved and combined.
         */
        $location_conditions = [];
        $has_city_filter = isset($filters['City']) && !in_array('City', $exclude_keys) && !empty($filters['City']);
        $has_neighborhood_filter = isset($filters['Neighborhood']) && !in_array('Neighborhood', $exclude_keys) && !empty($filters['Neighborhood']);
        $has_polygon_filter = isset($filters['polygon_shapes']) && !in_array('polygon_shapes', $exclude_keys) && !empty($filters['polygon_shapes']);

        foreach ($filters as $key => $value) {
            if (in_array($key, $exclude_keys) || !isset($filter_map[$key]) || ($value === '' || (is_array($value) && empty($value)))) continue;

            // Skip special filters that are handled separately below
            if ($key === 'polygon_shapes' || $key === 'agent_ids' || $key === 'City' || $key === 'Neighborhood') continue;
            $map = $filter_map[$key]; $col = "`{$map['alias']}`.`{$map['column']}`";
            $compare = $map['compare'] ?? '=';

            if (isset($map['type']) && $map['type'] === 'bool') {
                if ($value) $conditions[] = "{$col} = 1";
            } elseif (isset($map['type']) && $map['type'] === 'property_type') {
                // Special handling for PropertyType - "For Sale" includes both Residential and Residential Income
                if ($value === 'Residential') {
                    $conditions[] = "(`l`.`property_type` = 'Residential' OR `l`.`property_type` = 'Residential Income')";
                } else {
                    $conditions[] = $wpdb->prepare("{$col} = %s", $value);
                }
            } elseif (isset($map['type']) && $map['type'] === 'status_mapping') {
                // Check if this is a property search that should bypass status filter
                // MLS Number searches always bypass status
                $has_mls_number = !empty($filters['MLS Number']) || !empty($filters['ListingId']);

                // Address searches bypass status filter to show all properties at that address
                // This includes both single addresses AND "(All Units)" for multi-unit buildings
                $has_address_search = false;
                $address_filters = array('Address', 'Street Address');
                foreach ($address_filters as $addr_filter) {
                    if (!empty($filters[$addr_filter])) {
                        $has_address_search = true;
                        break;
                    }
                }

                // Bypass status filter for MLS Number or any Address search
                if ($has_mls_number || $has_address_search) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log('[MLD Query] Bypassing status filter for property search - MLS: ' . ($has_mls_number ? 'yes' : 'no') . ', Address: ' . ($has_address_search ? 'yes' : 'no'));
                    }
                    continue;
                }

                // Special handling for status mapping
                if (is_array($value)) {
                    $status_conditions = [];
                    foreach ($value as $status) {
                        if ($status === 'Under Agreement' || $status === 'Pending') {
                            // Pending/Under Agreement includes both Pending and Active Under Contract
                            $status_conditions[] = "(`l`.`standard_status` = 'Pending' OR `l`.`standard_status` = 'Active Under Contract')";
                        } elseif ($status === 'Sold') {
                            // Sold maps to Closed
                            $status_conditions[] = "`l`.`standard_status` = 'Closed'";
                        } else {
                            // Direct status mapping (Active, Expired, Withdrawn)
                            $status_conditions[] = $wpdb->prepare("`l`.`standard_status` = %s", $status);
                        }
                    }
                    if (!empty($status_conditions)) {
                        $conditions[] = '(' . implode(' OR ', $status_conditions) . ')';
                    }
                } else {
                    // Single status value
                    if ($value === 'Under Agreement' || $value === 'Pending') {
                        $conditions[] = "(`l`.`standard_status` = 'Pending' OR `l`.`standard_status` = 'Active Under Contract')";
                    } elseif ($value === 'Sold') {
                        $conditions[] = "`l`.`standard_status` = 'Closed'";
                    } else {
                        $conditions[] = $wpdb->prepare("`l`.`standard_status` = %s", $value);
                    }
                }
            } elseif (isset($map['type']) && $map['type'] === 'combined_parking') {
                // Special handling for combined parking (parking_total + covered_spaces)
                $conditions[] = $wpdb->prepare("(IFNULL(`ld`.`parking_total`, 0) + IFNULL(`ld`.`covered_spaces`, 0)) >= %d", $value);
            } elseif (isset($map['type']) && $map['type'] === 'numeric_string') {
                // Handle string fields that contain numeric values - cast to signed integer for proper comparison
                $conditions[] = $wpdb->prepare("CAST({$col} AS SIGNED) {$compare} %d", intval($value));
            } elseif (isset($map['type']) && $map['type'] === 'street_address') {
                // Handle Street Address type - parse street number and name
                if (is_array($value)) {
                    $address_conditions = [];
                    foreach ($value as $v) {
                        // Remove "(All Units)" indicator if present
                        $v = str_replace(' (All Units)', '', $v);

                        // Parse street number and street name
                        if (preg_match('/^(\d+)\s+(.+)$/i', trim($v), $matches)) {
                            $street_number = $matches[1];
                            $street_name = $matches[2];

                            // Get street name variations for matching
                            $street_variations_sql = self::get_street_name_variations_sql($street_name, 'll`.`street_name');
                            $address_conditions[] = $wpdb->prepare("`ll`.`street_number` = %s AND {$street_variations_sql}", $street_number);
                        }
                    }
                    if (!empty($address_conditions)) {
                        $conditions[] = '(' . implode(' OR ', $address_conditions) . ')';
                    }
                } else {
                    // Single Street Address value
                    // Remove "(All Units)" indicator if present
                    $value = str_replace(' (All Units)', '', $value);

                    // Parse street number and street name
                    if (preg_match('/^(\d+)\s+(.+)$/i', trim($value), $matches)) {
                        $street_number = $matches[1];
                        $street_name = $matches[2];

                        // Get street name variations for matching
                        $street_variations_sql = self::get_street_name_variations_sql($street_name, 'll`.`street_name');
                        $conditions[] = $wpdb->prepare("`ll`.`street_number` = %s AND {$street_variations_sql}", $street_number);
                    }
                }
            } elseif (isset($map['type']) && $map['type'] === 'street_name_normalized') {
                // Handle normalized street name searches
                if (is_array($value)) {
                    $street_conditions = [];
                    foreach ($value as $v) {
                        $street_variations_sql = self::get_street_name_variations_sql($v, 'll`.`street_name');
                        $street_conditions[] = $street_variations_sql;
                    }
                    if (!empty($street_conditions)) {
                        $conditions[] = '(' . implode(' OR ', $street_conditions) . ')';
                    }
                } else {
                    $street_variations_sql = self::get_street_name_variations_sql($value, 'll`.`street_name');
                    $conditions[] = $street_variations_sql;
                }
            } elseif (isset($map['type']) && $map['type'] === 'neighborhood') {
                // Special handling for neighborhood - check mls_area_major, mls_area_minor, and subdivision_name
                if (is_array($value)) {
                    $neighborhood_conditions = [];
                    foreach ($value as $v) {
                        $neighborhood_conditions[] = $wpdb->prepare("(`ll`.`mls_area_major` = %s OR `ll`.`mls_area_minor` = %s OR `ll`.`subdivision_name` = %s)", $v, $v, $v);
                    }
                    if (!empty($neighborhood_conditions)) {
                        $conditions[] = '(' . implode(' OR ', $neighborhood_conditions) . ')';
                    }
                } else {
                    $conditions[] = $wpdb->prepare("(`ll`.`mls_area_major` = %s OR `ll`.`mls_area_minor` = %s OR `ll`.`subdivision_name` = %s)", $value, $value, $value);
                }
            } elseif (isset($map['type']) && $map['type'] === 'multi') {
                // Handle multi-value fields that contain JSON arrays stored as strings
                if (is_array($value)) {
                    $multi_conditions = [];
                    foreach ($value as $v) {
                        // Since these are VARCHAR columns with JSON strings, we need string matching
                        // Match patterns like ["Colonial"] or ["Colonial", "Other"]
                        $conditions_for_value = [];
                        
                        // For JSON arrays, we need to handle potential spacing variations
                        $escaped_v = $wpdb->esc_like($v);
                        
                        // Exact match for single value array: ["Colonial"]
                        $conditions_for_value[] = $wpdb->prepare("{$col} = %s", '["' . $v . '"]');
                        
                        // Match with spaces after comma: ["Value", "Other"]
                        $conditions_for_value[] = $wpdb->prepare("{$col} LIKE %s", '%"' . $escaped_v . '"%');
                        
                        // Also check for non-JSON exact match
                        $conditions_for_value[] = $wpdb->prepare("{$col} = %s", $v);
                        
                        $multi_conditions[] = '(' . implode(' OR ', $conditions_for_value) . ')';
                    }
                    if (!empty($multi_conditions)) {
                        $conditions[] = '(' . implode(' OR ', $multi_conditions) . ')';
                    }
                } else {
                    // Single value selection
                    $escaped_value = $wpdb->esc_like($value);
                    $conditions_for_value = [];
                    
                    // Exact match for single value array
                    $conditions_for_value[] = $wpdb->prepare("{$col} = %s", '["' . $value . '"]');
                    
                    // Match anywhere in JSON array (handles spacing variations)
                    $conditions_for_value[] = $wpdb->prepare("{$col} LIKE %s", '%"' . $escaped_value . '"%');
                    
                    // Non-JSON exact match
                    $conditions_for_value[] = $wpdb->prepare("{$col} = %s", $value);
                    
                    $conditions[] = '(' . implode(' OR ', $conditions_for_value) . ')';
                }
            } elseif (is_array($value)) {
                if ($key === 'beds') {
                    $bed_conditions = [];
                    $has_plus = false;
                    
                    foreach ($value as $bed) {
                        if (strpos($bed, '+') !== false) {
                            $bed_conditions[] = $wpdb->prepare("{$col} >= %d", intval($bed));
                            $has_plus = true;
                        } else {
                            $bed_conditions[] = $wpdb->prepare("{$col} = %d", intval($bed));
                        }
                    }
                    
                    if (count($bed_conditions) > 1 && $has_plus) {
                        $conditions[] = $wpdb->prepare("{$col} >= %d", min(array_map('intval', $value)));
                    } else {
                        $conditions[] = '( ' . implode(' OR ', $bed_conditions) . ' )';
                    }
                } else {
                    $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                    $conditions[] = $wpdb->prepare("{$col} IN ({$placeholders})", ...$value);
                }
            } else {
                $conditions[] = $wpdb->prepare("{$col} {$compare} %s", $value);
            }
        }
        if (!empty($filters['open_house_only']) && !in_array('open_house_only', $exclude_keys)) {
            $bme_tables = self::get_bme_tables();
            $conditions[] = "l.listing_id IN (SELECT listing_id FROM {$bme_tables['open_houses']} WHERE expires_at > NOW())";
        }

        /**
         * STEP 1: Process Location-Based Filters (City and Neighborhood)
         *
         * These filters are processed separately from the main filter loop to enable
         * special OR logic when combined with polygon shapes. Each location filter
         * type supports multiple values and is stored in $location_conditions array.
         */

        // Process City filters - supports single city or multiple cities
        if ($has_city_filter) {
            $map = $filter_map['City'];
            $col = "`{$map['alias']}`.`{$map['column']}`";
            $value = $filters['City'];

            if (is_array($value)) {
                $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                $location_conditions[] = $wpdb->prepare("{$col} IN ({$placeholders})", ...$value);
            } else {
                $location_conditions[] = $wpdb->prepare("{$col} = %s", $value);
            }
        }

        // Process Neighborhood filters - checks multiple MLS area fields
        if ($has_neighborhood_filter) {
            $map = $filter_map['Neighborhood'];
            $value = $filters['Neighborhood'];

            if (is_array($value)) {
                // Handle multiple neighborhoods - each neighborhood checks all three area fields
                $neighborhood_conditions = [];
                foreach ($value as $v) {
                    // Neighborhood data can be stored in any of these three fields
                    $neighborhood_conditions[] = $wpdb->prepare("(`ll`.`mls_area_major` = %s OR `ll`.`mls_area_minor` = %s OR `ll`.`subdivision_name` = %s)", $v, $v, $v);
                }
                if (!empty($neighborhood_conditions)) {
                    $location_conditions[] = '(' . implode(' OR ', $neighborhood_conditions) . ')';
                }
            } else {
                // Single neighborhood value
                $location_conditions[] = $wpdb->prepare("(`ll`.`mls_area_major` = %s OR `ll`.`mls_area_minor` = %s OR `ll`.`subdivision_name` = %s)", $value, $value, $value);
            }
        }

        /**
         * STEP 2: Process Polygon Shape Filters
         *
         * Polygon shapes are user-drawn custom areas on the map. Each polygon is
         * converted to a spatial condition using point-in-polygon calculations.
         * Multiple polygons are combined with OR logic.
         */
        $polygon_condition = null;
        if ($has_polygon_filter) {
            $polygon_conditions = [];

            // Debug log
            if (WP_DEBUG) {
            }

            foreach ($filters['polygon_shapes'] as $polygon_coords) {
                if (is_array($polygon_coords) && count($polygon_coords) >= 3) {
                    // Use a custom point-in-polygon check
                    // For each polygon, we'll check if the listing's lat/lng is inside
                    $single_polygon_condition = self::build_polygon_condition($polygon_coords);
                    if ($single_polygon_condition) {
                        $polygon_conditions[] = $single_polygon_condition;

                        // Debug log the condition
                        if (WP_DEBUG) {
                        }
                    }
                }
            }
            if (!empty($polygon_conditions)) {
                // OR logic - listing can be in any of the polygons
                $polygon_condition = '(' . implode(' OR ', $polygon_conditions) . ')';
            }
        }

        /**
         * STEP 3: Combine All Spatial Filters with OR Logic
         *
         * This is the core of the spatial filtering system. All spatial filters
         * (cities, neighborhoods, polygons) are combined with OR logic to create
         * union behavior. Listings will appear if they match ANY of the spatial criteria.
         *
         * Example result: WHERE (city1 OR city2 OR neighborhood1 OR polygon1 OR polygon2)
         */
        $has_location_filters = $has_city_filter || $has_neighborhood_filter;

        if ($has_location_filters && $has_polygon_filter) {
            // Case: Both location filters (city/neighborhood) AND polygon filters exist
            $all_spatial_conditions = [];

            // Add location conditions
            if (!empty($location_conditions)) {
                $all_spatial_conditions = array_merge($all_spatial_conditions, $location_conditions);
            }

            // Add polygon condition
            if ($polygon_condition) {
                $all_spatial_conditions[] = $polygon_condition;
            }

            if (!empty($all_spatial_conditions)) {
                // Combine all spatial filters with OR logic for union behavior
                $conditions[] = "(" . implode(' OR ', $all_spatial_conditions) . ")";

                // Debug log
                if (WP_DEBUG) {
                }
            }
        } elseif ($has_location_filters && !empty($location_conditions)) {
            // Case: Only location filters (cities/neighborhoods) exist, no polygons
            if (count($location_conditions) > 1) {
                // Multiple location conditions - wrap in parentheses for OR logic
                $conditions[] = "(" . implode(' OR ', $location_conditions) . ")";
            } else {
                // Single location condition - add directly
                $conditions[] = $location_conditions[0];
            }
        } elseif ($has_polygon_filter && $polygon_condition) {
            // Case: Only polygon filters exist, no location filters
            $conditions[] = $polygon_condition;
        }

        /**
         * End of Spatial Filtering System
         *
         * At this point, all spatial filters have been processed and combined.
         * The resulting condition(s) are added to the main $conditions array
         * and will be combined with other non-spatial filters using AND logic.
         */

        // Handle agent filter (generic - matches any agent field)
        if (!empty($filters['agent_ids']) && !in_array('agent_ids', $exclude_keys)) {
            $agent_conditions = [];
            $agent_ids = is_array($filters['agent_ids']) ? $filters['agent_ids'] : [$filters['agent_ids']];

            if (WP_DEBUG) {
            }

            if (!empty($agent_ids)) {
                // Create placeholders for each agent ID
                $placeholders = implode(', ', array_fill(0, count($agent_ids), '%s'));

                // Check all three agent fields
                $agent_conditions[] = $wpdb->prepare("l.list_agent_mls_id IN ({$placeholders})", ...$agent_ids);
                $agent_conditions[] = $wpdb->prepare("l.buyer_agent_mls_id IN ({$placeholders})", ...$agent_ids);
                $agent_conditions[] = $wpdb->prepare("l.mlspin_team_member IN ({$placeholders})", ...$agent_ids);

                // OR logic - listing can have agent in any of the three fields
                $agent_condition = '(' . implode(' OR ', $agent_conditions) . ')';
                $conditions[] = $agent_condition;

                if (WP_DEBUG) {
                }
            }
        }

        // Handle specific listing agent (seller's agent) filter
        if (!empty($filters['listing_agent_id']) && !in_array('listing_agent_id', $exclude_keys)) {
            $conditions[] = $wpdb->prepare("l.list_agent_mls_id = %s", $filters['listing_agent_id']);
        }

        // Handle specific buyer agent filter
        if (!empty($filters['buyer_agent_id']) && !in_array('buyer_agent_id', $exclude_keys)) {
            $conditions[] = $wpdb->prepare("l.buyer_agent_mls_id = %s", $filters['buyer_agent_id']);
        }

        // =====================================================================
        // RENTAL FILTERS - v6.68.11 (parity with iOS REST API)
        // These filters require JOINs with listing_details and listing_financial
        // =====================================================================

        // Laundry features filter (In Unit, In Building, None)
        if (!empty($filters['laundry_features']) && !in_array('laundry_features', $exclude_keys)) {
            $laundry_values = is_array($filters['laundry_features']) ? $filters['laundry_features'] : array($filters['laundry_features']);
            $laundry_conditions = [];

            foreach ($laundry_values as $laundry) {
                $laundry = trim($laundry);
                if ($laundry === 'None') {
                    // No laundry facilities
                    $laundry_conditions[] = "(ld.laundry_features = '[]' OR ld.laundry_features IS NULL OR ld.laundry_features = '')";
                } else {
                    // Match specific laundry type (In Unit, In Building, etc.)
                    $laundry_conditions[] = $wpdb->prepare("ld.laundry_features LIKE %s", '%' . $wpdb->esc_like($laundry) . '%');
                }
            }

            if (!empty($laundry_conditions)) {
                $conditions[] = "(" . implode(' OR ', $laundry_conditions) . ")";
            }
        }

        // Lease term filter (12 months, 6 months, Monthly, Flexible)
        if (!empty($filters['lease_term']) && !in_array('lease_term', $exclude_keys)) {
            $lease_values = is_array($filters['lease_term']) ? $filters['lease_term'] : array($filters['lease_term']);
            $lease_conditions = [];

            foreach ($lease_values as $term) {
                $term_lower = strtolower(trim($term));
                switch ($term_lower) {
                    case '12 months':
                    case '12months':
                        // 12 month lease (Rental(12) or Rental(12+))
                        $lease_conditions[] = "(lf.lease_term LIKE '%%Rental(12)%%' OR lf.lease_term LIKE '%%Rental(12+)%%')";
                        break;
                    case '6 months':
                    case '6months':
                        // 6 month lease options
                        $lease_conditions[] = "(lf.lease_term LIKE '%%Rental(6)%%' OR lf.lease_term LIKE '%%Rental(6+)%%' OR lf.lease_term LIKE '%%Rental(6-12)%%')";
                        break;
                    case 'monthly':
                    case 'month-to-month':
                        // Month-to-month or tenant at will
                        $lease_conditions[] = "(lf.lease_term LIKE '%%Tenant at Will%%' OR lf.lease_term LIKE '%%Monthly%%' OR lf.lease_term LIKE '%%Taw%%')";
                        break;
                    case 'flexible':
                    case 'short term':
                        // Flexible or short-term lease
                        $lease_conditions[] = "(lf.lease_term LIKE '%%Flex%%' OR lf.lease_term LIKE '%%Short Term%%')";
                        break;
                    default:
                        // Generic search for other terms
                        $lease_conditions[] = $wpdb->prepare("lf.lease_term LIKE %s", '%' . $wpdb->esc_like($term) . '%');
                        break;
                }
            }

            if (!empty($lease_conditions)) {
                $conditions[] = "(" . implode(' OR ', $lease_conditions) . ")";
            }
        }

        // Available by date filter (rentals available by a specific date)
        if (!empty($filters['available_by']) && !in_array('available_by', $exclude_keys)) {
            $available_date = sanitize_text_field($filters['available_by']);
            // Validate date format (YYYY-MM-DD)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $available_date)) {
                $conditions[] = $wpdb->prepare("lf.availability_date <= %s", $available_date);
            }
        }

        // Available now filter (rentals available immediately)
        if ((!empty($filters['available_now']) || !empty($filters['MLSPIN_AvailableNow'])) &&
            !in_array('available_now', $exclude_keys) && !in_array('MLSPIN_AvailableNow', $exclude_keys)) {
            // Available now = availability_date is today or earlier, or NULL (immediate)
            $today = current_time('Y-m-d');
            $conditions[] = $wpdb->prepare("(lf.availability_date IS NULL OR lf.availability_date <= %s)", $today);
        }

        // Debug: Log built conditions
        if (WP_DEBUG && !empty($conditions)) {
        }

        return $conditions;
    }

    public static function get_distinct_filter_options($filters = []) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return [];
        
        // Debug logging for polygon filters
        if (WP_DEBUG && !empty($filters['polygon_shapes'])) {
        }
        
        $options = [];
        $query_info = self::determine_query_tables($filters);
        
        $single_value_fields = ['PropertySubType' => 'home_type', 'StandardStatus'  => 'status'];
        $multi_value_fields = ['StructureType' => 'structure_type', 'ArchitecturalStyle' => 'architectural_style'];
        $boolean_fields = [
            'SpaYN', 'WaterfrontYN', 'ViewYN', 'MLSPIN_WATERVIEW_FLAG', 'PropertyAttachedYN', 
            'MLSPIN_LENDER_OWNED', 'SeniorCommunityYN', 'MLSPIN_OUTDOOR_SPACE_AVAILABLE', 
            'MLSPIN_DPR_Flag', 'CoolingYN', 'FireplaceYN', 'GarageYN', 'PoolPrivateYN',
            'HorseYN', 'HomeWarrantyYN', 'AttachedGarageYN', 'ElectricOnPropertyYN',
            'AssociationYN', 'PetsAllowed', 'CarportYN'
        ];

        foreach ($single_value_fields as $field => $key) {
            $context_filters = self::build_filter_conditions($filters, [$key]);
            
            // Debug log the context filters for each field
            if (WP_DEBUG && !empty($filters['polygon_shapes'])) {
            }
            
            $options[$field] = self::get_distinct_single_value_options($field, $context_filters, $query_info);
        }
        foreach ($multi_value_fields as $field => $key) {
             $context_filters = self::build_filter_conditions($filters, [$key]);
            $options[$field] = self::get_distinct_multi_value_options($field, $context_filters, $query_info);
        }
        $options['amenities'] = [];
        foreach ($boolean_fields as $field) {
            $context_filters = self::build_filter_conditions($filters, [$field]);
            $count = self::get_boolean_field_count($field, $context_filters, $query_info);
            if ($count > 0) $options['amenities'][$field] = ['label' => MLD_Utils::get_field_label($field), 'count' => (int)$count];
        }
        $context_filters_oh = self::build_filter_conditions($filters, ['open_house_only']);
        $oh_count = self::get_boolean_field_count('open_house_only', $context_filters_oh, $query_info);
        if ($oh_count > 0) $options['amenities']['open_house_only'] = ['label' => 'Open House Only', 'count' => (int)$oh_count];
        return $options;
    }

    private static function get_distinct_single_value_options($field, $wheres, $query_info) {
        global $wpdb; $bme_tables = self::get_bme_tables(); $results = [];
        $field_map = ['PropertySubType' => ['table' => 'listings', 'db_col' => 'property_sub_type'], 'StandardStatus' => ['table' => 'listings', 'db_col' => 'standard_status']];
        $table_key = $field_map[$field]['table'] ?? 'listings';
        $db_col = $field_map[$field]['db_col'] ?? $field;
        
        // Debug logging for polygon filters in SQL
        $has_polygon = false;
        foreach ($wheres as $where) {
            if (strpos($where, 'll.coordinates') !== false || strpos($where, 'ST_Y(ll.coordinates)') !== false || strpos($where, 'ST_X(ll.coordinates)') !== false || strpos($where, 'ST_Contains') !== false) {
                $has_polygon = true;
                break;
            }
        }
        if (WP_DEBUG && $has_polygon) {
        }
        
        foreach ($query_info['tables_to_query'] as $type) {
            $suffix = ($type === 'archive') ? '_archive' : '';
            $table_name = $bme_tables[$table_key . $suffix];
            $sql = "SELECT l.`{$db_col}`, COUNT(*) as count FROM `{$table_name}` AS l ";
            
            // Force joining the location table if we have polygon filters
            $force_location_join = $has_polygon;
            $joins = self::get_minimal_joins_for_filters($wheres, $suffix, $force_location_join);
            $sql .= $joins;
            
            $where_clause = !empty($wheres) ? " WHERE " . implode(' AND ', $wheres) : "";
            $sql .= $where_clause;
            $sql .= " AND l.`{$db_col}` IS NOT NULL AND l.`{$db_col}` != '' GROUP BY l.`{$db_col}`";
            
            // Debug log the SQL if polygon filter is present
            if (WP_DEBUG && $has_polygon) {
                if (!empty($wheres)) {
                }
            }

            $query_results = $wpdb->get_results($sql);

            // Debug log results
            if (WP_DEBUG && $has_polygon && !empty($query_results)) {
            }
            
            $results = array_merge($results, $query_results);
        }
        $merged = [];
        foreach ($results as $row) { $val = $row->$db_col; $merged[$val] = ($merged[$val] ?? 0) + $row->count; }
        arsort($merged);
        return array_keys($merged);
    }

    private static function get_distinct_multi_value_options($field, $wheres, $query_info) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        $results = [];
        
        // Map fields to their database locations
        $field_map = [
            'StructureType' => ['table' => 'listing_details', 'db_col' => 'structure_type'],
            'ArchitecturalStyle' => ['table' => 'listing_details', 'db_col' => 'architectural_style']
        ];
        
        if (!isset($field_map[$field])) return [];
        
        $table_key = $field_map[$field]['table'];
        $db_col = $field_map[$field]['db_col'];
        
        foreach ($query_info['tables_to_query'] as $type) {
            $suffix = ($type === 'archive') ? '_archive' : '';
            $table_name = $bme_tables[$table_key . $suffix];
            
            // Check for polygon filters in the WHERE conditions
            $has_polygon = false;
            foreach ($wheres as $where) {
                if (strpos($where, 'll.coordinates') !== false || strpos($where, 'ST_Y(ll.coordinates)') !== false || strpos($where, 'ST_X(ll.coordinates)') !== false || strpos($where, 'ST_Contains') !== false) {
                    $has_polygon = true;
                    break;
                }
            }
            
            $sql = "SELECT ld.`{$db_col}`, COUNT(*) as count 
                    FROM `{$bme_tables['listings' . $suffix]}` AS l 
                    LEFT JOIN `{$table_name}` AS ld ON l.listing_id = ld.listing_id ";
            
            // Only add location table if we have polygon filters (ld is already joined above)
            if ($has_polygon) {
                $sql .= " LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id ";
            }
            
            // Check if we need other joins (but exclude ld and ll since we already have them)
            $filter_str = implode(' ', $wheres);
            if (strpos($filter_str, '`lf`.') !== false) {
                $sql .= " LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id ";
            }
            if (strpos($filter_str, '`lfeat`.') !== false) {
                $sql .= " LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id ";
            }
            
            $where_clause = !empty($wheres) ? " WHERE " . implode(' AND ', $wheres) : "";
            $sql .= $where_clause;
            $sql .= " AND ld.`{$db_col}` IS NOT NULL AND ld.`{$db_col}` != '' AND ld.`{$db_col}` != '[]'
                     GROUP BY ld.`{$db_col}`";
            
            // Debug log the SQL if polygon filter is present
            if (WP_DEBUG && $has_polygon) {
            }

            $query_results = $wpdb->get_results($sql);

            // Debug log results
            if (WP_DEBUG && $has_polygon) {
            }
            
            if (!empty($query_results)) {
                $results = array_merge($results, $query_results);
            }
        }
        
        // If no results found, return empty array
        if (empty($results)) {
            return [];
        }
        
        // Merge results from active and archive tables
        $merged = [];
        foreach ($results as $row) {
            $val = $row->$db_col;
            
            // Handle JSON array format or comma-separated values
            if (strpos($val, '[') === 0) {
                // It's a JSON array
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $v) {
                        $v = trim($v);
                        if (!empty($v)) {
                            $merged[$v] = ($merged[$v] ?? 0) + $row->count;
                        }
                    }
                } else {
                    // If JSON decode fails, treat as single value
                    $val = trim($val);
                    if (!empty($val)) {
                        $merged[$val] = ($merged[$val] ?? 0) + $row->count;
                    }
                }
            } else {
                // Handle comma-separated values or single values
                $values = array_map('trim', explode(',', $val));
                foreach ($values as $v) {
                    if (!empty($v)) {
                        $merged[$v] = ($merged[$v] ?? 0) + $row->count;
                    }
                }
            }
        }
        
        arsort($merged);
        
        // Format the results to match frontend expectations
        $formatted = [];
        foreach ($merged as $value => $count) {
            $formatted[] = [
                'value' => $value,
                'label' => $value,
                'count' => (int)$count
            ];
        }
        
        return $formatted;
    }
    private static function get_boolean_field_count($field, $wheres, $query_info) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        
        // Map boolean fields to their database locations
        $field_map = [
            // listing_features table
            'SpaYN' => ['table' => 'listing_features', 'db_col' => 'spa_yn', 'alias' => 'lfeat'],
            'WaterfrontYN' => ['table' => 'listing_features', 'db_col' => 'waterfront_yn', 'alias' => 'lfeat'],
            'ViewYN' => ['table' => 'listing_features', 'db_col' => 'view_yn', 'alias' => 'lfeat'],
            'MLSPIN_WATERVIEW_FLAG' => ['table' => 'listing_features', 'db_col' => 'mlspin_waterview_flag', 'alias' => 'lfeat'],
            'SeniorCommunityYN' => ['table' => 'listing_features', 'db_col' => 'senior_community_yn', 'alias' => 'lfeat'],
            'MLSPIN_OUTDOOR_SPACE_AVAILABLE' => ['table' => 'listing_features', 'db_col' => 'mlspin_outdoor_space_available', 'alias' => 'lfeat'],
            'PoolPrivateYN' => ['table' => 'listing_features', 'db_col' => 'pool_private_yn', 'alias' => 'lfeat'],
            'HorseYN' => ['table' => 'listing_features', 'db_col' => 'horse_yn', 'alias' => 'lfeat'],
            'PetsAllowed' => ['table' => 'listing_features', 'db_col' => 'pets_allowed', 'alias' => 'lfeat'],
            
            // listing_details table
            'PropertyAttachedYN' => ['table' => 'listing_details', 'db_col' => 'property_attached_yn', 'alias' => 'ld'],
            'CoolingYN' => ['table' => 'listing_details', 'db_col' => 'cooling_yn', 'alias' => 'ld'],
            'FireplaceYN' => ['table' => 'listing_details', 'db_col' => 'fireplace_yn', 'alias' => 'ld'],
            'GarageYN' => ['table' => 'listing_details', 'db_col' => 'garage_yn', 'alias' => 'ld'],
            'AttachedGarageYN' => ['table' => 'listing_details', 'db_col' => 'attached_garage_yn', 'alias' => 'ld'],
            'ElectricOnPropertyYN' => ['table' => 'listing_details', 'db_col' => 'electric_on_property_yn', 'alias' => 'ld'],
            'HomeWarrantyYN' => ['table' => 'listing_details', 'db_col' => 'home_warranty_yn', 'alias' => 'ld'],
            'CarportYN' => ['table' => 'listing_details', 'db_col' => 'carport_yn', 'alias' => 'ld'],
            
            // listing_financial table
            'MLSPIN_LENDER_OWNED' => ['table' => 'listing_financial', 'db_col' => 'mlspin_lender_owned', 'alias' => 'lf'],
            'MLSPIN_DPR_Flag' => ['table' => 'listing_financial', 'db_col' => 'mlspin_dpr_flag', 'alias' => 'lf'],
            'AssociationYN' => ['table' => 'listing_financial', 'db_col' => 'association_yn', 'alias' => 'lf'],
            
            // Special case for open houses
            'open_house_only' => ['special' => true]
        ];
        
        if (!isset($field_map[$field])) return 0;
        
        // Check for polygon filters in the WHERE conditions
        $has_polygon = false;
        foreach ($wheres as $where) {
            if (strpos($where, 'll.coordinates') !== false || strpos($where, 'ST_Y(ll.coordinates)') !== false || strpos($where, 'ST_X(ll.coordinates)') !== false || strpos($where, 'ST_Contains') !== false) {
                $has_polygon = true;
                break;
            }
        }
        
        // Handle special case for open houses
        if ($field === 'open_house_only') {
            $count = 0;
            foreach ($query_info['tables_to_query'] as $type) {
                $suffix = ($type === 'archive') ? '_archive' : '';
                $sql = "SELECT COUNT(DISTINCT l.id) 
                        FROM `{$bme_tables['listings' . $suffix]}` AS l 
                        INNER JOIN `{$bme_tables['open_houses']}` AS oh ON l.listing_id = oh.listing_id ";
                
                // Manually add joins for filters
                $filter_str = implode(' ', $wheres);
                // Check if we need location table (for polygon or city filters)
                if ($has_polygon || strpos($filter_str, '`ll`.') !== false) {
                    $sql .= " LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id ";
                }
                if (strpos($filter_str, '`ld`.') !== false) {
                    $sql .= " LEFT JOIN {$bme_tables['listing_details' . $suffix]} AS ld ON l.listing_id = ld.listing_id ";
                }
                if (strpos($filter_str, '`lf`.') !== false) {
                    $sql .= " LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id ";
                }
                if (strpos($filter_str, '`lfeat`.') !== false) {
                    $sql .= " LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id ";
                }
                
                $where_clause = !empty($wheres) ? " WHERE " . implode(' AND ', $wheres) : "";
                $sql .= $where_clause;
                $sql .= (!empty($where_clause) ? " AND " : " WHERE ") . "oh.expires_at > NOW()";
                
                // Debug logging for open house queries
                if (WP_DEBUG) {
                }

                $result = (int) $wpdb->get_var($sql);

                if (WP_DEBUG) {
                    // Check if there are ANY open houses (expired or not)
                    $total_oh_sql = "SELECT COUNT(*) FROM `{$bme_tables['open_houses']}`";
                    $total_oh = $wpdb->get_var($total_oh_sql);

                    // Check some sample expires_at values
                    $sample_sql = "SELECT listing_id, expires_at FROM `{$bme_tables['open_houses']}` ORDER BY expires_at DESC LIMIT 5";
                    $samples = $wpdb->get_results($sample_sql);
                    if ($samples) {
                        foreach ($samples as $sample) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("  Listing ID: {$sample->listing_id}, Expires: {$sample->expires_at}");
                            }
                        }

                        // Check current time
                        $now_sql = "SELECT NOW() as db_time";
                        $db_time = $wpdb->get_var($now_sql);

                        // Check for future open houses
                        $future_sql = "SELECT COUNT(*) FROM `{$bme_tables['open_houses']}` WHERE expires_at > NOW()";
                        $future_count = $wpdb->get_var($future_sql);
                    }
                }
                
                $count += $result;
            }
            return $count;
        }
        
        // Handle regular boolean fields
        $map = $field_map[$field];
        $table_key = $map['table'];
        $db_col = $map['db_col'];
        $alias = $map['alias'];
        
        $count = 0;
        foreach ($query_info['tables_to_query'] as $type) {
            $suffix = ($type === 'archive') ? '_archive' : '';
            $table_name = $bme_tables[$table_key . $suffix];
            
            $sql = "SELECT COUNT(DISTINCT l.id) 
                    FROM `{$bme_tables['listings' . $suffix]}` AS l 
                    LEFT JOIN `{$table_name}` AS {$alias} ON l.listing_id = {$alias}.listing_id ";
            
            // Manually add joins to avoid duplicates (we already have the main table joined)
            $filter_str = implode(' ', $wheres);
            
            // Add location table if we have polygon or city filters and it's not the current table
            if (($has_polygon || strpos($filter_str, '`ll`.') !== false) && $alias !== 'll') {
                $sql .= " LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id ";
            }
            
            // Add other tables only if needed and not already joined
            if (strpos($filter_str, '`ld`.') !== false && $alias !== 'ld') {
                $sql .= " LEFT JOIN {$bme_tables['listing_details' . $suffix]} AS ld ON l.listing_id = ld.listing_id ";
            }
            if (strpos($filter_str, '`lf`.') !== false && $alias !== 'lf') {
                $sql .= " LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id ";
            }
            if (strpos($filter_str, '`lfeat`.') !== false && $alias !== 'lfeat') {
                $sql .= " LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id ";
            }
            
            $where_clause = !empty($wheres) ? " WHERE " . implode(' AND ', $wheres) : "";
            $sql .= $where_clause;
            $sql .= (!empty($where_clause) ? " AND " : " WHERE ") . "{$alias}.`{$db_col}` = 1";
            
            // Debug log the SQL if polygon filter is present
            if (WP_DEBUG && $has_polygon) {
            }

            $result = (int) $wpdb->get_var($sql);

            // Debug log results
            if (WP_DEBUG && $has_polygon) {
            }
            
            $count += $result;
        }
        
        return $count;
    }

    public static function get_all_distinct_subtypes() {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return [];
        $sql = "(SELECT DISTINCT property_sub_type FROM {$bme_tables['listings']} WHERE property_sub_type IS NOT NULL AND property_sub_type != '')
                UNION
                (SELECT DISTINCT property_sub_type FROM {$bme_tables['listings_archive']} WHERE property_sub_type IS NOT NULL AND property_sub_type != '')
                ORDER BY property_sub_type ASC";
        return $wpdb->get_col($sql);
    }

    private static function get_minimal_joins_for_filters($filters, $suffix, $force_all = false) {
        $bme_tables = self::get_bme_tables();
        $joins = "";
        $filter_str = is_array($filters) ? implode(' ', $filters) : (string) $filters;
        
        // Check for polygon filters which require location table
        $has_polygon = false;
        if (is_array($filters)) {
            foreach ($filters as $filter) {
                if (strpos($filter, 'll.coordinates') !== false || strpos($filter, 'ST_Y(ll.coordinates)') !== false || strpos($filter, 'ST_X(ll.coordinates)') !== false || strpos($filter, 'ST_Contains') !== false) {
                    $has_polygon = true;
                    if (WP_DEBUG) {
                    }
                    break;
                }
            }
        } else {
            $has_polygon = (strpos($filter_str, 'll.coordinates') !== false || strpos($filter_str, 'ST_Y(ll.coordinates)') !== false || strpos($filter_str, 'ST_X(ll.coordinates)') !== false || strpos($filter_str, 'ST_Contains') !== false);
            if (WP_DEBUG && $has_polygon) {
            }
        }
        
        if ($force_all || strpos($filter_str, 'ld.') !== false || strpos($filter_str, '`ld`.') !== false) $joins .= " LEFT JOIN {$bme_tables['listing_details' . $suffix]} AS ld ON l.listing_id = ld.listing_id";
        if ($force_all || strpos($filter_str, 'll.') !== false || strpos($filter_str, '`ll`.') !== false || $has_polygon) $joins .= " LEFT JOIN {$bme_tables['listing_location' . $suffix]} AS ll ON l.listing_id = ll.listing_id";
        if ($force_all || strpos($filter_str, 'lf.') !== false || strpos($filter_str, '`lf`.') !== false) $joins .= " LEFT JOIN {$bme_tables['listing_financial' . $suffix]} AS lf ON l.listing_id = lf.listing_id";
        if ($force_all || strpos($filter_str, 'lfeat.') !== false || strpos($filter_str, '`lfeat`.') !== false) $joins .= " LEFT JOIN {$bme_tables['listing_features' . $suffix]} AS lfeat ON l.listing_id = lfeat.listing_id";
        return $joins;
    }

    /**
     * Normalize street name for consistent searching
     * Handles common abbreviations and variations
     */
    private static function normalize_street_name($street_name) {
        if (empty($street_name)) return '';

        // Convert to lowercase for processing
        $normalized = strtolower(trim($street_name));

        // Remove periods and extra spaces
        $normalized = preg_replace('/\./', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Common street suffix normalizations (order matters - longer forms first)
        $suffixes = [
            // Streets
            'street' => 'St',
            'str' => 'St',
            'st' => 'St',

            // Boulevards
            'boulevard' => 'Blvd',
            'blvd' => 'Blvd',
            'blv' => 'Blvd',

            // Avenues
            'avenue' => 'Ave',
            'aven' => 'Ave',
            'ave' => 'Ave',
            'av' => 'Ave',

            // Roads
            'road' => 'Rd',
            'rd' => 'Rd',

            // Drives
            'drive' => 'Dr',
            'drv' => 'Dr',
            'dr' => 'Dr',

            // Courts
            'court' => 'Ct',
            'ct' => 'Ct',

            // Places
            'place' => 'Pl',
            'pl' => 'Pl',

            // Lanes
            'lane' => 'Ln',
            'ln' => 'Ln',

            // Parkways
            'parkway' => 'Pkwy',
            'pkwy' => 'Pkwy',
            'pky' => 'Pkwy',

            // Highways
            'highway' => 'Hwy',
            'hwy' => 'Hwy',

            // Circles
            'circle' => 'Cir',
            'cir' => 'Cir',

            // Squares
            'square' => 'Sq',
            'sq' => 'Sq',

            // Terraces
            'terrace' => 'Ter',
            'ter' => 'Ter',

            // Trails
            'trail' => 'Trl',
            'trl' => 'Trl',

            // Ways
            'way' => 'Way',

            // Extensions
            'extension' => 'Ext',
            'ext' => 'Ext',
        ];

        // Direction normalizations
        $directions = [
            'north' => 'N',
            'south' => 'S',
            'east' => 'E',
            'west' => 'W',
            'northeast' => 'NE',
            'northwest' => 'NW',
            'southeast' => 'SE',
            'southwest' => 'SW',
        ];

        // Apply suffix normalizations
        foreach ($suffixes as $long => $short) {
            // Match suffix at word boundary
            $pattern = '/\b' . preg_quote($long, '/') . '\b/i';
            $normalized = preg_replace($pattern, $short, $normalized);
        }

        // Apply direction normalizations
        foreach ($directions as $long => $short) {
            $pattern = '/\b' . preg_quote($long, '/') . '\b/i';
            $normalized = preg_replace($pattern, $short, $normalized);
        }

        // Capitalize first letter of each word
        $normalized = ucwords($normalized);

        return $normalized;
    }

    /**
     * Build SQL conditions for street name variations
     */
    private static function get_street_name_variations_sql($street_name, $column = 'street_name') {
        global $wpdb;

        // Get normalized version
        $normalized = self::normalize_street_name($street_name);

        // Common variations to check
        $variations = [];

        // Add the original and normalized versions
        $variations[] = $street_name;
        $variations[] = $normalized;

        // If normalized ends with common suffix, add variations
        if (preg_match('/\b(St|Blvd|Ave|Rd|Dr|Ct|Pl|Ln|Pkwy|Hwy|Cir|Sq|Ter|Trl|Way|Ext)$/i', $normalized, $matches)) {
            $suffix = $matches[1];
            $base = trim(substr($normalized, 0, -strlen($suffix)));

            // Add common variations based on suffix
            switch (strtolower($suffix)) {
                case 'st':
                    $variations[] = $base . ' Street';
                    $variations[] = $base . ' St';
                    $variations[] = $base . ' St.';
                    break;
                case 'blvd':
                    $variations[] = $base . ' Boulevard';
                    $variations[] = $base . ' Blvd';
                    $variations[] = $base . ' Blv';
                    break;
                case 'ave':
                    $variations[] = $base . ' Avenue';
                    $variations[] = $base . ' Ave';
                    $variations[] = $base . ' Av';
                    break;
                case 'rd':
                    $variations[] = $base . ' Road';
                    $variations[] = $base . ' Rd';
                    break;
                case 'dr':
                    $variations[] = $base . ' Drive';
                    $variations[] = $base . ' Dr';
                    break;
                case 'ln':
                    $variations[] = $base . ' Lane';
                    $variations[] = $base . ' Ln';
                    break;
            }
        }

        // Remove duplicates and empty values
        $variations = array_unique(array_filter($variations));

        // Build SQL conditions
        $conditions = [];
        foreach ($variations as $variation) {
            $conditions[] = $wpdb->prepare("`{$column}` LIKE %s", '%' . $wpdb->esc_like($variation) . '%');
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    public static function get_agent_autocomplete_suggestions($term) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return [];

        $term_like = '%' . $wpdb->esc_like($term) . '%';
        $limit = 10;

        // Search for agents by name or MLS ID
        $sql = $wpdb->prepare(
            "SELECT DISTINCT
                agent_mls_id as value,
                agent_full_name as label,
                CONCAT(agent_full_name, ' (', agent_mls_id, ')') as display
            FROM {$bme_tables['agents']}
            WHERE (agent_full_name LIKE %s OR agent_mls_id LIKE %s)
                AND agent_mls_id IS NOT NULL
                AND agent_full_name IS NOT NULL
            ORDER BY agent_full_name ASC
            LIMIT %d",
            $term_like,
            $term_like,
            $limit
        );

        $results = $wpdb->get_results($sql);

        // Format results for autocomplete
        $suggestions = [];
        foreach ($results as $agent) {
            $suggestions[] = [
                'value' => $agent->value,
                'label' => $agent->display,
                'type' => 'Agent'
            ];
        }

        return $suggestions;
    }

    public static function get_autocomplete_suggestions($term) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables) return [];

        $term_like = '%' . $wpdb->esc_like($term) . '%';
        $limit = 5;
        $queries = [];

        // Check if the search term starts with a number (potential street address)
        $is_potential_address = preg_match('/^\d+\s+/i', trim($term));

        // If it looks like a street address (starts with number), add Street Address suggestions
        if ($is_potential_address) {
            // Parse street number and street name
            if (preg_match('/^(\d+)\s+(.+)$/i', trim($term), $matches)) {
                $street_number = $matches[1];
                $street_name_part = $matches[2];

                // Query for distinct street addresses (street_number + street_name combinations)
                foreach (['', '_archive'] as $suffix) {
                    $table = $bme_tables['listing_location'.$suffix];

                    // Build a simpler LIKE query for the street name part
                    $street_name_like = '%' . $wpdb->esc_like($street_name_part) . '%';

                    // Get unique street_number + street_name combinations (showing all, even single units)
                    $queries[] = $wpdb->prepare(
                        "(SELECT DISTINCT
                            CONCAT(street_number, ' ', street_name) AS value,
                            'Street Address' as type
                        FROM {$table}
                        WHERE street_number = %s
                        AND street_name LIKE %s
                        AND street_number IS NOT NULL
                        AND street_number != ''
                        AND street_name IS NOT NULL
                        AND street_name != ''
                        GROUP BY street_number, street_name
                        LIMIT %d)",
                        $street_number,
                        $street_name_like,
                        $limit
                    );
                }
            }
        }

        // Standard field searches with normalization for Street Name
        // Always include Address field for individual unit searches
        $fields = ['City' => 'listing_location', 'Postal Code' => 'listing_location', 'Street Name' => 'listing_location', 'MLS Number' => 'listings', 'Address' => 'listing_location', 'Building' => 'listing_location'];
        $db_cols = ['City' => 'city', 'Postal Code' => 'postal_code', 'Street Name' => 'street_name', 'MLS Number' => 'listing_id', 'Address' => 'unparsed_address', 'Building' => 'building_name'];

        foreach ($fields as $label => $table_key) {
            foreach (['', '_archive'] as $suffix) {
                $table = $bme_tables[$table_key.$suffix];
                $col = $db_cols[$label];

                // For Street Name field, use normalization
                if ($label === 'Street Name') {
                    $street_variations_sql = self::get_street_name_variations_sql($term, $col);
                    $queries[] = $wpdb->prepare("(SELECT DISTINCT `{$col}` AS value, '{$label}' as type FROM {$table} WHERE {$street_variations_sql} LIMIT %d)", $limit);
                } else {
                    $queries[] = $wpdb->prepare("(SELECT DISTINCT `{$col}` AS value, '{$label}' as type FROM {$table} WHERE `{$col}` LIKE %s LIMIT %d)", $term_like, $limit);
                }
            }
        }

        // Special handling for Neighborhood - combine mls_area_major, mls_area_minor, and subdivision_name
        foreach (['', '_archive'] as $suffix) {
            $table = $bme_tables['listing_location'.$suffix];
            $queries[] = $wpdb->prepare("(SELECT DISTINCT `mls_area_major` AS value, 'Neighborhood' as type FROM {$table} WHERE `mls_area_major` LIKE %s AND `mls_area_major` IS NOT NULL AND `mls_area_major` != '' LIMIT %d)", $term_like, $limit);
            $queries[] = $wpdb->prepare("(SELECT DISTINCT `mls_area_minor` AS value, 'Neighborhood' as type FROM {$table} WHERE `mls_area_minor` LIKE %s AND `mls_area_minor` IS NOT NULL AND `mls_area_minor` != '' LIMIT %d)", $term_like, $limit);
            $queries[] = $wpdb->prepare("(SELECT DISTINCT `subdivision_name` AS value, 'Neighborhood' as type FROM {$table} WHERE `subdivision_name` LIKE %s AND `subdivision_name` IS NOT NULL AND `subdivision_name` != '' LIMIT %d)", $term_like, $limit);
        }


        // Only build and execute query if we have queries to run
        if (empty($queries)) {
            return [];
        }

        $full_sql = implode(" UNION ALL ", $queries) . " LIMIT 20";
        $results = $wpdb->get_results($full_sql);

        // If query failed, return empty
        if (!$results) {
            $results = [];
        }

        // Process results to normalize and deduplicate
        $unique_results = [];
        $seen = [];
        $street_addresses = [];

        foreach ($results as $result) {
            // Normalize street names in results for consistency
            if ($result->type === 'Street Name') {
                $result->value = self::normalize_street_name($result->value);
            }

            // Handle Street Address type specially
            if ($result->type === 'Street Address') {
                // Normalize the street address
                if (preg_match('/^(\d+)\s+(.+)$/i', $result->value, $matches)) {
                    $street_num = $matches[1];
                    $street_name = self::normalize_street_name($matches[2]);
                    $result->value = $street_num . ' ' . $street_name;
                }

                // Mark as multi-unit search
                $result->value .= ' (All Units)';

                // Add to street addresses array
                $key = $result->value;
                if (!isset($street_addresses[$key])) {
                    $street_addresses[$key] = $result;
                }
            } else {
                $key = $result->type . '|' . $result->value;
                if (!isset($seen[$key])) {
                    $unique_results[] = $result;
                    $seen[$key] = true;
                }
            }
        }

        // Add Street Address results at the beginning if any
        if (!empty($street_addresses)) {
            foreach ($street_addresses as $address) {
                array_unshift($unique_results, $address);
            }
        }

        return $unique_results;
    }
    
    /**
     * Get sales history for a property by address
     * 
     * @param string $address The property address
     * @param string $current_mls_number The current MLS number to exclude
     * @return array Array of previous sales
     */
    public static function get_property_sales_history($address, $current_mls_number = '') {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables || empty($address)) {
            return array();
        }
        
        $results = array();
        
        // Get address variations for fuzzy matching
        $address_variations = MLD_Address_Utils::get_fuzzy_match_variations($address);
        
        // Check if normalized_address column exists
        $has_normalized = $wpdb->get_var("SHOW COLUMNS FROM {$bme_tables['listing_location']} LIKE 'normalized_address'") !== null;
        
        // Build WHERE clause for fuzzy address matching
        $address_conditions = array();
        $address_values = array();
        
        if ($has_normalized) {
            // If we have normalized column, use it for primary matching
            $normalized_address = MLD_Address_Utils::normalize($address);
            $address_conditions[] = 'll.normalized_address = %s';
            $address_values[] = $normalized_address;
            
            // Also check base address (without unit numbers)
            $base_address = MLD_Address_Utils::get_base_address($address);
            if ($base_address !== $normalized_address) {
                $address_conditions[] = 'll.normalized_address = %s';
                $address_values[] = $base_address;
            }
        }
        
        // Always check unparsed_address with variations
        foreach ($address_variations as $variation) {
            $address_conditions[] = 'll.unparsed_address = %s';
            $address_values[] = $variation;
        }
        
        $address_where = '(' . implode(' OR ', $address_conditions) . ')';
        
        // Search in archive tables for sold properties at the same address
        // Archive contains: Closed, Expired, Withdrawn, Pending, Canceled, Active Under Contract
        $query_values = array_merge($address_values, [$current_mls_number]);
        $sql = $wpdb->prepare("
            SELECT 
                l.listing_id,
                l.list_price,
                l.original_list_price,
                l.creation_timestamp,
                l.modification_timestamp,
                l.standard_status,
                l.close_date,
                l.close_price,
                l.mlspin_ant_sold_date,
                ll.unparsed_address
            FROM {$bme_tables['listings_archive']} l
            LEFT JOIN {$bme_tables['listing_location_archive']} ll ON l.listing_id = ll.listing_id
            WHERE {$address_where}
            AND l.listing_id != %d
            AND l.standard_status IN ('Closed', 'Expired', 'Withdrawn')
            ORDER BY COALESCE(l.close_date, l.mlspin_ant_sold_date, l.modification_timestamp) DESC
            LIMIT 10
        ", ...$query_values);
        
        $archive_results = $wpdb->get_results($sql, ARRAY_A);
        if (!empty($archive_results)) {
            $results = array_merge($results, $archive_results);
        }
        
        // Also check for any active listings at the same address (duplicate listings)
        $sql = $wpdb->prepare("
            SELECT 
                l.listing_id,
                l.list_price,
                l.original_list_price,
                l.creation_timestamp,
                l.modification_timestamp,
                l.standard_status,
                l.close_date,
                l.close_price,
                l.mlspin_ant_sold_date,
                ll.unparsed_address
            FROM {$bme_tables['listings']} l
            LEFT JOIN {$bme_tables['listing_location']} ll ON l.listing_id = ll.listing_id
            WHERE {$address_where}
            AND l.listing_id != %d
            ORDER BY l.creation_timestamp DESC
            LIMIT 5
        ", ...$query_values);
        
        $active_results = $wpdb->get_results($sql, ARRAY_A);
        if (!empty($active_results)) {
            $results = array_merge($results, $active_results);
        }
        
        // Remove duplicates based on listing_id
        $unique_results = array();
        $seen_mls = array();
        foreach ($results as $result) {
            if (!in_array($result['listing_id'], $seen_mls)) {
                $unique_results[] = $result;
                $seen_mls[] = $result['listing_id'];
            }
        }
        
        return $unique_results;
    }
    
    /**
     * Get tracked property history
     * 
     * @param string $mls_number The MLS number
     * @return array Array of history events
     */
    public static function get_tracked_property_history($mls_number) {
        global $wpdb;
        
        // Check if property history table exists
        $history_table = $wpdb->prefix . 'bme_property_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") !== $history_table) {
            return array();
        }
        
        $sql = $wpdb->prepare("
            SELECT 
                event_type,
                event_date,
                field_name,
                old_value,
                new_value,
                old_status,
                new_status,
                old_price,
                new_price,
                price_per_sqft,
                days_on_market,
                agent_name,
                office_name,
                additional_data
            FROM {$history_table}
            WHERE listing_id = %d
            ORDER BY event_date DESC
            LIMIT 50
        ", $mls_number);
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        return $results ? $results : array();
    }
    
    /**
     * Get full property history for a specific address including all transactions
     * 
     * @param string $address The property address
     * @param string $exclude_mls Optional MLS number to exclude
     * @return array Array of all historical transactions with full details
     */
    public static function get_full_property_history_by_address($address, $exclude_mls = '') {
        global $wpdb;
        
        // Check if property history table exists
        $history_table = $wpdb->prefix . 'bme_property_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") !== $history_table) {
            return array();
        }
        
        // Get address variations for fuzzy matching
        $address_variations = MLD_Address_Utils::get_fuzzy_match_variations($address);
        
        // Build WHERE clause for fuzzy address matching
        $address_conditions = array();
        $address_values = array();
        
        // Check if normalized_address column exists
        $has_normalized = $wpdb->get_var("SHOW COLUMNS FROM {$history_table} LIKE 'normalized_address'") !== null;
        
        if ($has_normalized) {
            // If we have normalized column, use it for primary matching
            $normalized_address = MLD_Address_Utils::normalize($address);
            $address_conditions[] = 'normalized_address = %s';
            $address_values[] = $normalized_address;
            
            // Also check base address (without unit numbers)
            $base_address = MLD_Address_Utils::get_base_address($address);
            if ($base_address !== $normalized_address) {
                $address_conditions[] = 'normalized_address = %s';
                $address_values[] = $base_address;
            }
        }
        
        // Always check unparsed_address with variations
        foreach ($address_variations as $variation) {
            $address_conditions[] = 'unparsed_address = %s';
            $address_values[] = $variation;
        }
        
        $address_where = '(' . implode(' OR ', $address_conditions) . ')';
        
        // Add exclusion if provided
        if (!empty($exclude_mls)) {
            $address_where .= ' AND listing_id != %d';
            $address_values[] = $exclude_mls;
        }
        
        $query_values = $address_values;
        $sql = $wpdb->prepare("
            SELECT 
                listing_id,
                event_type,
                event_date,
                field_name,
                old_value,
                new_value,
                old_status,
                new_status,
                old_price,
                new_price,
                price_per_sqft,
                days_on_market,
                agent_name,
                office_name,
                additional_data
            FROM {$history_table}
            WHERE {$address_where}
            ORDER BY listing_id, event_date ASC
        ", ...$query_values);
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        // Group by listing_id to get complete history for each transaction
        $grouped_history = array();
        if ($results) {
            foreach ($results as $event) {
                $mls = $event['listing_id'];
                if (!isset($grouped_history[$mls])) {
                    $grouped_history[$mls] = array();
                }
                $grouped_history[$mls][] = $event;
            }
        }
        
        return $grouped_history;
    }
    
    /**
     * Get current active listings at the same address
     * 
     * @param string $address The property address
     * @param string $exclude_mls Optional MLS number to exclude
     * @return array Array of current active listings
     */
    public static function get_current_listings_at_address($address, $exclude_mls = '') {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables || empty($address)) {
            return array();
        }
        
        // Get address variations for fuzzy matching
        $address_variations = MLD_Address_Utils::get_fuzzy_match_variations($address);
        
        // Check if normalized_address column exists
        $has_normalized = $wpdb->get_var("SHOW COLUMNS FROM {$bme_tables['listing_location']} LIKE 'normalized_address'") !== null;
        
        // Build WHERE clause for fuzzy address matching
        $address_conditions = array();
        $address_values = array();
        
        if ($has_normalized) {
            // If we have normalized column, use it for primary matching
            $normalized_address = MLD_Address_Utils::normalize($address);
            $address_conditions[] = 'll.normalized_address = %s';
            $address_values[] = $normalized_address;
            
            // Also check base address (without unit numbers)
            $base_address = MLD_Address_Utils::get_base_address($address);
            if ($base_address !== $normalized_address) {
                $address_conditions[] = 'll.normalized_address = %s';
                $address_values[] = $base_address;
            }
        }
        
        // Always check unparsed_address with variations
        foreach ($address_variations as $variation) {
            $address_conditions[] = 'll.unparsed_address = %s';
            $address_values[] = $variation;
        }
        
        $address_where = '(' . implode(' OR ', $address_conditions) . ')';
        
        // Search in active tables only for current listings
        $query_values = array_merge($address_values, [$exclude_mls]);
        $sql = $wpdb->prepare("
            SELECT 
                l.listing_id,
                l.list_price,
                l.original_list_price,
                l.creation_timestamp,
                l.modification_timestamp,
                l.original_entry_timestamp,
                l.standard_status,
                l.days_on_market,
                l.property_type,
                l.property_sub_type,
                l.list_agent_mls_id,
                l.list_office_mls_id,
                ll.unparsed_address,
                ld.bedrooms_total,
                ld.bathrooms_full,
                ld.bathrooms_half,
                ld.living_area,
                ld.year_built,
                a.agent_full_name,
                o.office_name
            FROM {$bme_tables['listings']} l
            LEFT JOIN {$bme_tables['listing_location']} ll ON l.listing_id = ll.listing_id
            LEFT JOIN {$bme_tables['listing_details']} ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$bme_tables['agents']} a ON l.list_agent_mls_id = a.agent_mls_id
            LEFT JOIN {$bme_tables['offices']} o ON l.list_office_mls_id = o.office_mls_id
            WHERE {$address_where}
            AND l.standard_status = 'Active'
            AND l.listing_id != %d
            ORDER BY l.creation_timestamp DESC
        ", ...$query_values);
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        return $results ? $results : array();
    }
    
    /**
     * Get nearby sold properties for comparison
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param float $radius_miles Radius in miles
     * @param string $property_type Property type to match
     * @param int $days Number of days to look back
     * @return array Array of nearby sold properties
     */
    public static function get_nearby_sold_properties($lat, $lng, $radius_miles = 0.5, $property_type = '', $days = 90) {
        global $wpdb;
        $bme_tables = self::get_bme_tables();
        if (!$bme_tables || !$lat || !$lng) return array();
        
        // Convert miles to degrees (approximate)
        $lat_range = $radius_miles / 69.0;
        $lng_range = $radius_miles / (69.0 * cos(deg2rad($lat)));
        
        $min_lat = $lat - $lat_range;
        $max_lat = $lat + $lat_range;
        $min_lng = $lng - $lng_range;
        $max_lng = $lng + $lng_range;
        
        // Use current_time() to respect WordPress timezone setting
        $date_limit = date('Y-m-d', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        
        $sql = "SELECT 
                l.listing_id,
                l.listing_key,
                l.close_price,
                l.list_price,
                l.close_date,
                ST_Y(ll.coordinates) AS latitude,
                ST_X(ll.coordinates) AS longitude,
                ll.street_number,
                ll.street_name,
                ll.city,
                ld.living_area,
                ld.bedrooms_total,
                ld.bathrooms_full,
                ld.bathrooms_half,
                l.property_sub_type,
                (
                    6371 * acos(
                        cos(radians(%f)) * cos(radians(ST_Y(ll.coordinates))) *
                        cos(radians(ST_X(ll.coordinates)) - radians(%f)) +
                        sin(radians(%f)) * sin(radians(ST_Y(ll.coordinates)))
                    )
                ) AS distance_km
            FROM {$bme_tables['listings_archive']} l
            LEFT JOIN {$bme_tables['listing_location']} ll ON l.listing_id = ll.listing_id
            LEFT JOIN {$bme_tables['listing_details']} ld ON l.listing_id = ld.listing_id
            WHERE l.standard_status = 'Closed'
                AND l.close_price > 0
                AND ST_Y(ll.coordinates) BETWEEN %f AND %f
                AND ST_X(ll.coordinates) BETWEEN %f AND %f
                AND l.close_date >= %s";
                
        if ($property_type) {
            $sql .= $wpdb->prepare(" AND (l.property_sub_type = %s OR l.property_type = %s)", $property_type, $property_type);
        }
        
        $sql .= " HAVING distance_km <= %f
                ORDER BY distance_km ASC
                LIMIT 50";
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                $sql,
                $lat, $lng, $lat,
                $min_lat, $max_lat,
                $min_lng, $max_lng,
                $date_limit,
                $radius_miles * 1.60934 // Convert miles to km
            ),
            ARRAY_A
        );
        
        return $results ? $results : array();
    }

    /**
     * Transform snake_case fields to PascalCase for frontend JavaScript
     *
     * @param array $listing Listing data with snake_case fields
     * @return array Listing data with PascalCase fields
     */
    private static function transform_to_pascalcase($listing) {
        if (!is_array($listing)) {
            return $listing;
        }

        $field_map = [
            'listing_id' => 'ListingId',
            'listing_key' => 'ListingKey',
            'list_price' => 'ListPrice',
            'original_list_price' => 'OriginalListPrice',
            'close_price' => 'ClosePrice',
            'bedrooms_total' => 'BedroomsTotal',
            'bathrooms_total_integer' => 'BathroomsTotalInteger',
            'bathrooms_full' => 'BathroomsFull',
            'bathrooms_half' => 'BathroomsHalf',
            'building_area_total' => 'LivingArea',  // Map to LivingArea for frontend
            'living_area' => 'LivingArea',
            'lot_size_acres' => 'LotSizeAcres',
            'year_built' => 'YearBuilt',
            'street_number' => 'StreetNumber',
            'street_name' => 'StreetName',
            'unit_number' => 'UnitNumber',
            'city' => 'City',
            'state_or_province' => 'StateOrProvince',
            'postal_code' => 'PostalCode',
            'county' => 'County',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'property_type' => 'PropertyType',
            'property_sub_type' => 'PropertySubType',
            'standard_status' => 'StandardStatus',
            'photo_url' => 'PhotoUrl',
            'main_photo_url' => 'PhotoUrl',
            'photo_count' => 'PhotoCount',
            'garage_spaces' => 'GarageSpaces',
            'has_pool' => 'HasPool',
            'has_fireplace' => 'HasFireplace',
            'has_basement' => 'HasBasement',
            'days_on_market' => 'DaysOnMarket',
            'virtual_tour_url' => 'VirtualTourUrl',
            'open_house_data' => 'OpenHouseData',
            'best_school_grade' => 'BestSchoolGrade',  // v6.30.2 Phase 3
            'district_grade' => 'DistrictGrade',      // v6.30.8 - District rating
            'district_percentile' => 'DistrictPercentile'  // v6.30.8 - District percentile
        ];

        $transformed = [];
        
        // Apply transformations
        foreach ($listing as $key => $value) {
            if (isset($field_map[$key])) {
                $transformed[$field_map[$key]] = $value;
            }
            // Keep original for backwards compatibility
            $transformed[$key] = $value;
        }

        return $transformed;
    }

}