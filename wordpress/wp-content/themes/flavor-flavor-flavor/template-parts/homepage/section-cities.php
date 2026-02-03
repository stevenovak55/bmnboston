<?php
/**
 * Homepage Featured Cities Section
 *
 * Displays featured cities with live listing counts and cover images.
 * v1.1.0: Redesigned to use <img> tags with object-fit for better image sizing.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get cities with live counts and images
$cities = BNE_MLS_Helpers::get_featured_cities();
?>

<section class="bne-section bne-cities">
    <div class="bne-container">
        <h2 class="bne-section-title">Featured Cities</h2>
        <p class="bne-section-subtitle">Explore properties across Greater Boston</p>

        <div class="bne-cities__grid">
            <?php foreach ($cities as $city) : ?>
                <a href="<?php echo esc_url($city['url']); ?>" class="bne-city-card">
                    <div class="bne-city-card__image-wrapper">
                        <?php if (!empty($city['image'])) : ?>
                            <img src="<?php echo esc_url($city['image']); ?>"
                                 alt="<?php echo esc_attr($city['name']); ?>"
                                 class="bne-city-card__image"
                                 loading="lazy">
                        <?php endif; ?>
                        <div class="bne-city-card__overlay"></div>
                    </div>
                    <div class="bne-city-card__content">
                        <h3 class="bne-city-card__name"><?php echo esc_html($city['name']); ?></h3>
                        <span class="bne-city-card__count">
                            <?php echo esc_html(number_format($city['count'])); ?> listings
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--outline">
                View All Cities
            </a>
        </div>
    </div>
</section>
