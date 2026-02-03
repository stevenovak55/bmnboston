<?php
/**
 * District Financial Section
 *
 * Shows per-pupil spending and financial data.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$expenditure = $data['expenditure_per_pupil'] ?? null;

if (empty($expenditure)) {
    return;
}

// MA state average is approximately $18,000 per pupil (2023-2024)
$state_avg = 18000;
$diff = $expenditure - $state_avg;
$diff_pct = ($diff / $state_avg) * 100;
?>

<section class="bne-section bne-district-financial">
    <div class="bne-container">
        <h2 class="bne-section-title">School Funding</h2>

        <div class="bne-financial-card">
            <div class="bne-financial-card__main">
                <span class="bne-financial-card__value"><?php echo bmn_format_currency($expenditure); ?></span>
                <span class="bne-financial-card__label">Per-Pupil Spending</span>
            </div>

            <div class="bne-financial-card__comparison">
                <?php if ($diff > 0) : ?>
                    <span class="bne-financial-card__diff bne-financial-card__diff--above">
                        +<?php echo bmn_format_currency(abs($diff)); ?> above state average
                    </span>
                <?php elseif ($diff < 0) : ?>
                    <span class="bne-financial-card__diff bne-financial-card__diff--below">
                        <?php echo bmn_format_currency(abs($diff)); ?> below state average
                    </span>
                <?php else : ?>
                    <span class="bne-financial-card__diff">
                        At state average
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <p class="bne-district-financial__note">
            Per-pupil spending includes instructional costs, administrative expenses, transportation, and support services. Data from <?php echo esc_html($data['ranking_year'] ?? date('Y')); ?> school year.
        </p>
    </div>
</section>
