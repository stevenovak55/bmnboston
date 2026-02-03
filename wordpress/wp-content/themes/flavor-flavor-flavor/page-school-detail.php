<?php
/**
 * Template Name: School Detail Page
 *
 * Comprehensive detail page for an individual school
 * Shows MCAS scores, demographics, sports, and nearby properties
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get slugs from URL
$district_slug = sanitize_title(get_query_var('bmn_district_slug'));
$school_slug = sanitize_title(get_query_var('bmn_school_slug'));

if (empty($district_slug) || empty($school_slug)) {
    wp_redirect(home_url('/schools/'));
    exit;
}

// Get school data
$school_data = bmn_get_school_detail_data($district_slug, $school_slug);

if (!$school_data) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part('404');
    exit;
}

// Set up SEO
if (class_exists('BNE_Landing_Page_SEO')) {
    BNE_Landing_Page_SEO::set_page_data($school_data, 'school_detail');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-school-detail-page" role="main">

    <?php
    // Hero section with school name, grade, rank
    get_template_part('template-parts/schools/section', 'school-hero', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // Quick stats bar
    get_template_part('template-parts/schools/section', 'school-stats', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // MCAS scores section
    if (!empty($school_data['mcas_averages'])) {
        get_template_part('template-parts/schools/section', 'school-mcas', array(
            'data' => $school_data,
        ));
    }
    ?>

    <?php
    // Demographics section
    if (!empty($school_data['demographics'])) {
        get_template_part('template-parts/schools/section', 'school-demographics', array(
            'data' => $school_data,
        ));
    }
    ?>

    <?php
    // Sports section (high schools only)
    if (!empty($school_data['sports'])) {
        get_template_part('template-parts/schools/section', 'school-sports', array(
            'data' => $school_data,
        ));
    }
    ?>

    <?php
    // Features section (graduation, attendance, AP, etc.)
    get_template_part('template-parts/schools/section', 'school-features', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // Location map
    if (!empty($school_data['latitude']) && !empty($school_data['longitude'])) {
        get_template_part('template-parts/schools/section', 'school-map', array(
            'data' => $school_data,
        ));
    }
    ?>

    <?php
    // CTA: View homes near this school
    get_template_part('template-parts/schools/section', 'school-listings-cta', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // Back to district link and nearby schools
    get_template_part('template-parts/schools/section', 'school-navigation', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // Related schools in the same district (internal linking for SEO)
    get_template_part('template-parts/schools/section', 'related-schools', array(
        'data' => $school_data,
    ));
    ?>

    <?php
    // FAQ section
    get_template_part('template-parts/schools/section', 'school-faq', array(
        'data' => $school_data,
    ));
    ?>

</main>

<?php
get_footer();
