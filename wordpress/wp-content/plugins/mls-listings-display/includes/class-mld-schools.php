<?php
/**
 * Handles school data for properties
 * Fetches from OpenStreetMap and GreatSchools API
 *
 * @package MLS_Listings_Display
 * @since 4.5.0
 */

class MLD_Schools {

    /**
     * School types we track
     */
    const SCHOOL_TYPES = [
        'elementary' => 'Elementary School',
        'middle' => 'Middle School',
        'high' => 'High School',
        'private' => 'Private School',
        'charter' => 'Charter School',
        'preschool' => 'Preschool',
        'university' => 'University/College'
    ];

    /**
     * Initialize the schools system
     */
    public function __construct() {
        // Hook into activation to create table
        register_activation_hook(MLD_PLUGIN_FILE, array($this, 'create_schools_table'));

        // Add AJAX handlers
        add_action('wp_ajax_mld_get_nearby_schools', array($this, 'ajax_get_nearby_schools'));
        add_action('wp_ajax_nopriv_mld_get_nearby_schools', array($this, 'ajax_get_nearby_schools'));


        add_action('wp_ajax_mld_toggle_schools_layer', array($this, 'ajax_toggle_schools_layer'));
        add_action('wp_ajax_nopriv_mld_toggle_schools_layer', array($this, 'ajax_toggle_schools_layer'));

        // Handle scheduled import
        add_action('mld_import_schools_data', array($this, 'import_schools_background'));
    }

    /**
     * Import schools data in background (called by scheduled event)
     */
    public function import_schools_background() {
        // Check if data already exists
        global $wpdb;
        $schools_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mld_schools");

        if ($schools_count > 0) {
            return; // Data already imported
        }

        // Run the import scripts
        if (file_exists(MLD_PLUGIN_PATH . 'includes/fetch-ma-schools.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/fetch-ma-schools.php';
            $fetcher = new MA_Schools_Fetcher();
            $fetcher->fetch_all_ma_schools();
        }

    }

    /**
     * Create database tables for caching school data
     */
    public static function create_schools_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Main schools table
        $schools_table = $wpdb->prefix . 'mld_schools';
        $sql = "CREATE TABLE IF NOT EXISTS $schools_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            osm_id BIGINT UNIQUE,
            name VARCHAR(255) NOT NULL,
            school_type VARCHAR(50),
            grades VARCHAR(50),
            school_level VARCHAR(20),
            address VARCHAR(255),
            city VARCHAR(100),
            state VARCHAR(50) DEFAULT 'Massachusetts',
            postal_code VARCHAR(20),
            latitude DECIMAL(10, 7),
            longitude DECIMAL(10, 7),
            phone VARCHAR(50),
            website VARCHAR(255),
            rating DECIMAL(3, 1),
            rating_source VARCHAR(50),
            student_count INT,
            student_teacher_ratio DECIMAL(4, 1),
            district VARCHAR(255),
            district_id INT,
            data_source VARCHAR(50) DEFAULT 'OpenStreetMap',
            amenities TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_location (latitude, longitude),
            KEY idx_city (city),
            KEY idx_type (school_type),
            KEY idx_level (school_level),
            KEY idx_rating (rating),
            KEY idx_district (district_id)
        ) $charset_collate;";


        // Property-School relationships (for caching nearest schools)
        $property_schools_table = $wpdb->prefix . 'mld_property_schools';
        $sql .= "CREATE TABLE IF NOT EXISTS $property_schools_table (
            id INT(11) NOT NULL AUTO_INCREMENT,
            listing_id VARCHAR(50) NOT NULL,
            school_id INT NOT NULL,
            distance_miles DECIMAL(4, 2),
            drive_time_minutes INT,
            walk_time_minutes INT,
            assigned_school BOOLEAN DEFAULT FALSE,
            school_level VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY listing_school (listing_id, school_id),
            KEY idx_listing (listing_id),
            KEY idx_school (school_id),
            KEY idx_distance (distance_miles),
            KEY idx_assigned (assigned_school)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * AJAX handler to get nearby schools for a property
     */
    public function ajax_get_nearby_schools() {
        // Verify nonce
        if (!check_ajax_referer('bme_map_nonce', 'security', false)) {
            wp_send_json_error('Security check failed', 403);
            return;
        }

        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $radius = floatval($_POST['radius'] ?? 2); // Default 2 miles
        $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === 'true';

        if (!$lat || !$lng) {
            wp_send_json_error('Invalid coordinates');
            return;
        }

        // Check cache first (unless force refresh)
        if (!$force_refresh && $listing_id) {
            $cached = $this->get_cached_schools_for_property($listing_id);
            if ($cached) {
                wp_send_json_success($cached);
                return;
            }
        }

        // Fetch schools from database
        $schools = $this->find_nearby_schools($lat, $lng, $radius);

        // If we don't have enough schools in DB, fetch from OpenStreetMap
        if (count($schools) < 3) {
            $this->fetch_schools_from_osm($lat, $lng, $radius);
            // Re-query after fetching new data
            $schools = $this->find_nearby_schools($lat, $lng, $radius);
        }

        // Categorize schools by level
        $categorized = $this->categorize_schools($schools);

        // Cache the results if we have a listing ID
        if ($listing_id && !empty($schools)) {
            $this->cache_schools_for_property($listing_id, $schools);
        }

        wp_send_json_success([
            'schools' => $schools,
            'categorized' => $categorized,
            'total' => count($schools)
        ]);
    }

    /**
     * Find nearby schools from database
     */
    private function find_nearby_schools($lat, $lng, $radius_miles) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_schools';

        // Use Haversine formula for distance calculation (works without spatial extensions)
        $sql = $wpdb->prepare("
            SELECT
                *,
                ( 3959 * acos( cos( radians(%f) ) * cos( radians( latitude ) ) *
                  cos( radians( longitude ) - radians(%f) ) +
                  sin( radians(%f) ) * sin( radians( latitude ) ) ) ) AS distance_miles
            FROM $table_name
            HAVING distance_miles <= %f
            ORDER BY distance_miles ASC
            LIMIT 20
        ", $lat, $lng, $lat, $radius_miles);

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Format results
        foreach ($results as &$school) {
            $school['distance_miles'] = round($school['distance_miles'], 2);
            $school['walk_time'] = $this->estimate_walk_time($school['distance_miles']);
            $school['drive_time'] = $this->estimate_drive_time($school['distance_miles']);
        }

        return $results;
    }

    /**
     * Fetch schools from OpenStreetMap
     */
    private function fetch_schools_from_osm($lat, $lng, $radius_miles) {
        // Convert radius to meters for Overpass API
        $radius_meters = $radius_miles * 1609.34;

        // Overpass API query for schools
        $query = sprintf('[out:json][timeout:25];
            (
              node["amenity"="school"](around:%d,%f,%f);
              way["amenity"="school"](around:%d,%f,%f);
              node["amenity"="kindergarten"](around:%d,%f,%f);
              way["amenity"="kindergarten"](around:%d,%f,%f);
              node["amenity"="university"](around:%d,%f,%f);
              way["amenity"="university"](around:%d,%f,%f);
              node["amenity"="college"](around:%d,%f,%f);
              way["amenity"="college"](around:%d,%f,%f);
            );
            out body;
            >;
            out skel qt;',
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng,
            $radius_meters, $lat, $lng
        );

        $url = 'https://overpass-api.de/api/interpreter';

        $response = wp_remote_post($url, [
            'body' => ['data' => $query],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Failed to fetch schools from OSM: ' . $response->get_error_message());
            }
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['elements'])) {
            return;
        }

        // Process and save schools
        foreach ($data['elements'] as $element) {
            if (empty($element['tags']['name'])) {
                continue;
            }

            // Extract school data
            $school_data = $this->parse_osm_school($element, $lat, $lng);
            if ($school_data) {
                $this->save_school($school_data);
            }
        }
    }

    /**
     * Parse OSM school data
     */
    private function parse_osm_school($element, $origin_lat, $origin_lng) {
        $tags = $element['tags'] ?? [];

        // Determine coordinates
        if ($element['type'] === 'node') {
            $lat = $element['lat'];
            $lng = $element['lon'];
        } elseif (isset($element['center'])) {
            $lat = $element['center']['lat'];
            $lng = $element['center']['lon'];
        } else {
            return null;
        }

        // Determine school type and level
        $amenity = $tags['amenity'] ?? '';
        $school_level = $this->determine_school_level($tags);
        $school_type = $this->determine_school_type($tags);

        return [
            'osm_id' => $element['id'],
            'name' => $tags['name'],
            'school_type' => $school_type,
            'school_level' => $school_level,
            'grades' => $tags['grades'] ?? $tags['school:grades'] ?? '',
            'address' => $this->format_address($tags),
            'city' => $tags['addr:city'] ?? '',
            'state' => $tags['addr:state'] ?? 'Massachusetts',
            'postal_code' => $tags['addr:postcode'] ?? '',
            'latitude' => $lat,
            'longitude' => $lng,
            'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? '',
            'website' => $tags['website'] ?? $tags['contact:website'] ?? '',
            'student_count' => isset($tags['capacity']) ? intval($tags['capacity']) : null,
            'data_source' => 'OpenStreetMap'
        ];
    }

    /**
     * Determine school level from OSM tags
     */
    private function determine_school_level($tags) {
        $name = strtolower($tags['name'] ?? '');
        $type = strtolower($tags['school:type'] ?? '');
        $grades = strtolower($tags['grades'] ?? $tags['school:grades'] ?? '');

        if (strpos($name, 'elementary') !== false || strpos($grades, 'k-5') !== false) {
            return 'elementary';
        } elseif (strpos($name, 'middle') !== false || strpos($grades, '6-8') !== false) {
            return 'middle';
        } elseif (strpos($name, 'high') !== false || strpos($grades, '9-12') !== false) {
            return 'high';
        } elseif ($tags['amenity'] === 'university' || $tags['amenity'] === 'college') {
            return 'university';
        } elseif ($tags['amenity'] === 'kindergarten' || strpos($name, 'preschool') !== false) {
            return 'preschool';
        }

        return 'unknown';
    }

    /**
     * Determine school type from OSM tags
     */
    private function determine_school_type($tags) {
        $operator = strtolower($tags['operator'] ?? '');
        $type = strtolower($tags['school:type'] ?? '');

        if (strpos($operator, 'private') !== false || $type === 'private') {
            return 'private';
        } elseif (strpos($operator, 'charter') !== false || strpos($type, 'charter') !== false) {
            return 'charter';
        } elseif (strpos($operator, 'catholic') !== false || strpos($operator, 'religious') !== false) {
            return 'private';
        }

        return 'public';
    }

    /**
     * Format address from OSM tags
     */
    private function format_address($tags) {
        $parts = [];

        if (isset($tags['addr:housenumber'])) {
            $parts[] = $tags['addr:housenumber'];
        }
        if (isset($tags['addr:street'])) {
            $parts[] = $tags['addr:street'];
        }

        return implode(' ', $parts);
    }

    /**
     * Save school to database
     */
    private function save_school($school_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_schools';

        // Check if school already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE osm_id = %d",
            $school_data['osm_id']
        ));

        if ($existing) {
            // Update existing record
            $wpdb->update(
                $table_name,
                $school_data,
                ['id' => $existing],
                null,
                ['%d']
            );
        } else {
            // Insert new record
            $wpdb->insert($table_name, $school_data);
        }
    }

    /**
     * Categorize schools by level
     */
    private function categorize_schools($schools) {
        $categorized = [
            'elementary' => [],
            'middle' => [],
            'high' => [],
            'private' => [],
            'other' => []
        ];

        foreach ($schools as $school) {
            if ($school['school_type'] === 'private') {
                $categorized['private'][] = $school;
            } elseif ($school['school_level'] === 'elementary') {
                $categorized['elementary'][] = $school;
            } elseif ($school['school_level'] === 'middle') {
                $categorized['middle'][] = $school;
            } elseif ($school['school_level'] === 'high') {
                $categorized['high'][] = $school;
            } else {
                $categorized['other'][] = $school;
            }
        }

        return $categorized;
    }

    /**
     * Estimate walk time based on distance
     */
    private function estimate_walk_time($miles) {
        // Average walking speed: 3 mph
        $minutes = round($miles * 20);
        return $minutes;
    }

    /**
     * Estimate drive time based on distance
     */
    private function estimate_drive_time($miles) {
        // Average city driving speed: 25 mph
        $minutes = round($miles * 2.4);
        return max($minutes, 2); // Minimum 2 minutes
    }

    /**
     * Get cached schools for a property
     */
    private function get_cached_schools_for_property($listing_id) {
        global $wpdb;

        $cache_table = $wpdb->prefix . 'mld_property_schools';
        $schools_table = $wpdb->prefix . 'mld_schools';

        $sql = $wpdb->prepare("
            SELECT
                s.*,
                ps.distance_miles,
                ps.drive_time_minutes,
                ps.walk_time_minutes,
                ps.assigned_school
            FROM $cache_table ps
            JOIN $schools_table s ON ps.school_id = s.id
            WHERE ps.listing_id = %s
            ORDER BY ps.distance_miles ASC
        ", $listing_id);

        $results = $wpdb->get_results($sql, ARRAY_A);

        if (empty($results)) {
            return null;
        }

        return [
            'schools' => $results,
            'categorized' => $this->categorize_schools($results),
            'total' => count($results)
        ];
    }

    /**
     * Cache schools for a property
     */
    private function cache_schools_for_property($listing_id, $schools) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_property_schools';

        // Clear existing cache
        $wpdb->delete($table_name, ['listing_id' => $listing_id]);

        // Insert new cache entries
        foreach ($schools as $school) {
            $wpdb->insert(
                $table_name,
                [
                    'listing_id' => $listing_id,
                    'school_id' => $school['id'],
                    'distance_miles' => $school['distance_miles'],
                    'drive_time_minutes' => $school['drive_time'] ?? $this->estimate_drive_time($school['distance_miles']),
                    'walk_time_minutes' => $school['walk_time'] ?? $this->estimate_walk_time($school['distance_miles']),
                    'school_level' => $school['school_level']
                ]
            );
        }
    }


    /**
     * Toggle schools layer visibility
     */
    public function ajax_toggle_schools_layer() {
        if (!check_ajax_referer('bme_map_nonce', 'security', false)) {
            wp_send_json_error('Security check failed', 403);
            return;
        }

        $show_schools = isset($_POST['show']) && $_POST['show'] === 'true';
        $school_types = isset($_POST['types']) ? (array)$_POST['types'] : ['all'];
        $bounds = isset($_POST['bounds']) ? json_decode(wp_unslash($_POST['bounds']), true) : null;

        // Debug log the types
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Schools - Received types: ' . json_encode($school_types));
        }

        if (!$bounds) {
            wp_send_json_error('Map bounds required');
            return;
        }

        if (!$show_schools) {
            wp_send_json_success(['show' => false]);
            return;
        }

        // Get schools within bounds
        $schools = $this->get_schools_in_bounds(
            $bounds['north'],
            $bounds['south'],
            $bounds['east'],
            $bounds['west'],
            $school_types
        );

        wp_send_json_success([
            'show' => true,
            'schools' => $schools
        ]);
    }

    /**
     * Get schools within map bounds
     */
    private function get_schools_in_bounds($north, $south, $east, $west, $types = ['all']) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_schools';

        // Calculate the span of the bounds to determine zoom level
        $lat_span = abs($north - $south);
        $lng_span = abs($east - $west);
        $max_span = max($lat_span, $lng_span);

        // Adjust limit based on approximate zoom level
        $limit = 500; // Default max
        if ($max_span > 1.0) {
            $limit = 50; // Very zoomed out - state level
        } elseif ($max_span > 0.5) {
            $limit = 100; // Region level
        } elseif ($max_span > 0.2) {
            $limit = 200; // County level
        } elseif ($max_span > 0.1) {
            $limit = 300; // City level
        } else {
            $limit = 500; // Neighborhood level
        }

        // Build type filter
        $type_where = '';
        $has_private = false;

        if (!in_array('all', $types) && !empty($types)) {
            // Check if 'private' is in the types
            if (in_array('private', $types)) {
                $has_private = true;
                // Remove 'private' from types array for school_level filtering
                $types = array_filter($types, function($type) {
                    return $type !== 'private';
                });
            }

            // Build school_level filter for remaining types
            $conditions = [];
            if (!empty($types)) {
                $type_placeholders = array_fill(0, count($types), '%s');
                $conditions[] = "school_level IN (" . implode(',', $type_placeholders) . ")";
            }

            // Add private school filter if needed
            if ($has_private) {
                $private_keywords = "(name LIKE '%Private%' OR name LIKE '%Catholic%' OR name LIKE '%Christian%' OR name LIKE '%Academy%' OR name LIKE '%Montessori%' OR name LIKE '%Prep%' OR name LIKE '%Parochial%')";
                $conditions[] = $private_keywords;
            }

            // Combine conditions with OR if we have both
            if (!empty($conditions)) {
                $type_where = " AND (" . implode(' OR ', $conditions) . ")";
            }
        }

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Schools - Filtering for types: ' . json_encode($types));
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Schools - Type where clause: ' . $type_where);
        }

        // Calculate center point for distance-based prioritization
        $center_lat = ($north + $south) / 2;
        $center_lng = ($east + $west) / 2;

        // Build the query with distance calculation for better school selection
        if (!empty($type_where)) {
            // Prepare the parameters array
            $params = [$center_lat, $center_lng, $center_lat, $south, $north, $west, $east];

            // Only add type parameters if we have school_level types (not just private)
            if (!empty($types)) {
                $params = array_merge($params, array_values($types));
            }

            $params[] = $limit;

            // Build the query string with placeholders
            $query = "
                SELECT *,
                (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) *
                 cos(radians(longitude) - radians(%f)) +
                 sin(radians(%f)) * sin(radians(latitude)))) AS distance
                FROM $table_name
                WHERE latitude BETWEEN %f AND %f
                AND longitude BETWEEN %f AND %f" . $type_where . "
                ORDER BY
                    CASE school_level
                        WHEN 'elementary' THEN 1
                        WHEN 'middle' THEN 2
                        WHEN 'high' THEN 3
                        WHEN 'university' THEN 4
                        WHEN 'preschool' THEN 5
                        ELSE 6
                    END,
                    distance ASC
                LIMIT %d";

            $sql = $wpdb->prepare($query, ...$params);
        } else {
            $sql = $wpdb->prepare("
                SELECT *,
                (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) *
                 cos(radians(longitude) - radians(%f)) +
                 sin(radians(%f)) * sin(radians(latitude)))) AS distance
                FROM $table_name
                WHERE latitude BETWEEN %f AND %f
                AND longitude BETWEEN %f AND %f
                ORDER BY
                    CASE school_level
                        WHEN 'elementary' THEN 1
                        WHEN 'middle' THEN 2
                        WHEN 'high' THEN 3
                        WHEN 'university' THEN 4
                        WHEN 'preschool' THEN 5
                        ELSE 6
                    END,
                    distance ASC
                LIMIT %d
            ", $center_lat, $center_lng, $center_lat,
               $south, $north, $west, $east, $limit);
        }

        // Cache results for frequently viewed areas
        $cache_key = 'mld_schools_bounds_' . md5(serialize([$north, $south, $east, $west, $types]));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Cache for 30 minutes
        set_transient($cache_key, $results, 30 * MINUTE_IN_SECONDS);

        return $results;
    }
}