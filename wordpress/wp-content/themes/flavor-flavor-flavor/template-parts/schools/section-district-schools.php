<?php
/**
 * District Schools Section
 *
 * Lists all schools in the district grouped by level.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

$schools_by_level = $data['schools_by_level'] ?? array(
    'elementary' => array(),
    'middle'     => array(),
    'high'       => array(),
);

$level_labels = array(
    'elementary' => 'Elementary Schools',
    'middle'     => 'Middle Schools',
    'high'       => 'High Schools',
);

$level_icons = array(
    'elementary' => 'abc',
    'middle'     => '123',
    'high'       => 'mortar-board',
);
?>

<section class="bne-section bne-district-schools">
    <div class="bne-container">
        <h2 class="bne-section-title">Schools in <?php echo esc_html($data['name'] ?? 'This District'); ?></h2>
        <p class="bne-section-subtitle">
            <?php echo number_format($data['schools_count'] ?? 0); ?> schools serving students from Pre-K through 12th grade
        </p>

        <div class="bne-district-schools__levels">
            <?php foreach ($schools_by_level as $level => $schools) : ?>
                <?php if (!empty($schools)) : ?>
                    <div class="bne-district-schools__level" id="<?php echo esc_attr($level); ?>-schools">
                        <h3 class="bne-district-schools__level-title">
                            <?php echo esc_html($level_labels[$level] ?? ucfirst($level) . ' Schools'); ?>
                            <span class="bne-district-schools__level-count">(<?php echo count($schools); ?>)</span>
                        </h3>

                        <div class="bne-district-schools__list">
                            <?php foreach ($schools as $school) :
                                    // Build school URL
                                    $district_slug = sanitize_title($data['name'] ?? '');
                                    $school_slug = sanitize_title($school->name);
                                    $school_url = home_url('/schools/' . $district_slug . '/' . $school_slug . '/');
                                ?>
                                <article class="bne-school-card bne-school-card--clickable">
                                    <a href="<?php echo esc_url($school_url); ?>" class="bne-school-card__link-overlay" aria-label="View <?php echo esc_attr($school->name); ?> details"></a>

                                    <div class="bne-school-card__grade <?php echo esc_attr(bmn_get_grade_class($school->letter_grade ?? 'N/A')); ?>">
                                        <?php echo esc_html($school->letter_grade ?? 'N/A'); ?>
                                    </div>

                                    <div class="bne-school-card__content">
                                        <h4 class="bne-school-card__name">
                                            <a href="<?php echo esc_url($school_url); ?>">
                                                <?php echo esc_html($school->name); ?>
                                            </a>
                                        </h4>

                                        <div class="bne-school-card__details">
                                            <?php if (!empty($school->grades_low) || !empty($school->grades_high)) : ?>
                                                <span class="bne-school-card__grades">
                                                    Grades <?php echo esc_html($school->grades_low ?? 'K'); ?>-<?php echo esc_html($school->grades_high ?? '12'); ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($school->enrollment)) : ?>
                                                <span class="bne-school-card__enrollment">
                                                    <?php echo number_format($school->enrollment); ?> students
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($school->state_rank)) : ?>
                                                <span class="bne-school-card__rank">
                                                    #<?php echo number_format($school->state_rank); ?> in state
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($school->address)) : ?>
                                            <p class="bne-school-card__address">
                                                <?php echo esc_html($school->address); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="bne-school-card__score">
                                        <?php if (!empty($school->composite_score)) : ?>
                                            <span class="bne-school-card__score-value"><?php echo number_format($school->composite_score, 1); ?></span>
                                            <span class="bne-school-card__score-label">Score</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="bne-school-card__action">
                                        <a href="<?php echo esc_url($school_url); ?>" class="bne-school-card__view-link">
                                            View Details <span aria-hidden="true">&rarr;</span>
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (empty($schools_by_level['elementary']) && empty($schools_by_level['middle']) && empty($schools_by_level['high'])) : ?>
            <div class="bne-district-schools__empty">
                <p>School information for this district is not yet available.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
