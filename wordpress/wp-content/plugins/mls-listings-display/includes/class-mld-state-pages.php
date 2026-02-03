<?php
/**
 * State Landing Pages for MLS Listings Display
 *
 * Handles SEO-optimized state landing pages for real estate listings
 * URL format: /homes-for-sale-in-{state}/
 *
 * @package MLS_Listings_Display
 * @since 6.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_State_Pages {

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
        add_action('init', array($this, 'add_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_state_page_request'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('document_title_parts', array($this, 'modify_state_page_title'), 10, 1);
        add_action('wp_head', array($this, 'add_state_page_meta'), 1);
    }

    public function add_rewrite_rules() {
        // Match /homes-for-sale-in-massachusetts/ or /homes-for-sale-in-ma/
        add_rewrite_rule(
            '^homes-for-sale-in-([a-z-]+)/?$',
            'index.php?mld_state_page=1&mld_state=$matches[1]',
            'top'
        );
    }

    public function add_query_vars($vars) {
        $vars[] = 'mld_state_page';
        $vars[] = 'mld_state';
        return $vars;
    }

    public function handle_state_page_request() {
        if (!get_query_var('mld_state_page')) {
            return;
        }

        $state_slug = get_query_var('mld_state');

        if (empty($state_slug)) {
            wp_die('Invalid state page', 'Error', array('response' => 404));
        }

        // Convert slug to state code
        // Support both full name (massachusetts) and abbreviation (ma)
        $state_code = $this->slug_to_state_code($state_slug);

        if (!$state_code) {
            wp_die('State not found', 'Error', array('response' => 404));
        }

        // Get state stats (with caching)
        $cache_key_count = 'mld_state_count_' . $state_code;
        $listing_count = get_transient($cache_key_count);

        if (false === $listing_count) {
            $listing_count = $this->get_state_listing_count($state_code);
            set_transient($cache_key_count, $listing_count, 6 * HOUR_IN_SECONDS);
        }

        if ($listing_count === 0) {
            wp_die('No listings found for this state', 'Error', array('response' => 404));
        }

        // Get state stats
        $cache_key_stats = 'mld_state_stats_' . $state_code;
        $state_stats = get_transient($cache_key_stats);

        if (false === $state_stats) {
            $state_stats = $this->get_state_stats($state_code);
            set_transient($cache_key_stats, $state_stats, 6 * HOUR_IN_SECONDS);
        }

        // Get cities in this state
        $cache_key_cities = 'mld_state_cities_' . $state_code;
        $cities = get_transient($cache_key_cities);

        if (false === $cities) {
            $cities = $this->get_state_cities($state_code);
            set_transient($cache_key_cities, $cities, 6 * HOUR_IN_SECONDS);
        }

        // Set up page data for template
        global $mld_state_page_data;
        $mld_state_page_data = array(
            'state' => $state_code,
            'state_name' => $this->get_state_full_name($state_code),
            'state_slug' => $state_slug,
            'listing_count' => $listing_count,
            'stats' => $state_stats,
            'cities' => $cities
        );

        // Load template
        $this->load_state_page_template();
        exit;
    }

    private function get_state_listing_count($state_code) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT l.listing_id)
            FROM {$wpdb->prefix}bme_listings l
            INNER JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE l.standard_status IN ('Active', 'Pending')
            AND loc.state_or_province = %s
        ", $state_code));

        return (int) $count;
    }

    private function get_state_stats($state_code) {
        global $wpdb;

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(DISTINCT l.listing_id) as total_listings,
                COUNT(DISTINCT loc.city) as total_cities,
                MIN(l.list_price) as min_price,
                MAX(l.list_price) as max_price,
                AVG(l.list_price) as avg_price,
                COUNT(DISTINCT l.property_type) as property_types
            FROM {$wpdb->prefix}bme_listings l
            INNER JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE l.standard_status IN ('Active', 'Pending')
            AND loc.state_or_province = %s
            AND l.list_price > 0
        ", $state_code), ARRAY_A);

        return $stats;
    }

    private function get_state_cities($state_code) {
        global $wpdb;

        $cities = $wpdb->get_results($wpdb->prepare("
            SELECT
                loc.city,
                COUNT(DISTINCT l.listing_id) as listing_count,
                MIN(l.list_price) as min_price,
                MAX(l.list_price) as max_price,
                AVG(l.list_price) as avg_price
            FROM {$wpdb->prefix}bme_listings l
            INNER JOIN {$wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
            WHERE l.standard_status IN ('Active', 'Pending')
            AND loc.state_or_province = %s
            AND l.list_price > 0
            AND loc.city IS NOT NULL
            AND loc.city != ''
            GROUP BY loc.city
            ORDER BY listing_count DESC
        ", $state_code), ARRAY_A);

        return $cities;
    }

    private function slug_to_state_code($slug) {
        $state_map = array(
            'massachusetts' => 'MA',
            'ma' => 'MA',
            // Add more states as needed
        );

        $slug_lower = strtolower($slug);
        return isset($state_map[$slug_lower]) ? $state_map[$slug_lower] : false;
    }

    private function get_state_full_name($state_code) {
        $state_names = array(
            'MA' => 'Massachusetts',
            // Add more states as needed
        );

        return isset($state_names[$state_code]) ? $state_names[$state_code] : $state_code;
    }

    private function slug_to_title($slug) {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function load_state_page_template() {
        global $mld_state_page_data;

        // Add body class for CSS targeting
        add_filter('body_class', function($classes) {
            $classes[] = 'mld-state-page';
            return $classes;
        });

        // Start output buffering
        ob_start();

        // Include WordPress header
        get_header();

        $state_code = $mld_state_page_data['state'];
        $state_name = $mld_state_page_data['state_name'];
        $listing_count = $mld_state_page_data['listing_count'];
        $stats = $mld_state_page_data['stats'];
        $cities = $mld_state_page_data['cities'];

        ?>
        <!-- MLD State Page Styles -->
        <style>
        .mld-state-page-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            background: #f8f9fa;
        }
        .mld-state-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        .mld-state-hero h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: 600;
        }
        .mld-state-hero p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 1.2em;
        }
        .mld-state-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .mld-market-stats {
            background: white;
            margin: -20px 20px 20px 20px;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mld-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .mld-stat-card {
            text-align: center;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #f8f9fa;
        }
        .mld-stat-card.highlight {
            background: #f0f4ff;
            border-color: #667eea;
        }
        .mld-stat-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mld-stat-value {
            font-size: 2em;
            font-weight: 700;
            color: #333;
        }
        .mld-section {
            background: white;
            margin: 20px;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .mld-section h2 {
            margin: 0 0 20px 0;
            font-size: 1.5em;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .mld-cities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .mld-city-card {
            display: block;
            padding: 20px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
        }
        .mld-city-card:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .mld-city-card-title {
            font-weight: 600;
            font-size: 1.2em;
            margin-bottom: 8px;
        }
        .mld-city-card-meta {
            font-size: 0.9em;
            opacity: 0.8;
        }
        </style>

        <div class="mld-state-page-wrapper">
            <!-- Hero Section -->
            <div class="mld-state-hero">
                <div class="mld-state-container">
                    <h1><?php echo esc_html($listing_count); ?> Homes for Sale in <?php echo esc_html($state_name); ?></h1>
                    <p>Updated <?php echo date('F Y'); ?></p>
                </div>
            </div>

            <!-- Market Stats -->
            <div class="mld-market-stats">
                <h2>Market Overview</h2>
                <div class="mld-stats-grid">
                    <div class="mld-stat-card highlight">
                        <div class="mld-stat-label">Active Listings</div>
                        <div class="mld-stat-value"><?php echo number_format($stats['total_listings']); ?></div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Cities</div>
                        <div class="mld-stat-value"><?php echo number_format($stats['total_cities']); ?></div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Average Price</div>
                        <div class="mld-stat-value">$<?php echo number_format($stats['avg_price'], 0); ?></div>
                    </div>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Price Range</div>
                        <div class="mld-stat-value">$<?php echo number_format($stats['min_price'], 0); ?></div>
                        <div class="mld-stat-subvalue">to $<?php echo number_format($stats['max_price'], 0); ?></div>
                    </div>
                </div>
            </div>

            <!-- Cities Section -->
            <div class="mld-section">
                <h2>Browse by City</h2>
                <div class="mld-cities-grid">
                    <?php foreach ($cities as $city):
                        $city_slug = sanitize_title($city['city']);
                        $city_url = home_url('/homes-for-sale-in-' . $city_slug . '-' . strtolower($state_code) . '/');
                    ?>
                        <a href="<?php echo esc_url($city_url); ?>" class="mld-city-card">
                            <div class="mld-city-card-title"><?php echo esc_html($city['city']); ?></div>
                            <div class="mld-city-card-meta">
                                <?php echo number_format($city['listing_count']); ?> listings
                                <br>
                                Avg: $<?php echo number_format($city['avg_price'], 0); ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php
        get_footer();

        // End output buffering and display
        ob_end_flush();
    }

    public function modify_state_page_title($title_parts) {
        global $mld_state_page_data;

        if (!empty($mld_state_page_data)) {
            $state_name = $mld_state_page_data['state_name'];
            $listing_count = $mld_state_page_data['listing_count'];

            $title_parts['title'] = $listing_count . ' Homes for Sale in ' . $state_name . ' | Updated ' . date('M Y');

            // Remove default separator and tagline for cleaner title
            unset($title_parts['tagline']);
        }

        return $title_parts;
    }

    public function add_state_page_meta() {
        global $mld_state_page_data;

        if (empty($mld_state_page_data)) {
            return;
        }

        $state_name = $mld_state_page_data['state_name'];
        $state_code = $mld_state_page_data['state'];
        $listing_count = $mld_state_page_data['listing_count'];
        $stats = $mld_state_page_data['stats'];

        // Meta description
        $description = sprintf(
            'Find %s homes for sale in %s. Browse %s cities with average prices from $%s. Updated %s.',
            number_format($listing_count),
            $state_name,
            number_format($stats['total_cities']),
            number_format($stats['avg_price'], 0),
            date('F Y')
        );

        $current_url = home_url('/homes-for-sale-in-' . $mld_state_page_data['state_slug'] . '/');

        ?>
        <!-- Meta Tags -->
        <meta name="description" content="<?php echo esc_attr($description); ?>" />
        <link rel="canonical" href="<?php echo esc_url($current_url); ?>" />

        <!-- Open Graph -->
        <meta property="og:type" content="website" />
        <meta property="og:title" content="<?php echo esc_attr($listing_count . ' Homes for Sale in ' . $state_name); ?>" />
        <meta property="og:description" content="<?php echo esc_attr($description); ?>" />
        <meta property="og:url" content="<?php echo esc_url($current_url); ?>" />

        <!-- Twitter Card -->
        <meta name="twitter:card" content="summary" />
        <meta name="twitter:title" content="<?php echo esc_attr($listing_count . ' Homes for Sale in ' . $state_name); ?>" />
        <meta name="twitter:description" content="<?php echo esc_attr($description); ?>" />

        <!-- Schema.org Markup -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "CollectionPage",
            "name": "<?php echo esc_js($listing_count . ' Homes for Sale in ' . $state_name); ?>",
            "description": "<?php echo esc_js($description); ?>",
            "url": "<?php echo esc_url($current_url); ?>",
            "mainEntity": {
                "@type": "Place",
                "name": "<?php echo esc_js($state_name); ?>",
                "address": {
                    "@type": "PostalAddress",
                    "addressRegion": "<?php echo esc_js($state_code); ?>",
                    "addressCountry": "US"
                }
            }
        }
        </script>
        <?php
    }
}

// Initialize
MLD_State_Pages::get_instance();
