<?php
/**
 * REST API Endpoints for Flip Analyzer.
 *
 * Namespace: bmn-flip/v1
 * Endpoints:
 *   GET  /results                    - List scored properties (paginated)
 *   GET  /results/{listing_id}       - Single property details
 *   GET  /results/{listing_id}/comps - Comparables used for ARV
 *   GET  /summary                    - Per-city summary stats
 *   GET  /config/cities              - Get target cities
 *   POST /config/cities              - Update target cities (admin only)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_REST_API {

    const NAMESPACE = 'bmn-flip/v1';

    /**
     * Register all REST routes.
     */
    public static function register_routes(): void {
        // GET /results - List flip analysis results
        register_rest_route(self::NAMESPACE, '/results', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_results'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args'                => self::get_results_args(),
        ]);

        // GET /results/{listing_id} - Single property details
        register_rest_route(self::NAMESPACE, '/results/(?P<listing_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_result'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args'                => [
                'listing_id' => [
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // GET /results/{listing_id}/comps - Comparables for a property
        register_rest_route(self::NAMESPACE, '/results/(?P<listing_id>\d+)/comps', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_comps'],
            'permission_callback' => [__CLASS__, 'check_auth'],
            'args'                => [
                'listing_id' => [
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // GET /summary - Summary stats
        register_rest_route(self::NAMESPACE, '/summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_summary'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        // GET /config/cities - Get target cities
        register_rest_route(self::NAMESPACE, '/config/cities', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_get_cities'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);

        // POST /config/cities - Update target cities (admin only)
        register_rest_route(self::NAMESPACE, '/config/cities', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'handle_update_cities'],
            'permission_callback' => [__CLASS__, 'check_admin_auth'],
            'args'                => [
                'cities' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ]);
    }

    /**
     * Get argument definitions for results endpoint.
     */
    private static function get_results_args(): array {
        return [
            'per_page' => [
                'default'           => 20,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0 && $param <= 100;
                },
            ],
            'page' => [
                'default'           => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
            ],
            'min_score' => [
                'default'           => 0,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0 && $param <= 100;
                },
            ],
            'city' => [
                'default' => '',
                'type'    => 'string',
            ],
            'sort' => [
                'default' => 'total_score',
                'enum'    => ['total_score', 'estimated_profit', 'estimated_roi', 'list_price', 'estimated_arv'],
            ],
            'order' => [
                'default' => 'DESC',
                'enum'    => ['ASC', 'DESC'],
            ],
            'has_photos' => [
                'default' => false,
                'type'    => 'boolean',
            ],
        ];
    }

    /**
     * Check JWT authentication (requires valid token).
     */
    public static function check_auth(WP_REST_Request $request): bool|WP_Error {
        $auth_header = $request->get_header('Authorization');

        if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error(
                'rest_forbidden',
                'Authentication required.',
                ['status' => 401]
            );
        }

        $token = substr($auth_header, 7);

        // Verify JWT using MLD's JWT Handler
        if (class_exists('MLD_JWT_Handler') && method_exists('MLD_JWT_Handler', 'verify_jwt')) {
            try {
                $payload = MLD_JWT_Handler::verify_jwt($token);
                if (is_wp_error($payload)) {
                    return $payload;
                }
                // Set current user from JWT
                if (isset($payload['sub'])) {
                    wp_set_current_user($payload['sub']);
                }
                return true;
            } catch (Exception $e) {
                return new WP_Error(
                    'rest_forbidden',
                    'Invalid token: ' . $e->getMessage(),
                    ['status' => 401]
                );
            }
        }

        // Fallback: check if user is logged in via cookie
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            'Authentication required.',
            ['status' => 401]
        );
    }

    /**
     * Check admin-level authentication.
     */
    public static function check_admin_auth(WP_REST_Request $request): bool|WP_Error {
        $auth_result = self::check_auth($request);
        if (is_wp_error($auth_result)) {
            return $auth_result;
        }

        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                'Administrator access required.',
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * GET /results - List flip analysis results.
     */
    public static function handle_get_results(WP_REST_Request $request): WP_REST_Response {
        $per_page  = (int) $request->get_param('per_page');
        $page      = (int) $request->get_param('page');
        $min_score = (float) $request->get_param('min_score');
        $city      = $request->get_param('city');
        $sort      = $request->get_param('sort');
        $order     = $request->get_param('order');
        $has_photos = $request->get_param('has_photos');

        // Get total count first
        $total = self::get_total_count($min_score, $city, $has_photos);

        // Get paginated results
        $results = Flip_Database::get_results([
            'top'        => $per_page,
            'min_score'  => $min_score,
            'city'       => $city,
            'sort'       => $sort,
            'order'      => $order,
            'has_photos' => $has_photos,
        ]);

        // Format results for API response
        $properties = array_map([__CLASS__, 'format_result'], $results);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'properties' => $properties,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * GET /results/{listing_id} - Single property details.
     */
    public static function handle_get_result(WP_REST_Request $request): WP_REST_Response {
        $listing_id = (int) $request->get_param('listing_id');

        $result = Flip_Database::get_result_by_listing($listing_id);

        if (!$result) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'No flip analysis found for this property.',
            ], 404);
        }

        $formatted = self::format_result($result, true);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $formatted,
        ], 200);
    }

    /**
     * GET /results/{listing_id}/comps - Comparables for a property.
     */
    public static function handle_get_comps(WP_REST_Request $request): WP_REST_Response {
        $listing_id = (int) $request->get_param('listing_id');

        $result = Flip_Database::get_result_by_listing($listing_id);

        if (!$result) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'No flip analysis found for this property.',
            ], 404);
        }

        $comps = [];
        if (!empty($result->comp_details_json)) {
            $comps = json_decode($result->comp_details_json, true) ?: [];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'listing_id'     => $listing_id,
                'comp_count'     => $result->comp_count,
                'arv_confidence' => $result->arv_confidence,
                'avg_comp_ppsf'  => (float) $result->avg_comp_ppsf,
                'comps'          => $comps,
            ],
        ], 200);
    }

    /**
     * GET /summary - Per-city summary stats.
     */
    public static function handle_get_summary(WP_REST_Request $request): WP_REST_Response {
        $summary = Flip_Database::get_summary();

        // Format cities
        $cities = array_map(function ($city) {
            return [
                'city'      => $city->city,
                'total'     => (int) $city->total,
                'viable'    => (int) $city->viable,
                'avg_score' => round((float) $city->avg_score, 1),
            ];
        }, $summary['cities'] ?? []);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'total'        => $summary['total'],
                'viable'       => $summary['viable'],
                'avg_score'    => $summary['avg_score'],
                'avg_roi'      => $summary['avg_roi'],
                'disqualified' => $summary['disqualified'],
                'last_run'     => $summary['last_run'],
                'cities'       => $cities,
            ],
        ], 200);
    }

    /**
     * GET /config/cities - Get target cities.
     */
    public static function handle_get_cities(WP_REST_Request $request): WP_REST_Response {
        $cities = Flip_Database::get_target_cities();

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'cities' => $cities,
            ],
        ], 200);
    }

    /**
     * POST /config/cities - Update target cities.
     */
    public static function handle_update_cities(WP_REST_Request $request): WP_REST_Response {
        $cities = $request->get_param('cities');

        if (!is_array($cities)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Cities must be an array.',
            ], 400);
        }

        // Sanitize city names
        $cities = array_map('sanitize_text_field', $cities);
        $cities = array_filter($cities); // Remove empty values
        $cities = array_values(array_unique($cities)); // Remove duplicates

        Flip_Database::set_target_cities($cities);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'cities'  => $cities,
                'message' => 'Target cities updated.',
            ],
        ], 200);
    }

    /**
     * Get total count for pagination.
     */
    private static function get_total_count(float $min_score, string $city, bool $has_photos): int {
        global $wpdb;
        $table = Flip_Database::table_name();

        $latest_run = $wpdb->get_var("SELECT MAX(run_date) FROM {$table}");
        if (!$latest_run) {
            return 0;
        }

        $where = ["run_date = %s", "disqualified = 0"];
        $params = [$latest_run];

        if ($min_score > 0) {
            $where[] = "total_score >= %f";
            $params[] = $min_score;
        }

        if (!empty($city)) {
            $cities = array_map('trim', explode(',', $city));
            $placeholders = implode(',', array_fill(0, count($cities), '%s'));
            $where[] = "city IN ({$placeholders})";
            $params = array_merge($params, $cities);
        }

        if ($has_photos) {
            $where[] = "photo_score IS NOT NULL";
        }

        $where_sql = implode(' AND ', $where);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
            $params
        ));
    }

    /**
     * Format a result for API response.
     *
     * @param object $result Database row.
     * @param bool   $include_details Include full details (for single property).
     */
    private static function format_result(object $result, bool $include_details = false): array {
        $data = [
            'listing_id'          => (int) $result->listing_id,
            'address'             => $result->address,
            'city'                => $result->city,
            'list_price'          => (float) $result->list_price,
            'total_score'         => (float) $result->total_score,
            'financial_score'     => (float) $result->financial_score,
            'property_score'      => (float) $result->property_score,
            'location_score'      => (float) $result->location_score,
            'market_score'        => (float) $result->market_score,
            'photo_score'         => $result->photo_score !== null ? (float) $result->photo_score : null,
            'estimated_arv'       => (float) $result->estimated_arv,
            'arv_confidence'      => $result->arv_confidence,
            'comp_count'          => (int) $result->comp_count,
            'estimated_rehab_cost' => (float) $result->estimated_rehab_cost,
            'rehab_level'         => $result->rehab_level,
            'mao'                 => (float) $result->mao,
            'estimated_profit'    => (float) $result->estimated_profit,
            'estimated_roi'       => (float) $result->estimated_roi,
            'main_photo_url'      => $result->main_photo_url ?? '',
            'disqualified'        => (bool) $result->disqualified,
            'run_date'            => $result->run_date,
        ];

        if ($include_details) {
            // Add property details
            $data['original_list_price'] = (float) $result->original_list_price;
            $data['price_per_sqft']      = (float) $result->price_per_sqft;
            $data['building_area_total'] = (int) $result->building_area_total;
            $data['bedrooms_total']      = (int) $result->bedrooms_total;
            $data['bathrooms_total']     = (float) $result->bathrooms_total;
            $data['year_built']          = (int) $result->year_built;
            $data['lot_size_acres']      = (float) $result->lot_size_acres;
            $data['avg_comp_ppsf']       = (float) $result->avg_comp_ppsf;
            $data['listing_key']         = $result->listing_key;
            $data['disqualify_reason']   = $result->disqualify_reason;

            // Parse JSON fields
            if (!empty($result->comp_details_json)) {
                $data['comps'] = json_decode($result->comp_details_json, true) ?: [];
            } else {
                $data['comps'] = [];
            }

            if (!empty($result->remarks_signals_json)) {
                $data['remarks_signals'] = json_decode($result->remarks_signals_json, true) ?: [];
            } else {
                $data['remarks_signals'] = [];
            }

            if (!empty($result->photo_analysis_json)) {
                $data['photo_analysis'] = json_decode($result->photo_analysis_json, true) ?: [];
            } else {
                $data['photo_analysis'] = null;
            }
        }

        return $data;
    }
}
