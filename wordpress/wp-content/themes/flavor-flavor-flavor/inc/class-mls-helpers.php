<?php
/**
 * MLS Helpers Class
 *
 * Provides helper functions for getting live MLS data from the
 * Bridge MLS Extractor Pro and MLS Listings Display plugins.
 *
 * Uses the optimized wp_bme_listing_summary table for 8.5x faster queries.
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_MLS_Helpers {

    /**
     * Cache TTL constants (in seconds)
     */
    const CACHE_TTL_CITY = 7200;         // 2 hours
    const CACHE_TTL_NEIGHBORHOOD = 3600; // 1 hour
    const CACHE_TTL_LISTINGS = 1800;     // 30 minutes

    /**
     * Get MLD plugin settings
     *
     * @return array
     */
    public static function get_mld_settings() {
        $settings = get_option('mld_settings', array());
        return is_array($settings) ? $settings : array();
    }

    /**
     * Get the property search page URL from MLD settings
     *
     * @return string The search page URL
     */
    public static function get_search_page_url() {
        $settings = self::get_mld_settings();
        $search_url = isset($settings['search_page_url']) ? trim($settings['search_page_url']) : '';

        // If set in MLD settings, use that
        if (!empty($search_url)) {
            // Ensure it starts with /
            if (strpos($search_url, '/') !== 0 && strpos($search_url, 'http') !== 0) {
                $search_url = '/' . $search_url;
            }
            // Ensure it ends with /
            if (substr($search_url, -1) !== '/') {
                $search_url .= '/';
            }
            return home_url($search_url);
        }

        // Fallback to theme customizer setting or default
        $default_url = get_theme_mod('bne_search_page_url', '/property-search/');
        return home_url($default_url);
    }

    /**
     * Get the property detail page URL
     *
     * @param string $listing_id The listing ID
     * @return string The property detail URL
     */
    public static function get_property_url($listing_id) {
        $settings = self::get_mld_settings();
        $property_base = isset($settings['property_detail_url']) ? trim($settings['property_detail_url']) : '';

        // If set in MLD settings, use that
        if (!empty($property_base)) {
            // Ensure proper formatting
            if (strpos($property_base, '/') !== 0) {
                $property_base = '/' . $property_base;
            }
            $property_base = rtrim($property_base, '/');
            return home_url($property_base . '/' . $listing_id . '/');
        }

        // Default property URL structure
        return home_url('/property/' . $listing_id . '/');
    }

    /**
     * Get the contact page URL
     *
     * @return string The contact page URL
     */
    public static function get_contact_page_url() {
        // Check theme customizer setting first
        $contact_url = get_theme_mod('bne_contact_page_url', '');

        if (!empty($contact_url)) {
            return home_url($contact_url);
        }

        // Default
        return home_url('/contact/');
    }

    /**
     * Build a search URL with hash parameters
     *
     * MLD plugin uses hash-based URLs with capitalized parameter names.
     * Example: /search/#Neighborhood=Seaport+District
     *
     * @param array $params Parameters (e.g., ['city' => 'Boston'] or ['neighborhood' => 'Seaport District'])
     * @return string Full URL with hash parameters
     */
    public static function build_search_url($params = array()) {
        $base_url = self::get_search_page_url();

        if (empty($params)) {
            return $base_url;
        }

        // Remove trailing slash before adding hash
        $base_url = rtrim($base_url, '/') . '/';

        // Map lowercase keys to MLD's capitalized parameter names
        $param_map = array(
            'city'         => 'City',
            'neighborhood' => 'Neighborhood',
            'zip'          => 'Zip',
            'state'        => 'State',
            'price_min'    => 'PriceMin',
            'price_max'    => 'PriceMax',
            'beds'         => 'Beds',
            'baths'        => 'Baths',
            'home_type'    => 'HomeType',
            'sqft_min'     => 'SqftMin',
            'sqft_max'     => 'SqftMax',
        );

        // Build hash parameters
        $hash_parts = array();
        foreach ($params as $key => $value) {
            // Use mapped name if available, otherwise capitalize first letter
            $param_name = isset($param_map[$key]) ? $param_map[$key] : ucfirst($key);
            // URL encode with + for spaces (like the MLD plugin uses)
            $hash_parts[] = $param_name . '=' . str_replace('%20', '+', rawurlencode($value));
        }

        return $base_url . '#' . implode('&', $hash_parts);
    }

    /**
     * Check if MLS plugins are available
     *
     * @return bool
     */
    public static function is_mls_available() {
        global $wpdb;

        // Check if summary table exists
        $table_name = $wpdb->prefix . 'bme_listing_summary';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

        return !empty($table_exists);
    }

    /**
     * Get available property types from database
     *
     * Returns distinct property types with counts, dynamically loaded from
     * the wp_bme_listing_summary table.
     *
     * @param bool $include_counts Whether to include listing counts
     * @return array Array of property types with optional counts
     */
    public static function get_available_property_types($include_counts = true) {
        if (!self::is_mls_available()) {
            return self::get_default_property_types();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        $cache_key = 'bne_property_types_' . ($include_counts ? 'counts' : 'simple');
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Query distinct property types with counts
        $results = $wpdb->get_results(
            "SELECT property_type, COUNT(*) as count
             FROM {$table}
             WHERE standard_status = 'Active'
             AND property_type IS NOT NULL
             AND property_type != ''
             GROUP BY property_type
             ORDER BY count DESC",
            ARRAY_A
        );

        if (empty($results)) {
            return self::get_default_property_types();
        }

        // Map database values to user-friendly labels
        $type_labels = self::get_property_type_labels();
        $property_types = array();

        foreach ($results as $row) {
            $db_value = $row['property_type'];
            $label = isset($type_labels[$db_value]) ? $type_labels[$db_value] : $db_value;

            $property_types[] = array(
                'value' => $db_value,
                'label' => $label,
                'count' => $include_counts ? (int) $row['count'] : null
            );
        }

        set_transient($cache_key, $property_types, self::CACHE_TTL_CITY);

        return $property_types;
    }

    /**
     * Get user-friendly labels for property types
     *
     * Maps database property_type values to display labels.
     *
     * @return array Associative array of db_value => display_label
     */
    public static function get_property_type_labels() {
        return array(
            'Residential'         => __('Residential', 'flavor-flavor-flavor'),
            'Residential Lease'   => __('Rentals', 'flavor-flavor-flavor'),
            'Residential Income'  => __('Multi-Family', 'flavor-flavor-flavor'),
            'Commercial Sale'     => __('Commercial Sale', 'flavor-flavor-flavor'),
            'Commercial Lease'    => __('Commercial Lease', 'flavor-flavor-flavor'),
            'Land'                => __('Land', 'flavor-flavor-flavor'),
            'Business Opportunity' => __('Business', 'flavor-flavor-flavor'),
        );
    }

    /**
     * Get default property types when database is unavailable
     *
     * @return array
     */
    private static function get_default_property_types() {
        return array(
            array('value' => 'Residential', 'label' => __('Residential', 'flavor-flavor-flavor'), 'count' => null),
            array('value' => 'Residential Lease', 'label' => __('Rentals', 'flavor-flavor-flavor'), 'count' => null),
            array('value' => 'Land', 'label' => __('Land', 'flavor-flavor-flavor'), 'count' => null),
        );
    }

    /**
     * Get listing count for a specific city
     *
     * Uses the optimized summary table for fast queries.
     *
     * @param string $city City name
     * @return int Listing count
     */
    public static function get_city_listing_count($city) {
        if (!self::is_mls_available()) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        $cache_key = 'bne_city_count_' . sanitize_title($city);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE city = %s
             AND standard_status = 'Active'",
            $city
        ));

        $count = $count ? (int) $count : 0;
        set_transient($cache_key, $count, self::CACHE_TTL_CITY);

        return $count;
    }

    /**
     * Get listing count for a specific neighborhood
     *
     * @param string $neighborhood Neighborhood name
     * @return int Listing count
     */
    public static function get_neighborhood_listing_count($neighborhood) {
        if (!self::is_mls_available()) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        $cache_key = 'bne_neighborhood_count_' . sanitize_title($neighborhood);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        // Try subdivision_name first, then fall back to city
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE (subdivision_name = %s OR city = %s)
             AND standard_status = 'Active'",
            $neighborhood,
            $neighborhood
        ));

        $count = $count ? (int) $count : 0;
        set_transient($cache_key, $count, self::CACHE_TTL_NEIGHBORHOOD);

        return $count;
    }

    /**
     * Get featured cities with listing counts
     *
     * @param array|null $cities Optional array of city names. Uses customizer setting if null.
     * @return array Array of cities with counts
     */
    public static function get_featured_cities($cities = null) {
        if ($cities === null) {
            $cities_string = get_theme_mod('bne_featured_cities', 'Boston,Newton,Cambridge,Somerville,Arlington,Needham');
            $cities = array_map('trim', explode(',', $cities_string));
        }

        $cache_key = 'bne_featured_cities_' . md5(serialize($cities));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $result = array();

        if (self::is_mls_available()) {
            // Batch query for all cities at once (much faster than individual queries)
            global $wpdb;
            $table = $wpdb->prefix . 'bme_listing_summary';

            $placeholders = implode(',', array_fill(0, count($cities), '%s'));
            $query = $wpdb->prepare(
                "SELECT city, COUNT(*) as count
                 FROM {$table}
                 WHERE city IN ({$placeholders})
                 AND standard_status = 'Active'
                 GROUP BY city",
                $cities
            );

            $counts = $wpdb->get_results($query, ARRAY_A);
            $count_map = array();
            foreach ($counts as $row) {
                $count_map[$row['city']] = (int) $row['count'];
            }

            // Get photo from most expensive Residential listing for each city
            // (expensive listings tend to have better professional exterior photos)
            $photo_map = array();
            foreach ($cities as $city) {
                $photo_query = $wpdb->prepare(
                    "SELECT main_photo_url
                     FROM {$table}
                     WHERE city = %s
                     AND standard_status = 'Active'
                     AND property_type = 'Residential'
                     AND main_photo_url IS NOT NULL
                     AND main_photo_url != ''
                     AND list_price > 0
                     ORDER BY list_price DESC
                     LIMIT 1",
                    $city
                );
                $photo = $wpdb->get_var($photo_query);
                if ($photo) {
                    $photo_map[$city] = $photo;
                }
            }

            foreach ($cities as $city) {
                $result[] = array(
                    'name'  => $city,
                    'count' => isset($count_map[$city]) ? $count_map[$city] : 0,
                    'url'   => self::build_search_url(array('city' => $city)),
                    'slug'  => sanitize_title($city),
                    'image' => isset($photo_map[$city]) ? $photo_map[$city] : '',
                );
            }
        } else {
            // Fallback when MLS not available
            foreach ($cities as $city) {
                $result[] = array(
                    'name'  => $city,
                    'count' => 0,
                    'url'   => self::build_search_url(array('city' => $city)),
                    'slug'  => sanitize_title($city),
                    'image' => '',
                );
            }
        }

        set_transient($cache_key, $result, self::CACHE_TTL_CITY);
        return $result;
    }

    /**
     * Get featured neighborhoods with listing counts and cover images
     *
     * Neighborhoods are stored in the wp_bme_listing_location table's mls_area_major column.
     * Cover image is the main photo from the newest listing in each neighborhood.
     *
     * @param array|null $neighborhoods Optional array of neighborhood names. Uses customizer setting if null.
     * @return array Array of neighborhoods with counts and images
     */
    public static function get_featured_neighborhoods($neighborhoods = null) {
        if ($neighborhoods === null) {
            $neighborhoods_string = get_theme_mod(
                'bne_featured_neighborhoods',
                'Seaport District,South Boston,South End,Back Bay,East Boston,Jamaica Plain,Beacon Hill'
            );
            $neighborhoods = array_map('trim', explode(',', $neighborhoods_string));
        }

        $cache_key = 'bne_featured_neighborhoods_' . md5(serialize($neighborhoods));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $result = array();

        if (self::is_mls_available()) {
            global $wpdb;
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
            $location_table = $wpdb->prefix . 'bme_listing_location';

            // Query counts for each neighborhood
            $placeholders = implode(',', array_fill(0, count($neighborhoods), '%s'));
            $query = $wpdb->prepare(
                "SELECT loc.mls_area_major as name, COUNT(*) as count
                 FROM {$location_table} loc
                 INNER JOIN {$summary_table} s ON loc.listing_id = s.listing_id
                 WHERE loc.mls_area_major IN ({$placeholders})
                 AND s.standard_status = 'Active'
                 GROUP BY loc.mls_area_major",
                $neighborhoods
            );

            $counts = $wpdb->get_results($query, ARRAY_A);
            $count_map = array();
            foreach ($counts as $row) {
                $count_map[$row['name']] = (int) $row['count'];
            }

            // Get photo from most expensive Residential listing for each neighborhood
            // (expensive listings tend to have better professional exterior photos)
            $photo_map = array();
            foreach ($neighborhoods as $neighborhood) {
                $photo_query = $wpdb->prepare(
                    "SELECT s.main_photo_url
                     FROM {$summary_table} s
                     INNER JOIN {$location_table} loc ON s.listing_id = loc.listing_id
                     WHERE loc.mls_area_major = %s
                     AND s.standard_status = 'Active'
                     AND s.property_type = 'Residential'
                     AND s.main_photo_url IS NOT NULL
                     AND s.main_photo_url != ''
                     AND s.list_price > 0
                     ORDER BY s.list_price DESC
                     LIMIT 1",
                    $neighborhood
                );
                $photo = $wpdb->get_var($photo_query);
                if ($photo) {
                    $photo_map[$neighborhood] = $photo;
                }
            }

            foreach ($neighborhoods as $neighborhood) {
                $result[] = array(
                    'name'  => $neighborhood,
                    'count' => isset($count_map[$neighborhood]) ? $count_map[$neighborhood] : 0,
                    'url'   => self::build_search_url(array('neighborhood' => $neighborhood)),
                    'slug'  => sanitize_title($neighborhood),
                    'image' => isset($photo_map[$neighborhood]) ? $photo_map[$neighborhood] : '',
                );
            }
        } else {
            foreach ($neighborhoods as $neighborhood) {
                $result[] = array(
                    'name'  => $neighborhood,
                    'count' => 0,
                    'url'   => self::build_search_url(array('neighborhood' => $neighborhood)),
                    'slug'  => sanitize_title($neighborhood),
                    'image' => '',
                );
            }
        }

        set_transient($cache_key, $result, self::CACHE_TTL_NEIGHBORHOOD);
        return $result;
    }

    /**
     * Get newest listings (Residential for-sale only, limited to featured cities, max 2 per city)
     *
     * @param int $count Number of listings to return
     * @return array Array of listing data
     */
    public static function get_newest_listings($count = 8) {
        if (!self::is_mls_available()) {
            return array();
        }

        $cache_key = 'bne_newest_listings_res_cities_v2_' . $count;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        // Limit to these specific cities
        $allowed_cities = array(
            'Boston', 'Reading', 'Newton', 'Cambridge', 'Somerville',
            'Arlington', 'Wellesley', 'North Reading', 'Wilmington',
            'Winchester', 'Melrose'
        );
        $city_placeholders = implode(',', array_fill(0, count($allowed_cities), '%s'));

        // Use ROW_NUMBER to get max 2 listings per city, then sort by newest overall
        $query_args = array_merge($allowed_cities, array($count));
        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM (
                SELECT
                    listing_id,
                    list_price,
                    street_number,
                    street_name,
                    city,
                    state_or_province,
                    postal_code,
                    bedrooms_total,
                    bathrooms_total,
                    building_area_total,
                    main_photo_url,
                    property_sub_type,
                    modification_timestamp,
                    ROW_NUMBER() OVER (PARTITION BY city ORDER BY modification_timestamp DESC) as city_rank
                 FROM {$table}
                 WHERE standard_status = 'Active'
                 AND property_type = 'Residential'
                 AND city IN ({$city_placeholders})
                 AND main_photo_url IS NOT NULL
                 AND main_photo_url != ''
            ) ranked
            WHERE city_rank <= 2
            ORDER BY modification_timestamp DESC
            LIMIT %d",
            $query_args
        ), ARRAY_A);

        $result = array();
        foreach ($listings as $listing) {
            $result[] = array(
                'id'           => $listing['listing_id'],
                'price'        => self::format_price($listing['list_price']),
                'price_raw'    => (float) $listing['list_price'],
                'address'      => trim($listing['street_number'] . ' ' . $listing['street_name']),
                'city'         => $listing['city'],
                'state'        => $listing['state_or_province'],
                'zip'          => $listing['postal_code'],
                'beds'         => (int) $listing['bedrooms_total'],
                'baths'        => (float) $listing['bathrooms_total'],
                'sqft'         => self::format_number($listing['building_area_total']),
                'sqft_raw'     => (int) $listing['building_area_total'],
                'photo'        => $listing['main_photo_url'],
                'type'         => $listing['property_sub_type'],
                'url'          => self::get_property_url($listing['listing_id']),
            );
        }

        set_transient($cache_key, $result, self::CACHE_TTL_LISTINGS);
        return $result;
    }

    /**
     * Format price for display
     *
     * @param mixed $price Price value
     * @return string Formatted price
     */
    public static function format_price($price) {
        if (empty($price)) {
            return 'Price TBD';
        }

        $price = (float) $price;

        if ($price >= 1000000) {
            return '$' . number_format($price / 1000000, 2) . 'M';
        }

        return '$' . number_format($price, 0);
    }

    /**
     * Format number with commas
     *
     * @param mixed $number Number value
     * @return string Formatted number
     */
    public static function format_number($number) {
        if (empty($number)) {
            return '—';
        }

        return number_format((int) $number, 0);
    }

    /**
     * Get total active listing count
     *
     * @return int Total count
     */
    public static function get_total_listing_count() {
        if (!self::is_mls_available()) {
            return 0;
        }

        $cache_key = 'bne_total_listings';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bme_listing_summary';

        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE standard_status = 'Active'"
        );

        $count = $count ? (int) $count : 0;
        set_transient($cache_key, $count, self::CACHE_TTL_CITY);

        return $count;
    }

    /**
     * Clear all BNE MLS caches
     *
     * Called when MLS data is refreshed
     */
    public static function clear_caches() {
        global $wpdb;

        // Delete all BNE transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bne_%'
             OR option_name LIKE '_transient_timeout_bne_%'"
        );

        // Clear object cache
        wp_cache_flush();
    }

    /**
     * Get neighborhood statistics for analytics display
     *
     * Returns active listings, median price, average DOM for a neighborhood.
     * Only includes Residential property type listings.
     *
     * @param string $neighborhood Neighborhood name (mls_area_major)
     * @return array|null Stats array or null if unavailable
     */
    public static function get_neighborhood_stats($neighborhood) {
        if (!self::is_mls_available()) {
            return null;
        }

        $cache_key = 'bne_neighborhood_stats_res_' . md5($neighborhood);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $location_table = $wpdb->prefix . 'bme_listing_location';

        // Get active listing count and stats (Residential only)
        $query = $wpdb->prepare(
            "SELECT
                COUNT(*) as active_listings,
                AVG(s.list_price) as avg_price,
                AVG(s.days_on_market) as avg_dom
             FROM {$summary_table} s
             INNER JOIN {$location_table} loc ON s.listing_id = loc.listing_id
             WHERE loc.mls_area_major = %s
             AND s.standard_status = 'Active'
             AND s.property_type = 'Residential'",
            $neighborhood
        );

        $stats = $wpdb->get_row($query, ARRAY_A);

        if (!$stats || !$stats['active_listings']) {
            return null;
        }

        // Get median price (Residential only)
        $median_query = $wpdb->prepare(
            "SELECT s.list_price
             FROM {$summary_table} s
             INNER JOIN {$location_table} loc ON s.listing_id = loc.listing_id
             WHERE loc.mls_area_major = %s
             AND s.standard_status = 'Active'
             AND s.property_type = 'Residential'
             AND s.list_price > 0
             ORDER BY s.list_price ASC",
            $neighborhood
        );

        $prices = $wpdb->get_col($median_query);
        $median_price = 0;

        if (!empty($prices)) {
            $count = count($prices);
            $middle = floor($count / 2);
            if ($count % 2 === 0) {
                $median_price = ($prices[$middle - 1] + $prices[$middle]) / 2;
            } else {
                $median_price = $prices[$middle];
            }
        }

        $result = array(
            'active_listings' => (int) $stats['active_listings'],
            'median_price'    => (float) $median_price,
            'avg_dom'         => round((float) $stats['avg_dom']),
            'price_change'    => 0, // YoY change requires historical data we don't have
        );

        set_transient($cache_key, $result, self::CACHE_TTL_NEIGHBORHOOD);

        return $result;
    }

    /**
     * Get analytics data for multiple neighborhoods
     *
     * Includes image from the most expensive listing in each neighborhood.
     *
     * @param array $neighborhoods Array of neighborhood names
     * @return array Array of neighborhood data with stats, URLs, and images
     */
    public static function get_neighborhoods_analytics($neighborhoods = null) {
        if ($neighborhoods === null) {
            $neighborhoods_string = get_theme_mod(
                'bne_analytics_neighborhoods',
                'Back Bay, South End, Beacon Hill, Jamaica Plain, Charlestown, Cambridge'
            );
            $neighborhoods = array_map('trim', explode(',', $neighborhoods_string));
        }

        $cache_key = 'bne_neighborhoods_analytics_res_' . md5(serialize($neighborhoods));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $result = array();

        // Get images from the most expensive Residential listing in each neighborhood
        $image_map = array();
        if (self::is_mls_available()) {
            global $wpdb;
            $summary_table = $wpdb->prefix . 'bme_listing_summary';
            $location_table = $wpdb->prefix . 'bme_listing_location';

            foreach ($neighborhoods as $neighborhood) {
                $image_query = $wpdb->prepare(
                    "SELECT s.main_photo_url
                     FROM {$summary_table} s
                     INNER JOIN {$location_table} loc ON s.listing_id = loc.listing_id
                     WHERE loc.mls_area_major = %s
                     AND s.standard_status = 'Active'
                     AND s.property_type = 'Residential'
                     AND s.main_photo_url IS NOT NULL
                     AND s.main_photo_url != ''
                     AND s.list_price > 0
                     ORDER BY s.list_price DESC
                     LIMIT 1",
                    $neighborhood
                );
                $image = $wpdb->get_var($image_query);
                if ($image) {
                    $image_map[$neighborhood] = $image;
                }
            }
        }

        foreach ($neighborhoods as $neighborhood) {
            $stats = self::get_neighborhood_stats($neighborhood);

            $result[] = array(
                'name'            => $neighborhood,
                'slug'            => sanitize_title($neighborhood),
                'url'             => self::build_search_url(array('neighborhood' => $neighborhood)),
                'image'           => isset($image_map[$neighborhood]) ? $image_map[$neighborhood] : '',
                'active_listings' => $stats ? $stats['active_listings'] : 0,
                'median_price'    => $stats ? $stats['median_price'] : 0,
                'avg_dom'         => $stats ? $stats['avg_dom'] : 0,
                'price_change'    => $stats ? $stats['price_change'] : 0,
            );
        }

        set_transient($cache_key, $result, self::CACHE_TTL_NEIGHBORHOOD);

        return $result;
    }

    /**
     * Check if MLD shortcodes are available
     *
     * @return bool
     */
    public static function has_mld_shortcodes() {
        return shortcode_exists('mld_featured') || shortcode_exists('mld_listing_cards');
    }

    /**
     * Render featured listings via shortcode
     *
     * @param int $count Number of listings
     * @param string $layout Layout type (carousel or grid)
     * @return string HTML output
     */
    public static function render_featured_listings($count = 8, $layout = 'carousel') {
        if (self::has_mld_shortcodes()) {
            return do_shortcode(sprintf(
                '[mld_featured count="%d" layout="%s"]',
                absint($count),
                esc_attr($layout)
            ));
        }

        // Fallback: Use our own data
        $listings = self::get_newest_listings($count);
        if (empty($listings)) {
            return '<p class="bne-no-listings">No listings available at this time.</p>';
        }

        ob_start();
        ?>
        <div class="bne-listings-<?php echo esc_attr($layout); ?>">
            <?php foreach ($listings as $listing) : ?>
                <div class="bne-listing-card">
                    <a href="<?php echo esc_url($listing['url']); ?>" class="bne-listing-card__link">
                        <div class="bne-listing-card__image-wrapper">
                            <img
                                src="<?php echo esc_url($listing['photo']); ?>"
                                alt="<?php echo esc_attr($listing['address']); ?>"
                                class="bne-listing-card__image"
                                loading="lazy"
                            >
                            <span class="bne-listing-card__price"><?php echo esc_html($listing['price']); ?></span>
                        </div>
                        <div class="bne-listing-card__content">
                            <h3 class="bne-listing-card__address"><?php echo esc_html($listing['address']); ?></h3>
                            <p class="bne-listing-card__location"><?php echo esc_html($listing['city'] . ', ' . $listing['state']); ?></p>
                            <div class="bne-listing-card__details">
                                <span><?php echo esc_html($listing['beds']); ?> bd</span>
                                <span><?php echo esc_html($listing['baths']); ?> ba</span>
                                <?php if ($listing['sqft_raw']) : ?>
                                    <span><?php echo esc_html($listing['sqft']); ?> sqft</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Hook into BME summary refresh to clear caches
add_action('bme_summary_table_refreshed', array('BNE_MLS_Helpers', 'clear_caches'));
