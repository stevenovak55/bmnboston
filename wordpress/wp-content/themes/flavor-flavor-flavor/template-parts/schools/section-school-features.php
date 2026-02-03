<?php
/**
 * School Features Section (graduation, attendance, AP, etc.)
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$features = $data['features'] ?? array();
$level = strtolower($data['level'] ?? '');

// Count available features
$has_features = !empty($features['graduation']) ||
                !empty($features['attendance']) ||
                !empty($features['ap_summary']) ||
                !empty($features['masscore']) ||
                !empty($features['staffing']);

if (!$has_features) {
    return;
}
?>

<section class="bne-school-features">
    <div class="bne-container">
        <h2 class="bne-section-title">School Performance</h2>
        <p class="bne-section-subtitle">Key metrics and program information</p>

        <div class="bne-features-grid">
            <?php if (!empty($features['graduation']) && strpos($level, 'high') !== false) :
                $grad = $features['graduation'];
            ?>
                <div class="bne-feature-card">
                    <h3 class="bne-feature-card__title">Graduation</h3>
                    <div class="bne-feature-card__metrics">
                        <?php if (!empty($grad['graduation_rate_4_year'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($grad['graduation_rate_4_year'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">4-Year Rate</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($grad['dropout_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($grad['dropout_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">Dropout Rate</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($features['attendance'])) :
                $attend = $features['attendance'];
            ?>
                <div class="bne-feature-card">
                    <h3 class="bne-feature-card__title">Attendance</h3>
                    <div class="bne-feature-card__metrics">
                        <?php if (!empty($attend['attendance_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($attend['attendance_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">Attendance Rate</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($attend['chronic_absence_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($attend['chronic_absence_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">Chronic Absence</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($features['ap_summary']) && strpos($level, 'high') !== false) :
                $ap = $features['ap_summary'];
            ?>
                <div class="bne-feature-card">
                    <h3 class="bne-feature-card__title">Advanced Placement</h3>
                    <div class="bne-feature-card__metrics">
                        <?php if (!empty($ap['ap_participation_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($ap['ap_participation_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">Participation</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($ap['ap_pass_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($ap['ap_pass_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">Pass Rate (3+)</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($ap['ap_courses_offered'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html($ap['ap_courses_offered']); ?></span>
                                <span class="bne-feature-metric__label">Courses Offered</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($features['masscore']) && strpos($level, 'high') !== false) :
                $masscore = $features['masscore'];
            ?>
                <div class="bne-feature-card">
                    <h3 class="bne-feature-card__title">College Readiness</h3>
                    <div class="bne-feature-card__metrics">
                        <?php if (!empty($masscore['masscore_completion_rate'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($masscore['masscore_completion_rate'], 1)); ?>%</span>
                                <span class="bne-feature-metric__label">MassCore Completion</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="bne-feature-card__note">MassCore is Massachusetts' recommended college-ready curriculum.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($features['staffing'])) :
                $staffing = $features['staffing'];
            ?>
                <div class="bne-feature-card">
                    <h3 class="bne-feature-card__title">Staffing</h3>
                    <div class="bne-feature-card__metrics">
                        <?php if (!empty($staffing['student_teacher_ratio'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($staffing['student_teacher_ratio'])); ?>:1</span>
                                <span class="bne-feature-metric__label">Student-Teacher Ratio</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($staffing['total_fte_teachers'])) : ?>
                            <div class="bne-feature-metric">
                                <span class="bne-feature-metric__value"><?php echo esc_html(round($staffing['total_fte_teachers'])); ?></span>
                                <span class="bne-feature-metric__label">Teachers (FTE)</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
