<?php
/**
 * Boston Open Data Provider
 *
 * Imports Boston Public Schools data from Analyze Boston portal.
 *
 * API: https://data.boston.gov/api/3/action/datastore_search?resource_id=
 *
 * @package BMN_Schools
 * @since 0.3.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/data-providers/class-base-provider.php';

/**
 * Boston Open Data Provider Class
 *
 * @since 0.3.0
 */
class BMN_Schools_Boston_Provider extends BMN_Schools_Base_Provider {

    /**
     * Provider name.
     *
     * @var string
     */
    protected $provider_name = 'boston_open_data';

    /**
     * Display name.
     *
     * @var string
     */
    protected $display_name = 'Boston Public Schools';

    /**
     * API base URL.
     *
     * @var string
     */
    private $api_base = 'https://data.boston.gov/api/3/action/datastore_search';

    /**
     * Public schools resource ID (CSV format).
     * Updated Dec 2025 - old ID was removed from portal.
     *
     * @var string
     */
    private $schools_resource_id = '6ceeff38-a0db-46df-b5be-f8cfdea0186d';

    /**
     * Run the data sync/import.
     *
     * @since 0.3.0
     * @param array $options Import options.
     * @return array Result with success status and message.
     */
    public function sync($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'include_programs' => true,
        ];

        $options = wp_parse_args($options, $defaults);

        $this->update_source_status('syncing');
        BMN_Schools_Logger::import_started($this->provider_name);

        try {
            // Fetch all schools
            $schools = $this->fetch_schools();

            if (is_wp_error($schools)) {
                throw new Exception('API request failed: ' . $schools->get_error_message());
            }

            $imported = 0;

            foreach ($schools as $school) {
                $result = $this->import_school($school);
                if ($result) {
                    $imported++;
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $this->update_source_status('active', $imported);
            BMN_Schools_Logger::import_completed($this->provider_name, $imported, $duration_ms);

            return [
                'success' => true,
                'message' => sprintf('Imported %d schools from Boston Open Data', $imported),
                'count' => $imported,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->update_source_status('error', 0, $e->getMessage());
            $this->log_error($e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'count' => 0,
            ];
        }
    }

    /**
     * Fetch schools from Boston Open Data API.
     *
     * @since 0.3.0
     * @return array|WP_Error Schools data or error.
     */
    private function fetch_schools() {
        $all_records = [];
        $offset = 0;
        $limit = 100;

        do {
            $url = add_query_arg([
                'resource_id' => $this->schools_resource_id,
                'limit' => $limit,
                'offset' => $offset,
            ], $this->api_base);

            $this->log_progress("Fetching schools from offset {$offset}...");

            $response = $this->http_get($url);

            if (is_wp_error($response)) {
                return $response;
            }

            $data = $this->parse_json($response);

            if (is_wp_error($data)) {
                return $data;
            }

            if (!isset($data['success']) || !$data['success']) {
                return new WP_Error('api_error', 'Boston API returned error');
            }

            if (!isset($data['result']['records'])) {
                return new WP_Error('invalid_response', 'No records in API response');
            }

            $records = $data['result']['records'];
            $all_records = array_merge($all_records, $records);

            $total = $data['result']['total'] ?? 0;
            $offset += $limit;

            $this->log_progress("Retrieved " . count($all_records) . " of {$total} records");

        } while (count($records) === $limit && $offset < $total);

        return $all_records;
    }

    /**
     * Import a single school.
     *
     * @since 0.3.0
     * @param array $school School data from API.
     * @return bool Success.
     */
    private function import_school($school) {
        // Map Boston Open Data fields to our schema
        // Fields: SCH_NAME, ADDRESS, CITY (neighborhood), ZIPCODE, SCH_TYPE, POINT_X, POINT_Y, SCH_ID
        $school_data = [
            'name' => $this->extract_field($school, ['SCH_NAME', 'SCHNAME', 'School Name', 'name']),
            'address' => $this->extract_field($school, ['ADDRESS', 'Address']),
            'city' => 'Boston', // CITY field is neighborhood, we use Boston as the city
            'county' => $this->extract_field($school, ['CITY']), // Store neighborhood in county field
            'state' => 'MA',
            'zip' => $this->extract_field($school, ['ZIPCODE', 'ZIP', 'Zip']),
            'latitude' => $this->extract_coordinate($school, 'lat'),
            'longitude' => $this->extract_coordinate($school, 'lng'),
            'school_type' => 'public',
            'level' => $this->map_school_type($school),
        ];

        // Try to extract school ID
        $sch_id = $this->extract_field($school, ['SCH_ID', 'CSP_SCH_ID']);
        if ($sch_id) {
            // Clean up the ID (remove decimal places)
            $sch_id = preg_replace('/\..*$/', '', $sch_id);
            $school_data['state_school_id'] = 'BPS-' . $sch_id;
        }

        // Skip if no name
        if (empty($school_data['name'])) {
            return false;
        }

        // Sanitize and upsert
        $school_data = $this->sanitize_school_data($school_data);
        $result = $this->upsert_school($school_data);

        return !is_wp_error($result);
    }

    /**
     * Map SCH_TYPE to level.
     *
     * @since 0.3.0
     * @param array $school School data.
     * @return string Level (elementary, middle, high, combined).
     */
    private function map_school_type($school) {
        $type = $this->extract_field($school, ['SCH_TYPE']);

        if (!$type) {
            return 'combined';
        }

        $type = strtoupper(trim($type));

        $type_map = [
            'ES' => 'elementary',
            'ELC' => 'elementary',
            'K8' => 'combined',
            'MS' => 'middle',
            'HS' => 'high',
            'SHS' => 'high',
            'EEC' => 'elementary', // Early Education Center
        ];

        return isset($type_map[$type]) ? $type_map[$type] : 'combined';
    }

    /**
     * Extract a field value trying multiple possible keys.
     *
     * @since 0.3.0
     * @param array $data Data array.
     * @param array $keys Possible keys.
     * @return mixed|null Field value or null.
     */
    private function extract_field($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                return $data[$key];
            }
        }
        return null;
    }

    /**
     * Build full address from components.
     *
     * @since 0.3.0
     * @param array $school School data.
     * @return string Full address.
     */
    private function build_address($school) {
        $address = $this->extract_field($school, ['ADDRESS', 'Address', 'STREET']);

        if (empty($address)) {
            $street_num = $this->extract_field($school, ['ST_NUM', 'Street Number']);
            $street_name = $this->extract_field($school, ['ST_NAME', 'Street Name']);

            if ($street_num && $street_name) {
                $address = $street_num . ' ' . $street_name;
            }
        }

        return $address ?: '';
    }

    /**
     * Extract coordinate (latitude or longitude).
     *
     * @since 0.3.0
     * @param array  $school School data.
     * @param string $type   'lat' or 'lng'.
     * @return float|null Coordinate value.
     */
    private function extract_coordinate($school, $type) {
        if ($type === 'lat') {
            // Boston uses POINT_Y for latitude
            $keys = ['POINT_Y', 'Y', 'LAT', 'LATITUDE', 'Latitude', 'latitude'];
        } else {
            // Boston uses POINT_X for longitude
            $keys = ['POINT_X', 'X', 'LONG', 'LON', 'LONGITUDE', 'Longitude', 'longitude'];
        }

        $value = $this->extract_field($school, $keys);

        if ($value !== null && $value !== '') {
            return floatval($value);
        }

        // Try to parse from shape_wkt field (POINT format)
        if (isset($school['shape_wkt'])) {
            // Format: POINT (-71.004120000999933 42.388790000000029)
            if (preg_match('/POINT\s*\(\s*([\-0-9.]+)\s+([\-0-9.]+)\s*\)/', $school['shape_wkt'], $matches)) {
                if ($type === 'lng') {
                    return floatval($matches[1]);
                } else {
                    return floatval($matches[2]);
                }
            }
        }

        return null;
    }

    /**
     * Get list of BPS schools for a specific grade level.
     *
     * @since 0.3.0
     * @param string $level Grade level (elementary, middle, high).
     * @return array Schools matching the level.
     */
    public function get_schools_by_level($level) {
        $table = $this->db->get_table('schools');

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE city = 'Boston'
             AND school_type = 'public'
             AND level = %s
             ORDER BY name ASC",
            $level
        ));
    }

    /**
     * Get school by name (partial match).
     *
     * @since 0.3.0
     * @param string $name School name to search.
     * @return array Matching schools.
     */
    public function search_bps_schools($name) {
        $table = $this->db->get_table('schools');

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE city = 'Boston'
             AND school_type = 'public'
             AND name LIKE %s
             ORDER BY name ASC
             LIMIT 20",
            '%' . $this->wpdb->esc_like($name) . '%'
        ));
    }
}
