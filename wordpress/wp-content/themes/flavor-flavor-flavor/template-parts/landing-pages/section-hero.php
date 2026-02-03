<?php
/**
 * Landing Page Hero Section
 *
 * Displays hero image, title, and quick search for neighborhood/school district pages
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

$name = $data['name'] ?? 'Real Estate';
$state = $data['state'] ?? 'MA';
$listing_count = $data['listing_count'] ?? 0;
$image = $data['image'] ?? '';
$median_price = $data['median_price'] ?? 0;

// Default background if no image
$bg_style = !empty($image) ? "background-image: url('" . esc_url($image) . "');" : '';
?>

<section class="bne-landing-hero" <?php echo $bg_style ? 'style="' . esc_attr($bg_style) . '"' : ''; ?>>
    <div class="bne-landing-hero__overlay"></div>
    <div class="bne-landing-hero__content">
        <nav class="bne-landing-breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li><a href="<?php echo esc_url(home_url('/real-estate/')); ?>"><?php echo esc_html($state); ?> Real Estate</a></li>
                <li aria-current="page"><?php echo esc_html($name); ?></li>
            </ol>
        </nav>

        <h1 class="bne-landing-hero__title">
            <?php if ($type === 'school_district') : ?>
                Homes Near <?php echo esc_html($name); ?>
            <?php else : ?>
                <?php echo esc_html($name); ?> Real Estate
            <?php endif; ?>
        </h1>

        <?php if ($listing_count > 0) : ?>
            <p class="bne-landing-hero__subtitle">
                <strong><?php echo number_format($listing_count); ?></strong> homes for sale
                <?php if ($median_price > 0) : ?>
                    &bull; Median price: <strong>$<?php echo number_format($median_price); ?></strong>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p class="bne-landing-hero__subtitle">
                Explore homes for sale in <?php echo esc_html($name); ?>, <?php echo esc_html($state); ?>
            </p>
        <?php endif; ?>

        <!-- Search filters moved to dedicated filters section below -->
    </div>
</section>
