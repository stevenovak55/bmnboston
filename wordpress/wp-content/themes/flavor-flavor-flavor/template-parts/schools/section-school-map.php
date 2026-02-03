<?php
/**
 * School Location Map Section
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$lat = $data['latitude'] ?? null;
$lng = $data['longitude'] ?? null;
$name = $data['name'] ?? 'School';
$address = $data['address'] ?? '';
$city = $data['city'] ?? '';

if (!$lat || !$lng) {
    return;
}
?>

<section class="bne-school-map">
    <div class="bne-container">
        <h2 class="bne-section-title">Location</h2>
        <p class="bne-section-subtitle"><?php echo esc_html($address); ?>, <?php echo esc_html($city); ?>, MA</p>

        <div class="bne-school-map__container"
             id="school-location-map"
             role="img"
             aria-label="<?php echo esc_attr(sprintf('Map showing location of %s in %s, Massachusetts', $name, $city)); ?>"
             data-lat="<?php echo esc_attr($lat); ?>"
             data-lng="<?php echo esc_attr($lng); ?>"
             data-name="<?php echo esc_attr($name); ?>">
            <div class="bne-school-map__placeholder">
                Loading map...
            </div>
        </div>

        <div class="bne-school-map__actions">
            <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo esc_attr(urlencode($address . ', ' . $city . ', MA')); ?>"
               class="bne-btn bne-btn--primary"
               target="_blank"
               rel="noopener">
                Get Directions
            </a>
        </div>
    </div>
</section>
