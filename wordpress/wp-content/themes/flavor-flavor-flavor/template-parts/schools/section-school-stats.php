<?php
/**
 * School Quick Stats Bar
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$demographics = $data['demographics'] ?? array();
$features = $data['features'] ?? array();

$total_students = $demographics['total_students'] ?? null;
$student_teacher_ratio = null;
if (!empty($features['staffing']['student_teacher_ratio'])) {
    $student_teacher_ratio = $features['staffing']['student_teacher_ratio'];
}
$graduation_rate = null;
if (!empty($features['graduation']['graduation_rate_4_year'])) {
    $graduation_rate = $features['graduation']['graduation_rate_4_year'];
}
$attendance_rate = null;
if (!empty($features['attendance']['attendance_rate'])) {
    $attendance_rate = $features['attendance']['attendance_rate'];
} elseif (!empty($features['attendance']['chronic_absence_rate'])) {
    $attendance_rate = 100 - $features['attendance']['chronic_absence_rate'];
}
?>

<section class="bne-school-stats">
    <div class="bne-container">
        <div class="bne-school-stats__grid">
            <?php if ($total_students) : ?>
                <div class="bne-school-stats__item">
                    <span class="bne-school-stats__value"><?php echo esc_html(number_format($total_students)); ?></span>
                    <span class="bne-school-stats__label">Students</span>
                </div>
            <?php endif; ?>

            <?php if ($student_teacher_ratio) : ?>
                <div class="bne-school-stats__item">
                    <span class="bne-school-stats__value"><?php echo esc_html(round($student_teacher_ratio)); ?>:1</span>
                    <span class="bne-school-stats__label">Student-Teacher Ratio</span>
                </div>
            <?php endif; ?>

            <?php if ($graduation_rate) : ?>
                <div class="bne-school-stats__item">
                    <span class="bne-school-stats__value"><?php echo esc_html(round($graduation_rate)); ?>%</span>
                    <span class="bne-school-stats__label">Graduation Rate</span>
                </div>
            <?php endif; ?>

            <?php if ($attendance_rate) : ?>
                <div class="bne-school-stats__item">
                    <span class="bne-school-stats__value"><?php echo esc_html(round($attendance_rate, 1)); ?>%</span>
                    <span class="bne-school-stats__label">Attendance Rate</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['composite_score'])) : ?>
                <div class="bne-school-stats__item">
                    <span class="bne-school-stats__value"><?php echo esc_html(round($data['composite_score'], 1)); ?></span>
                    <span class="bne-school-stats__label">Composite Score</span>
                </div>
            <?php endif; ?>

            <?php
            // Inline CTA to view homes
            $city = $data['city'] ?? '';
            if ($city) :
                $city_formatted = ucwords(strtolower($city));
                $search_url = home_url('/search/#City=' . rawurlencode($city_formatted) . '&PropertyType=Residential&status=Active');
            ?>
                <div class="bne-school-stats__item bne-school-stats__item--cta">
                    <a href="<?php echo esc_url($search_url); ?>" class="bne-school-stats__cta-link">
                        <span class="bne-school-stats__cta-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                        </span>
                        <span class="bne-school-stats__cta-text">View Homes</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
