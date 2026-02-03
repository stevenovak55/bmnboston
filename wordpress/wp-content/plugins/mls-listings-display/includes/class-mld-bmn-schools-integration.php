<?php
/**
 * BMN Schools Integration
 *
 * Connects MLS Listings Display with BMN Schools plugin
 * for enhanced school data on property pages.
 *
 * @package MLS_Listings_Display
 * @since 6.28.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class MLD_BMN_Schools_Integration
 *
 * Provides integration with BMN Schools plugin for property pages.
 *
 * @since 6.28.0
 */
class MLD_BMN_Schools_Integration {

    /**
     * Cache expiration in seconds (30 minutes)
     */
    const CACHE_EXPIRATION = 1800;

    /**
     * Constructor
     */
    public function __construct() {
        // Add action to display schools on property detail pages
        add_action('mld_after_property_features', [$this, 'display_property_schools'], 10, 1);

        // Add shortcode for manual placement
        add_shortcode('mld_property_schools', [$this, 'property_schools_shortcode']);

        // Register AJAX handler for lazy loading
        add_action('wp_ajax_mld_get_bmn_schools', [$this, 'ajax_get_bmn_schools']);
        add_action('wp_ajax_nopriv_mld_get_bmn_schools', [$this, 'ajax_get_bmn_schools']);

        // Enqueue schools assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_schools_assets']);
    }

    /**
     * Internal REST API dispatch - avoids HTTP overhead.
     *
     * This method dispatches REST API requests internally using WordPress's
     * rest_do_request() instead of wp_remote_get(). This is critical for
     * performance as it:
     * - Avoids HTTP connection overhead
     * - Doesn't consume additional PHP-FPM workers
     * - Is much faster (no network round-trip)
     *
     * @since 6.30.12
     * @param string $route  REST route (e.g., 'bmn-schools/v1/property/schools')
     * @param array  $params Query parameters
     * @return array|false Decoded response data or false on error
     */
    private function internal_rest_request($route, $params = []) {
        $request = new WP_REST_Request('GET', '/' . $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        if ($response->is_error()) {
            return false;
        }

        $data = $response->get_data();

        if (empty($data['success']) || empty($data['data'])) {
            return false;
        }

        return $data['data'];
    }

    /**
     * Enqueue CSS and JS for enhanced schools section
     */
    public function enqueue_schools_assets() {
        // Enqueue on property detail pages
        wp_enqueue_style(
            'mld-schools',
            MLD_PLUGIN_URL . 'assets/css/mld-schools.css',
            [],
            defined('MLD_VERSION') ? MLD_VERSION : '6.54.0'
        );

        wp_enqueue_script(
            'mld-schools-glossary',
            MLD_PLUGIN_URL . 'assets/js/mld-schools-glossary.js',
            [],
            defined('MLD_VERSION') ? MLD_VERSION : '6.54.0',
            true
        );

        // Enqueue Chart.js for trend charts (v6.54.0)
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Enqueue school comparison and trends script (v6.54.1 - cache bust)
        wp_enqueue_script(
            'mld-schools-compare-trends',
            MLD_PLUGIN_URL . 'assets/js/mld-schools-compare-trends.js',
            ['chartjs'],
            '6.54.1',
            true
        );

        // Localize script with API base
        wp_localize_script('mld-schools-compare-trends', 'mldSchoolsConfig', [
            'apiBase' => rest_url('bmn-schools/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Check if BMN Schools plugin is active
     *
     * @return bool
     */
    public function is_bmn_schools_active() {
        return defined('BMN_SCHOOLS_VERSION');
    }

    /**
     * Get schools for a property location
     *
     * @param float $latitude  Property latitude
     * @param float $longitude Property longitude
     * @param float $radius    Search radius in miles (default 2)
     * @return array|false Schools data or false on error
     */
    public function get_schools_for_location($latitude, $longitude, $radius = 2.0) {
        if (!$latitude || !$longitude) {
            return false;
        }

        // Check cache first
        $cache_key = 'mld_bmn_schools_' . md5("{$latitude},{$longitude},{$radius}");
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Use internal REST dispatch (avoids HTTP overhead and PHP-FPM worker consumption)
        $data = $this->internal_rest_request('bmn-schools/v1/property/schools', [
            'lat' => $latitude,
            'lng' => $longitude,
            'distance' => $radius,
        ]);

        if (!$data) {
            return false;
        }

        set_transient($cache_key, $data, self::CACHE_EXPIRATION);

        return $data;
    }

    /**
     * Get ALL schools in the property's school district
     *
     * This method fetches all schools belonging to the same district as the property,
     * rather than just nearby schools within a radius. This provides a complete view
     * of the educational options available within the school district.
     *
     * @since 6.30.4
     * @param float $latitude  Property latitude
     * @param float $longitude Property longitude
     * @return array|false Schools data or false on error
     */
    public function get_schools_for_district($latitude, $longitude) {
        if (!$latitude || !$longitude) {
            return false;
        }

        // First, get the district for this location
        $district = $this->get_district_for_point($latitude, $longitude);
        if (!$district || empty($district['id'])) {
            // Fallback to radius-based search if no district found
            return $this->get_schools_for_location($latitude, $longitude, 5);
        }

        // Extract district/city name for filtering
        // Most schools have city populated, so we use city filter instead of district_id
        $district_name = $district['name'] ?? '';
        // Remove common suffixes like "School District", "Public Schools"
        $city_name = preg_replace('/\s*(School District|Public Schools)$/i', '', $district_name);
        $city_name = trim($city_name);

        if (empty($city_name)) {
            return $this->get_schools_for_location($latitude, $longitude, 5);
        }

        // Check cache with city name
        $cache_key = 'mld_bmn_district_schools_' . md5("{$latitude},{$longitude},{$city_name}");
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Use internal REST dispatch (avoids HTTP overhead and PHP-FPM worker consumption)
        $data = $this->internal_rest_request('bmn-schools/v1/property/schools', [
            'lat' => $latitude,
            'lng' => $longitude,
            'city' => $city_name,
        ]);

        if (!$data) {
            // Fallback to radius-based search if no data
            return $this->get_schools_for_location($latitude, $longitude, 5);
        }

        // Check if we actually got schools - if not, fallback to radius
        $school_count = 0;
        if (!empty($data['schools'])) {
            foreach ($data['schools'] as $level_schools) {
                $school_count += count($level_schools);
            }
        }

        if ($school_count === 0) {
            // No schools found by city, fallback to radius-based search
            return $this->get_schools_for_location($latitude, $longitude, 5);
        }

        set_transient($cache_key, $data, self::CACHE_EXPIRATION);

        return $data;
    }

    /**
     * Display schools on property detail page
     *
     * @param array|object $property Property data
     */
    public function display_property_schools($property) {
        // Get coordinates
        $latitude = is_object($property) ? ($property->latitude ?? null) : ($property['latitude'] ?? null);
        $longitude = is_object($property) ? ($property->longitude ?? null) : ($property['longitude'] ?? null);

        if (!$latitude || !$longitude) {
            return;
        }

        // Use district-based fetching to show ALL schools in the school district (v6.30.4)
        $schools_data = $this->get_schools_for_district($latitude, $longitude);

        if (!$schools_data) {
            return;
        }

        echo $this->render_schools_section($schools_data);
    }

    /**
     * Shortcode for property schools
     *
     * Usage: [mld_property_schools lat="42.3601" lng="-71.0589" radius="2"]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function property_schools_shortcode($atts) {
        $atts = shortcode_atts([
            'lat' => '',
            'lng' => '',
            'radius' => 5,
            'listing_id' => '',
        ], $atts, 'mld_property_schools');

        // If listing_id provided, get coordinates from that
        if (!empty($atts['listing_id'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'bme_listing_summary';
            $listing = $wpdb->get_row($wpdb->prepare(
                "SELECT latitude, longitude FROM {$table} WHERE listing_key = %s OR listing_id = %s",
                $atts['listing_id'],
                $atts['listing_id']
            ));

            if ($listing) {
                $atts['lat'] = $listing->latitude;
                $atts['lng'] = $listing->longitude;
            }
        }

        if (empty($atts['lat']) || empty($atts['lng'])) {
            return '<!-- No coordinates for schools display -->';
        }

        $schools_data = $this->get_schools_for_location(
            floatval($atts['lat']),
            floatval($atts['lng']),
            floatval($atts['radius'])
        );

        if (!$schools_data) {
            return '<!-- No schools data available -->';
        }

        return $this->render_schools_section($schools_data);
    }

    /**
     * AJAX handler for lazy loading schools
     */
    public function ajax_get_bmn_schools() {
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $radius = floatval($_POST['radius'] ?? 5);

        if (!$lat || !$lng) {
            wp_send_json_error('Missing coordinates');
            return;
        }

        $schools_data = $this->get_schools_for_location($lat, $lng, $radius);

        if (!$schools_data) {
            wp_send_json_error('No schools found');
            return;
        }

        wp_send_json_success([
            'html' => $this->render_schools_section($schools_data),
            'data' => $schools_data,
        ]);
    }

    /**
     * Render schools section HTML
     *
     * @param array $data Schools data from API
     * @return string HTML
     */
    public function render_schools_section($data) {
        ob_start();
        ?>
        <div class="mld-schools-section" style="margin-top: 30px;">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                </svg>
                Nearby Schools
            </h3>

            <?php if (!empty($data['district'])): ?>
            <div class="mld-school-district" style="background: #f8fafc; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #0891b2;">
                <div style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">School District</div>
                <div style="font-size: 16px; font-weight: 500; color: #1e293b;"><?php echo esc_html($data['district']['name']); ?></div>
            </div>
            <?php endif; ?>

            <div class="mld-schools-grid" style="display: grid; gap: 16px;">
                <?php
                $levels = [
                    'elementary' => ['title' => 'Elementary Schools', 'color' => '#22c55e', 'icon' => 'E'],
                    'middle' => ['title' => 'Middle Schools', 'color' => '#3b82f6', 'icon' => 'M'],
                    'high' => ['title' => 'High Schools', 'color' => '#a855f7', 'icon' => 'H'],
                ];

                foreach ($levels as $level => $config):
                    $schools = $data['schools'][$level] ?? [];
                    if (empty($schools)) continue;
                ?>
                <div class="mld-schools-level">
                    <h4 style="font-size: 14px; font-weight: 600; color: <?php echo $config['color']; ?>; margin-bottom: 12px;">
                        <?php echo $config['title']; ?>
                    </h4>
                    <?php foreach ($schools as $school): ?>
                    <div class="mld-school-card" style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: <?php echo $config['color']; ?>20; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <span style="font-weight: 600; color: <?php echo $config['color']; ?>; font-size: 14px;"><?php echo $config['icon']; ?></span>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 500; color: #1e293b; margin-bottom: 4px;"><?php echo esc_html($school['name']); ?></div>
                            <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; color: #64748b;">
                                <span title="Distance">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
                                    </svg>
                                    <?php echo number_format($school['distance'], 1); ?> mi
                                </span>
                                <?php if (!empty($school['mcas_proficient_pct'])): ?>
                                <span title="MCAS Proficiency" style="color: #22c55e;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 4px;">
                                        <path d="M12 20V10M18 20V4M6 20v-4"/>
                                    </svg>
                                    <?php echo round($school['mcas_proficient_pct']); ?>% Proficient
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($school['grades'])): ?>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Grades <?php echo esc_html($school['grades']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php
                $total = count($data['schools']['elementary'] ?? []) +
                         count($data['schools']['middle'] ?? []) +
                         count($data['schools']['high'] ?? []);
                if ($total === 0):
                ?>
                <div style="text-align: center; padding: 20px; color: #64748b;">
                    No schools found within 5 miles
                </div>
                <?php endif; ?>
            </div>

            <div style="margin-top: 16px; font-size: 12px; color: #94a3b8; text-align: right;">
                School data provided by BMN Schools
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ========================================================================
    // ENHANCED SCHOOLS SECTION RENDERING (v6.30.0)
    // Matches iOS NearbySchoolsSection
    // ========================================================================

    /**
     * Level configuration for styling
     */
    const LEVEL_CONFIG = [
        'elementary' => ['title' => 'Elementary Schools', 'colorClass' => 'elementary', 'icon' => 'E'],
        'middle' => ['title' => 'Middle Schools', 'colorClass' => 'middle', 'icon' => 'M'],
        'high' => ['title' => 'High Schools', 'colorClass' => 'high', 'icon' => 'H'],
    ];

    /**
     * Glossary terms to display
     */
    const GLOSSARY_TERMS = [
        'composite-score' => 'Composite Score',
        'letter-grades' => 'Letter Grades',
        'mcas' => 'MCAS',
        'masscore' => 'MassCore',
        'percentile' => 'Percentile Rank',
    ];

    /**
     * Render enhanced schools section HTML (iOS parity)
     *
     * @param array $data Schools data from API
     * @return string HTML
     */
    public function render_enhanced_schools_section($data) {
        ob_start();
        ?>
        <div class="mld-schools-section" id="mld-nearby-schools">
            <h3 class="mld-schools-heading">
                <?php echo $this->get_graduation_cap_svg(); ?>
                Nearby Schools
            </h3>

            <?php if (!empty($data['district'])): ?>
                <?php echo $this->render_district_card($data['district']); ?>
            <?php endif; ?>

            <div class="mld-schools-levels">
                <?php
                $total_schools = 0;
                foreach (self::LEVEL_CONFIG as $level => $config):
                    $schools = $data['schools'][$level] ?? [];
                    if (empty($schools)) continue;
                    $total_schools += count($schools);
                    echo $this->render_school_level_section($level, $schools, $config);
                endforeach;

                if ($total_schools === 0):
                ?>
                <div class="mld-schools-unavailable">
                    <?php echo $this->get_school_icon_svg(); ?>
                    <p>No schools found within 5 miles</p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($total_schools > 0): ?>
                <?php echo $this->render_glossary_links(); ?>

                <!-- Compare Schools floating button (v6.54.0) -->
                <div class="mld-compare-schools-bar" id="mld-compare-bar" style="display: none;">
                    <span class="mld-compare-count">0 schools selected</span>
                    <button type="button" class="mld-compare-btn" id="mld-compare-btn" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="9"></rect>
                            <rect x="14" y="3" width="7" height="9"></rect>
                            <line x1="10" y1="8" x2="14" y2="8"></line>
                        </svg>
                        Compare Schools
                    </button>
                    <button type="button" class="mld-compare-clear" id="mld-compare-clear">Clear</button>
                </div>
            <?php endif; ?>

            <div class="mld-schools-footer">
                School data provided by BMN Schools
            </div>
        </div>

        <?php echo $this->render_glossary_modal(); ?>
        <?php echo $this->render_comparison_modal(); ?>
        <?php echo $this->render_trends_modal(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render district card
     */
    private function render_district_card($district) {
        $name = $district['name'] ?? '';
        $type = $district['type'] ?? '';
        $ranking = $district['ranking'] ?? null;
        $discipline = $district['discipline'] ?? null;
        $college_outcomes = $district['college_outcomes'] ?? null;

        ob_start();
        ?>
        <div class="mld-district-card">
            <div class="mld-district-icon">
                <?php echo $this->get_map_icon_svg(); ?>
            </div>
            <div class="mld-district-info">
                <div class="mld-district-label">School District</div>
                <div class="mld-district-name">
                    <?php echo esc_html($name); ?>
                    <?php if ($type): ?>
                        <span class="mld-district-type"><?php echo esc_html($type); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($ranking && !empty($ranking['letter_grade'])): ?>
            <div class="mld-district-ranking">
                <?php echo $this->render_grade_badge($ranking['letter_grade']); ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($college_outcomes): ?>
            <?php echo $this->render_college_outcomes($college_outcomes); ?>
        <?php endif; ?>

        <?php if ($discipline): ?>
            <?php echo $this->render_discipline_data($discipline); ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render college outcomes section
     */
    private function render_college_outcomes($outcomes) {
        $year = $outcomes['year'] ?? '';
        $total_pct = $outcomes['total_postsecondary_pct'] ?? 0;
        $four_year = $outcomes['four_year_pct'] ?? 0;
        $two_year = $outcomes['two_year_pct'] ?? 0;
        $out_of_state = $outcomes['out_of_state_pct'] ?? 0;
        $employed = $outcomes['employed_pct'] ?? 0;

        ob_start();
        ?>
        <div class="mld-district-outcomes">
            <div class="mld-outcomes-header">
                <span class="mld-outcomes-icon">🎓</span>
                <span class="mld-outcomes-title">Where Graduates Go</span>
                <?php if ($year): ?>
                    <span class="mld-outcomes-year">Class of <?php echo esc_html($year); ?></span>
                <?php endif; ?>
            </div>
            <div class="mld-outcomes-main">
                <span class="mld-outcomes-pct"><?php echo round($total_pct); ?>%</span>
                <span class="mld-outcomes-label">attend college</span>
            </div>
            <div class="mld-outcomes-breakdown">
                <span class="mld-outcome-pill" style="--pill-color: #9333ea;">
                    <span class="mld-pill-value"><?php echo round($four_year); ?>%</span>
                    <span class="mld-pill-label">4-Year</span>
                </span>
                <span class="mld-outcome-pill" style="--pill-color: #6366f1;">
                    <span class="mld-pill-value"><?php echo round($two_year); ?>%</span>
                    <span class="mld-pill-label">2-Year</span>
                </span>
                <span class="mld-outcome-pill" style="--pill-color: #3b82f6;">
                    <span class="mld-pill-value"><?php echo round($out_of_state); ?>%</span>
                    <span class="mld-pill-label">Out of State</span>
                </span>
                <span class="mld-outcome-pill" style="--pill-color: #14b8a6;">
                    <span class="mld-pill-value"><?php echo round($employed); ?>%</span>
                    <span class="mld-pill-label">Working</span>
                </span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render discipline data section
     */
    private function render_discipline_data($discipline) {
        $year = $discipline['year'] ?? '';
        $rate = $discipline['discipline_rate'] ?? null;
        $oss = $discipline['out_of_school_suspension_pct'] ?? 0;
        $iss = $discipline['in_school_suspension_pct'] ?? 0;
        $expulsion = $discipline['expulsion_pct'] ?? 0;
        $emergency = $discipline['emergency_removal_pct'] ?? 0;
        $percentile_label = $discipline['percentile_label'] ?? null;

        // Determine if low discipline (below 3%)
        $is_low = $rate !== null && $rate < 3.0;
        $state_avg = 5.5; // Approximate state average

        // Get summary text - prefer API percentile label, fall back to computed
        if ($percentile_label) {
            $summary = $percentile_label;
        } elseif ($rate === null) {
            $summary = 'Discipline data available';
        } elseif ($rate < 2) {
            $summary = 'Very low discipline rate';
        } elseif ($rate < 5) {
            $summary = 'Low discipline rate';
        } elseif ($rate < 10) {
            $summary = 'Average discipline rate';
        } else {
            $summary = 'Above average incidents';
        }

        $color_class = $is_low ? 'mld-discipline-low' : 'mld-discipline-avg';

        ob_start();
        ?>
        <div class="mld-district-discipline <?php echo esc_attr($color_class); ?>">
            <div class="mld-discipline-header">
                <span class="mld-discipline-icon"><?php echo $is_low ? '✓' : '⚠'; ?></span>
                <span class="mld-discipline-title">School Safety</span>
                <?php if ($year): ?>
                    <span class="mld-discipline-year"><?php echo esc_html($year - 1); ?>-<?php echo esc_html($year % 100); ?></span>
                <?php endif; ?>
            </div>
            <div class="mld-discipline-main">
                <?php if ($rate !== null): ?>
                    <span class="mld-discipline-rate"><?php echo number_format($rate, 1); ?>%</span>
                <?php endif; ?>
                <span class="mld-discipline-summary"><?php echo esc_html($summary); ?></span>
            </div>
            <?php if ($oss > 0 || $iss > 0 || $expulsion > 0 || $emergency > 0): ?>
            <div class="mld-discipline-breakdown">
                <?php if ($oss > 0): ?>
                <span class="mld-discipline-pill" style="--pill-color: #f97316;">
                    <span class="mld-pill-value"><?php echo number_format($oss, 1); ?>%</span>
                    <span class="mld-pill-label">Suspensions</span>
                </span>
                <?php endif; ?>
                <?php if ($iss > 0): ?>
                <span class="mld-discipline-pill" style="--pill-color: #eab308;">
                    <span class="mld-pill-value"><?php echo number_format($iss, 1); ?>%</span>
                    <span class="mld-pill-label">In-School</span>
                </span>
                <?php endif; ?>
                <?php if ($expulsion > 0): ?>
                <span class="mld-discipline-pill" style="--pill-color: #ef4444;">
                    <span class="mld-pill-value"><?php echo number_format($expulsion, 1); ?>%</span>
                    <span class="mld-pill-label">Expulsions</span>
                </span>
                <?php endif; ?>
                <?php if ($emergency > 0): ?>
                <span class="mld-discipline-pill" style="--pill-color: #a855f7;">
                    <span class="mld-pill-value"><?php echo number_format($emergency, 1); ?>%</span>
                    <span class="mld-pill-label">Emergency</span>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($rate !== null): ?>
            <div class="mld-discipline-comparison">
                <?php
                $diff = $rate - $state_avg;
                $is_below = $diff < 0;
                ?>
                <span class="mld-comparison-icon"><?php echo $is_below ? '↓' : '↑'; ?></span>
                <span class="mld-comparison-text">
                    <?php echo number_format(abs($diff), 1); ?>% <?php echo $is_below ? 'below' : 'above'; ?> state avg
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render school level section
     */
    private function render_school_level_section($level, $schools, $config) {
        ob_start();
        ?>
        <div class="mld-school-level">
            <div class="mld-level-header">
                <div class="mld-level-icon <?php echo esc_attr($config['colorClass']); ?>">
                    <span><?php echo esc_html($config['icon']); ?></span>
                </div>
                <h4 class="mld-level-title <?php echo esc_attr($config['colorClass']); ?>">
                    <?php echo esc_html($config['title']); ?>
                </h4>
                <span class="mld-level-count"><?php echo count($schools); ?> school<?php echo count($schools) > 1 ? 's' : ''; ?></span>
            </div>
            <div class="mld-school-cards">
                <?php foreach ($schools as $school): ?>
                    <?php echo $this->render_school_card($school, $config); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render individual school card
     */
    private function render_school_card($school, $config) {
        $school_id = $school['id'] ?? 0;
        $name = $school['name'] ?? '';
        $grades = $school['grades'] ?? '';
        $distance = $school['distance'] ?? 0;
        $ranking = $school['ranking'] ?? null;
        $demographics = $school['demographics'] ?? null;
        $highlights = $school['highlights'] ?? [];
        $level = $school['level'] ?? '';

        // Extract ranking data
        $letter_grade = $ranking['letter_grade'] ?? null;
        $composite_score = $ranking['composite_score'] ?? null;
        $percentile_rank = $ranking['percentile_rank'] ?? null;
        $state_rank = $ranking['state_rank'] ?? null;
        $category_total = $ranking['category_total'] ?? null;
        $trend = $ranking['trend'] ?? null;
        $data_completeness = $ranking['data_completeness'] ?? null;
        $benchmarks = $ranking['benchmarks'] ?? null;
        $percentile_context = $ranking['percentile_context'] ?? null;

        // Fallback to MCAS if no ranking
        $mcas_score = $school['mcas_proficient_pct'] ?? null;

        $grade_class = $this->get_grade_class($letter_grade);

        ob_start();
        ?>
        <div class="mld-school-card" data-school-id="<?php echo esc_attr($school_id); ?>" data-school-name="<?php echo esc_attr($name); ?>" data-school-level="<?php echo esc_attr($level); ?>">
            <div class="mld-school-actions">
                <label class="mld-compare-checkbox" title="Select for comparison">
                    <input type="checkbox" class="mld-compare-check" value="<?php echo esc_attr($school_id); ?>">
                    <span class="mld-compare-checkmark"></span>
                </label>
                <button type="button" class="mld-trends-btn" title="View historical trends" data-school-id="<?php echo esc_attr($school_id); ?>" data-school-name="<?php echo esc_attr($name); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                    </svg>
                </button>
            </div>
            <?php echo $this->render_grade_badge($letter_grade, $config['colorClass']); ?>

            <div class="mld-school-info">
                <div class="mld-school-name-row">
                    <span class="mld-school-name"><?php echo esc_html($name); ?></span>
                    <?php if ($percentile_context): ?>
                        <span class="mld-percentile-badge <?php echo esc_attr($grade_class); ?>">
                            <?php echo esc_html($percentile_context); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mld-school-stats">
                    <span class="mld-stat-item" title="Distance">
                        <?php echo $this->get_location_icon_svg(); ?>
                        <?php echo $distance < 0.1 ? '< 0.1' : number_format($distance, 1); ?> mi
                    </span>

                    <?php if ($state_rank && $category_total): ?>
                    <span class="mld-stat-separator">|</span>
                    <span class="mld-stat-item mld-state-rank" title="State Rank">
                        #<?php echo number_format($state_rank); ?> of <?php echo number_format($category_total); ?>
                    </span>
                    <?php endif; ?>

                    <?php if ($composite_score): ?>
                    <span class="mld-stat-separator">|</span>
                    <span class="mld-stat-item mld-composite-score" title="Composite Score">
                        Score: <?php echo number_format($composite_score, 1); ?>
                    </span>
                    <?php elseif ($mcas_score): ?>
                    <span class="mld-stat-separator">|</span>
                    <span class="mld-stat-item" title="MCAS Proficiency" style="color: #22c55e;">
                        <?php echo round($mcas_score); ?>% Proficient
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($trend && !empty($trend['rank_change_text'])): ?>
                    <?php echo $this->render_trend_indicator($trend); ?>
                <?php endif; ?>

                <?php if ($benchmarks && !empty($benchmarks['vs_state'])): ?>
                    <?php echo $this->render_benchmark($benchmarks); ?>
                <?php endif; ?>

                <?php if ($data_completeness): ?>
                    <?php echo $this->render_data_completeness($data_completeness); ?>
                <?php endif; ?>

                <?php if ($demographics): ?>
                    <?php echo $this->render_demographics_row($demographics); ?>
                <?php endif; ?>

                <?php
                // Sports data (for high schools with MIAA data)
                $sports = $school['sports'] ?? null;
                if ($sports): ?>
                    <?php echo $this->render_sports_row($sports); ?>
                <?php endif; ?>

                <?php if (!empty($highlights)): ?>
                    <?php echo $this->render_highlight_chips($highlights); ?>
                <?php endif; ?>

                <?php if ($grades): ?>
                <div class="mld-school-grades">Grades <?php echo esc_html($grades); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render grade badge
     */
    private function render_grade_badge($grade, $fallback_level = null) {
        $grade_class = $this->get_grade_class($grade);

        ob_start();
        if ($grade):
        ?>
        <div class="mld-grade-badge <?php echo esc_attr($grade_class); ?>">
            <span><?php echo esc_html($grade); ?></span>
        </div>
        <?php else: ?>
        <div class="mld-grade-badge no-grade">
            <?php echo $this->get_school_icon_svg(); ?>
        </div>
        <?php endif;
        return ob_get_clean();
    }

    /**
     * Render trend indicator
     */
    private function render_trend_indicator($trend) {
        $direction = $trend['direction'] ?? 'stable';
        $text = $trend['rank_change_text'] ?? '';

        ob_start();
        ?>
        <div class="mld-trend <?php echo esc_attr($direction); ?>">
            <?php if ($direction === 'up'): ?>
                <?php echo $this->get_arrow_up_svg(); ?>
            <?php elseif ($direction === 'down'): ?>
                <?php echo $this->get_arrow_down_svg(); ?>
            <?php else: ?>
                <?php echo $this->get_minus_svg(); ?>
            <?php endif; ?>
            <span><?php echo esc_html($text); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render benchmark comparison
     */
    private function render_benchmark($benchmarks) {
        $vs_state = $benchmarks['vs_state'] ?? '';
        $is_above = strpos($vs_state, 'above') !== false || strpos($vs_state, '+') === 0;

        ob_start();
        ?>
        <div class="mld-benchmark <?php echo $is_above ? 'above' : 'below'; ?>">
            <?php if ($is_above): ?>
                <?php echo $this->get_chart_up_svg(); ?>
            <?php else: ?>
                <?php echo $this->get_chart_down_svg(); ?>
            <?php endif; ?>
            <span><?php echo esc_html($vs_state); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render data completeness indicator
     */
    private function render_data_completeness($data) {
        $confidence = $data['confidence_level'] ?? 'limited';
        $short_label = $data['short_label'] ?? '';
        $limited_note = $data['limited_data_note'] ?? '';

        ob_start();
        ?>
        <div class="mld-data-completeness <?php echo esc_attr($confidence); ?>">
            <?php if ($confidence === 'comprehensive'): ?>
                <?php echo $this->get_check_seal_svg(); ?>
            <?php elseif ($confidence === 'good'): ?>
                <?php echo $this->get_check_circle_svg(); ?>
            <?php else: ?>
                <?php echo $this->get_exclamation_svg(); ?>
            <?php endif; ?>
            <span><?php echo esc_html($short_label); ?></span>
        </div>

        <?php if ($limited_note): ?>
        <div class="mld-limited-data-note">
            <?php echo $this->get_exclamation_svg(); ?>
            <span><?php echo esc_html($limited_note); ?></span>
        </div>
        <?php endif;

        return ob_get_clean();
    }

    /**
     * Render demographics row
     */
    private function render_demographics_row($demographics) {
        $students = $demographics['students_formatted'] ?? $demographics['total_students'] ?? null;
        $diversity = $demographics['diversity'] ?? null;
        $lunch = $demographics['free_lunch_formatted'] ?? null;

        if (!$students && !$diversity && !$lunch) {
            return '';
        }

        ob_start();
        ?>
        <div class="mld-demographics">
            <?php if ($students): ?>
            <span class="mld-demo-item">
                <?php echo $this->get_people_svg(); ?>
                <?php echo esc_html(is_numeric($students) ? number_format($students) . ' students' : $students); ?>
            </span>
            <?php endif; ?>

            <?php if ($diversity): ?>
            <span class="mld-demo-item mld-diversity">
                <?php echo $this->get_diversity_svg(); ?>
                <?php echo esc_html($diversity); ?>
            </span>
            <?php endif; ?>

            <?php if ($lunch): ?>
            <span class="mld-demo-item">
                <?php echo $this->get_lunch_svg(); ?>
                <?php echo esc_html($lunch); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render sports row (for high schools with MIAA data)
     */
    private function render_sports_row($sports) {
        if (empty($sports) || empty($sports['sports_count'])) {
            return '';
        }

        $count = $sports['sports_count'];
        $total = $sports['total_participants'] ?? 0;
        $is_strong = $count >= 15;

        ob_start();
        ?>
        <div class="mld-sports-row <?php echo $is_strong ? 'strong-athletics' : ''; ?>">
            <span class="mld-sports-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12" y2="8"/>
                </svg>
            </span>
            <span class="mld-sports-text">
                <?php echo esc_html($count); ?> sports
                <?php if ($total > 0): ?>
                    • <?php echo number_format($total); ?> athletes
                <?php endif; ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render highlight chips
     */
    private function render_highlight_chips($highlights) {
        if (empty($highlights)) {
            return '';
        }

        // Limit to 4 highlights
        $highlights = array_slice($highlights, 0, 4);

        ob_start();
        ?>
        <div class="mld-highlights">
            <?php foreach ($highlights as $highlight):
                $type = $highlight['type'] ?? 'default';
                $text = $highlight['short_text'] ?? $highlight['text'] ?? '';
            ?>
            <span class="mld-highlight-chip <?php echo esc_attr($type); ?>">
                <?php echo $this->get_highlight_icon($type); ?>
                <?php echo esc_html($text); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render glossary links section
     */
    private function render_glossary_links() {
        ob_start();
        ?>
        <div class="mld-glossary-section">
            <div class="mld-glossary-title">
                <?php echo $this->get_info_svg(); ?>
                Learn about these ratings
            </div>
            <div class="mld-glossary-chips">
                <?php foreach (self::GLOSSARY_TERMS as $term => $label): ?>
                <button class="mld-glossary-chip" data-term="<?php echo esc_attr($term); ?>">
                    <?php echo $this->get_info_svg(); ?>
                    <?php echo esc_html($label); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render glossary modal (hidden by default)
     */
    private function render_glossary_modal() {
        ob_start();
        ?>
        <div id="mld-glossary-modal" class="mld-glossary-modal" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="mld-modal-overlay"></div>
            <div class="mld-modal-content">
                <button class="mld-modal-close" aria-label="Close">&times;</button>
                <h3 class="mld-modal-term"></h3>
                <p class="mld-modal-fullname"></p>
                <div class="mld-modal-section">
                    <h4 class="mld-modal-section-title">
                        <?php echo $this->get_info_svg(); ?>
                        What is it?
                    </h4>
                    <div class="mld-modal-description"></div>
                </div>
                <div class="mld-parent-tip" style="display: none;">
                    <div class="mld-parent-tip-label">
                        <?php echo $this->get_lightbulb_svg(); ?>
                        Parent Tip
                    </div>
                    <p class="mld-parent-tip-text"></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get CSS class for letter grade
     */
    private function get_grade_class($grade) {
        if (!$grade) return 'no-grade';

        $grade_lower = strtolower($grade);
        $first_char = $grade_lower[0];

        // Handle A+, A, A-
        if ($first_char === 'a') {
            if (strpos($grade_lower, '+') !== false) return 'grade-a-plus';
            if (strpos($grade_lower, '-') !== false) return 'grade-a-minus';
            return 'grade-a';
        }
        // Handle B+, B, B-
        if ($first_char === 'b') {
            if (strpos($grade_lower, '+') !== false) return 'grade-b-plus';
            if (strpos($grade_lower, '-') !== false) return 'grade-b-minus';
            return 'grade-b';
        }
        // Handle C+, C, C-
        if ($first_char === 'c') {
            if (strpos($grade_lower, '+') !== false) return 'grade-c-plus';
            if (strpos($grade_lower, '-') !== false) return 'grade-c-minus';
            return 'grade-c';
        }
        // Handle D+, D, D-
        if ($first_char === 'd') {
            if (strpos($grade_lower, '+') !== false) return 'grade-d-plus';
            if (strpos($grade_lower, '-') !== false) return 'grade-d-minus';
            return 'grade-d';
        }

        return 'grade-f';
    }

    /**
     * Get highlight icon based on type
     */
    private function get_highlight_icon($type) {
        switch ($type) {
            case 'ap':
            case 'masscore':
                return '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5z M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
            case 'ratio':
            case 'resources':
                return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
            case 'diversity':
                return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
            case 'graduation':
            case 'attendance':
                return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            default:
                return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        }
    }

    // ========================================================================
    // SVG ICONS
    // ========================================================================

    private function get_graduation_cap_svg() {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>';
    }

    private function get_map_icon_svg() {
        return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>';
    }

    private function get_school_icon_svg() {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';
    }

    private function get_location_icon_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    }

    private function get_arrow_up_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>';
    }

    private function get_arrow_down_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>';
    }

    private function get_minus_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    }

    private function get_chart_up_svg() {
        return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>';
    }

    private function get_chart_down_svg() {
        return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>';
    }

    private function get_check_seal_svg() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>';
    }

    private function get_check_circle_svg() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    }

    private function get_exclamation_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }

    private function get_people_svg() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
    }

    private function get_diversity_svg() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
    }

    private function get_lunch_svg() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>';
    }

    private function get_info_svg() {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
    }

    private function get_lightbulb_svg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7z"/></svg>';
    }

    // ========================================================================
    // PROPERTY SEARCH SCHOOL FILTERS (Phase 4)
    // ========================================================================

    /**
     * Letter grade to minimum score mapping
     */
    const GRADE_MIN_SCORES = [
        'A+' => 97,
        'A'  => 90,  // A- and above
        'B+' => 87,
        'B'  => 80,  // B- and above
        'C+' => 77,
        'C'  => 70,  // C- and above
    ];

    /**
     * Get the latest year with ranking data (v6.31.8 fix)
     * Fixes bug where date('Y') returned 2026 but rankings are from 2025
     *
     * @return int Latest year with ranking data, or current year minus 1 as fallback
     */
    private function get_latest_ranking_year() {
        static $latest_year = null;

        if ($latest_year !== null) {
            return $latest_year;
        }

        $cache_key = 'mld_latest_ranking_year';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $latest_year = (int) $cached;
            return $latest_year;
        }

        global $wpdb;
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

        // Get the most recent year that has ranking data
        $result = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");

        if ($result) {
            $latest_year = (int) $result;
        } else {
            // Fallback to previous year if no rankings exist
            $latest_year = (int) date('Y') - 1;
        }

        // Cache for 24 hours (year rarely changes)
        set_transient($cache_key, $latest_year, 86400);

        return $latest_year;
    }

    /**
     * Get cached top-rated schools with coordinates
     *
     * @param string $level School level: 'elementary', 'middle', 'high', or null for all
     * @param string $min_grade Minimum letter grade (default 'A')
     * @return array Array of school objects with id, lat, lng, percentile_rank
     */
    public function get_top_schools_cached($level = null, $min_grade = 'A') {
        $cache_key = 'mld_top_schools_' . ($level ?: 'all') . '_' . $min_grade;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;

        // Convert grade to minimum percentile
        $min_percentile = $this->grade_to_min_percentile($min_grade);
        $year = $this->get_latest_ranking_year();

        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
        $schools_table = $wpdb->prefix . 'bmn_schools';

        $sql = "SELECT r.school_id, s.latitude, s.longitude, r.percentile_rank, r.composite_score, s.level
                FROM {$rankings_table} r
                JOIN {$schools_table} s ON r.school_id = s.id
                WHERE r.year = %d
                AND r.percentile_rank >= %d
                AND s.latitude IS NOT NULL
                AND s.longitude IS NOT NULL";

        $params = [$year, $min_percentile];

        if ($level) {
            $sql .= " AND s.level = %s";
            $params[] = $level;
        }

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        // Cache for 1 hour (school rankings rarely change)
        set_transient($cache_key, $results, 3600);

        return $results ?: [];
    }

    /**
     * Convert letter grade to minimum percentile
     *
     * @param string $grade Letter grade
     * @return int Minimum percentile for that grade
     */
    private function grade_to_min_percentile($grade) {
        // Matches BMN Schools grading thresholds
        $map = [
            'A+' => 90, 'A' => 80, 'A-' => 70,
            'B+' => 60, 'B' => 50, 'B-' => 40,
            'C+' => 30, 'C' => 20, 'C-' => 10,
            'D' => 5, 'F' => 0,
        ];
        return $map[$grade] ?? 80;
    }

    /**
     * Check if a property is near a top-rated school
     *
     * @param float $lat Property latitude
     * @param float $lng Property longitude
     * @param string $level School level: 'elementary', 'middle', 'high'
     * @param float $radius_miles Maximum distance in miles
     * @param string $min_grade Minimum letter grade (default 'A')
     * @return bool True if property is near a qualifying school
     */
    public function property_near_top_school($lat, $lng, $level, $radius_miles = 2.0, $min_grade = 'A') {
        if (!$lat || !$lng) {
            return false;
        }

        $top_schools = $this->get_top_schools_cached($level, $min_grade);

        foreach ($top_schools as $school) {
            $distance = $this->haversine_distance($lat, $lng, $school->latitude, $school->longitude);
            if ($distance <= $radius_miles) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get best nearby school grade for a property
     * Uses direct database query for performance (avoids REST API calls)
     *
     * @param float $lat Property latitude
     * @param float $lng Property longitude
     * @param float $radius_miles Maximum distance in miles
     * @return string|null Best letter grade found, or null if no schools nearby
     */
    public function get_best_nearby_school_grade($lat, $lng, $radius_miles = 2.0) {
        if (!$lat || !$lng) {
            return null;
        }

        // Check cache first (grid-based caching for nearby lookups)
        $grid_lat = round($lat, 2); // ~0.7 mile grid
        $grid_lng = round($lng, 2);
        $cache_key = "mld_best_school_{$grid_lat}_{$grid_lng}_{$radius_miles}";
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;

        $year = $this->get_latest_ranking_year();
        $schools_table = $wpdb->prefix . 'bmn_schools';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

        // Direct query to find best-rated school within radius
        // Use percentile_rank to determine grade (letter_grade not stored in DB)
        $sql = "SELECT r.percentile_rank, r.composite_score
                FROM {$schools_table} s
                JOIN {$rankings_table} r ON s.id = r.school_id
                WHERE r.year = %d
                AND s.latitude IS NOT NULL
                AND s.longitude IS NOT NULL
                AND (3959 * ACOS(
                    COS(RADIANS(%f)) * COS(RADIANS(s.latitude)) *
                    COS(RADIANS(s.longitude) - RADIANS(%f)) +
                    SIN(RADIANS(%f)) * SIN(RADIANS(s.latitude))
                )) <= %f
                ORDER BY r.percentile_rank DESC
                LIMIT 1";

        $result = $wpdb->get_row($wpdb->prepare(
            $sql,
            $year, $lat, $lng, $lat, $radius_miles
        ));

        // Convert percentile to letter grade
        $best_grade = $result ? $this->percentile_to_grade($result->percentile_rank) : null;

        // Cache result for 30 minutes
        set_transient($cache_key, $best_grade ?: '', 1800);

        return $best_grade;
    }

    /**
     * DEPRECATED (v6.68.15): This method is no longer used
     *
     * This was a helper for the deprecated get_district_average_grade_by_city() method.
     * The system now uses get_district_grade_for_city() which queries the district_rankings
     * table directly for more accurate grades that match the API display.
     *
     * @deprecated 6.68.15 No longer used - district grades come from district_rankings table
     * @return array Map of district_id => letter_grade
     */
    /*
    private function get_all_district_averages() {
        static $district_averages = null;

        if ($district_averages !== null) {
            return $district_averages;
        }

        $cache_key = 'mld_all_district_averages';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $district_averages = $cached;
            return $district_averages;
        }

        global $wpdb;

        $schools_table = $wpdb->prefix . 'bmn_schools';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
        $year = $this->get_latest_ranking_year();

        // Get average percentile for ALL districts in one query
        $sql = "SELECT s.district_id, AVG(r.percentile_rank) as avg_percentile
                FROM {$schools_table} s
                JOIN {$rankings_table} r ON s.id = r.school_id
                WHERE s.district_id IS NOT NULL
                AND r.year = %d
                GROUP BY s.district_id";

        $results = $wpdb->get_results($wpdb->prepare($sql, $year));

        $district_averages = [];
        foreach ($results as $row) {
            // v6.68.8: Removed round() to fix grade inflation bug
            // 69.8 percentile should be B+ (not A-), so use raw value
            $district_averages[$row->district_id] = $this->percentile_to_grade($row->avg_percentile);
        }

        // Cache for 1 hour
        set_transient($cache_key, $district_averages, 3600);

        return $district_averages;
    }
    */

    /**
     * Get city to district mapping (cached)
     * Maps city names to district IDs for fast lookup
     *
     * @return array Map of lowercase city name => district_id
     */
    private function get_city_to_district_map() {
        static $city_map = null;

        if ($city_map !== null) {
            return $city_map;
        }

        $cache_key = 'mld_city_to_district_map';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $city_map = $cached;
            return $city_map;
        }

        global $wpdb;

        $districts_table = $wpdb->prefix . 'bmn_school_districts';

        // Get all districts and extract city name from district name
        // District names are like "Boston", "Wellesley", "Cambridge School District"
        $sql = "SELECT id, name FROM {$districts_table}";
        $districts = $wpdb->get_results($sql);

        // First pass: collect districts with simple names (no suffix)
        $city_map = [];
        foreach ($districts as $d) {
            $name = strtolower(trim($d->name));
            // Skip charter schools, academies, regional districts
            if (preg_match('/charter|academy|regional/i', $name)) {
                continue;
            }
            // Only add simple names (no "school district" suffix) in first pass
            if (!preg_match('/school district|public schools/i', $name)) {
                $city_map[$name] = $d->id;
            }
        }

        // Second pass: add districts with suffix ONLY if city not already mapped
        foreach ($districts as $d) {
            $name = strtolower(trim($d->name));
            if (preg_match('/charter|academy|regional/i', $name)) {
                continue;
            }
            // Clean the name by removing suffixes
            $city = preg_replace('/ school district$/i', '', $name);
            $city = preg_replace('/ public schools$/i', '', $city);
            $city = trim($city);

            // Only add if not already in map (prefer simple names)
            if (!empty($city) && !isset($city_map[$city])) {
                $city_map[$city] = $d->id;
            }
        }

        // Cache for 1 hour
        set_transient($cache_key, $city_map, 3600);

        return $city_map;
    }

    /**
     * DEPRECATED (v6.68.15): Use get_district_grade_for_city() instead
     *
     * This method averaged school percentiles which gave inconsistent results compared
     * to the district_rankings table used by the API display. For example:
     * - Norfolk: This method returned A- (79% avg), but API showed B+ (62% composite)
     * - Dartmouth: This method returned A- (69.8% avg), but API showed B+ (68% composite)
     *
     * The new method uses district_rankings.composite_score for consistency with
     * what users see on property detail pages.
     *
     * @deprecated 6.68.15 Use get_district_grade_for_city() instead
     * @param string $city Property city name
     * @return string|null Average letter grade for the district, or null if not found
     */
    /*
    public function get_district_average_grade_by_city($city) {
        if (empty($city)) {
            return null;
        }

        // Get pre-cached data
        $district_averages = $this->get_all_district_averages();
        $city_map = $this->get_city_to_district_map();

        if (empty($city_map) || empty($district_averages)) {
            return null;
        }

        // Look up district by city name
        $city_lower = strtolower(trim($city));
        if (!isset($city_map[$city_lower])) {
            return null;
        }

        $district_id = $city_map[$city_lower];
        if (!isset($district_averages[$district_id])) {
            return null;
        }

        return $district_averages[$district_id];
    }
    */

    /**
     * Get district grade and percentile for a city (v6.30.0)
     * Returns full district grade info including precise grade and percentile
     *
     * @param string $city City name
     * @return array|null ['grade' => 'A', 'percentile' => 92, 'district_id' => 123] or null
     */
    public function get_district_grade_for_city($city) {
        if (empty($city)) {
            return null;
        }

        $city_lower = strtolower(trim($city));
        $city_map = $this->get_city_to_district_map();

        if (!isset($city_map[$city_lower])) {
            return null;
        }

        $district_id = $city_map[$city_lower];

        // Query district ranking for composite score
        global $wpdb;
        $ranking = $wpdb->get_row($wpdb->prepare(
            "SELECT composite_score, letter_grade
             FROM {$wpdb->prefix}bmn_district_rankings
             WHERE district_id = %d
             ORDER BY year DESC LIMIT 1",
            $district_id
        ));

        if (!$ranking || !$ranking->composite_score) {
            return null;
        }

        // Calculate percentile from composite score
        $percentile = $this->get_district_percentile_from_score($ranking->composite_score);

        // Get precise grade from percentile (A+, A, A-, B+, etc.)
        $grade = $this->percentile_to_grade($percentile);

        return [
            'grade' => $grade,
            'percentile' => $percentile,
            'district_id' => $district_id,
        ];
    }

    /**
     * Calculate district percentile from composite score (v6.30.0)
     *
     * @param float $composite_score The district's composite score
     * @return int Percentile rank (0-100)
     */
    private function get_district_percentile_from_score($composite_score) {
        global $wpdb;

        // Cache all district scores for percentile calculation
        static $all_scores = null;
        if ($all_scores === null) {
            $cache_key = 'mld_district_scores_for_percentile';
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                $all_scores = $cached;
            } else {
                $all_scores = $wpdb->get_col(
                    "SELECT composite_score FROM {$wpdb->prefix}bmn_district_rankings
                     WHERE year = (SELECT MAX(year) FROM {$wpdb->prefix}bmn_district_rankings)
                     AND composite_score IS NOT NULL
                     ORDER BY composite_score ASC"
                );
                set_transient($cache_key, $all_scores, 3600); // Cache for 1 hour
            }
        }

        if (empty($all_scores)) {
            return 50; // Default to middle if no data
        }

        $below_count = 0;
        foreach ($all_scores as $score) {
            if ((float) $score < (float) $composite_score) {
                $below_count++;
            }
        }

        return (int) round(($below_count / count($all_scores)) * 100);
    }

    /**
     * Get school district average grade for a property location
     * DEPRECATED: Use get_district_average_grade_by_city instead
     *
     * @param float $lat Property latitude
     * @param float $lng Property longitude
     * @return string|null Average letter grade for the district, or null if not found
     */
    public function get_district_average_grade($lat, $lng) {
        // This method is kept for backwards compatibility but won't work well
        // since most schools don't have district_id set
        return null;
    }

    /**
     * Convert percentile rank to letter grade
     * MUST MATCH BMN Schools Ranking Calculator thresholds!
     *
     * @param int $percentile Percentile rank (0-100)
     * @return string Letter grade
     */
    private function percentile_to_grade($percentile) {
        // Matches BMN_Schools_Ranking_Calculator::get_letter_grade_from_percentile()
        if ($percentile >= 90) return 'A+';
        if ($percentile >= 80) return 'A';
        if ($percentile >= 70) return 'A-';
        if ($percentile >= 60) return 'B+';
        if ($percentile >= 50) return 'B';
        if ($percentile >= 40) return 'B-';
        if ($percentile >= 30) return 'C+';
        if ($percentile >= 20) return 'C';
        if ($percentile >= 10) return 'C-';
        if ($percentile >= 5) return 'D';
        return 'F';
    }

    /**
     * Filter properties by school criteria
     *
     * @param array $properties Array of property objects
     * @param array $criteria Filter criteria
     * @return array Filtered properties
     */
    public function filter_properties_by_school_criteria($properties, $criteria) {
        if (empty($properties) || empty($criteria)) {
            return $properties;
        }

        $filtered = [];

        foreach ($properties as $property) {
            // Handle both camelCase and PascalCase property names
            // Traditional path returns 'Latitude'/'Longitude', optimized path may return lowercase
            if (is_object($property)) {
                $lat = $property->latitude ?? $property->Latitude ?? null;
                $lng = $property->longitude ?? $property->Longitude ?? null;
            } else {
                $lat = $property['latitude'] ?? $property['Latitude'] ?? null;
                $lng = $property['longitude'] ?? $property['Longitude'] ?? null;
            }

            // Skip properties without coordinates
            if (!$lat || !$lng) {
                continue;
            }

            $passes = true;

            // Filter by district average grade (average of ALL schools in the district)
            if (!empty($criteria['school_grade']) && $passes) {
                // Get city from property object (handle both camelCase and PascalCase)
                // Traditional path returns 'City', optimized path returns 'city'
                $city = null;
                if (is_object($property)) {
                    $city = $property->city ?? $property->City ?? null;
                } else {
                    $city = $property['city'] ?? $property['City'] ?? null;
                }
                // v6.68.15: Use get_district_grade_for_city() for consistency with API display
                // This uses district_rankings table (same as property detail page shows)
                $district_info = $this->get_district_grade_for_city($city);
                $district_grade = $district_info ? $district_info['grade'] : null;
                $passes = $district_grade && $this->grade_meets_minimum($district_grade, $criteria['school_grade']);
            }

            // Legacy filter: near top elementary (2mi, A-rated)
            if (!empty($criteria['near_top_elementary']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'elementary', 2.0, 'A');
            }

            // Legacy filter: near top high school (3mi, A-rated)
            if (!empty($criteria['near_top_high']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'high', 3.0, 'A');
            }

            // ============================================================
            // NEW Level-Specific Filters (v6.29.4) - 1 mile radius
            // ============================================================
            // Note: 'A-' means A+, A, and A- (percentile >= 70)
            //       'B-' means B+, B, B-, and all A grades (percentile >= 40)

            // Elementary (K-4): within 1 mile of A-rated (includes A+, A, A-)
            if (!empty($criteria['near_a_elementary']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'elementary', 1.0, 'A-');
            }

            // Elementary (K-4): within 1 mile of A or B rated (includes all B and A grades)
            if (!empty($criteria['near_ab_elementary']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'elementary', 1.0, 'B-');
            }

            // Middle (4-8): within 1 mile of A-rated (includes A+, A, A-)
            if (!empty($criteria['near_a_middle']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'middle', 1.0, 'A-');
            }

            // Middle (4-8): within 1 mile of A or B rated (includes all B and A grades)
            if (!empty($criteria['near_ab_middle']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'middle', 1.0, 'B-');
            }

            // High (9-12): within 1 mile of A-rated (includes A+, A, A-)
            if (!empty($criteria['near_a_high']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'high', 1.0, 'A-');
            }

            // High (9-12): within 1 mile of A or B rated (includes all B and A grades)
            if (!empty($criteria['near_ab_high']) && $passes) {
                $passes = $this->property_near_top_school($lat, $lng, 'high', 1.0, 'B-');
            }

            // Filter by school district
            if (!empty($criteria['school_district_id']) && $passes) {
                $district = $this->get_district_for_point($lat, $lng);
                $passes = $district && $district['id'] == $criteria['school_district_id'];
            }

            if ($passes) {
                $filtered[] = $property;
            }
        }

        return $filtered;
    }

    /**
     * Check if a grade meets minimum requirement
     *
     * @param string $grade The grade to check (e.g., 'A', 'B+')
     * @param string $min_grade Minimum required grade
     * @return bool True if grade meets or exceeds minimum
     */
    private function grade_meets_minimum($grade, $min_grade) {
        $grade_order = ['A+' => 12, 'A' => 11, 'A-' => 10, 'B+' => 9, 'B' => 8, 'B-' => 7,
                        'C+' => 6, 'C' => 5, 'C-' => 4, 'D+' => 3, 'D' => 2, 'D-' => 1, 'F' => 0];

        // When min_grade is a single letter (A, B, C, D), include all variants of that letter
        // So "A" means A+, A, A- all pass; "B" means B+, B, B- all pass, etc.
        // Treat single letter as the "-" variant for comparison
        if (strlen($min_grade) === 1 && $min_grade !== 'F') {
            $min_grade = $min_grade . '-';
        }

        $grade_value = $grade_order[$grade] ?? 0;
        $min_value = $grade_order[$min_grade] ?? 0;

        return $grade_value >= $min_value;
    }

    /**
     * Get district for a geographic point
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @return array|null District data or null
     */
    public function get_district_for_point($lat, $lng) {
        if (!$lat || !$lng) {
            return null;
        }

        $cache_key = 'mld_district_' . md5("{$lat},{$lng}");
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached ?: null;
        }

        // Use internal REST dispatch (avoids HTTP overhead and PHP-FPM worker consumption)
        $district = $this->internal_rest_request('bmn-schools/v1/districts/for-point', [
            'lat' => $lat,
            'lng' => $lng,
        ]);

        if (!$district) {
            set_transient($cache_key, '', 300); // Cache failure for 5 min
            return null;
        }

        set_transient($cache_key, $district, self::CACHE_EXPIRATION);

        return $district;
    }

    /**
     * Calculate distance between two points using Haversine formula
     *
     * @param float $lat1 First latitude
     * @param float $lng1 First longitude
     * @param float $lat2 Second latitude
     * @param float $lng2 Second longitude
     * @return float Distance in miles
     */
    private function haversine_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 3959; // miles

        $lat1_rad = deg2rad($lat1);
        $lat2_rad = deg2rad($lat2);
        $delta_lat = deg2rad($lat2 - $lat1);
        $delta_lng = deg2rad($lng2 - $lng1);

        $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lng / 2) * sin($delta_lng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earth_radius * $c;
    }

    /**
     * Check if any school filters are active
     *
     * @param array $criteria Filter criteria
     * @return bool True if any school filter is set
     */
    public static function has_school_filters($criteria) {
        return !empty($criteria['school_grade']) ||
               !empty($criteria['near_top_elementary']) ||
               !empty($criteria['near_top_high']) ||
               !empty($criteria['school_district_id']) ||
               // New level-specific filters (v6.29.3)
               !empty($criteria['near_a_elementary']) ||
               !empty($criteria['near_ab_elementary']) ||
               !empty($criteria['near_a_middle']) ||
               !empty($criteria['near_ab_middle']) ||
               !empty($criteria['near_a_high']) ||
               !empty($criteria['near_ab_high']);
    }

    /**
     * Render comparison modal (v6.54.0)
     *
     * @return string HTML
     */
    private function render_comparison_modal() {
        ob_start();
        ?>
        <div class="mld-comparison-modal" id="mld-comparison-modal" role="dialog" aria-modal="true" aria-labelledby="mld-comparison-title" aria-hidden="true">
            <div class="mld-modal-overlay"></div>
            <div class="mld-modal-content mld-comparison-content">
                <div class="mld-modal-header">
                    <h3 class="mld-modal-title" id="mld-comparison-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <rect x="3" y="3" width="7" height="9"></rect>
                            <rect x="14" y="3" width="7" height="9"></rect>
                            <line x1="10" y1="8" x2="14" y2="8"></line>
                        </svg>
                        Compare Schools
                    </h3>
                    <button class="mld-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="mld-comparison-loading">
                    <div class="mld-spinner"></div>
                    <span>Loading comparison...</span>
                </div>
                <div class="mld-comparison-error" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span class="mld-error-message">Failed to load comparison data</span>
                    <button type="button" class="mld-retry-btn">Try Again</button>
                </div>
                <div class="mld-comparison-body" style="display: none;">
                    <div class="mld-comparison-table-wrapper">
                        <table class="mld-comparison-table">
                            <thead id="mld-comparison-header"></thead>
                            <tbody id="mld-comparison-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render trends modal (v6.54.0)
     *
     * @return string HTML
     */
    private function render_trends_modal() {
        ob_start();
        ?>
        <div class="mld-trends-modal" id="mld-trends-modal" role="dialog" aria-modal="true" aria-labelledby="mld-trends-title" aria-hidden="true">
            <div class="mld-modal-overlay"></div>
            <div class="mld-modal-content mld-trends-content">
                <div class="mld-modal-header">
                    <h3 class="mld-modal-title" id="mld-trends-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                        <span id="mld-trends-school-name">Historical Trends</span>
                    </h3>
                    <button class="mld-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="mld-trends-loading">
                    <div class="mld-spinner"></div>
                    <span>Loading trends...</span>
                </div>
                <div class="mld-trends-error" style="display: none;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span class="mld-error-message">Failed to load trend data</span>
                    <button type="button" class="mld-retry-btn">Try Again</button>
                </div>
                <div class="mld-trends-body" style="display: none;">
                    <div class="mld-subject-filter">
                        <button type="button" class="mld-subject-btn active" data-subject="all">All Subjects</button>
                        <button type="button" class="mld-subject-btn" data-subject="ela">ELA</button>
                        <button type="button" class="mld-subject-btn" data-subject="math">Math</button>
                        <button type="button" class="mld-subject-btn" data-subject="science">Science</button>
                    </div>
                    <div class="mld-trends-charts" id="mld-trends-charts">
                        <!-- Charts will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get the singleton instance for filtering
     *
     * @return MLD_BMN_Schools_Integration
     */
    public static function get_instance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }
}

// Initialize the integration
new MLD_BMN_Schools_Integration();
