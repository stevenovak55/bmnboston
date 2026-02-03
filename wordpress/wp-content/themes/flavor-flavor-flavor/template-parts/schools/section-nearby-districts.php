<?php
/**
 * Nearby Districts Section
 *
 * Shows related districts for comparison.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

$nearby = $data['nearby'] ?? array();

if (empty($nearby)) {
    return;
}
?>

<section class="bne-section bne-nearby-districts">
    <div class="bne-container">
        <h2 class="bne-section-title">Nearby Districts</h2>
        <p class="bne-section-subtitle">
            Compare <?php echo esc_html($data['name'] ?? 'this district'); ?> with other districts in <?php echo esc_html($data['county'] ?? 'the area'); ?> County.
        </p>

        <div class="bne-nearby-districts__grid">
            <?php foreach ($nearby as $district) : ?>
                <a href="<?php echo esc_url($district->url); ?>" class="bne-nearby-district-card">
                    <div class="bne-nearby-district-card__grade <?php echo esc_attr(bmn_get_grade_class($district->letter_grade ?? 'N/A')); ?>">
                        <?php echo esc_html($district->letter_grade ?? 'N/A'); ?>
                    </div>
                    <div class="bne-nearby-district-card__content">
                        <h3 class="bne-nearby-district-card__name"><?php echo esc_html($district->name); ?></h3>
                        <p class="bne-nearby-district-card__city"><?php echo esc_html($district->city); ?>, MA</p>
                        <?php if (!empty($district->composite_score)) : ?>
                            <span class="bne-nearby-district-card__score">
                                Score: <?php echo number_format($district->composite_score, 1); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
