<?php
/**
 * Homepage Neighborhood Analytics Section
 *
 * Displays neighborhood analytics cards with market statistics
 * pulled from the MLS Listings Display plugin.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get customizer settings
$section_title = get_theme_mod('bne_analytics_title', 'Explore Boston Neighborhoods');
$section_subtitle = get_theme_mod('bne_analytics_subtitle', 'Discover market insights and find your perfect neighborhood');

// Get analytics data from helper (uses real MLS data with correct URLs)
$neighborhoods = BNE_MLS_Helpers::get_neighborhoods_analytics();

// Skip section if no neighborhoods configured
if (empty($neighborhoods)) {
    return;
}

/**
 * Format price for display in millions
 */
function bne_format_analytics_price($price) {
    if ($price <= 0) {
        return 'N/A';
    }
    if ($price >= 1000000) {
        $millions = $price / 1000000;
        // Round to 1 decimal place, standard format: $3.6M
        return '$' . number_format($millions, 1) . 'M';
    }
    return '$' . number_format($price / 1000) . 'K';
}
?>

<section class="bne-analytics bne-section" id="neighborhood-analytics">
    <div class="bne-container">
        <!-- Section Header -->
        <header class="bne-section-header bne-animate">
            <h2 class="bne-section-title"><?php echo esc_html($section_title); ?></h2>
            <?php if ($section_subtitle) : ?>
                <p class="bne-section-subtitle"><?php echo esc_html($section_subtitle); ?></p>
            <?php endif; ?>
        </header>

        <!-- Neighborhood Cards Grid -->
        <div class="bne-analytics__grid">
            <?php foreach ($neighborhoods as $index => $neighborhood) : ?>
                <article class="bne-analytics__card bne-animate" tabindex="0">
                    <!-- Card Image (from most expensive listing, with gradient fallback) -->
                    <div class="bne-analytics__card-image <?php echo empty($neighborhood['image']) ? 'bne-analytics__card-image--gradient-' . esc_attr(($index % 6) + 1) : ''; ?>">
                        <?php if (!empty($neighborhood['image'])) : ?>
                            <img
                                src="<?php echo esc_url($neighborhood['image']); ?>"
                                alt="<?php echo esc_attr($neighborhood['name']); ?> neighborhood"
                                loading="lazy"
                            >
                        <?php endif; ?>
                        <div class="bne-analytics__card-overlay"></div>
                    </div>

                    <!-- Card Content -->
                    <div class="bne-analytics__card-content">
                        <h3 class="bne-analytics__card-title"><?php echo esc_html($neighborhood['name']); ?></h3>

                        <!-- Stats Grid -->
                        <div class="bne-analytics__stats">
                            <div class="bne-analytics__stat">
                                <span class="bne-analytics__stat-value"><?php echo esc_html($neighborhood['active_listings']); ?></span>
                                <span class="bne-analytics__stat-label"><?php esc_html_e('Active Listings', 'flavor-flavor-flavor'); ?></span>
                            </div>
                            <div class="bne-analytics__stat">
                                <span class="bne-analytics__stat-value"><?php echo bne_format_analytics_price($neighborhood['median_price']); ?></span>
                                <span class="bne-analytics__stat-label"><?php esc_html_e('Median Price', 'flavor-flavor-flavor'); ?></span>
                            </div>
                            <div class="bne-analytics__stat">
                                <span class="bne-analytics__stat-value"><?php echo $neighborhood['avg_dom'] > 0 ? esc_html($neighborhood['avg_dom']) : 'N/A'; ?></span>
                                <span class="bne-analytics__stat-label"><?php esc_html_e('Avg Days on Market', 'flavor-flavor-flavor'); ?></span>
                            </div>
                            <?php if ($neighborhood['price_change'] != 0) : ?>
                            <div class="bne-analytics__stat bne-analytics__stat--trend">
                                <span class="bne-analytics__stat-value <?php echo $neighborhood['price_change'] >= 0 ? 'is-positive' : 'is-negative'; ?>">
                                    <?php echo $neighborhood['price_change'] >= 0 ? '+' : ''; ?><?php echo esc_html($neighborhood['price_change']); ?>%
                                </span>
                                <span class="bne-analytics__stat-label"><?php esc_html_e('YoY Change', 'flavor-flavor-flavor'); ?></span>
                            </div>
                            <?php else : ?>
                            <div class="bne-analytics__stat">
                                <span class="bne-analytics__stat-value">&mdash;</span>
                                <span class="bne-analytics__stat-label"><?php esc_html_e('YoY Change', 'flavor-flavor-flavor'); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- CTA Link (uses correct hash-based URL format) -->
                        <a href="<?php echo esc_url($neighborhood['url']); ?>" class="bne-analytics__card-link">
                            <?php esc_html_e('View Listings', 'flavor-flavor-flavor'); ?>
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                                <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                            </svg>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- View All Link -->
        <div class="bne-analytics__footer bne-animate">
            <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--outline">
                <?php esc_html_e('Explore All Neighborhoods', 'flavor-flavor-flavor'); ?>
            </a>
        </div>
    </div>
</section>
