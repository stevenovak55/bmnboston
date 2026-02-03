<?php
/**
 * District Safety Section
 *
 * Shows discipline and safety data.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$discipline = $data['discipline'] ?? null;

if (empty($discipline)) {
    return;
}

// Get percentile label
$percentile = $discipline['percentile'] ?? 50;
if ($percentile <= 25) {
    $safety_label = 'Very Low';
    $safety_class = 'bne-safety--low';
} elseif ($percentile <= 50) {
    $safety_label = 'Low';
    $safety_class = 'bne-safety--low';
} elseif ($percentile <= 75) {
    $safety_label = 'Average';
    $safety_class = 'bne-safety--average';
} else {
    $safety_label = 'Above Average';
    $safety_class = 'bne-safety--high';
}
?>

<section class="bne-section bne-district-safety">
    <div class="bne-container">
        <h2 class="bne-section-title">School Safety</h2>
        <p class="bne-section-subtitle">
            Discipline data for <?php echo esc_html($data['name'] ?? 'this district'); ?>
            (<?php echo esc_html($discipline['year'] ?? date('Y')); ?>)
        </p>

        <div class="bne-safety-summary <?php echo esc_attr($safety_class); ?>">
            <div class="bne-safety-summary__rate">
                <span class="bne-safety-summary__value"><?php echo number_format($discipline['discipline_rate'] ?? 0, 1); ?>%</span>
                <span class="bne-safety-summary__label">Discipline Rate</span>
            </div>
            <div class="bne-safety-summary__percentile">
                <span class="bne-safety-summary__badge"><?php echo esc_html($safety_label); ?></span>
                <span class="bne-safety-summary__note">
                    <?php if ($percentile <= 50) : ?>
                        Lower than <?php echo (100 - $percentile); ?>% of MA districts
                    <?php else : ?>
                        Higher than <?php echo $percentile; ?>% of MA districts
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="bne-safety-details">
            <?php if (isset($discipline['out_of_school_suspension_pct'])) : ?>
                <div class="bne-safety-stat">
                    <span class="bne-safety-stat__value"><?php echo number_format($discipline['out_of_school_suspension_pct'], 1); ?>%</span>
                    <span class="bne-safety-stat__label">Out-of-School Suspensions</span>
                </div>
            <?php endif; ?>

            <?php if (isset($discipline['in_school_suspension_pct'])) : ?>
                <div class="bne-safety-stat">
                    <span class="bne-safety-stat__value"><?php echo number_format($discipline['in_school_suspension_pct'], 1); ?>%</span>
                    <span class="bne-safety-stat__label">In-School Suspensions</span>
                </div>
            <?php endif; ?>

            <?php if (isset($discipline['expulsion_pct'])) : ?>
                <div class="bne-safety-stat">
                    <span class="bne-safety-stat__value"><?php echo number_format($discipline['expulsion_pct'], 1); ?>%</span>
                    <span class="bne-safety-stat__label">Expulsions</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
