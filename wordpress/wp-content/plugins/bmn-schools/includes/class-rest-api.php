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
        $this->cache = new BMN_Schools_Cache_Manager();

        $this->register_routes();
    }

    /**
     * Check rate limiting for expensive endpoints.
     *
     * Returns WP_REST_Response if request should be blocked, false if allowed.
     *
     * Note: We intentionally do NOT block bots (Googlebot, etc.) here.
     * The root cause of server overload was fixed in MLD v6.30.12 by switching
     * to internal REST dispatch, so property pages no longer make HTTP requests
     * to this API. Bots crawling the site should work normally.
     *
     * @since 0.6.19
     * @param string $endpoint_name Name of endpoint for rate limit key.
     * @param int    $max_requests  Max requests per minute (default 60).
     * @return WP_REST_Response|false Response if blocked, false if allowed.
     */
    private function check_rate_limit($endpoint_name = 'default', $max_requests = 60) {
        // Rate limiting: max requests per minute per IP (generous limit for normal use)
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
        $rate_limit_key = 'bmn_schools_rate_' . md5($client_ip . '_' . $endpoint_name);
        $request_count = (int) get_transient($rate_limit_key);

        if ($request_count >= $max_requests) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Rate limit exceeded. Please wait before making more requests.',
            ], 429);
        }

        // Increment counter
        set_transient($rate_limit_key, $request_count + 1, MINUTE_IN_SECONDS);

        return false; // Not blocked
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
                'district_id' => [
                    'validate_callback' => function($param) {
                        return empty($param) || is_numeric($param);
                    },
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

            // Calculate data completeness
            $components_map = [
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
            foreach ($components_map as $name => $value) {
                if ($value !== null) {
                    $components_available++;
                    $components_list[] = $name;
                }
            }

            // Determine confidence level - elementary schools have different thresholds
            $is_elementary = stripos($school->level ?? '', 'Elementary') !== false;
            $confidence_level = 'limited';
            $limited_data_note = null;

            if ($is_elementary) {
                // Elementary: 5 possible components (MCAS, attendance, ratio, growth, spending)
                if ($components_available >= 5) {
                    $confidence_level = 'comprehensive';
                } elseif ($components_available >= 4) {
                    $confidence_level = 'good';
                } else {
                    // Provide specific reason for limited data
                    $has_mcas = in_array('mcas', $components_list);
                    $has_growth = in_array('growth', $components_list);
                    if (!$has_mcas && !$has_growth) {
                        $limited_data_note = 'No MCAS data available (this school may be private or serve grades below 3)';
                    } elseif (!$has_growth) {
                        $limited_data_note = 'Limited historical data for year-over-year comparison';
                    } else {
                        $limited_data_note = 'Rating based on limited available metrics';
                    }
                }
            } else {
                // Middle/High: 8 possible components
                if ($components_available >= 7) {
                    $confidence_level = 'comprehensive';
                } elseif ($components_available >= 5) {
                    $confidence_level = 'good';
                }
            }

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
                'data_completeness' => [
                    'components_available' => $components_available,
                    'components_total' => $is_elementary ? 5 : 8,
                    'confidence_level' => $confidence_level,
                    'components' => $components_list,
                    'limited_data_note' => $limited_data_note,
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

        // Parse extra_data JSON and extract college_outcomes and discipline
        if (!empty($district->extra_data)) {
            $extra = json_decode($district->extra_data, true);
            if (isset($extra['college_outcomes'])) {
                $district->college_outcomes = $extra['college_outcomes'];
            }
            if (isset($extra['discipline'])) {
                $district->discipline = $this->enrich_discipline_with_percentile($extra['discipline']);
            }
        }
        unset($district->extra_data); // Remove raw JSON from response

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
        // Rate limiting for expensive endpoint
        $rate_check = $this->check_rate_limit('district_for_point', 30);
        if ($rate_check) {
            return $rate_check;
        }

        global $wpdb;

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));

        // PERFORMANCE FIX: Cache by rounded coordinates (3 decimal places = ~100m accuracy)
        // This dramatically reduces full table scans with point-in-polygon checks
        $cache_key = 'bmn_district_' . sprintf('%.3f_%.3f', $lat, $lng);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            // Check if it's a "not found" cache entry
            if (isset($cached['not_found']) && $cached['not_found']) {
                return new WP_REST_Response([
                    'success' => false,
                    'code' => 'not_found',
                    'message' => 'No district found containing these coordinates',
                    'coordinates' => ['latitude' => $lat, 'longitude' => $lng],
                ], 404);
            }
            return new WP_REST_Response($cached, 200);
        }

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

                $response_data = [
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
                ];

                // Cache for 1 hour (district boundaries don't change)
                set_transient($cache_key, $response_data, HOUR_IN_SECONDS);

                return new WP_REST_Response($response_data, 200);
            }
        }

        // No district found - cache the "not found" result too
        set_transient($cache_key, ['not_found' => true], HOUR_IN_SECONDS);

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

        // Search districts (exclude duplicates)
        $districts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, city FROM {$tables['districts']}
             WHERE name LIKE %s
             AND (type IS NULL OR type NOT IN ('duplicate', 'inactive'))
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
     * Determine appropriate display level from grade range.
     *
     * Maps 'combined' or 'other' level schools to elementary/middle/high
     * based on their actual grade ranges.
     *
     * Grade mappings:
     * - Elementary: PK, K, 01-05 (or 06 if no middle grades)
     * - Middle: 06-08
     * - High: 09-12
     *
     * @since 0.6.13
     * @param object $school School object with level, grades_low, grades_high.
     * @return string Level for display grouping: 'elementary', 'middle', 'high', or 'other'.
     */
    private function determine_display_level($school) {
        // If already categorized as elementary/middle/high, use that
        $level = strtolower($school->level ?? '');
        if (in_array($level, ['elementary', 'middle', 'high'])) {
            return $level;
        }

        // Check school name for level indicators (overrides grade-based logic)
        // This handles cases like "Swampscott Middle School" with grade range PK-08
        $name = strtolower($school->name ?? '');
        if (preg_match('/\b(middle|jr\.|jr |junior)\s*(high\s*)?(school|academy)/i', $name)) {
            return 'middle';
        }
        if (preg_match('/\bhigh\s+(school|academy)/i', $name) && !preg_match('/\bjr\.\s*high/i', $name)) {
            return 'high';
        }
        if (preg_match('/\b(elementary|primary)\s+(school|academy)/i', $name)) {
            return 'elementary';
        }

        // For combined/other, determine from grade range
        $grades_low = strtoupper(trim($school->grades_low ?? ''));
        $grades_high = strtoupper(trim($school->grades_high ?? ''));

        if (empty($grades_low) && empty($grades_high)) {
            return 'other';
        }

        // Convert grade strings to numeric values for comparison
        // PK=-1, K=0, 01=1, 02=2, ..., 12=12
        $low_num = $this->grade_to_number($grades_low);
        $high_num = $this->grade_to_number($grades_high);

        // Determine primary level based on grade range
        // If spans multiple levels, use the primary one (where most grades fall)
        if ($high_num <= 5) {
            // Ends at grade 5 or below = elementary
            return 'elementary';
        } elseif ($high_num <= 8) {
            // Ends at grade 6-8
            if ($low_num >= 6) {
                // Starts at 6+ = middle school only
                return 'middle';
            } elseif ($low_num <= 0) {
                // Starts at K or PK and goes to 6-8 = primarily elementary (K-8 school)
                return 'elementary';
            } else {
                // Starts at 1-5 and ends at 6-8 = treat as middle
                return 'middle';
            }
        } else {
            // Ends at grade 9-12 = high school (or spans to high)
            if ($low_num >= 9) {
                return 'high';
            } elseif ($low_num >= 6) {
                // 6-12 school = show in high
                return 'high';
            } else {
                // K-12 or similar = show in elementary (parents care most about elementary)
                return 'elementary';
            }
        }
    }

    /**
     * Convert grade string to numeric value for comparison.
     *
     * @param string $grade Grade string (e.g., 'PK', 'K', '01', '12').
     * @return int Numeric grade (-1 for PK, 0 for K, 1-12 for grades).
     */
    private function grade_to_number($grade) {
        $grade = strtoupper(trim($grade));

        if ($grade === 'PK' || $grade === 'PRE-K' || $grade === 'P') {
            return -1;
        }
        if ($grade === 'K' || $grade === 'KG') {
            return 0;
        }

        // Remove leading zeros and convert to int
        $num = intval(ltrim($grade, '0'));
        return min(max($num, 0), 12);
    }

    /**
     * Get regional school mapping for a city.
     *
     * Massachusetts has many regional school districts and tuition agreements where
     * students from one town attend schools in another town for certain grade levels.
     * This method returns the mapping of where students actually attend school.
     *
     * @since 0.6.15
     * @param string $city City name to look up.
     * @return array|null Array with 'elementary', 'middle', 'high' keys indicating
     *                    which city's schools to use for each level. null values mean
     *                    use the city's own schools. Returns null if no mapping exists.
     */
    private function get_regional_school_mapping($city) {
        // Normalize city name
        $city = strtoupper(trim($city));

        // Comprehensive mapping of Massachusetts regional school arrangements
        // Format: 'CITY' => ['elementary' => null|'OtherCity', 'middle' => ..., 'high' => ...]
        // null = use own city's schools, string = look for schools in specified city
        $mappings = [
            // === TUITION AGREEMENTS (Town sends students to another town) ===

            // Nahant sends students to Swampscott for grades 7-12
            'NAHANT' => [
                'elementary' => null,
                'middle' => 'SWAMPSCOTT',
                'high' => 'SWAMPSCOTT',
            ],

            // === TWO-TOWN REGIONAL DISTRICTS ===

            // Acton-Boxborough Regional (grades 7-12) - Boxborough has K-6, sends to Acton for 7-12
            'BOXBOROUGH' => [
                'elementary' => null,
                'middle' => 'ACTON',
                'high' => 'ACTON',
            ],

            // Lincoln-Sudbury Regional (grades 9-12) - Lincoln has K-8, sends to Sudbury for 9-12
            'LINCOLN' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'SUDBURY',
            ],

            // Dover-Sherborn Regional (grades 6-12) - Both towns have K-5, regional for 6-12
            'SHERBORN' => [
                'elementary' => null,
                'middle' => 'DOVER',
                'high' => 'DOVER',
            ],

            // Hamilton-Wenham Regional (grades 6-12) - Wenham sends to Hamilton
            'WENHAM' => [
                'elementary' => null,
                'middle' => 'HAMILTON',
                'high' => 'HAMILTON',
            ],

            // Concord-Carlisle Regional (grades 9-12) - Carlisle has K-8, sends to Concord for 9-12
            'CARLISLE' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'CONCORD',
            ],

            // Manchester-Essex Regional (grades 6-12) - Essex sends to Manchester
            'ESSEX' => [
                'elementary' => null,
                'middle' => 'MANCHESTER-BY-THE-SEA',
                'high' => 'MANCHESTER-BY-THE-SEA',
            ],

            // Northborough-Southborough Regional (Algonquin HS in Northborough)
            'SOUTHBOROUGH' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'NORTHBOROUGH',
            ],

            // Bridgewater-Raynham Regional (grades 9-12)
            'RAYNHAM' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'BRIDGEWATER',
            ],

            // Ashburnham-Westminster Regional (HS in Ashburnham)
            'WESTMINSTER' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'ASHBURNHAM',
            ],

            // Berlin-Boylston Regional (Tahanto Regional in Boylston)
            'BERLIN' => [
                'elementary' => null,
                'middle' => 'BOYLSTON',
                'high' => 'BOYLSTON',
            ],

            // Groton-Dunstable Regional (schools in Groton)
            'DUNSTABLE' => [
                'elementary' => null,
                'middle' => 'GROTON',
                'high' => 'GROTON',
            ],

            // Ayer-Shirley Regional (HS in Ayer, MS in Shirley)
            'SHIRLEY' => [
                'elementary' => null,
                'middle' => null, // MS is in Shirley
                'high' => 'AYER',
            ],
            'AYER' => [
                'elementary' => null,
                'middle' => 'SHIRLEY', // MS is in Shirley
                'high' => null, // HS is in Ayer
            ],

            // Whitman-Hanson Regional (HS in Hanson)
            'WHITMAN' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'HANSON',
            ],

            // Dennis-Yarmouth Regional (HS in Yarmouth)
            'DENNIS' => [
                'elementary' => null,
                'middle' => 'YARMOUTH',
                'high' => 'YARMOUTH',
            ],

            // Dighton-Rehoboth Regional (HS in Dighton)
            'REHOBOTH' => [
                'elementary' => null,
                'middle' => 'DIGHTON',
                'high' => 'DIGHTON',
            ],

            // Somerset-Berkley Regional (HS in Somerset)
            'BERKLEY' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'SOMERSET',
            ],

            // === THREE-TOWN REGIONAL DISTRICTS ===

            // King Philip Regional (grades 7-12) - Norfolk, Plainville, Wrentham
            // Middle school in Norfolk, High school in Wrentham
            'NORFOLK' => [
                'elementary' => null,
                'middle' => null, // MS is in Norfolk
                'high' => 'WRENTHAM',
            ],
            'PLAINVILLE' => [
                'elementary' => null,
                'middle' => 'NORFOLK',
                'high' => 'WRENTHAM',
            ],

            // Masconomet Regional (grades 7-12) - Boxford, Middleton, Topsfield
            // School campus in Boxford
            'MIDDLETON' => [
                'elementary' => null,
                'middle' => 'BOXFORD',
                'high' => 'BOXFORD',
            ],
            'TOPSFIELD' => [
                'elementary' => null,
                'middle' => 'BOXFORD',
                'high' => 'BOXFORD',
            ],

            // Silver Lake Regional (grades 7-12) - Halifax, Kingston, Plympton
            // Schools in Kingston
            'HALIFAX' => [
                'elementary' => null,
                'middle' => 'KINGSTON',
                'high' => 'KINGSTON',
            ],
            'PLYMPTON' => [
                'elementary' => null,
                'middle' => 'KINGSTON',
                'high' => 'KINGSTON',
            ],

            // Triton Regional (grades 7-12) - Newbury, Rowley, Salisbury
            // Schools in Newbury
            'ROWLEY' => [
                'elementary' => null,
                'middle' => 'NEWBURY',
                'high' => 'NEWBURY',
            ],
            'SALISBURY' => [
                'elementary' => null,
                'middle' => 'NEWBURY',
                'high' => 'NEWBURY',
            ],

            // Pentucket Regional (grades 7-12) - Groveland, Merrimac, West Newbury
            // Schools in West Newbury
            'GROVELAND' => [
                'elementary' => null,
                'middle' => 'WEST NEWBURY',
                'high' => 'WEST NEWBURY',
            ],
            'MERRIMAC' => [
                'elementary' => null,
                'middle' => 'WEST NEWBURY',
                'high' => 'WEST NEWBURY',
            ],

            // Nashoba Regional (Bolton, Lancaster, Stow) - HS in Bolton
            'LANCASTER' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'BOLTON',
            ],
            'STOW' => [
                'elementary' => null,
                'middle' => null,
                'high' => 'BOLTON',
            ],

            // North Middlesex Regional (Ashby, Pepperell, Townsend)
            'ASHBY' => [
                'elementary' => null,
                'middle' => 'TOWNSEND',
                'high' => 'TOWNSEND',
            ],
            'PEPPERELL' => [
                'elementary' => null,
                'middle' => 'TOWNSEND',
                'high' => 'TOWNSEND',
            ],

            // Nauset Regional (Brewster, Eastham, Orleans, Wellfleet)
            'BREWSTER' => [
                'elementary' => null,
                'middle' => 'ORLEANS',
                'high' => 'EASTHAM',
            ],
            'ORLEANS' => [
                'elementary' => null,
                'middle' => null, // MS is in Orleans
                'high' => 'EASTHAM',
            ],
            'WELLFLEET' => [
                'elementary' => null,
                'middle' => 'ORLEANS',
                'high' => 'EASTHAM',
            ],

            // Wachusett Regional (Holden, Paxton, Princeton, Rutland, Sterling)
            'PAXTON' => [
                'elementary' => null,
                'middle' => 'HOLDEN',
                'high' => 'HOLDEN',
            ],
            'PRINCETON' => [
                'elementary' => null,
                'middle' => 'HOLDEN',
                'high' => 'HOLDEN',
            ],
            'RUTLAND' => [
                'elementary' => null,
                'middle' => 'HOLDEN',
                'high' => 'HOLDEN',
            ],
            'STERLING' => [
                'elementary' => null,
                'middle' => 'HOLDEN',
                'high' => 'HOLDEN',
            ],

            // Quabbin Regional (Barre, Hardwick, Hubbardston, New Braintree, Oakham)
            'HARDWICK' => [
                'elementary' => null,
                'middle' => 'BARRE',
                'high' => 'BARRE',
            ],
            'HUBBARDSTON' => [
                'elementary' => null,
                'middle' => 'BARRE',
                'high' => 'BARRE',
            ],
            'NEW BRAINTREE' => [
                'elementary' => null,
                'middle' => 'BARRE',
                'high' => 'BARRE',
            ],
            'OAKHAM' => [
                'elementary' => null,
                'middle' => 'BARRE',
                'high' => 'BARRE',
            ],

            // Old Rochester Regional (Marion, Mattapoisett, Rochester) - schools in Mattapoisett
            'MARION' => [
                'elementary' => null,
                'middle' => 'MATTAPOISETT',
                'high' => 'MATTAPOISETT',
            ],
            'ROCHESTER' => [
                'elementary' => null,
                'middle' => 'MATTAPOISETT',
                'high' => 'MATTAPOISETT',
            ],

            // Monomoy Regional (Chatham, Harwich)
            'CHATHAM' => [
                'elementary' => null,
                'middle' => null, // MS is in Chatham
                'high' => 'HARWICH',
            ],

            // === BERKSHIRE COUNTY REGIONAL DISTRICTS ===

            // Hoosac Valley Regional (Adams, Cheshire, Savoy) - schools in Cheshire
            'SAVOY' => [
                'elementary' => null, // PK-6 in Savoy
                'middle' => 'CHESHIRE',
                'high' => 'CHESHIRE',
            ],
            'ADAMS' => [
                'elementary' => null, // PK-3 in Adams
                'middle' => 'CHESHIRE',
                'high' => 'CHESHIRE',
            ],

            // Mount Greylock Regional (Williamstown, Lanesborough) - schools in Williamstown
            'LANESBOROUGH' => [
                'elementary' => null,
                'middle' => 'WILLIAMSTOWN',
                'high' => 'WILLIAMSTOWN',
            ],

            // Southern Berkshire Regional (Sheffield, Monterey, New Marlborough, etc.)
            'MONTEREY' => [
                'elementary' => null,
                'middle' => 'SHEFFIELD',
                'high' => 'SHEFFIELD',
            ],
            'NEW MARLBOROUGH' => [
                'elementary' => null,
                'middle' => 'SHEFFIELD',
                'high' => 'SHEFFIELD',
            ],

            // Berkshire Hills Regional (Great Barrington, Stockbridge, West Stockbridge)
            'STOCKBRIDGE' => [
                'elementary' => null,
                'middle' => 'GREAT BARRINGTON',
                'high' => 'GREAT BARRINGTON',
            ],
            'WEST STOCKBRIDGE' => [
                'elementary' => null,
                'middle' => 'GREAT BARRINGTON',
                'high' => 'GREAT BARRINGTON',
            ],

            // Central Berkshire Regional (Becket, Dalton, Hinsdale, Peru, Washington, Windsor)
            'BECKET' => [
                'elementary' => null,
                'middle' => 'DALTON',
                'high' => 'DALTON',
            ],
            'HINSDALE' => [
                'elementary' => null,
                'middle' => 'DALTON',
                'high' => 'DALTON',
            ],
            'PERU' => [
                'elementary' => null,
                'middle' => 'DALTON',
                'high' => 'DALTON',
            ],
            'WASHINGTON' => [
                'elementary' => null,
                'middle' => 'DALTON',
                'high' => 'DALTON',
            ],
            'WINDSOR' => [
                'elementary' => null,
                'middle' => 'DALTON',
                'high' => 'DALTON',
            ],
        ];

        return $mappings[$city] ?? null;
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

    // ==========================================================================
    // BATCH QUERY METHODS (Performance optimization - v0.6.36)
    // These methods fetch data for multiple schools in a single query
    // ==========================================================================

    /**
     * Batch fetch MCAS scores for multiple schools.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_mcas_scores($school_ids) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT school_id, AVG(proficient_or_above_pct) as avg_mcas
             FROM {$tables['test_scores']} t
             WHERE school_id IN ({$placeholders})
             AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = t.school_id)
             GROUP BY school_id",
            ...$school_ids
        ));

        $mcas_by_school = [];
        foreach ($results as $row) {
            $mcas_by_school[(int) $row->school_id] = round((float) $row->avg_mcas, 1);
        }
        return $mcas_by_school;
    }

    /**
     * Batch fetch rankings for multiple schools.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_rankings($school_ids) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        // Use a subquery to get only the most recent ranking per school
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.school_id, r.year, r.category, r.composite_score, r.percentile_rank, r.state_rank,
                    r.mcas_score, r.graduation_score, r.masscore_score, r.attendance_score,
                    r.ap_score, r.growth_score, r.spending_score, r.ratio_score
             FROM {$tables['rankings']} r
             INNER JOIN (
                 SELECT school_id, MAX(year) as max_year
                 FROM {$tables['rankings']}
                 WHERE school_id IN ({$placeholders})
                 AND composite_score IS NOT NULL
                 GROUP BY school_id
             ) latest ON r.school_id = latest.school_id AND r.year = latest.max_year
             WHERE r.composite_score IS NOT NULL",
            ...$school_ids
        ));

        $rankings_by_school = [];
        foreach ($results as $row) {
            $rankings_by_school[(int) $row->school_id] = $row;
        }
        return $rankings_by_school;
    }

    /**
     * Batch fetch previous year rankings for trend calculation.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @param int $current_year The current ranking year.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_previous_rankings($school_ids, $current_year) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        $params = array_merge($school_ids, [$current_year - 1]);
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT school_id, year, composite_score, percentile_rank, state_rank, category
             FROM {$tables['rankings']}
             WHERE school_id IN ({$placeholders})
             AND year = %d
             AND composite_score IS NOT NULL",
            ...$params
        ));

        $prev_by_school = [];
        foreach ($results as $row) {
            $prev_by_school[(int) $row->school_id] = $row;
        }
        return $prev_by_school;
    }

    /**
     * Batch fetch demographics for multiple schools.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_demographics($school_ids) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        // Use a subquery to get only the most recent demographics per school
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT d.school_id, d.total_students, d.pct_free_reduced_lunch, d.avg_class_size,
                    d.pct_white, d.pct_black, d.pct_hispanic, d.pct_asian, d.pct_multiracial,
                    d.pct_english_learner, d.pct_special_ed
             FROM {$tables['demographics']} d
             INNER JOIN (
                 SELECT school_id, MAX(year) as max_year
                 FROM {$tables['demographics']}
                 WHERE school_id IN ({$placeholders})
                 GROUP BY school_id
             ) latest ON d.school_id = latest.school_id AND d.year = latest.max_year",
            ...$school_ids
        ));

        $demo_by_school = [];
        foreach ($results as $row) {
            $demo_by_school[(int) $row->school_id] = $row;
        }
        return $demo_by_school;
    }

    /**
     * Batch fetch discipline data for multiple schools.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_discipline($school_ids) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        if (!isset($tables['discipline'])) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT d.school_id, d.year, d.enrollment, d.students_disciplined,
                    d.in_school_suspension_pct, d.out_of_school_suspension_pct,
                    d.expulsion_pct, d.discipline_rate
             FROM {$tables['discipline']} d
             INNER JOIN (
                 SELECT school_id, MAX(year) as max_year
                 FROM {$tables['discipline']}
                 WHERE school_id IN ({$placeholders})
                 GROUP BY school_id
             ) latest ON d.school_id = latest.school_id AND d.year = latest.max_year",
            ...$school_ids
        ));

        // Get 25th percentile threshold once for all schools
        $threshold = $wpdb->get_var(
            "SELECT discipline_rate FROM {$tables['discipline']}
             WHERE discipline_rate IS NOT NULL
             ORDER BY discipline_rate ASC
             LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.25) FROM {$tables['discipline']} WHERE discipline_rate IS NOT NULL)"
        );
        $threshold = $threshold !== null ? (float) $threshold : null;

        $disc_by_school = [];
        foreach ($results as $row) {
            $is_low = false;
            if ($threshold !== null && $row->discipline_rate !== null && (float) $row->discipline_rate <= $threshold) {
                $is_low = true;
            }
            $disc_by_school[(int) $row->school_id] = [
                'year' => (int) $row->year,
                'enrollment' => $row->enrollment ? (int) $row->enrollment : null,
                'students_disciplined' => $row->students_disciplined ? (int) $row->students_disciplined : null,
                'in_school_suspension_pct' => $row->in_school_suspension_pct ? round((float) $row->in_school_suspension_pct, 1) : null,
                'out_of_school_suspension_pct' => $row->out_of_school_suspension_pct ? round((float) $row->out_of_school_suspension_pct, 1) : null,
                'expulsion_pct' => $row->expulsion_pct ? round((float) $row->expulsion_pct, 1) : null,
                'discipline_rate' => $row->discipline_rate ? round((float) $row->discipline_rate, 1) : null,
                'is_low_discipline' => $is_low,
            ];
        }
        return $disc_by_school;
    }

    /**
     * Batch fetch sports data for multiple schools.
     *
     * @since 0.6.36
     * @param array $school_ids Array of school IDs.
     * @return array Associative array keyed by school_id.
     */
    private function batch_get_sports($school_ids) {
        if (empty($school_ids)) {
            return [];
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        if (!isset($tables['sports'])) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
        // Get all sports for the most recent year per school
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.school_id, s.sport, s.gender, s.participants
             FROM {$tables['sports']} s
             INNER JOIN (
                 SELECT school_id, MAX(year) as max_year
                 FROM {$tables['sports']}
                 WHERE school_id IN ({$placeholders})
                 GROUP BY school_id
             ) latest ON s.school_id = latest.school_id AND s.year = latest.max_year
             ORDER BY s.school_id, s.sport, s.gender",
            ...$school_ids
        ));

        // Group by school_id
        $sports_by_school = [];
        foreach ($results as $row) {
            $sid = (int) $row->school_id;
            if (!isset($sports_by_school[$sid])) {
                $sports_by_school[$sid] = [];
            }
            $sports_by_school[$sid][] = $row;
        }

        // Format each school's sports data
        $formatted = [];
        foreach ($sports_by_school as $sid => $sports) {
            $sports_list = [];
            $total_boys = 0;
            $total_girls = 0;

            foreach ($sports as $sport) {
                $sports_list[] = [
                    'sport' => $sport->sport,
                    'gender' => $sport->gender,
                    'participants' => (int) $sport->participants,
                ];
                if ($sport->gender === 'Boys') {
                    $total_boys += (int) $sport->participants;
                } else {
                    $total_girls += (int) $sport->participants;
                }
            }

            $unique_sports = count(array_unique(array_column($sports_list, 'sport')));
            $formatted[$sid] = [
                'sports_count' => $unique_sports,
                'total_participants' => $total_boys + $total_girls,
                'boys_participants' => $total_boys,
                'girls_participants' => $total_girls,
                'sports' => $sports_list,
            ];
        }

        return $formatted;
    }

    // ==========================================================================
    // END BATCH QUERY METHODS
    // ==========================================================================

    /**
     * Get discipline data for a school.
     *
     * Returns suspension, expulsion, and other discipline metrics from DESE SSDR.
     *
     * @since 0.6.22
     * @param int $school_id School ID.
     * @return array|null Discipline data or null if not available.
     */
    private function get_school_discipline($school_id) {
        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        // Check if discipline table exists
        if (!isset($tables['discipline'])) {
            return null;
        }

        $discipline = $wpdb->get_row($wpdb->prepare(
            "SELECT year, enrollment, students_disciplined,
                    in_school_suspension_pct, out_of_school_suspension_pct,
                    expulsion_pct, removed_to_alternate_pct, emergency_removal_pct,
                    school_based_arrest_pct, law_enforcement_referral_pct, discipline_rate
             FROM {$tables['discipline']}
             WHERE school_id = %d
             ORDER BY year DESC
             LIMIT 1",
            $school_id
        ));

        if (!$discipline) {
            return null;
        }

        // Determine if this is a "low discipline rate" school (bottom 25%)
        $is_low_discipline = false;
        if ($discipline->discipline_rate !== null) {
            // Get the 25th percentile threshold
            $threshold = $wpdb->get_var(
                "SELECT discipline_rate FROM {$tables['discipline']}
                 WHERE discipline_rate IS NOT NULL
                 ORDER BY discipline_rate ASC
                 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.25) FROM {$tables['discipline']} WHERE discipline_rate IS NOT NULL)"
            );
            if ($threshold !== null && $discipline->discipline_rate <= (float) $threshold) {
                $is_low_discipline = true;
            }
        }

        return [
            'year' => (int) $discipline->year,
            'enrollment' => $discipline->enrollment ? (int) $discipline->enrollment : null,
            'students_disciplined' => $discipline->students_disciplined ? (int) $discipline->students_disciplined : null,
            'in_school_suspension_pct' => $discipline->in_school_suspension_pct ? round((float) $discipline->in_school_suspension_pct, 1) : null,
            'out_of_school_suspension_pct' => $discipline->out_of_school_suspension_pct ? round((float) $discipline->out_of_school_suspension_pct, 1) : null,
            'expulsion_pct' => $discipline->expulsion_pct ? round((float) $discipline->expulsion_pct, 1) : null,
            'discipline_rate' => $discipline->discipline_rate ? round((float) $discipline->discipline_rate, 1) : null,
            'is_low_discipline' => $is_low_discipline,
        ];
    }

    /**
     * Enrich discipline data with percentile ranking.
     *
     * Calculates the percentile based on discipline_rate compared to all districts
     * and adds percentile and percentile_label fields.
     *
     * @since 0.6.24
     * @param array $discipline Discipline data from extra_data.
     * @return array Enriched discipline data with percentile info.
     */
    private function enrich_discipline_with_percentile($discipline) {
        if (empty($discipline) || !isset($discipline['discipline_rate'])) {
            return $discipline;
        }

        global $wpdb;
        $tables = bmn_schools()->get_table_names();
        $rate = (float) $discipline['discipline_rate'];

        // Get all district discipline rates for percentile calculation
        // Use static cache to avoid repeated queries
        static $all_rates = null;
        if ($all_rates === null) {
            $results = $wpdb->get_col(
                "SELECT JSON_EXTRACT(extra_data, '$.discipline.discipline_rate') as rate
                 FROM {$tables['districts']}
                 WHERE extra_data IS NOT NULL
                 AND JSON_EXTRACT(extra_data, '$.discipline.discipline_rate') IS NOT NULL
                 ORDER BY rate ASC"
            );
            $all_rates = array_map('floatval', array_filter($results, function($r) {
                return $r !== null && $r !== 'null';
            }));
        }

        if (empty($all_rates)) {
            return $discipline;
        }

        // Calculate percentile (lower rate = better = lower percentile number)
        $count_below = 0;
        foreach ($all_rates as $r) {
            if ($r < $rate) {
                $count_below++;
            }
        }
        $percentile = round(($count_below / count($all_rates)) * 100);

        // Determine percentile label (lower percentile = lower discipline = better)
        if ($percentile <= 25) {
            $percentile_label = 'Very Low (Safest)';
        } elseif ($percentile <= 50) {
            $percentile_label = 'Low';
        } elseif ($percentile <= 75) {
            $percentile_label = 'Average';
        } else {
            $percentile_label = 'Above Average';
        }

        // Add percentile info to discipline data
        $discipline['percentile'] = $percentile;
        $discipline['percentile_label'] = $percentile_label;

        return $discipline;
    }

    /**
     * Get sports data for a school.
     *
     * Returns list of sports programs with participation counts for high schools.
     * Sports data is only available for high schools from MIAA.
     *
     * @since 0.6.24
     * @param int $school_id School ID.
     * @return array|null Sports data or null if not available.
     */
    private function get_school_sports($school_id) {
        global $wpdb;
        $tables = bmn_schools()->get_table_names();

        // Check if sports table exists
        if (!isset($tables['sports'])) {
            return null;
        }

        // Get sports data for the most recent year
        $sports = $wpdb->get_results($wpdb->prepare(
            "SELECT sport, gender, participants
             FROM {$tables['sports']}
             WHERE school_id = %d
             AND year = (SELECT MAX(year) FROM {$tables['sports']} WHERE school_id = %d)
             ORDER BY sport ASC, gender ASC",
            $school_id, $school_id
        ));

        if (empty($sports)) {
            return null;
        }

        // Format sports data
        $sports_list = [];
        $total_boys = 0;
        $total_girls = 0;

        foreach ($sports as $sport) {
            $sports_list[] = [
                'sport' => $sport->sport,
                'gender' => $sport->gender,
                'participants' => (int) $sport->participants,
            ];
            if ($sport->gender === 'Boys') {
                $total_boys += (int) $sport->participants;
            } else {
                $total_girls += (int) $sport->participants;
            }
        }

        // Count unique sports (ignoring gender)
        $unique_sports = count(array_unique(array_column($sports_list, 'sport')));

        return [
            'sports_count' => $unique_sports,
            'total_participants' => $total_boys + $total_girls,
            'boys_participants' => $total_boys,
            'girls_participants' => $total_girls,
            'sports' => $sports_list,
        ];
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

        // PERFORMANCE FIX: Use transient cache instead of static (persists across requests)
        // Category totals change only when rankings are recalculated (rarely)
        static $category_totals = null;

        if ($category_totals === null) {
            // Try transient cache first
            $category_totals = get_transient('bmn_category_totals');

            if ($category_totals === false) {
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

                // Cache for 1 hour (invalidated on ranking recalculation)
                set_transient('bmn_category_totals', $category_totals, HOUR_IN_SECONDS);
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

        // Rate limit: 5 requests per minute for this expensive spatial query endpoint
        // Prevents database exhaustion from rapid-fire calls
        $rate_blocked = $this->check_rate_limit('property_schools', 5);
        if ($rate_blocked) {
            return $rate_blocked;
        }

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        $radius = floatval($request->get_param('radius'));
        $city = $request->get_param('city');
        $district_id = $request->get_param('district_id');

        // Check cache
        $cache_key = BMN_Schools_Cache_Manager::generate_key('property_schools', [
            'lat' => round($lat, 4),
            'lng' => round($lng, 4),
            'radius' => $radius,
            'city' => $city ? strtoupper($city) : '',
            'district_id' => $district_id ?: '',
        ]);
        $cached = BMN_Schools_Cache_Manager::get($cache_key, 'nearby_schools');
        if ($cached !== false) {
            return new WP_REST_Response($cached, 200);
        }

        $tables = bmn_schools()->get_table_names();

        // Build query based on filter mode:
        // 1. district_id - Get ALL schools in the district (no limit per level)
        // 2. city - Get schools in that city
        // 3. radius - Get schools within radius (legacy behavior)
        if ($district_id) {
            // Filter by district - get ALL schools in the district
            $sql = $wpdb->prepare(
                "SELECT s.*,
                 (3959 * ACOS(
                     COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                     COS(RADIANS(longitude) - RADIANS(%f)) +
                     SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
                 )) AS distance
                 FROM {$tables['schools']} s
                 WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                 AND district_id = %d
                 ORDER BY level ASC, distance ASC",
                $lat, $lng, $lat, intval($district_id)
            );
        } elseif ($city) {
            // Filter by city (case-insensitive) - get ALL schools in the city/district
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
                 ORDER BY level ASC, distance ASC",
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

        // For district/city mode, show more schools per level (10); for radius mode, limit to 3
        $max_schools_per_level = ($district_id || $city) ? 10 : 3;

        // Load ranking calculator for highlights and benchmark lookups
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';

        // PERFORMANCE OPTIMIZATION (v0.6.36): Two-pass approach with batch queries
        // Pass 1: Determine which schools will be included (respecting level limits)
        $grouped = [
            'elementary' => [],
            'middle' => [],
            'high' => [],
        ];
        $schools_to_include = [];
        $high_school_ids = [];

        foreach ($results as $school) {
            $level = $this->determine_display_level($school);
            if (!isset($grouped[$level])) {
                continue;
            }
            if (count($grouped[$level]) >= $max_schools_per_level) {
                continue;
            }
            // Store school with its level for later
            $school->_display_level = $level;
            $grouped[$level][] = $school;
            $schools_to_include[] = $school;
            if ($level === 'high') {
                $high_school_ids[] = (int) $school->id;
            }
        }

        // Collect all school IDs for batch queries
        $school_ids = array_map(function($s) { return (int) $s->id; }, $schools_to_include);

        // Batch fetch all data in single queries (instead of N+1 queries per school)
        $mcas_by_school = $this->batch_get_mcas_scores($school_ids);
        $rankings_by_school = $this->batch_get_rankings($school_ids);
        $demographics_by_school = $this->batch_get_demographics($school_ids);
        $discipline_by_school = $this->batch_get_discipline($school_ids);
        $sports_by_school = $this->batch_get_sports($high_school_ids);

        // Get previous year rankings for trend calculation (need ranking year first)
        $prev_rankings_by_school = [];
        if (!empty($rankings_by_school)) {
            $first_ranking = reset($rankings_by_school);
            if ($first_ranking && $first_ranking->year) {
                $prev_rankings_by_school = $this->batch_get_previous_rankings($school_ids, (int) $first_ranking->year);
            }
        }

        // Reset grouped for Pass 2 (building final response)
        $grouped = [
            'elementary' => [],
            'middle' => [],
            'high' => [],
        ];

        // Pass 2: Build response using pre-fetched batch data
        foreach ($schools_to_include as $school) {
            $level = $school->_display_level;
            $sid = (int) $school->id;

            // Get MCAS from batch data
            $mcas = $mcas_by_school[$sid] ?? null;

            // Get ranking from batch data
            $ranking_data = null;
            $ranking = $rankings_by_school[$sid] ?? null;
            if ($ranking && $ranking->composite_score !== null) {
                // Calculate trend from batch previous rankings
                $prev = $prev_rankings_by_school[$sid] ?? null;
                $trend = null;
                if ($prev) {
                    $rank_change = (int) $prev->state_rank - (int) $ranking->state_rank;
                    $direction = $rank_change > 0 ? 'up' : ($rank_change < 0 ? 'down' : 'stable');
                    $abs_change = abs($rank_change);
                    $trend = [
                        'direction' => $direction,
                        'rank_change' => $rank_change,
                        'score_change' => round((float) $ranking->composite_score - (float) $prev->composite_score, 1),
                        'percentile_change' => (int) $ranking->percentile_rank - (int) $prev->percentile_rank,
                        'previous_year' => (int) $prev->year,
                        'previous_rank' => (int) $prev->state_rank,
                        'previous_score' => round((float) $prev->composite_score, 1),
                        'rank_change_text' => $direction === 'up' ? "Improved {$abs_change} spots from last year" :
                                              ($direction === 'down' ? "Dropped {$abs_change} spots from last year" : "Same rank as last year"),
                    ];
                }

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
                // Phase 6: Elementary now has 5 components (added spending with district fallback)
                $is_elementary = stripos($school->level ?? '', 'Elementary') !== false;
                $confidence_level = 'limited';
                $limited_data_note = null;

                if ($is_elementary) {
                    // For elementary: 5 possible components (MCAS, attendance, ratio, growth, spending)
                    if ($components_available >= 5) {
                        $confidence_level = 'comprehensive';
                    } elseif ($components_available >= 4) {
                        $confidence_level = 'good';
                    } else {
                        // Provide specific reason for limited data
                        $has_mcas = in_array('mcas', $components_list);
                        $has_growth = in_array('growth', $components_list);
                        if (!$has_mcas && !$has_growth) {
                            $limited_data_note = 'No MCAS data available (this school may be private or serve grades below 3)';
                        } elseif (!$has_growth) {
                            $limited_data_note = 'Limited historical data for year-over-year comparison';
                        } else {
                            $limited_data_note = 'Rating based on limited available metrics';
                        }
                    }
                } else {
                    // Standard thresholds for middle/high schools
                    if ($components_available >= 7) {
                        $confidence_level = 'comprehensive';
                    } elseif ($components_available >= 5) {
                        $confidence_level = 'good';
                    }
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
                        'components_total' => $is_elementary ? 5 : 8,
                        'confidence_level' => $confidence_level,
                        'components' => $components_list,
                        'limited_data_note' => $limited_data_note,
                    ],
                    'benchmarks' => $benchmark_data,
                ];
            }

            // Get demographics from batch data
            $demographics_data = null;
            $demographics = $demographics_by_school[$sid] ?? null;
            if ($demographics) {
                $demographics_data = [
                    'total_students' => $demographics->total_students ? (int) $demographics->total_students : null,
                    'pct_free_reduced_lunch' => $demographics->pct_free_reduced_lunch ? round((float) $demographics->pct_free_reduced_lunch, 1) : null,
                    'avg_class_size' => $demographics->avg_class_size ? round((float) $demographics->avg_class_size, 1) : null,
                    'diversity' => $this->calculate_diversity_index($demographics),
                ];
            }

            // Get highlights for this school (already cached per-school via transients)
            $highlights = BMN_Schools_Ranking_Calculator::get_school_highlights($sid);

            // Get discipline from batch data
            $discipline_data = $discipline_by_school[$sid] ?? null;

            // Get sports from batch data (only for high schools)
            $sports_data = ($level === 'high') ? ($sports_by_school[$sid] ?? null) : null;

            $grouped[$level][] = [
                'id' => $sid,
                'name' => $school->name,
                'grades' => $this->format_grades($school->grades_low, $school->grades_high),
                'distance' => round($school->distance, 2),
                'address' => $school->address,
                'mcas_proficient_pct' => $mcas,
                'latitude' => (float) $school->latitude,
                'longitude' => (float) $school->longitude,
                'ranking' => $ranking_data,
                'demographics' => $demographics_data,
                'highlights' => !empty($highlights) ? $highlights : null,
                'discipline' => $discipline_data,
                'sports' => $sports_data,
            ];
        }

        // For city/district mode: if any levels are empty, supplement with regional schools
        // This handles regional districts where cities share schools (e.g., Nahant→Swampscott)
        if ($city) {
            $missing_levels = [];
            foreach (['elementary', 'middle', 'high'] as $check_level) {
                if (empty($grouped[$check_level])) {
                    $missing_levels[] = $check_level;
                }
            }

            if (!empty($missing_levels)) {
                // Check for regional school mapping
                $regional_mapping = $this->get_regional_school_mapping($city);

                // Track already-added school IDs to avoid duplicates
                $added_ids = [];
                foreach ($grouped as $level_schools) {
                    foreach ($level_schools as $s) {
                        $added_ids[$s['id']] = true;
                    }
                }

                // Process each missing level
                foreach ($missing_levels as $missing_level) {
                    $mapped_city = null;

                    // Check if we have a regional mapping for this level
                    if ($regional_mapping && !empty($regional_mapping[$missing_level])) {
                        $mapped_city = $regional_mapping[$missing_level];
                    }

                    if ($mapped_city) {
                        // Fetch schools from the mapped city for this level
                        $regional_sql = $wpdb->prepare(
                            "SELECT s.*,
                             (3959 * ACOS(
                                 COS(RADIANS(%f)) * COS(RADIANS(latitude)) *
                                 COS(RADIANS(longitude) - RADIANS(%f)) +
                                 SIN(RADIANS(%f)) * SIN(RADIANS(latitude))
                             )) AS distance
                             FROM {$tables['schools']} s
                             WHERE UPPER(city) = %s
                             AND latitude IS NOT NULL AND longitude IS NOT NULL
                             ORDER BY name ASC
                             LIMIT 20",
                            $lat, $lng, $lat, strtoupper($mapped_city)
                        );
                        $regional_results = $wpdb->get_results($regional_sql);

                        foreach ($regional_results as $regional_school) {
                            if (isset($added_ids[$regional_school->id])) {
                                continue;
                            }

                            $school_level = $this->determine_display_level($regional_school);
                            if ($school_level !== $missing_level) {
                                continue;
                            }

                            if (count($grouped[$missing_level]) >= 5) {
                                break;
                            }

                            // Get full data for regional school
                            $regional_mcas = $wpdb->get_var($wpdb->prepare(
                                "SELECT AVG(proficient_or_above_pct)
                                 FROM {$tables['test_scores']}
                                 WHERE school_id = %d
                                 AND year = (SELECT MAX(year) FROM {$tables['test_scores']} WHERE school_id = %d)",
                                $regional_school->id, $regional_school->id
                            ));

                            $regional_ranking = $wpdb->get_row($wpdb->prepare(
                                "SELECT composite_score, percentile_rank, state_rank, category, year
                                 FROM {$tables['rankings']}
                                 WHERE school_id = %d AND composite_score IS NOT NULL
                                 ORDER BY year DESC LIMIT 1",
                                $regional_school->id
                            ));

                            $regional_ranking_data = null;
                            if ($regional_ranking) {
                                // Get trend data
                                $prev_ranking = $wpdb->get_row($wpdb->prepare(
                                    "SELECT state_rank FROM {$tables['rankings']}
                                     WHERE school_id = %d AND year = %d - 1
                                     AND composite_score IS NOT NULL",
                                    $regional_school->id, $regional_ranking->year
                                ));

                                $trend_data = null;
                                if ($prev_ranking) {
                                    $rank_change = (int) $prev_ranking->state_rank - (int) $regional_ranking->state_rank;
                                    $direction = $rank_change > 0 ? 'up' : ($rank_change < 0 ? 'down' : 'stable');
                                    $abs_change = abs($rank_change);
                                    $trend_text = $direction === 'up' ? "Improved {$abs_change} spots from last year" :
                                                  ($direction === 'down' ? "Dropped {$abs_change} spots from last year" : "Same rank as last year");
                                    $trend_data = [
                                        'direction' => $direction,
                                        'rank_change' => $rank_change,
                                        'rank_change_text' => $trend_text,
                                    ];
                                }

                                $regional_ranking_data = [
                                    'category' => $regional_ranking->category,
                                    'category_label' => $this->format_category_label($regional_ranking->category),
                                    'composite_score' => round((float) $regional_ranking->composite_score, 1),
                                    'percentile_rank' => (int) $regional_ranking->percentile_rank,
                                    'state_rank' => (int) $regional_ranking->state_rank,
                                    'category_total' => $this->get_category_total($regional_ranking->category),
                                    'letter_grade' => BMN_Schools_Ranking_Calculator::get_letter_grade_from_percentile($regional_ranking->percentile_rank),
                                    'trend' => $trend_data,
                                ];
                            }

                            // Get demographics
                            $regional_demographics = $wpdb->get_row($wpdb->prepare(
                                "SELECT total_students, pct_free_reduced_lunch,
                                        pct_white, pct_black, pct_hispanic, pct_asian, pct_multiracial
                                 FROM {$tables['demographics']}
                                 WHERE school_id = %d
                                 ORDER BY year DESC LIMIT 1",
                                $regional_school->id
                            ));

                            $regional_demographics_data = null;
                            if ($regional_demographics) {
                                $regional_demographics_data = [
                                    'total_students' => (int) $regional_demographics->total_students,
                                    'diversity' => $this->calculate_diversity_index($regional_demographics),
                                    'pct_free_reduced_lunch' => $regional_demographics->pct_free_reduced_lunch ?
                                        round((float) $regional_demographics->pct_free_reduced_lunch, 1) : null,
                                ];
                            }

                            // Get highlights
                            $regional_highlights = BMN_Schools_Ranking_Calculator::get_school_highlights($regional_school->id);

                            // Get discipline data
                            $regional_discipline = $this->get_school_discipline($regional_school->id);

                            $grouped[$missing_level][] = [
                                'id' => (int) $regional_school->id,
                                'name' => $regional_school->name,
                                'grades' => $this->format_grades($regional_school->grades_low, $regional_school->grades_high),
                                'distance' => round($regional_school->distance, 2),
                                'address' => $regional_school->address,
                                'city' => $regional_school->city, // Include city to show it's from another town
                                'mcas_proficient_pct' => $regional_mcas ? round((float) $regional_mcas, 1) : null,
                                'latitude' => (float) $regional_school->latitude,
                                'longitude' => (float) $regional_school->longitude,
                                'ranking' => $regional_ranking_data,
                                'demographics' => $regional_demographics_data,
                                'highlights' => !empty($regional_highlights) ? $regional_highlights : null,
                                'discipline' => $regional_discipline,
                                'is_regional' => true, // Flag to indicate this is from a regional/shared district
                                'regional_note' => "Students from {$city} attend this school",
                            ];
                            $added_ids[$regional_school->id] = true;
                        }
                    }
                }
            }
        }

        // Get district
        $district = null;
        $districts = $wpdb->get_results(
            "SELECT id, name, nces_district_id, type, boundary_geojson, extra_data
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

                // Include college outcomes and discipline if available
                if (!empty($dist->extra_data)) {
                    $extra = json_decode($dist->extra_data, true);
                    if (isset($extra['college_outcomes'])) {
                        $district['college_outcomes'] = $extra['college_outcomes'];
                    }
                    if (isset($extra['discipline'])) {
                        $district['discipline'] = $this->enrich_discipline_with_percentile($extra['discipline']);
                    }
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
                'description' => 'Our proprietary 0-100 rating combining multiple data points. For middle/high schools: MCAS scores (40%), graduation rate (12%), MCAS growth (10%), AP performance (9%), MassCore completion (8%), attendance (8%), student-teacher ratio (5%), per-pupil spending (4%), and college outcomes (4%). For elementary schools: MCAS scores (45%), attendance (20%), MCAS growth (15%), per-pupil spending (12%), and student-teacher ratio (8%).',
                'parent_tip' => 'The composite score gives a holistic view, but no single number tells the whole story. Two schools with similar scores might excel in different areas. Click "View Details" to see the breakdown.',
            ],
            'letter_grade' => [
                'term' => 'Letter Grade',
                'full_name' => 'School Letter Grade (A+ to F)',
                'category' => 'rating',
                'description' => 'A simplified rating based on the school\'s percentile rank among similar schools. A+ (top 10%), A (top 20%), A- (top 30%), B+ (top 40%), B (top 50%), B- (top 60%), C+ (top 70%), C (top 80%), C- (top 90%), D (bottom 10%), F (lowest).',
                'parent_tip' => 'Letter grades make comparison easy, but remember that a "B" school (top 50%) might be perfect for your child. Consider factors like location, specific programs, and school culture alongside the grade.',
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
            'college_outcomes' => [
                'term' => 'College Outcomes',
                'full_name' => 'Post-Secondary Enrollment Rate',
                'category' => 'metric',
                'description' => 'The percentage of high school graduates who enroll in college (2-year or 4-year) within one year of graduation. Massachusetts tracks this data through the Education to Careers Hub. This metric contributes 4% to a school\'s composite score.',
                'parent_tip' => 'Look for districts where 60%+ of graduates attend college. Top districts often have 80%+ enrollment rates. Also consider 4-year vs 2-year college rates—both are valuable paths.',
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
            'suspension' => [
                'term' => 'Suspension',
                'full_name' => 'School Suspension',
                'category' => 'discipline',
                'description' => 'Temporary removal from school, either in-school (ISS) where students remain on campus in a separate room, or out-of-school (OSS) where students are sent home. Massachusetts limits OSS to 10 consecutive days without a hearing.',
                'parent_tip' => 'Ask about the school\'s re-entry process and support for returning students. Schools with low suspension rates often use alternative approaches like restorative justice.',
            ],
            'expulsion' => [
                'term' => 'Expulsion',
                'full_name' => 'School Expulsion',
                'category' => 'discipline',
                'description' => 'Permanent removal from school, typically reserved for serious violations like weapons or drugs. In Massachusetts, expelled students must be offered alternative education services.',
                'parent_tip' => 'Expulsion is rare and schools must follow due process. Massachusetts law protects students\' right to a hearing and appeal before expulsion.',
            ],
            'restorative_justice' => [
                'term' => 'Restorative Justice',
                'full_name' => 'Restorative Discipline Practices',
                'category' => 'discipline',
                'description' => 'An approach to discipline that focuses on repairing harm and restoring relationships rather than punishment. Includes peer mediation, community circles, and conflict resolution programs.',
                'parent_tip' => 'Schools using restorative practices often have lower suspension rates and better school climate. Ask if the school has trained staff in restorative approaches.',
            ],
            'discipline_rate' => [
                'term' => 'Discipline Rate',
                'full_name' => 'Student Discipline Rate',
                'category' => 'discipline',
                'description' => 'The percentage of students receiving disciplinary action (out-of-school suspension, expulsion, or emergency removal) in a school year. The Massachusetts state average is approximately 5.5%.',
                'parent_tip' => 'Lower discipline rates suggest a positive school climate with fewer behavioral incidents. Rates under 3% indicate schools that emphasize prevention and alternatives to suspension.',
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
