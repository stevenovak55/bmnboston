<?php
/**
 * School Detail Hero Section
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$name = $data['name'] ?? 'School';
$letter_grade = $data['letter_grade'] ?? 'N/A';
$percentile_rank = $data['percentile_rank'] ?? null;
$state_rank = $data['state_rank'] ?? null;
$category_rank = $data['category_rank'] ?? null;
$category_total = $data['category_total'] ?? null;
$level = $data['level'] ?? '';
$grades_low = $data['grades_low'] ?? '';
$grades_high = $data['grades_high'] ?? '';
$address = $data['address'] ?? '';
$city = $data['city'] ?? '';
$district = $data['district'] ?? array();
$trend = $data['trend'] ?? null;

$grade_class = bmn_get_grade_class($letter_grade);
$grade_letter = substr($letter_grade, 0, 1);

// Format grades display
$grades_display = '';
if ($grades_low && $grades_high) {
    $grades_display = "Grades {$grades_low}-{$grades_high}";
} elseif ($level) {
    $grades_display = ucfirst($level) . ' School';
}
?>

<section class="bne-school-hero">
    <div class="bne-container">
        <!-- Breadcrumbs -->
        <nav class="bne-breadcrumbs" aria-label="Breadcrumb">
            <ol class="bne-breadcrumbs__list">
                <li class="bne-breadcrumbs__item">
                    <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                </li>
                <li class="bne-breadcrumbs__item">
                    <a href="<?php echo esc_url(home_url('/schools/')); ?>">Schools</a>
                </li>
                <?php if (!empty($district['name'])) : ?>
                    <li class="bne-breadcrumbs__item">
                        <a href="<?php echo esc_url($district['url']); ?>"><?php echo esc_html($district['name']); ?></a>
                    </li>
                <?php endif; ?>
                <li class="bne-breadcrumbs__item bne-breadcrumbs__item--current">
                    <?php echo esc_html($name); ?>
                </li>
            </ol>
        </nav>

        <div class="bne-school-hero__content">
            <div class="bne-school-hero__main">
                <!-- Grade Badge -->
                <div class="bne-school-hero__grade-badge <?php echo esc_attr($grade_class); ?>">
                    <span class="bne-school-hero__grade"><?php echo esc_html($letter_grade); ?></span>
                    <?php if ($percentile_rank !== null) : ?>
                        <span class="bne-school-hero__percentile">Top <?php echo esc_html(100 - $percentile_rank); ?>%</span>
                    <?php endif; ?>
                </div>

                <div class="bne-school-hero__info">
                    <h1 class="bne-school-hero__title"><?php echo esc_html($name); ?></h1>
                    <p class="bne-school-hero__subtitle">
                        <?php echo esc_html($grades_display); ?>
                        <?php if ($city) : ?>
                            <span class="bne-school-hero__location"><?php echo esc_html($city); ?>, MA</span>
                        <?php endif; ?>
                    </p>

                    <?php if ($trend) : ?>
                        <p class="bne-school-hero__trend bne-school-hero__trend--<?php echo esc_attr($trend['direction']); ?>">
                            <?php if ($trend['direction'] === 'up') : ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 14l5-5 5 5z"/></svg>
                            <?php elseif ($trend['direction'] === 'down') : ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                            <?php endif; ?>
                            <?php echo esc_html($trend['text']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="bne-school-hero__stats">
                <?php if ($state_rank) : ?>
                    <div class="bne-school-hero__stat">
                        <span class="bne-school-hero__stat-value">#<?php echo esc_html(number_format($state_rank)); ?></span>
                        <span class="bne-school-hero__stat-label">State Rank</span>
                    </div>
                <?php endif; ?>

                <?php if ($category_rank && $category_total) : ?>
                    <div class="bne-school-hero__stat">
                        <span class="bne-school-hero__stat-value">#<?php echo esc_html($category_rank); ?></span>
                        <span class="bne-school-hero__stat-label">of <?php echo esc_html($category_total); ?> <?php echo esc_html(ucfirst($level)); ?> Schools</span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($district['letter_grade'])) : ?>
                    <div class="bne-school-hero__stat">
                        <span class="bne-school-hero__stat-value"><?php echo esc_html($district['letter_grade']); ?></span>
                        <span class="bne-school-hero__stat-label">District Grade</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Contact Info -->
            <?php if ($address || !empty($data['phone']) || !empty($data['website'])) : ?>
                <div class="bne-school-hero__contact">
                    <?php if ($address) : ?>
                        <span class="bne-school-hero__address">
                            <?php echo esc_html($address); ?>, <?php echo esc_html($city); ?>, MA <?php echo esc_html($data['zip'] ?? ''); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($data['phone'])) : ?>
                        <a href="tel:<?php echo esc_attr($data['phone']); ?>" class="bne-school-hero__link">
                            <?php echo esc_html($data['phone']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($data['website'])) : ?>
                        <a href="<?php echo esc_url($data['website']); ?>" class="bne-school-hero__link" target="_blank" rel="noopener">
                            Visit Website
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // School Highlights Badges
            $highlights = function_exists('bmn_get_school_highlights')
                ? bmn_get_school_highlights($data['id'] ?? 0, $data)
                : array();

            if (!empty($highlights)) :
            ?>
                <div class="bne-school-hero__highlights">
                    <?php foreach ($highlights as $highlight) : ?>
                        <span class="bne-highlight-badge bne-highlight-badge--<?php echo esc_attr($highlight['type']); ?>">
                            <?php echo esc_html($highlight['text']); ?>
                            <?php if (!empty($highlight['detail'])) : ?>
                                <span class="bne-highlight-badge__detail"><?php echo esc_html($highlight['detail']); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
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
                        <span>MCAS scores from <?php echo esc_html($ranking_year); ?></span>
                    <?php endif; ?>
                    <?php if ($data_freshness) : ?>
                        <span>Data updated <?php echo esc_html(date('F Y', strtotime($data_freshness))); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
