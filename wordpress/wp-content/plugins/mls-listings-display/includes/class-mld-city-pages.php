<?php
/**
 * City Landing Pages for MLS Listings Display
 *
 * Handles SEO-optimized city landing pages for real estate listings
 * URL format: /homes-for-sale-in-{city}-{state}/
 *
 * @package MLS_Listings_Display
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_City_Pages {

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
        add_action('init', array($this, 'add_rewrite_rules'), 1);
        add_action('template_redirect', array($this, 'handle_city_page_request'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('document_title_parts', array($this, 'modify_city_page_title'), 10, 1);
        add_action('wp_head', array($this, 'add_city_page_meta'), 1);
    }

    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^homes-for-sale-in-([a-z0-9-]+)-([a-z]{2})/?$',
            'index.php?mld_city_page=1&mld_city=$matches[1]&mld_state=$matches[2]',
            'top'
        );
    }

    public function add_query_vars($vars) {
        $vars[] = 'mld_city_page';
        $vars[] = 'mld_city';
        $vars[] = 'mld_state';
        return $vars;
    }

    public function handle_city_page_request() {
        if (!get_query_var('mld_city_page')) {
            return;
        }

        $city_slug = get_query_var('mld_city');
        $state_slug = get_query_var('mld_state');

        if (empty($city_slug) || empty($state_slug)) {
            wp_die('Invalid city page', 'Error', array('response' => 404));
        }

        // Convert slugs to proper names
        $city_name = $this->slug_to_title($city_slug);
        $state_code = strtoupper($state_slug);

        // Verify city has listings (with caching)
        $cache_key_count = MLD_Performance_Cache::get_city_count_key($city_name, $state_code);
        $listing_count = MLD_Performance_Cache::remember(
            $cache_key_count,
            function() use ($city_name, $state_code) {
                return $this->get_city_listing_count($city_name, $state_code);
            },
            MLD_Performance_Cache::CACHE_CITY_STATS
        );

        if ($listing_count === 0) {
            wp_die('No listings found for this location', 'Error', array('response' => 404));
        }

        // Get search page URL from settings
        $mld_settings = get_option('mld_settings', array());
        $search_page_url = !empty($mld_settings['search_page_url']) ? $mld_settings['search_page_url'] : '/search/';

        // Redirect to search page with city filter pre-applied
        $redirect_url = home_url($search_page_url . '#City=' . urlencode($city_name) . '&PropertyType=Residential&status=Active');

        wp_redirect($redirect_url, 301);
        exit;
    }

    private function load_city_page_template() {
        global $mld_city_page_data;

        // Add body class for CSS targeting
        add_filter('body_class', function($classes) {
            $classes[] = 'mld-city-page';
            return $classes;
        });

        // Start output buffering
        ob_start();

        // Include WordPress header
        get_header();

        ?>
        <!-- MLD City Page Styles -->
        <style>
        .mld-city-page-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            background: #f8f9fa;
        }
        .mld-breadcrumbs {
            background: white;
            padding: 12px 20px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        .mld-breadcrumbs a {
            color: #667eea;
            text-decoration: none;
        }
        .mld-breadcrumbs a:hover {
            text-decoration: underline;
        }
        .mld-breadcrumbs span {
            color: #666;
            margin: 0 8px;
        }
        .mld-city-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .mld-city-hero h1 {
            margin: 0;
            font-size: 2em;
            font-weight: 600;
        }
        .mld-city-hero p {
            margin: 8px 0 0 0;
            opacity: 0.9;
            font-size: 1.1em;
        }
        .mld-city-container {
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .mld-stat-card {
            text-align: center;
            padding: 15px;
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
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mld-stat-value {
            font-size: 1.8em;
            font-weight: 700;
            color: #333;
        }
        .mld-stat-subvalue {
            font-size: 0.9em;
            color: #888;
            margin-top: 4px;
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
        .mld-nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }
        .mld-nav-card {
            display: block;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: all 0.2s;
        }
        .mld-nav-card:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }
        .mld-nav-card-title {
            font-weight: 600;
            font-size: 1.05em;
            margin-bottom: 5px;
        }
        .mld-nav-card-meta {
            font-size: 0.85em;
            opacity: 0.8;
        }
        .mld-property-types {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        .mld-type-badge {
            padding: 10px 20px;
            background: #f0f4ff;
            border: 1px solid #667eea;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .mld-type-badge strong {
            color: #667eea;
        }
        .mld-city-content {
            margin: 20px;
        }
        /* Override all fixed positioning and overflow rules from half map */
        body.mld-city-page,
        html.mld-city-page {
            overflow: auto !important;
            position: static !important;
            height: auto !important;
        }
        .mld-city-page-wrapper .mld-fixed-wrapper {
            position: relative !important;
            top: auto !important;
            left: auto !important;
            width: 100% !important;
            height: 700px !important;
            z-index: auto !important;
            border-radius: 12px;
            overflow: visible !important;
        }
        .mld-city-page-wrapper .bme-map-ui-wrapper {
            position: relative !important;
            height: 700px !important;
        }
        .mld-city-page-wrapper #bme-map-container {
            position: relative !important;
            height: 700px !important;
        }
        @media (max-width: 768px) {
            .mld-city-hero h1 {
                font-size: 1.5em;
            }
            .mld-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .mld-stat-value {
                font-size: 1.4em;
            }
            .mld-market-stats, .mld-section, .mld-city-content {
                margin: 10px;
                padding: 20px;
            }
        }
        </style>

        <div class="mld-city-page-wrapper">
            <!-- Breadcrumbs -->
            <div class="mld-breadcrumbs">
                <a href="<?php echo home_url('/'); ?>">Home</a>
                <span>›</span>
                <a href="<?php echo home_url('/'); ?>">Real Estate</a>
                <span>›</span>
                <a href="<?php echo home_url('/'); ?>"><?php echo esc_html($mld_city_page_data['state']); ?></a>
                <span>›</span>
                <strong><?php echo esc_html($mld_city_page_data['city']); ?></strong>
            </div>

            <!-- Compact Hero -->
            <div class="mld-city-hero">
                <h1><?php echo esc_html($mld_city_page_data['city']); ?>, <?php echo esc_html($mld_city_page_data['state']); ?> Real Estate</h1>
                <p><?php echo number_format($mld_city_page_data['listing_count']); ?> Listings Available | Updated <?php echo date('F j, Y'); ?></p>
            </div>

            <!-- Comprehensive Market Statistics -->
            <div class="mld-market-stats">
                <h2>Market Overview</h2>
                <div class="mld-stats-grid">
                    <?php $stats = $mld_city_page_data['stats']; ?>

                    <div class="mld-stat-card highlight">
                        <div class="mld-stat-label">Total Listings</div>
                        <div class="mld-stat-value"><?php echo number_format($mld_city_page_data['listing_count']); ?></div>
                        <?php if (!empty($stats['new_this_week'])): ?>
                        <div class="mld-stat-subvalue">+<?php echo $stats['new_this_week']; ?> this week</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($stats['median_price'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Median Price</div>
                        <div class="mld-stat-value">$<?php echo number_format($stats['median_price'], 0); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['avg_price'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Average Price</div>
                        <div class="mld-stat-value">$<?php echo number_format($stats['avg_price'], 0); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['price_per_sqft'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Price per Sq Ft</div>
                        <div class="mld-stat-value">$<?php echo number_format($stats['price_per_sqft'], 0); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['avg_dom'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Avg Days on Market</div>
                        <div class="mld-stat-value"><?php echo round($stats['avg_dom']); ?></div>
                        <div class="mld-stat-subvalue">Active listings</div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['pending_count'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Pending Sales</div>
                        <div class="mld-stat-value"><?php echo number_format($stats['pending_count']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['sold_count_30d'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Sold (30 days)</div>
                        <div class="mld-stat-value"><?php echo number_format($stats['sold_count_30d']); ?></div>
                        <?php if (!empty($stats['avg_sold_price'])): ?>
                        <div class="mld-stat-subvalue">Avg: $<?php echo number_format($stats['avg_sold_price'], 0); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($stats['price_changes_30d'])): ?>
                    <div class="mld-stat-card">
                        <div class="mld-stat-label">Price Changes</div>
                        <div class="mld-stat-value"><?php echo number_format($stats['price_changes_30d']); ?></div>
                        <div class="mld-stat-subvalue">Last 30 days</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Property Types Breakdown -->
            <?php if (!empty($stats['property_types']) && is_array($stats['property_types'])): ?>
            <div class="mld-section">
                <h2>Property Types in <?php echo esc_html($mld_city_page_data['city']); ?></h2>
                <div class="mld-property-types">
                    <?php foreach ($stats['property_types'] as $type): ?>
                        <div class="mld-type-badge">
                            <strong><?php echo esc_html($type->property_type); ?></strong>
                            · <?php echo number_format($type->count); ?> listings
                            <?php if ($type->avg_price > 0): ?>
                            · Avg $<?php echo number_format($type->avg_price, 0); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Neighborhoods/Zip Codes -->
            <?php if (!empty($stats['neighborhoods']) && is_array($stats['neighborhoods'])): ?>
            <div class="mld-section">
                <h2>Browse by Neighborhood</h2>
                <div class="mld-nav-grid">
                    <?php foreach ($stats['neighborhoods'] as $neighborhood): ?>
                        <a href="<?php echo home_url('/'); ?>?postal_code=<?php echo urlencode($neighborhood->postal_code); ?>&city=<?php echo urlencode($mld_city_page_data['city']); ?>" class="mld-nav-card">
                            <div class="mld-nav-card-title"><?php echo esc_html($neighborhood->postal_code); ?></div>
                            <div class="mld-nav-card-meta">
                                <?php echo number_format($neighborhood->count); ?> listings
                                <?php if ($neighborhood->avg_price > 0): ?>
                                · Avg $<?php echo number_format($neighborhood->avg_price, 0); ?>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Nearby Cities -->
            <?php if (!empty($stats['nearby_cities']) && is_array($stats['nearby_cities'])): ?>
            <div class="mld-section">
                <h2>Explore Nearby Cities in <?php echo esc_html($mld_city_page_data['state']); ?></h2>
                <div class="mld-nav-grid">
                    <?php foreach ($stats['nearby_cities'] as $nearby): ?>
                        <?php
                        $city_slug = strtolower(str_replace(' ', '-', $nearby->city));
                        $state_slug = strtolower($mld_city_page_data['state']);
                        ?>
                        <a href="<?php echo home_url('/homes-for-sale-in-' . $city_slug . '-' . $state_slug . '/'); ?>" class="mld-nav-card">
                            <div class="mld-nav-card-title"><?php echo esc_html($nearby->city); ?></div>
                            <div class="mld-nav-card-meta">
                                <?php echo number_format($nearby->count); ?> listings
                                <?php if ($nearby->avg_price > 0): ?>
                                · Avg $<?php echo number_format($nearby->avg_price, 0); ?>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Map and Listings -->
            <div class="mld-section">
                <h2>Browse Listings in <?php echo esc_html($mld_city_page_data['city']); ?></h2>
                <div class="mld-city-content">
                    <?php
                    // Display half map view with property list and city pre-filtered
                    echo do_shortcode('[bme_listings_half_map_view city="' . esc_attr($mld_city_page_data['city']) . '"]');
                    ?>
                </div>
            </div>

        </div>
        <!-- End MLD City Page -->
        <?php

        // Include WordPress footer
        get_footer();

        // Output and clean buffer
        echo ob_get_clean();
    }

    public function modify_city_page_title($title) {
        if (!get_query_var('mld_city_page')) {
            return $title;
        }

        global $mld_city_page_data;

        if (empty($mld_city_page_data)) {
            return $title;
        }

        $new_title = array(
            'title' => sprintf(
                '%d Homes for Sale in %s, %s | Updated %s',
                $mld_city_page_data['listing_count'],
                $mld_city_page_data['city'],
                $mld_city_page_data['state'],
                date('M Y')
            )
        );

        return $new_title;
    }

    public function add_city_page_meta() {
        if (!get_query_var('mld_city_page')) {
            return;
        }

        global $mld_city_page_data;

        if (empty($mld_city_page_data)) {
            return;
        }

        $city = $mld_city_page_data['city'];
        $state = $mld_city_page_data['state'];
        $count = $mld_city_page_data['listing_count'];
        $stats = $mld_city_page_data['stats'];

        // Build enhanced description with market statistics
        $description_parts = array();
        $description_parts[] = sprintf('Browse %d homes for sale in %s, %s', $count, $city, $state);

        // Add price range if available
        if (!empty($stats['price_range']) && !empty($stats['avg_price'])) {
            $min_price = $stats['price_range']['min'];
            $max_price = $stats['price_range']['max'];
            $avg_price = $stats['avg_price'];

            if ($min_price > 0 && $max_price > 0) {
                $description_parts[] = sprintf(
                    'from $%s to $%s',
                    number_format($min_price, 0),
                    number_format($max_price, 0)
                );
            }

            if ($avg_price > 0) {
                $description_parts[] = sprintf('(avg: $%s)', number_format($avg_price, 0));
            }
        }

        // Add property types if available
        if (!empty($stats['property_types']) && count($stats['property_types']) > 0) {
            $types = array_slice($stats['property_types'], 0, 3); // Limit to 3 types
            $type_names = array_map(function($type) {
                return is_object($type) ? $type->property_type : $type;
            }, $types);
            $description_parts[] = 'including ' . implode(', ', $type_names);
        }

        // Add call to action
        $description_parts[] = 'View photos, pricing, and property details';
        $description_parts[] = sprintf('Updated %s', date('F Y'));

        $description = implode('. ', $description_parts) . '.';

        // Ensure description doesn't exceed 160 characters
        if (strlen($description) > 160) {
            $description = substr($description, 0, 157) . '...';
        }

        $canonical_url = home_url("/homes-for-sale-in-{$mld_city_page_data['city_slug']}-{$mld_city_page_data['state_slug']}/");

        ?>
        <!-- MLD City Page Meta -->
        <meta name="description" content="<?php echo esc_attr($description); ?>">
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="<?php echo esc_url($canonical_url); ?>">

        <!-- Open Graph -->
        <meta property="og:type" content="website">
        <meta property="og:title" content="Homes for Sale in <?php echo esc_attr($city); ?>, <?php echo esc_attr($state); ?>">
        <meta property="og:description" content="<?php echo esc_attr($description); ?>">
        <meta property="og:url" content="<?php echo esc_url($canonical_url); ?>">

        <!-- Twitter Card -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Homes for Sale in <?php echo esc_attr($city); ?>, <?php echo esc_attr($state); ?>">
        <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">

        <!-- Schema.org CollectionPage Markup -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "CollectionPage",
            "name": "Homes for Sale in <?php echo esc_js($city); ?>, <?php echo esc_js($state); ?>",
            "description": "<?php echo esc_js($description); ?>",
            "url": "<?php echo esc_js($canonical_url); ?>",
            "numberOfItems": <?php echo intval($count); ?>,
            "about": {
                "@type": "Place",
                "name": "<?php echo esc_js($city); ?>, <?php echo esc_js($state); ?>",
                "address": {
                    "@type": "PostalAddress",
                    "addressLocality": "<?php echo esc_js($city); ?>",
                    "addressRegion": "<?php echo esc_js($state); ?>"
                }
            }
        }
        </script>

        <!-- BreadcrumbList Schema -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "BreadcrumbList",
            "itemListElement": [
                {
                    "@type": "ListItem",
                    "position": 1,
                    "name": "Home",
                    "item": "<?php echo esc_js(home_url('/')); ?>"
                },
                {
                    "@type": "ListItem",
                    "position": 2,
                    "name": "Homes for Sale",
                    "item": "<?php echo esc_js(home_url('/')); ?>"
                },
                {
                    "@type": "ListItem",
                    "position": 3,
                    "name": "<?php echo esc_js($city . ', ' . $state); ?>",
                    "item": "<?php echo esc_js($canonical_url); ?>"
                }
            ]
        }
        </script>
        <?php
    }

    private function get_city_listing_count($city, $state) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
                AND state_or_province = %s
                AND standard_status IN ('Active', 'Pending')
        ", $city, $state));
    }

    private function get_city_stats($city, $state) {
        global $wpdb;

        $stats = array();

        // Get comprehensive price statistics
        $price_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                AVG(list_price) as avg_price,
                MIN(list_price) as min_price,
                MAX(list_price) as max_price,
                AVG(CASE WHEN living_area > 0 THEN list_price / living_area END) as price_per_sqft,
                AVG(days_on_market) as avg_dom,
                COUNT(CASE WHEN standard_status = 'Active' THEN 1 END) as active_count,
                COUNT(CASE WHEN standard_status = 'Pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN DATEDIFF(NOW(), list_date) <= 7 THEN 1 END) as new_this_week,
                COUNT(CASE WHEN DATEDIFF(NOW(), list_date) <= 30 THEN 1 END) as new_this_month,
                COUNT(CASE WHEN price_change_timestamp IS NOT NULL
                    AND DATEDIFF(NOW(), price_change_timestamp) <= 30
                    AND price_change_timestamp != '0000-00-00 00:00:00' THEN 1 END) as price_changes_30d
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
                AND state_or_province = %s
                AND standard_status IN ('Active', 'Pending')
                AND list_price > 0
        ", $city, $state));

        if ($price_stats) {
            $stats['avg_price'] = $price_stats->avg_price;
            $stats['price_range'] = array(
                'min' => $price_stats->min_price,
                'max' => $price_stats->max_price
            );
            $stats['price_per_sqft'] = $price_stats->price_per_sqft;
            $stats['avg_dom'] = $price_stats->avg_dom;
            $stats['active_count'] = $price_stats->active_count;
            $stats['pending_count'] = $price_stats->pending_count;
            $stats['new_this_week'] = $price_stats->new_this_week;
            $stats['new_this_month'] = $price_stats->new_this_month;
            $stats['price_changes_30d'] = $price_stats->price_changes_30d;
        }

        // Get median price (separate query since MySQL doesn't have built-in median)
        $median_price = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(t1.list_price) as median_price
            FROM (
                SELECT list_price, @rownum:=@rownum+1 as row_num, @total_rows:=@rownum
                FROM {$wpdb->prefix}bme_listing_summary, (SELECT @rownum:=0) r
                WHERE city = %s
                    AND state_or_province = %s
                    AND standard_status IN ('Active', 'Pending')
                    AND list_price > 0
                ORDER BY list_price
            ) t1,
            (SELECT @total_rows:=0) r2
            WHERE t1.row_num IN (FLOOR((@total_rows+1)/2), FLOOR((@total_rows+2)/2))
        ", $city, $state));

        if ($median_price) {
            $stats['median_price'] = $median_price;
        }

        // Get sold statistics for trend comparison (last 30 days)
        $sold_stats = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as sold_count,
                AVG(close_price) as avg_sold_price,
                AVG(days_on_market) as avg_sold_dom
            FROM {$wpdb->prefix}bme_listings
            WHERE city = %s
                AND state_or_province = %s
                AND standard_status = 'Closed'
                AND close_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND close_price > 0
        ", $city, $state));

        if ($sold_stats) {
            $stats['sold_count_30d'] = $sold_stats->sold_count;
            $stats['avg_sold_price'] = $sold_stats->avg_sold_price;
            $stats['avg_sold_dom'] = $sold_stats->avg_sold_dom;
        }

        // Get property types with counts
        $property_types = $wpdb->get_results($wpdb->prepare("
            SELECT
                property_type,
                COUNT(*) as count,
                AVG(list_price) as avg_price
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
                AND state_or_province = %s
                AND standard_status IN ('Active', 'Pending')
                AND property_type IS NOT NULL
                AND property_type != ''
            GROUP BY property_type
            ORDER BY count DESC
            LIMIT 10
        ", $city, $state));

        if ($property_types) {
            $stats['property_types'] = $property_types;
        }

        // Get neighborhoods with counts and prices
        $neighborhoods = $wpdb->get_results($wpdb->prepare("
            SELECT
                postal_code,
                COUNT(*) as count,
                AVG(list_price) as avg_price
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE city = %s
                AND state_or_province = %s
                AND standard_status IN ('Active', 'Pending')
                AND postal_code IS NOT NULL
                AND postal_code != ''
            GROUP BY postal_code
            ORDER BY count DESC
            LIMIT 10
        ", $city, $state));

        if ($neighborhoods) {
            $stats['neighborhoods'] = $neighborhoods;
        }

        // Get nearby cities (same state, has active listings)
        $nearby_cities = $wpdb->get_results($wpdb->prepare("
            SELECT
                city,
                COUNT(*) as count,
                AVG(list_price) as avg_price
            FROM {$wpdb->prefix}bme_listing_summary
            WHERE state_or_province = %s
                AND city != %s
                AND standard_status IN ('Active', 'Pending')
            GROUP BY city
            HAVING count > 0
            ORDER BY count DESC
            LIMIT 12
        ", $state, $city));

        if ($nearby_cities) {
            $stats['nearby_cities'] = $nearby_cities;
        }

        return $stats;
    }

    private function slug_to_title($slug) {
        return ucwords(str_replace('-', ' ', $slug));
    }
}

// Initialize - use plugins_loaded if available, otherwise initialize immediately
// This handles the case where the plugin is activated after plugins_loaded has fired
if (did_action('plugins_loaded')) {
    MLD_City_Pages::get_instance();
} else {
    add_action('plugins_loaded', function() {
        MLD_City_Pages::get_instance();
    });
}
