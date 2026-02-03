<?php
/**
 * Platform Integration Class
 *
 * Provides hooks and filters for integrating with other BMN plugins
 * and the iOS app.
 *
 * @package BMN_Schools
 * @since 0.5.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Integration Class
 *
 * @since 0.5.0
 */
class BMN_Schools_Integration {

    /**
     * Database manager instance.
     *
     * @var BMN_Schools_Database_Manager
     */
    private $db;

    /**
     * Constructor.
     *
     * @since 0.5.0
     */
    public function __construct() {
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
        $this->db = new BMN_Schools_Database_Manager();
    }

    /**
     * Initialize hooks and filters.
     *
     * @since 0.5.0
     */
    public function init() {
        // Register filters for MLS plugin integration
        add_filter('bmn_schools_for_location', [$this, 'get_schools_for_location'], 10, 2);
        add_filter('bmn_schools_for_property', [$this, 'get_schools_for_property'], 10, 2);
        add_filter('bmn_district_for_location', [$this, 'get_district_for_location'], 10, 2);

        // Register shortcodes
        add_shortcode('bmn_nearby_schools', [$this, 'shortcode_nearby_schools']);
        add_shortcode('bmn_school_info', [$this, 'shortcode_school_info']);
        add_shortcode('bmn_district_info', [$this, 'shortcode_district_info']);
        add_shortcode('bmn_top_schools', [$this, 'shortcode_top_schools']);

        // Enqueue frontend assets when shortcodes are used
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
    }

    /**
     * Get schools near a location.
     *
     * Usage in MLS plugin:
     * $schools = apply_filters('bmn_schools_for_location', [], [
     *     'latitude' => 42.3601,
     *     'longitude' => -71.0589,
     *     'radius' => 1,
     *     'limit' => 5
     * ]);
     *
     * @since 0.5.0
     * @param array $schools Default empty array.
     * @param array $args    Location arguments.
     * @return array Schools near the location.
     */
    public function get_schools_for_location($schools, $args) {
        global $wpdb;

        $defaults = [
            'latitude' => null,
            'longitude' => null,
            'radius' => 1, // miles
            'limit' => 10,
            'level' => null,
            'type' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        if (!$args['latitude'] || !$args['longitude']) {
            return $schools;
        }

        $tables = $this->db->get_table_names();

        // Build query with Haversine formula
        $sql = "SELECT s.*,
                (3959 * ACOS(
                    COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                    COS(RADIANS(longitude) - RADIANS(%f)) +
                    SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
                )) AS distance
                FROM {$tables['schools']} s
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL";

        $params = [$args['latitude'], $args['longitude'], $args['latitude']];

        if ($args['level']) {
            $sql .= " AND level = %s";
            $params[] = $args['level'];
        }

        if ($args['type']) {
            $sql .= " AND school_type = %s";
            $params[] = $args['type'];
        }

        $sql .= " HAVING distance <= %f ORDER BY distance ASC LIMIT %d";
        $params[] = $args['radius'];
        $params[] = $args['limit'];

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        return array_map([$this, 'format_school_for_integration'], $results);
    }

    /**
     * Get schools for a property listing.
     *
     * Usage in MLS plugin:
     * $schools = apply_filters('bmn_schools_for_property', [], $listing);
     *
     * @since 0.5.0
     * @param array  $schools Default empty array.
     * @param object $listing Property listing object.
     * @return array Schools data for the property.
     */
    public function get_schools_for_property($schools, $listing) {
        // Extract coordinates from listing
        $lat = null;
        $lng = null;

        if (isset($listing->latitude)) {
            $lat = floatval($listing->latitude);
        } elseif (isset($listing->Latitude)) {
            $lat = floatval($listing->Latitude);
        }

        if (isset($listing->longitude)) {
            $lng = floatval($listing->longitude);
        } elseif (isset($listing->Longitude)) {
            $lng = floatval($listing->Longitude);
        }

        if (!$lat || !$lng) {
            return $schools;
        }

        // Get nearby schools grouped by level
        $all_schools = $this->get_schools_for_location([], [
            'latitude' => $lat,
            'longitude' => $lng,
            'radius' => 2,
            'limit' => 20,
        ]);

        // Get district for location
        $district = $this->get_district_for_location(null, [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        // Group schools by level
        $grouped = [
            'elementary' => [],
            'middle' => [],
            'high' => [],
            'other' => [],
        ];

        foreach ($all_schools as $school) {
            $level = $school['level'] ?? 'other';
            if (!isset($grouped[$level])) {
                $level = 'other';
            }
            if (count($grouped[$level]) < 3) { // Limit 3 per level
                $grouped[$level][] = $school;
            }
        }

        return [
            'district' => $district,
            'schools' => $grouped,
            'coordinates' => [
                'latitude' => $lat,
                'longitude' => $lng,
            ],
        ];
    }

    /**
     * Get district for a location.
     *
     * @since 0.5.0
     * @param mixed $district Default null.
     * @param array $args     Location arguments.
     * @return array|null District data or null.
     */
    public function get_district_for_location($district, $args) {
        global $wpdb;

        $lat = floatval($args['latitude'] ?? 0);
        $lng = floatval($args['longitude'] ?? 0);

        if (!$lat || !$lng) {
            return null;
        }

        $tables = $this->db->get_table_names();

        // Get districts with boundaries
        $districts = $wpdb->get_results(
            "SELECT id, name, nces_district_id, type, boundary_geojson
             FROM {$tables['districts']}
             WHERE boundary_geojson IS NOT NULL
             AND state = 'MA'"
        );

        foreach ($districts as $dist) {
            $geometry = json_decode($dist->boundary_geojson, true);

            if ($this->point_in_geometry($lat, $lng, $geometry)) {
                return [
                    'id' => (int) $dist->id,
                    'name' => $dist->name,
                    'nces_id' => $dist->nces_district_id,
                    'type' => $dist->type,
                ];
            }
        }

        return null;
    }

    /**
     * Format school for integration response.
     *
     * @since 0.5.0
     * @param object $school School database row.
     * @return array Formatted school.
     */
    private function format_school_for_integration($school) {
        global $wpdb;

        $tables = $this->db->get_table_names();

        $formatted = [
            'id' => (int) $school->id,
            'name' => $school->name,
            'type' => $school->school_type,
            'level' => $school->level,
            'grades' => $this->format_grades($school->grades_low, $school->grades_high),
            'address' => $school->address,
            'city' => $school->city,
            'zip' => $school->zip,
            'distance' => isset($school->distance) ? round($school->distance, 2) : null,
            'latitude' => $school->latitude ? (float) $school->latitude : null,
            'longitude' => $school->longitude ? (float) $school->longitude : null,
        ];

        // Get latest MCAS summary
        $mcas = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(proficient_or_above_pct) as avg_proficient
             FROM {$tables['test_scores']}
             WHERE school_id = %d
             AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = %d)",
            $school->id,
            $school->id
        ));

        if ($mcas && $mcas->avg_proficient) {
            $formatted['mcas_proficient_pct'] = round((float) $mcas->avg_proficient, 1);
        }

        return $formatted;
    }

    /**
     * Format grade range.
     *
     * @since 0.5.0
     * @param string $low  Low grade.
     * @param string $high High grade.
     * @return string|null Formatted grades.
     */
    private function format_grades($low, $high) {
        if (!$low && !$high) {
            return null;
        }
        if ($low === $high) {
            return $low;
        }
        return $low . '-' . $high;
    }

    /**
     * Check if point is in geometry.
     *
     * @since 0.5.0
     * @param float $lat      Latitude.
     * @param float $lng      Longitude.
     * @param array $geometry GeoJSON geometry.
     * @return bool True if inside.
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
     * Ray casting point-in-polygon.
     *
     * @since 0.5.0
     * @param float $lat     Latitude.
     * @param float $lng     Longitude.
     * @param array $polygon Polygon coordinates.
     * @return bool True if inside.
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

    /**
     * Shortcode: Display nearby schools.
     *
     * Usage: [bmn_nearby_schools lat="42.36" lng="-71.06" radius="1" limit="5"]
     *
     * @since 0.5.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode_nearby_schools($atts) {
        $atts = shortcode_atts([
            'lat' => '',
            'lng' => '',
            'radius' => 1,
            'limit' => 5,
            'level' => '',
            'show_distance' => 'yes',
            'show_mcas' => 'yes',
        ], $atts);

        if (!$atts['lat'] || !$atts['lng']) {
            return '<p class="bmn-schools-error">Location coordinates required.</p>';
        }

        $schools = $this->get_schools_for_location([], [
            'latitude' => floatval($atts['lat']),
            'longitude' => floatval($atts['lng']),
            'radius' => floatval($atts['radius']),
            'limit' => intval($atts['limit']),
            'level' => $atts['level'] ?: null,
        ]);

        if (empty($schools)) {
            return '<p class="bmn-schools-empty">No schools found nearby.</p>';
        }

        ob_start();
        ?>
        <div class="bmn-schools-list">
            <?php foreach ($schools as $school): ?>
            <div class="bmn-school-card bmn-school-level-<?php echo esc_attr($school['level']); ?>">
                <h4 class="bmn-school-name"><?php echo esc_html($school['name']); ?></h4>
                <div class="bmn-school-meta">
                    <span class="bmn-school-level"><?php echo esc_html(ucfirst($school['level'])); ?></span>
                    <?php if ($school['grades']): ?>
                    <span class="bmn-school-grades">Grades: <?php echo esc_html($school['grades']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bmn-school-details">
                    <?php if ($atts['show_distance'] === 'yes' && $school['distance']): ?>
                    <span class="bmn-school-distance"><?php echo esc_html($school['distance']); ?> mi</span>
                    <?php endif; ?>
                    <?php if ($atts['show_mcas'] === 'yes' && isset($school['mcas_proficient_pct'])): ?>
                    <span class="bmn-school-mcas">MCAS: <?php echo esc_html($school['mcas_proficient_pct']); ?>% proficient</span>
                    <?php endif; ?>
                </div>
                <div class="bmn-school-address">
                    <?php echo esc_html($school['address']); ?>, <?php echo esc_html($school['city']); ?> <?php echo esc_html($school['zip']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Display school info.
     *
     * Usage: [bmn_school_info id="123"]
     *
     * @since 0.5.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode_school_info($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'id' => '',
            'show_mcas' => 'yes',
            'show_trends' => 'no',
        ], $atts);

        if (!$atts['id']) {
            return '<p class="bmn-schools-error">School ID required.</p>';
        }

        $tables = $this->db->get_table_names();
        $school = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['schools']} WHERE id = %d",
            intval($atts['id'])
        ));

        if (!$school) {
            return '<p class="bmn-schools-error">School not found.</p>';
        }

        $mcas_data = [];
        if ($atts['show_mcas'] === 'yes') {
            $mcas_data = $wpdb->get_results($wpdb->prepare(
                "SELECT subject, proficient_or_above_pct
                 FROM {$tables['test_scores']}
                 WHERE school_id = %d
                 AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = %d)
                 ORDER BY subject",
                $school->id,
                $school->id
            ));
        }

        ob_start();
        ?>
        <div class="bmn-school-detail">
            <h3 class="bmn-school-name"><?php echo esc_html($school->name); ?></h3>
            <div class="bmn-school-meta">
                <span class="bmn-school-type"><?php echo esc_html(ucfirst($school->school_type)); ?></span>
                <span class="bmn-school-level"><?php echo esc_html(ucfirst($school->level)); ?></span>
                <?php if ($school->grades_low && $school->grades_high): ?>
                <span class="bmn-school-grades">Grades <?php echo esc_html($school->grades_low); ?>-<?php echo esc_html($school->grades_high); ?></span>
                <?php endif; ?>
            </div>
            <div class="bmn-school-address">
                <strong>Address:</strong> <?php echo esc_html($school->address); ?>, <?php echo esc_html($school->city); ?>, MA <?php echo esc_html($school->zip); ?>
            </div>
            <?php if (!empty($mcas_data)): ?>
            <div class="bmn-school-mcas">
                <h4>MCAS Results</h4>
                <table class="bmn-mcas-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>% Proficient</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mcas_data as $score): ?>
                        <tr>
                            <td><?php echo esc_html($score->subject); ?></td>
                            <td><?php echo esc_html($score->proficient_or_above_pct); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Display district info.
     *
     * Usage: [bmn_district_info id="123"] or [bmn_district_info lat="42.36" lng="-71.06"]
     *
     * @since 0.5.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode_district_info($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'id' => '',
            'lat' => '',
            'lng' => '',
        ], $atts);

        $tables = $this->db->get_table_names();
        $district = null;

        if ($atts['id']) {
            $district = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tables['districts']} WHERE id = %d",
                intval($atts['id'])
            ));
        } elseif ($atts['lat'] && $atts['lng']) {
            $dist_data = $this->get_district_for_location(null, [
                'latitude' => floatval($atts['lat']),
                'longitude' => floatval($atts['lng']),
            ]);
            if ($dist_data) {
                $district = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tables['districts']} WHERE id = %d",
                    $dist_data['id']
                ));
            }
        }

        if (!$district) {
            return '<p class="bmn-schools-error">District not found.</p>';
        }

        // Get school count
        $school_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['schools']} WHERE district_id = %d",
            $district->id
        ));

        ob_start();
        ?>
        <div class="bmn-district-detail">
            <h3 class="bmn-district-name"><?php echo esc_html($district->name); ?></h3>
            <div class="bmn-district-meta">
                <span class="bmn-district-type"><?php echo esc_html(ucfirst($district->type)); ?> District</span>
                <?php if ($school_count > 0): ?>
                <span class="bmn-district-schools"><?php echo esc_html($school_count); ?> Schools</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: Display top schools.
     *
     * Usage: [bmn_top_schools city="Boston" level="elementary" limit="5"]
     *
     * @since 0.5.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode_top_schools($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'city' => '',
            'level' => '',
            'limit' => 10,
        ], $atts);

        $tables = $this->db->get_table_names();

        $where = ['1=1'];
        $params = [];

        if ($atts['city']) {
            $where[] = 's.city = %s';
            $params[] = $atts['city'];
        }

        if ($atts['level']) {
            $where[] = 's.level = %s';
            $params[] = $atts['level'];
        }

        $where_sql = implode(' AND ', $where);
        $params[] = intval($atts['limit']);

        $sql = "SELECT s.id, s.name, s.city, s.level,
                       AVG(ts.proficient_or_above_pct) as avg_proficient
                FROM {$tables['schools']} s
                JOIN {$tables['test_scores']} ts ON s.id = ts.school_id
                WHERE {$where_sql}
                AND ts.year = (SELECT MAX(year) FROM {$tables['test_scores']})
                GROUP BY s.id
                ORDER BY avg_proficient DESC
                LIMIT %d";

        $schools = $wpdb->get_results($wpdb->prepare($sql, $params));

        if (empty($schools)) {
            return '<p class="bmn-schools-empty">No schools found.</p>';
        }

        ob_start();
        ?>
        <div class="bmn-top-schools">
            <ol class="bmn-schools-ranking">
                <?php foreach ($schools as $school): ?>
                <li class="bmn-school-rank-item">
                    <span class="bmn-school-name"><?php echo esc_html($school->name); ?></span>
                    <span class="bmn-school-city"><?php echo esc_html($school->city); ?></span>
                    <span class="bmn-school-score"><?php echo esc_html(round($school->avg_proficient, 1)); ?>%</span>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Maybe enqueue frontend assets.
     *
     * @since 0.5.0
     */
    public function maybe_enqueue_assets() {
        global $post;

        if (!$post) {
            return;
        }

        // Check if any of our shortcodes are used
        $shortcodes = ['bmn_nearby_schools', 'bmn_school_info', 'bmn_district_info', 'bmn_top_schools'];
        $has_shortcode = false;

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $has_shortcode = true;
                break;
            }
        }

        if ($has_shortcode) {
            wp_enqueue_style(
                'bmn-schools-frontend',
                BMN_SCHOOLS_PLUGIN_URL . 'assets/css/frontend.css',
                [],
                BMN_SCHOOLS_VERSION
            );
        }
    }
}
