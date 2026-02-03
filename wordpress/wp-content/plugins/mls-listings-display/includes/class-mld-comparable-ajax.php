<?php
/**
 * Enhanced Comparable Sales AJAX Handler
 *
 * @package    MLS_Listings_Display
 * @subpackage MLS_Listings_Display/includes
 * @since      5.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MLD_Comparable_Ajax {

    /**
     * Initialize AJAX hooks
     */
    public function __construct() {
        add_action('wp_ajax_get_enhanced_comparables', array($this, 'get_enhanced_comparables'));
        add_action('wp_ajax_nopriv_get_enhanced_comparables', array($this, 'get_enhanced_comparables'));
    }

    /**
     * Get enhanced comparables with filters
     */
    public function get_enhanced_comparables() {
        // Block known bots from expensive AJAX operations (prevents 504 timeouts)
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $bot_patterns = array('Googlebot', 'bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'YandexBot', 'facebookexternalhit', 'Twitterbot', 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'Bytespider');
        foreach ($bot_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                wp_send_json_error(array('message' => 'Not available for automated requests'), 403);
                return;
            }
        }

        // Check nonce BEFORE rate limiting (v6.68.23)
        // This prevents attackers from exhausting rate limits without valid nonces
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Rate limiting: 30 requests/minute for logged-in users, 15 for anonymous
        // v6.68.23: Increased limits and added CDN IP detection
        $client_ip = $this->get_client_ip();
        $rate_limit_key = 'mld_comp_rate_' . md5($client_ip);
        $request_count = (int) get_transient($rate_limit_key);

        // Higher limit for authenticated users (comparing properties, adjusting filters)
        $rate_limit = is_user_logged_in() ? 30 : 15;

        if ($request_count >= $rate_limit) {
            wp_send_json_error(array(
                'message' => 'Rate limit exceeded. Please wait a moment before making more requests.',
                'retry_after' => 60
            ), 429);
            return;
        }
        set_transient($rate_limit_key, $request_count + 1, MINUTE_IN_SECONDS);

        try {
            // Get subject property data
            $subject = array(
                'listing_id' => sanitize_text_field($_POST['listing_id'] ?? ''),
                'lat' => floatval($_POST['lat'] ?? 0),
                'lng' => floatval($_POST['lng'] ?? 0),
                'price' => floatval($_POST['price'] ?? 0),
                'beds' => intval($_POST['beds'] ?? 0),
                'baths' => floatval($_POST['baths'] ?? 0),
                'sqft' => intval($_POST['sqft'] ?? 0),
                'property_type' => sanitize_text_field($_POST['property_type'] ?? ''),
                'year_built' => intval($_POST['year_built'] ?? 0),
                'garage_spaces' => intval($_POST['garage_spaces'] ?? 0),
                'pool' => filter_var($_POST['pool'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'waterfront' => filter_var($_POST['waterfront'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'road_type' => sanitize_text_field($_POST['road_type'] ?? ''),
                'property_condition' => sanitize_text_field($_POST['property_condition'] ?? ''),
                'city' => sanitize_text_field($_POST['city'] ?? ''),
                'state' => sanitize_text_field($_POST['state'] ?? '')
            );

            // v6.68.23: Validate coordinates before processing
            if ($subject['lat'] !== 0 && ($subject['lat'] < -90 || $subject['lat'] > 90)) {
                wp_send_json_error(array('message' => 'Invalid latitude coordinate'));
                return;
            }
            if ($subject['lng'] !== 0 && ($subject['lng'] < -180 || $subject['lng'] > 180)) {
                wp_send_json_error(array('message' => 'Invalid longitude coordinate'));
                return;
            }

            // Get filter settings from user with validation
            $filters = array(
                // v6.68.23: Clamp radius to reasonable bounds (0.5 to 100 miles)
                'radius' => max(0.5, min(100, floatval($_POST['radius'] ?? 3))),
                // v6.68.23: Clamp percentage ranges (1 to 100)
                'price_range_pct' => max(1, min(100, intval($_POST['price_range_pct'] ?? 15))),
                'sqft_range_pct' => max(1, min(100, intval($_POST['sqft_range_pct'] ?? 20))),
                'beds_min' => !empty($_POST['beds_min']) ? max(0, min(20, intval($_POST['beds_min']))) : null,
                'beds_max' => !empty($_POST['beds_max']) ? max(0, min(20, intval($_POST['beds_max']))) : null,
                'beds_exact' => filter_var($_POST['beds_exact'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'baths_min' => !empty($_POST['baths_min']) ? max(0, min(20, floatval($_POST['baths_min']))) : null,
                'baths_max' => !empty($_POST['baths_max']) ? max(0, min(20, floatval($_POST['baths_max']))) : null,
                'garage_min' => !empty($_POST['garage_min']) ? max(0, min(10, intval($_POST['garage_min']))) : null,
                'garage_exact' => filter_var($_POST['garage_exact'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'year_built_range' => max(1, min(100, intval($_POST['year_built_range'] ?? 10))),
                'lot_size_min' => !empty($_POST['lot_size_min']) ? max(0, floatval($_POST['lot_size_min'])) : null,
                'lot_size_max' => !empty($_POST['lot_size_max']) ? max(0, floatval($_POST['lot_size_max'])) : null,
                'pool_required' => isset($_POST['pool_required']) ? filter_var($_POST['pool_required'], FILTER_VALIDATE_BOOLEAN) : null,
                'waterfront_only' => filter_var($_POST['waterfront_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'same_city_only' => filter_var($_POST['same_city_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'statuses' => $this->validate_statuses($_POST['statuses'] ?? null),
                'months_back' => max(1, min(60, intval($_POST['months_back'] ?? 12))),
                'max_dom' => !empty($_POST['max_dom']) ? max(1, min(1000, intval($_POST['max_dom']))) : null,
                'hoa_max' => !empty($_POST['hoa_max']) ? max(0, floatval($_POST['hoa_max'])) : null,
                'exclude_hoa' => filter_var($_POST['exclude_hoa'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort_by' => sanitize_text_field($_POST['sort_by'] ?? 'similarity'),
                // v6.68.23: Clamp limit to prevent excessive results (1 to 100)
                'limit' => max(1, min(100, intval($_POST['limit'] ?? 20)))
            );

            // Debug logging (reduced - print_r is expensive)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Comparable Ajax] Subject: ' . $subject['listing_id'] . ' in ' . $subject['city']);
            }

            // Validate required fields
            if (!$subject['lat'] || !$subject['lng']) {
                error_log('[MLD Comparable Ajax] Missing lat/lng - lat: ' . $subject['lat'] . ', lng: ' . $subject['lng']);
                wp_send_json_error('Missing location data');
                return;
            }

            // Check cache first (30-minute TTL per property + ALL filter params)
            // v6.68.23: Include all filter parameters in cache key to prevent incorrect cached results
            // v6.74.3: Fixed - JSON_SORT_KEYS doesn't exist in PHP, use ksort() instead
            $filters_sorted = $filters;
            ksort($filters_sorted);
            $cache_key = 'mld_comps_v2_' . md5(
                $subject['listing_id'] . '|' .
                json_encode($filters_sorted, JSON_NUMERIC_CHECK)
            );
            $cached_response = get_transient($cache_key);
            if ($cached_response !== false) {
                wp_send_json_success($cached_response);
                return;
            }

            // Get comparables using enhanced engine
            require_once plugin_dir_path(__FILE__) . 'class-mld-comparable-sales.php';
            $comp_engine = new MLD_Comparable_Sales();
            $results = $comp_engine->find_comparables($subject, $filters);

            // Debug: Log comparable count only (reduced logging for performance)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $comp_count = !empty($results['comparables']) ? count($results['comparables']) : 0;
                error_log('[MLD Comparable Ajax] Found ' . $comp_count . ' comparables');
            }

            // Get market context (already computed during find_comparables, reuse if available)
            $market_context = isset($results['market_context'])
                ? $results['market_context']
                : $comp_engine->get_market_context($subject['city'], $subject['state']);

            // Format response
            $response = array(
                'success' => true,
                'comparables' => $results['comparables'],
                'summary' => $results['summary'],
                'market_context' => $market_context,
                'filters_applied' => $results['filters_applied'] ?? array(),
                'subject_property' => $subject,
                // Debug info (minimal in production)
                '_debug' => array(
                    'comparables_count' => count($results['comparables']),
                    'cached' => false
                )
            );

            // Cache the response for 30 minutes
            set_transient($cache_key, $response, 30 * MINUTE_IN_SECONDS);

            wp_send_json_success($response);

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Comparable Ajax] Error: ' . $e->getMessage());
            }
            wp_send_json_error('Failed to fetch comparables: ' . $e->getMessage());
        }
    }

    /**
     * Validate and sanitize status values against allowed whitelist
     *
     * Prevents injection of arbitrary status values in queries
     *
     * @since 6.68.23
     * @param string|null $statuses_json JSON-encoded array of statuses
     * @return array Validated array of status values
     */
    private function validate_statuses($statuses_json) {
        // Allowed status values (matches database values)
        $allowed_statuses = array(
            'Active',
            'Pending',
            'Active Under Contract',
            'Closed',
            'Withdrawn',
            'Expired',
            'Canceled',
            'Coming Soon'
        );

        // Default to Closed if no input
        if (empty($statuses_json)) {
            return array('Closed');
        }

        // Decode JSON input
        $statuses = json_decode(stripslashes($statuses_json), true);

        // Handle JSON decode failure
        if (!is_array($statuses)) {
            error_log('[MLD Comparable Ajax] Invalid statuses JSON: ' . $statuses_json);
            return array('Closed');
        }

        // Filter to only allowed values
        $validated = array_filter($statuses, function($status) use ($allowed_statuses) {
            return in_array($status, $allowed_statuses, true);
        });

        // Return at least one status (default to Closed)
        return !empty($validated) ? array_values($validated) : array('Closed');
    }

    /**
     * Get client IP address with CDN/proxy support
     *
     * Checks forwarded headers for the real client IP when behind CDN (Kinsta, Cloudflare, etc.)
     *
     * @since 6.68.23
     * @return string Client IP address
     */
    private function get_client_ip() {
        // Check X-Forwarded-For header (CDN/proxy environments like Kinsta)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can contain multiple IPs, take the first (original client)
            $forwarded_ips = explode(',', sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']));
            $client_ip = trim($forwarded_ips[0]);
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        // Check X-Real-IP header (Nginx proxy)
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $client_ip = sanitize_text_field($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        // Check CF-Connecting-IP header (Cloudflare)
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $client_ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        // Fall back to REMOTE_ADDR
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    }
}

// Initialize
new MLD_Comparable_Ajax();
