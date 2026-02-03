<?php
/**
 * School MCAS Scores Section
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$mcas_averages = $data['mcas_averages'] ?? array();
$scores_by_year = $data['scores_by_year'] ?? array();

if (empty($mcas_averages)) {
    return;
}

// Get available years for tabs
$years = array_keys($scores_by_year);
rsort($years);
?>

<section class="bne-school-mcas">
    <div class="bne-container">
        <h2 class="bne-section-title">MCAS Test Scores</h2>
        <p class="bne-section-subtitle">Massachusetts Comprehensive Assessment System results</p>

        <!-- Summary Cards -->
        <div class="bne-mcas-summary">
            <?php foreach ($mcas_averages as $subject_data) :
                $subject = $subject_data->subject ?? '';
                $proficient = round($subject_data->avg_proficient ?? 0, 1);
                $advanced = round($subject_data->avg_advanced ?? 0, 1);

                // Color coding based on proficiency
                $color_class = 'bne-mcas-card--average';
                if ($proficient >= 70) {
                    $color_class = 'bne-mcas-card--excellent';
                } elseif ($proficient >= 50) {
                    $color_class = 'bne-mcas-card--good';
                } elseif ($proficient < 40) {
                    $color_class = 'bne-mcas-card--low';
                }
            ?>
                <div class="bne-mcas-card <?php echo esc_attr($color_class); ?>">
                    <h3 class="bne-mcas-card__subject"><?php echo esc_html(ucfirst($subject)); ?></h3>
                    <div class="bne-mcas-card__score">
                        <span class="bne-mcas-card__value"><?php echo esc_html($proficient); ?>%</span>
                        <span class="bne-mcas-card__label">Proficient or Above</span>
                    </div>
                    <?php if ($advanced > 0) : ?>
                        <div class="bne-mcas-card__advanced">
                            <span class="bne-mcas-card__advanced-value"><?php echo esc_html($advanced); ?>%</span>
                            <span class="bne-mcas-card__advanced-label">Advanced</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Historical Data -->
        <?php if (count($years) > 1) : ?>
            <div class="bne-mcas-history">
                <h3 class="bne-mcas-history__title">Score Trends</h3>
                <div class="bne-mcas-history__table-wrap">
                    <table class="bne-mcas-history__table">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Subject</th>
                                <th>Grade</th>
                                <th>Proficient+</th>
                                <th>Advanced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $row_count = 0;
                            foreach ($years as $year) :
                                if ($row_count >= 15) break; // Limit rows
                                $year_scores = $scores_by_year[$year] ?? array();
                                foreach ($year_scores as $score) :
                                    if ($row_count >= 15) break;
                                    $row_count++;
                            ?>
                                <tr>
                                    <td><?php echo esc_html($year); ?></td>
                                    <td><?php echo esc_html(ucfirst($score->subject)); ?></td>
                                    <td><?php echo esc_html($score->grade); ?></td>
                                    <td><?php echo esc_html(round($score->proficient_or_above_pct ?? 0, 1)); ?>%</td>
                                    <td><?php echo esc_html(round($score->advanced_pct ?? 0, 1)); ?>%</td>
                                </tr>
                            <?php
                                endforeach;
                            endforeach;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <p class="bne-mcas-note">
            MCAS is administered annually to students in grades 3-8 and 10. Scores reflect the percentage of students
            meeting or exceeding grade-level expectations.
        </p>
    </div>
</section>
