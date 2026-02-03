<?php
/**
 * Landing Page Nearby Section
 *
 * Displays nearby neighborhoods/cities
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? '';
$nearby = $data['nearby'] ?? array();

if (empty($nearby)) {
    return;
}
?>

<section class="bne-landing-nearby">
    <div class="bne-landing-container">
        <h2 class="bne-landing-section-title">
            Nearby Cities
        </h2>
        <p class="bne-landing-section-subtitle">
            Explore homes in communities near <?php echo esc_html($name); ?>
        </p>

        <div class="bne-landing-nearby__grid">
            <?php foreach ($nearby as $city) :
                $city_name = $city['name'] ?? '';
                $city_count = $city['listing_count'] ?? 0;
                $city_url = $city['url'] ?? home_url('/real-estate/' . sanitize_title($city_name) . '/');
                $distance = isset($city['distance']) ? round($city['distance'], 1) : '';
            ?>
                <a href="<?php echo esc_url($city_url); ?>" class="bne-landing-nearby-card">
                    <div class="bne-landing-nearby-card__content">
                        <h3 class="bne-landing-nearby-card__name">
                            <?php echo esc_html($city_name); ?>
                        </h3>
                        <div class="bne-landing-nearby-card__meta">
                            <span class="bne-landing-nearby-card__count">
                                <?php echo number_format($city_count); ?> listings
                            </span>
                            <?php if ($distance) : ?>
                                <span class="bne-landing-nearby-card__distance">
                                    <?php echo esc_html($distance); ?> mi
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <svg class="bne-landing-nearby-card__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M5 12h14"></path>
                        <path d="m12 5 7 7-7 7"></path>
                    </svg>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
