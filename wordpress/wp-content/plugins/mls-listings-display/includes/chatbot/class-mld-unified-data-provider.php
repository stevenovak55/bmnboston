<?php
/**
 * Unified Data Provider
 *
 * Single interface for accessing all data types from the database.
 * Provides real-time data retrieval for the chatbot without duplication.
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Unified_Data_Provider {

    /**
     * Cache duration in seconds
     */
    const CACHE_DURATION = 3600; // 1 hour

    /**
     * Max results per query
     */
    const MAX_RESULTS = 50;

    /**
     * Data reference mapper instance
     *
     * @var MLD_Data_Reference_Mapper
     */
    private $data_mapper;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize data reference mapper
        if (class_exists('MLD_Data_Reference_Mapper')) {
            $this->data_mapper = new MLD_Data_Reference_Mapper();
        }
    }

    /**
     * Get property data based on criteria
     *
     * @param array $criteria Search criteria
     * @return array Property data
     */
    public function getPropertyData($criteria = array()) {
        global $wpdb;

        // Build base query
        $table = $wpdb->prefix . 'bme_listings';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Use summary table for performance when possible
        $use_summary = $this->should_use_summary_table($criteria);
        $from_table = $use_summary ? $summary_table : $table;

        // Start building query
        $query = "SELECT * FROM {$from_table} WHERE 1=1";
        $query_params = array();

        // Add standard status filter by default
        if (!isset($criteria['include_inactive'])) {
            $query .= " AND standard_status = 'Active'";
        }

        // Apply criteria filters
        if (!empty($criteria['listing_id'])) {
            $query .= " AND listing_id = %s";
            $query_params[] = $criteria['listing_id'];
        }

        if (!empty($criteria['city'])) {
            // Check if this is a neighborhood (v6.14.0)
            $neighborhood = $this->resolveNeighborhood($criteria['city']);
            if ($neighborhood) {
                // It's a Boston neighborhood - filter by city AND mls_area
                $query .= " AND city = %s";
                $query_params[] = $neighborhood['city'];

                // Try to filter by MLS area if the column exists
                if (!$use_summary) {
                    $query .= " AND (mls_area_major LIKE %s OR mls_area_minor LIKE %s)";
                    $mls_area_like = '%' . $wpdb->esc_like($neighborhood['mls_area']) . '%';
                    $query_params[] = $mls_area_like;
                    $query_params[] = $mls_area_like;
                }
            } else {
                // Regular city filter
                $query .= " AND city = %s";
                $query_params[] = $criteria['city'];
            }
        }

        // Direct neighborhood filter (v6.14.0)
        if (!empty($criteria['neighborhood'])) {
            $neighborhood = $this->resolveNeighborhood($criteria['neighborhood']);
            if ($neighborhood) {
                $query .= " AND city = %s";
                $query_params[] = $neighborhood['city'];

                if (!$use_summary) {
                    $query .= " AND (mls_area_major LIKE %s OR mls_area_minor LIKE %s)";
                    $mls_area_like = '%' . $wpdb->esc_like($neighborhood['mls_area']) . '%';
                    $query_params[] = $mls_area_like;
                    $query_params[] = $mls_area_like;
                }
            }
        }

        if (!empty($criteria['min_price'])) {
            $query .= " AND list_price >= %d";
            $query_params[] = intval($criteria['min_price']);
        }

        if (!empty($criteria['max_price'])) {
            $query .= " AND list_price <= %d";
            $query_params[] = intval($criteria['max_price']);
        }

        if (!empty($criteria['min_bedrooms'])) {
            $query .= " AND bedrooms_total >= %d";
            $query_params[] = intval($criteria['min_bedrooms']);
        }

        if (!empty($criteria['min_bathrooms'])) {
            $query .= " AND bathrooms_total >= %d";
            $query_params[] = intval($criteria['min_bathrooms']);
        }

        if (!empty($criteria['property_type'])) {
            // Map common user-friendly terms to database values
            $type_mapping = array(
                // User terms -> property_sub_type values
                'condo' => 'Condominium',
                'condominium' => 'Condominium',
                'condos' => 'Condominium',
                'apartment' => 'Apartment',
                'single family' => 'Single Family Residence',
                'single-family' => 'Single Family Residence',
                'house' => 'Single Family Residence',
                'townhouse' => 'Attached (Townhouse/Rowhouse/Duplex)',
                'townhome' => 'Attached (Townhouse/Rowhouse/Duplex)',
                'duplex' => 'Attached (Townhouse/Rowhouse/Duplex)',
                'multi-family' => 'Multi Family',
                'multifamily' => 'Multi Family',
                'land' => 'Land',
            );

            $search_type = strtolower(trim($criteria['property_type']));

            if (isset($type_mapping[$search_type])) {
                // Search in property_sub_type for specific types like Condo, Apartment, etc.
                $query .= " AND property_sub_type = %s";
                $query_params[] = $type_mapping[$search_type];
            } elseif (in_array($criteria['property_type'], array('Residential', 'Commercial Sale', 'Commercial Lease', 'Land', 'Residential Lease', 'Residential Income'))) {
                // Direct match for main property_type categories
                $query .= " AND property_type = %s";
                $query_params[] = $criteria['property_type'];
            } else {
                // Fallback: search both fields with LIKE for flexibility
                $query .= " AND (property_type LIKE %s OR property_sub_type LIKE %s)";
                $like_value = '%' . $wpdb->esc_like($criteria['property_type']) . '%';
                $query_params[] = $like_value;
                $query_params[] = $like_value;
            }
        }

        // Also support direct property_sub_type filter
        if (!empty($criteria['property_sub_type'])) {
            $query .= " AND property_sub_type = %s";
            $query_params[] = $criteria['property_sub_type'];
        }

        if (!empty($criteria['min_sqft'])) {
            $query .= " AND living_area >= %d";
            $query_params[] = intval($criteria['min_sqft']);
        }

        if (!empty($criteria['max_sqft'])) {
            $query .= " AND living_area <= %d";
            $query_params[] = intval($criteria['max_sqft']);
        }

        // Add geographic search if coordinates provided
        if (!empty($criteria['latitude']) && !empty($criteria['longitude']) && !empty($criteria['radius_miles'])) {
            $lat = floatval($criteria['latitude']);
            $lng = floatval($criteria['longitude']);
            $radius = floatval($criteria['radius_miles']);

            // Haversine formula for distance calculation
            $query .= " AND (
                3959 * acos(
                    cos(radians(%f)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(%f)) +
                    sin(radians(%f)) * sin(radians(latitude))
                )
            ) <= %f";
            $query_params[] = $lat;
            $query_params[] = $lng;
            $query_params[] = $lat;
            $query_params[] = $radius;
        }

        // Add sorting (v6.10.9: use columns compatible with both tables)
        if (!empty($criteria['sort_by'])) {
            // Columns available in both listings and summary tables
            $allowed_sorts = array('list_price', 'bedrooms_total', 'building_area_total');
            // Summary table uses modification_timestamp, main table uses original_entry_timestamp
            if ($criteria['sort_by'] === 'original_entry_timestamp' && $use_summary) {
                $criteria['sort_by'] = 'modification_timestamp';
            }
            $allowed_sorts[] = $use_summary ? 'modification_timestamp' : 'original_entry_timestamp';

            if (in_array($criteria['sort_by'], $allowed_sorts)) {
                $sort_order = !empty($criteria['sort_order']) && strtoupper($criteria['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
                $query .= " ORDER BY {$criteria['sort_by']} {$sort_order}";
            }
        } else {
            // Default sort by price (works for both tables)
            $query .= " ORDER BY list_price DESC";
        }

        // Add limit
        $limit = !empty($criteria['limit']) ? intval($criteria['limit']) : self::MAX_RESULTS;
        $query .= " LIMIT %d";
        $query_params[] = min($limit, self::MAX_RESULTS);

        // Execute query
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        // Enhance with additional data if requested
        if (!empty($results) && !empty($criteria['include_details'])) {
            foreach ($results as &$property) {
                $property['media'] = $this->getPropertyMedia($property['listing_id']);
                $property['details'] = $this->getPropertyDetails($property['listing_id']);
                $property['schools'] = $this->getPropertySchools($property['listing_id']);
            }
        }

        return $results ?: array();
    }

    /**
     * Get property media (photos)
     *
     * @param string $listing_id Listing ID
     * @return array Media data
     */
    public function getPropertyMedia($listing_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_media';
        $query = $wpdb->prepare(
            "SELECT media_url, media_caption, media_order, media_type
             FROM {$table}
             WHERE listing_id = %s
             ORDER BY media_order ASC",
            $listing_id
        );

        return $wpdb->get_results($query, ARRAY_A) ?: array();
    }

    /**
     * Get property details
     *
     * @param string $listing_id Listing ID
     * @return array Property details
     */
    public function getPropertyDetails($listing_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_property_details';
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing_id = %s",
            $listing_id
        );

        return $wpdb->get_row($query, ARRAY_A) ?: array();
    }

    /**
     * Get property schools
     *
     * @param string $listing_id Listing ID
     * @return array School data
     */
    public function getPropertySchools($listing_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_schools';
        $query = $wpdb->prepare(
            "SELECT school_name, school_type, school_rating, school_grades, distance_miles
             FROM {$table}
             WHERE listing_id = %s
             ORDER BY school_rating DESC",
            $listing_id
        );

        return $wpdb->get_results($query, ARRAY_A) ?: array();
    }

    /**
     * Get market analytics for an area
     *
     * @param string $area Area/city name
     * @param string $period Time period (daily, weekly, monthly, yearly)
     * @return array Market analytics
     */
    public function getMarketAnalytics($area = null, $period = 'monthly') {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_market_analytics';

        if ($area) {
            $query = $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE area = %s AND period = %s
                 ORDER BY date DESC
                 LIMIT 12",
                $area,
                $period
            );
        } else {
            // Get overall market stats
            $query = $wpdb->prepare(
                "SELECT
                    period,
                    AVG(avg_price) as avg_price,
                    AVG(median_price) as median_price,
                    SUM(total_listings) as total_listings,
                    AVG(avg_dom) as avg_dom,
                    AVG(price_per_sqft) as price_per_sqft
                 FROM {$table}
                 WHERE period = %s
                 GROUP BY date
                 ORDER BY date DESC
                 LIMIT 12",
                $period
            );
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        // If no results, calculate from listings
        if (empty($results) && $area) {
            $results = $this->calculateMarketStats($area);
        }

        return $results ?: array();
    }

    /**
     * Calculate market statistics from listings
     *
     * @param string $area Area name
     * @return array Calculated stats
     */
    private function calculateMarketStats($area) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_listings';

        // Use wp_date() for WordPress timezone consistency
        $today = wp_date('Y-m-d');
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_listings,
                AVG(list_price) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(DATEDIFF(%s, original_entry_timestamp)) as avg_dom,
                AVG(list_price / NULLIF(living_area, 0)) as price_per_sqft
             FROM {$table}
             WHERE city = %s AND standard_status = 'Active'",
            $today,
            $area
        ), ARRAY_A);

        // Calculate median
        $median = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(list_price) as median_price
             FROM (
                SELECT list_price
                FROM {$table}
                WHERE city = %s AND standard_status = 'Active'
                ORDER BY list_price
                LIMIT 2 OFFSET (
                    SELECT FLOOR(COUNT(*) / 2)
                    FROM {$table}
                    WHERE city = %s AND standard_status = 'Active'
                )
             ) as median_calc",
            $area,
            $area
        ));

        if ($stats) {
            $stats['median_price'] = $median;
            $stats['area'] = $area;
            $stats['calculated_at'] = current_time('mysql');
        }

        return array($stats);
    }

    /**
     * Get agent information
     *
     * @param mixed $agent_id Agent ID or null for all
     * @return array Agent data
     */
    public function getAgentInfo($agent_id = null) {
        global $wpdb;

        $agents_table = $wpdb->prefix . 'bme_agents';
        $offices_table = $wpdb->prefix . 'bme_offices';

        if ($agent_id) {
            $query = $wpdb->prepare(
                "SELECT a.*, o.office_name, o.office_phone, o.office_address
                 FROM {$agents_table} a
                 LEFT JOIN {$offices_table} o ON a.office_id = o.office_id
                 WHERE a.agent_id = %s",
                $agent_id
            );
            return $wpdb->get_row($query, ARRAY_A) ?: array();
        } else {
            // Get top agents by listing count
            $query = "SELECT
                        a.*,
                        o.office_name,
                        COUNT(l.listing_id) as listing_count
                      FROM {$agents_table} a
                      LEFT JOIN {$offices_table} o ON a.office_id = o.office_id
                      LEFT JOIN {$wpdb->prefix}bme_listings l ON a.agent_id = l.listing_agent_id
                      WHERE l.standard_status = 'Active'
                      GROUP BY a.agent_id
                      ORDER BY listing_count DESC
                      LIMIT 10";
            return $wpdb->get_results($query, ARRAY_A) ?: array();
        }
    }

    /**
     * Get neighborhood statistics
     *
     * @param string $neighborhood Neighborhood name
     * @return array Neighborhood data
     */
    public function getNeighborhoodStats($neighborhood) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_neighborhood_analytics';

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE neighborhood = %s",
            $neighborhood
        );

        $result = $wpdb->get_row($query, ARRAY_A);

        // If no pre-calculated stats, calculate from listings
        if (!$result) {
            $listings_table = $wpdb->prefix . 'bme_listings';
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    %s as neighborhood,
                    COUNT(*) as total_listings,
                    AVG(list_price) as avg_price,
                    MIN(list_price) as min_price,
                    MAX(list_price) as max_price,
                    AVG(bedrooms_total) as avg_bedrooms,
                    AVG(bathrooms_total) as avg_bathrooms,
                    AVG(living_area) as avg_sqft
                 FROM {$listings_table}
                 WHERE city = %s AND standard_status = 'Active'",
                $neighborhood,
                $neighborhood
            ), ARRAY_A);
        }

        return $result ?: array();
    }

    /**
     * Get user's saved searches
     *
     * @param int $user_id User ID
     * @return array Saved searches
     */
    public function getUserSavedSearches($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_saved_searches';

        $query = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_id = %d AND is_active = 1
             ORDER BY created_at DESC",
            $user_id
        );

        return $wpdb->get_results($query, ARRAY_A) ?: array();
    }

    /**
     * Get price trends for an area
     *
     * @param array $criteria Filter criteria
     * @param string $timeframe Timeframe (30d, 90d, 1y, all)
     * @return array Price trend data
     */
    public function getPriceTrends($criteria = array(), $timeframe = '90d') {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_price_history';
        $listings_table = $wpdb->prefix . 'bme_listings';

        // Calculate date range
        $date_limit = $this->calculate_date_limit($timeframe);

        $query = "SELECT
                    DATE_FORMAT(change_date, '%%Y-%%m') as month,
                    AVG(new_price) as avg_price,
                    COUNT(*) as change_count,
                    AVG((new_price - old_price) / old_price * 100) as avg_change_percent
                  FROM {$table} ph
                  JOIN {$listings_table} l ON ph.listing_id = l.listing_id
                  WHERE change_date >= %s";

        $query_params = array($date_limit);

        if (!empty($criteria['city'])) {
            $query .= " AND l.city = %s";
            $query_params[] = $criteria['city'];
        }

        if (!empty($criteria['property_type'])) {
            $query .= " AND l.property_type = %s";
            $query_params[] = $criteria['property_type'];
        }

        $query .= " GROUP BY month ORDER BY month DESC";

        $prepared_query = $wpdb->prepare($query, $query_params);
        $results = $wpdb->get_results($prepared_query, ARRAY_A);

        return $results ?: array();
    }

    /**
     * Get CMA comparables for a property
     *
     * @param array $property Subject property data
     * @param int $count Number of comps to find
     * @return array Comparable properties
     */
    public function getCMAComparables($property, $count = 6) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_listings';

        // Extract property characteristics
        $price = intval($property['list_price']);
        $beds = intval($property['bedrooms_total']);
        $baths = floatval($property['bathrooms_total']);
        $sqft = intval($property['living_area']);
        $lat = floatval($property['latitude']);
        $lng = floatval($property['longitude']);
        $type = $property['property_type'];

        // Calculate ranges (±20% for price, ±1 for beds/baths)
        $price_min = $price * 0.8;
        $price_max = $price * 1.2;
        $beds_min = max(1, $beds - 1);
        $beds_max = $beds + 1;
        $baths_min = max(1, $baths - 0.5);
        $baths_max = $baths + 0.5;
        $sqft_min = $sqft * 0.8;
        $sqft_max = $sqft * 1.2;

        $query = $wpdb->prepare(
            "SELECT *,
                ABS(list_price - %d) as price_diff,
                ABS(bedrooms_total - %d) as bed_diff,
                ABS(bathrooms_total - %f) as bath_diff,
                ABS(living_area - %d) as sqft_diff,
                (3959 * acos(
                    cos(radians(%f)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(%f)) +
                    sin(radians(%f)) * sin(radians(latitude))
                )) as distance_miles
             FROM {$table}
             WHERE listing_id != %s
             AND standard_status = 'Active'
             AND property_type = %s
             AND list_price BETWEEN %d AND %d
             AND bedrooms_total BETWEEN %d AND %d
             AND bathrooms_total BETWEEN %f AND %f
             AND living_area BETWEEN %d AND %d
             HAVING distance_miles <= 3
             ORDER BY (
                price_diff / %d * 0.4 +
                bed_diff * 0.2 +
                bath_diff * 0.1 +
                sqft_diff / %d * 0.2 +
                distance_miles * 0.1
             ) ASC
             LIMIT %d",
            $price, $beds, $baths, $sqft,
            $lat, $lng, $lat,
            $property['listing_id'],
            $type,
            $price_min, $price_max,
            $beds_min, $beds_max,
            $baths_min, $baths_max,
            $sqft_min, $sqft_max,
            $price, $sqft,
            $count
        );

        $comparables = $wpdb->get_results($query, ARRAY_A);

        // Calculate similarity scores
        foreach ($comparables as &$comp) {
            $comp['similarity_score'] = $this->calculate_similarity_score($property, $comp);
        }

        return $comparables ?: array();
    }

    /**
     * Execute a custom query template
     *
     * @param string $template_name Template name
     * @param array $parameters Query parameters
     * @return array Query results
     */
    public function executeQueryTemplate($template_name, $parameters = array()) {
        global $wpdb;

        // Get query template from patterns table
        $patterns_table = $wpdb->prefix . 'mld_chat_query_patterns';
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT query_template, required_params
             FROM {$patterns_table}
             WHERE pattern_name = %s AND is_active = 1",
            $template_name
        ), ARRAY_A);

        if (!$template) {
            return array('error' => 'Template not found');
        }

        // Build query with parameters
        $query = $template['query_template'];
        $required_params = json_decode($template['required_params'], true);

        $query_params = array();
        foreach ($required_params as $param) {
            if (!isset($parameters[$param])) {
                return array('error' => "Missing required parameter: {$param}");
            }
            $query_params[] = $parameters[$param];
        }

        // Execute query
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        // Update usage statistics
        $wpdb->query($wpdb->prepare(
            "UPDATE {$patterns_table}
             SET usage_count = usage_count + 1
             WHERE pattern_name = %s",
            $template_name
        ));

        return $results ?: array();
    }

    /**
     * Get quick statistics
     *
     * @param string $stat_type Type of statistic
     * @param array $filters Optional filters
     * @return mixed Statistic value
     */
    public function getQuickStat($stat_type, $filters = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'bme_listings';
        $where = " WHERE standard_status = 'Active'";

        // Add filters
        if (!empty($filters['city'])) {
            $where .= $wpdb->prepare(" AND city = %s", $filters['city']);
        }
        if (!empty($filters['property_type'])) {
            $where .= $wpdb->prepare(" AND property_type = %s", $filters['property_type']);
        }

        switch ($stat_type) {
            case 'total_active':
                return $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");

            case 'average_price':
                return $wpdb->get_var("SELECT AVG(list_price) FROM {$table}{$where}");

            case 'median_price':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");
                $offset = floor($count / 2);
                return $wpdb->get_var("SELECT list_price FROM {$table}{$where} ORDER BY list_price LIMIT 1 OFFSET {$offset}");

            case 'price_range':
                return $wpdb->get_row(
                    "SELECT MIN(list_price) as min_price, MAX(list_price) as max_price FROM {$table}{$where}",
                    ARRAY_A
                );

            case 'inventory_by_type':
                return $wpdb->get_results(
                    "SELECT property_type, COUNT(*) as count FROM {$table}{$where} GROUP BY property_type ORDER BY count DESC",
                    ARRAY_A
                );

            case 'inventory_by_city':
                return $wpdb->get_results(
                    "SELECT city, COUNT(*) as count FROM {$table}{$where} GROUP BY city ORDER BY count DESC LIMIT 10",
                    ARRAY_A
                );

            case 'new_listings_today':
                // Use wp_date() for WordPress timezone consistency
                $today = wp_date('Y-m-d');
                $where .= $wpdb->prepare(" AND DATE(original_entry_timestamp) = %s", $today);
                return $wpdb->get_var("SELECT COUNT(*) FROM {$table}{$where}");

            case 'price_reduced_today':
                $price_table = $wpdb->prefix . 'bme_price_history';
                // Use wp_date() for WordPress timezone consistency
                $today = wp_date('Y-m-d');
                return $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT listing_id)
                     FROM {$price_table}
                     WHERE DATE(change_date) = %s
                     AND new_price < old_price",
                    $today
                ));

            default:
                return null;
        }
    }

    /**
     * Search properties by text query
     *
     * @param string $search_text Search text
     * @param int $limit Result limit
     * @return array Search results
     */
    public function searchProperties($search_text, $limit = 20) {
        global $wpdb;

        $listings_table = $wpdb->prefix . 'bme_listings';
        $location_table = $wpdb->prefix . 'bme_listing_location';

        // v6.14.0: Normalize street suffixes for better matching
        $normalized_text = $this->normalizeStreetSuffixes($search_text);

        // Split into words and remove common filler words
        $words = preg_split('/\s+/', $normalized_text);
        $words = array_filter($words, function($word) {
            $fillers = array('in', 'at', 'on', 'the', 'a', 'an', 'is', 'are', 'still', 'available', 'for', 'sale');
            return strlen($word) > 1 && !in_array(strtolower($word), $fillers);
        });

        if (empty($words)) {
            return array();
        }

        // Build WHERE clause - all words must match somewhere
        $where_parts = array();
        $params = array();

        foreach ($words as $word) {
            $pattern = '%' . $wpdb->esc_like($word) . '%';
            $where_parts[] = "(
                l.listing_id LIKE %s
                OR loc.street_number LIKE %s
                OR loc.street_name LIKE %s
                OR loc.normalized_address LIKE %s
                OR loc.city LIKE %s
                OR l.public_remarks LIKE %s
            )";
            $params = array_merge($params, array($pattern, $pattern, $pattern, $pattern, $pattern, $pattern));
        }

        $where_clause = implode(' AND ', $where_parts);
        $params[] = $limit;

        $query = $wpdb->prepare(
            "SELECT l.*,
                    loc.street_number, loc.street_name, loc.city, loc.state_or_province,
                    loc.postal_code, loc.normalized_address,
                    CONCAT(COALESCE(loc.street_number, ''), ' ', COALESCE(loc.street_name, '')) as full_street
             FROM {$listings_table} l
             LEFT JOIN {$location_table} loc ON l.listing_id = loc.listing_id
             WHERE l.standard_status = 'Active'
             AND {$where_clause}
             ORDER BY l.list_price DESC
             LIMIT %d",
            ...$params
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Data Provider 6.14.0] searchProperties for '{$search_text}' (normalized: '{$normalized_text}'): found " . count($results ?: array()) . " results");
        }

        return $results ?: array();
    }

    /**
     * Normalize street suffixes for better search matching
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     * @since 6.14.0
     */
    private function normalizeStreetSuffixes($text) {
        $replacements = array(
            '/\bStreet\b/i' => 'St',
            '/\bAvenue\b/i' => 'Ave',
            '/\bRoad\b/i' => 'Rd',
            '/\bDrive\b/i' => 'Dr',
            '/\bLane\b/i' => 'Ln',
            '/\bBoulevard\b/i' => 'Blvd',
            '/\bPlace\b/i' => 'Pl',
            '/\bCourt\b/i' => 'Ct',
            '/\bCircle\b/i' => 'Cir',
            '/\bTerrace\b/i' => 'Ter',
            '/\bHighway\b/i' => 'Hwy',
            '/\bParkway\b/i' => 'Pkwy',
        );

        return preg_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Check if should use summary table for query
     *
     * @param array $criteria Query criteria
     * @return bool Use summary table
     */
    private function should_use_summary_table($criteria) {
        // Summary table has limited fields but is optimized for common queries
        // v6.10.9: Added property_sub_type for condo/apartment/etc searches
        $summary_fields = array(
            'listing_id', 'list_price', 'bedrooms_total', 'bathrooms_total',
            'living_area', 'building_area_total', 'city', 'state', 'postal_code',
            'property_type', 'property_sub_type', 'latitude', 'longitude',
            'standard_status', 'year_built', 'lot_size_acres'
        );

        // Filter parameters that map to summary table columns (v6.10.9)
        $summary_filter_params = array(
            'min_price', 'max_price',           // maps to list_price
            'min_bedrooms',                      // maps to bedrooms_total
            'min_bathrooms',                     // maps to bathrooms_total
            'min_sqft', 'max_sqft',             // maps to living_area
            'radius_miles',                      // maps to lat/long
        );

        // Query options that don't affect table choice
        $option_params = array(
            'limit', 'sort_by', 'sort_order', 'include_inactive', 'include_details'
        );

        // Check if all requested fields are compatible with summary table
        foreach (array_keys($criteria) as $field) {
            if (!in_array($field, $summary_fields) &&
                !in_array($field, $summary_filter_params) &&
                !in_array($field, $option_params)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate date limit for timeframe
     *
     * @param string $timeframe Timeframe string
     * @return string Date string
     */
    private function calculate_date_limit($timeframe) {
        // Use wp_date() for WordPress timezone consistency
        $now = current_time('timestamp');
        switch ($timeframe) {
            case '7d':
                return wp_date('Y-m-d', $now - (7 * DAY_IN_SECONDS));
            case '30d':
                return wp_date('Y-m-d', $now - (30 * DAY_IN_SECONDS));
            case '90d':
                return wp_date('Y-m-d', $now - (90 * DAY_IN_SECONDS));
            case '6m':
                return wp_date('Y-m-d', $now - (6 * 30 * DAY_IN_SECONDS));
            case '1y':
                return wp_date('Y-m-d', $now - (365 * DAY_IN_SECONDS));
            case 'all':
            default:
                return '2000-01-01';
        }
    }

    /**
     * Calculate similarity score between properties
     *
     * @param array $subject Subject property
     * @param array $comparable Comparable property
     * @return float Similarity score (0-100)
     */
    private function calculate_similarity_score($subject, $comparable) {
        $score = 100;

        // Price difference (40% weight)
        $price_diff_percent = abs($comparable['list_price'] - $subject['list_price']) / $subject['list_price'] * 100;
        $score -= min(40, $price_diff_percent * 2);

        // Bedroom difference (20% weight)
        $bed_diff = abs($comparable['bedrooms_total'] - $subject['bedrooms_total']);
        $score -= $bed_diff * 10;

        // Bathroom difference (10% weight)
        $bath_diff = abs($comparable['bathrooms_total'] - $subject['bathrooms_total']);
        $score -= $bath_diff * 5;

        // Square footage difference (20% weight)
        if ($subject['living_area'] > 0) {
            $sqft_diff_percent = abs($comparable['living_area'] - $subject['living_area']) / $subject['living_area'] * 100;
            $score -= min(20, $sqft_diff_percent);
        }

        // Distance (10% weight)
        if (isset($comparable['distance_miles'])) {
            $score -= min(10, $comparable['distance_miles'] * 3);
        }

        return max(0, $score);
    }

    /**
     * Cache query result
     *
     * @param string $cache_key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success
     */
    private function cache_result($cache_key, $data, $ttl = self::CACHE_DURATION) {
        return set_transient('mld_data_' . $cache_key, $data, $ttl);
    }

    /**
     * Get cached result
     *
     * @param string $cache_key Cache key
     * @return mixed Cached data or false
     */
    private function get_cached_result($cache_key) {
        return get_transient('mld_data_' . $cache_key);
    }

    /**
     * Boston neighborhood to MLS area mapping
     *
     * Used for resolving neighborhood names to proper city/area filters
     *
     * @var array
     * @since 6.14.0
     */
    private static $boston_neighborhoods = array(
        'south boston' => array('city' => 'Boston', 'mls_area' => 'South Boston'),
        'southie' => array('city' => 'Boston', 'mls_area' => 'South Boston'),
        'north end' => array('city' => 'Boston', 'mls_area' => 'North End'),
        'back bay' => array('city' => 'Boston', 'mls_area' => 'Back Bay'),
        'beacon hill' => array('city' => 'Boston', 'mls_area' => 'Beacon Hill'),
        'south end' => array('city' => 'Boston', 'mls_area' => 'South End'),
        'charlestown' => array('city' => 'Boston', 'mls_area' => 'Charlestown'),
        'east boston' => array('city' => 'Boston', 'mls_area' => 'East Boston'),
        'eastie' => array('city' => 'Boston', 'mls_area' => 'East Boston'),
        'jamaica plain' => array('city' => 'Boston', 'mls_area' => 'Jamaica Plain'),
        'jp' => array('city' => 'Boston', 'mls_area' => 'Jamaica Plain'),
        'dorchester' => array('city' => 'Boston', 'mls_area' => 'Dorchester'),
        'dot' => array('city' => 'Boston', 'mls_area' => 'Dorchester'),
        'roxbury' => array('city' => 'Boston', 'mls_area' => 'Roxbury'),
        'mission hill' => array('city' => 'Boston', 'mls_area' => 'Mission Hill'),
        'fenway' => array('city' => 'Boston', 'mls_area' => 'Fenway'),
        'allston' => array('city' => 'Boston', 'mls_area' => 'Allston'),
        'brighton' => array('city' => 'Boston', 'mls_area' => 'Brighton'),
        'west roxbury' => array('city' => 'Boston', 'mls_area' => 'West Roxbury'),
        'roslindale' => array('city' => 'Boston', 'mls_area' => 'Roslindale'),
        'hyde park' => array('city' => 'Boston', 'mls_area' => 'Hyde Park'),
        'mattapan' => array('city' => 'Boston', 'mls_area' => 'Mattapan'),
        'seaport' => array('city' => 'Boston', 'mls_area' => 'Seaport District'),
        'seaport district' => array('city' => 'Boston', 'mls_area' => 'Seaport District'),
        'bay village' => array('city' => 'Boston', 'mls_area' => 'Bay Village'),
        'chinatown' => array('city' => 'Boston', 'mls_area' => 'Chinatown'),
        'leather district' => array('city' => 'Boston', 'mls_area' => 'Leather District'),
        'financial district' => array('city' => 'Boston', 'mls_area' => 'Financial District'),
        'downtown' => array('city' => 'Boston', 'mls_area' => 'Downtown'),
        'west end' => array('city' => 'Boston', 'mls_area' => 'West End'),
        'midtown' => array('city' => 'Boston', 'mls_area' => 'Midtown'),
    );

    /**
     * Resolve neighborhood name to city and MLS area
     *
     * @param string $location Location input (could be city or neighborhood)
     * @return array|null Resolved location or null if not a known neighborhood
     * @since 6.14.0
     */
    public function resolveNeighborhood($location) {
        $normalized = strtolower(trim($location));

        if (isset(self::$boston_neighborhoods[$normalized])) {
            return self::$boston_neighborhoods[$normalized];
        }

        return null;
    }

    /**
     * Get comprehensive property data
     *
     * Loads ALL available data from all BME tables for a specific listing.
     * Used for detailed property Q&A where user asks follow-up questions.
     *
     * @param string $listing_id MLS listing ID
     * @return array|null Comprehensive property data or null if not found
     * @since 6.14.0
     */
    public function getComprehensivePropertyData($listing_id) {
        global $wpdb;

        // Get base listing data
        $listings_table = $wpdb->prefix . 'bme_listings';
        $property = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$listings_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if (!$property) {
            return null;
        }

        // Get detailed listing data
        $details_table = $wpdb->prefix . 'bme_listing_details';
        $details = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$details_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if ($details) {
            $property = array_merge($property, $details);
        }

        // Get location data
        $location_table = $wpdb->prefix . 'bme_listing_location';
        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$location_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if ($location) {
            $property = array_merge($property, $location);
        }

        // Get financial data
        $financial_table = $wpdb->prefix . 'bme_listing_financial';
        $financial = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$financial_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if ($financial) {
            $property = array_merge($property, $financial);
        }

        // Get features data
        $features_table = $wpdb->prefix . 'bme_listing_features';
        $features = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$features_table} WHERE listing_id = %s LIMIT 1",
            $listing_id
        ), ARRAY_A);

        if ($features) {
            $property = array_merge($property, $features);
        }

        // Get rooms data (1-to-many)
        $rooms_table = $wpdb->prefix . 'bme_rooms';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$rooms_table}'");
        if ($table_exists) {
            $rooms = $wpdb->get_results($wpdb->prepare(
                "SELECT room_type, room_level, room_dimensions, room_features
                 FROM {$rooms_table} WHERE listing_id = %s ORDER BY room_level, room_type",
                $listing_id
            ), ARRAY_A);
            $property['rooms'] = $rooms ?: array();
        }

        // Get photos
        $media_table = $wpdb->prefix . 'bme_media';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$media_table}'");
        if ($table_exists) {
            $photos = $wpdb->get_results($wpdb->prepare(
                "SELECT media_url, media_caption FROM {$media_table}
                 WHERE listing_id = %s ORDER BY media_order LIMIT 20",
                $listing_id
            ), ARRAY_A);
            $property['photos'] = $photos ?: array();
        }

        // Get price history
        $history_table = $wpdb->prefix . 'bme_price_history';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$history_table}'");
        if ($table_exists) {
            $price_history = $wpdb->get_results($wpdb->prepare(
                "SELECT change_date as event_date, old_price, new_price, change_type as event_type
                 FROM {$history_table}
                 WHERE listing_id = %s
                 ORDER BY change_date DESC LIMIT 10",
                $listing_id
            ), ARRAY_A);
            $property['price_history'] = $price_history ?: array();
        }

        // Get open houses
        $open_houses_table = $wpdb->prefix . 'bme_open_houses';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$open_houses_table}'");
        if ($table_exists) {
            $open_houses = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$open_houses_table} WHERE listing_id = %s",
                $listing_id
            ), ARRAY_A);
            $property['open_houses'] = $open_houses ?: array();
        }

        // Get agent info
        $agents_table = $wpdb->prefix . 'bme_agents';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$agents_table}'");
        if ($table_exists && !empty($property['list_agent_mls_id'])) {
            $agent = $wpdb->get_row($wpdb->prepare(
                "SELECT agent_full_name, agent_email, agent_phone, agent_office_name
                 FROM {$agents_table} WHERE agent_mls_id = %s",
                $property['list_agent_mls_id']
            ), ARRAY_A);
            if ($agent) {
                $property['agent'] = $agent;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Unified Data Provider 6.14.0] Loaded comprehensive data for ' . $listing_id . ' with ' . count($property) . ' fields');
        }

        return $property;
    }
}