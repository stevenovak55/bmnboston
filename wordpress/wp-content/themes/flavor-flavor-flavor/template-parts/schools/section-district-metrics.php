<?php
/**
 * District Metrics Section
 *
 * Displays performance metrics and score breakdown.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

$elementary_avg = $data['elementary_avg'] ?? null;
$middle_avg = $data['middle_avg'] ?? null;
$high_avg = $data['high_avg'] ?? null;
$expenditure = $data['expenditure_per_pupil'] ?? null;
?>

<section class="bne-section bne-district-metrics">
    <div class="bne-container">
        <h2 class="bne-section-title">Performance Overview</h2>

        <div class="bne-district-metrics__grid">
            <!-- Average Scores by Level -->
            <?php if ($elementary_avg || $middle_avg || $high_avg) : ?>
                <div class="bne-metric-card">
                    <h3 class="bne-metric-card__title">School Averages by Level</h3>
                    <div class="bne-metric-card__scores">
                        <?php if ($elementary_avg) : ?>
                            <div class="bne-metric-card__score">
                                <span class="bne-metric-card__score-label">Elementary</span>
                                <span class="bne-metric-card__score-value"><?php echo number_format($elementary_avg, 1); ?></span>
                                <span class="bne-metric-card__score-grade <?php echo esc_attr(bmn_get_grade_class(bmn_get_letter_grade_from_score($elementary_avg, 'elementary'))); ?>">
                                    <?php echo bmn_get_letter_grade_from_score($elementary_avg, 'elementary'); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($middle_avg) : ?>
                            <div class="bne-metric-card__score">
                                <span class="bne-metric-card__score-label">Middle</span>
                                <span class="bne-metric-card__score-value"><?php echo number_format($middle_avg, 1); ?></span>
                                <span class="bne-metric-card__score-grade <?php echo esc_attr(bmn_get_grade_class(bmn_get_letter_grade_from_score($middle_avg, 'middle'))); ?>">
                                    <?php echo bmn_get_letter_grade_from_score($middle_avg, 'middle'); ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($high_avg) : ?>
                            <div class="bne-metric-card__score">
                                <span class="bne-metric-card__score-label">High</span>
                                <span class="bne-metric-card__score-value"><?php echo number_format($high_avg, 1); ?></span>
                                <span class="bne-metric-card__score-grade <?php echo esc_attr(bmn_get_grade_class(bmn_get_letter_grade_from_score($high_avg, 'high'))); ?>">
                                    <?php echo bmn_get_letter_grade_from_score($high_avg, 'high'); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Per-Pupil Spending -->
            <?php if ($expenditure) : ?>
                <div class="bne-metric-card">
                    <h3 class="bne-metric-card__title">Per-Pupil Spending</h3>
                    <div class="bne-metric-card__value-large">
                        <?php echo bmn_format_currency($expenditure); ?>
                    </div>
                    <p class="bne-metric-card__description">
                        Annual expenditure per student (<?php echo $data['ranking_year'] ?? date('Y'); ?>)
                    </p>
                </div>
            <?php endif; ?>

            <!-- Cities Served -->
            <?php if (!empty($data['cities_served']) && count($data['cities_served']) > 1) : ?>
                <div class="bne-metric-card">
                    <h3 class="bne-metric-card__title">Cities & Towns Served</h3>
                    <div class="bne-metric-card__cities">
                        <?php foreach ($data['cities_served'] as $city) : ?>
                            <span class="bne-metric-card__city"><?php echo esc_html($city); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Facts -->
            <div class="bne-metric-card">
                <h3 class="bne-metric-card__title">Quick Facts</h3>
                <ul class="bne-metric-card__facts">
                    <li><strong><?php echo number_format($data['schools_count'] ?? 0); ?></strong> total schools</li>
                    <li><strong><?php echo bmn_format_number_short($data['total_students'] ?? 0); ?></strong> students enrolled</li>
                    <?php if (!empty($data['composite_score'])) : ?>
                        <li>Composite score: <strong><?php echo number_format($data['composite_score'], 1); ?>/100</strong></li>
                    <?php endif; ?>
                    <?php if (!empty($data['ranking_year'])) : ?>
                        <li>Data from <strong><?php echo esc_html($data['ranking_year']); ?></strong> school year</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</section>
