<?php
/**
 * School Listings CTA Section
 *
 * Prominent call-to-action to view homes for sale near the school.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$school_name = $data['name'] ?? 'this school';
$city = $data['city'] ?? '';
$level = $data['level'] ?? 'school';
$letter_grade = $data['letter_grade'] ?? '';
$district = $data['district'] ?? array();
$latitude = $data['latitude'] ?? null;
$longitude = $data['longitude'] ?? null;

// Skip if no city
if (empty($city)) {
    return;
}

// Build search URL for this city (hash-based format for search page)
$city_formatted = ucwords(strtolower($city));
$search_url = home_url('/search/#City=' . rawurlencode($city_formatted) . '&PropertyType=Residential&status=Active');

// Get listing count for this city (cached)
$listing_count = 0;
$transient_key = 'bmn_city_listings_' . sanitize_title($city);
$cached_count = get_transient($transient_key);

if ($cached_count !== false) {
    $listing_count = $cached_count;
} else {
    global $wpdb;
    $listing_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary
         WHERE city = %s AND standard_status = 'Active'",
        strtoupper($city)
    ));
    set_transient($transient_key, $listing_count, HOUR_IN_SECONDS);
}

// Level-specific messaging
$level_text = '';
switch (strtolower($level)) {
    case 'elementary':
        $level_text = 'elementary school';
        break;
    case 'middle':
        $level_text = 'middle school';
        break;
    case 'high':
        $level_text = 'high school';
        break;
    default:
        $level_text = 'school';
}

// Grade badge color class
$grade_class = function_exists('bmn_get_grade_class') ? bmn_get_grade_class($letter_grade) : '';
?>

<section class="bne-section bne-school-listings-cta">
    <div class="bne-container">
        <div class="bne-listings-cta__content">
            <div class="bne-listings-cta__text">
                <?php if ($letter_grade && $letter_grade !== 'N/A') : ?>
                    <span class="bne-listings-cta__badge <?php echo esc_attr($grade_class); ?>">
                        <?php echo esc_html($letter_grade); ?>-Rated School
                    </span>
                <?php endif; ?>

                <h2 class="bne-listings-cta__title">
                    Find Homes Near <?php echo esc_html($school_name); ?>
                </h2>

                <p class="bne-listings-cta__description">
                    <?php if ($listing_count > 0) : ?>
                        Browse <strong><?php echo number_format($listing_count); ?> homes for sale</strong> in <?php echo esc_html($city); ?>
                        that are zoned for this <?php echo esc_html($letter_grade ? $letter_grade . '-rated ' : ''); ?><?php echo esc_html($level_text); ?>.
                    <?php else : ?>
                        Explore available homes in <?php echo esc_html($city); ?> that are zoned for <?php echo esc_html($school_name); ?>.
                    <?php endif; ?>
                </p>

                <div class="bne-listings-cta__actions">
                    <a href="<?php echo esc_url($search_url); ?>" class="bne-btn bne-btn--primary bne-btn--large">
                        View Homes in <?php echo esc_html($city); ?>
                        <?php if ($listing_count > 0) : ?>
                            <span class="bne-btn__count"><?php echo number_format($listing_count); ?></span>
                        <?php endif; ?>
                    </a>

                    <?php if (!empty($district['url'])) : ?>
                        <a href="<?php echo esc_url($district['url']); ?>" class="bne-btn bne-btn--outline">
                            View All <?php echo esc_html($district['name'] ?? 'District'); ?> Schools
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bne-listings-cta__visual">
                <div class="bne-listings-cta__icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="bne-listings-cta__stats">
                    <?php if ($listing_count > 0) : ?>
                        <span class="bne-listings-cta__stat-number"><?php echo number_format($listing_count); ?></span>
                        <span class="bne-listings-cta__stat-label">Homes Available</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
        // Show a mini inline CTA about scheduling a showing
        ?>
        <div class="bne-listings-cta__secondary">
            <p>
                Ready to tour homes in <?php echo esc_html($city); ?>?
                <a href="<?php echo esc_url(home_url('/contact/')); ?>">Schedule a showing</a> with our team.
            </p>
        </div>
    </div>
</section>
