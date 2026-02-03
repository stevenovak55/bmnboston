<?php
/**
 * Incremental Sitemap Generator for MLS Listings Display
 * 
 * Generates separate sitemaps for:
 * - New listings (last 48 hours) - regenerates every 15 minutes
 * - Modified listings (last 24 hours) - regenerates every hour
 * - Full property sitemap - regenerates daily
 * 
 * This approach is 90x more efficient than regenerating all properties frequently.
 *
 * @package MLS_Listings_Display
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Incremental_Sitemaps {

    const CACHE_DIR = WP_CONTENT_DIR . '/cache/mld-sitemaps/';
    const URLS_PER_SITEMAP = 45000;
    
    // Time windows for different sitemap types
    const NEW_LISTINGS_WINDOW = 48; // hours
    const MODIFIED_LISTINGS_WINDOW = 24; // hours

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
        // Register rewrite rules for new sitemap types
        add_action('init', array($this, 'add_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_sitemap_request'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        
        // Register cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('wp', array($this, 'schedule_regeneration'));
        
        // Cron hooks for different sitemap types
        add_action('mld_regenerate_new_listings_sitemap', array($this, 'regenerate_new_listings'));
        add_action('mld_regenerate_modified_listings_sitemap', array($this, 'regenerate_modified_listings'));
        add_action('mld_regenerate_full_sitemap', array($this, 'regenerate_full_sitemap'));
        
        // Update robots.txt
        add_filter('robots_txt', array($this, 'add_sitemaps_to_robots'), 10, 2);
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        // Every 15 minutes
        $schedules['every_15_minutes'] = array(
            'interval' => 15 * 60,
            'display'  => __('Every 15 Minutes')
        );

        // Every 30 minutes for more frequent modified listings updates
        $schedules['every_30_minutes'] = array(
            'interval' => 30 * 60,
            'display'  => __('Every 30 Minutes')
        );

        // Every 6 hours for secondary sitemaps
        $schedules['every_6_hours'] = array(
            'interval' => 6 * 60 * 60,
            'display'  => __('Every 6 Hours')
        );

        // Every 12 hours for full sitemap
        $schedules['every_12_hours'] = array(
            'interval' => 12 * 60 * 60,
            'display'  => __('Every 12 Hours')
        );

        return $schedules;
    }

    /**
     * Add rewrite rules for incremental sitemaps
     */
    public function add_rewrite_rules() {
        add_rewrite_rule('^new-listings-sitemap\.xml$', 'index.php?mld_sitemap=new-listings', 'top');
        add_rewrite_rule('^modified-listings-sitemap\.xml$', 'index.php?mld_sitemap=modified-listings', 'top');
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        return $vars; // Already handled by main sitemap generator
    }

    /**
     * Handle sitemap requests
     */
    public function handle_sitemap_request() {
        $sitemap_type = get_query_var('mld_sitemap');

        if (!$sitemap_type) {
            return;
        }

        // Only handle our specific sitemap types
        if (!in_array($sitemap_type, array('new-listings', 'modified-listings'))) {
            return;
        }

        // Clean any output buffers to prevent interference
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $xml = '';
            switch ($sitemap_type) {
                case 'new-listings':
                    $xml = $this->generate_new_listings_sitemap();
                    break;

                case 'modified-listings':
                    $xml = $this->generate_modified_listings_sitemap();
                    break;
            }

            if (empty($xml)) {
                status_header(404);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Sitemap not found or empty';
                exit;
            }

            // Set proper HTTP headers for Googlebot compatibility
            status_header(200);
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: public, max-age=900'); // 15 min cache for incremental
            header('Content-Length: ' . strlen($xml));
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('X-Robots-Tag: noindex, follow');

            echo $xml;
            exit;

        } catch (Exception $e) {
            error_log('MLD Incremental Sitemap Error: ' . $e->getMessage());
            status_header(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Sitemap generation error';
            exit;
        }
    }

    /**
     * Schedule all sitemap regeneration tasks
     */
    public function schedule_regeneration() {
        // New listings - every 15 minutes
        if (!wp_next_scheduled('mld_regenerate_new_listings_sitemap')) {
            wp_schedule_event(time(), 'every_15_minutes', 'mld_regenerate_new_listings_sitemap');
        }

        // Modified listings - every 30 minutes (increased from hourly for faster indexing)
        $scheduled = wp_next_scheduled('mld_regenerate_modified_listings_sitemap');
        if (!$scheduled) {
            wp_schedule_event(time(), 'every_30_minutes', 'mld_regenerate_modified_listings_sitemap');
        }

        // Full sitemap - every 12 hours (increased from daily)
        $full_scheduled = wp_next_scheduled('mld_regenerate_full_sitemap');
        if (!$full_scheduled) {
            wp_schedule_event(time(), 'every_12_hours', 'mld_regenerate_full_sitemap');
        }
    }

    /**
     * Generate new listings sitemap (last 48 hours)
     */
    public function generate_new_listings_sitemap() {
        $cache_file = self::CACHE_DIR . 'new-listings-sitemap.xml';

        // Check cache (15 minute cache)
        if ($this->is_cache_valid($cache_file, 15 * 60)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Use WordPress timezone for date comparison (per CLAUDE.md timezone protocol)
        $wp_now = current_time('mysql');

        // Get listings added in last 48 hours
        $properties = $wpdb->get_results($wpdb->prepare("
            SELECT
                listing_id,
                street_number,
                street_name,
                unit_number,
                city,
                state_or_province,
                postal_code,
                bedrooms_total,
                bathrooms_total,
                property_type,
                standard_status,
                list_price,
                modification_timestamp,
                listing_contract_date,
                main_photo_url
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND listing_contract_date >= DATE_SUB(%s, INTERVAL %d HOUR)
            ORDER BY listing_contract_date DESC
            LIMIT %d
        ", $wp_now, self::NEW_LISTINGS_WINDOW, self::URLS_PER_SITEMAP), ARRAY_A);

        $xml = $this->build_property_xml($properties, '1.0', 'hourly');

        $this->write_cache_file($cache_file, $xml);

        // Ping search engines for new listings
        $this->ping_search_engines_for_new_listings();

        return $xml;
    }

    /**
     * Generate modified listings sitemap (last 24 hours)
     */
    public function generate_modified_listings_sitemap() {
        $cache_file = self::CACHE_DIR . 'modified-listings-sitemap.xml';

        // Check cache (30 minute cache for more frequent updates)
        if ($this->is_cache_valid($cache_file, 30 * 60)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Use WordPress timezone for date comparison (per CLAUDE.md timezone protocol)
        $wp_now = current_time('mysql');

        // Get listings modified in last 24 hours (excluding brand new ones)
        $properties = $wpdb->get_results($wpdb->prepare("
            SELECT
                listing_id,
                street_number,
                street_name,
                unit_number,
                city,
                state_or_province,
                postal_code,
                bedrooms_total,
                bathrooms_total,
                property_type,
                standard_status,
                list_price,
                modification_timestamp,
                listing_contract_date,
                main_photo_url
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND modification_timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)
                AND listing_contract_date < DATE_SUB(%s, INTERVAL %d HOUR)
            ORDER BY modification_timestamp DESC
            LIMIT %d
        ", $wp_now, self::MODIFIED_LISTINGS_WINDOW, $wp_now, self::NEW_LISTINGS_WINDOW, self::URLS_PER_SITEMAP), ARRAY_A);

        $xml = $this->build_property_xml($properties, '0.9', 'hourly');

        $this->write_cache_file($cache_file, $xml);

        return $xml;
    }

    /**
     * Write content to cache file with error handling
     *
     * @param string $cache_file The cache file path
     * @param string $content The content to write
     * @return bool True on success, false on failure
     */
    private function write_cache_file($cache_file, $content) {
        $result = file_put_contents($cache_file, $content);
        if ($result === false) {
            error_log('MLD Incremental Sitemap: Failed to write cache file: ' . $cache_file);
            return false;
        }
        return true;
    }

    /**
     * Regenerate full sitemap (called by cron daily)
     */
    public function regenerate_full_sitemap() {
        $generator = MLD_Sitemap_Generator::get_instance();
        $generator->regenerate_all_sitemaps();
    }

    /**
     * Regenerate new listings sitemap (called by cron every 15 min)
     */
    public function regenerate_new_listings() {
        $this->clear_cache('new-listings-sitemap.xml');
        $this->generate_new_listings_sitemap();
    }

    /**
     * Regenerate modified listings sitemap (called by cron every 30 min)
     */
    public function regenerate_modified_listings() {
        $this->clear_cache('modified-listings-sitemap.xml');
        $this->generate_modified_listings_sitemap();

        // Ping search engines for modified listings
        $this->ping_search_engines_for_modified_listings();
    }

    /**
     * Build property XML from property data
     */
    private function build_property_xml($properties, $default_priority = '0.8', $default_changefreq = 'weekly') {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
        $xml .= 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        foreach ($properties as $property) {
            $property_url = MLD_URL_Helper::get_property_url($property);

            if (empty($property_url)) {
                continue;
            }

            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($property_url) . '</loc>';

            // Last modified - use WordPress timezone-aware date formatting
            $lastmod = !empty($property['modification_timestamp'])
                ? wp_date('c', strtotime($property['modification_timestamp']))
                : wp_date('c');
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';

            // Use provided defaults
            $xml .= '<changefreq>' . $default_changefreq . '</changefreq>';
            $xml .= '<priority>' . $default_priority . '</priority>';

            // Add main image
            if (!empty($property['main_photo_url'])) {
                $address = trim(implode(' ', array_filter([
                    $property['street_number'],
                    $property['street_name'],
                    $property['unit_number']
                ])));

                $xml .= '<image:image>';
                $xml .= '<image:loc>' . esc_url($property['main_photo_url']) . '</image:loc>';
                $xml .= '<image:title>' . esc_xml($address) . '</image:title>';
                $xml .= '<image:caption>' . esc_xml(sprintf(
                    '%s in %s, %s',
                    function_exists('mld_get_seo_property_type') ? mld_get_seo_property_type($property['property_type']) : ($property['property_type'] ?: 'Property'),
                    $property['city'] ?: '',
                    $property['state_or_province'] ?: ''
                )) . '</image:caption>';
                $xml .= '</image:image>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';
        
        return $xml;
    }

    /**
     * Add all sitemaps to robots.txt
     */
    public function add_sitemaps_to_robots($output, $public) {
        if ($public == '0') {
            return $output;
        }

        $site_url = trailingslashit(home_url());

        // Add incremental sitemaps at the top (highest priority)
        $output .= "\n# MLS Incremental Sitemaps (Updated Frequently)\n";
        $output .= "Sitemap: " . $site_url . "new-listings-sitemap.xml\n";
        $output .= "Sitemap: " . $site_url . "modified-listings-sitemap.xml\n";

        return $output;
    }

    /**
     * Ping search engines specifically for new listings
     */
    private function ping_search_engines_for_new_listings() {
        // Use actual sitemap URL, not query var format
        $sitemap_url = urlencode(home_url('/new-listings-sitemap.xml'));
        wp_remote_get("https://www.google.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
        wp_remote_get("https://www.bing.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
    }

    /**
     * Ping search engines for modified listings
     */
    private function ping_search_engines_for_modified_listings() {
        $sitemap_url = urlencode(home_url('/modified-listings-sitemap.xml'));
        wp_remote_get("https://www.google.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
        wp_remote_get("https://www.bing.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
    }

    /**
     * Check if cache is valid
     */
    private function is_cache_valid($file, $max_age_seconds) {
        if (!file_exists($file)) {
            return false;
        }
        return (time() - filemtime($file)) < $max_age_seconds;
    }

    /**
     * Clear specific cache file
     */
    private function clear_cache($filename) {
        $file = self::CACHE_DIR . $filename;
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Get statistics for admin dashboard
     */
    public function get_stats() {
        global $wpdb;

        $stats = array();

        // Use WordPress timezone for date comparison (per CLAUDE.md timezone protocol)
        $wp_now = current_time('mysql');

        // New listings count
        $stats['new_listings'] = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND listing_contract_date >= DATE_SUB(%s, INTERVAL %d HOUR)
        ", $wp_now, self::NEW_LISTINGS_WINDOW));

        // Modified listings count
        $stats['modified_listings'] = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
                AND modification_timestamp >= DATE_SUB(%s, INTERVAL %d HOUR)
                AND listing_contract_date < DATE_SUB(%s, INTERVAL %d HOUR)
        ", $wp_now, self::MODIFIED_LISTINGS_WINDOW, $wp_now, self::NEW_LISTINGS_WINDOW));

        // Get last generation times - use wp_date for WordPress timezone consistency
        $new_file = self::CACHE_DIR . 'new-listings-sitemap.xml';
        $modified_file = self::CACHE_DIR . 'modified-listings-sitemap.xml';

        $stats['new_last_generated'] = file_exists($new_file)
            ? wp_date('F j, Y g:i a', filemtime($new_file))
            : 'Never';

        $stats['modified_last_generated'] = file_exists($modified_file)
            ? wp_date('F j, Y g:i a', filemtime($modified_file))
            : 'Never';

        // Next scheduled times
        $stats['new_next_run'] = wp_next_scheduled('mld_regenerate_new_listings_sitemap');
        $stats['modified_next_run'] = wp_next_scheduled('mld_regenerate_modified_listings_sitemap');
        $stats['full_next_run'] = wp_next_scheduled('mld_regenerate_full_sitemap');

        return $stats;
    }
}

// Initialize - use plugins_loaded if available, otherwise initialize immediately
// This handles the case where the plugin is activated after plugins_loaded has fired
if (did_action('plugins_loaded')) {
    MLD_Incremental_Sitemaps::get_instance();
} else {
    add_action('plugins_loaded', function() {
        MLD_Incremental_Sitemaps::get_instance();
    });
}
