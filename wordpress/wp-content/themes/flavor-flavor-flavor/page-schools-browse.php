<?php
/**
 * Template Name: Schools Browse Page
 *
 * Browse/search all Massachusetts school districts
 * Supports filtering by grade, city, and score range
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get filters from URL parameters
$filters = array(
    'grade'     => isset($_GET['grade']) ? sanitize_text_field($_GET['grade']) : '',
    'city'      => isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '',
    'min_score' => isset($_GET['min_score']) ? intval($_GET['min_score']) : 0,
    'max_score' => isset($_GET['max_score']) ? intval($_GET['max_score']) : 100,
    'sort'      => isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'rank',
    'page'      => isset($_GET['pg']) ? max(1, intval($_GET['pg'])) : 1,
);

// Validate grade filter
$valid_grades = array('A', 'B', 'C', 'D', 'F', '');
if (!in_array($filters['grade'], $valid_grades)) {
    $filters['grade'] = '';
}

// Validate sort option
$valid_sorts = array('rank', 'name', 'score');
if (!in_array($filters['sort'], $valid_sorts)) {
    $filters['sort'] = 'rank';
}

// Get districts data
$districts_data = bmn_get_districts_browse_data($filters);

// Set up page data for SEO
$seo_data = array(
    'name'           => 'Massachusetts School Districts',
    'title'          => 'Massachusetts School Districts | Rankings & School Data',
    'description'    => 'Browse and compare ' . $districts_data['total'] . ' Massachusetts school districts. Filter by grade, city, and ranking. View school performance data, MCAS scores, and more.',
    'total_districts' => $districts_data['total'],
    'filters'        => $filters,
    'canonical_url'  => home_url('/schools/'),
    'data_freshness' => date('Y-m-d'),
);

// Set up SEO
if (class_exists('BNE_Landing_Page_SEO')) {
    BNE_Landing_Page_SEO::set_page_data($seo_data, 'schools_browse');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-schools-browse-page" role="main">

    <?php
    // Hero section with page title and search
    get_template_part('template-parts/schools/section', 'browse-hero', array(
        'data' => $seo_data,
        'filters' => $filters,
    ));
    ?>

    <?php
    // Filter controls
    get_template_part('template-parts/schools/section', 'districts-filters', array(
        'data' => $districts_data,
        'filters' => $filters,
    ));
    ?>

    <?php
    // Districts grid
    get_template_part('template-parts/schools/section', 'districts-grid', array(
        'data' => $districts_data,
        'filters' => $filters,
    ));
    ?>

</main>

<?php get_footer(); ?>
