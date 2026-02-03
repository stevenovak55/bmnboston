<?php
/**
 * NCES EDGE Data Provider
 *
 * Imports school district boundaries from NCES EDGE (Education Demographic
 * and Geographic Estimates) program.
 *
 * API: https://nces.ed.gov/opengis/rest/services/School_District_Boundaries/
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
 * NCES EDGE Data Provider Class
 *
 * @since 0.3.0
 */
class BMN_Schools_NCES_Edge_Provider extends BMN_Schools_Base_Provider {

    /**
     * Provider name.
     *
     * @var string
     */
    protected $provider_name = 'nces_edge';

    /**
     * Display name.
     *
     * @var string
     */
    protected $display_name = 'NCES EDGE District Boundaries';

    /**
     * API base URL.
     *
     * @var string
     */
    private $api_base = 'https://nces.ed.gov/opengis/rest/services/School_District_Boundaries/';

    /**
     * Current service layer (most recent available).
     *
     * @var string
     */
    private $service_layer = 'EDGE_SCHOOLDISTRICT_TL21_SY2021';

    /**
     * Massachusetts FIPS code.
     *
     * @var string
     */
    private $state_fips = '25';

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
            'state_fips' => $this->state_fips,
            'include_geometry' => true,
        ];

        $options = wp_parse_args($options, $defaults);

        $this->update_source_status('syncing');
        BMN_Schools_Logger::import_started($this->provider_name);

        try {
            // Get total count first
            $total = $this->get_district_count($options['state_fips']);
            $this->log_progress("Found {$total} districts for state FIPS {$options['state_fips']}");

            // Fetch districts in batches
            $imported = 0;
            $offset = 0;
            $batch_size = 100;

            while ($offset < $total) {
                $this->log_progress("Fetching districts {$offset} to " . min($offset + $batch_size, $total) . "...");

                $districts = $this->fetch_districts($options['state_fips'], $batch_size, $offset, $options['include_geometry']);

                if (is_wp_error($districts)) {
                    throw new Exception('API request failed: ' . $districts->get_error_message());
                }

                foreach ($districts as $district) {
                    $result = $this->import_district($district);
                    if ($result) {
                        $imported++;
                    }
                }

                $offset += $batch_size;
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $this->update_source_status('active', $imported);
            BMN_Schools_Logger::import_completed($this->provider_name, $imported, $duration_ms);

            return [
                'success' => true,
                'message' => sprintf('Imported %d district boundaries from NCES EDGE', $imported),
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
     * Get count of districts for a state.
     *
     * @since 0.3.0
     * @param string $state_fips State FIPS code.
     * @return int District count.
     */
    private function get_district_count($state_fips) {
        $url = $this->build_query_url($state_fips, [
            'returnCountOnly' => 'true',
        ]);

        $response = $this->http_get($url);

        if (is_wp_error($response)) {
            return 0;
        }

        $data = $this->parse_json($response);

        if (is_wp_error($data)) {
            return 0;
        }

        return isset($data['count']) ? intval($data['count']) : 0;
    }

    /**
     * Fetch districts from the API.
     *
     * @since 0.3.0
     * @param string $state_fips      State FIPS code.
     * @param int    $limit           Results limit.
     * @param int    $offset          Results offset.
     * @param bool   $include_geometry Include boundary geometry.
     * @return array|WP_Error Districts or error.
     */
    private function fetch_districts($state_fips, $limit, $offset, $include_geometry = true) {
        $params = [
            'resultRecordCount' => $limit,
            'resultOffset' => $offset,
            'orderByFields' => 'NAME',
        ];

        if (!$include_geometry) {
            $params['returnGeometry'] = 'false';
        }

        $url = $this->build_query_url($state_fips, $params);

        $response = $this->http_get($url);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = $this->parse_json($response);

        if (is_wp_error($data)) {
            return $data;
        }

        if (!isset($data['features'])) {
            return new WP_Error('invalid_response', 'No features in API response');
        }

        return $data['features'];
    }

    /**
     * Build API query URL.
     *
     * @since 0.3.0
     * @param string $state_fips State FIPS code.
     * @param array  $extra_params Additional parameters.
     * @return string Query URL.
     */
    private function build_query_url($state_fips, $extra_params = []) {
        $base_url = $this->api_base . $this->service_layer . '/MapServer/0/query';

        $params = array_merge([
            'where' => "STATEFP='{$state_fips}'",
            'outFields' => '*',
            'f' => 'geojson',
        ], $extra_params);

        return $base_url . '?' . http_build_query($params);
    }

    /**
     * Import a single district.
     *
     * @since 0.3.0
     * @param array $feature GeoJSON feature.
     * @return bool Success.
     */
    private function import_district($feature) {
        if (empty($feature['properties'])) {
            return false;
        }

        $props = $feature['properties'];

        // Map NCES EDGE fields to our schema
        $district_data = [
            'nces_district_id' => $this->extract_nces_id($props),
            'state_district_id' => $props['GEOID'] ?? null,
            'name' => $props['NAME'] ?? null,
            'type' => $this->map_district_type($props),
            'grades_low' => $props['LOGRADE'] ?? null,
            'grades_high' => $props['HIGRADE'] ?? null,
            'state' => 'MA',
        ];

        // Add geometry if present
        if (!empty($feature['geometry'])) {
            $district_data['boundary_geojson'] = wp_json_encode($feature['geometry']);
        }

        if (empty($district_data['name'])) {
            return false;
        }

        $result = $this->upsert_district($district_data);

        return !is_wp_error($result);
    }

    /**
     * Extract NCES district ID from properties.
     *
     * The GEOID is typically in format: SSLLLLLLL (2 state + 5-7 local)
     * The NCES LEA ID is the local portion.
     *
     * @since 0.3.0
     * @param array $props Feature properties.
     * @return string|null NCES ID.
     */
    private function extract_nces_id($props) {
        // Try LEAID first (direct NCES ID)
        if (!empty($props['LEAID'])) {
            return $props['LEAID'];
        }

        // Extract from GEOID
        if (!empty($props['GEOID'])) {
            // GEOID format: SSNNNNNNN (state + district number)
            // For NCES, we typically need just the numeric portion
            return $props['GEOID'];
        }

        return null;
    }

    /**
     * Map district type from properties.
     *
     * @since 0.3.0
     * @param array $props Feature properties.
     * @return string District type.
     */
    private function map_district_type($props) {
        // SDTYP field indicates school district type
        // 00 = Local school district or equivalent
        // 01 = Component of a supervisory union
        // 02 = Supervisory union administrative center
        // 03 = Consolidated school district
        // 04 = Regional school district
        // 05 = State-operated school district
        // 06 = Federally operated school district
        // 07 = Charter school district

        $sdtyp = $props['SDTYP'] ?? null;

        $types = [
            '00' => 'local',
            '01' => 'component',
            '02' => 'supervisory_union',
            '03' => 'consolidated',
            '04' => 'regional',
            '05' => 'state_operated',
            '06' => 'federal',
            '07' => 'charter',
        ];

        return isset($types[$sdtyp]) ? $types[$sdtyp] : 'local';
    }

    /**
     * Get district boundary by NCES ID.
     *
     * @since 0.3.0
     * @param string $nces_id NCES district ID.
     * @return array|null GeoJSON geometry or null.
     */
    public function get_boundary($nces_id) {
        $table = $this->db->get_table('districts');

        $geojson = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT boundary_geojson FROM {$table} WHERE nces_district_id = %s OR state_district_id = %s",
            $nces_id,
            $nces_id
        ));

        if ($geojson) {
            return json_decode($geojson, true);
        }

        return null;
    }

    /**
     * Find district containing a point.
     *
     * Note: This is a simplified point-in-polygon check.
     * For production, consider using MySQL spatial functions or PostGIS.
     *
     * @since 0.3.0
     * @param float $lat Latitude.
     * @param float $lng Longitude.
     * @return array|null District data or null.
     */
    public function find_district_for_point($lat, $lng) {
        $table = $this->db->get_table('districts');

        // Get all districts with boundaries
        $districts = $this->wpdb->get_results(
            "SELECT id, name, nces_district_id, state_district_id, boundary_geojson
             FROM {$table}
             WHERE boundary_geojson IS NOT NULL
             AND state = 'MA'"
        );

        foreach ($districts as $district) {
            $geometry = json_decode($district->boundary_geojson, true);

            if ($this->point_in_geometry($lat, $lng, $geometry)) {
                return [
                    'id' => $district->id,
                    'name' => $district->name,
                    'nces_district_id' => $district->nces_district_id,
                    'state_district_id' => $district->state_district_id,
                ];
            }
        }

        return null;
    }

    /**
     * Check if a point is inside a GeoJSON geometry.
     *
     * @since 0.3.0
     * @param float $lat      Latitude.
     * @param float $lng      Longitude.
     * @param array $geometry GeoJSON geometry.
     * @return bool True if point is inside.
     */
    private function point_in_geometry($lat, $lng, $geometry) {
        if (empty($geometry['type']) || empty($geometry['coordinates'])) {
            return false;
        }

        switch ($geometry['type']) {
            case 'Polygon':
                return $this->point_in_polygon($lat, $lng, $geometry['coordinates'][0]);

            case 'MultiPolygon':
                foreach ($geometry['coordinates'] as $polygon) {
                    if ($this->point_in_polygon($lat, $lng, $polygon[0])) {
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Ray casting algorithm for point-in-polygon.
     *
     * @since 0.3.0
     * @param float $lat    Latitude (y).
     * @param float $lng    Longitude (x).
     * @param array $polygon Array of [lng, lat] coordinates.
     * @return bool True if point is inside.
     */
    private function point_in_polygon($lat, $lng, $polygon) {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            if ((($yi > $lat) !== ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
