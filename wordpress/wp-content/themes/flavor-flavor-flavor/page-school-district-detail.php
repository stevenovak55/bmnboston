<?php
/**
 * Template Name: School District Detail Page
 *
 * Comprehensive detail page for a school district
 * Shows all schools, rankings, metrics, and property listings
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get district slug from URL
$district_slug = sanitize_title(get_query_var('bmn_district_slug'));

if (empty($district_slug)) {
    // No slug provided - this shouldn't happen if routing is correct
    wp_redirect(home_url('/schools/'));
    exit;
}

// Get district data
$district_data = bmn_get_district_detail_data($district_slug);

if (!$district_data) {
    // District not found - handled by routing, but just in case
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part('404');
    exit;
}

// Set up SEO
if (class_exists('BNE_Landing_Page_SEO')) {
    BNE_Landing_Page_SEO::set_page_data($district_data, 'school_district_detail');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-district-detail-page" role="main">

    <?php
    // Hero section with district name, grade, rank
    get_template_part('template-parts/schools/section', 'district-hero', array(
        'data' => $district_data,
    ));
    ?>

    <?php
    // Performance metrics (composite breakdown, MCAS, etc.)
    get_template_part('template-parts/schools/section', 'district-metrics', array(
        'data' => $district_data,
    ));
    ?>

    <?php
    // Schools list grouped by level
    get_template_part('template-parts/schools/section', 'district-schools', array(
        'data' => $district_data,
    ));
    ?>

    <?php
    // District boundary map
    if (!empty($district_data['boundary_geojson'])) {
        get_template_part('template-parts/schools/section', 'district-map', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // Property listings in district (always show - has CTA even without listings)
    get_template_part('template-parts/schools/section', 'district-listings', array(
        'data' => $district_data,
    ));
    ?>

    <?php
    // Financial data (per-pupil spending)
    if (!empty($district_data['expenditure_per_pupil'])) {
        get_template_part('template-parts/schools/section', 'district-financial', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // College outcomes
    if (!empty($district_data['college_outcomes'])) {
        get_template_part('template-parts/schools/section', 'district-outcomes', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // Safety/Discipline data
    if (!empty($district_data['discipline'])) {
        get_template_part('template-parts/schools/section', 'district-safety', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // Nearby districts
    if (!empty($district_data['nearby']) && count($district_data['nearby']) > 0) {
        get_template_part('template-parts/schools/section', 'nearby-districts', array(
            'data' => $district_data,
        ));
    }
    ?>

    <?php
    // FAQ section
    get_template_part('template-parts/schools/section', 'district-faq', array(
        'data' => $district_data,
    ));
    ?>

</main>

<?php get_footer(); ?>
