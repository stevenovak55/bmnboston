<?php
/**
 * District Listings Section
 *
 * Shows property listings in the district with prominent CTAs.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$listings = $data['listings'] ?? array();
$district_name = $data['name'] ?? 'This District';
$letter_grade = $data['letter_grade'] ?? '';
$listing_count = $data['listing_count'] ?? count($listings);
$grade_class = function_exists('bmn_get_grade_class') ? bmn_get_grade_class($letter_grade) : '';

// Build search URL early for use in multiple places (hash-based format for search page)
$cities = $data['cities_served'] ?? array($data['city'] ?? '');
// Format cities for URL - title case each city
$cities_formatted = array_map(function($c) {
    return ucwords(strtolower(trim($c)));
}, array_filter($cities));
$search_url = home_url('/search/#City=' . rawurlencode(implode(',', $cities_formatted)) . '&PropertyType=Residential&status=Active');

// If no listings, show a CTA-only section
if (empty($listings)) :
?>
<section class="bne-section bne-district-listings bne-district-listings--no-listings">
    <div class="bne-container">
        <div class="bne-district-listings__cta-box">
            <div class="bne-district-listings__cta-content">
                <?php if ($letter_grade && $letter_grade !== 'N/A') : ?>
                    <span class="bne-listings-cta__badge <?php echo esc_attr($grade_class); ?>">
                        <?php echo esc_html($letter_grade); ?>-Rated District
                    </span>
                <?php endif; ?>
                <h2>Looking for Homes in <?php echo esc_html($district_name); ?>?</h2>
                <p>Contact us to be the first to know when new listings become available in this highly-rated school district.</p>
                <div class="bne-district-listings__cta-actions">
                    <a href="<?php echo esc_url($search_url); ?>" class="bne-btn bne-btn--primary">
                        Search All Listings
                    </a>
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="bne-btn bne-btn--outline">
                        Contact Us
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php
    return;
endif;
?>

<section class="bne-section bne-district-listings">
    <div class="bne-container">
        <div class="bne-district-listings__header">
            <div class="bne-district-listings__header-main">
                <?php if ($letter_grade && $letter_grade !== 'N/A') : ?>
                    <span class="bne-district-listings__grade-tag <?php echo esc_attr($grade_class); ?>">
                        <?php echo esc_html($letter_grade); ?>-Rated District
                    </span>
                <?php endif; ?>
                <h2 class="bne-section-title">Homes for Sale in <?php echo esc_html($district_name); ?></h2>
                <p class="bne-section-subtitle">
                    <strong><?php echo number_format($listing_count); ?> properties</strong> available in this school district
                    <?php if (!empty($data['median_price'])) : ?>
                        &bull; Median price: <?php echo bmn_format_currency($data['median_price']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="bne-district-listings__header-action">
                <a href="<?php echo esc_url($search_url); ?>" class="bne-btn bne-btn--primary">
                    View All <?php echo number_format($listing_count); ?> Homes
                </a>
            </div>
        </div>

        <div class="bne-district-listings__grid">
            <?php foreach (array_slice($listings, 0, 6) as $listing) : ?>
                <article class="bne-listing-card">
                    <?php if (!empty($listing->main_photo_url)) :
                        $address = trim($listing->street_number . ' ' . $listing->street_name);
                        $city = $listing->city ?? '';
                        $price = bmn_format_currency($listing->list_price);
                        $alt_text = sprintf('Home for sale at %s, %s - %s', $address, $city, $price);
                    ?>
                        <div class="bne-listing-card__image">
                            <img src="<?php echo esc_url($listing->main_photo_url); ?>"
                                 alt="<?php echo esc_attr($alt_text); ?>"
                                 loading="lazy">
                        </div>
                    <?php endif; ?>

                    <div class="bne-listing-card__content">
                        <div class="bne-listing-card__price">
                            <?php echo bmn_format_currency($listing->list_price); ?>
                        </div>
                        <h3 class="bne-listing-card__address">
                            <?php echo esc_html($listing->street_number . ' ' . $listing->street_name); ?>
                        </h3>
                        <p class="bne-listing-card__city">
                            <?php echo esc_html($listing->city); ?>, MA
                        </p>
                        <div class="bne-listing-card__details">
                            <?php if (!empty($listing->bedrooms_total)) : ?>
                                <span><?php echo $listing->bedrooms_total; ?> bed</span>
                            <?php endif; ?>
                            <?php if (!empty($listing->bathrooms_total)) : ?>
                                <span><?php echo $listing->bathrooms_total; ?> bath</span>
                            <?php endif; ?>
                            <?php if (!empty($listing->building_area_total)) : ?>
                                <span><?php echo number_format($listing->building_area_total); ?> sqft</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <a href="<?php echo esc_url(home_url('/property/' . $listing->listing_key . '/')); ?>"
                       class="bne-listing-card__link">
                        View Property
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="bne-district-listings__cta-box">
            <div class="bne-district-listings__cta-content">
                <h3>Find Your Perfect Home in <?php echo esc_html($district_name); ?></h3>
                <p>
                    Get access to <?php echo number_format($listing_count); ?> homes for sale in this
                    <?php echo esc_html($letter_grade ? $letter_grade . '-rated ' : ''); ?>school district.
                    Our team can help you find the right home for your family.
                </p>
                <div class="bne-district-listings__cta-actions">
                    <a href="<?php echo esc_url($search_url); ?>" class="bne-btn bne-btn--primary bne-btn--large">
                        View All <?php echo number_format($listing_count); ?> Homes
                    </a>
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="bne-btn bne-btn--outline">
                        Schedule a Showing
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
