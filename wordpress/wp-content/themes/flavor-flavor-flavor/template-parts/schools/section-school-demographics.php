<?php
/**
 * School Demographics Section
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$demographics = $data['demographics'] ?? array();

if (empty($demographics)) {
    return;
}

$total_students = $demographics['total_students'] ?? 0;
?>

<section class="bne-school-demographics">
    <div class="bne-container">
        <h2 class="bne-section-title">Student Demographics</h2>
        <p class="bne-section-subtitle"><?php echo esc_html(number_format($total_students)); ?> students enrolled</p>

        <div class="bne-demographics-grid">
            <!-- Race/Ethnicity -->
            <div class="bne-demographics-card">
                <h3 class="bne-demographics-card__title">Race/Ethnicity</h3>
                <div class="bne-demographics-card__bars">
                    <?php
                    $race_data = array(
                        'White' => $demographics['pct_white'] ?? 0,
                        'Hispanic' => $demographics['pct_hispanic'] ?? 0,
                        'Black' => $demographics['pct_black'] ?? 0,
                        'Asian' => $demographics['pct_asian'] ?? 0,
                        'Multiracial' => $demographics['pct_multirace'] ?? 0,
                    );
                    arsort($race_data);

                    foreach ($race_data as $label => $pct) :
                        if ($pct <= 0) continue;
                    ?>
                        <div class="bne-demographics-bar">
                            <div class="bne-demographics-bar__label">
                                <span><?php echo esc_html($label); ?></span>
                                <span><?php echo esc_html(round($pct, 1)); ?>%</span>
                            </div>
                            <div class="bne-demographics-bar__track">
                                <div class="bne-demographics-bar__fill" style="width: <?php echo esc_attr($pct); ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Gender -->
            <div class="bne-demographics-card">
                <h3 class="bne-demographics-card__title">Gender</h3>
                <div class="bne-demographics-card__split">
                    <div class="bne-demographics-split__item">
                        <span class="bne-demographics-split__value"><?php echo esc_html(round($demographics['pct_male'] ?? 50, 1)); ?>%</span>
                        <span class="bne-demographics-split__label">Male</span>
                    </div>
                    <div class="bne-demographics-split__item">
                        <span class="bne-demographics-split__value"><?php echo esc_html(round($demographics['pct_female'] ?? 50, 1)); ?>%</span>
                        <span class="bne-demographics-split__label">Female</span>
                    </div>
                </div>
            </div>

            <!-- Special Populations -->
            <div class="bne-demographics-card">
                <h3 class="bne-demographics-card__title">Student Programs</h3>
                <div class="bne-demographics-card__list">
                    <?php if (isset($demographics['pct_english_learner'])) : ?>
                        <div class="bne-demographics-list__item">
                            <span>English Learners</span>
                            <span><?php echo esc_html(round($demographics['pct_english_learner'], 1)); ?>%</span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($demographics['pct_special_education'])) : ?>
                        <div class="bne-demographics-list__item">
                            <span>Special Education</span>
                            <span><?php echo esc_html(round($demographics['pct_special_education'], 1)); ?>%</span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($demographics['pct_free_reduced_lunch'])) : ?>
                        <div class="bne-demographics-list__item">
                            <span>Free/Reduced Lunch</span>
                            <span><?php echo esc_html(round($demographics['pct_free_reduced_lunch'], 1)); ?>%</span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($demographics['pct_economically_disadvantaged'])) : ?>
                        <div class="bne-demographics-list__item">
                            <span>Economically Disadvantaged</span>
                            <span><?php echo esc_html(round($demographics['pct_economically_disadvantaged'], 1)); ?>%</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
