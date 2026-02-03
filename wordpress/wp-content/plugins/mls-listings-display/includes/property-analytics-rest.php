<?php
/**
 * Property Analytics REST API Endpoint
 *
 * Provides REST API endpoints for property page analytics.
 * This file should be included in the main plugin file.
 *
 * @package MLS_Listings_Display
 * @since 6.13.15
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
        'permission_callback' => '__return_true', // Public endpoint
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

        // Get city summary data
        $city_summary = MLD_Extended_Analytics::get_city_summary($city, $state, $property_type);

        if (empty($city_summary)) {
            // Try to calculate if not cached
            $city_summary = MLD_Extended_Analytics::calculate_city_summary($city, $state, $property_type);
        }

        $response_data['city_summary'] = $city_summary ?: array();

        // Get market heat data
        $market_heat = MLD_Extended_Analytics::get_market_heat_index($city, $state, $property_type);
        $response_data['market_heat'] = $market_heat ?: array(
            'score' => 0,
            'classification' => 'Unknown'
        );

        // Add tab-specific data for non-lite requests
        if (!$lite && $tab !== 'overview') {
            $tab_data = mld_get_analytics_tab_data($city, $state, $property_type, $tab);
            if ($tab_data) {
                $response_data['tab_data'] = $tab_data;
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
 * @return array|null Tab data or null
 */
function mld_get_analytics_tab_data($city, $state, $property_type, $tab) {
    switch ($tab) {
        case 'pricing':
            return MLD_Extended_Analytics::get_pricing_data($city, $state, $property_type);
        case 'inventory':
            return MLD_Extended_Analytics::get_inventory_trends($city, $state, $property_type);
        case 'time':
            return MLD_Extended_Analytics::get_time_on_market_data($city, $state, $property_type);
        case 'activity':
            return MLD_Extended_Analytics::get_market_activity_data($city, $state, $property_type);
        default:
            return null;
    }
}
