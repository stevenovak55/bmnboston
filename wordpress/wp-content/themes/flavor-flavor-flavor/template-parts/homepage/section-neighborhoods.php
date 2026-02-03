<?php
/**
 * Homepage Featured Neighborhoods Section
 *
 * Displays a grid of neighborhoods with live listing counts and cover images.
 * v1.1.0: Redesigned to use <img> tags with object-fit for better image sizing.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get neighborhoods with live counts and images
$neighborhoods = BNE_MLS_Helpers::get_featured_neighborhoods();
?>

<section class="bne-section bne-section--beige bne-neighborhoods">
    <div class="bne-container">
        <h2 class="bne-section-title">Featured Neighborhoods</h2>
        <p class="bne-section-subtitle">Explore properties in Boston's most desirable areas</p>

        <div class="bne-neighborhoods__grid">
            <?php foreach ($neighborhoods as $index => $neighborhood) : ?>
                <a href="<?php echo esc_url($neighborhood['url']); ?>"
                   class="bne-neighborhood-card <?php echo $index === 0 ? 'bne-neighborhood-card--featured' : ''; ?>">
                    <div class="bne-neighborhood-card__image-wrapper">
                        <?php if (!empty($neighborhood['image'])) : ?>
                            <img src="<?php echo esc_url($neighborhood['image']); ?>"
                                 alt="<?php echo esc_attr($neighborhood['name']); ?>"
                                 class="bne-neighborhood-card__image"
                                 loading="lazy">
                        <?php endif; ?>
                        <div class="bne-neighborhood-card__overlay"></div>
                    </div>
                    <div class="bne-neighborhood-card__content">
                        <h3 class="bne-neighborhood-card__name"><?php echo esc_html($neighborhood['name']); ?></h3>
                        <span class="bne-neighborhood-card__count">
                            <?php echo esc_html(number_format($neighborhood['count'])); ?> listings
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--outline">
                View All Neighborhoods
            </a>
        </div>
    </div>
</section>
