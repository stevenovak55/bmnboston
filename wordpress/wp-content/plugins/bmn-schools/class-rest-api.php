<?php
/**
 * REST API Class
 *
 * Provides REST API endpoints for school data.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * REST API Class
 *
 * @since 0.1.0
 */
class BMN_Schools_REST_API {

    /**
     * API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'bmn-schools/v1';

    /**
     * Cache manager instance.
     *
     * @var BMN_Schools_Cache_Manager
     */
    private $cache;

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct() {
        // Load cache manager
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-cache-manager.php';
        $this->cache = 'BMN_Schools_Cache_Manager';

        $this->register_routes();
    }

    /**
     * Register REST API routes.
     *
     * @since 0.1.0
     */
    public function register_routes() {
        // Schools endpoints
        register_rest_route(self::NAMESPACE, '/schools', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schools'],
            'permission_callback' => '__return_true',
            'args' => $this->get_schools_args(),
        ]);

        register_rest_route(self::NAMESPACE, '/schools/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_school'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/schools/nearby', [
            'methods' => 'GET',
            'callback' => [$this, 'get_nearby_schools'],
            'permission_callback' => '__return_true',
            'args' => $this->get_nearby_args(),
        ]);

        // Districts endpoints
        register_rest_route(self::NAMESPACE, '/districts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_districts'],
            'permission_callback' => '__return_true',
            'args' => $this->get_districts_args(),
        ]);

        register_rest_route(self::NAMESPACE, '/districts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_district'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/districts/(?P<id>\d+)/boundary', [
            'methods' => 'GET',
            'callback' => [$this, 'get_district_boundary'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/districts/for-point', [
            'methods' => 'GET',
            'callback' => [$this, 'get_district_for_point'],
            'permission_callback' => '__return_true',
            'args' => [
                'lat' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= -90 && $param <= 90;
                    },
                ],
                'lng' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= -180 && $param <= 180;
                    },
                ],
            ],
        ]);

        // Search endpoint
        register_rest_route(self::NAMESPACE, '/search/autocomplete', [
            'methods' => 'GET',
            'callback' => [$this, 'autocomplete'],
            'permission_callback' => '__return_true',
            'args' => [
                'term' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Stats endpoints
        register_rest_route(self::NAMESPACE, '/stats/city/(?P<city>[a-zA-Z\s]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_city_stats'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/stats/zip/(?P<zip>\d{5})', [
            'methods' => 'GET',
            'callback' => [$this, 'get_zip_stats'],
            'permission_callback' => '__return_true',
        ]);

        // Health check
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health_check'],
            'permission_callback' => '__return_true',
        ]);

        // Phase 4: School comparison endpoint
        register_rest_route(self::NAMESPACE, '/schools/compare', [
            'methods' => 'GET',
            'callback' => [$this, 'compare_schools'],
            'permission_callback' => '__return_true',
            'args' => [
                'ids' => [
                    'required' => true,
                    'description' => 'Comma-separated school IDs to compare (2-5 schools)',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Phase 4: MCAS trend analysis endpoint
        register_rest_route(self::NAMESPACE, '/schools/(?P<id>\d+)/trends', [
            'methods' => 'GET',
            'callback' => [$this, 'get_school_trends'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'subject' => [
                    'default' => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'years' => [
                    'default' => 5,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 1 && $param <= 10;
                    },
                ],
            ],
        ]);

        // Phase 4: District trends endpoint
        register_rest_route(self::NAMESPACE, '/districts/(?P<id>\d+)/trends', [
            'methods' => 'GET',
            'callback' => [$this, 'get_district_trends'],
            'permission_callback' => '__return_true',
        ]);

        // Phase 4: Top schools endpoint
        register_rest_route(self::NAMESPACE, '/schools/top', [
            'methods' => 'GET',
            'callback' => [$this, 'get_top_schools'],
            'permission_callback' => '__return_true',
            'args' => [
                'city' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'level' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'default' => 10,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 50;
                    },
                ],
                'metric' => [
                    'default' => 'mcas',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Phase 5: Schools for property (iOS app integration)
        register_rest_route(self::NAMESPACE, '/property/schools', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schools_for_property'],
            'permission_callback' => '__return_true',
            'args' => [
                'lat' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= -90 && $param <= 90;
                    },
                ],
                'lng' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= -180 && $param <= 180;
                    },
                ],
                'radius' => [
                    'default' => 2,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 10;
                    },
                ],
                'city' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Phase 5: Schools map data (for map overlays)
        register_rest_route(self::NAMESPACE, '/schools/map', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schools_for_map'],
            'permission_callback' => '__return_true',
            'args' => [
                'bounds' => [
                    'required' => true,
                    'description' => 'Map bounds: south,west,north,east',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'level' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Phase 4: Glossary endpoint for MA education terms
        register_rest_route(self::NAMESPACE, '/glossary', [
            'methods' => 'GET',
            'callback' => [$this, 'get_glossary'],
            'permission_callback' => '__return_true',
            'args' => [
                'term' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Admin: Geocoding endpoints
        register_rest_route(self::NAMESPACE, '/admin/geocode/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_geocode_status'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/geocode/run', [
            'methods' => 'POST',
            'callback' => [$this, 'run_geocoding'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'limit' => [
                    'default' => 100,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 500;
                    },
                ],
            ],
        ]);
    }

    /**
     * Check if current user has admin permissions.
     *
     * @since 0.5.1
     * @return bool
     */
    public function admin_permission_check() {
        return current_user_can('manage_options');
    }

    /**
     * Get schools list arguments.
     *
     * @since 0.1.0
     * @return array Arguments.
     */
    private function get_schools_args() {
        return [
            'city' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'zip' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'district_id' => [
                'validate_callback' => function($param) {
                    return empty($param) || is_numeric($param);
                },
            ],
            'level' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'bounds' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'default' => 20,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
            ],
            'page' => [
                'default' => 1,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
            ],
        ];
    }

    /**
     * Get nearby schools arguments.
     *
     * @since 0.1.0
     * @return array Arguments.
     */
    private function get_nearby_args() {
        return [
            'lat' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= -90 && $param <= 90;
                },
            ],
            'lng' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= -180 && $param <= 180;
                },
            ],
            'radius' => [
                'default' => 1,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0 && $param <= 50;
                },
            ],
            'level' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'type' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'limit' => [
                'default' => 10,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0 && $param <= 50;
                },
            ],
        ];
    }

    /**
     * Get districts arguments.
     *
     * @since 0.1.0
     * @return array Arguments.
     */
    private function get_districts_args() {
        return [
            'city' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'county' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'default' => 20,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
            ],
            'page' => [
                'default' => 1,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
            ],
        ];
    }

    /**
     * Get schools list.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_schools($request) {
        global $wpdb;

        $tables = bmn_schools()->get_table_names();
        $table = $tables['schools'];

        // Build query
        $where = ['1=1'];
        $params = [];

        // Filter by city
        if ($city = $request->get_param('city')) {
            $where[] = 'city = %s';
            $params[] = $city;
        }

        // Filter by zip
        if ($zip = $request->get_param('zip')) {
            $where[] = 'zip = %s';
            $params[] = $zip;
        }

        // Filter by district
        if ($district_id = $request->get_param('district_id')) {
            $where[] = 'district_id = %d';
            $params[] = $district_id;
        }

        // Filter by level
        if ($level = $request->get_param('level')) {
            $where[] = 'level = %s';
            $params[] = $level;
        }

        // Filter by type
        if ($type = $request->get_param('type')) {
            $where[] = 'school_type = %s';
            $params[] = $type;
        }

        // Filter by map bounds
        if ($bounds = $request->get_param('bounds')) {
            $coords = explode(',', $bounds);
            if (count($coords) === 4) {
                $where[] = 'latitude BETWEEN %f AND %f';
                $where[] = 'longitude BETWEEN %f AND %f';
                $params[] = floatval($coords[0]); // south
                $params[] = floatval($coords[2]); // north
                $params[] = floatval($coords[1]); // west
                $params[] = floatval($coords[3]); // east
            }
        }

        // Pagination
        $per_page = intval($request->get_param('per_page'));
        $page = intval($request->get_param('page'));
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get results
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY name ASC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        // Format results
        $schools = array_map([$this, 'format_school'], $results);

        return new WP_REST_Response([
            'success' => true,
            'data' => $schools,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Get single school.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_school($request) {
        global $wpdb;

        $id = intval($request->get_param('id'));
        $tables = bmn_schools()->get_table_names();

        // Get school
        $school = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['schools']} WHERE id = %d",
            $id
        ));

        if (!$school) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'School not found',
            ], 404);
        }

        // Get additional data
        $formatted = $this->format_school($school, true);

        // Get test scores
        $formatted['test_scores'] = $wpdb->get_results($wpdb->prepare(
            "SELECT year, subject, grade, proficient_or_above_pct, avg_scaled_score
             FROM {$tables['test_scores']}
             WHERE school_id = %d
             ORDER BY year DESC, subject ASC",
            $id
        ));

        // Get rankings (composite scores)
        $ranking = $wpdb->get_row($wpdb->prepare(
            "SELECT year, category, composite_score, percentile_rank, state_rank,
                    mcas_score, graduation_score, masscore_score,
                    attendance_score, ap_score, growth_score,
                    spending_score, ratio_score, calculated_at
             FROM {$tables['rankings']}
             WHERE school_id = %d
             ORDER BY year DESC
             LIMIT 1",
            $id
        ));

        if ($ranking && $ranking->composite_score !== null) {
            // Calculate letter grade based on percentile (not absolute score)
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';
            $letter_grade = BMN_Schools_Ranking_Calculator::get_letter_grade_from_percentile($ranking->percentile_rank);

            $formatted['ranking'] = [
                'year' => (int) $ranking->year,
                'category' => $ranking->category,
                'category_label' => $this->format_category_label($ranking->category),
                'composite_score' => round((float) $ranking->composite_score, 1),
                'percentile_rank' => (int) $ranking->percentile_rank,
                'state_rank' => $ranking->state_rank !== null ? (int) $ranking->state_rank : null,
                'category_total' => $this->get_category_total($ranking->category),
                'letter_grade' => $letter_grade,
                'trend' => $this->get_ranking_trend($id, (int) $ranking->year, $ranking->category),
                'components' => [
                    'mcas' => $ranking->mcas_score !== null ? round((float) $ranking->mcas_score, 1) : null,
                    'graduation' => $ranking->graduation_score !== null ? round((float) $ranking->graduation_score, 1) : null,
                    'masscore' => $ranking->masscore_score !== null ? round((float) $ranking->masscore_score, 1) : null,
                    'attendance' => $ranking->attendance_score !== null ? round((float) $ranking->attendance_score, 1) : null,
                    'ap' => $ranking->ap_score !== null ? round((float) $ranking->ap_score, 1) : null,
                    'growth' => $ranking->growth_score !== null ? round((float) $ranking->growth_score, 1) : null,
                    'spending' => $ranking->spending_score !== null ? round((float) $ranking->spending_score, 1) : null,
                    'ratio' => $ranking->ratio_score !== null ? round((float) $ranking->ratio_score, 1) : null,
                ],
                'calculated_at' => $ranking->calculated_at,
            ];
        } else {
            $formatted['ranking'] = null;
        }

        // Get demographics (most recent)
        $formatted['demographics'] = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['demographics']}
             WHERE school_id = %d
             ORDER BY year DESC
             LIMIT 1",
            $id
        ));

        // Get school highlights (Task 3.2: Expose hidden features)
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';
        $formatted['highlights'] = BMN_Schools_Ranking_Calculator::get_school_highlights($id);

        // Get programs data from features table
        $formatted['programs'] = $this->get_school_programs($id);

        return new WP_REST_Response([
            'success' => true,
            'data' => $formatted,
        ], 200);
    }

    /**
     * Get school programs/pathways data from features table.
     *
     * @param int $school_id School ID.
     * @return array|null Programs data.
     */
    private function get_school_programs($school_id) {
        global $wpdb;
        $features_table = $wpdb->prefix . 'bmn_school_features';

        $programs = [
            'cte_available' => false,
            'cte_programs' => [],
            'early_college' => false,
            'innovation_pathway' => false,
            'ap_courses_offered' => 0,
        ];

        // Get pathways data
        $pathways_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'pathways'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));

        if ($pathways_data) {
            $pathways = json_decode($pathways_data, true);
            $programs['cte_available'] = !empty($pathways['has_cte']) && $pathways['has_cte'];
            $programs['cte_programs'] = !empty($pathways['cte_programs']) ? $pathways['cte_programs'] : [];
            $programs['early_college'] = !empty($pathways['has_early_college']) && $pathways['has_early_college'];
            $programs['innovation_pathway'] = !empty($pathways['has_innovation_pathway']) && $pathways['has_innovation_pathway'];
        }

        // Get AP summary data
        $ap_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'ap_summary'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));

        if ($ap_data) {
            $ap = json_decode($ap_data, true);
            $programs['ap_courses_offered'] = !empty($ap['total_courses']) ? (int) $ap['total_courses'] : 0;
            $programs['ap_pass_rate'] = !empty($ap['pass_rate']) ? round((float) $ap['pass_rate'], 1) : null;
            $programs['ap_participation_rate'] = !empty($ap['participation_rate']) ? round((float) $ap['participation_rate'], 1) : null;
        }

        // Return null if no data
        if (!$programs['cte_available'] && !$programs['early_college'] &&
            !$programs['innovation_pathway'] && $programs['ap_courses_offered'] === 0) {
            return null;
        }

        return $programs;
    }

    /**
     * Get nearby schools.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_nearby_schools($request) {
        global $wpdb;

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        $radius = floatval($request->get_param('radius'));
        $limit = intval($request->get_param('limit'));

        $tables = bmn_schools()->get_table_names();

        // Haversine formula for distance in miles
        $sql = $wpdb->prepare(
            "SELECT *,
             (3959 * ACOS(
                 COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                 COS(RADIANS(longitude) - RADIANS(%f)) +
                 SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
             )) AS distance
             FROM {$tables['schools']}
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             HAVING distance <= %f
             ORDER BY distance ASC
             LIMIT %d",
            $lat,
            $lng,
            $lat,
            $radius,
            $limit
        );

        $results = $wpdb->get_results($sql);

        $schools = array_map(function($school) {
            $formatted = $this->format_school($school);
            $formatted['distance'] = round($school->distance, 2);
            return $formatted;
        }, $results);

        return new WP_REST_Response([
            'success' => true,
            'data' => $schools,
        ], 200);
    }

    /**
     * Get districts list.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_districts($request) {
        global $wpdb;

        $tables = bmn_schools()->get_table_names();
        $table = $tables['districts'];

        $where = ['1=1'];
        $params = [];

        if ($city = $request->get_param('city')) {
            $where[] = 'city = %s';
            $params[] = $city;
        }

        if ($county = $request->get_param('county')) {
            $where[] = 'county = %s';
            $params[] = $county;
        }

        $per_page = intval($request->get_param('per_page'));
        $page = intval($request->get_param('page'));
        $offset = ($page - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);

        $sql = "SELECT id, nces_district_id, name, city, county, total_schools, total_students
                FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY name ASC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        return new WP_REST_Response([
            'success' => true,
            'data' => $results,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Get single district.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_district($request) {
        global $wpdb;

        $id = intval($request->get_param('id'));
        $tables = bmn_schools()->get_table_names();

        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['districts']} WHERE id = %d",
            $id
        ));

        if (!$district) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'District not found',
            ], 404);
        }

        // Get schools in district
        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, level, school_type, address, city
             FROM {$tables['schools']}
             WHERE district_id = %d
             ORDER BY level, name",
            $id
        ));

        $district->schools = $schools;

        return new WP_REST_Response([
            'success' => true,
            'data' => $district,
        ], 200);
    }

    /**
     * Get district boundary GeoJSON.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_district_boundary($request) {
        global $wpdb;

        $id = intval($request->get_param('id'));
        $tables = bmn_schools()->get_table_names();

        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, boundary_geojson FROM {$tables['districts']} WHERE id = %d",
            $id
        ));

        if (!$district) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'District not found',
            ], 404);
        }

        if (empty($district->boundary_geojson)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'no_boundary',
                'message' => 'No boundary data available for this district',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'id' => $district->id,
                'name' => $district->name,
                'boundary' => json_decode($district->boundary_geojson),
            ],
        ], 200);
    }

    /**
     * Get district containing a point.
     *
     * Uses point-in-polygon algorithm to find which district boundary
     * contains the given coordinates.
     *
     * @since 0.3.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_district_for_point($request) {
        global $wpdb;

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));

        $tables = bmn_schools()->get_table_names();

        // Get all districts with boundaries
        $districts = $wpdb->get_results(
            "SELECT id, name, nces_district_id, state_district_id, type, boundary_geojson
             FROM {$tables['districts']}
             WHERE boundary_geojson IS NOT NULL
             AND state = 'MA'"
        );

        foreach ($districts as $district) {
            $geometry = json_decode($district->boundary_geojson, true);

            if ($this->point_in_geometry($lat, $lng, $geometry)) {
                // Found the containing district
                // Get additional info
                $school_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['schools']} WHERE district_id = %d",
                    $district->id
                ));

                return new WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'id' => (int) $district->id,
                        'name' => $district->name,
                        'nces_district_id' => $district->nces_district_id,
                        'state_district_id' => $district->state_district_id,
                        'type' => $district->type,
                        'school_count' => (int) $school_count,
                        'coordinates' => [
                            'latitude' => $lat,
                            'longitude' => $lng,
                        ],
                    ],
                ], 200);
            }
        }

        // No district found
        return new WP_REST_Response([
            'success' => false,
            'code' => 'not_found',
            'message' => 'No district found containing these coordinates',
            'coordinates' => [
                'latitude' => $lat,
                'longitude' => $lng,
            ],
        ], 404);
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
     * @param float $lat     Latitude (y).
     * @param float $lng     Longitude (x).
     * @param array $polygon Array of [lng, lat] coordinates.
     * @return bool True if point is inside.
     */
    private function point_in_polygon($lat, $lng, $polygon) {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0]; // longitude
            $yi = $polygon[$i][1]; // latitude
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
     * Autocomplete search.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function autocomplete($request) {
        global $wpdb;

        $term = $request->get_param('term');
        $tables = bmn_schools()->get_table_names();

        $suggestions = [];

        // Search schools
        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, city, level FROM {$tables['schools']}
             WHERE name LIKE %s
             ORDER BY name ASC
             LIMIT 5",
            '%' . $wpdb->esc_like($term) . '%'
        ));

        foreach ($schools as $school) {
            $suggestions[] = [
                'value' => $school->name,
                'type' => 'School',
                'subtype' => ucfirst($school->level),
                'id' => $school->id,
                'icon' => 'building.2.fill',
            ];
        }

        // Search districts
        $districts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, city FROM {$tables['districts']}
             WHERE name LIKE %s
             ORDER BY name ASC
             LIMIT 3",
            '%' . $wpdb->esc_like($term) . '%'
        ));

        foreach ($districts as $district) {
            $suggestions[] = [
                'value' => $district->name,
                'type' => 'District',
                'id' => $district->id,
                'icon' => 'map.fill',
            ];
        }

        // Search cities
        $cities = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT city FROM {$tables['schools']}
             WHERE city LIKE %s
             ORDER BY city ASC
             LIMIT 3",
            '%' . $wpdb->esc_like($term) . '%'
        ));

        foreach ($cities as $city) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['schools']} WHERE city = %s",
                $city
            ));
            $suggestions[] = [
                'value' => $city,
                'type' => 'City',
                'count' => (int) $count,
                'icon' => 'building.2.fill',
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $suggestions,
        ], 200);
    }

    /**
     * Get city statistics.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_city_stats($request) {
        global $wpdb;

        $city = sanitize_text_field($request->get_param('city'));
        $tables = bmn_schools()->get_table_names();

        $stats = [
            'city' => $city,
            'total_schools' => 0,
            'public_schools' => 0,
            'private_schools' => 0,
            'elementary' => 0,
            'middle' => 0,
            'high' => 0,
        ];

        $counts = $wpdb->get_results($wpdb->prepare(
            "SELECT school_type, level, COUNT(*) as count
             FROM {$tables['schools']}
             WHERE city = %s
             GROUP BY school_type, level",
            $city
        ));

        foreach ($counts as $row) {
            $stats['total_schools'] += $row->count;

            if ($row->school_type === 'public') {
                $stats['public_schools'] += $row->count;
            } else {
                $stats['private_schools'] += $row->count;
            }

            if ($row->level) {
                $stats[$row->level] = ($stats[$row->level] ?? 0) + $row->count;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $stats,
        ], 200);
    }

    /**
     * Get ZIP code statistics.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_zip_stats($request) {
        global $wpdb;

        $zip = sanitize_text_field($request->get_param('zip'));
        $tables = bmn_schools()->get_table_names();

        $stats = [
            'zip' => $zip,
            'total_schools' => 0,
            'schools' => [],
        ];

        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, level, school_type
             FROM {$tables['schools']}
             WHERE zip = %s
             ORDER BY name",
            $zip
        ));

        $stats['total_schools'] = count($schools);
        $stats['schools'] = $schools;

        return new WP_REST_Response([
            'success' => true,
            'data' => $stats,
        ], 200);
    }

    /**
     * Health check endpoint.
     *
     * @since 0.1.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function health_check($request) {
        $db_manager = new BMN_Schools_Database_Manager();
        $table_status = $db_manager->verify_tables();
        $stats = $db_manager->get_stats();

        $all_healthy = true;
        foreach ($table_status as $status) {
            if (!$status['exists']) {
                $all_healthy = false;
                break;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'status' => $all_healthy ? 'healthy' : 'degraded',
                'version' => BMN_SCHOOLS_VERSION,
                'tables' => $table_status,
                'record_counts' => $stats,
            ],
        ], 200);
    }

    /**
     * Format school for API response.
     *
     * @since 0.1.0
     * @param object $school School database row.
     * @param bool   $full   Include all fields.
     * @return array Formatted school.
     */
    private function format_school($school, $full = false) {
        $formatted = [
            'id' => (int) $school->id,
            'name' => $school->name,
            'type' => $school->school_type,
            'level' => $school->level,
            'grades' => $this->format_grades($school->grades_low, $school->grades_high),
            'address' => $school->address,
            'city' => $school->city,
            'state' => $school->state,
            'zip' => $school->zip,
            'latitude' => $school->latitude ? (float) $school->latitude : null,
            'longitude' => $school->longitude ? (float) $school->longitude : null,
        ];

        if ($full) {
            $formatted['nces_id'] = $school->nces_school_id;
            $formatted['state_id'] = $school->state_school_id;
            $formatted['district_id'] = $school->district_id ? (int) $school->district_id : null;
            $formatted['county'] = $school->county;
            $formatted['phone'] = $school->phone;
            $formatted['website'] = $school->website;
            $formatted['enrollment'] = $school->enrollment ? (int) $school->enrollment : null;
            $formatted['student_teacher_ratio'] = $school->student_teacher_ratio ? (float) $school->student_teacher_ratio : null;
        }

        return $formatted;
    }

    /**
     * Format grade range.
     *
     * @since 0.1.0
     * @param string $low  Low grade.
     * @param string $high High grade.
     * @return string Formatted grade range.
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
     * Format category label for display.
     *
     * Converts internal category codes to user-friendly labels.
     *
     * @since 0.6.1
     * @param string $category Category code (e.g., 'public_high').
     * @return string Formatted label (e.g., 'Public High Schools').
     */
    private function format_category_label($category) {
        if (empty($category)) {
            return null;
        }

        $labels = [
            'public_elementary' => 'Public Elementary Schools',
            'public_middle' => 'Public Middle Schools',
            'public_high' => 'Public High Schools',
            'public_other' => 'Public Schools',
            'private_elementary' => 'Private Elementary Schools',
            'private_middle' => 'Private Middle Schools',
            'private_high' => 'Private High Schools',
            'private_other' => 'Private Schools',
        ];

        return $labels[$category] ?? ucwords(str_replace('_', ' ', $category));
    }

    /**
     * Calculate diversity index (0-100) based on racial demographics.
     * Uses Simpson's Diversity Index: higher = more diverse.
     *
     * @param object $demographics Demographics row from database.
     * @return string|null Diversity level label or null if insufficient data.
     */
    private function calculate_diversity_index($demographics) {
        $percentages = [
            (float) ($demographics->pct_white ?? 0),
            (float) ($demographics->pct_black ?? 0),
            (float) ($demographics->pct_hispanic ?? 0),
            (float) ($demographics->pct_asian ?? 0),
            (float) ($demographics->pct_multiracial ?? 0),
        ];

        // Check if we have any data
        $total = array_sum($percentages);
        if ($total < 50) {
            return null; // Insufficient data (should be ~100%)
        }

        // Calculate Simpson's Diversity Index: 1 - sum(p^2)
        $sum_squares = 0;
        foreach ($percentages as $pct) {
            $proportion = $pct / 100;
            $sum_squares += $proportion * $proportion;
        }
        $index = (1 - $sum_squares) * 100;

        // Convert to label
        if ($index >= 70) {
            return 'Very Diverse';
        } elseif ($index >= 50) {
            return 'Diverse';
        } elseif ($index >= 30) {
            return 'Moderate';
        } else {
            return 'Less Diverse';
        }
    }

    /**
     * Get total count of schools in a category for the current year.
     *
     * @param string $category Category name (e.g., 'public_elementary').
     * @return int Total count.
     */
    private function get_category_total($category) {
        if (empty($category)) {
            return 0;
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        // Cache key for category totals (recalculates if not cached)
        static $category_totals = null;

        if ($category_totals === null) {
            $category_totals = [];
            // Get the most recent year
            $current_year = $wpdb->get_var(
                "SELECT MAX(year) FROM {$tables['rankings']} WHERE composite_score IS NOT NULL"
            );
            // Count by category for the current year only
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT category, COUNT(*) as total
                 FROM {$tables['rankings']}
                 WHERE category IS NOT NULL
                 AND composite_score IS NOT NULL
                 AND year = %d
                 GROUP BY category",
                $current_year
            ));
            foreach ($results as $row) {
                $category_totals[$row->category] = (int) $row->total;
            }
        }

        return $category_totals[$category] ?? 0;
    }

    /**
     * Get ranking trend data comparing current year to previous year.
     *
     * @param int $school_id School ID.
     * @param int $current_year Current ranking year.
     * @param string $category School category.
     * @return array|null Trend data or null if no previous data.
     */
    private function get_ranking_trend($school_id, $current_year, $category) {
        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        // Get previous year's ranking for the same school and category
        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT year, composite_score, percentile_rank, state_rank, category
             FROM {$tables['rankings']}
             WHERE school_id = %d
             AND year = %d
             AND category = %s
             AND composite_score IS NOT NULL",
            $school_id, $current_year - 1, $category
        ));

        if (!$previous) {
            return null;
        }

        // Get current year's ranking
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT composite_score, percentile_rank, state_rank
             FROM {$tables['rankings']}
             WHERE school_id = %d
             AND year = %d
             AND category = %s",
            $school_id, $current_year, $category
        ));

        if (!$current) {
            return null;
        }

        // Calculate changes (positive = improvement)
        $rank_change = (int) $previous->state_rank - (int) $current->state_rank; // Lower rank = better
        $score_change = round((float) $current->composite_score - (float) $previous->composite_score, 1);
        $percentile_change = (int) $current->percentile_rank - (int) $previous->percentile_rank;

        // Determine direction based on rank change
        $direction = 'stable';
        if ($rank_change > 0) {
            $direction = 'up';
        } elseif ($rank_change < 0) {
            $direction = 'down';
        }

        return [
            'direction' => $direction,
            'rank_change' => $rank_change,
            'score_change' => $score_change,
            'percentile_change' => $percentile_change,
            'previous_year' => (int) $previous->year,
            'previous_rank' => (int) $previous->state_rank,
            'previous_score' => round((float) $previous->composite_score, 1),
        ];
    }

    /**
     * Compare multiple schools.
     *
     * @since 0.4.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function compare_schools($request) {
        global $wpdb;

        $ids_param = $request->get_param('ids');
        $ids = array_map('intval', explode(',', $ids_param));

        // Validate 2-5 schools
        if (count($ids) < 2) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'invalid_request',
                'message' => 'At least 2 school IDs required for comparison',
            ], 400);
        }

        if (count($ids) > 5) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'invalid_request',
                'message' => 'Maximum 5 schools can be compared at once',
            ], 400);
        }

        // Check cache
        $cache_key = BMN_Schools_Cache_Manager::generate_key('compare', ['ids' => implode(',', $ids)]);
        $cached = BMN_Schools_Cache_Manager::get($cache_key, 'comparison');
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $tables = bmn_schools()->get_table_names();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Get schools
        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['schools']} WHERE id IN ({$placeholders})",
            ...$ids
        ));

        if (count($schools) < 2) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'Not enough valid schools found',
            ], 404);
        }

        $comparison = [];

        foreach ($schools as $school) {
            $school_data = $this->format_school($school, true);

            // Get latest test scores
            $scores = $wpdb->get_results($wpdb->prepare(
                "SELECT subject, proficient_or_above_pct, avg_scaled_score
                 FROM {$tables['test_scores']}
                 WHERE school_id = %d
                 AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = %d)
                 ORDER BY subject",
                $school->id,
                $school->id
            ));

            $school_data['test_scores'] = [];
            foreach ($scores as $score) {
                $school_data['test_scores'][$score->subject] = [
                    'proficient_pct' => (float) $score->proficient_or_above_pct,
                    'avg_score' => (float) $score->avg_scaled_score,
                ];
            }

            // Get demographics
            $demographics = $wpdb->get_row($wpdb->prepare(
                "SELECT total_students, pct_free_reduced_lunch, avg_class_size
                 FROM {$tables['demographics']}
                 WHERE school_id = %d
                 ORDER BY year DESC LIMIT 1",
                $school->id
            ));

            if ($demographics) {
                $school_data['demographics'] = [
                    'enrollment' => (int) $demographics->total_students,
                    'free_reduced_lunch_pct' => (float) $demographics->pct_free_reduced_lunch,
                    'avg_class_size' => (float) $demographics->avg_class_size,
                ];
            }

            $comparison[] = $school_data;
        }

        $response = [
            'success' => true,
            'data' => [
                'schools' => $comparison,
                'comparison_fields' => [
                    'enrollment',
                    'student_teacher_ratio',
                    'test_scores',
                    'demographics',
                ],
            ],
        ];

        // Cache the result
        BMN_Schools_Cache_Manager::set($cache_key, $response, 'comparison');

        return new WP_REST_Response($response, 200);
    }

    /**
     * Get school MCAS trends.
     *
     * @since 0.4.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_school_trends($request) {
        global $wpdb;

        $school_id = intval($request->get_param('id'));
        $subject = $request->get_param('subject');
        $years = intval($request->get_param('years'));

        // Check cache
        $cache_key = BMN_Schools_Cache_Manager::generate_key('trends', [
            'school_id' => $school_id,
            'subject' => $subject,
            'years' => $years,
        ]);
        $cached = BMN_Schools_Cache_Manager::get($cache_key, 'trends');
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $tables = bmn_schools()->get_table_names();

        // Get school info
        $school = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, city FROM {$tables['schools']} WHERE id = %d",
            $school_id
        ));

        if (!$school) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'School not found',
            ], 404);
        }

        // Build query
        $where = "school_id = %d";
        $params = [$school_id];

        if ($subject !== 'all') {
            $where .= " AND subject = %s";
            $params[] = $subject;
        }

        // Get scores ordered by year
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT year, subject, grade, proficient_or_above_pct, avg_scaled_score
             FROM {$tables['test_scores']}
             WHERE {$where}
             ORDER BY year DESC, subject ASC
             LIMIT %d",
            array_merge($params, [$years * 10]) // Allow for multiple subjects/grades
        ));

        // Organize by subject and calculate trends
        $trends = [];
        $by_subject = [];

        foreach ($scores as $score) {
            $subj = $score->subject;
            if (!isset($by_subject[$subj])) {
                $by_subject[$subj] = [];
            }
            $by_subject[$subj][] = [
                'year' => (int) $score->year,
                'grade' => $score->grade,
                'proficient_pct' => (float) $score->proficient_or_above_pct,
                'avg_score' => (float) $score->avg_scaled_score,
            ];
        }

        foreach ($by_subject as $subj => $data) {
            // Sort by year ascending for trend calculation
            usort($data, function($a, $b) {
                return $a['year'] - $b['year'];
            });

            $trend_direction = 'stable';
            $trend_value = 0;

            if (count($data) >= 2) {
                $first = $data[0]['proficient_pct'];
                $last = $data[count($data) - 1]['proficient_pct'];
                $trend_value = $last - $first;

                if ($trend_value > 5) {
                    $trend_direction = 'improving';
                } elseif ($trend_value < -5) {
                    $trend_direction = 'declining';
                }
            }

            $trends[$subj] = [
                'data' => $data,
                'trend' => $trend_direction,
                'change' => round($trend_value, 1),
                'latest' => end($data),
            ];
        }

        $response = [
            'success' => true,
            'data' => [
                'school' => [
                    'id' => (int) $school->id,
                    'name' => $school->name,
                    'city' => $school->city,
                ],
                'trends' => $trends,
                'summary' => $this->calculate_trend_summary($trends),
            ],
        ];

        // Cache the result
        BMN_Schools_Cache_Manager::set($cache_key, $response, 'trends');

        return new WP_REST_Response($response, 200);
    }

    /**
     * Calculate overall trend summary.
     *
     * @since 0.4.0
     * @param array $trends Trends by subject.
     * @return array Summary.
     */
    private function calculate_trend_summary($trends) {
        if (empty($trends)) {
            return [
                'overall' => 'no_data',
                'subjects_improving' => 0,
                'subjects_declining' => 0,
                'subjects_stable' => 0,
            ];
        }

        $improving = 0;
        $declining = 0;
        $stable = 0;

        foreach ($trends as $trend) {
            switch ($trend['trend']) {
                case 'improving':
                    $improving++;
                    break;
                case 'declining':
                    $declining++;
                    break;
                default:
                    $stable++;
            }
        }

        $overall = 'stable';
        if ($improving > $declining && $improving > $stable) {
            $overall = 'improving';
        } elseif ($declining > $improving && $declining > $stable) {
            $overall = 'declining';
        }

        return [
            'overall' => $overall,
            'subjects_improving' => $improving,
            'subjects_declining' => $declining,
            'subjects_stable' => $stable,
        ];
    }

    /**
     * Get district trends.
     *
     * @since 0.4.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_district_trends($request) {
        global $wpdb;

        $district_id = intval($request->get_param('id'));
        $tables = bmn_schools()->get_table_names();

        // Get district info
        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$tables['districts']} WHERE id = %d",
            $district_id
        ));

        if (!$district) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'not_found',
                'message' => 'District not found',
            ], 404);
        }

        // Get aggregated scores for schools in district
        $scores = $wpdb->get_results($wpdb->prepare(
            "SELECT ts.year, ts.subject,
                    AVG(ts.proficient_or_above_pct) as avg_proficient,
                    COUNT(DISTINCT ts.school_id) as school_count
             FROM {$tables['test_scores']} ts
             JOIN {$tables['schools']} s ON ts.school_id = s.id
             WHERE s.district_id = %d
             GROUP BY ts.year, ts.subject
             ORDER BY ts.year DESC, ts.subject",
            $district_id
        ));

        // Organize by subject
        $by_subject = [];
        foreach ($scores as $score) {
            $subj = $score->subject;
            if (!isset($by_subject[$subj])) {
                $by_subject[$subj] = [];
            }
            $by_subject[$subj][] = [
                'year' => (int) $score->year,
                'avg_proficient_pct' => round((float) $score->avg_proficient, 1),
                'schools_reporting' => (int) $score->school_count,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'district' => [
                    'id' => (int) $district->id,
                    'name' => $district->name,
                ],
                'trends' => $by_subject,
            ],
        ], 200);
    }

    /**
     * Get top schools by metric.
     *
     * @since 0.4.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_top_schools($request) {
        global $wpdb;

        $city = $request->get_param('city');
        $level = $request->get_param('level');
        $limit = intval($request->get_param('limit'));
        $metric = $request->get_param('metric');

        // Check cache
        $cache_key = BMN_Schools_Cache_Manager::generate_key('top_schools', [
            'city' => $city,
            'level' => $level,
            'limit' => $limit,
            'metric' => $metric,
        ]);
        $cached = BMN_Schools_Cache_Manager::get($cache_key, 'stats');
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $tables = bmn_schools()->get_table_names();

        $where = ['1=1'];
        $params = [];

        if ($city) {
            $where[] = 's.city = %s';
            $params[] = $city;
        }

        if ($level) {
            $where[] = 's.level = %s';
            $params[] = $level;
        }

        $where_sql = implode(' AND ', $where);

        if ($metric === 'mcas') {
            // Rank by average MCAS proficiency
            $sql = "SELECT s.id, s.name, s.city, s.level, s.school_type,
                           AVG(ts.proficient_or_above_pct) as avg_proficient,
                           COUNT(DISTINCT ts.subject) as subjects_tested
                    FROM {$tables['schools']} s
                    JOIN {$tables['test_scores']} ts ON s.id = ts.school_id
                    WHERE {$where_sql}
                    AND ts.year = (SELECT MAX(year) FROM {$tables['test_scores']})
                    GROUP BY s.id
                    HAVING subjects_tested >= 1
                    ORDER BY avg_proficient DESC
                    LIMIT %d";
            $params[] = $limit;
        } else {
            // Default: by enrollment (as a fallback)
            $sql = "SELECT s.id, s.name, s.city, s.level, s.school_type, s.enrollment
                    FROM {$tables['schools']} s
                    WHERE {$where_sql}
                    AND s.enrollment IS NOT NULL
                    ORDER BY s.enrollment DESC
                    LIMIT %d";
            $params[] = $limit;
        }

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        $schools = [];
        $rank = 1;
        foreach ($results as $school) {
            $school_data = [
                'rank' => $rank++,
                'id' => (int) $school->id,
                'name' => $school->name,
                'city' => $school->city,
                'level' => $school->level,
                'type' => $school->school_type,
            ];

            if ($metric === 'mcas') {
                $school_data['avg_proficient_pct'] = round((float) $school->avg_proficient, 1);
                $school_data['subjects_tested'] = (int) $school->subjects_tested;
            } else {
                $school_data['enrollment'] = (int) $school->enrollment;
            }

            $schools[] = $school_data;
        }

        $response = [
            'success' => true,
            'data' => [
                'metric' => $metric,
                'filters' => [
                    'city' => $city,
                    'level' => $level,
                ],
                'schools' => $schools,
            ],
        ];

        // Cache the result
        BMN_Schools_Cache_Manager::set($cache_key, $response, 'stats');

        return new WP_REST_Response($response, 200);
    }

    /**
     * Get schools for a property location.
     *
     * Optimized for iOS app property detail view.
     * Returns schools grouped by level with district info.
     *
     * @since 0.5.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_schools_for_property($request) {
        global $wpdb;

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        $radius = floatval($request->get_param('radius'));
        $city = $request->get_param('city');

        // Check cache
        $cache_key = BMN_Schools_Cache_Manager::generate_key('property_schools', [
            'lat' => round($lat, 4),
            'lng' => round($lng, 4),
            'radius' => $radius,
            'city' => $city ? strtoupper($city) : '',
        ]);
        $cached = BMN_Schools_Cache_Manager::get($cache_key, 'nearby_schools');
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $tables = bmn_schools()->get_table_names();

        // Build query - filter by city if provided, otherwise use radius
        if ($city) {
            // Filter by city (case-insensitive)
            $sql = $wpdb->prepare(
                "SELECT s.*,
                 (3959 * ACOS(
                     COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                     COS(RADIANS(longitude) - RADIANS(%f)) +
                     SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
                 )) AS distance
                 FROM {$tables['schools']} s
                 WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                 AND UPPER(city) = %s
                 ORDER BY distance ASC
                 LIMIT 30",
                $lat, $lng, $lat, strtoupper($city)
            );
        } else {
            // Filter by radius (legacy behavior)
            $sql = $wpdb->prepare(
                "SELECT s.*,
                 (3959 * ACOS(
                     COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                     COS(RADIANS(longitude) - RADIANS(%f)) +
                     SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
                 )) AS distance
                 FROM {$tables['schools']} s
                 WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                 HAVING distance <= %f
                 ORDER BY distance ASC
                 LIMIT 30",
                $lat, $lng, $lat, $radius
            );
        }

        $results = $wpdb->get_results($sql);

        // Load ranking calculator for highlights and benchmark lookups
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';

        // Group by level
        $grouped = [
            'elementary' => [],
            'middle' => [],
            'high' => [],
        ];

        foreach ($results as $school) {
            $level = $school->level ?: 'other';
            if (!isset($grouped[$level])) {
                continue; // Skip 'other' and 'combined' for cleaner display
            }

            if (count($grouped[$level]) >= 3) {
                continue; // Limit 3 per level
            }

            // Get MCAS score
            $mcas = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(proficient_or_above_pct)
                 FROM {$tables['test_scores']}
                 WHERE school_id = %d
                 AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = %d)",
                $school->id, $school->id
            ));

            // Get ranking data including component scores for data completeness
            $ranking_data = null;
            $ranking = $wpdb->get_row($wpdb->prepare(
                "SELECT year, category, composite_score, percentile_rank, state_rank,
                        mcas_score, graduation_score, masscore_score, attendance_score,
                        ap_score, growth_score, spending_score, ratio_score
                 FROM {$tables['rankings']}
                 WHERE school_id = %d AND composite_score IS NOT NULL
                 ORDER BY year DESC
                 LIMIT 1",
                $school->id
            ));
            if ($ranking && $ranking->composite_score !== null) {
                $trend = $this->get_ranking_trend($school->id, (int) $ranking->year, $ranking->category);

                // Count available components for data completeness
                $components = [
                    'mcas' => $ranking->mcas_score,
                    'graduation' => $ranking->graduation_score,
                    'masscore' => $ranking->masscore_score,
                    'attendance' => $ranking->attendance_score,
                    'ap' => $ranking->ap_score,
                    'growth' => $ranking->growth_score,
                    'spending' => $ranking->spending_score,
                    'ratio' => $ranking->ratio_score,
                ];
                $components_available = 0;
                $components_list = [];
                foreach ($components as $name => $value) {
                    if ($value !== null) {
                        $components_available++;
                        $components_list[] = $name;
                    }
                }

                // Determine confidence level based on data completeness
                $confidence_level = 'limited';
                if ($components_available >= 7) {
                    $confidence_level = 'comprehensive';
                } elseif ($components_available >= 5) {
                    $confidence_level = 'good';
                }

                // Get benchmark comparison
                $school_level = $school->level ?: 'all';
                $benchmark = BMN_Schools_Ranking_Calculator::get_benchmark('composite_score', $school_level, null, (int) $ranking->year);
                $state_benchmark = BMN_Schools_Ranking_Calculator::get_benchmark('composite_score', 'all', null, (int) $ranking->year);

                $benchmark_data = null;
                if ($benchmark || $state_benchmark) {
                    $category_avg = $benchmark ? round((float) $benchmark->state_average, 1) : null;
                    $state_avg = $state_benchmark ? round((float) $state_benchmark->state_average, 1) : null;
                    $score = (float) $ranking->composite_score;

                    $vs_category = null;
                    $vs_state = null;

                    if ($category_avg) {
                        $diff = round($score - $category_avg, 1);
                        $vs_category = $diff >= 0 ? "+{$diff} above average" : "{$diff} below average";
                    }
                    if ($state_avg) {
                        $diff = round($score - $state_avg, 1);
                        $vs_state = $diff >= 0 ? "+{$diff} above state avg" : "{$diff} below state avg";
                    }

                    $benchmark_data = [
                        'category_average' => $category_avg,
                        'state_average' => $state_avg,
                        'vs_category' => $vs_category,
                        'vs_state' => $vs_state,
                    ];
                }

                $ranking_data = [
                    'category' => $ranking->category,
                    'category_label' => $this->format_category_label($ranking->category),
                    'composite_score' => round((float) $ranking->composite_score, 1),
                    'percentile_rank' => (int) $ranking->percentile_rank,
                    'state_rank' => $ranking->state_rank !== null ? (int) $ranking->state_rank : null,
                    'category_total' => $this->get_category_total($ranking->category),
                    'letter_grade' => BMN_Schools_Ranking_Calculator::get_letter_grade_from_percentile($ranking->percentile_rank),
                    'trend' => $trend,
                    'data_completeness' => [
                        'components_available' => $components_available,
                        'components_total' => 8,
                        'confidence_level' => $confidence_level,
                        'components' => $components_list,
                    ],
                    'benchmarks' => $benchmark_data,
                ];
            }

            // Get demographics data
            $demographics_data = null;
            $demographics = $wpdb->get_row($wpdb->prepare(
                "SELECT total_students, pct_free_reduced_lunch, avg_class_size,
                        pct_white, pct_black, pct_hispanic, pct_asian,
                        pct_multiracial, pct_english_learner, pct_special_ed
                 FROM {$tables['demographics']}
                 WHERE school_id = %d
                 ORDER BY year DESC
                 LIMIT 1",
                $school->id
            ));
            if ($demographics) {
                $demographics_data = [
                    'total_students' => $demographics->total_students ? (int) $demographics->total_students : null,
                    'pct_free_reduced_lunch' => $demographics->pct_free_reduced_lunch ? round((float) $demographics->pct_free_reduced_lunch, 1) : null,
                    'avg_class_size' => $demographics->avg_class_size ? round((float) $demographics->avg_class_size, 1) : null,
                    'diversity' => $this->calculate_diversity_index($demographics),
                ];
            }

            // Get highlights for this school
            $highlights = BMN_Schools_Ranking_Calculator::get_school_highlights($school->id);

            $grouped[$level][] = [
                'id' => (int) $school->id,
                'name' => $school->name,
                'grades' => $this->format_grades($school->grades_low, $school->grades_high),
                'distance' => round($school->distance, 2),
                'address' => $school->address,
                'mcas_proficient_pct' => $mcas ? round((float) $mcas, 1) : null,
                'latitude' => (float) $school->latitude,
                'longitude' => (float) $school->longitude,
                'ranking' => $ranking_data,
                'demographics' => $demographics_data,
                'highlights' => !empty($highlights) ? $highlights : null,
            ];
        }

        // Get district
        $district = null;
        $districts = $wpdb->get_results(
            "SELECT id, name, nces_district_id, type, boundary_geojson
             FROM {$tables['districts']}
             WHERE boundary_geojson IS NOT NULL AND state = 'MA'"
        );

        foreach ($districts as $dist) {
            $geometry = json_decode($dist->boundary_geojson, true);
            if ($this->point_in_geometry($lat, $lng, $geometry)) {
                // Get district ranking
                $district_ranking = BMN_Schools_Ranking_Calculator::get_district_ranking($dist->id);

                $district = [
                    'id' => (int) $dist->id,
                    'name' => $dist->name,
                    'type' => $dist->type,
                ];

                if ($district_ranking) {
                    $district['ranking'] = [
                        'composite_score' => round((float) $district_ranking->composite_score, 1),
                        'percentile_rank' => (int) $district_ranking->percentile_rank,
                        'state_rank' => (int) $district_ranking->state_rank,
                        'letter_grade' => $district_ranking->letter_grade,
                        'schools_count' => (int) $district_ranking->schools_count,
                        'elementary_avg' => $district_ranking->elementary_avg ? round((float) $district_ranking->elementary_avg, 1) : null,
                        'middle_avg' => $district_ranking->middle_avg ? round((float) $district_ranking->middle_avg, 1) : null,
                        'high_avg' => $district_ranking->high_avg ? round((float) $district_ranking->high_avg, 1) : null,
                    ];
                }
                break;
            }
        }

        $response = [
            'success' => true,
            'data' => [
                'district' => $district,
                'schools' => $grouped,
                'location' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'radius' => $radius,
                ],
            ],
        ];

        // Cache for 15 minutes
        BMN_Schools_Cache_Manager::set($cache_key, $response, 'nearby_schools');

        return new WP_REST_Response($response, 200);
    }

    /**
     * Get schools for map display.
     *
     * Returns minimal data optimized for map pins.
     *
     * @since 0.5.0
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_schools_for_map($request) {
        global $wpdb;

        $bounds = $request->get_param('bounds');
        $level = $request->get_param('level');

        $coords = explode(',', $bounds);
        if (count($coords) !== 4) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'invalid_bounds',
                'message' => 'Bounds must be: south,west,north,east',
            ], 400);
        }

        $south = floatval($coords[0]);
        $west = floatval($coords[1]);
        $north = floatval($coords[2]);
        $east = floatval($coords[3]);

        $tables = bmn_schools()->get_table_names();

        $where = [
            'latitude BETWEEN %f AND %f',
            'longitude BETWEEN %f AND %f',
            'latitude IS NOT NULL',
            'longitude IS NOT NULL',
        ];
        $params = [$south, $north, $west, $east];

        if ($level) {
            $where[] = 'level = %s';
            $params[] = $level;
        }

        $where_sql = implode(' AND ', $where);
        $params[] = 200; // Limit for performance

        $sql = "SELECT id, name, level, school_type, latitude, longitude
                FROM {$tables['schools']}
                WHERE {$where_sql}
                LIMIT %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        $schools = array_map(function($school) {
            return [
                'id' => (int) $school->id,
                'name' => $school->name,
                'level' => $school->level,
                'type' => $school->school_type,
                'lat' => (float) $school->latitude,
                'lng' => (float) $school->longitude,
            ];
        }, $results);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'schools' => $schools,
                'count' => count($schools),
                'bounds' => [
                    'south' => $south,
                    'west' => $west,
                    'north' => $north,
                    'east' => $east,
                ],
            ],
        ], 200);
    }

    /**
     * Get geocoding status.
     *
     * @since 0.5.1
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_geocode_status($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'bmn_schools';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $with_coords = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE latitude IS NOT NULL AND longitude IS NOT NULL"
        );
        $pending = BMN_Schools_Geocoder::get_pending_count();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'total_schools' => $total,
                'with_coordinates' => $with_coords,
                'pending_geocoding' => $pending,
                'percent_complete' => $total > 0 ? round(($with_coords / $total) * 100, 1) : 0,
                'estimated_time_remaining' => $pending . ' seconds (1 req/sec rate limit)',
            ],
        ], 200);
    }

    /**
     * Run batch geocoding.
     *
     * @since 0.5.1
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function run_geocoding($request) {
        $limit = intval($request->get_param('limit'));

        // Increase execution time limit for batch processing
        set_time_limit($limit * 2 + 30);

        $start_time = microtime(true);

        $stats = BMN_Schools_Geocoder::geocode_schools($limit);

        $duration = round(microtime(true) - $start_time, 2);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'processed' => $stats['total'],
                'success' => $stats['success'],
                'failed' => $stats['failed'],
                'skipped' => $stats['skipped'],
                'duration_seconds' => $duration,
                'remaining' => BMN_Schools_Geocoder::get_pending_count(),
            ],
        ], 200);
    }

    /**
     * Get glossary of Massachusetts education terms.
     *
     * Phase 4: Educational Context & Glossary
     * Helps out-of-state parents understand MA-specific education terminology.
     *
     * @since 0.6.10
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response with glossary terms.
     */
    public function get_glossary($request) {
        $term = $request->get_param('term');

        // Comprehensive glossary of MA education terms
        $glossary = [
            'mcas' => [
                'term' => 'MCAS',
                'full_name' => 'Massachusetts Comprehensive Assessment System',
                'category' => 'testing',
                'description' => 'The statewide standardized test given to all Massachusetts public school students in grades 3-8 and 10. Tests English Language Arts (ELA), Mathematics, and Science (grades 5, 8, 10). Scores are categorized as Exceeding, Meeting, Partially Meeting, or Not Meeting Expectations.',
                'parent_tip' => 'Look for schools where most students "Meet" or "Exceed" expectations. A school with 70%+ Meeting/Exceeding is considered strong.',
            ],
            'masscore' => [
                'term' => 'MassCore',
                'full_name' => 'Massachusetts College and Career Readiness Program',
                'category' => 'curriculum',
                'description' => 'A recommended high school curriculum to prepare students for college and careers. Includes 4 years of English, 4 years of Math, 3 years of Lab Science, 3 years of History, 2 years of Foreign Language, 1 year of Arts, and 5 additional core courses.',
                'parent_tip' => 'High MassCore completion rates (80%+) indicate a school prepares students well for college. Many top universities prefer students who completed MassCore.',
            ],
            'chapter_70' => [
                'term' => 'Chapter 70',
                'full_name' => 'Chapter 70 State Aid Formula',
                'category' => 'funding',
                'description' => 'The primary state education funding formula in Massachusetts. It calculates how much state aid each school district receives based on enrollment, student needs (low-income, ELL, special education), and local property wealth.',
                'parent_tip' => 'Districts receiving more Chapter 70 aid often have higher concentrations of students needing extra support. This isn\'t necessarily bad—it means more resources are being directed where needed.',
            ],
            'charter_school' => [
                'term' => 'Charter School',
                'full_name' => 'Commonwealth Charter School',
                'category' => 'school_type',
                'description' => 'Publicly funded schools that operate independently from the local school district. They have more flexibility in curriculum and teaching methods but must meet state standards. Admission is typically by lottery when oversubscribed.',
                'parent_tip' => 'Charter schools can be great options but vary significantly in quality. Check MCAS scores and visit before enrolling. Note: Moving to a new town doesn\'t guarantee a charter seat—lotteries are separate from residence.',
            ],
            'regional_school' => [
                'term' => 'Regional School',
                'full_name' => 'Regional School District',
                'category' => 'school_type',
                'description' => 'A school district formed by multiple towns combining resources, most commonly for high schools. Students from member towns attend together, often providing more course options and activities than a small town could offer alone.',
                'parent_tip' => 'Regional high schools often have more AP courses, sports, and extracurriculars. However, your child may have a longer bus ride. Check which towns are in the region and the driving distance.',
            ],
            'metco' => [
                'term' => 'METCO',
                'full_name' => 'Metropolitan Council for Educational Opportunity',
                'category' => 'program',
                'description' => 'A voluntary desegregation program where Boston students can attend participating suburban schools. It\'s one of the oldest programs of its kind in the nation, founded in 1966.',
                'parent_tip' => 'If you\'re in a suburban town, your schools may have METCO students. This adds diversity and is generally seen as beneficial for all students.',
            ],
            'sped' => [
                'term' => 'SPED / Special Education',
                'full_name' => 'Special Education Services',
                'category' => 'program',
                'description' => 'Services and supports for students with disabilities, provided through Individualized Education Programs (IEPs). Massachusetts has strong special education laws, often going beyond federal requirements.',
                'parent_tip' => 'Massachusetts is known for excellent special education services. If your child may need support, the SPED percentage at a school indicates experience serving diverse learners. Schools with 15-20% SPED often have robust support systems.',
            ],
            'ell' => [
                'term' => 'ELL',
                'full_name' => 'English Language Learners',
                'category' => 'program',
                'description' => 'Students who are learning English as a second language. Massachusetts offers various programs including Sheltered English Immersion (SEI), Transitional Bilingual Education, and Two-Way Immersion.',
                'parent_tip' => 'A school\'s ELL percentage reflects its linguistic diversity. Schools with high ELL populations often have experience supporting non-native English speakers and may offer bilingual programs.',
            ],
            'composite_score' => [
                'term' => 'Composite Score',
                'full_name' => 'BMN Boston Composite Rating',
                'category' => 'rating',
                'description' => 'Our proprietary 0-100 rating that combines multiple data points: MCAS scores (25%), graduation rate (15%), MassCore completion (15%), attendance (10%), AP performance (10%), MCAS growth (10%), per-pupil spending (10%), and student-teacher ratio (5%).',
                'parent_tip' => 'The composite score gives a holistic view, but no single number tells the whole story. Two schools with similar scores might excel in different areas. Click "View Details" to see the breakdown.',
            ],
            'letter_grade' => [
                'term' => 'Letter Grade',
                'full_name' => 'School Letter Grade (A+ to F)',
                'category' => 'rating',
                'description' => 'A simplified rating based on the composite score. A+ (97-100), A (93-96), A- (90-92), B+ (87-89), B (83-86), B- (80-82), C+ (77-79), C (73-76), C- (70-72), D (60-69), F (below 60).',
                'parent_tip' => 'Letter grades make comparison easy, but remember that a "B" school might be perfect for your child. Consider factors like location, specific programs, and school culture alongside the grade.',
            ],
            'percentile_rank' => [
                'term' => 'Percentile Rank',
                'full_name' => 'State Percentile Ranking',
                'category' => 'rating',
                'description' => 'Shows how a school compares to others statewide. A school at the 75th percentile scores higher than 75% of similar schools in Massachusetts.',
                'parent_tip' => 'Percentile is often more useful than raw rank. Being in the "Top 25%" (75th percentile or above) is excellent. Schools between 50th-75th percentile are solid choices.',
            ],
            'ap' => [
                'term' => 'AP',
                'full_name' => 'Advanced Placement',
                'category' => 'curriculum',
                'description' => 'College-level courses offered in high school. Students can earn college credit by passing AP exams. Massachusetts has high AP participation rates compared to the national average.',
                'parent_tip' => 'Look at both AP participation (how many students take AP courses) and AP pass rate (how many score 3+ on the exam). A school with 60%+ pass rate has strong AP programs.',
            ],
            'cte' => [
                'term' => 'CTE',
                'full_name' => 'Career and Technical Education',
                'category' => 'program',
                'description' => 'Vocational programs that prepare students for specific careers. Massachusetts vocational-technical schools are highly regarded and often have waiting lists. Programs include healthcare, IT, construction trades, culinary arts, and more.',
                'parent_tip' => 'Vocational schools in MA are not "less than" traditional high schools—many have excellent academics AND trade training. Graduates often have job offers before graduating and can earn certifications.',
            ],
            'innovation_pathway' => [
                'term' => 'Innovation Pathway',
                'full_name' => 'Massachusetts Innovation Pathway',
                'category' => 'program',
                'description' => 'Rigorous, employer-validated career pathways in traditional high schools. Combines academic coursework with work-based learning. Designated pathways meet state-defined standards for quality.',
                'parent_tip' => 'Innovation Pathways offer career exploration without committing to a vocational school. Great for students interested in healthcare, IT, engineering, or advanced manufacturing.',
            ],
            'early_college' => [
                'term' => 'Early College',
                'full_name' => 'Early College Program',
                'category' => 'program',
                'description' => 'Partnerships between high schools and colleges where students earn college credits while in high school, often for free. Massachusetts has a designated Early College network with quality standards.',
                'parent_tip' => 'Early College can save thousands in college costs. Students can earn 12-30 credits. It\'s especially valuable for first-generation college students who get a head start on the college experience.',
            ],
            'chronic_absence' => [
                'term' => 'Chronic Absence',
                'full_name' => 'Chronic Absenteeism Rate',
                'category' => 'metric',
                'description' => 'The percentage of students who miss 10% or more of school days (about 18 days per year). High chronic absence rates can indicate school climate issues or transportation challenges.',
                'parent_tip' => 'Look for schools with chronic absence rates under 15%. Rates above 20% may signal problems with school engagement or community challenges.',
            ],
            'student_teacher_ratio' => [
                'term' => 'Student-Teacher Ratio',
                'full_name' => 'Student to Teacher Ratio',
                'category' => 'metric',
                'description' => 'The average number of students per teacher. This is not the same as class size—it includes all instructional staff including specialists.',
                'parent_tip' => 'Lower is generally better for individual attention. Massachusetts averages around 12:1. Schools under 14:1 offer more personalized attention.',
            ],
            'per_pupil_spending' => [
                'term' => 'Per-Pupil Spending',
                'full_name' => 'Per-Pupil Expenditure',
                'category' => 'funding',
                'description' => 'The total spending divided by enrollment. Includes teacher salaries, supplies, facilities, and support services. Massachusetts is among the highest spending states.',
                'parent_tip' => 'More spending doesn\'t always mean better outcomes, but consistently low spending (under $15K/student) may indicate resource constraints. The MA average is around $18-20K per pupil.',
            ],
            'accountability' => [
                'term' => 'Accountability',
                'full_name' => 'State Accountability System',
                'category' => 'oversight',
                'description' => 'Massachusetts evaluates schools on multiple measures including achievement, growth, and progress toward targets. Schools not meeting standards may receive additional oversight and support.',
                'parent_tip' => 'Schools identified as "requiring assistance" aren\'t necessarily bad—they\'re getting extra resources to improve. Check if the school is trending upward.',
            ],
            'school_committee' => [
                'term' => 'School Committee',
                'full_name' => 'Local School Committee',
                'category' => 'governance',
                'description' => 'The elected (or sometimes appointed) board that governs local public schools. They set policy, approve budgets, and hire the superintendent. Called "School Board" in most other states.',
                'parent_tip' => 'School Committee meetings are open to the public. Attending a meeting can give you insight into community priorities and any issues facing the schools.',
            ],
        ];

        // If specific term requested, return just that term
        if ($term) {
            $term_key = strtolower(str_replace([' ', '-'], '_', $term));
            if (isset($glossary[$term_key])) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => $glossary[$term_key],
                ], 200);
            }

            // Try partial match
            $matches = [];
            foreach ($glossary as $key => $entry) {
                if (stripos($key, $term_key) !== false ||
                    stripos($entry['term'], $term) !== false ||
                    stripos($entry['full_name'], $term) !== false) {
                    $matches[$key] = $entry;
                }
            }

            if (!empty($matches)) {
                return new WP_REST_Response([
                    'success' => true,
                    'data' => array_values($matches),
                ], 200);
            }

            return new WP_REST_Response([
                'success' => false,
                'code' => 'term_not_found',
                'message' => "Term '{$term}' not found in glossary",
            ], 404);
        }

        // Return all terms grouped by category
        $by_category = [];
        foreach ($glossary as $key => $entry) {
            $category = $entry['category'];
            if (!isset($by_category[$category])) {
                $by_category[$category] = [];
            }
            $by_category[$category][$key] = $entry;
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'terms' => $glossary,
                'by_category' => $by_category,
                'categories' => [
                    'testing' => 'Testing & Assessment',
                    'curriculum' => 'Curriculum & Courses',
                    'funding' => 'Funding & Resources',
                    'school_type' => 'Types of Schools',
                    'program' => 'Programs & Services',
                    'rating' => 'Our Ratings',
                    'metric' => 'School Metrics',
                    'oversight' => 'Oversight & Accountability',
                    'governance' => 'School Governance',
                ],
                'count' => count($glossary),
            ],
        ], 200);
    }
}
