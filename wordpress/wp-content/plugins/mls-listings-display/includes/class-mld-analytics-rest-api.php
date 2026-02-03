<?php
/**
 * Property Analytics REST API Endpoint
 *
 * Provides REST API endpoints for property page analytics.
 * Fixed v6.13.15 - Returns correct data for each tab.
 *
 * @package MLS_Listings_Display
 * @since 6.12.8
 * @version 6.13.15
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register REST API routes for property analytics
 */
function mld_register_property_analytics_rest_routes() {
    register_rest_route('mld/v1', '/property-analytics/(?P<city>[^/]+)', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'mld_handle_property_analytics_request',
        'permission_callback' => '__return_true',
        'args' => array(
            'city' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'state' => array(
                'default' => 'MA',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'tab' => array(
                'default' => 'overview',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'lite' => array(
                'default' => 'false',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'property_type' => array(
                'default' => 'all',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    // City comparison endpoint
    register_rest_route('mld/v1', '/city-comparison', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'mld_handle_city_comparison_request',
        'permission_callback' => '__return_true',
        'args' => array(
            'cities' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'state' => array(
                'default' => 'MA',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    // Available cities endpoint
    register_rest_route('mld/v1', '/available-cities', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'mld_handle_available_cities_request',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'mld_register_property_analytics_rest_routes');

/**
 * Handle property analytics REST request
 *
 * @param WP_REST_Request $request REST request object
 * @return WP_REST_Response|WP_Error Response or error
 */
function mld_handle_property_analytics_request($request) {
    $city = urldecode($request->get_param('city'));
    $state = $request->get_param('state');
    $tab = $request->get_param('tab');
    $lite = $request->get_param('lite') === 'true';
    $property_type = $request->get_param('property_type');

    if (empty($city)) {
        return new WP_Error('missing_city', 'City parameter is required', array('status' => 400));
    }

    // Ensure the analytics class is loaded
    if (!class_exists('MLD_Extended_Analytics')) {
        if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php';
        } else {
            return new WP_Error('analytics_unavailable', 'Analytics module not available', array('status' => 500));
        }
    }

    try {
        $response_data = array();

        // Always get city summary for all tabs
        $city_summary = MLD_Extended_Analytics::get_city_summary($city, $state, $property_type);
        if (empty($city_summary)) {
            $city_summary = MLD_Extended_Analytics::calculate_city_summary($city, $state, $property_type);
        }
        $response_data['city_summary'] = $city_summary ?: array();

        // Get market heat data
        $market_heat = MLD_Extended_Analytics::get_market_heat_index($city, $state, $property_type);
        $response_data['market_heat'] = $market_heat ?: array(
            'score' => 0,
            'classification' => 'Unknown'
        );

        // Add tab-specific data
        if (!$lite || $tab !== 'overview') {
            $tab_data = mld_get_tab_specific_data($city, $state, $property_type, $tab);
            if ($tab_data) {
                $response_data = array_merge($response_data, $tab_data);
            }
        }

        // Add metadata
        $response_data['meta'] = array(
            'city' => $city,
            'state' => $state,
            'property_type' => $property_type,
            'tab' => $tab,
            'lite' => $lite,
            'timestamp' => current_time('mysql'),
        );

        return new WP_REST_Response($response_data, 200);

    } catch (Exception $e) {
        error_log('[MLD Property Analytics REST] Error: ' . $e->getMessage());
        return new WP_Error(
            'analytics_error',
            'Failed to retrieve analytics data: ' . $e->getMessage(),
            array('status' => 500)
        );
    }
}

/**
 * Get tab-specific analytics data
 *
 * @param string $city City name
 * @param string $state State code
 * @param string $property_type Property type filter
 * @param string $tab Tab identifier
 * @return array Tab data
 */
function mld_get_tab_specific_data($city, $state, $property_type, $tab) {
    $data = array();

    switch ($tab) {
        case 'trends':
            // Get monthly price trends
            $trends = MLD_Extended_Analytics::get_price_trends($city, $state, 24, $property_type);
            $data['monthly_trends'] = $trends ?: array();
            break;

        case 'supply':
            // Get supply & demand metrics
            $supply_demand = MLD_Extended_Analytics::get_supply_demand_metrics($city, $state, $property_type);
            $data['supply_demand'] = $supply_demand ?: array();
            break;

        case 'velocity':
            // Get days on market analysis
            $dom = MLD_Extended_Analytics::get_dom_analysis($city, $state, $property_type);
            $data['dom_analysis'] = $dom ?: array();
            break;

        case 'comparison':
            // Comparison is handled via separate endpoint
            // Just return empty - JS will use /city-comparison
            break;

        case 'agents':
            // Get top agents (admin only for privacy)
            if (current_user_can('manage_options')) {
                $agents = MLD_Extended_Analytics::get_top_agents($city, $state, 20, 12);
                $data['top_agents'] = $agents ?: array();
            } else {
                $data['top_agents'] = array();
            }
            break;

        case 'yoy':
            // Year-over-year comparison - get from financial analysis
            $financial = MLD_Extended_Analytics::get_financial_analysis($city, $state, $property_type, 24);
            if ($financial && isset($financial['yoy_comparison'])) {
                $data['yoy_comparison'] = $financial['yoy_comparison'];
            } else {
                // Build from city summary
                $summary = MLD_Extended_Analytics::get_city_summary($city, $state, $property_type);
                if ($summary) {
                    $data['yoy_comparison'] = array(array(
                        'price_change_pct' => $summary['yoy_price_change_pct'] ?? 0,
                        'volume_change_pct' => $summary['yoy_sales_change_pct'] ?? 0,
                        'current_avg_dom' => $summary['avg_dom_12m'] ?? 0,
                        'previous_avg_dom' => null, // Not available separately
                    ));
                } else {
                    $data['yoy_comparison'] = array();
                }
            }
            break;

        case 'property':
            // Price analysis by bedrooms
            $price_by_beds = MLD_Extended_Analytics::get_price_by_bedrooms($city, $state, 12);
            $data['price_by_beds'] = $price_by_beds ?: array();
            break;

        case 'features':
            // Feature premiums
            $premiums = MLD_Extended_Analytics::get_all_feature_premiums($city, $state, $property_type, 24);
            $data['feature_premiums'] = $premiums ?: array();
            break;

        case 'overview':
        default:
            // Overview just uses city_summary and market_heat already included
            break;
    }

    return $data;
}

/**
 * Handle city comparison request
 *
 * @param WP_REST_Request $request REST request object
 * @return WP_REST_Response|WP_Error Response or error
 */
function mld_handle_city_comparison_request($request) {
    $cities_str = $request->get_param('cities');
    $state = $request->get_param('state');

    if (empty($cities_str)) {
        return new WP_Error('missing_cities', 'Cities parameter is required', array('status' => 400));
    }

    // Ensure analytics class is loaded
    if (!class_exists('MLD_Extended_Analytics')) {
        if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php';
        } else {
            return new WP_Error('analytics_unavailable', 'Analytics module not available', array('status' => 500));
        }
    }

    $cities = array_map('trim', explode(',', $cities_str));
    $comparison = MLD_Extended_Analytics::compare_cities($cities, 'all');
    $metrics = MLD_Extended_Analytics::get_comparison_metrics();

    return new WP_REST_Response(array(
        'comparison' => $comparison,
        'metrics' => $metrics,
    ), 200);
}

/**
 * Handle available cities request
 *
 * @param WP_REST_Request $request REST request object
 * @return WP_REST_Response|WP_Error Response or error
 */
function mld_handle_available_cities_request($request) {
    // Ensure analytics class is loaded
    if (!class_exists('MLD_Extended_Analytics')) {
        if (file_exists(MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php')) {
            require_once MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php';
        } else {
            return new WP_Error('analytics_unavailable', 'Analytics module not available', array('status' => 500));
        }
    }

    $cities = MLD_Extended_Analytics::get_available_cities(10);

    return new WP_REST_Response($cities ?: array(), 200);
}
