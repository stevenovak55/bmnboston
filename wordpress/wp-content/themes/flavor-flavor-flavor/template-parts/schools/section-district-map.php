<?php
/**
 * District Map Section
 *
 * Displays the district boundary map.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

// Skip if no boundary data
if (empty($data['boundary_geojson'])) {
    return;
}
?>

<section class="bne-section bne-district-map">
    <div class="bne-container">
        <h2 class="bne-section-title">District Boundaries</h2>
        <p class="bne-section-subtitle">
            The <?php echo esc_html($data['name'] ?? 'district'); ?> serves students within this geographic area.
        </p>

        <div class="bne-district-map__container" id="district-boundary-map"
             role="img"
             aria-label="<?php echo esc_attr(sprintf('Map showing boundaries of %s in Massachusetts', $data['name'] ?? 'district')); ?>"
             data-geojson="<?php echo esc_attr($data['boundary_geojson']); ?>"
             data-district-name="<?php echo esc_attr($data['name'] ?? ''); ?>">
            <div class="bne-district-map__placeholder">
                <p>Loading district boundary map...</p>
            </div>
        </div>

        <?php if (!empty($data['cities_served'])) : ?>
            <div class="bne-district-map__legend">
                <strong>Areas Served:</strong>
                <?php echo esc_html(implode(', ', $data['cities_served'])); ?>
            </div>
        <?php endif; ?>
    </div>
</section>
