<?php
/**
 * BME Data Provider Implementation
 *
 * Concrete implementation of the MLD_Data_Provider_Interface
 * that works with the Bridge MLS Extractor Pro plugin
 *
 * @package MLS_Listings_Display
 * @since 3.2.0
 * @updated 5.2.0 - Added summary table support for 8.5x performance improvement
 */

if (!defined('ABSPATH')) {
    exit;
}

// Require the interface file if not already loaded
if (!interface_exists('MLD_Data_Provider_Interface')) {
    require_once dirname(__FILE__) . '/interface-mld-data-provider.php';
}

/**
 * BME Data Provider class
 */
class MLD_BME_Data_Provider implements MLD_Data_Provider_Interface {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Database manager instance
     */
    private $db_manager = null;

    /**
     * Cache manager instance
     */
    private $cache_manager = null;

    /**
     * Summary table availability flag
     * @since 5.2.0
     */
    private $has_summary_table = false;

    /**
     * Summary table name
     * @since 5.2.0
     */
    private $summary_table = '';

    /**
     * Archive summary table availability flag
     * @since 6.27.24
     */
    private $has_archive_summary_table = false;

    /**
     * Archive summary table name
     * @since 6.27.24
     */
    private $archive_summary_table = '';

    /**
     * Constructor
     */
    public function __construct() {
        // Only initialize if BME plugin is available
        if ($this->is_available()) {
            $this->db_manager = bme_pro()->get('db');
            $this->cache_manager = bme_pro()->get('cache');

            // Check for summary table support
            $this->detect_summary_table();
            $this->detect_archive_summary_table();
        }
    }

    /**
     * Detect if summary table exists and is usable
     * @since 5.2.0
     */
    private function detect_summary_table() {
        global $wpdb;

        $this->summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->summary_table
            )
        );

        if ($table_exists === $this->summary_table) {
            // Check if table has data
            $has_data = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->summary_table} LIMIT 1"
            );

            $this->has_summary_table = $has_data > 0;

            if ($this->has_summary_table) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD] Summary table detected with ' . $has_data . ' listings - enabling optimized queries');
                }
            }
        }
    }

    /**
     * Detect if archive summary table exists and is usable
     * @since 6.27.24
     */
    private function detect_archive_summary_table() {
        global $wpdb;

        $this->archive_summary_table = $wpdb->prefix . 'bme_listing_summary_archive';

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->archive_summary_table
            )
        );

        if ($table_exists === $this->archive_summary_table) {
            // Check if table has data
            $has_data = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->archive_summary_table} LIMIT 1"
            );

            $this->has_archive_summary_table = $has_data > 0;

            if ($this->has_archive_summary_table) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD] Archive summary table detected with ' . $has_data . ' listings - enabling optimized archive queries');
                }
            }
        }
    }

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
     * Get database tables array
     */
    public function get_tables() {
        if (!$this->is_available()) {
            return [];
        }

        return $this->db_manager->get_tables();
    }

    /**
     * Get listings using optimized summary table when available
     * Falls back to traditional JOINs if summary not available
     *
     * @since 5.2.0 - Added summary table optimization
     */
    public function get_listings($filters = [], $limit = 20, $offset = 0) {
        // Check status to determine if we need archive tables
        $need_archive = false;
        if (!empty($filters['status'])) {
            $archive_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled', 'Sold'];
            if (is_array($filters['status'])) {
                $need_archive = !empty(array_intersect($filters['status'], $archive_statuses));
            } else {
                $need_archive = in_array($filters['status'], $archive_statuses);
            }
        }

        // Use archive method if needed
        if ($need_archive) {
            return $this->get_archive_listings($filters, $limit, $offset);
        }

        // Try optimized method first if summary table available
        if ($this->has_summary_table && $this->can_use_summary_for_filters($filters)) {
            $optimized = $this->get_listings_optimized($filters, $limit, $offset);
            if ($optimized !== false) {
                return $optimized;
            }
        }

        // Fallback to traditional method
        return $this->get_listings_traditional($filters, $limit, $offset);
    }

    /**
     * Get listings using optimized summary table
     * Provides 8.5x faster performance for common queries
     *
     * @param array $filters Search filters
     * @param int $limit Results limit
     * @param int $offset Results offset
     * @return array|false Listing results or false if cannot use summary
     * @since 5.2.0
     */
    public function get_listings_optimized($filters = [], $limit = 20, $offset = 0) {
        if (!$this->is_available() || !$this->has_summary_table) {
            return false;
        }

        // Check if we can use summary table for these filters
        if (!$this->can_use_summary_for_filters($filters)) {
            return false;
        }

        // Filters that require direct summary table query (BME's method doesn't handle these properly)
        // These filters are handled correctly by build_summary_where_clauses() but not by BME's search_listings_optimized
        // BME's method doesn't properly handle array values for location filters
        $filters_requiring_direct_query = [
            'city',                 // BME returns 0 when city is array
            'postal_code', 'home_type', 'structure_type', 'architectural_style',
            'street_name', 'listing_id', 'neighborhood', 'lot_size_min', 'lot_size_max',
            'year_built_min', 'year_built_max', 'garage_spaces_min',
            'waterfront', 'view', 'spa', 'has_hoa', 'senior_community', 'horse_property',
            'open_house_only', 'has_basement'  // has_basement needs JSON check in listing_details
        ];

        $use_direct_query = false;
        foreach ($filters_requiring_direct_query as $filter_key) {
            if (!empty($filters[$filter_key])) {
                $use_direct_query = true;
                break;
            }
        }

        // Only use BME's method if we don't have filters that require direct query
        if (!$use_direct_query && method_exists($this->db_manager, 'search_listings_optimized')) {
            $params = $this->convert_filters_to_bme_format($filters);
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $results = $this->db_manager->search_listings_optimized($params);

            if ($results !== false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD] Used BME optimized search - returned ' . count($results) . ' listings');
                }

                // Normalize field names for frontend compatibility
                $results = $this->normalize_listings_array($results);
                return $results;
            }
        }

        // Query summary table directly (handles all filters correctly)
        global $wpdb;

        // Build optimized query
        $query = $this->build_summary_query($filters, $limit, $offset);

        // Check cache first
        $cache_key = 'listings_summary_' . md5(serialize($filters) . $limit . $offset);
        $cached = $this->cache_manager ? $this->cache_manager->get($cache_key) : false;

        if ($cached !== false) {
            return $cached;
        }

        // Execute query
        $results = $wpdb->get_results($query, ARRAY_A);

        // Cache results
        if ($this->cache_manager && $results) {
            $this->cache_manager->set($cache_key, $results, 1800); // 30 min cache
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD] Used summary table query - returned ' . count($results) . ' listings');
        }

        
        // Normalize field names for frontend compatibility
        $results = $this->normalize_listings_array($results);
        return $results ?: [];
    }

    /**
     * Traditional get_listings method using JOINs
     * Used when summary table not available or filters require full data
     *
     * @since 5.2.0 - Renamed from get_listings
     */
    private function get_listings_traditional($filters = [], $limit = 20, $offset = 0) {
        if (!$this->is_available()) {
            return [];
        }

        global $wpdb;
        $tables = $this->get_tables();

        // Build query based on filters
        $query = $this->build_listing_query($filters, $limit, $offset);

        // Check cache first
        $cache_key = 'listings_' . md5(serialize($filters) . $limit . $offset);
        $cached = $this->cache_manager ? $this->cache_manager->get($cache_key) : false;

        if ($cached !== false) {
            return $cached;
        }

        // Execute query
        $results = $wpdb->get_results($query, ARRAY_A);

        // Cache results
        if ($this->cache_manager && $results) {
            $this->cache_manager->set($cache_key, $results, 3600);
        }

        // Normalize field names for frontend compatibility
        $results = $this->normalize_listings_array($results ?: []);
        return $results;
    }

    /**
     * Check if filters can use summary table
     * Summary table only contains active listings and common fields
     *
     * @param array $filters
     * @return bool
     * @since 5.2.0
     */
    private function can_use_summary_for_filters($filters) {
        // Summary table only has active-type listings
        if (isset($filters['status'])) {
            $allowed_statuses = ['Active', 'Active Under Contract', 'Pending'];

            if (is_array($filters['status'])) {
                foreach ($filters['status'] as $status) {
                    if (!in_array($status, $allowed_statuses)) {
                        return false;
                    }
                }
            } else {
                if (!in_array($filters['status'], $allowed_statuses)) {
                    return false;
                }
            }
        }

        // Check if filters require fields not in summary
        // Note: Agent filters require full listings table as summary doesn't have agent columns
        // Neighborhood requires mls_area_major, mls_area_minor, subdivision_name from location table
        // Boolean amenity filters not in summary require listing_features table
        $summary_unsupported = [
            'rooms',
            'full_address_details',
            'agent_details',
            'office_details',
            'listing_agent_id',     // Not in summary table
            'buyer_agent_id',       // Not in summary table
            'agent_ids',            // Generic agent filter - not in summary table
            'neighborhood',         // Requires mls_area fields from location table
            'structure_type',       // Requires listing_details table
            'architectural_style',  // Requires listing_details table
            // Boolean filters not in summary table:
            'waterfront',           // Requires listing_features.waterfront_yn
            'view',                 // Requires listing_features.view_yn
            'spa',                  // Requires listing_features.spa_yn
            'senior_community',     // Requires listing_features.senior_community_yn
            'horse_property'        // Requires listing_features.horse_yn
        ];
        foreach ($summary_unsupported as $field) {
            if (isset($filters[$field]) && !empty($filters[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert MLD filters to BME optimized search format
     *
     * @param array $filters MLD format filters
     * @return array BME format parameters
     * @since 5.2.0
     */
    private function convert_filters_to_bme_format($filters) {
        $params = [];

        // Map MLD filters to BME parameters
        // Location filters
        if (isset($filters['city'])) $params['city'] = $filters['city'];
        if (isset($filters['postal_code'])) $params['postal_code'] = $filters['postal_code'];
        if (isset($filters['street_name'])) $params['street_name'] = $filters['street_name'];
        if (isset($filters['listing_id'])) $params['listing_id'] = $filters['listing_id'];
        if (isset($filters['neighborhood'])) $params['neighborhood'] = $filters['neighborhood'];

        // Price and size filters
        if (isset($filters['min_price'])) $params['min_price'] = $filters['min_price'];
        if (isset($filters['max_price'])) $params['max_price'] = $filters['max_price'];
        if (isset($filters['min_beds'])) $params['bedrooms'] = $filters['min_beds'];
        if (isset($filters['min_baths'])) $params['bathrooms'] = $filters['min_baths'];
        if (isset($filters['min_sqft'])) $params['min_sqft'] = $filters['min_sqft'];
        if (isset($filters['max_sqft'])) $params['max_sqft'] = $filters['max_sqft'];
        if (isset($filters['lot_size_min'])) $params['lot_size_min'] = $filters['lot_size_min'];
        if (isset($filters['lot_size_max'])) $params['lot_size_max'] = $filters['lot_size_max'];
        if (isset($filters['year_built_min'])) $params['year_built_min'] = $filters['year_built_min'];
        if (isset($filters['year_built_max'])) $params['year_built_max'] = $filters['year_built_max'];
        if (isset($filters['garage_spaces_min'])) $params['garage_spaces_min'] = $filters['garage_spaces_min'];

        // Property type filters
        if (isset($filters['property_type'])) $params['property_type'] = $filters['property_type'];
        if (isset($filters['home_type'])) $params['home_type'] = $filters['home_type'];
        if (isset($filters['structure_type'])) $params['structure_type'] = $filters['structure_type'];
        if (isset($filters['architectural_style'])) $params['architectural_style'] = $filters['architectural_style'];

        // Status filter
        if (isset($filters['status'])) {
            $params['status'] = is_array($filters['status']) ? $filters['status'][0] : $filters['status'];
        }

        // Features/amenities - original filters
        if (isset($filters['has_pool'])) $params['has_pool'] = (bool)$filters['has_pool'];
        if (isset($filters['has_fireplace'])) $params['has_fireplace'] = (bool)$filters['has_fireplace'];
        if (isset($filters['has_basement'])) $params['has_basement'] = (bool)$filters['has_basement'];
        if (isset($filters['pet_friendly'])) $params['pet_friendly'] = (bool)$filters['pet_friendly'];

        // Features/amenities - new filters for full parity with Half Map Search
        if (isset($filters['waterfront'])) $params['waterfront'] = (bool)$filters['waterfront'];
        if (isset($filters['view'])) $params['view'] = (bool)$filters['view'];
        if (isset($filters['spa'])) $params['spa'] = (bool)$filters['spa'];
        if (isset($filters['has_hoa'])) $params['has_hoa'] = (bool)$filters['has_hoa'];
        if (isset($filters['senior_community'])) $params['senior_community'] = (bool)$filters['senior_community'];
        if (isset($filters['horse_property'])) $params['horse_property'] = (bool)$filters['horse_property'];
        if (isset($filters['open_house_only'])) $params['open_house_only'] = (bool)$filters['open_house_only'];

        // Sorting
        if (isset($filters['orderby'])) $params['orderby'] = $filters['orderby'];
        if (isset($filters['order'])) $params['order'] = $filters['order'];

        return $params;
    }

    /**
     * Build query for summary table
     *
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return string SQL query
     * @since 5.2.0
     */
    private function build_summary_query($filters, $limit, $offset) {
        global $wpdb;

        $query = "SELECT * FROM {$this->summary_table} WHERE 1=1";

        // Add WHERE clauses
        $where_clauses = $this->build_summary_where_clauses($filters);
        if ($where_clauses) {
            $query .= " AND " . implode(' AND ', $where_clauses);
        }

        // Add ORDER BY
        $orderby = isset($filters['orderby']) ? $filters['orderby'] : 'modification_timestamp';
        $order = isset($filters['order']) ? strtoupper($filters['order']) : 'DESC';

        // Map common orderby fields
        $orderby_map = [
            'price' => 'list_price',
            'date' => 'modification_timestamp',
            'beds' => 'bedrooms_total',
            'baths' => 'bathrooms_total',
            'sqft' => 'building_area_total'
        ];

        if (isset($orderby_map[$orderby])) {
            $orderby = $orderby_map[$orderby];
        }

        $query .= " ORDER BY {$orderby} {$order}";

        // Add LIMIT and OFFSET
        if ($limit > 0) {
            $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        return $query;
    }

    /**
     * Build WHERE clauses for summary table query
     *
     * @param array $filters
     * @return array WHERE clauses
     * @since 5.2.0
     */
    private function build_summary_where_clauses($filters) {
        global $wpdb;
        $where_clauses = [];

        // Status filter (summary only has active-type listings)
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '%s'));
                $where_clauses[] = $wpdb->prepare("standard_status IN ($placeholders)", $filters['status']);
            } else {
                $where_clauses[] = $wpdb->prepare("standard_status = %s", $filters['status']);
            }
        } else {
            // Default to active listings
            $where_clauses[] = "standard_status IN ('Active', 'Active Under Contract', 'Pending')";
        }

        // Property type
        if (!empty($filters['property_type'])) {
            $where_clauses[] = $wpdb->prepare("property_type = %s", $filters['property_type']);
        }

        // Price range
        if (!empty($filters['min_price'])) {
            $where_clauses[] = $wpdb->prepare("list_price >= %d", $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $where_clauses[] = $wpdb->prepare("list_price <= %d", $filters['max_price']);
        }

        // Bedrooms
        if (!empty($filters['min_beds'])) {
            $where_clauses[] = $wpdb->prepare("bedrooms_total >= %d", $filters['min_beds']);
        }

        // Bathrooms
        if (!empty($filters['min_baths'])) {
            $where_clauses[] = $wpdb->prepare("bathrooms_total >= %d", $filters['min_baths']);
        }

        // Square footage
        if (!empty($filters['min_sqft'])) {
            $where_clauses[] = $wpdb->prepare("building_area_total >= %d", $filters['min_sqft']);
        }
        if (!empty($filters['max_sqft'])) {
            $where_clauses[] = $wpdb->prepare("building_area_total <= %d", $filters['max_sqft']);
        }

        // City - supports both single value and arrays (comma-separated from shortcode)
        if (!empty($filters['city'])) {
            if (is_array($filters['city'])) {
                $placeholders = implode(',', array_fill(0, count($filters['city']), '%s'));
                $where_clauses[] = $wpdb->prepare("city IN ($placeholders)", $filters['city']);
            } else {
                $where_clauses[] = $wpdb->prepare("city = %s", $filters['city']);
            }
        }

        // State
        if (!empty($filters['state'])) {
            $where_clauses[] = $wpdb->prepare("state_or_province = %s", $filters['state']);
        }

        // Postal code - supports both single value and arrays
        if (!empty($filters['postal_code'])) {
            if (is_array($filters['postal_code'])) {
                $placeholders = implode(',', array_fill(0, count($filters['postal_code']), '%s'));
                $where_clauses[] = $wpdb->prepare("postal_code IN ($placeholders)", $filters['postal_code']);
            } else {
                $where_clauses[] = $wpdb->prepare("postal_code = %s", $filters['postal_code']);
            }
        }

        // Listing ID / MLS Number - supports both single value and arrays
        if (!empty($filters['listing_id'])) {
            if (is_array($filters['listing_id'])) {
                $placeholders = implode(',', array_fill(0, count($filters['listing_id']), '%s'));
                $where_clauses[] = $wpdb->prepare("listing_id IN ($placeholders)", $filters['listing_id']);
            } else {
                $where_clauses[] = $wpdb->prepare("listing_id = %s", $filters['listing_id']);
            }
        }

        // Street name - supports both single value and arrays with LIKE for flexibility
        if (!empty($filters['street_name'])) {
            if (is_array($filters['street_name'])) {
                $street_conditions = [];
                foreach ($filters['street_name'] as $street) {
                    $street_conditions[] = $wpdb->prepare("street_name LIKE %s", '%' . $wpdb->esc_like($street) . '%');
                }
                if (!empty($street_conditions)) {
                    $where_clauses[] = '(' . implode(' OR ', $street_conditions) . ')';
                }
            } else {
                $where_clauses[] = $wpdb->prepare("street_name LIKE %s", '%' . $wpdb->esc_like($filters['street_name']) . '%');
            }
        }

        // Home type / Property sub type - supports arrays
        if (!empty($filters['home_type'])) {
            if (is_array($filters['home_type'])) {
                $placeholders = implode(',', array_fill(0, count($filters['home_type']), '%s'));
                $where_clauses[] = $wpdb->prepare("property_sub_type IN ($placeholders)", $filters['home_type']);
            } else {
                $where_clauses[] = $wpdb->prepare("property_sub_type = %s", $filters['home_type']);
            }
        }

        // Year built range
        if (!empty($filters['year_built_min'])) {
            $where_clauses[] = $wpdb->prepare("year_built >= %d", (int)$filters['year_built_min']);
        }
        if (!empty($filters['year_built_max'])) {
            $where_clauses[] = $wpdb->prepare("year_built <= %d", (int)$filters['year_built_max']);
        }

        // Lot size range (in acres)
        if (!empty($filters['lot_size_min'])) {
            $where_clauses[] = $wpdb->prepare("lot_size_acres >= %f", (float)$filters['lot_size_min']);
        }
        if (!empty($filters['lot_size_max'])) {
            $where_clauses[] = $wpdb->prepare("lot_size_acres <= %f", (float)$filters['lot_size_max']);
        }

        // Features/Amenities (available in summary table)
        // Accept both boolean true and string 'yes' for filter values
        // This ensures compatibility with both shortcode handler and JS preview
        if (isset($filters['has_pool']) && ($filters['has_pool'] === 'yes' || $filters['has_pool'] === true)) {
            $where_clauses[] = "has_pool = 1";
        }
        if (isset($filters['has_fireplace']) && ($filters['has_fireplace'] === 'yes' || $filters['has_fireplace'] === true)) {
            $where_clauses[] = "has_fireplace = 1";
        }
        if (isset($filters['has_basement']) && ($filters['has_basement'] === 'yes' || $filters['has_basement'] === true)) {
            // Summary table has_basement is unreliable - check actual JSON data in listing_details
            // Exclude: empty strings, '[]' (empty array), '["N"]' (no basement marker)
            $details_table = $wpdb->prefix . 'bme_listing_details';
            $where_clauses[] = "listing_id IN (SELECT listing_id FROM {$details_table} WHERE basement IS NOT NULL AND basement != '' AND basement != '[]' AND basement NOT LIKE '%\"N\"%')";
        }
        if (isset($filters['pet_friendly']) && ($filters['pet_friendly'] === 'yes' || $filters['pet_friendly'] === true)) {
            $where_clauses[] = "pet_friendly = 1";
        }
        if (isset($filters['has_hoa']) && ($filters['has_hoa'] === 'yes' || $filters['has_hoa'] === true)) {
            $where_clauses[] = "has_hoa = 1";
        }

        // Note: Additional boolean filters (waterfront, view, spa, senior_community, horse_property)
        // are NOT in the summary table, so queries with these filters will fall back to traditional method
        // via can_use_summary_for_filters() check

        // Garage spaces (support both min_garage and garage_spaces_min)
        $garage_min = !empty($filters['min_garage']) ? $filters['min_garage'] : (!empty($filters['garage_spaces_min']) ? $filters['garage_spaces_min'] : 0);
        if ($garage_min > 0) {
            $where_clauses[] = $wpdb->prepare("garage_spaces >= %d", (int)$garage_min);
        }

        // Open house only filter
        if (isset($filters['open_house_only']) && ($filters['open_house_only'] === 'yes' || $filters['open_house_only'] === true)) {
            $open_house_table = $wpdb->prefix . 'bme_open_houses';
            $where_clauses[] = "listing_id IN (SELECT listing_id FROM {$open_house_table} WHERE expires_at > NOW())";
        }

        // Geographic bounds (for map searches)
        if (!empty($filters['lat_north']) && !empty($filters['lat_south'])) {
            $where_clauses[] = $wpdb->prepare(
                "latitude BETWEEN %f AND %f",
                $filters['lat_south'],
                $filters['lat_north']
            );
        }
        if (!empty($filters['lng_west']) && !empty($filters['lng_east'])) {
            $where_clauses[] = $wpdb->prepare(
                "longitude BETWEEN %f AND %f",
                $filters['lng_west'],
                $filters['lng_east']
            );
        }

        // Keyword search - limited to summary fields
        if (!empty($filters['keyword'])) {
            $keyword = '%' . $wpdb->esc_like($filters['keyword']) . '%';
            $where_clauses[] = $wpdb->prepare(
                "(city LIKE %s OR property_type LIKE %s OR property_sub_type LIKE %s)",
                $keyword, $keyword, $keyword
            );
        }

        return $where_clauses;
    }

    /**
     * Get a single listing by ID
     */
    public function get_listing($listing_id) {
        if (!$this->is_available()) {
            return null;
        }

        global $wpdb;
        $tables = $this->get_tables();

        // Check cache first
        $cache_key = 'listing_' . $listing_id;
        $cached = $this->cache_manager ? $this->cache_manager->get($cache_key) : false;

        if ($cached !== false) {
            return $cached;
        }

        // Query for listing - needs full JOINs for complete data
        $query = $wpdb->prepare(
            "SELECT l.*, ll.*, ld.*, lf.*, lfi.*
            FROM {$tables['listings']} l
            LEFT JOIN {$tables['listing_location']} ll ON l.listing_id = ll.listing_id
            LEFT JOIN {$tables['listing_details']} ld ON l.listing_id = ld.listing_id
            LEFT JOIN {$tables['listing_features']} lf ON l.listing_id = lf.listing_id
            LEFT JOIN {$tables['listing_financial']} lfi ON l.listing_id = lfi.listing_id
            WHERE l.listing_id = %s
            LIMIT 1",
            $listing_id
        );

        $result = $wpdb->get_row($query, ARRAY_A);

        // If not found in active, check archive
        if (!$result) {
            $query = $wpdb->prepare(
                "SELECT l.*, ll.*, ld.*, lf.*, lfi.*
                FROM {$tables['listings_archive']} l
                LEFT JOIN {$tables['listing_location_archive']} ll ON l.listing_id = ll.listing_id
                LEFT JOIN {$tables['listing_details_archive']} ld ON l.listing_id = ld.listing_id
                LEFT JOIN {$tables['listing_features_archive']} lf ON l.listing_id = lf.listing_id
                LEFT JOIN {$tables['listing_financial_archive']} lfi ON l.listing_id = lfi.listing_id
                WHERE l.listing_id = %s
                LIMIT 1",
                $listing_id
            );

            $result = $wpdb->get_row($query, ARRAY_A);
        }

        // Cache result
        if ($this->cache_manager && $result) {
            $this->cache_manager->set($cache_key, $result, 3600);
        }

        return $result;
    }

    /**
     * Get listing count based on filters
     */
    public function get_listing_count($filters = []) {
        if (!$this->is_available()) {
            return 0;
        }

        global $wpdb;

        // Check if we need archive tables (same logic as get_listings)
        $need_archive = false;
        if (!empty($filters['status'])) {
            $archive_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled', 'Sold'];
            if (is_array($filters['status'])) {
                $need_archive = !empty(array_intersect($filters['status'], $archive_statuses));
            } else {
                $need_archive = in_array($filters['status'], $archive_statuses);
            }
        }

        // Use archive count if needed
        if ($need_archive) {
            return $this->get_archive_listing_count($filters);
        }

        // Try summary table first for active listings
        if ($this->has_summary_table && $this->can_use_summary_for_filters($filters)) {
            $query = "SELECT COUNT(*) FROM {$this->summary_table} WHERE 1=1";

            $where_clauses = $this->build_summary_where_clauses($filters);
            if ($where_clauses) {
                $query .= " AND " . implode(' AND ', $where_clauses);
            }

            $count = (int)$wpdb->get_var($query);
            if ($count > 0) {
                return $count;
            }
        }

        // Fallback to traditional count
        $tables = $this->get_tables();

        // Build count query
        $query = $this->build_count_query($filters);

        // Check cache
        $cache_key = 'listing_count_' . md5(serialize($filters));
        $cached = $this->cache_manager ? $this->cache_manager->get($cache_key) : false;

        if ($cached !== false) {
            return (int)$cached;
        }

        $count = (int)$wpdb->get_var($query);

        // Cache result
        if ($this->cache_manager) {
            $this->cache_manager->set($cache_key, $count, 3600);
        }

        return $count;
    }

    /**
     * Get listing count from archive tables
     *
     * @param array $filters Search filters
     * @return int Count of matching archived listings
     * @since 6.11.25
     */
    private function get_archive_listing_count($filters = []) {
        global $wpdb;
        $tables = $this->get_tables();

        $query = "SELECT COUNT(DISTINCT l.id)
                 FROM {$tables['listings_archive']} l
                 LEFT JOIN {$tables['listing_location_archive']} ll ON l.listing_id = ll.listing_id
                 LEFT JOIN {$tables['listing_details_archive']} ld ON l.listing_id = ld.listing_id
                 LEFT JOIN {$tables['listing_financial_archive']} lfi ON l.listing_id = lfi.listing_id
                 LEFT JOIN {$tables['listing_features_archive']} lf ON l.listing_id = lf.listing_id
                 WHERE 1=1";

        // Add filter conditions (uses archive-specific where clauses)
        $where_clauses = $this->build_archive_where_clauses($filters);
        if ($where_clauses) {
            $query .= " AND " . implode(' AND ', $where_clauses);
        }

        return (int)$wpdb->get_var($query);
    }

    /**
     * Get distinct values for a field
     */
    public function get_distinct_values($field, $filters = []) {
        if (!$this->is_available()) {
            return [];
        }

        global $wpdb;

        // Check if field exists in summary table
        $summary_fields = ['city', 'state_or_province', 'postal_code', 'property_type',
                          'property_sub_type', 'standard_status', 'county'];

        if ($this->has_summary_table && in_array($field, $summary_fields)) {
            $query = "SELECT DISTINCT `{$field}` FROM {$this->summary_table}
                     WHERE `{$field}` IS NOT NULL AND `{$field}` != ''
                     ORDER BY `{$field}` ASC";

            $results = $wpdb->get_col($query);
            if (!empty($results)) {
                return $results;
            }
        }

        // Fallback to traditional method
        $tables = $this->get_tables();

        // Determine which table the field belongs to
        $table = $this->get_table_for_field($field);
        if (!$table) {
            return [];
        }

        // Build query
        $query = "SELECT DISTINCT `{$field}` FROM {$table} WHERE `{$field}` IS NOT NULL AND `{$field}` != ''";

        // Add filters if provided
        if (!empty($filters)) {
            $where_clauses = $this->build_where_clauses($filters);
            if ($where_clauses) {
                $query .= " AND " . implode(' AND ', $where_clauses);
            }
        }

        $query .= " ORDER BY `{$field}` ASC";

        $results = $wpdb->get_col($query);

        // Fields stored as JSON arrays need special handling
        $json_array_fields = ['structure_type', 'architectural_style'];
        if (in_array($field, $json_array_fields) && !empty($results)) {
            $results = $this->parse_json_array_values($results);
        }

        return $results ?: [];
    }

    /**
     * Parse JSON array values and extract unique individual values
     *
     * Data like ["Colonial"], ["Colonial","Victorian"], ["Ranch"] becomes
     * ['Colonial', 'Ranch', 'Victorian']
     *
     * @param array $raw_values Raw JSON array strings from database
     * @return array Unique individual values sorted alphabetically
     * @since 6.11.37
     */
    private function parse_json_array_values($raw_values) {
        $unique_values = [];

        foreach ($raw_values as $json_string) {
            // Skip empty values
            if (empty($json_string) || $json_string === '[]') {
                continue;
            }

            // Try to decode as JSON
            $decoded = json_decode($json_string, true);

            if (is_array($decoded)) {
                // It's a valid JSON array
                foreach ($decoded as $value) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $unique_values[$value] = true;
                    }
                }
            } else {
                // Not JSON, use raw value (shouldn't happen but handle it)
                $value = trim($json_string);
                if (!empty($value)) {
                    $unique_values[$value] = true;
                }
            }
        }

        // Get unique keys, sort alphabetically
        $result = array_keys($unique_values);
        sort($result, SORT_STRING | SORT_FLAG_CASE);

        return $result;
    }

    /**
     * Search listings
     */
    public function search_listings($keyword, $filters = [], $limit = 20) {
        if (!$this->is_available()) {
            return [];
        }

        // Add keyword to filters
        $filters['keyword'] = $keyword;

        return $this->get_listings($filters, $limit, 0);
    }

    /**
     * Get listing media
     */
    public function get_listing_media($listing_id) {
        if (!$this->is_available()) {
            return [];
        }

        global $wpdb;
        $tables = $this->get_tables();

        $query = $wpdb->prepare(
            "SELECT * FROM {$tables['media']}
            WHERE listing_id = %s
            ORDER BY media_order ASC",
            $listing_id
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }

    /**
     * Check if summary table exists and has data
     *
     * @return bool
     * @since 5.2.0
     */
    public function has_summary_table() {
        return $this->has_summary_table;
    }

    /**
     * Get summary table statistics
     *
     * @return array Statistics about summary table
     * @since 5.2.0
     */
    public function get_summary_stats() {
        if (!$this->has_summary_table) {
            return ['available' => false];
        }

        global $wpdb;

        $stats = [
            'available' => true,
            'table_name' => $this->summary_table,
            'total_listings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->summary_table}"),
            'last_updated' => $wpdb->get_var("SELECT MAX(modification_timestamp) FROM {$this->summary_table}"),
            'cities' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT city) FROM {$this->summary_table}"),
            'property_types' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT property_type) FROM {$this->summary_table}")
        ];

        return $stats;
    }

    /**
     * Check if provider is available
     */
    public function is_available() {
        return function_exists('bme_pro') &&
               is_callable([bme_pro(), 'get']) &&
               bme_pro()->get('db') !== null;
    }

    /**
     * Get provider version
     */
    public function get_version() {
        if (!$this->is_available()) {
            return '0.0.0';
        }

        return defined('BME_PRO_VERSION') ? BME_PRO_VERSION : '0.0.0';
    }

    /**
     * Build listing query based on filters (traditional method)
     */
    private function build_listing_query($filters, $limit, $offset) {
        global $wpdb;
        $tables = $this->get_tables();

        $query = "SELECT l.*, ll.city, ll.state_or_province, ll.postal_code,
                        ll.latitude, ll.longitude, ll.unparsed_address,
                        ll.street_number, ll.street_name, ll.unit_number,
                        ld.bedrooms_total, ld.bathrooms_total_integer, ld.bathrooms_full,
                        ld.bathrooms_half, ld.building_area_total, ld.lot_size_acres,
                        ld.year_built, ld.garage_spaces,
                        lfi.association_fee, lfi.association_fee_frequency,
                        lfi.tax_annual_amount, lfi.tax_assessed_value,
                        lf.waterfront_yn, lf.pool_private_yn, lf.view_yn,
                        m.media_url as main_photo_url,
                        (SELECT COUNT(*) FROM {$tables['media']} WHERE listing_id = l.listing_id) as photo_count
                 FROM {$tables['listings']} l
                 LEFT JOIN {$tables['listing_location']} ll ON l.listing_id = ll.listing_id
                 LEFT JOIN {$tables['listing_details']} ld ON l.listing_id = ld.listing_id
                 LEFT JOIN {$tables['listing_financial']} lfi ON l.listing_id = lfi.listing_id
                 LEFT JOIN {$tables['listing_features']} lf ON l.listing_id = lf.listing_id
                 LEFT JOIN {$tables['media']} m ON l.listing_id = m.listing_id AND m.order_index = 1
                 WHERE 1=1";

        // Add filter conditions
        $where_clauses = $this->build_where_clauses($filters);
        if ($where_clauses) {
            $query .= " AND " . implode(' AND ', $where_clauses);
        }

        // Add order by
        $query .= " ORDER BY l.modification_timestamp DESC";

        // Add limit and offset
        if ($limit > 0) {
            $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        return $query;
    }

    /**
     * Build count query based on filters
     */
    private function build_count_query($filters) {
        global $wpdb;
        $tables = $this->get_tables();

        $query = "SELECT COUNT(DISTINCT l.id)
                 FROM {$tables['listings']} l
                 LEFT JOIN {$tables['listing_location']} ll ON l.listing_id = ll.listing_id
                 LEFT JOIN {$tables['listing_details']} ld ON l.listing_id = ld.listing_id
                 LEFT JOIN {$tables['listing_financial']} lfi ON l.listing_id = lfi.listing_id
                 LEFT JOIN {$tables['listing_features']} lf ON l.listing_id = lf.listing_id
                 WHERE 1=1";

        // Add filter conditions
        $where_clauses = $this->build_where_clauses($filters);
        if ($where_clauses) {
            $query .= " AND " . implode(' AND ', $where_clauses);
        }

        return $query;
    }

    /**
     * Build WHERE clauses from filters (traditional method)
     */
    private function build_where_clauses($filters) {
        global $wpdb;
        $where_clauses = [];

        // Status filter
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '%s'));
                $where_clauses[] = $wpdb->prepare("l.standard_status IN ($placeholders)", $filters['status']);
            } else {
                $where_clauses[] = $wpdb->prepare("l.standard_status = %s", $filters['status']);
            }
        } else {
            // Default to active listings
            $where_clauses[] = "l.standard_status IN ('Active', 'Pending')";
        }

        // Property type filter
        if (!empty($filters['property_type'])) {
            $where_clauses[] = $wpdb->prepare("l.property_type = %s", $filters['property_type']);
        }

        // Price range
        if (!empty($filters['min_price'])) {
            $where_clauses[] = $wpdb->prepare("l.list_price >= %d", $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $where_clauses[] = $wpdb->prepare("l.list_price <= %d", $filters['max_price']);
        }

        // Bedrooms
        if (!empty($filters['min_beds'])) {
            $where_clauses[] = $wpdb->prepare("ld.bedrooms_total >= %d", $filters['min_beds']);
        }

        // Bathrooms
        if (!empty($filters['min_baths'])) {
            $where_clauses[] = $wpdb->prepare("ld.bathrooms_total_integer >= %d", $filters['min_baths']);
        }

        // City - supports both single value and arrays
        if (!empty($filters['city'])) {
            if (is_array($filters['city'])) {
                $placeholders = implode(',', array_fill(0, count($filters['city']), '%s'));
                $where_clauses[] = $wpdb->prepare("ll.city IN ($placeholders)", $filters['city']);
            } else {
                $where_clauses[] = $wpdb->prepare("ll.city = %s", $filters['city']);
            }
        }

        // Postal code - supports both single value and arrays
        if (!empty($filters['postal_code'])) {
            if (is_array($filters['postal_code'])) {
                $placeholders = implode(',', array_fill(0, count($filters['postal_code']), '%s'));
                $where_clauses[] = $wpdb->prepare("ll.postal_code IN ($placeholders)", $filters['postal_code']);
            } else {
                $where_clauses[] = $wpdb->prepare("ll.postal_code = %s", $filters['postal_code']);
            }
        }

        // Listing ID / MLS Number - supports both single value and arrays
        if (!empty($filters['listing_id'])) {
            if (is_array($filters['listing_id'])) {
                $placeholders = implode(',', array_fill(0, count($filters['listing_id']), '%s'));
                $where_clauses[] = $wpdb->prepare("l.listing_id IN ($placeholders)", $filters['listing_id']);
            } else {
                $where_clauses[] = $wpdb->prepare("l.listing_id = %s", $filters['listing_id']);
            }
        }

        // Street name - supports both single value and arrays with LIKE for flexibility
        if (!empty($filters['street_name'])) {
            if (is_array($filters['street_name'])) {
                $street_conditions = [];
                foreach ($filters['street_name'] as $street) {
                    $street_conditions[] = $wpdb->prepare("ll.street_name LIKE %s", '%' . $wpdb->esc_like($street) . '%');
                }
                if (!empty($street_conditions)) {
                    $where_clauses[] = '(' . implode(' OR ', $street_conditions) . ')';
                }
            } else {
                $where_clauses[] = $wpdb->prepare("ll.street_name LIKE %s", '%' . $wpdb->esc_like($filters['street_name']) . '%');
            }
        }

        // Neighborhood - requires mls_area fields from location table
        if (!empty($filters['neighborhood'])) {
            if (is_array($filters['neighborhood'])) {
                $neighborhood_conditions = [];
                foreach ($filters['neighborhood'] as $neighborhood) {
                    $like_pattern = '%' . $wpdb->esc_like($neighborhood) . '%';
                    $neighborhood_conditions[] = $wpdb->prepare(
                        "(ll.mls_area_major LIKE %s OR ll.mls_area_minor LIKE %s OR ll.subdivision_name LIKE %s)",
                        $like_pattern, $like_pattern, $like_pattern
                    );
                }
                if (!empty($neighborhood_conditions)) {
                    $where_clauses[] = '(' . implode(' OR ', $neighborhood_conditions) . ')';
                }
            } else {
                $like_pattern = '%' . $wpdb->esc_like($filters['neighborhood']) . '%';
                $where_clauses[] = $wpdb->prepare(
                    "(ll.mls_area_major LIKE %s OR ll.mls_area_minor LIKE %s OR ll.subdivision_name LIKE %s)",
                    $like_pattern, $like_pattern, $like_pattern
                );
            }
        }

        // Home type / Property sub type - supports arrays
        if (!empty($filters['home_type'])) {
            if (is_array($filters['home_type'])) {
                $placeholders = implode(',', array_fill(0, count($filters['home_type']), '%s'));
                $where_clauses[] = $wpdb->prepare("l.property_sub_type IN ($placeholders)", $filters['home_type']);
            } else {
                $where_clauses[] = $wpdb->prepare("l.property_sub_type = %s", $filters['home_type']);
            }
        }

        // Structure type (stored as JSON array in database, use LIKE for matching)
        if (!empty($filters['structure_type'])) {
            if (is_array($filters['structure_type'])) {
                $struct_conditions = [];
                foreach ($filters['structure_type'] as $type) {
                    $struct_conditions[] = $wpdb->prepare("ld.structure_type LIKE %s", '%' . $wpdb->esc_like($type) . '%');
                }
                if (!empty($struct_conditions)) {
                    $where_clauses[] = '(' . implode(' OR ', $struct_conditions) . ')';
                }
            } else {
                $where_clauses[] = $wpdb->prepare("ld.structure_type LIKE %s", '%' . $wpdb->esc_like($filters['structure_type']) . '%');
            }
        }

        // Architectural style
        if (!empty($filters['architectural_style'])) {
            if (is_array($filters['architectural_style'])) {
                $style_conditions = [];
                foreach ($filters['architectural_style'] as $style) {
                    $style_conditions[] = $wpdb->prepare("ld.architectural_style LIKE %s", '%' . $wpdb->esc_like($style) . '%');
                }
                if (!empty($style_conditions)) {
                    $where_clauses[] = '(' . implode(' OR ', $style_conditions) . ')';
                }
            } else {
                $where_clauses[] = $wpdb->prepare("ld.architectural_style LIKE %s", '%' . $wpdb->esc_like($filters['architectural_style']) . '%');
            }
        }

        // Square footage
        if (!empty($filters['min_sqft'])) {
            $where_clauses[] = $wpdb->prepare("ld.building_area_total >= %d", $filters['min_sqft']);
        }
        if (!empty($filters['max_sqft'])) {
            $where_clauses[] = $wpdb->prepare("ld.building_area_total <= %d", $filters['max_sqft']);
        }

        // Year built range
        if (!empty($filters['year_built_min'])) {
            $where_clauses[] = $wpdb->prepare("ld.year_built >= %d", (int)$filters['year_built_min']);
        }
        if (!empty($filters['year_built_max'])) {
            $where_clauses[] = $wpdb->prepare("ld.year_built <= %d", (int)$filters['year_built_max']);
        }

        // Lot size range (in acres)
        if (!empty($filters['lot_size_min'])) {
            $where_clauses[] = $wpdb->prepare("ld.lot_size_acres >= %f", (float)$filters['lot_size_min']);
        }
        if (!empty($filters['lot_size_max'])) {
            $where_clauses[] = $wpdb->prepare("ld.lot_size_acres <= %f", (float)$filters['lot_size_max']);
        }

        // Garage spaces (support both min_garage and garage_spaces_min)
        $garage_min = !empty($filters['min_garage']) ? $filters['min_garage'] : (!empty($filters['garage_spaces_min']) ? $filters['garage_spaces_min'] : 0);
        if ($garage_min > 0) {
            $where_clauses[] = $wpdb->prepare("ld.garage_spaces >= %d", (int)$garage_min);
        }

        // Features/Amenities
        // Accept both boolean true and string 'yes' for filter values
        // Database columns are tinyint(1), so compare to integer 1
        // IMPORTANT: fireplace_yn is in listing_details (ld), not listing_features (lf)
        // basement is a TEXT field in listing_details, not a boolean
        if (isset($filters['waterfront']) && ($filters['waterfront'] === 'yes' || $filters['waterfront'] === true)) {
            $where_clauses[] = "lf.waterfront_yn = 1";
        }
        if ((isset($filters['pool']) && ($filters['pool'] === 'yes' || $filters['pool'] === true)) ||
            (isset($filters['has_pool']) && ($filters['has_pool'] === 'yes' || $filters['has_pool'] === true))) {
            $where_clauses[] = "lf.pool_private_yn = 1";
        }
        if (isset($filters['has_fireplace']) && ($filters['has_fireplace'] === 'yes' || $filters['has_fireplace'] === true)) {
            $where_clauses[] = "ld.fireplace_yn = 1";  // Fixed: was lf, should be ld
        }
        if (isset($filters['has_basement']) && ($filters['has_basement'] === 'yes' || $filters['has_basement'] === true)) {
            // basement is a TEXT field in listing_details, check if not empty
            $where_clauses[] = "(ld.basement IS NOT NULL AND ld.basement != '')";
        }
        if (isset($filters['pet_friendly']) && ($filters['pet_friendly'] === 'yes' || $filters['pet_friendly'] === true)) {
            $where_clauses[] = "lf.pets_allowed = 1";
        }

        // New amenity filters for full parity with Half Map Search
        if (isset($filters['view']) && ($filters['view'] === 'yes' || $filters['view'] === true)) {
            $where_clauses[] = "lf.view_yn = 1";
        }
        if (isset($filters['spa']) && ($filters['spa'] === 'yes' || $filters['spa'] === true)) {
            $where_clauses[] = "lf.spa_yn = 1";
        }
        if (isset($filters['senior_community']) && ($filters['senior_community'] === 'yes' || $filters['senior_community'] === true)) {
            $where_clauses[] = "lf.senior_community_yn = 1";
        }
        if (isset($filters['horse_property']) && ($filters['horse_property'] === 'yes' || $filters['horse_property'] === true)) {
            $where_clauses[] = "lf.horse_yn = 1";
        }
        if (isset($filters['has_hoa']) && ($filters['has_hoa'] === 'yes' || $filters['has_hoa'] === true)) {
            $where_clauses[] = "lfi.association_yn = 1";
        }

        // Open house only filter
        if (isset($filters['open_house_only']) && ($filters['open_house_only'] === 'yes' || $filters['open_house_only'] === true)) {
            $open_house_table = $wpdb->prefix . 'bme_open_houses';
            $where_clauses[] = "l.listing_id IN (SELECT listing_id FROM {$open_house_table} WHERE expires_at > NOW())";
        }

        // Keyword search
        if (!empty($filters['keyword'])) {
            $keyword = '%' . $wpdb->esc_like($filters['keyword']) . '%';
            $where_clauses[] = $wpdb->prepare(
                "(l.public_remarks LIKE %s OR ll.unparsed_address LIKE %s OR ll.city LIKE %s)",
                $keyword, $keyword, $keyword
            );
        }

        // Generic agent filter (agent_ids) - matches listing agent, buyer agent, or team member with OR logic
        // This is the same behavior as the half-map search page
        if (!empty($filters['agent_ids'])) {
            $agent_ids = is_array($filters['agent_ids']) ? $filters['agent_ids'] : [$filters['agent_ids']];
            if (!empty($agent_ids)) {
                $agent_conditions = [];
                $placeholders = implode(', ', array_fill(0, count($agent_ids), '%s'));

                // Check list_agent_mls_id
                $agent_conditions[] = $wpdb->prepare("l.list_agent_mls_id IN ({$placeholders})", ...$agent_ids);
                // Check buyer_agent_mls_id
                $agent_conditions[] = $wpdb->prepare("l.buyer_agent_mls_id IN ({$placeholders})", ...$agent_ids);

                // OR logic - listing can have agent in any of the fields
                $where_clauses[] = '(' . implode(' OR ', $agent_conditions) . ')';
            }
        }

        // Specific agent filters - listing agent (seller's agent)
        if (!empty($filters['listing_agent_id'])) {
            $where_clauses[] = $wpdb->prepare("l.list_agent_mls_id = %s", $filters['listing_agent_id']);
        }

        // Specific agent filters - buyer's agent
        if (!empty($filters['buyer_agent_id'])) {
            $where_clauses[] = $wpdb->prepare("l.buyer_agent_mls_id = %s", $filters['buyer_agent_id']);
        }

        return $where_clauses;
    }

    /**
     * Get the table name for a given field
     */
    private function get_table_for_field($field) {
        $tables = $this->get_tables();

        // Map fields to tables
        $field_map = [
            'city' => $tables['listing_location'],
            'state_or_province' => $tables['listing_location'],
            'postal_code' => $tables['listing_location'],
            'property_type' => $tables['listings'],
            'property_sub_type' => $tables['listings'],
            'standard_status' => $tables['listings'],
            'bedrooms_total' => $tables['listing_details'],
            'bathrooms_total_integer' => $tables['listing_details'],
            'building_area_total' => $tables['listing_details'],
            'list_price' => $tables['listings'],
            'structure_type' => $tables['listing_details'],
            'architectural_style' => $tables['listing_details']
        ];

        return isset($field_map[$field]) ? $field_map[$field] : null;
    }

    /**
     * Get archive listings using optimized summary table
     * Provides ~25x faster performance by avoiding 5-table JOINs
     *
     * @param array $filters Search filters
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array|false Listing results or false if cannot use summary table
     * @since 6.27.24
     */
    private function get_archive_listings_optimized($filters = [], $limit = 20, $offset = 0) {
        if (!$this->has_archive_summary_table) {
            return false;
        }

        global $wpdb;

        // Build WHERE clauses using the existing method (works with summary table columns)
        $where_clauses = $this->build_archive_summary_where_clauses($filters);
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Determine order by
        $orderby = isset($filters['orderby']) ? $filters['orderby'] : 'close_date';
        $order = isset($filters['order']) ? strtoupper($filters['order']) : 'DESC';

        // Map order fields to summary table columns
        $orderby_map = [
            'l.close_date' => 'close_date',
            'l.list_price' => 'list_price',
            'l.modification_timestamp' => 'modification_timestamp',
            'close_date' => 'close_date',
            'list_price' => 'list_price',
        ];
        $orderby = isset($orderby_map[$orderby]) ? $orderby_map[$orderby] : 'close_date';

        // Build optimized query using archive summary table (NO JOINs!)
        $query = "SELECT
                    listing_id,
                    listing_key,
                    mls_id,
                    property_type,
                    property_sub_type,
                    standard_status,
                    list_price,
                    original_list_price,
                    close_price,
                    bedrooms_total,
                    bathrooms_total,
                    bathrooms_full,
                    bathrooms_half,
                    building_area_total,
                    lot_size_acres,
                    year_built,
                    street_number,
                    street_name,
                    city,
                    state_or_province,
                    postal_code,
                    county,
                    latitude,
                    longitude,
                    garage_spaces,
                    main_photo_url,
                    photo_count,
                    listing_contract_date,
                    close_date,
                    days_on_market,
                    modification_timestamp,
                    subdivision_name,
                    unparsed_address
                 FROM {$this->archive_summary_table}
                 {$where_sql}
                 ORDER BY {$orderby} {$order}";

        if ($limit > 0) {
            $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        // Execute query
        $results = $wpdb->get_results($query, ARRAY_A);

        if ($results === null) {
            // Query failed, fall back to traditional method
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD] Archive summary query failed: ' . $wpdb->last_error);
            }
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD] Archive summary query returned ' . count($results) . ' listings');
        }

        // Normalize field names for frontend compatibility
        $results = $this->normalize_listings_array($results);
        return $results ?: [];
    }

    /**
     * Build WHERE clauses for archive summary table queries
     *
     * @param array $filters
     * @return array WHERE clauses
     * @since 6.27.24
     */
    private function build_archive_summary_where_clauses($filters) {
        global $wpdb;
        $where_clauses = [];

        // Status filter - map user-friendly names to database values
        $status_map = ['Sold' => 'Closed'];

        if (!empty($filters['status'])) {
            $raw_statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $mapped_statuses = [];
            foreach ($raw_statuses as $status) {
                $mapped = isset($status_map[$status]) ? $status_map[$status] : $status;
                $mapped_statuses[] = $wpdb->prepare('%s', $mapped);
            }
            if (!empty($mapped_statuses)) {
                $where_clauses[] = 'standard_status IN (' . implode(',', $mapped_statuses) . ')';
            }
        }

        // City filter
        if (!empty($filters['city'])) {
            $cities = is_array($filters['city']) ? $filters['city'] : [$filters['city']];
            $city_placeholders = [];
            foreach ($cities as $city) {
                $city_placeholders[] = $wpdb->prepare('%s', $city);
            }
            $where_clauses[] = 'city IN (' . implode(',', $city_placeholders) . ')';
        }

        // Postal code filter
        if (!empty($filters['postal_code'])) {
            $zips = is_array($filters['postal_code']) ? $filters['postal_code'] : [$filters['postal_code']];
            $zip_placeholders = [];
            foreach ($zips as $zip) {
                $zip_placeholders[] = $wpdb->prepare('%s', $zip);
            }
            $where_clauses[] = 'postal_code IN (' . implode(',', $zip_placeholders) . ')';
        }

        // Property type filter
        if (!empty($filters['property_type'])) {
            $where_clauses[] = $wpdb->prepare('property_type = %s', $filters['property_type']);
        }

        // Price filters (use close_price for Closed status, list_price otherwise)
        if (!empty($filters['min_price'])) {
            $where_clauses[] = $wpdb->prepare('COALESCE(close_price, list_price) >= %d', $filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $where_clauses[] = $wpdb->prepare('COALESCE(close_price, list_price) <= %d', $filters['max_price']);
        }

        // Bedroom filter
        if (!empty($filters['beds']) || !empty($filters['bedrooms_min'])) {
            $beds = !empty($filters['beds']) ? $filters['beds'] : $filters['bedrooms_min'];
            $where_clauses[] = $wpdb->prepare('bedrooms_total >= %d', $beds);
        }

        // Bathroom filter
        if (!empty($filters['baths']) || !empty($filters['bathrooms_min'])) {
            $baths = !empty($filters['baths']) ? $filters['baths'] : $filters['bathrooms_min'];
            $where_clauses[] = $wpdb->prepare('bathrooms_total >= %f', $baths);
        }

        // Square footage filters
        if (!empty($filters['sqft_min'])) {
            $where_clauses[] = $wpdb->prepare('building_area_total >= %d', $filters['sqft_min']);
        }
        if (!empty($filters['sqft_max'])) {
            $where_clauses[] = $wpdb->prepare('building_area_total <= %d', $filters['sqft_max']);
        }

        // Year built filters
        if (!empty($filters['year_built_min'])) {
            $where_clauses[] = $wpdb->prepare('year_built >= %d', $filters['year_built_min']);
        }
        if (!empty($filters['year_built_max'])) {
            $where_clauses[] = $wpdb->prepare('year_built <= %d', $filters['year_built_max']);
        }

        // Listing ID / MLS number filter
        if (!empty($filters['listing_id'])) {
            $where_clauses[] = $wpdb->prepare('listing_id = %s', $filters['listing_id']);
        }

        // Map bounds filter
        if (!empty($filters['bounds'])) {
            $coords = explode(',', $filters['bounds']);
            if (count($coords) === 4) {
                $south = floatval($coords[0]);
                $west = floatval($coords[1]);
                $north = floatval($coords[2]);
                $east = floatval($coords[3]);
                $where_clauses[] = $wpdb->prepare(
                    'latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f',
                    $south, $north, $west, $east
                );
            }
        }

        return $where_clauses;
    }

    /**
     * Get listings from archive tables with proper JOINs
     * Used for sold, expired, withdrawn, and canceled listings
     * Falls back to this when summary table is unavailable or query needs full data
     *
     * @param array $filters Search filters
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Archive listings
     * @since 5.2.0
     */
    public function get_archive_listings($filters = [], $limit = 20, $offset = 0) {
        if (!$this->is_available()) {
            return [];
        }

        // Try optimized method first if archive summary table available
        // This provides ~25x faster performance for list queries
        if ($this->has_archive_summary_table) {
            $optimized = $this->get_archive_listings_optimized($filters, $limit, $offset);
            if ($optimized !== false) {
                return $optimized;
            }
        }

        // Fallback to traditional method with JOINs
        global $wpdb;
        $tables = $this->get_tables();

        // Build query with all necessary JOINs for archive tables
        $query = "SELECT l.*,
                        ll.city, ll.state_or_province, ll.postal_code,
                        ll.latitude, ll.longitude, ll.unparsed_address,
                        ll.street_number, ll.street_name, ll.unit_number,
                        ld.bedrooms_total, ld.bathrooms_total_integer, ld.bathrooms_full,
                        ld.bathrooms_half, ld.building_area_total, ld.lot_size_acres,
                        ld.year_built, ld.garage_spaces,
                        lfi.association_fee, lfi.association_fee_frequency,
                        lfi.tax_annual_amount, lfi.tax_assessed_value,
                        lf.waterfront_yn, lf.pool_private_yn, lf.view_yn,
                        m.media_url as main_photo_url,
                        (SELECT COUNT(*) FROM {$tables['media']} WHERE listing_id = l.listing_id) as photo_count
                 FROM {$tables['listings_archive']} l
                 LEFT JOIN {$tables['listing_location_archive']} ll ON l.listing_id = ll.listing_id
                 LEFT JOIN {$tables['listing_details_archive']} ld ON l.listing_id = ld.listing_id
                 LEFT JOIN {$tables['listing_financial_archive']} lfi ON l.listing_id = lfi.listing_id
                 LEFT JOIN {$tables['listing_features_archive']} lf ON l.listing_id = lf.listing_id
                 LEFT JOIN (
                    SELECT listing_id, MIN(media_url) as media_url
                    FROM {$tables['media']}
                    WHERE media_category = 'Photo'
                    GROUP BY listing_id
                 ) m ON l.listing_id = m.listing_id
                 WHERE 1=1";

        // Add filter conditions
        $where_clauses = $this->build_archive_where_clauses($filters);
        if ($where_clauses) {
            $query .= " AND " . implode(' AND ', $where_clauses);
        }

        // Add order by
        $orderby = isset($filters['orderby']) ? $filters['orderby'] : 'l.close_date';
        $order = isset($filters['order']) ? $filters['order'] : 'DESC';
        $query .= " ORDER BY {$orderby} {$order}";

        // Add limit and offset
        if ($limit > 0) {
            $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }

        // Execute query
        $results = $wpdb->get_results($query, ARRAY_A);

        
        // Normalize field names for frontend compatibility
        $results = $this->normalize_listings_array($results);
        return $results ?: [];
    }

    /**
     * Build WHERE clauses for archive queries
     *
     * @param array $filters
     * @return array WHERE clauses
     * @since 5.2.0
     */
    private function build_archive_where_clauses($filters) {
        global $wpdb;
        $where_clauses = [];

        // Status filter for archive (Sold, Expired, Withdrawn, Canceled)
        // Map user-friendly status names to database values
        $status_map = [
            'Sold' => 'Closed',  // User says "Sold", database has "Closed"
        ];

        if (!empty($filters['status'])) {
            // Map status values
            $mapped_statuses = [];
            $raw_statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            foreach ($raw_statuses as $status) {
                $mapped_statuses[] = isset($status_map[$status]) ? $status_map[$status] : $status;
            }

            $placeholders = implode(',', array_fill(0, count($mapped_statuses), '%s'));
            $where_clauses[] = $wpdb->prepare("l.standard_status IN ($placeholders)", $mapped_statuses);
        } else {
            // Default to common archive statuses
            $where_clauses[] = "l.standard_status IN ('Closed', 'Expired', 'Withdrawn', 'Canceled')";
        }

        // Property type
        if (!empty($filters['property_type'])) {
            $where_clauses[] = $wpdb->prepare("l.property_type = %s", $filters['property_type']);
        }

        // Price range (use close_price for sold properties if available)
        if (!empty($filters['min_price']) || !empty($filters['max_price'])) {
            $price_field = "COALESCE(l.close_price, l.list_price)";

            if (!empty($filters['min_price'])) {
                $where_clauses[] = $wpdb->prepare("{$price_field} >= %d", $filters['min_price']);
            }
            if (!empty($filters['max_price'])) {
                $where_clauses[] = $wpdb->prepare("{$price_field} <= %d", $filters['max_price']);
            }
        }

        // Date range for closed/sold properties
        if (!empty($filters['close_date_min'])) {
            $where_clauses[] = $wpdb->prepare("l.close_date >= %s", $filters['close_date_min']);
        }
        if (!empty($filters['close_date_max'])) {
            $where_clauses[] = $wpdb->prepare("l.close_date <= %s", $filters['close_date_max']);
        }

        // City - supports arrays
        if (!empty($filters['city'])) {
            if (is_array($filters['city'])) {
                $placeholders = implode(',', array_fill(0, count($filters['city']), '%s'));
                $where_clauses[] = $wpdb->prepare("ll.city IN ($placeholders)", $filters['city']);
            } else {
                $where_clauses[] = $wpdb->prepare("ll.city = %s", $filters['city']);
            }
        }

        // Postal code - supports arrays
        if (!empty($filters['postal_code'])) {
            if (is_array($filters['postal_code'])) {
                $placeholders = implode(',', array_fill(0, count($filters['postal_code']), '%s'));
                $where_clauses[] = $wpdb->prepare("ll.postal_code IN ($placeholders)", $filters['postal_code']);
            } else {
                $where_clauses[] = $wpdb->prepare("ll.postal_code = %s", $filters['postal_code']);
            }
        }

        // Listing ID - supports arrays
        if (!empty($filters['listing_id'])) {
            if (is_array($filters['listing_id'])) {
                $placeholders = implode(',', array_fill(0, count($filters['listing_id']), '%s'));
                $where_clauses[] = $wpdb->prepare("l.listing_id IN ($placeholders)", $filters['listing_id']);
            } else {
                $where_clauses[] = $wpdb->prepare("l.listing_id = %s", $filters['listing_id']);
            }
        }

        // Bedrooms
        if (!empty($filters['min_beds'])) {
            $where_clauses[] = $wpdb->prepare("ld.bedrooms_total >= %d", $filters['min_beds']);
        }

        // Bathrooms
        if (!empty($filters['min_baths'])) {
            $where_clauses[] = $wpdb->prepare("ld.bathrooms_total_integer >= %d", $filters['min_baths']);
        }

        // Square footage
        if (!empty($filters['min_sqft'])) {
            $where_clauses[] = $wpdb->prepare("ld.building_area_total >= %d", $filters['min_sqft']);
        }
        if (!empty($filters['max_sqft'])) {
            $where_clauses[] = $wpdb->prepare("ld.building_area_total <= %d", $filters['max_sqft']);
        }

        // Generic agent filter (agent_ids) - matches listing agent or buyer agent with OR logic
        if (!empty($filters['agent_ids'])) {
            $agent_ids = is_array($filters['agent_ids']) ? $filters['agent_ids'] : [$filters['agent_ids']];
            if (!empty($agent_ids)) {
                $agent_conditions = [];
                $placeholders = implode(', ', array_fill(0, count($agent_ids), '%s'));

                // Check list_agent_mls_id
                $agent_conditions[] = $wpdb->prepare("l.list_agent_mls_id IN ({$placeholders})", ...$agent_ids);
                // Check buyer_agent_mls_id
                $agent_conditions[] = $wpdb->prepare("l.buyer_agent_mls_id IN ({$placeholders})", ...$agent_ids);

                // OR logic - listing can have agent in any of the fields
                $where_clauses[] = '(' . implode(' OR ', $agent_conditions) . ')';
            }
        }

        // Specific agent filters
        if (!empty($filters['listing_agent_id'])) {
            $where_clauses[] = $wpdb->prepare("l.list_agent_mls_id = %s", $filters['listing_agent_id']);
        }
        if (!empty($filters['buyer_agent_id'])) {
            $where_clauses[] = $wpdb->prepare("l.buyer_agent_mls_id = %s", $filters['buyer_agent_id']);
        }

        return $where_clauses;
    }


    /**
     * Normalize summary table fields to match frontend expectations
     *
     * @param array $listing Raw listing from summary table
     * @return array Normalized listing data
     * @since 5.2.1
     */
    private function normalize_summary_fields($listing) {
        // Convert objects to arrays for consistent handling
        if (is_object($listing)) {
            $listing = (array) $listing;
        }

        if (!is_array($listing)) {
            return $listing;
        }

        // Map summary table fields to expected field names
        $field_mappings = [
            'main_photo_url' => 'photo_url',
            'bathrooms_total' => 'bathrooms_total_integer',
            'building_area_total' => 'living_area',  // Card template expects living_area
        ];

        // Apply mappings (copy value to new key if not already set)
        foreach ($field_mappings as $from => $to) {
            if (isset($listing[$from]) && !empty($listing[$from]) && (!isset($listing[$to]) || empty($listing[$to]))) {
                $listing[$to] = $listing[$from];
            }
        }

        // Ensure numeric fields are properly typed
        $numeric_fields = [
            'bedrooms_total', 'bathrooms_total_integer', 'bathrooms_full', 'bathrooms_half',
            'building_area_total', 'living_area', 'list_price', 'garage_spaces', 'latitude', 'longitude'
        ];

        foreach ($numeric_fields as $field) {
            if (isset($listing[$field]) && $listing[$field] !== null && $listing[$field] !== '') {
                $listing[$field] = is_numeric($listing[$field]) ? $listing[$field] : 0;
            }
        }

        // Ensure critical fields exist even if null
        $required_fields = [
            'listing_id', 'listing_key', 'list_price', 'standard_status',
            'street_number', 'street_name', 'unit_number', 'city', 'state_or_province', 'postal_code',
            'bedrooms_total', 'bathrooms_total_integer', 'bathrooms_full', 'bathrooms_half',
            'building_area_total', 'living_area',
            'latitude', 'longitude', 'photo_url'
        ];

        foreach ($required_fields as $field) {
            if (!isset($listing[$field])) {
                $listing[$field] = null;
            }
        }

        return $listing;
    }

    /**
     * Normalize an array of listings
     *
     * @param array $listings
     * @return array
     * @since 5.2.1
     */
    private function normalize_listings_array($listings) {
        if (!is_array($listings)) {
            return $listings;
        }

        return array_map([$this, 'normalize_summary_fields'], $listings);
    }


}