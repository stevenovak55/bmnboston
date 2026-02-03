<?php
/**
 * Template Name: School District Landing Page
 *
 * Dynamic landing page for school district areas
 * Displays homes near schools with district info
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get school district data from URL or query var
$district_slug = get_query_var('school_district', '');
if (empty($district_slug)) {
    // Try to get from page slug
    global $post;
    if ($post) {
        $district_slug = $post->post_name;
    }
}

// Sanitize the slug
$district_slug = sanitize_title($district_slug);

// Convert slug to display name
$district_name = ucwords(str_replace('-', ' ', $district_slug));

// Get school district data from database
$district_data = bne_get_school_district_data($district_name);

// Set up SEO if data available
if (!empty($district_data) && class_exists('BNE_Landing_Page_SEO')) {
    BNE_Landing_Page_SEO::set_page_data($district_data, 'school_district');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-school-district-page" role="main">

    <?php
    // Hero section
    get_template_part('template-parts/landing-pages/section', 'hero', array(
        'data' => $district_data,
        'type' => 'school_district',
    ));
    ?>

    <?php
    // School info section (school district specific)
    if (!empty($district_data['schools'])) {
        get_template_part('template-parts/landing-pages/section', 'school-info', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // Market stats section
    if (!empty($district_data['listing_count']) && $district_data['listing_count'] > 0) {
        get_template_part('template-parts/landing-pages/section', 'stats', array(
            'data' => $district_data,
            'type' => 'school_district',
        ));
    }
    ?>

    <?php
    // Listings grid
    get_template_part('template-parts/landing-pages/section', 'listings-grid', array(
        'data' => $district_data,
        'type' => 'school_district',
    ));
    ?>

    <?php
    // Nearby districts
    get_template_part('template-parts/landing-pages/section', 'nearby', array(
        'data' => $district_data,
        'type' => 'school_district',
    ));
    ?>

    <?php
    // FAQ section
    if (!empty($district_data['name'])) {
        get_template_part('template-parts/landing-pages/section', 'faq', array(
            'data' => $district_data,
            'type' => 'school_district',
        ));
    }
    ?>

    <?php
    // CTA section
    get_template_part('template-parts/landing-pages/section', 'cta', array(
        'data' => $district_data,
        'type' => 'school_district',
    ));
    ?>

</main>

<?php get_footer(); ?>
