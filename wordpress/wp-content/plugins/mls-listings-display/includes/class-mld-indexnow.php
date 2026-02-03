<?php
/**
 * IndexNow API Integration for MLS Listings Display
 *
 * IndexNow is a protocol that allows websites to notify search engines
 * about URL changes instantly. Supported by Bing, Yandex, Seznam.
 * Google is expected to join soon.
 *
 * @package MLS_Listings_Display
 * @since 6.14.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_IndexNow {

    const API_ENDPOINT = 'https://api.indexnow.org/indexnow';
    const OPTION_KEY = 'mld_indexnow_api_key';
    const MAX_URLS_PER_REQUEST = 10000;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Create key file route
        add_action('init', array($this, 'add_key_file_rewrite'));
        add_action('template_redirect', array($this, 'serve_key_file'));
        add_filter('query_vars', array($this, 'add_query_vars'));

        // Hook into sitemap regeneration to submit URLs
        add_action('mld_regenerate_new_listings_sitemap', array($this, 'submit_new_listings'), 20);
        add_action('mld_regenerate_modified_listings_sitemap', array($this, 'submit_modified_listings'), 20);

        // Hook into full sitemap regeneration to submit location URLs
        add_action('mld_sitemaps_regenerated', array($this, 'submit_location_urls'), 10);

        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add rewrite rule for key file
     */
    public function add_key_file_rewrite() {
        $key = $this->get_or_create_key();
        add_rewrite_rule('^' . preg_quote($key) . '\.txt$', 'index.php?mld_indexnow_key=1', 'top');
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'mld_indexnow_key';
        return $vars;
    }

    /**
     * Serve the key file
     */
    public function serve_key_file() {
        if (!get_query_var('mld_indexnow_key')) {
            return;
        }

        $key = $this->get_or_create_key();

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=86400');
        echo $key;
        exit;
    }

    /**
     * Get or create the IndexNow API key
     *
     * @return string The API key
     */
    public function get_or_create_key() {
        $key = get_option(self::OPTION_KEY);

        if (empty($key)) {
            // Generate a random 32-character hexadecimal key
            $key = bin2hex(random_bytes(16));
            update_option(self::OPTION_KEY, $key, false);

            // Flush rewrite rules to register new key file URL
            flush_rewrite_rules();
        }

        return $key;
    }

    /**
     * Submit URLs to IndexNow API
     *
     * @param array $urls Array of URLs to submit
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function submit_urls($urls) {
        if (empty($urls)) {
            return true;
        }

        // Check if IndexNow is enabled
        if (!$this->is_enabled()) {
            return true;
        }

        $key = $this->get_or_create_key();
        $host = parse_url(home_url(), PHP_URL_HOST);

        // Limit to max URLs per request
        $urls = array_slice($urls, 0, self::MAX_URLS_PER_REQUEST);

        $body = array(
            'host' => $host,
            'key' => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList' => array_values($urls)
        );

        $response = wp_remote_post(self::API_ENDPOINT, array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('MLD IndexNow Error: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // Log successful submissions
        if ($response_code === 200 || $response_code === 202) {
            error_log(sprintf('MLD IndexNow: Successfully submitted %d URLs', count($urls)));
            return true;
        }

        // Log failures
        $body = wp_remote_retrieve_body($response);
        error_log(sprintf('MLD IndexNow Error: HTTP %d - %s', $response_code, $body));

        return new WP_Error('indexnow_error', sprintf('IndexNow returned HTTP %d', $response_code));
    }

    /**
     * Submit new listings to IndexNow
     */
    public function submit_new_listings() {
        global $wpdb;

        // Use WordPress timezone
        $wp_now = current_time('mysql');

        // Get new listings from last 48 hours
        $listings = $wpdb->get_results($wpdb->prepare("
            SELECT listing_id, street_number, street_name, unit_number, city,
                   state_or_province, postal_code, bedrooms_total, bathrooms_total,
                   property_type, standard_status
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND listing_contract_date >= DATE_SUB(%s, INTERVAL 48 HOUR)
            ORDER BY listing_contract_date DESC
            LIMIT 1000
        ", $wp_now), ARRAY_A);

        if (empty($listings)) {
            return;
        }

        $urls = array();
        foreach ($listings as $listing) {
            $url = MLD_URL_Helper::get_property_url($listing);
            if (!empty($url)) {
                $urls[] = $url;
            }
        }

        if (!empty($urls)) {
            $this->submit_urls($urls);
        }
    }

    /**
     * Submit modified listings to IndexNow
     */
    public function submit_modified_listings() {
        global $wpdb;

        // Use WordPress timezone
        $wp_now = current_time('mysql');

        // Get modified listings from last 24 hours (excluding new ones)
        $listings = $wpdb->get_results($wpdb->prepare("
            SELECT listing_id, street_number, street_name, unit_number, city,
                   state_or_province, postal_code, bedrooms_total, bathrooms_total,
                   property_type, standard_status
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND modification_timestamp >= DATE_SUB(%s, INTERVAL 24 HOUR)
                AND listing_contract_date < DATE_SUB(%s, INTERVAL 48 HOUR)
            ORDER BY modification_timestamp DESC
            LIMIT 1000
        ", $wp_now, $wp_now), ARRAY_A);

        if (empty($listings)) {
            return;
        }

        $urls = array();
        foreach ($listings as $listing) {
            $url = MLD_URL_Helper::get_property_url($listing);
            if (!empty($url)) {
                $urls[] = $url;
            }
        }

        if (!empty($urls)) {
            $this->submit_urls($urls);
        }
    }

    /**
     * Submit city and neighborhood location URLs to IndexNow
     *
     * Called after full sitemap regeneration to notify search engines
     * about location pages (cities and neighborhoods)
     */
    public function submit_location_urls() {
        global $wpdb;

        $urls = array();

        // Get city URLs
        $cities = $wpdb->get_results("
            SELECT DISTINCT
                city,
                state_or_province,
                SUM(CASE WHEN property_type LIKE '%Lease%' OR property_type LIKE '%Rental%' THEN 1 ELSE 0 END) as rental_count,
                SUM(CASE WHEN property_type NOT LIKE '%Lease%' AND property_type NOT LIKE '%Rental%' THEN 1 ELSE 0 END) as sale_count
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND city IS NOT NULL
                AND city != ''
                AND state_or_province IS NOT NULL
            GROUP BY city, state_or_province
            ORDER BY (sale_count + rental_count) DESC
            LIMIT 500
        ");

        foreach ($cities as $city) {
            $city_slug = sanitize_title($city->city);
            $state_slug = strtolower($city->state_or_province);

            // Theme URL format: /boston/
            if ($city->sale_count > 0) {
                $urls[] = home_url("/{$city_slug}/");
            }

            // Rentals URL: /boston/rentals/
            if ($city->rental_count > 0) {
                $urls[] = home_url("/{$city_slug}/rentals/");
            }

            // SEO URL format: /homes-for-sale-in-boston-ma/
            $urls[] = home_url("/homes-for-sale-in-{$city_slug}-{$state_slug}/");
        }

        // Get neighborhood URLs
        $neighborhoods = $wpdb->get_results("
            SELECT
                loc.mls_area_major as neighborhood,
                SUM(CASE WHEN s.property_type LIKE '%Lease%' OR s.property_type LIKE '%Rental%' THEN 1 ELSE 0 END) as rental_count,
                SUM(CASE WHEN s.property_type NOT LIKE '%Lease%' AND s.property_type NOT LIKE '%Rental%' THEN 1 ELSE 0 END) as sale_count
            FROM {$wpdb->prefix}bme_listing_location loc
            INNER JOIN {$wpdb->prefix}bme_listings li ON loc.listing_id = li.listing_id
            INNER JOIN {$wpdb->prefix}bme_listing_summary s ON li.listing_id = s.listing_id
            WHERE li.standard_status = 'Active'
                AND loc.mls_area_major IS NOT NULL
                AND loc.mls_area_major != ''
            GROUP BY loc.mls_area_major
            ORDER BY (sale_count + rental_count) DESC
            LIMIT 500
        ");

        foreach ($neighborhoods as $neighborhood) {
            $neighborhood_slug = sanitize_title($neighborhood->neighborhood);

            if (empty($neighborhood_slug)) {
                continue;
            }

            // Neighborhood sales URL: /back-bay/
            if ($neighborhood->sale_count > 0) {
                $urls[] = home_url("/{$neighborhood_slug}/");
            }

            // Neighborhood rentals URL: /back-bay/rentals/
            if ($neighborhood->rental_count > 0) {
                $urls[] = home_url("/{$neighborhood_slug}/rentals/");
            }
        }

        // Remove duplicates
        $urls = array_unique($urls);

        if (!empty($urls)) {
            error_log(sprintf('MLD IndexNow: Submitting %d location URLs (cities + neighborhoods)', count($urls)));
            $this->submit_urls($urls);
        }
    }

    /**
     * Submit specific URLs (for manual or on-demand submission)
     *
     * @param array $listing_ids Array of listing IDs
     * @return bool|WP_Error
     */
    public function submit_listings_by_id($listing_ids) {
        global $wpdb;

        if (empty($listing_ids)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));

        $listings = $wpdb->get_results($wpdb->prepare("
            SELECT listing_id, street_number, street_name, unit_number, city,
                   state_or_province, postal_code, bedrooms_total, bathrooms_total,
                   property_type, standard_status
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE listing_id IN ({$placeholders})
        ", $listing_ids), ARRAY_A);

        $urls = array();
        foreach ($listings as $listing) {
            $url = MLD_URL_Helper::get_property_url($listing);
            if (!empty($url)) {
                $urls[] = $url;
            }
        }

        return $this->submit_urls($urls);
    }

    /**
     * Check if IndexNow is enabled
     *
     * @return bool
     */
    public function is_enabled() {
        return get_option('mld_indexnow_enabled', true);
    }

    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('mld_seo_settings', 'mld_indexnow_enabled', array(
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
    }

    /**
     * Get statistics for admin display
     *
     * @return array
     */
    public function get_stats() {
        return array(
            'enabled' => $this->is_enabled(),
            'api_key' => $this->get_or_create_key(),
            'key_file_url' => home_url('/' . $this->get_or_create_key() . '.txt'),
            'api_endpoint' => self::API_ENDPOINT
        );
    }

    /**
     * Verify the key file is accessible
     *
     * @return bool|WP_Error
     */
    public function verify_key_file() {
        $key = $this->get_or_create_key();
        $key_url = home_url('/' . $key . '.txt');

        $response = wp_remote_get($key_url, array('timeout' => 10));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return new WP_Error('key_file_not_found', sprintf('Key file returned HTTP %d', $code));
        }

        if (trim($body) !== $key) {
            return new WP_Error('key_mismatch', 'Key file content does not match expected key');
        }

        return true;
    }
}

// Initialize
if (did_action('plugins_loaded')) {
    MLD_IndexNow::get_instance();
} else {
    add_action('plugins_loaded', function() {
        MLD_IndexNow::get_instance();
    });
}
