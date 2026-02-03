<?php
/**
 * XML Sitemap Generator for MLS Listings Display - Version 2
 * 
 * Properly mapped to actual database schema
 *
 * @package MLS_Listings_Display
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Sitemap_Generator {

    const URLS_PER_SITEMAP = 45000;
    const CACHE_DIR = WP_CONTENT_DIR . '/cache/mld-sitemaps/';
    const CACHE_DURATION = 86400; // 24 hours

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->ensure_cache_directory();
    }

    private function init_hooks() {
        add_action('init', array($this, 'add_rewrite_rules'), 1); // Priority 1 to run before WP core
        add_action('template_redirect', array($this, 'handle_sitemap_request'));
        add_action('wp', array($this, 'schedule_regeneration'));
        add_action('mld_regenerate_sitemaps', array($this, 'regenerate_all_sitemaps'));
        add_filter('robots_txt', array($this, 'add_sitemap_to_robots'), 10, 2);
        add_filter('wp_sitemaps_enabled', '__return_false'); // Disable WordPress default sitemaps
        add_action('init', array($this, 'remove_wp_sitemap_rules'), 999); // Remove WP core sitemap rules
        add_filter('redirect_canonical', array($this, 'prevent_sitemap_redirect'), 10, 2); // Prevent trailing slash redirect
    }

    /**
     * Prevent WordPress canonical redirect for sitemap URLs
     * This stops the 301 redirect from /sitemap.xml to /sitemap.xml/
     *
     * @param string $redirect_url The redirect URL
     * @param string $requested_url The original requested URL
     * @return string|false The redirect URL or false to prevent redirect
     */
    public function prevent_sitemap_redirect($redirect_url, $requested_url) {
        // Check if this is a sitemap request
        if (get_query_var('mld_sitemap')) {
            return false; // Prevent redirect
        }
        return $redirect_url;
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^sitemap\.xml$', 'index.php?mld_sitemap=index', 'top');
        add_rewrite_rule('^property-sitemap\.xml$', 'index.php?mld_sitemap=properties&sitemap_page=1', 'top');
        add_rewrite_rule('^property-sitemap-([0-9]+)\.xml$', 'index.php?mld_sitemap=properties&sitemap_page=$matches[1]', 'top');
        add_rewrite_rule('^city-sitemap\.xml$', 'index.php?mld_sitemap=cities', 'top');
        add_rewrite_rule('^state-sitemap\.xml$', 'index.php?mld_sitemap=states', 'top');
        add_rewrite_rule('^property-type-sitemap\.xml$', 'index.php?mld_sitemap=property-types', 'top');
        add_rewrite_rule('^pages-sitemap\.xml$', 'index.php?mld_sitemap=pages', 'top');
        add_rewrite_rule('^posts-sitemap\.xml$', 'index.php?mld_sitemap=posts', 'top');
        add_rewrite_rule('^neighborhood-sitemap\.xml$', 'index.php?mld_sitemap=neighborhoods', 'top');
        add_rewrite_rule('^schools-sitemap\.xml$', 'index.php?mld_sitemap=schools', 'top');

        add_filter('query_vars', function($vars) {
            $vars[] = 'mld_sitemap';
            $vars[] = 'sitemap_page';
            return $vars;
        });
    }

    /**
     * Remove WordPress default sitemap rewrite rules
     * These conflict with our custom sitemap URLs
     */
    public function remove_wp_sitemap_rules() {
        global $wp_rewrite;
        
        // Get all current rules
        $rules = get_option('rewrite_rules');
        
        if (!is_array($rules)) {
            return;
        }
        
        // Remove WordPress sitemap rules
        $wp_sitemap_patterns = array(
            '^wp-sitemap\.xml$',
            '^wp-sitemap\.xsl$',
            '^wp-sitemap-index\.xsl$',
            '^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$',
            '^wp-sitemap-([a-z]+?)-(\d+?)\.xml$',
            'sitemap\.xml' // WordPress also adds this redirect
        );
        
        $modified = false;
        foreach ($wp_sitemap_patterns as $pattern) {
            if (isset($rules[$pattern])) {
                unset($rules[$pattern]);
                $modified = true;
            }
        }
        
        // Update rules if we removed any
        if ($modified) {
            update_option('rewrite_rules', $rules);
        }
    }

    public function handle_sitemap_request() {
        $sitemap_type = get_query_var('mld_sitemap');

        if (!$sitemap_type) {
            return;
        }

        // Only handle our specific sitemap types
        $handled_types = array('index', 'properties', 'cities', 'states', 'property-types', 'pages', 'posts', 'neighborhoods', 'schools');
        if (!in_array($sitemap_type, $handled_types)) {
            // Let other handlers (like incremental sitemaps) handle this
            return;
        }

        // Clean any output buffers to prevent interference
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $xml = $this->get_sitemap_content($sitemap_type);

            if (empty($xml)) {
                status_header(404);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Sitemap not found or empty';
                exit;
            }

            // Set proper HTTP headers for Googlebot compatibility
            status_header(200);
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: public, max-age=3600');
            header('Content-Length: ' . strlen($xml));
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header('X-Robots-Tag: noindex, follow');

            echo $xml;
            exit;

        } catch (Exception $e) {
            error_log('MLD Sitemap Error: ' . $e->getMessage());
            status_header(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Sitemap generation error';
            exit;
        }
    }

    /**
     * Get sitemap content by type
     *
     * @param string $sitemap_type The type of sitemap to generate
     * @return string The XML content
     */
    private function get_sitemap_content($sitemap_type) {
        switch ($sitemap_type) {
            case 'index':
                return $this->generate_sitemap_index();

            case 'properties':
                $page = intval(get_query_var('sitemap_page', 1));
                return $this->generate_property_sitemap($page);

            case 'cities':
                return $this->generate_city_sitemap();

            case 'states':
                return $this->generate_state_sitemap();

            case 'property-types':
                return $this->generate_property_type_sitemap();

            case 'pages':
                return $this->generate_pages_sitemap();

            case 'posts':
                return $this->generate_posts_sitemap();

            case 'neighborhoods':
                return $this->generate_neighborhood_sitemap();

            case 'schools':
                return $this->generate_schools_sitemap();

            default:
                return '';
        }
    }

    public function generate_sitemap_index() {
        $cache_file = self::CACHE_DIR . 'sitemap-index.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        $site_url = trailingslashit(home_url());
        // Use WordPress timezone-aware date formatting (per CLAUDE.md timezone protocol)
        $lastmod_now = wp_date('c');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Add incremental sitemaps first (highest priority, updated most frequently)
        $xml .= '<sitemap>';
        $xml .= '<loc>' . esc_url($site_url . 'new-listings-sitemap.xml') . '</loc>';
        $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
        $xml .= '</sitemap>';

        $xml .= '<sitemap>';
        $xml .= '<loc>' . esc_url($site_url . 'modified-listings-sitemap.xml') . '</loc>';
        $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
        $xml .= '</sitemap>';

        // Add full property sitemaps
        $total_properties = $this->get_total_active_properties();
        $num_property_sitemaps = ceil($total_properties / self::URLS_PER_SITEMAP);

        for ($i = 1; $i <= $num_property_sitemaps; $i++) {
            $sitemap_url = $i === 1 ? 'property-sitemap.xml' : "property-sitemap-{$i}.xml";
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . $sitemap_url) . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add state sitemap
        if ($this->has_state_pages()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'state-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add city sitemap
        if ($this->has_city_pages()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'city-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add property-type sitemap
        if ($this->has_property_type_pages()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'property-type-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add pages sitemap (WordPress pages)
        if ($this->has_pages()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'pages-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add posts sitemap (WordPress blog posts)
        if ($this->has_posts()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'posts-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add neighborhood sitemap (auto-generated neighborhood pages)
        if ($this->has_neighborhood_pages()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'neighborhood-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        // Add schools sitemap (BMN Schools plugin virtual pages)
        if ($this->has_schools()) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_url($site_url . 'schools-sitemap.xml') . '</loc>';
            $xml .= '<lastmod>' . $lastmod_now . '</lastmod>';
            $xml .= '</sitemap>';
        }

        $xml .= '</sitemapindex>';

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
            error_log('MLD Sitemap: Failed to write cache file: ' . $cache_file);
            return false;
        }
        return true;
    }

    public function generate_property_sitemap($page = 1) {
        $cache_file = self::CACHE_DIR . "property-sitemap-{$page}.xml";

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;
        $offset = ($page - 1) * self::URLS_PER_SITEMAP;

        // Query with exact column names from database
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
                bathrooms_full,
                bathrooms_half,
                property_type,
                standard_status,
                list_price,
                modification_timestamp,
                listing_contract_date,
                main_photo_url
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
            ORDER BY modification_timestamp DESC
            LIMIT %d OFFSET %d
        ", self::URLS_PER_SITEMAP, $offset), ARRAY_A);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
        $xml .= 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        foreach ($properties as $property) {
            // Generate URL using MLD_URL_Helper static method
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

            // Change frequency based on age
            $days_old = !empty($property['listing_contract_date']) 
                ? (time() - strtotime($property['listing_contract_date'])) / 86400 
                : 0;
            
            if ($property['standard_status'] === 'Active') {
                if ($days_old < 7) {
                    $changefreq = 'daily';
                } elseif ($days_old < 30) {
                    $changefreq = 'weekly';
                } else {
                    $changefreq = 'monthly';
                }
            } else {
                $changefreq = 'monthly';
            }
            $xml .= '<changefreq>' . $changefreq . '</changefreq>';

            // Priority
            if ($property['standard_status'] === 'Active') {
                $priority = '0.8';
                if ($days_old < 7) {
                    $priority = '0.9';
                }
                if (!empty($property['list_price']) && $property['list_price'] > 1000000) {
                    $priority = '0.9';
                }
            } else {
                $priority = '0.5';
            }
            $xml .= '<priority>' . $priority . '</priority>';

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

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    public function generate_city_sitemap() {
        $cache_file = self::CACHE_DIR . 'city-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Enhanced query to get rental counts for each city
        $cities = $wpdb->get_results("
            SELECT DISTINCT
                city,
                state_or_province,
                COUNT(*) as listing_count,
                SUM(CASE WHEN property_type LIKE '%Lease%' OR property_type LIKE '%Rental%' THEN 1 ELSE 0 END) as rental_count,
                SUM(CASE WHEN property_type NOT LIKE '%Lease%' AND property_type NOT LIKE '%Rental%' THEN 1 ELSE 0 END) as sale_count,
                MAX(modification_timestamp) as last_modified
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND city IS NOT NULL
                AND city != ''
                AND state_or_province IS NOT NULL
            GROUP BY city, state_or_province
            ORDER BY listing_count DESC
        ");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($cities as $city) {
            $city_slug = sanitize_title($city->city);
            $state_slug = strtolower($city->state_or_province);

            // Use WordPress timezone-aware date formatting
            $lastmod = !empty($city->last_modified)
                ? wp_date('c', strtotime($city->last_modified))
                : wp_date('c');

            $changefreq = $city->listing_count > 100 ? 'daily' : ($city->listing_count > 20 ? 'weekly' : 'monthly');

            $priority = $city->listing_count > 500 ? '1.0' : ($city->listing_count > 100 ? '0.9' : ($city->listing_count > 50 ? '0.8' : '0.7'));

            // 1. Original SEO URL format: /homes-for-sale-in-boston-ma/
            $seo_url = home_url("/homes-for-sale-in-{$city_slug}-{$state_slug}/");
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($seo_url) . '</loc>';
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            $xml .= '<changefreq>' . $changefreq . '</changefreq>';
            $xml .= '<priority>' . $priority . '</priority>';
            $xml .= '</url>';

            // 2. Theme URL format: /boston/ (for sales - default)
            if ($city->sale_count > 0) {
                $theme_url = home_url("/{$city_slug}/");
                $xml .= '<url>';
                $xml .= '<loc>' . esc_url($theme_url) . '</loc>';
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
                $xml .= '<changefreq>' . $changefreq . '</changefreq>';
                // Slightly lower priority than SEO URL to avoid duplicate content issues
                $theme_priority = max(0.5, floatval($priority) - 0.1);
                $xml .= '<priority>' . number_format($theme_priority, 1) . '</priority>';
                $xml .= '</url>';
            }

            // 3. Rentals URL: /boston/rentals/
            if ($city->rental_count > 0) {
                $rental_url = home_url("/{$city_slug}/rentals/");
                $xml .= '<url>';
                $xml .= '<loc>' . esc_url($rental_url) . '</loc>';
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
                $xml .= '<changefreq>' . $changefreq . '</changefreq>';
                // Lower priority for rental pages
                $rental_priority = max(0.5, floatval($priority) - 0.2);
                $xml .= '<priority>' . number_format($rental_priority, 1) . '</priority>';
                $xml .= '</url>';
            }
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    public function generate_state_sitemap() {
        $cache_file = self::CACHE_DIR . 'state-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Get states with active listings
        $states = $wpdb->get_results("
            SELECT DISTINCT
                state_or_province,
                COUNT(*) as listing_count,
                MAX(modification_timestamp) as last_modified
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND state_or_province IS NOT NULL
                AND state_or_province != ''
            GROUP BY state_or_province
            ORDER BY listing_count DESC
        ");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($states as $state) {
            // Convert state code to full name for URL
            $state_name_map = array(
                'MA' => 'massachusetts',
                'NH' => 'new-hampshire',
                'CT' => 'connecticut',
                'RI' => 'rhode-island',
                'VT' => 'vermont',
                'ME' => 'maine',
            );

            $state_code = strtoupper($state->state_or_province);
            $state_slug = isset($state_name_map[$state_code]) ? $state_name_map[$state_code] : strtolower($state_code);
            $state_url = home_url("/homes-for-sale-in-{$state_slug}/");

            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($state_url) . '</loc>';

            // Use WordPress timezone-aware date formatting
            $lastmod = !empty($state->last_modified)
                ? wp_date('c', strtotime($state->last_modified))
                : wp_date('c');
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';

            // State pages change frequently as new listings are added
            $xml .= '<changefreq>daily</changefreq>';

            // State pages are high priority landing pages
            $xml .= '<priority>1.0</priority>';

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    public function generate_property_type_sitemap() {
        $cache_file = self::CACHE_DIR . 'property-type-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Get all property types and subtypes with active listings
        $types = $wpdb->get_results("
            SELECT DISTINCT
                property_type,
                property_sub_type,
                COUNT(*) as listing_count,
                MAX(modification_timestamp) as last_modified
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND (property_type IS NOT NULL OR property_sub_type IS NOT NULL)
            GROUP BY property_type, property_sub_type
            HAVING listing_count > 0
            ORDER BY listing_count DESC
        ");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($types as $type) {
            // Determine which type to use (subtype takes precedence)
            $type_name = !empty($type->property_sub_type) ? $type->property_sub_type : $type->property_type;

            // Determine listing type (sale vs rent)
            $is_rental = (strpos(strtolower($type->property_type ?? ''), 'lease') !== false ||
                          strpos(strtolower($type->property_type ?? ''), 'rental') !== false);
            $listing_type = $is_rental ? 'rent' : 'sale';

            // Generate URL using the property type pages class
            $type_url = MLD_Property_Type_Pages::get_property_type_url(
                $type->property_type,
                $type->property_sub_type,
                $listing_type
            );

            // Skip if no URL could be generated
            if (empty($type_url)) {
                continue;
            }

            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($type_url) . '</loc>';

            // Use WordPress timezone-aware date formatting
            $lastmod = !empty($type->last_modified)
                ? wp_date('c', strtotime($type->last_modified))
                : wp_date('c');
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';

            // Property type pages change daily as new listings are added
            $xml .= '<changefreq>daily</changefreq>';

            // High priority SEO landing pages
            $xml .= '<priority>0.9</priority>';

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    /**
     * Generate WordPress pages sitemap
     *
     * @return string The XML sitemap content
     */
    public function generate_pages_sitemap() {
        $cache_file = self::CACHE_DIR . 'pages-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC'
        ));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($pages as $page) {
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url(get_permalink($page->ID)) . '</loc>';
            $xml .= '<lastmod>' . wp_date('c', strtotime($page->post_modified)) . '</lastmod>';

            // Home page and important pages get higher priority
            if ($page->ID == get_option('page_on_front')) {
                $xml .= '<changefreq>daily</changefreq>';
                $xml .= '<priority>1.0</priority>';
            } else {
                $xml .= '<changefreq>weekly</changefreq>';
                $xml .= '<priority>0.8</priority>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    /**
     * Generate WordPress blog posts sitemap
     *
     * @return string The XML sitemap content
     */
    public function generate_posts_sitemap() {
        $cache_file = self::CACHE_DIR . 'posts-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC'
        ));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($posts as $post) {
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url(get_permalink($post->ID)) . '</loc>';
            $xml .= '<lastmod>' . wp_date('c', strtotime($post->post_modified)) . '</lastmod>';

            // Recent posts get higher frequency
            $days_old = (time() - strtotime($post->post_date)) / 86400;
            if ($days_old < 7) {
                $xml .= '<changefreq>daily</changefreq>';
                $xml .= '<priority>0.8</priority>';
            } elseif ($days_old < 30) {
                $xml .= '<changefreq>weekly</changefreq>';
                $xml .= '<priority>0.7</priority>';
            } else {
                $xml .= '<changefreq>monthly</changefreq>';
                $xml .= '<priority>0.6</priority>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    /**
     * Generate neighborhood sitemap from mls_area_major values
     *
     * Neighborhoods are distinct from cities - they represent local areas
     * like "Back Bay", "South End", "Beacon Hill" etc.
     *
     * @return string The XML sitemap content
     */
    public function generate_neighborhood_sitemap() {
        $cache_file = self::CACHE_DIR . 'neighborhood-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        // Query neighborhoods from mls_area_major with listing counts
        // Also get rental counts to determine if rental URL should be included
        $neighborhoods = $wpdb->get_results("
            SELECT
                loc.mls_area_major as neighborhood,
                COUNT(*) as total_listings,
                SUM(CASE WHEN s.property_type LIKE '%Lease%' OR s.property_type LIKE '%Rental%' THEN 1 ELSE 0 END) as rental_count,
                SUM(CASE WHEN s.property_type NOT LIKE '%Lease%' AND s.property_type NOT LIKE '%Rental%' THEN 1 ELSE 0 END) as sale_count,
                MAX(s.modification_timestamp) as last_modified
            FROM {$wpdb->prefix}bme_listing_location loc
            INNER JOIN {$wpdb->prefix}bme_listings li ON loc.listing_id = li.listing_id
            INNER JOIN {$wpdb->prefix}bme_listing_summary s ON li.listing_id = s.listing_id
            WHERE li.standard_status = 'Active'
                AND loc.mls_area_major IS NOT NULL
                AND loc.mls_area_major != ''
            GROUP BY loc.mls_area_major
            HAVING total_listings > 0
            ORDER BY total_listings DESC
        ");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($neighborhoods as $neighborhood) {
            $neighborhood_slug = sanitize_title($neighborhood->neighborhood);

            // Skip if slug is empty or invalid
            if (empty($neighborhood_slug)) {
                continue;
            }

            // Main neighborhood URL (for sales - default)
            $neighborhood_url = home_url("/{$neighborhood_slug}/");

            // Use WordPress timezone-aware date formatting
            $lastmod = !empty($neighborhood->last_modified)
                ? wp_date('c', strtotime($neighborhood->last_modified))
                : wp_date('c');

            // Calculate priority based on listing count (0.5-1.0 scale)
            if ($neighborhood->total_listings > 500) {
                $priority = '1.0';
            } elseif ($neighborhood->total_listings > 100) {
                $priority = '0.9';
            } elseif ($neighborhood->total_listings > 50) {
                $priority = '0.8';
            } elseif ($neighborhood->total_listings > 20) {
                $priority = '0.7';
            } elseif ($neighborhood->total_listings > 10) {
                $priority = '0.6';
            } else {
                $priority = '0.5';
            }

            // Change frequency based on listing volume
            $changefreq = $neighborhood->total_listings > 100 ? 'daily' :
                         ($neighborhood->total_listings > 20 ? 'weekly' : 'monthly');

            // Add main neighborhood page URL (defaults to sales)
            if ($neighborhood->sale_count > 0) {
                $xml .= '<url>';
                $xml .= '<loc>' . esc_url($neighborhood_url) . '</loc>';
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
                $xml .= '<changefreq>' . $changefreq . '</changefreq>';
                $xml .= '<priority>' . $priority . '</priority>';
                $xml .= '</url>';
            }

            // Add rentals URL if neighborhood has rental listings
            if ($neighborhood->rental_count > 0) {
                $rental_url = home_url("/{$neighborhood_slug}/rentals/");

                // Slightly lower priority for rental pages
                $rental_priority = min(1.0, floatval($priority) - 0.1);
                $rental_priority = number_format($rental_priority, 1);

                $xml .= '<url>';
                $xml .= '<loc>' . esc_url($rental_url) . '</loc>';
                $xml .= '<lastmod>' . $lastmod . '</lastmod>';
                $xml .= '<changefreq>' . $changefreq . '</changefreq>';
                $xml .= '<priority>' . $rental_priority . '</priority>';
                $xml .= '</url>';
            }
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    /**
     * Generate schools sitemap for BMN Schools plugin virtual pages
     * Includes: /schools/, /schools/{district}/, /schools/{district}/{school}/
     */
    public function generate_schools_sitemap() {
        $cache_file = self::CACHE_DIR . 'schools-sitemap.xml';

        if ($this->is_cache_valid($cache_file)) {
            return file_get_contents($cache_file);
        }

        global $wpdb;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // Check if BMN Schools tables exist
        $districts_table = $wpdb->prefix . 'bmn_school_districts';
        $schools_table = $wpdb->prefix . 'bmn_schools';

        $districts_exist = $wpdb->get_var("SHOW TABLES LIKE '{$districts_table}'") === $districts_table;
        $schools_exist = $wpdb->get_var("SHOW TABLES LIKE '{$schools_table}'") === $schools_table;

        if (!$districts_exist || !$schools_exist) {
            $xml .= '</urlset>';
            $this->write_cache_file($cache_file, $xml);
            return $xml;
        }

        $site_url = trailingslashit(home_url());
        $lastmod = wp_date('c');

        // Add main browse page: /schools/
        $xml .= '<url>';
        $xml .= '<loc>' . esc_url($site_url . 'schools/') . '</loc>';
        $xml .= '<lastmod>' . $lastmod . '</lastmod>';
        $xml .= '<changefreq>weekly</changefreq>';
        $xml .= '<priority>0.9</priority>';
        $xml .= '</url>';

        // Get all districts with school counts (generate slugs from names)
        $districts = $wpdb->get_results("
            SELECT
                d.id,
                d.name,
                COUNT(s.id) as school_count,
                dr.composite_score
            FROM {$districts_table} d
            LEFT JOIN {$schools_table} s ON s.district_id = d.id
            LEFT JOIN {$wpdb->prefix}bmn_district_rankings dr ON dr.district_id = d.id
            WHERE d.name IS NOT NULL AND d.name != ''
            GROUP BY d.id, d.name, dr.composite_score
            ORDER BY dr.composite_score DESC, d.name ASC
        ");

        // Build district slug lookup for school URLs
        $district_slugs = array();

        foreach ($districts as $district) {
            // Generate slug from name using WordPress function
            $district_slug = sanitize_title($district->name);
            $district_slugs[$district->id] = $district_slug;

            // Skip empty slugs
            if (empty($district_slug)) {
                continue;
            }

            // Calculate priority based on school count and ranking
            if (!empty($district->composite_score) && $district->composite_score > 60) {
                $priority = '0.8';
            } elseif ($district->school_count > 10) {
                $priority = '0.7';
            } elseif ($district->school_count > 5) {
                $priority = '0.6';
            } else {
                $priority = '0.5';
            }

            // District page: /schools/{district-slug}/
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($site_url . 'schools/' . $district_slug . '/') . '</loc>';
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            $xml .= '<changefreq>weekly</changefreq>';
            $xml .= '<priority>' . $priority . '</priority>';
            $xml .= '</url>';
        }

        // Get all schools (generate slugs from names)
        $schools = $wpdb->get_results("
            SELECT
                s.id,
                s.name,
                s.district_id,
                r.composite_score,
                r.rating_band
            FROM {$schools_table} s
            LEFT JOIN {$wpdb->prefix}bmn_school_rankings r ON r.school_id = s.id
            WHERE s.name IS NOT NULL AND s.name != ''
                AND s.district_id IS NOT NULL
            ORDER BY r.composite_score DESC, s.name ASC
        ");

        foreach ($schools as $school) {
            // Get district slug from lookup
            $district_slug = isset($district_slugs[$school->district_id]) ? $district_slugs[$school->district_id] : null;
            if (empty($district_slug)) {
                continue;
            }

            // Generate school slug from name
            $school_slug = sanitize_title($school->name);
            if (empty($school_slug)) {
                continue;
            }

            // Calculate priority based on ranking
            if (!empty($school->rating_band)) {
                if (strpos($school->rating_band, 'A') === 0) {
                    $priority = '0.7';
                } elseif (strpos($school->rating_band, 'B') === 0) {
                    $priority = '0.6';
                } else {
                    $priority = '0.5';
                }
            } elseif (!empty($school->composite_score)) {
                // Use composite score as fallback
                if ($school->composite_score >= 60) {
                    $priority = '0.7';
                } elseif ($school->composite_score >= 40) {
                    $priority = '0.6';
                } else {
                    $priority = '0.5';
                }
            } else {
                $priority = '0.4';
            }

            // School page: /schools/{district-slug}/{school-slug}/
            $xml .= '<url>';
            $xml .= '<loc>' . esc_url($site_url . 'schools/' . $district_slug . '/' . $school_slug . '/') . '</loc>';
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            $xml .= '<changefreq>monthly</changefreq>';
            $xml .= '<priority>' . $priority . '</priority>';
            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        $this->write_cache_file($cache_file, $xml);
        return $xml;
    }

    /**
     * Check if there are neighborhoods with active listings
     */
    private function has_neighborhood_pages() {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT loc.mls_area_major)
            FROM {$wpdb->prefix}bme_listing_location loc
            INNER JOIN {$wpdb->prefix}bme_listings li ON loc.listing_id = li.listing_id
            WHERE li.standard_status = 'Active'
                AND loc.mls_area_major IS NOT NULL
                AND loc.mls_area_major != ''
        ");
        return intval($count) > 0;
    }

    /**
     * Check if BMN Schools plugin tables exist and have data
     */
    private function has_schools() {
        global $wpdb;
        $districts_table = $wpdb->prefix . 'bmn_school_districts';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$districts_table}'") === $districts_table;
        if (!$table_exists) {
            return false;
        }

        // Check if there are districts with names (slugs are generated from names)
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$districts_table}
            WHERE name IS NOT NULL AND name != ''
        ");
        return intval($count) > 0;
    }

    public function add_sitemap_to_robots($output, $public) {
        if ($public == '0') {
            return $output;
        }

        $site_url = trailingslashit(home_url());
        $output .= "\n# MLS Listings Sitemaps\n";
        $output .= "Sitemap: " . $site_url . "sitemap.xml\n";
        $output .= "Sitemap: " . $site_url . "property-sitemap.xml\n";
        $output .= "Sitemap: " . $site_url . "new-listings-sitemap.xml\n";
        $output .= "Sitemap: " . $site_url . "modified-listings-sitemap.xml\n";

        if ($this->has_state_pages()) {
            $output .= "Sitemap: " . $site_url . "state-sitemap.xml\n";
        }

        if ($this->has_city_pages()) {
            $output .= "Sitemap: " . $site_url . "city-sitemap.xml\n";
        }

        if ($this->has_property_type_pages()) {
            $output .= "Sitemap: " . $site_url . "property-type-sitemap.xml\n";
        }

        if ($this->has_pages()) {
            $output .= "Sitemap: " . $site_url . "pages-sitemap.xml\n";
        }

        if ($this->has_posts()) {
            $output .= "Sitemap: " . $site_url . "posts-sitemap.xml\n";
        }

        if ($this->has_neighborhood_pages()) {
            $output .= "Sitemap: " . $site_url . "neighborhood-sitemap.xml\n";
        }

        if ($this->has_schools()) {
            $output .= "Sitemap: " . $site_url . "schools-sitemap.xml\n";
        }

        return $output;
    }

    public function schedule_regeneration() {
        if (!wp_next_scheduled('mld_regenerate_sitemaps')) {
            wp_schedule_event(time(), 'daily', 'mld_regenerate_sitemaps');
        }
    }

    public function regenerate_all_sitemaps() {
        $this->clear_cache();
        $this->generate_sitemap_index();
        $this->generate_property_sitemap(1);

        if ($this->has_state_pages()) {
            $this->generate_state_sitemap();
        }

        if ($this->has_city_pages()) {
            $this->generate_city_sitemap();
        }

        if ($this->has_property_type_pages()) {
            $this->generate_property_type_sitemap();
        }

        if ($this->has_pages()) {
            $this->generate_pages_sitemap();
        }

        if ($this->has_posts()) {
            $this->generate_posts_sitemap();
        }

        if ($this->has_neighborhood_pages()) {
            $this->generate_neighborhood_sitemap();
        }

        if ($this->has_schools()) {
            $this->generate_schools_sitemap();
        }

        // Also regenerate incremental sitemaps (new-listings and modified-listings)
        $incremental = MLD_Incremental_Sitemaps::get_instance();
        $incremental->regenerate_new_listings();
        $incremental->regenerate_modified_listings();

        $this->ping_search_engines();

        // Trigger IndexNow submission for location URLs
        do_action('mld_sitemaps_regenerated');
    }

    private function clear_cache() {
        // Only clear cache files managed by this class
        // Don't delete incremental sitemap cache files (managed by MLD_Incremental_Sitemaps)
        $our_files = array(
            self::CACHE_DIR . 'sitemap-index.xml',
            self::CACHE_DIR . 'state-sitemap.xml',
            self::CACHE_DIR . 'city-sitemap.xml',
            self::CACHE_DIR . 'property-type-sitemap.xml',
            self::CACHE_DIR . 'pages-sitemap.xml',
            self::CACHE_DIR . 'posts-sitemap.xml',
            self::CACHE_DIR . 'neighborhood-sitemap.xml',
            self::CACHE_DIR . 'schools-sitemap.xml',
        );

        // Clear all property sitemap pages
        $property_files = glob(self::CACHE_DIR . 'property-sitemap-*.xml');
        if ($property_files) {
            $our_files = array_merge($our_files, $property_files);
        }

        foreach ($our_files as $file) {
            if (file_exists($file) && is_file($file)) {
                unlink($file);
            }
        }
    }

    private function is_cache_valid($file) {
        if (!file_exists($file)) {
            return false;
        }
        return (time() - filemtime($file)) < self::CACHE_DURATION;
    }

    private function ensure_cache_directory() {
        if (!file_exists(self::CACHE_DIR)) {
            $created = wp_mkdir_p(self::CACHE_DIR);
            if (!$created) {
                error_log('MLD Sitemap: Failed to create cache directory: ' . self::CACHE_DIR);
                return false;
            }
            // Protect cache directory from direct web access (sitemaps are served through WordPress)
            $htaccess = self::CACHE_DIR . '.htaccess';
            if (!file_exists($htaccess)) {
                $this->write_cache_file($htaccess, "Order Allow,Deny\nDeny from all");
            }
        }
        return true;
    }

    private function get_total_active_properties() {
        global $wpdb;
        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status IN ('Active', 'Pending')
        ");
    }

    private function has_city_pages() {
        return true;
    }

    private function has_state_pages() {
        return true;
    }

    private function has_property_type_pages() {
        return true;
    }

    /**
     * Check if there are published WordPress pages
     */
    private function has_pages() {
        $count = wp_count_posts('page');
        return isset($count->publish) && $count->publish > 0;
    }

    /**
     * Check if there are published WordPress posts
     */
    private function has_posts() {
        $count = wp_count_posts('post');
        return isset($count->publish) && $count->publish > 0;
    }

    private function ping_search_engines() {
        $sitemap_url = urlencode(home_url('/sitemap.xml'));
        wp_remote_get("https://www.google.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
        wp_remote_get("https://www.bing.com/ping?sitemap={$sitemap_url}", array('timeout' => 5, 'blocking' => false));
    }
}

// Initialize - use plugins_loaded if available, otherwise initialize immediately
// This handles the case where the plugin is activated after plugins_loaded has fired
if (did_action('plugins_loaded')) {
    MLD_Sitemap_Generator::get_instance();
} else {
    add_action('plugins_loaded', function() {
        MLD_Sitemap_Generator::get_instance();
    });
}
