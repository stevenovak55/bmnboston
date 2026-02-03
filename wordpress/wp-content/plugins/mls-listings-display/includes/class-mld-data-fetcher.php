<?php
/**
 * Generalized data fetcher for schools and boundaries
 * Supports all US states with progress tracking
 */

class MLD_Data_Fetcher {

    private $wpdb;
    private $progress_callback;
    private $total_imported = 0;
    private $total_skipped = 0;
    private $errors = [];
    private $imported_items = [];

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Set progress callback for real-time updates
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
    }

    /**
     * Send progress update
     */
    private function send_progress($message, $percent = null, $data = null) {
        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, [
                'message' => $message,
                'percent' => $percent,
                'imported' => $this->total_imported,
                'skipped' => $this->total_skipped,
                'errors' => count($this->errors),
                'current_data' => $data
            ]);
        }
    }

    /**
     * Fetch schools for a specific state
     */
    public function fetch_schools($state_code, $options = []) {
        $this->total_imported = 0;
        $this->total_skipped = 0;
        $this->errors = [];
        $this->imported_items = [];

        $state_name = $this->get_state_name($state_code);
        $bounds = $this->get_state_bounds($state_code);

        if (!$bounds) {
            $this->send_progress("Error: Could not determine bounds for {$state_name}", 0);
            return false;
        }

        $this->send_progress("Starting school import for {$state_name}...", 0);

        // Clear existing data if requested
        if (!empty($options['clear_existing'])) {
            $this->send_progress("Clearing existing schools for {$state_name}...", 5);
            // Clear both state code and state name formats
            $deleted = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->prefix}mld_schools WHERE state = %s OR state = %s",
                $state_code,
                $state_name
            ));
            $this->send_progress("Cleared {$deleted} existing schools", 5);
        }

        // For large states like MA, split into smaller regions
        $schools = [];
        if ($state_code === 'MA') {
            $regions = $this->get_ma_regions();
            foreach ($regions as $region_name => $region_bounds) {
                $this->send_progress("Fetching schools for {$region_name}...", 8);
                $region_schools = $this->fetch_schools_from_osm($region_bounds, $state_code);
                if (!empty($region_schools)) {
                    $schools = array_merge($schools, $region_schools);
                    $this->send_progress("Found " . count($region_schools) . " schools in {$region_name}", 10);
                }
                // Small delay between regions to avoid rate limiting
                sleep(1);
            }
        } else {
            // Fetch from OpenStreetMap
            $schools = $this->fetch_schools_from_osm($bounds, $state_code);
        }

        if (empty($schools)) {
            $this->send_progress("No schools found for {$state_name}", 100);
            return false;
        }

        $total = count($schools);
        $this->send_progress("Found {$total} schools to process...", 15);

        // Process each school
        foreach ($schools as $index => $school) {
            $percent = 15 + (($index / $total) * 80);

            $result = $this->import_school($school, $state_code);

            if ($result['status'] === 'imported') {
                $this->total_imported++;
                $this->imported_items[] = $result['data'];
                $this->send_progress(
                    "Imported: {$result['data']['name']} ({$result['data']['type']})",
                    $percent,
                    $result['data']
                );
            } elseif ($result['status'] === 'skipped') {
                $this->total_skipped++;
                $this->send_progress(
                    "Skipped duplicate: {$result['data']['name']}",
                    $percent
                );
            } else {
                $this->errors[] = $result['error'];
                $this->send_progress(
                    "Error importing school: {$result['error']}",
                    $percent
                );
            }

            // Send batch update every 10 schools
            if ($index > 0 && $index % 10 === 0) {
                $this->send_progress(
                    "Progress: {$this->total_imported} imported, {$this->total_skipped} skipped",
                    $percent
                );
            }
        }

        $this->send_progress(
            "Import complete! Imported {$this->total_imported} schools, skipped {$this->total_skipped} duplicates",
            100
        );

        // Add error summary if there were errors
        $error_summary = '';
        if (!empty($this->errors)) {
            // Get unique error types
            $error_types = array_count_values($this->errors);
            $error_summary = '<h4>Errors encountered:</h4><ul>';
            foreach ($error_types as $error => $count) {
                $error_summary .= '<li>' . esc_html($error) . ' (x' . $count . ')</li>';
            }
            $error_summary .= '</ul>';
        }

        return [
            'success' => true,
            'imported' => $this->total_imported,
            'skipped' => $this->total_skipped,
            'errors' => $this->errors,
            'error_summary' => $error_summary,
            'items' => array_slice($this->imported_items, 0, 10) // Return first 10 for display
        ];
    }

    /**
     * Fetch schools from OpenStreetMap
     */
    private function fetch_schools_from_osm($bounds, $state_code) {
        $overpass_url = 'https://overpass-api.de/api/interpreter';

        // Build a simpler, valid Overpass query for schools
        // Note: We're not filtering by "imported" tag as that's not a standard OSM tag
        $query = sprintf('[out:json][timeout:90];
            (
                node["amenity"="school"](%f,%f,%f,%f);
                way["amenity"="school"](%f,%f,%f,%f);
                node["amenity"="kindergarten"](%f,%f,%f,%f);
                way["amenity"="kindergarten"](%f,%f,%f,%f);
                node["amenity"="university"](%f,%f,%f,%f);
                way["amenity"="university"](%f,%f,%f,%f);
                node["amenity"="college"](%f,%f,%f,%f);
                way["amenity"="college"](%f,%f,%f,%f);
            );
            out center;',
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'],
            $bounds['south'], $bounds['west'], $bounds['north'], $bounds['east']
        );

        $this->send_progress("Fetching schools from OpenStreetMap...", 15);

        $response = wp_remote_post($overpass_url, [
            'body' => ['data' => $query],
            'timeout' => 120,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ]);

        if (is_wp_error($response)) {
            $error_message = 'Failed to fetch data from OpenStreetMap: ' . $response->get_error_message();
            $this->errors[] = $error_message;
            $this->send_progress($error_message, 100);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Failed to parse OpenStreetMap response: ' . json_last_error_msg();
            $this->errors[] = $error_message;
            $this->send_progress($error_message, 100);
            return [];
        }

        if (empty($data['elements'])) {
            $this->send_progress("No schools found in OpenStreetMap for this area", 100);
            return [];
        }

        $this->send_progress("Found " . count($data['elements']) . " schools from OpenStreetMap", 20);

        return $data['elements'];
    }

    /**
     * Import a single school
     */
    private function import_school($element, $state_code) {
        // Extract school data
        $tags = isset($element['tags']) ? $element['tags'] : [];

        $name = isset($tags['name']) ? $tags['name'] : 'Unnamed School';
        $type = $this->determine_school_type($tags, $name);

        // Get coordinates
        if (isset($element['lat']) && isset($element['lon'])) {
            $lat = $element['lat'];
            $lon = $element['lon'];
        } elseif (isset($element['center'])) {
            $lat = $element['center']['lat'];
            $lon = $element['center']['lon'];
        } else {
            return [
                'status' => 'error',
                'error' => 'No coordinates found for school: ' . $name
            ];
        }

        // Get additional information
        $address = $this->extract_address($tags);
        $website = isset($tags['website']) ? $tags['website'] : '';
        $phone = isset($tags['phone']) ? $tags['phone'] : '';

        // Check for duplicates by OSM ID first, then by name/location
        $osm_id = isset($element['id']) ? $element['id'] : null;

        if ($osm_id) {
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}mld_schools WHERE osm_id = %d",
                $osm_id
            ));
        } else {
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}mld_schools
                 WHERE name = %s AND ABS(latitude - %f) < 0.0001 AND ABS(longitude - %f) < 0.0001",
                $name, $lat, $lon
            ));
        }

        if ($existing) {
            return [
                'status' => 'skipped',
                'data' => ['name' => $name, 'type' => $type]
            ];
        }

        // Insert into database matching the actual table structure
        $result = $this->wpdb->insert(
            $this->wpdb->prefix . 'mld_schools',
            [
                'osm_id' => $osm_id,
                'name' => $name,
                'school_type' => $type,
                'school_level' => $this->map_to_school_level($type),
                'address' => $address,
                'city' => isset($tags['addr:city']) ? $tags['addr:city'] : '',
                'state' => $state_code,
                'postal_code' => isset($tags['addr:postcode']) ? $tags['addr:postcode'] : '',
                'latitude' => $lat,
                'longitude' => $lon,
                'website' => $website,
                'phone' => $phone,
                'district_id' => 0,
                'data_source' => 'OpenStreetMap',
                'last_updated' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s']
        );

        if ($result === false) {
            return [
                'status' => 'error',
                'error' => 'Database error: ' . $this->wpdb->last_error
            ];
        }

        return [
            'status' => 'imported',
            'data' => [
                'name' => $name,
                'type' => $type,
                'address' => $address,
                'city' => isset($tags['addr:city']) ? $tags['addr:city'] : '',
                'lat' => $lat,
                'lon' => $lon
            ]
        ];
    }

    /**
     * Map school type to school level
     */
    private function map_to_school_level($type) {
        switch($type) {
            case 'preschool':
            case 'kindergarten':
                return 'preschool';
            case 'elementary':
                return 'elementary';
            case 'middle':
                return 'middle';
            case 'high':
                return 'high';
            case 'college':
            case 'university':
                return 'college';
            default:
                return 'unknown';
        }
    }

    /**
     * Determine school type from tags and name
     */
    private function determine_school_type($tags, $name) {
        $amenity = isset($tags['amenity']) ? $tags['amenity'] : '';
        $isced = isset($tags['isced:level']) ? $tags['isced:level'] : '';

        // Check amenity type
        if ($amenity === 'kindergarten') {
            return 'preschool';
        }
        if ($amenity === 'university') {
            return 'university';
        }
        if ($amenity === 'college') {
            return 'college';
        }

        // Check ISCED level
        if ($isced) {
            if (strpos($isced, '0') !== false) return 'preschool';
            if (strpos($isced, '1') !== false) return 'elementary';
            if (strpos($isced, '2') !== false) return 'middle';
            if (strpos($isced, '3') !== false) return 'high';
        }

        // Check name patterns
        $name_lower = strtolower($name);
        if (strpos($name_lower, 'preschool') !== false ||
            strpos($name_lower, 'kindergarten') !== false ||
            strpos($name_lower, 'nursery') !== false) {
            return 'preschool';
        }
        if (strpos($name_lower, 'elementary') !== false ||
            strpos($name_lower, 'primary') !== false) {
            return 'elementary';
        }
        if (strpos($name_lower, 'middle') !== false ||
            strpos($name_lower, 'junior high') !== false) {
            return 'middle';
        }
        if (strpos($name_lower, 'high school') !== false ||
            strpos($name_lower, 'secondary') !== false) {
            return 'high';
        }

        return 'unknown';
    }

    /**
     * Extract address from tags
     */
    private function extract_address($tags) {
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
     * Get Massachusetts regions for chunked fetching
     */
    private function get_ma_regions() {
        return [
            'Boston Core' => [
                'north' => 42.40,
                'south' => 42.30,
                'east' => -70.99,
                'west' => -71.15
            ],
            'Greater Boston North' => [
                'north' => 42.50,
                'south' => 42.38,
                'east' => -70.95,
                'west' => -71.25
            ],
            'Greater Boston South' => [
                'north' => 42.32,
                'south' => 42.20,
                'east' => -70.90,
                'west' => -71.20
            ],
            'MetroWest' => [
                'north' => 42.45,
                'south' => 42.15,
                'east' => -71.20,
                'west' => -71.65
            ],
            'North Shore' => [
                'north' => 42.80,
                'south' => 42.45,
                'east' => -70.60,
                'west' => -71.20
            ],
            'South Shore' => [
                'north' => 42.20,
                'south' => 41.90,
                'east' => -70.50,
                'west' => -71.10
            ],
            'Merrimack Valley' => [
                'north' => 42.85,
                'south' => 42.50,
                'east' => -71.00,
                'west' => -71.50
            ],
            'Central Mass' => [
                'north' => 42.60,
                'south' => 42.00,
                'east' => -71.50,
                'west' => -72.20
            ],
            'Pioneer Valley' => [
                'north' => 42.65,
                'south' => 42.00,
                'east' => -72.20,
                'west' => -72.75
            ],
            'Berkshires' => [
                'north' => 42.75,
                'south' => 42.00,
                'east' => -72.75,
                'west' => -73.50
            ],
            'Cape Cod and Islands' => [
                'north' => 42.10,
                'south' => 41.25,
                'east' => -69.90,
                'west' => -70.85
            ],
            'Southeastern Mass' => [
                'north' => 42.00,
                'south' => 41.50,
                'east' => -70.50,
                'west' => -71.20
            ]
        ];
    }

    /**
     * Get state bounds
     */
    private function get_state_bounds($state_code) {
        // Simplified bounds for all US states
        $bounds = [
            'MA' => ['north' => 42.9, 'south' => 41.2, 'east' => -69.9, 'west' => -73.5],
            'CT' => ['north' => 42.1, 'south' => 40.9, 'east' => -71.8, 'west' => -73.7],
            'RI' => ['north' => 42.0, 'south' => 41.1, 'east' => -71.1, 'west' => -71.9],
            'NH' => ['north' => 45.3, 'south' => 42.7, 'east' => -70.7, 'west' => -72.6],
            'VT' => ['north' => 45.0, 'south' => 42.7, 'east' => -71.5, 'west' => -73.4],
            'ME' => ['north' => 47.5, 'south' => 43.0, 'east' => -66.9, 'west' => -71.1],
            'NY' => ['north' => 45.0, 'south' => 40.5, 'east' => -71.8, 'west' => -79.8],
            'CA' => ['north' => 42.0, 'south' => 32.5, 'east' => -114.1, 'west' => -124.5],
            'TX' => ['north' => 36.5, 'south' => 25.8, 'east' => -93.5, 'west' => -106.7],
            'FL' => ['north' => 31.0, 'south' => 24.5, 'east' => -80.0, 'west' => -87.6],
            // Add more states as needed
        ];

        return isset($bounds[$state_code]) ? $bounds[$state_code] : null;
    }




    /**
     * Extract boundary geometry from OSM element
     */
    private function extract_boundary_geometry($element) {
        $coordinates = [];

        // For relations with geometry
        if (isset($element['geometry'])) {
            foreach ($element['geometry'] as $point) {
                if (isset($point['lat']) && isset($point['lon'])) {
                    $coordinates[] = [$point['lon'], $point['lat']];
                }
            }
        }
        // For ways with nodes
        elseif (isset($element['nodes']) && is_array($element['nodes'])) {
            foreach ($element['nodes'] as $node) {
                if (isset($node['lat']) && isset($node['lon'])) {
                    $coordinates[] = [$node['lon'], $node['lat']];
                }
            }
        }
        // For relations with members
        elseif (isset($element['members'])) {
            // This would need more complex processing to resolve member references
            // For now, we'll skip complex relations
            return null;
        }

        if (empty($coordinates)) {
            return null;
        }

        // Ensure polygon is closed
        if ($coordinates[0] !== $coordinates[count($coordinates) - 1]) {
            $coordinates[] = $coordinates[0];
        }

        return [
            'type' => 'Polygon',
            'coordinates' => [$coordinates]
        ];
    }

    /**
     * Get state name from code
     */
    private function get_state_name($state_code) {
        $states = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming'
        ];

        return isset($states[$state_code]) ? $states[$state_code] : $state_code;
    }
}