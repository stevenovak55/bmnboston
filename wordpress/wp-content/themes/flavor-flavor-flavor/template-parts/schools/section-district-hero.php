<?php
/**
 * District Detail Hero Section
 *
 * Displays the hero banner for a district detail page.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

$grade = $data['letter_grade'] ?? 'N/A';
$grade_class = bmn_get_grade_class($grade);
$percentile = $data['percentile_rank'] ?? 0;
?>

<section class="bne-district-hero">
    <div class="bne-container">
        <nav class="bne-breadcrumbs" aria-label="Breadcrumb">
            <ol class="bne-breadcrumbs__list">
                <li class="bne-breadcrumbs__item">
                    <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                </li>
                <li class="bne-breadcrumbs__item">
                    <a href="<?php echo esc_url(home_url('/schools/')); ?>">School Districts</a>
                </li>
                <li class="bne-breadcrumbs__item bne-breadcrumbs__item--current">
                    <span><?php echo esc_html($data['name'] ?? 'District'); ?></span>
                </li>
            </ol>
        </nav>

        <div class="bne-district-hero__content">
            <div class="bne-district-hero__main">
                <div class="bne-district-hero__grade-badge <?php echo esc_attr($grade_class); ?>">
                    <span class="bne-district-hero__grade"><?php echo esc_html($grade); ?></span>
                    <?php if ($percentile > 0) : ?>
                        <span class="bne-district-hero__percentile">Top <?php echo (100 - $percentile); ?>%</span>
                    <?php endif; ?>
                </div>

                <div class="bne-district-hero__info">
                    <h1 class="bne-district-hero__title"><?php echo esc_html($data['name'] ?? 'District'); ?></h1>
                    <p class="bne-district-hero__subtitle">
                        <?php echo esc_html($data['city'] ?? ''); ?>, Massachusetts
                        <?php if (!empty($data['county'])) : ?>
                            &bull; <?php echo esc_html($data['county']); ?> County
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="bne-district-hero__stats">
                <?php if (!empty($data['state_rank'])) : ?>
                    <div class="bne-district-hero__stat">
                        <span class="bne-district-hero__stat-value">#<?php echo number_format($data['state_rank']); ?></span>
                        <span class="bne-district-hero__stat-label">State Rank</span>
                    </div>
                <?php endif; ?>

                <div class="bne-district-hero__stat">
                    <span class="bne-district-hero__stat-value"><?php echo number_format($data['schools_count'] ?? 0); ?></span>
                    <span class="bne-district-hero__stat-label">Schools</span>
                </div>

                <div class="bne-district-hero__stat">
                    <span class="bne-district-hero__stat-value"><?php echo bmn_format_number_short($data['total_students'] ?? 0); ?></span>
                    <span class="bne-district-hero__stat-label">Students</span>
                </div>

                <?php if (!empty($data['composite_score'])) : ?>
                    <div class="bne-district-hero__stat">
                        <span class="bne-district-hero__stat-value"><?php echo number_format($data['composite_score'], 1); ?></span>
                        <span class="bne-district-hero__stat-label">Composite Score</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($data['website']) || !empty($data['phone'])) : ?>
                <div class="bne-district-hero__contact">
                    <?php if (!empty($data['website'])) : ?>
                        <a href="<?php echo esc_url($data['website']); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="bne-district-hero__link">
                            Official Website &rarr;
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($data['phone'])) : ?>
                        <a href="tel:<?php echo esc_attr($data['phone']); ?>"
                           class="bne-district-hero__link">
                            <?php echo esc_html($data['phone']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // Data Freshness Indicator
            $ranking_year = $data['ranking_year'] ?? null;
            $data_freshness = $data['data_freshness'] ?? null;

            if ($ranking_year || $data_freshness) :
            ?>
                <div class="bne-data-freshness">
                    <?php if ($ranking_year) : ?>
                        <span>Rankings from <?php echo esc_html($ranking_year); ?></span>
                    <?php endif; ?>
                    <?php if ($data_freshness) : ?>
                        <span>Data updated <?php
                            $freshness_date = new DateTime($data_freshness, wp_timezone());
                            echo esc_html(wp_date('F Y', $freshness_date->getTimestamp()));
                        ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
