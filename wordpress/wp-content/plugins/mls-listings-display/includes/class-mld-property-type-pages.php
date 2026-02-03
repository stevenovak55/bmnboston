<?php
/**
 * Property Type Landing Pages for MLS Listings Display
 *
 * Handles SEO-optimized property type landing pages
 * URL format: /{property-type-slug}-for-sale/
 * Examples: /single-family-homes-for-sale/, /condominiums-for-sale/
 *
 * @package MLS_Listings_Display
 * @since 6.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Type_Pages {

    private static $instance = null;

    // Property type to URL slug mappings
    private static $type_mappings = array(
        // Main types
        'Residential' => 'residential-homes',
        'Residential Lease' => 'rental-properties',
        'Residential Income' => 'multi-family-properties',
        'Commercial Sale' => 'commercial-properties',
        'Commercial Lease' => 'commercial-leases',
        'Land' => 'land',
        'Business Opportunity' => 'business-opportunities',
        
        // Residential subtypes
        'Single Family Residence' => 'single-family-homes',
        'Condominium' => 'condominiums',
        'Townhouse' => 'townhouses',
        'Stock Cooperative' => 'co-ops',
        'Condex' => 'condex',
        'Attached (Townhouse/Rowhouse/Duplex)' => 'attached-homes',
        
        // Residential Income subtypes
        '2 Family' => 'two-family-homes',
        '3 Family' => 'three-family-homes',
        '4 Family' => 'four-family-homes',
        'Multi Family' => 'multi-family-homes',
        'Duplex' => 'duplexes',
        '2 Family - 2 Units Up/Down' => 'two-family-up-down',
        '2 Family - 2 Units Side by Side' => 'two-family-side-by-side',
        '3 Family - 3 Units Up/Down' => 'three-family-up-down',
        '4 Family - 4 Units Up/Down' => 'four-family-up-down',
        '5-9 Family' => 'small-apartment-buildings',
        '5+ Family - 5+ Units Up/Down' => 'apartment-buildings',
        '5+ Family - Rooming House' => 'rooming-houses',
        
        // Residential Lease subtypes
        'Apartment' => 'apartments',
        
        // Land subtypes
        'Residential Land' => 'residential-land',
        'Commercial Land' => 'commercial-land',
        'Parking' => 'parking-lots'
    );

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
        add_action('init', array($this, 'add_rewrite_rules'), 2);
        add_action('template_redirect', array($this, 'handle_property_type_page_request'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('document_title_parts', array($this, 'modify_page_title'), 10, 1);
        add_action('wp_head', array($this, 'add_page_meta'), 1);
    }

    public function add_rewrite_rules() {
        // Match property type URLs
        add_rewrite_rule(
            '^([a-z0-9-]+)-for-(sale|rent|lease)/?$',
            'index.php?mld_property_type_page=1&mld_property_type_slug=$matches[1]&mld_listing_type=$matches[2]',
            'top'
        );
    }

    public function add_query_vars($vars) {
        $vars[] = 'mld_property_type_page';
        $vars[] = 'mld_property_type_slug';
        $vars[] = 'mld_listing_type';
        return $vars;
    }

    public function handle_property_type_page_request() {
        if (!get_query_var('mld_property_type_page')) {
            return;
        }

        $slug = get_query_var('mld_property_type_slug');
        $listing_type = get_query_var('mld_listing_type');

        if (empty($slug)) {
            wp_die('Invalid property type page', 'Error', array('response' => 404));
        }

        // Get property type info
        $type_info = $this->get_property_type_from_slug($slug, $listing_type);

        if (!$type_info) {
            wp_die('Property type not found', 'Error', array('response' => 404));
        }

        // Get listing count
        $cache_key = 'mld_property_type_count_' . md5($slug . $listing_type);
        $listing_count = get_transient($cache_key);

        if (false === $listing_count) {
            $listing_count = $this->get_property_type_listing_count($type_info);
            set_transient($cache_key, $listing_count, 6 * HOUR_IN_SECONDS);
        }

        if ($listing_count === 0) {
            wp_die('No listings found for this property type', 'Error', array('response' => 404));
        }

        // Get property type stats
        $cache_key_stats = 'mld_property_type_stats_' . md5($slug . $listing_type);
        $stats = get_transient($cache_key_stats);

        if (false === $stats) {
            $stats = $this->get_property_type_stats($type_info);
            set_transient($cache_key_stats, $stats, 6 * HOUR_IN_SECONDS);
        }

        // Store for use in title/meta
        $GLOBALS['mld_property_type_data'] = array(
            'type_info' => $type_info,
            'listing_count' => $listing_count,
            'stats' => $stats
        );

        // Output the page
        $this->output_property_type_page($type_info, $listing_count, $stats);
        exit;
    }

    private function get_property_type_from_slug($slug, $listing_type) {
        global $wpdb;

        // Try to find matching property type or subtype
        foreach (self::$type_mappings as $type_name => $type_slug) {
            if ($type_slug === $slug) {
                // Determine if this is main type or subtype
                $is_main_type = in_array($type_name, array(
                    'Residential', 'Residential Lease', 'Residential Income',
                    'Commercial Sale', 'Commercial Lease', 'Land', 'Business Opportunity'
                ));

                return array(
                    'name' => $type_name,
                    'slug' => $type_slug,
                    'listing_type' => $listing_type,
                    'is_main_type' => $is_main_type
                );
            }
        }

        return null;
    }

    private function get_property_type_listing_count($type_info) {
        global $wpdb;

        $where_clauses = array("standard_status = 'Active'");

        if ($type_info['is_main_type']) {
            $where_clauses[] = $wpdb->prepare("property_type = %s", $type_info['name']);
        } else {
            $where_clauses[] = $wpdb->prepare("property_sub_type = %s", $type_info['name']);
        }

        $where = implode(' AND ', $where_clauses);

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE {$where}
        ");
    }

    private function get_property_type_stats($type_info) {
        global $wpdb;

        $where_clauses = array("standard_status = 'Active'");

        if ($type_info['is_main_type']) {
            $where_clauses[] = $wpdb->prepare("property_type = %s", $type_info['name']);
        } else {
            $where_clauses[] = $wpdb->prepare("property_sub_type = %s", $type_info['name']);
        }

        $where = implode(' AND ', $where_clauses);

        return $wpdb->get_row("
            SELECT
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(list_price) as avg_price,
                COUNT(DISTINCT city) as city_count
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE {$where}
                AND list_price > 0
        ");
    }

    /**
     * Get featured listings for property type page
     * Returns 12 featured listings sorted by newest first
     *
     * @param array $type_info Property type info
     * @param int $limit Number of listings to fetch
     * @return array Array of listing data
     */
    private function get_featured_listings($type_info, $limit = 12) {
        global $wpdb;

        $where_clauses = array("standard_status = 'Active'");

        if ($type_info['is_main_type']) {
            $where_clauses[] = $wpdb->prepare("property_type = %s", $type_info['name']);
        } else {
            $where_clauses[] = $wpdb->prepare("property_sub_type = %s", $type_info['name']);
        }

        $where = implode(' AND ', $where_clauses);

        // Use correct column names from wp_bme_listing_summary table
        $listings = $wpdb->get_results("
            SELECT
                listing_id,
                CONCAT(COALESCE(street_number, ''), ' ', COALESCE(street_name, '')) as street_address,
                unit_number,
                city,
                state_or_province,
                postal_code,
                list_price,
                bedrooms_total,
                bathrooms_total,
                building_area_total,
                main_photo_url as primary_photo,
                property_sub_type,
                days_on_market,
                modification_timestamp
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE {$where}
                AND list_price > 0
                AND main_photo_url IS NOT NULL
                AND main_photo_url != ''
            ORDER BY modification_timestamp DESC
            LIMIT " . intval($limit), ARRAY_A);

        return $listings ?: array();
    }

    public function modify_page_title($title) {
        if (!get_query_var('mld_property_type_page')) {
            return $title;
        }

        $data = $GLOBALS['mld_property_type_data'] ?? null;
        if (!$data) {
            return $title;
        }

        $type_name = $data['type_info']['name'];
        $count = number_format($data['listing_count']);
        $month_year = date('M Y');

        $action = $data['type_info']['listing_type'] === 'sale' ? 'for Sale' : 'for Rent';

        $title['title'] = "{$count} {$type_name} {$action} | Updated {$month_year}";

        return $title;
    }

    public function add_page_meta() {
        if (!get_query_var('mld_property_type_page')) {
            return;
        }

        $data = $GLOBALS['mld_property_type_data'] ?? null;
        if (!$data) {
            return;
        }

        $type_name = $data['type_info']['name'];
        $count = number_format($data['listing_count']);
        $stats = $data['stats'];
        $action = $data['type_info']['listing_type'] === 'sale' ? 'for sale' : 'for rent';

        // Meta description
        $description = "{$count} {$type_name} {$action}";
        
        if ($stats->min_price && $stats->max_price) {
            $min = '$' . number_format($stats->min_price);
            $max = '$' . number_format($stats->max_price);
            $description .= " ranging from {$min} to {$max}";
        }
        
        if ($stats->city_count) {
            $description .= " across {$stats->city_count} cities in Massachusetts";
        }

        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";

        // Open Graph
        echo '<meta property="og:title" content="' . esc_attr($type_name . ' ' . ucfirst($action)) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta property="og:type" content="website">' . "\n";

        // Schema.org CollectionPage
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $type_name . ' ' . ucfirst($action),
            'description' => $description,
            'url' => home_url($_SERVER['REQUEST_URI'])
        );

        echo '<script type="application/ld+json">' . json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    /**
     * Output the property type landing page with featured listings
     * Updated v6.14.3 - Comprehensive styling and listing grid
     */
    private function output_property_type_page($type_info, $listing_count, $stats) {
        // Get featured listings
        $featured_listings = $this->get_featured_listings($type_info, 12);

        // Get search page URL from settings
        $mld_settings = get_option('mld_settings', array());
        $search_page_url = !empty($mld_settings['search_page_url']) ? $mld_settings['search_page_url'] : '/search/';

        // Build filter URL
        $filters = array();
        if ($type_info['is_main_type']) {
            $filters[] = 'PropertyType=' . urlencode($type_info['name']);
        } else {
            $filters[] = 'home_type=' . urlencode($type_info['name']);
        }
        $filters[] = 'status=Active';
        $filter_url = home_url($search_page_url . '#' . implode('&', $filters));

        $type_name = $type_info['name'];
        $action = $type_info['listing_type'] === 'sale' ? 'for Sale' : 'for Rent';
        $action_lower = strtolower($action);

        get_header();
        ?>
        <style>
            .mld-ptype-page {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                color: #1a1a1a;
                line-height: 1.6;
            }
            .mld-ptype-hero {
                background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #3d7ab5 100%);
                color: white;
                padding: 60px 20px;
                text-align: center;
            }
            .mld-ptype-hero h1 {
                font-size: 2.5rem;
                font-weight: 700;
                margin: 0 0 15px 0;
                color: white;
            }
            .mld-ptype-hero .subtitle {
                font-size: 1.2rem;
                opacity: 0.9;
                margin-bottom: 30px;
            }
            .mld-ptype-stats-bar {
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
                gap: 30px;
                margin-top: 25px;
            }
            .mld-ptype-stat {
                text-align: center;
                padding: 15px 25px;
                background: rgba(255,255,255,0.15);
                border-radius: 10px;
                min-width: 140px;
            }
            .mld-ptype-stat-value {
                font-size: 1.8rem;
                font-weight: 700;
                display: block;
            }
            .mld-ptype-stat-label {
                font-size: 0.9rem;
                opacity: 0.85;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .mld-ptype-container {
                max-width: 1400px;
                margin: 0 auto;
                padding: 40px 20px;
            }
            .mld-ptype-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 25px;
                flex-wrap: wrap;
                gap: 15px;
            }
            .mld-ptype-section-header h2 {
                font-size: 1.75rem;
                font-weight: 600;
                margin: 0;
                color: #1a1a1a;
            }
            .mld-ptype-view-all {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #1e3a5f;
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                font-size: 1rem;
                transition: all 0.2s ease;
            }
            .mld-ptype-view-all:hover {
                background: #2d5a87;
                color: white;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(30, 58, 95, 0.3);
            }
            .mld-ptype-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 25px;
            }
            .mld-ptype-card {
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 12px rgba(0,0,0,0.08);
                transition: all 0.3s ease;
                text-decoration: none;
                color: inherit;
                display: block;
            }
            .mld-ptype-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            }
            .mld-ptype-card-image {
                position: relative;
                aspect-ratio: 4/3;
                overflow: hidden;
                background: #f0f0f0;
            }
            .mld-ptype-card-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform 0.3s ease;
            }
            .mld-ptype-card:hover .mld-ptype-card-image img {
                transform: scale(1.05);
            }
            .mld-ptype-card-badge {
                position: absolute;
                top: 12px;
                left: 12px;
                background: #16a34a;
                color: white;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
            }
            .mld-ptype-card-badge.new {
                background: #2563eb;
            }
            .mld-ptype-card-content {
                padding: 18px;
            }
            .mld-ptype-card-price {
                font-size: 1.4rem;
                font-weight: 700;
                color: #1a1a1a;
                margin-bottom: 8px;
            }
            .mld-ptype-card-address {
                font-size: 1rem;
                color: #1a1a1a;
                margin-bottom: 4px;
                font-weight: 500;
            }
            .mld-ptype-card-location {
                font-size: 0.9rem;
                color: #6b7280;
                margin-bottom: 12px;
            }
            .mld-ptype-card-specs {
                display: flex;
                gap: 15px;
                font-size: 0.9rem;
                color: #4b5563;
            }
            .mld-ptype-card-specs span {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .mld-ptype-card-specs strong {
                color: #1a1a1a;
            }
            .mld-ptype-content-section {
                background: #f9fafb;
                padding: 50px 20px;
                margin-top: 40px;
            }
            .mld-ptype-content-inner {
                max-width: 900px;
                margin: 0 auto;
            }
            .mld-ptype-content-inner h2 {
                font-size: 1.75rem;
                margin-bottom: 20px;
                color: #1a1a1a;
            }
            .mld-ptype-content-inner p {
                font-size: 1.1rem;
                color: #4b5563;
                margin-bottom: 15px;
                line-height: 1.7;
            }
            .mld-ptype-cta-section {
                text-align: center;
                padding: 50px 20px;
                background: white;
            }
            .mld-ptype-cta-section h3 {
                font-size: 1.5rem;
                margin-bottom: 15px;
            }
            .mld-ptype-cta-section p {
                color: #6b7280;
                margin-bottom: 25px;
                font-size: 1.1rem;
            }
            .mld-ptype-cta-btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
                color: white;
                padding: 16px 40px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                font-size: 1.1rem;
                transition: all 0.2s ease;
            }
            .mld-ptype-cta-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(30, 58, 95, 0.4);
                color: white;
            }
            @media (max-width: 768px) {
                .mld-ptype-hero {
                    padding: 40px 15px;
                }
                .mld-ptype-hero h1 {
                    font-size: 1.75rem;
                }
                .mld-ptype-hero .subtitle {
                    font-size: 1rem;
                }
                .mld-ptype-stats-bar {
                    gap: 15px;
                }
                .mld-ptype-stat {
                    padding: 12px 18px;
                    min-width: 120px;
                }
                .mld-ptype-stat-value {
                    font-size: 1.4rem;
                }
                .mld-ptype-grid {
                    grid-template-columns: 1fr;
                    gap: 20px;
                }
                .mld-ptype-section-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .mld-ptype-view-all {
                    width: 100%;
                    justify-content: center;
                }
            }
        </style>

        <div class="mld-ptype-page">
            <!-- Hero Section -->
            <div class="mld-ptype-hero">
                <h1><?php echo esc_html($type_name . ' ' . $action); ?></h1>
                <p class="subtitle">Find your perfect <?php echo esc_html(strtolower($type_name)); ?> in Massachusetts</p>

                <div class="mld-ptype-stats-bar">
                    <div class="mld-ptype-stat">
                        <span class="mld-ptype-stat-value"><?php echo number_format($listing_count); ?></span>
                        <span class="mld-ptype-stat-label">Active Listings</span>
                    </div>

                    <?php if ($stats->avg_price): ?>
                    <div class="mld-ptype-stat">
                        <span class="mld-ptype-stat-value">$<?php echo number_format($stats->avg_price / 1000); ?>K</span>
                        <span class="mld-ptype-stat-label">Avg. Price</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($stats->min_price && $stats->max_price): ?>
                    <div class="mld-ptype-stat">
                        <span class="mld-ptype-stat-value">$<?php echo number_format($stats->min_price / 1000); ?>K+</span>
                        <span class="mld-ptype-stat-label">Starting From</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($stats->city_count): ?>
                    <div class="mld-ptype-stat">
                        <span class="mld-ptype-stat-value"><?php echo number_format($stats->city_count); ?></span>
                        <span class="mld-ptype-stat-label">Cities</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Featured Listings Section -->
            <?php if (!empty($featured_listings)): ?>
            <div class="mld-ptype-container">
                <div class="mld-ptype-section-header">
                    <h2>Featured <?php echo esc_html($type_name); ?> Listings</h2>
                    <a href="<?php echo esc_url($filter_url); ?>" class="mld-ptype-view-all">
                        View All <?php echo number_format($listing_count); ?> Listings
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>

                <div class="mld-ptype-grid">
                    <?php foreach ($featured_listings as $listing):
                        $address = trim($listing['street_address'] . ($listing['unit_number'] ? ' #' . $listing['unit_number'] : ''));
                        $city_state = $listing['city'] . ', ' . $listing['state_or_province'];
                        $price = '$' . number_format($listing['list_price']);
                        $beds = (int)$listing['bedrooms_total'];
                        $baths = (float)$listing['bathrooms_total'];
                        $sqft = number_format((int)$listing['building_area_total']);
                        $photo = $listing['primary_photo'] ?: 'https://placehold.co/400x300/e5e7eb/9ca3af?text=No+Photo';
                        $listing_url = home_url('/property/' . $listing['listing_id'] . '/');
                        $is_new = (int)$listing['days_on_market'] <= 7;
                    ?>
                    <a href="<?php echo esc_url($listing_url); ?>" class="mld-ptype-card">
                        <div class="mld-ptype-card-image">
                            <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($address); ?>" loading="lazy">
                            <?php if ($is_new): ?>
                            <span class="mld-ptype-card-badge new">New</span>
                            <?php else: ?>
                            <span class="mld-ptype-card-badge">Active</span>
                            <?php endif; ?>
                        </div>
                        <div class="mld-ptype-card-content">
                            <div class="mld-ptype-card-price"><?php echo esc_html($price); ?></div>
                            <div class="mld-ptype-card-address"><?php echo esc_html($address); ?></div>
                            <div class="mld-ptype-card-location"><?php echo esc_html($city_state); ?></div>
                            <div class="mld-ptype-card-specs">
                                <span><strong><?php echo $beds; ?></strong> beds</span>
                                <span><strong><?php echo $baths; ?></strong> baths</span>
                                <?php if ($sqft > 0): ?>
                                <span><strong><?php echo $sqft; ?></strong> sqft</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Content Section for SEO -->
            <div class="mld-ptype-content-section">
                <div class="mld-ptype-content-inner">
                    <h2>About <?php echo esc_html($type_name); ?> in Massachusetts</h2>
                    <p>
                        Looking for <?php echo esc_html(strtolower($type_name)); ?> <?php echo esc_html($action_lower); ?> in Massachusetts?
                        Browse our comprehensive listings featuring <?php echo number_format($listing_count); ?> properties
                        across <?php echo number_format($stats->city_count); ?> cities and towns.
                    </p>
                    <p>
                        <?php echo esc_html($type_name); ?> prices in Massachusetts currently range from
                        $<?php echo number_format($stats->min_price); ?> to $<?php echo number_format($stats->max_price); ?>,
                        with an average price of $<?php echo number_format($stats->avg_price); ?>.
                        Whether you're a first-time buyer or looking to upgrade, we have options to fit every budget.
                    </p>
                    <p>
                        Our listings are updated daily with the latest MLS data, ensuring you have access to the most
                        current properties on the market. Use our advanced search filters to narrow down by location,
                        price range, bedrooms, and more.
                    </p>
                </div>
            </div>

            <!-- CTA Section -->
            <div class="mld-ptype-cta-section">
                <h3>Ready to Find Your <?php echo esc_html($type_name); ?>?</h3>
                <p>Search all <?php echo number_format($listing_count); ?> <?php echo esc_html(strtolower($type_name)); ?> listings with our interactive map and advanced filters.</p>
                <a href="<?php echo esc_url($filter_url); ?>" class="mld-ptype-cta-btn">
                    Search All Listings
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                </a>
            </div>
        </div>
        <?php

        get_footer();
    }

    /**
     * Get URL for a property type
     */
    public static function get_property_type_url($property_type, $property_sub_type = '', $listing_type = 'sale') {
        // Determine which type to use (subtype takes precedence)
        $type_name = !empty($property_sub_type) ? $property_sub_type : $property_type;
        
        // Get slug from mapping
        $slug = self::$type_mappings[$type_name] ?? null;
        
        if (!$slug) {
            return '';
        }

        // Build URL
        $url_action = ($listing_type === 'rent' || $listing_type === 'lease') ? 'rent' : 'sale';
        
        return home_url("/{$slug}-for-{$url_action}/");
    }

    /**
     * Get all available property types from database
     */
    public static function get_all_property_types() {
        global $wpdb;

        $cache_key = 'mld_all_property_types';
        $types = get_transient($cache_key);

        if (false !== $types) {
            return $types;
        }

        $types = $wpdb->get_results("
            SELECT DISTINCT
                property_type,
                property_sub_type,
                COUNT(*) as listing_count
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE standard_status = 'Active'
                AND property_type IS NOT NULL
                AND property_type != ''
            GROUP BY property_type, property_sub_type
            HAVING listing_count > 0
            ORDER BY listing_count DESC
        ");

        set_transient($cache_key, $types, 24 * HOUR_IN_SECONDS);

        return $types;
    }
}

// Initialize - use plugins_loaded if available, otherwise initialize immediately
// This handles the case where the plugin is activated after plugins_loaded has fired
if (did_action('plugins_loaded')) {
    MLD_Property_Type_Pages::get_instance();
} else {
    add_action('plugins_loaded', function() {
        MLD_Property_Type_Pages::get_instance();
    });
}
