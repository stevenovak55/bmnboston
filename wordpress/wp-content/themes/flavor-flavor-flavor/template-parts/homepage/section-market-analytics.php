<?php
/**
 * Homepage Market Analytics Section
 *
 * Displays detailed city-based market analytics using MLD shortcodes.
 * Features market summaries, heat indices, feature premiums, and price analysis.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get customizer settings
$section_title = get_theme_mod('bne_market_analytics_title', 'City Market Insights');
$section_subtitle = get_theme_mod('bne_market_analytics_subtitle', 'Detailed market intelligence for your target areas');
$show_section = get_theme_mod('bne_show_market_analytics', true);

// Skip if section is disabled
if (!$show_section) {
    return;
}

// Check if MLD shortcodes are available
if (!shortcode_exists('mld_market_summary')) {
    return;
}

// Get city configuration
$cities_string = get_theme_mod('bne_market_analytics_cities', 'Reading, Wakefield, Stoneham, Melrose, Winchester');
$state = get_theme_mod('bne_market_analytics_state', 'MA');
$months = get_theme_mod('bne_market_analytics_months', '12');

// Parse cities
$cities = array_map('trim', explode(',', $cities_string));
$cities = array_filter($cities); // Remove empty values

if (empty($cities)) {
    return;
}

// Get feature toggles
$show_heat = get_theme_mod('bne_show_market_heat', true);
$show_premiums = get_theme_mod('bne_show_feature_premiums', true);
$show_bedrooms = get_theme_mod('bne_show_price_bedrooms', true);
$show_agents = get_theme_mod('bne_show_top_agents', false);

// Limit to 5 cities for performance
$cities = array_slice($cities, 0, 5);
$first_city = $cities[0];
?>

<section class="bne-market-analytics bne-section" id="market-analytics">
    <div class="bne-container">
        <!-- Section Header -->
        <header class="bne-section-header bne-animate">
            <h2 class="bne-section-title"><?php echo esc_html($section_title); ?></h2>
            <?php if ($section_subtitle) : ?>
                <p class="bne-section-subtitle"><?php echo esc_html($section_subtitle); ?></p>
            <?php endif; ?>
        </header>

        <!-- City Selector Tabs -->
        <div class="bne-market-analytics__tabs-wrapper bne-animate">
            <div class="bne-market-analytics__tabs" role="tablist" aria-label="Select city for market analytics">
                <?php foreach ($cities as $index => $city) :
                    $is_active = ($index === 0);
                    $city_slug = sanitize_title($city);
                ?>
                <button
                    class="bne-market-analytics__tab<?php echo $is_active ? ' is-active' : ''; ?>"
                    role="tab"
                    aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                    aria-controls="city-panel-<?php echo esc_attr($city_slug); ?>"
                    data-city="<?php echo esc_attr($city); ?>"
                    data-city-slug="<?php echo esc_attr($city_slug); ?>"
                >
                    <?php echo esc_html($city); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- City Panels -->
        <div class="bne-market-analytics__panels">
            <?php foreach ($cities as $index => $city) :
                $is_active = ($index === 0);
                $city_slug = sanitize_title($city);
            ?>
            <div
                id="city-panel-<?php echo esc_attr($city_slug); ?>"
                class="bne-market-analytics__panel<?php echo $is_active ? ' is-active' : ''; ?>"
                role="tabpanel"
                <?php echo !$is_active ? 'hidden' : ''; ?>
            >
                <!-- Market Summary Card -->
                <div class="bne-market-analytics__summary bne-animate">
                    <?php echo do_shortcode('[mld_market_summary city="' . esc_attr($city) . '" state="' . esc_attr($state) . '" months="' . esc_attr($months) . '"]'); ?>
                </div>

                <!-- Detailed Analytics Grid -->
                <div class="bne-market-analytics__grid bne-animate">
                    <?php if ($show_heat) : ?>
                    <!-- Market Heat Index -->
                    <div class="bne-market-analytics__card bne-glass">
                        <h3 class="bne-market-analytics__card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" width="20" height="20">
                                <path d="M12 2v8l4 4"/>
                                <circle cx="12" cy="14" r="8"/>
                            </svg>
                            Market Heat Index
                        </h3>
                        <div class="bne-market-analytics__card-content">
                            <?php echo do_shortcode('[mld_market_heat city="' . esc_attr($city) . '" state="' . esc_attr($state) . '" style="detailed"]'); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($show_bedrooms) : ?>
                    <!-- Price by Bedrooms -->
                    <div class="bne-market-analytics__card bne-glass">
                        <h3 class="bne-market-analytics__card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" width="20" height="20">
                                <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                            Price by Bedrooms
                        </h3>
                        <div class="bne-market-analytics__card-content">
                            <?php echo do_shortcode('[mld_price_by_bedrooms city="' . esc_attr($city) . '" state="' . esc_attr($state) . '" months="' . esc_attr($months) . '"]'); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($show_premiums) : ?>
                    <!-- Feature Premiums -->
                    <div class="bne-market-analytics__card bne-glass">
                        <h3 class="bne-market-analytics__card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" width="20" height="20">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                            Feature Value Premiums
                        </h3>
                        <div class="bne-market-analytics__card-content">
                            <?php echo do_shortcode('[mld_feature_premiums city="' . esc_attr($city) . '" state="' . esc_attr($state) . '" months="24"]'); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($show_agents) : ?>
                    <!-- Top Agents -->
                    <div class="bne-market-analytics__card bne-glass">
                        <h3 class="bne-market-analytics__card-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" stroke-width="2" width="20" height="20">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            Top Agents
                        </h3>
                        <div class="bne-market-analytics__card-content">
                            <?php echo do_shortcode('[mld_top_agents city="' . esc_attr($city) . '" state="' . esc_attr($state) . '" limit="5" months="' . esc_attr($months) . '"]'); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- CTA -->
        <div class="bne-market-analytics__footer bne-animate">
            <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--primary">
                <?php esc_html_e('Search All Properties', 'flavor-flavor-flavor'); ?>
                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                </svg>
            </a>
        </div>
    </div>
</section>

<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.bne-market-analytics__tab');
        const panels = document.querySelectorAll('.bne-market-analytics__panel');

        if (!tabs.length) return;

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const citySlug = this.dataset.citySlug;

                // Update tab states
                tabs.forEach(t => {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('is-active');
                this.setAttribute('aria-selected', 'true');

                // Update panel states
                panels.forEach(panel => {
                    panel.classList.remove('is-active');
                    panel.hidden = true;
                });

                const targetPanel = document.getElementById('city-panel-' + citySlug);
                if (targetPanel) {
                    targetPanel.classList.add('is-active');
                    targetPanel.hidden = false;
                }
            });

            // Keyboard navigation
            tab.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    const tabList = Array.from(tabs);
                    const currentIndex = tabList.indexOf(this);
                    const direction = e.key === 'ArrowRight' ? 1 : -1;
                    const newIndex = (currentIndex + direction + tabList.length) % tabList.length;
                    tabList[newIndex].focus();
                    tabList[newIndex].click();
                }
            });
        });
    });
})();
</script>
