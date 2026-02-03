<?php
/**
 * Schools Browse Hero Section
 *
 * Displays the hero banner for the schools browse page.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$filters = isset($args['filters']) ? $args['filters'] : array();
?>

<section class="bne-section bne-schools-hero">
    <div class="bne-container">
        <div class="bne-schools-hero__content">
            <nav class="bne-breadcrumbs" aria-label="Breadcrumb">
                <ol class="bne-breadcrumbs__list">
                    <li class="bne-breadcrumbs__item">
                        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                    </li>
                    <li class="bne-breadcrumbs__item bne-breadcrumbs__item--current">
                        <span>School Districts</span>
                    </li>
                </ol>
            </nav>

            <h1 class="bne-schools-hero__title">Massachusetts School Districts</h1>
            <p class="bne-schools-hero__subtitle">
                Browse and compare <?php echo number_format($data['total_districts'] ?? 342); ?> school districts.
                Find the best schools for your family.
            </p>

            <div class="bne-schools-hero__stats">
                <div class="bne-schools-hero__stat">
                    <span class="bne-schools-hero__stat-value"><?php echo number_format($data['total_districts'] ?? 342); ?></span>
                    <span class="bne-schools-hero__stat-label">Districts</span>
                </div>
                <div class="bne-schools-hero__stat">
                    <span class="bne-schools-hero__stat-value">2,600+</span>
                    <span class="bne-schools-hero__stat-label">Schools</span>
                </div>
                <div class="bne-schools-hero__stat">
                    <span class="bne-schools-hero__stat-value">8+</span>
                    <span class="bne-schools-hero__stat-label">Years of MCAS Data</span>
                </div>
            </div>
        </div>
    </div>
</section>
