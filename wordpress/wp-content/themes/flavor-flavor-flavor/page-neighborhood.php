<?php
/**
 * Template Name: Neighborhood Landing Page
 *
 * Dynamic landing page for neighborhoods/cities
 * Displays real estate listings, market stats, and local info
 * Supports auto-generated city pages via rewrite rules
 *
 * @package flavor_flavor_flavor
 * @version 1.3.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get neighborhood/city data - supports both manual pages and auto-generated URLs
$neighborhood_slug = '';
$neighborhood_name = '';

// First check for auto-generated city page (via rewrite rules)
if (function_exists('bne_get_current_city_slug')) {
    $neighborhood_slug = bne_get_current_city_slug();
    $neighborhood_name = bne_get_current_city_name();
}

// Fallback: check query var
if (empty($neighborhood_slug)) {
    $neighborhood_slug = get_query_var('neighborhood', '');
}

// Fallback: get from page slug (for manually created pages)
if (empty($neighborhood_slug)) {
    global $post;
    if ($post) {
        $neighborhood_slug = $post->post_name;
    }
}

// Sanitize the slug
$neighborhood_slug = sanitize_title($neighborhood_slug);

// Get display name if not already set
if (empty($neighborhood_name)) {
    // Check if city exists in database for proper casing
    if (function_exists('bne_city_exists')) {
        $db_name = bne_city_exists($neighborhood_slug);
        if ($db_name) {
            $neighborhood_name = $db_name;
        }
    }
    // Fallback: convert slug to display name
    if (empty($neighborhood_name)) {
        $neighborhood_name = ucwords(str_replace('-', ' ', $neighborhood_slug));
    }
}

// Determine listing type - check rewrite var first, then GET param
$default_listing_type = 'sale';
if (get_query_var('bne_listing_type')) {
    $default_listing_type = sanitize_text_field(get_query_var('bne_listing_type'));
}

// Get filters from URL parameters
$filters = array(
    'listing_type' => isset($_GET['type']) ? sanitize_text_field($_GET['type']) : $default_listing_type,
    'property_type' => isset($_GET['property_type']) ? sanitize_text_field($_GET['property_type']) : '',
    'property_sub_type' => isset($_GET['sub_type']) ? sanitize_text_field($_GET['sub_type']) : '',
    'min_beds' => isset($_GET['beds']) ? intval($_GET['beds']) : '',
    'min_baths' => isset($_GET['baths']) ? intval($_GET['baths']) : '',
    'min_price' => isset($_GET['min_price']) ? intval($_GET['min_price']) : '',
    'max_price' => isset($_GET['max_price']) ? intval($_GET['max_price']) : '',
    'features' => isset($_GET['features']) ? array_map('sanitize_text_field', (array)$_GET['features']) : array(),
    'sort' => isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : '',
    'page' => isset($_GET['pg']) ? intval($_GET['pg']) : 1,
    'per_page' => 12,
);

// Determine location type (city or neighborhood) from auto-generated page data
global $bne_current_city;
$location_type = 'city';
if (!empty($bne_current_city['type'])) {
    $location_type = $bne_current_city['type'];
}

// Get neighborhood data with filters applied
$neighborhood_data = bne_get_neighborhood_data_filtered($neighborhood_name, $filters, $location_type);

// Set up SEO if data available
if (!empty($neighborhood_data) && class_exists('BNE_Landing_Page_SEO')) {
    BNE_Landing_Page_SEO::set_page_data($neighborhood_data, 'neighborhood');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-neighborhood-page" role="main">

    <?php
    // Hero section with neighborhood image and search
    get_template_part('template-parts/landing-pages/section', 'hero', array(
        'data' => $neighborhood_data,
        'type' => 'neighborhood',
    ));
    ?>

    <?php
    // Filters section with dynamic options
    get_template_part('template-parts/landing-pages/section', 'filters', array(
        'data' => $neighborhood_data,
        'type' => 'neighborhood',
    ));
    ?>

    <?php
    // Market stats section
    if (!empty($neighborhood_data['listing_count']) && $neighborhood_data['listing_count'] > 0) {
        get_template_part('template-parts/landing-pages/section', 'stats', array(
            'data' => $neighborhood_data,
            'type' => 'neighborhood',
        ));
    }
    ?>

    <?php
    // Listings grid
    get_template_part('template-parts/landing-pages/section', 'listings-grid', array(
        'data' => $neighborhood_data,
        'type' => 'neighborhood',
    ));
    ?>

    <?php
    // Nearby neighborhoods
    get_template_part('template-parts/landing-pages/section', 'nearby', array(
        'data' => $neighborhood_data,
        'type' => 'neighborhood',
    ));
    ?>

    <?php
    // FAQ section with Schema.org markup
    if (!empty($neighborhood_data['name'])) {
        get_template_part('template-parts/landing-pages/section', 'faq', array(
            'data' => $neighborhood_data,
            'type' => 'neighborhood',
        ));
    }
    ?>

    <?php
    // CTA section
    get_template_part('template-parts/landing-pages/section', 'cta', array(
        'data' => $neighborhood_data,
        'type' => 'neighborhood',
    ));
    ?>

</main>

<?php get_footer(); ?>
