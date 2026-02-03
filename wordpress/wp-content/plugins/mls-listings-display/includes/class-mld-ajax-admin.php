<?php
/**
 * MLD Admin AJAX Handler
 * Handles admin-side AJAX requests
 *
 * @package MLS_Listings_Display
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Ajax_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_mld_test_api', array($this, 'test_api_connection'));
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mld_admin_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $api = sanitize_text_field($_POST['api'] ?? '');
        
        switch ($api) {
            case 'walkscore':
                $this->test_walkscore_api();
                break;
                
            case 'googlemaps':
                $this->test_google_maps_api();
                break;
                
            // Mapbox testing removed - Google Maps only for performance optimization
                
            default:
                wp_send_json_error(array('message' => 'Unknown API'));
        }
    }
    
    /**
     * Test Walk Score API
     */
    private function test_walkscore_api() {
        $api_key = MLD_Settings::get_walk_score_api_key();
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key not configured'));
            return;
        }
        
        // Test with a known location (Seattle Space Needle)
        $test_address = '400 Broad St, Seattle, WA 98109';
        $test_lat = 47.6205;
        $test_lon = -122.3493;
        
        $url = add_query_arg(array(
            'format' => 'json',
            'address' => urlencode($test_address),
            'lat' => $test_lat,
            'lon' => $test_lon,
            'transit' => 1,
            'bike' => 1,
            'wsapikey' => $api_key
        ), 'https://api.walkscore.com/score');
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Connection failed: ' . $response->get_error_message()
            ));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!empty($data['status']) && $data['status'] == 1) {
            wp_send_json_success(array(
                'message' => 'Connected successfully',
                'walkscore' => $data['walkscore'] ?? 'N/A',
                'transit' => $data['transit']['score'] ?? 'N/A',
                'bike' => $data['bike']['score'] ?? 'N/A'
            ));
        } else {
            $error_msg = $data['description'] ?? 'Unknown error';
            wp_send_json_error(array('message' => 'API Error: ' . $error_msg));
        }
    }
    
    /**
     * Test Google Maps API
     */
    private function test_google_maps_api() {
        $api_key = MLD_Settings::get_google_maps_api_key();
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key not configured'));
            return;
        }
        
        // Test geocoding API
        $url = add_query_arg(array(
            'address' => '1600 Amphitheatre Parkway, Mountain View, CA',
            'key' => $api_key
        ), 'https://maps.googleapis.com/maps/api/geocode/json');
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Connection failed: ' . $response->get_error_message()
            ));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!empty($data['status'])) {
            if ($data['status'] === 'OK') {
                wp_send_json_success(array(
                    'message' => 'Connected successfully',
                    'location' => $data['results'][0]['formatted_address'] ?? ''
                ));
            } else {
                $error_msg = $this->get_google_maps_error($data['status']);
                wp_send_json_error(array('message' => 'API Error: ' . $error_msg));
            }
        } else {
            wp_send_json_error(array('message' => 'Invalid response from Google Maps'));
        }
    }
    
    // Mapbox API testing method removed - Google Maps only for performance optimization
    
    /**
     * Get Google Maps error message
     */
    private function get_google_maps_error($status) {
        $errors = array(
            'REQUEST_DENIED' => 'API key is invalid or missing required APIs',
            'OVER_QUERY_LIMIT' => 'Query limit exceeded',
            'ZERO_RESULTS' => 'No results found (API working correctly)',
            'INVALID_REQUEST' => 'Invalid request',
            'UNKNOWN_ERROR' => 'Server error, please try again'
        );
        
        return $errors[$status] ?? $status;
    }
}

// Initialize
new MLD_Ajax_Admin();