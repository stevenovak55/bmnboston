<?php
/**
 * District College Outcomes Section
 *
 * Shows where graduates go after high school.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$outcomes = $data['college_outcomes'] ?? null;

if (empty($outcomes)) {
    return;
}
?>

<section class="bne-section bne-district-outcomes">
    <div class="bne-container">
        <h2 class="bne-section-title">Where Graduates Go</h2>
        <p class="bne-section-subtitle">
            Post-graduation outcomes for <?php echo esc_html($data['name'] ?? 'this district'); ?>
            (Class of <?php echo esc_html($outcomes['year'] ?? ''); ?>)
        </p>

        <div class="bne-outcomes-grid">
            <?php if (!empty($outcomes['total_postsecondary_pct'])) : ?>
                <div class="bne-outcome-card bne-outcome-card--featured">
                    <span class="bne-outcome-card__value"><?php echo number_format($outcomes['total_postsecondary_pct'], 1); ?>%</span>
                    <span class="bne-outcome-card__label">Attend College</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['four_year_pct'])) : ?>
                <div class="bne-outcome-card">
                    <span class="bne-outcome-card__value"><?php echo number_format($outcomes['four_year_pct'], 1); ?>%</span>
                    <span class="bne-outcome-card__label">4-Year College</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['two_year_pct'])) : ?>
                <div class="bne-outcome-card">
                    <span class="bne-outcome-card__value"><?php echo number_format($outcomes['two_year_pct'], 1); ?>%</span>
                    <span class="bne-outcome-card__label">2-Year College</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['out_of_state_pct'])) : ?>
                <div class="bne-outcome-card">
                    <span class="bne-outcome-card__value"><?php echo number_format($outcomes['out_of_state_pct'], 1); ?>%</span>
                    <span class="bne-outcome-card__label">Out of State</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($outcomes['employed_pct'])) : ?>
                <div class="bne-outcome-card">
                    <span class="bne-outcome-card__value"><?php echo number_format($outcomes['employed_pct'], 1); ?>%</span>
                    <span class="bne-outcome-card__label">Employed</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($outcomes['grad_count'])) : ?>
            <p class="bne-district-outcomes__note">
                Based on <?php echo number_format($outcomes['grad_count']); ?> graduates
            </p>
        <?php endif; ?>
    </div>
</section>
