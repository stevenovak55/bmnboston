<?php
/**
 * Landing Page Stats Section
 *
 * Displays market statistics for the location with dynamic data
 * Includes GEO-optimized prose summaries for AI discoverability
 *
 * @package flavor_flavor_flavor
 * @version 1.3.9
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? '';
$listing_count = $data['listing_count'] ?? 0;
$avg_price = $data['avg_price'] ?? 0;
$avg_dom = $data['avg_dom'] ?? 0;
$price_min = $data['price_range_min'] ?? 0;
$price_max = $data['price_range_max'] ?? 0;
$avg_sqft = $data['avg_sqft'] ?? 0;
$avg_price_per_sqft = $data['avg_price_per_sqft'] ?? 0;
$listing_type = $data['active_filters']['listing_type'] ?? 'sale';
?>

<section class="bne-landing-stats">
    <div class="bne-landing-container">
        <h2 class="bne-landing-section-title">
            <?php echo esc_html($name); ?> Market Overview
        </h2>
        <p class="bne-landing-section-subtitle">
            Real-time market statistics for <?php echo $listing_type === 'lease' ? 'rentals' : 'homes for sale'; ?> in <?php echo esc_html($name); ?>
            <span class="bne-landing-stats__freshness">
                Data as of <?php echo esc_html($data['freshness_display'] ?? wp_date('M j, Y')); ?>
            </span>
        </p>

        <?php
        // Generate natural language market summary for GEO optimization
        $prose_summary = function_exists('bne_generate_market_prose') ? bne_generate_market_prose($data) : '';
        if (!empty($prose_summary)) :
        ?>
        <div class="bne-landing-stats__prose-summary">
            <?php echo esc_html($prose_summary); ?>
        </div>
        <?php endif; ?>

        <div class="bne-landing-stats__grid">
            <!-- Active Listings -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--blue">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value">
                    <?php echo number_format($listing_count); ?>
                </div>
                <div class="bne-landing-stat-card__label">
                    Active <?php echo $listing_type === 'lease' ? 'Rentals' : 'Listings'; ?>
                </div>
            </div>

            <!-- Average/Median Price -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--green">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value">
                    $<?php echo number_format($avg_price); ?>
                    <?php if ($listing_type === 'lease') : ?><small>/mo</small><?php endif; ?>
                </div>
                <div class="bne-landing-stat-card__label">Average Price</div>
            </div>

            <!-- Days on Market -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--orange">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value">
                    <?php echo number_format($avg_dom); ?>
                    <small>days</small>
                </div>
                <div class="bne-landing-stat-card__label">Avg. Days on Market</div>
            </div>

            <!-- Price Range -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--purple">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                        <polyline points="16 7 22 7 22 13"></polyline>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value bne-landing-stat-card__value--small">
                    $<?php echo bne_format_price_short($price_min); ?> - $<?php echo bne_format_price_short($price_max); ?>
                </div>
                <div class="bne-landing-stat-card__label">Price Range</div>
            </div>

            <?php if ($avg_sqft > 0 && $listing_type === 'sale') : ?>
            <!-- Average Square Feet -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--teal">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="3" y1="9" x2="21" y2="9"></line>
                        <line x1="9" y1="21" x2="9" y2="9"></line>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value">
                    <?php echo number_format($avg_sqft); ?>
                    <small>sqft</small>
                </div>
                <div class="bne-landing-stat-card__label">Avg. Living Area</div>
            </div>
            <?php endif; ?>

            <?php if ($avg_price_per_sqft > 0 && $listing_type === 'sale') : ?>
            <!-- Price Per Sqft -->
            <div class="bne-landing-stat-card">
                <div class="bne-landing-stat-card__icon bne-landing-stat-card__icon--pink">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    </svg>
                </div>
                <div class="bne-landing-stat-card__value">
                    $<?php echo number_format($avg_price_per_sqft); ?>
                    <small>/sqft</small>
                </div>
                <div class="bne-landing-stat-card__label">Avg. Price/Sqft</div>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Show property type breakdown if we have filter options
        $filter_options = $data['filter_options'] ?? array();
        if (!empty($filter_options['property_sub_types'])) :
            $top_types = array_slice($filter_options['property_sub_types'], 0, 5);
        ?>
        <div class="bne-landing-stats__breakdown">
            <h3 class="bne-landing-stats__breakdown-title">Property Types in <?php echo esc_html($name); ?></h3>
            <div class="bne-landing-stats__breakdown-bars">
                <?php
                $total = array_sum(array_column($top_types, 'count'));
                foreach ($top_types as $ptype) :
                    $percentage = $total > 0 ? ($ptype['count'] / $total * 100) : 0;
                ?>
                    <div class="bne-landing-stats__bar-item">
                        <div class="bne-landing-stats__bar-header">
                            <span class="bne-landing-stats__bar-label"><?php echo esc_html($ptype['label']); ?></span>
                            <span class="bne-landing-stats__bar-count"><?php echo number_format($ptype['count']); ?></span>
                        </div>
                        <div class="bne-landing-stats__bar-track">
                            <div class="bne-landing-stats__bar-fill" style="width: <?php echo esc_attr($percentage); ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
