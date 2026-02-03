<?php
/**
 * Handles city boundary polygons for map display
 * Fetches from OpenStreetMap Nominatim and caches locally
 *
 * @package MLS_Listings_Display
 * @since 4.4.0
 */

class MLD_City_Boundaries {

    /**
     * Initialize the city boundaries system
     */
    public function __construct() {
        // Hook into activation to create table
        register_activation_hook(MLD_PLUGIN_FILE, array($this, 'create_boundaries_table'));

        // Add AJAX handlers
        add_action('wp_ajax_mld_get_city_boundary', array($this, 'ajax_get_city_boundary'));
        add_action('wp_ajax_nopriv_mld_get_city_boundary', array($this, 'ajax_get_city_boundary'));
    }

    /**
     * Create database table for caching city boundaries
     */
    public static function create_boundaries_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT(11) NOT NULL AUTO_INCREMENT,
            city VARCHAR(100) NOT NULL,
            state VARCHAR(50) NOT NULL,
            country VARCHAR(50) DEFAULT 'USA',
            boundary_type VARCHAR(50) DEFAULT 'city',
            display_name VARCHAR(255),
            boundary_data LONGTEXT NOT NULL,
            bbox_north DECIMAL(10, 7),
            bbox_south DECIMAL(10, 7),
            bbox_east DECIMAL(10, 7),
            bbox_west DECIMAL(10, 7),
            fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY city_state_type (city, state, boundary_type),
            KEY last_used_idx (last_used)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * AJAX handler to get city boundary
     */
    public function ajax_get_city_boundary() {
        // Verify nonce
        if (!check_ajax_referer('bme_map_nonce', 'security', false)) {
            wp_send_json_error('Security check failed', 403);
            return;
        }

        $location = sanitize_text_field($_POST['location'] ?? $_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? 'Massachusetts');
        $type = sanitize_text_field($_POST['type'] ?? 'city');
        $parent_city = sanitize_text_field($_POST['parent_city'] ?? '');

        if (empty($location)) {
            wp_send_json_error('Location name is required');
            return;
        }

        // Try to get from cache first
        $boundary = $this->get_cached_boundary($location, $state, $type);

        if (!$boundary) {
            // Fetch from Nominatim
            if ($type === 'neighborhood' && !empty($parent_city)) {
                $boundary = $this->fetch_neighborhood_boundary($location, $parent_city, $state);
            } else {
                $boundary = $this->fetch_boundary_from_nominatim($location, $state, $type);
            }

            if ($boundary) {
                // Cache it
                $this->cache_boundary($location, $state, $boundary, $type);
            }
        }

        if ($boundary) {
            wp_send_json_success($boundary);
        } else {
            wp_send_json_error('Could not fetch boundary');
        }
    }

    /**
     * Get cached boundary from database
     */
    private function get_cached_boundary($location, $state, $type = 'city') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        // Check if boundary exists and is less than 30 days old
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT boundary_data,
                    bbox_north, bbox_south, bbox_east, bbox_west,
                    TIMESTAMPDIFF(DAY, fetched_at, NOW()) as age_days
             FROM $table_name
             WHERE city = %s AND state = %s AND boundary_type = %s
             HAVING age_days < 30",
            $location, $state, $type
        ));

        if ($result) {
            // Update last_used timestamp
            $wpdb->update(
                $table_name,
                array('last_used' => current_time('mysql')),
                array('city' => $location, 'state' => $state, 'boundary_type' => $type)
            );

            return array(
                'geometry' => json_decode($result->boundary_data, true),
                'bbox' => array(
                    'north' => floatval($result->bbox_north),
                    'south' => floatval($result->bbox_south),
                    'east' => floatval($result->bbox_east),
                    'west' => floatval($result->bbox_west)
                )
            );
        }

        return null;
    }

    /**
     * Fetch neighborhood boundary from Nominatim
     */
    private function fetch_neighborhood_boundary($neighborhood, $city, $state) {
        // Try different query formats for neighborhoods
        $queries = [
            // Format 1: neighborhood, city, state
            ['q' => "$neighborhood, $city, $state, USA"],
            // Format 2: Using suburb parameter
            ['suburb' => $neighborhood, 'city' => $city, 'state' => $state, 'country' => 'USA'],
            // Format 3: Using neighbourhood (British spelling)
            ['neighbourhood' => $neighborhood, 'city' => $city, 'state' => $state, 'country' => 'USA']
        ];

        foreach ($queries as $query_params) {
            $result = $this->query_nominatim($query_params);
            if ($result) {
                $result['type'] = 'neighborhood';
                return $result;
            }
        }

        return null;
    }

    /**
     * Fetch boundary from OpenStreetMap Nominatim
     */
    private function fetch_boundary_from_nominatim($location, $state, $type = 'city') {
        $query_params = [];

        if ($type === 'city') {
            $query_params = [
                'city' => $location,
                'state' => $state,
                'country' => 'USA'
            ];
        } else {
            // Generic query for other types
            $query_params = [
                'q' => "$location, $state, USA"
            ];
        }

        $result = $this->query_nominatim($query_params);
        if ($result) {
            $result['type'] = $type;
        }
        return $result;
    }

    /**
     * Query Nominatim API
     */
    private function query_nominatim($query_params) {
        // Build Nominatim API URL
        $query_params['format'] = 'geojson';
        $query_params['polygon_geojson'] = '1';
        $query_params['limit'] = '1';

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($query_params);

        // Set proper headers (Nominatim requires User-Agent)
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'MLS-Listings-Display-Plugin/4.4.0 (WordPress)',
                'Referer' => home_url()
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD City Boundaries: Failed to fetch from Nominatim - ' . $response->get_error_message());
            }
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['features']) || empty($data['features'][0]['geometry'])) {
            return null;
        }

        $feature = $data['features'][0];
        $geometry = $feature['geometry'];

        // Calculate bounding box
        $bbox = $this->calculate_bbox($geometry);

        // Get display name from the feature properties
        $display_name = $feature['properties']['display_name'] ?? '';

        return array(
            'geometry' => $geometry,
            'bbox' => $bbox,
            'display_name' => $display_name
        );
    }

    /**
     * Calculate bounding box from geometry
     */
    private function calculate_bbox($geometry) {
        $bbox = array(
            'north' => -90,
            'south' => 90,
            'east' => -180,
            'west' => 180
        );

        // Function to process coordinate arrays
        $process_coords = function($coords) use (&$bbox) {
            foreach ($coords as $coord) {
                if (is_array($coord[0])) {
                    // Nested array (MultiPolygon)
                    foreach ($coord as $subCoord) {
                        if (is_array($subCoord[0])) {
                            foreach ($subCoord as $point) {
                                $bbox['west'] = min($bbox['west'], $point[0]);
                                $bbox['east'] = max($bbox['east'], $point[0]);
                                $bbox['south'] = min($bbox['south'], $point[1]);
                                $bbox['north'] = max($bbox['north'], $point[1]);
                            }
                        } else {
                            $bbox['west'] = min($bbox['west'], $subCoord[0]);
                            $bbox['east'] = max($bbox['east'], $subCoord[0]);
                            $bbox['south'] = min($bbox['south'], $subCoord[1]);
                            $bbox['north'] = max($bbox['north'], $subCoord[1]);
                        }
                    }
                } else {
                    // Simple coordinate pair
                    $bbox['west'] = min($bbox['west'], $coord[0]);
                    $bbox['east'] = max($bbox['east'], $coord[0]);
                    $bbox['south'] = min($bbox['south'], $coord[1]);
                    $bbox['north'] = max($bbox['north'], $coord[1]);
                }
            }
        };

        if ($geometry['type'] === 'Polygon') {
            $process_coords($geometry['coordinates'][0]);
        } elseif ($geometry['type'] === 'MultiPolygon') {
            foreach ($geometry['coordinates'] as $polygon) {
                $process_coords($polygon[0]);
            }
        }

        return $bbox;
    }

    /**
     * Cache boundary in database
     */
    private function cache_boundary($location, $state, $boundary, $type = 'city') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        // Prepare data for insertion
        $data = array(
            'city' => $location,
            'state' => $state,
            'country' => 'USA',
            'boundary_type' => $type,
            'display_name' => $boundary['display_name'] ?? $location,
            'boundary_data' => json_encode($boundary['geometry']),
            'bbox_north' => $boundary['bbox']['north'],
            'bbox_south' => $boundary['bbox']['south'],
            'bbox_east' => $boundary['bbox']['east'],
            'bbox_west' => $boundary['bbox']['west'],
            'fetched_at' => current_time('mysql'),
            'last_used' => current_time('mysql')
        );

        $result = $wpdb->replace(
            $table_name,
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s')
        );

        // Log any errors
        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD City Boundaries: Failed to cache boundary for ' . $location . ', ' . $state);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Database error: ' . $wpdb->last_error);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD City Boundaries: Successfully cached boundary for ' . $location . ', ' . $state . ' (Type: ' . $type . ')');
            }
        }
    }

    /**
     * Clean up old cached boundaries (optional maintenance)
     */
    public function cleanup_old_boundaries() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_city_boundaries';

        // Delete boundaries not used in 90 days
        $wpdb->query(
            "DELETE FROM $table_name
             WHERE last_used < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
    }
}