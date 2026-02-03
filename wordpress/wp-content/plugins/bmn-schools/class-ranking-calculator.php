<?php
/**
 * Ranking Calculator Class
 *
 * Calculates composite school scores and rankings based on multiple factors
 * including MCAS scores, graduation rates, attendance, and more.
 *
 * @package BMN_Schools
 * @since 0.6.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Ranking Calculator Class
 *
 * @since 0.6.0
 */
class BMN_Schools_Ranking_Calculator {

    /**
     * Scoring weights for composite calculation - High School / Default.
     * Weights should sum to 1.0 (100%).
     *
     * @var array
     */
    private $weights = [
        'mcas_proficiency' => 0.30,   // 30% - MCAS proficiency scores (was 25%)
        'graduation_rate'  => 0.15,   // 15% - 4-year graduation rate (was 12%)
        'mcas_growth'      => 0.12,   // 12% - Year-over-year improvement (was 10%)
        'ratio'            => 0.10,   // 10% - Student-teacher ratio (was 20% - reduced to avoid small school bias)
        'masscore'         => 0.10,   // 10% - MassCore completion %
        'attendance'       => 0.10,   // 10% - Inverse of chronic absence
        'ap_performance'   => 0.08,   // 8%  - AP participation/pass rate
        'per_pupil'        => 0.05,   // 5%  - Per-pupil spending index
    ];

    /**
     * Elementary school weights - emphasizes available metrics.
     * Elementary schools don't have graduation, AP, MassCore data.
     * Phase 6: Added per_pupil with district fallback (was 0% when school-level only).
     *
     * @var array
     */
    private $weights_elementary = [
        'mcas_proficiency' => 0.40,   // 40% - MCAS proficiency (primary metric) (was 35%)
        'attendance'       => 0.20,   // 20% - Inverse of chronic absence (was 25%)
        'ratio'            => 0.15,   // 15% - Student-teacher ratio (was 20% - reduced to avoid small school bias)
        'mcas_growth'      => 0.15,   // 15% - Year-over-year improvement (was 10%)
        'per_pupil'        => 0.10,   // 10% - Per-pupil spending (uses district fallback)
        'graduation_rate'  => 0.00,   // N/A for elementary
        'masscore'         => 0.00,   // N/A for elementary
        'ap_performance'   => 0.00,   // N/A for elementary
    ];

    /**
     * The year to calculate rankings for.
     *
     * @var int
     */
    private $year;

    /**
     * Constructor.
     *
     * @param int|null $year Year to calculate rankings for. Defaults to current year.
     */
    public function __construct($year = null) {
        $this->year = $year ?: (int) date('Y');
    }

    /**
     * Calculate and store rankings for all schools.
     *
     * @return array Results with count of schools ranked.
     */
    public function calculate_all_rankings() {
        global $wpdb;

        $schools_table = $wpdb->prefix . 'bmn_schools';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

        // Get all schools
        $schools = $wpdb->get_results("SELECT id, name, level FROM {$schools_table}");

        $results = [
            'total' => count($schools),
            'ranked' => 0,
            'skipped' => 0,
            'year' => $this->year,
        ];

        $scores = [];

        // Calculate raw scores for each school
        foreach ($schools as $school) {
            $score_data = $this->calculate_school_score($school->id, $school->level);

            if ($score_data['has_data']) {
                $scores[$school->id] = $score_data;
                $results['ranked']++;
            } else {
                $results['skipped']++;
            }
        }

        // Calculate percentile ranks based on composite scores
        $this->calculate_percentile_ranks($scores);

        // Store rankings in database
        foreach ($scores as $school_id => $score_data) {
            $this->store_ranking($school_id, $score_data);
        }

        // Log the calculation
        if (class_exists('BMN_Schools_Logger')) {
            BMN_Schools_Logger::log('info', 'ranking', 'Rankings calculated', $results);
        }

        return $results;
    }

    /**
     * Calculate score for a single school.
     *
     * @param int         $school_id School ID.
     * @param string|null $level     School level (Elementary, Middle, High). If null, looked up.
     * @return array Score data with component scores and composite.
     */
    public function calculate_school_score($school_id, $level = null) {
        // Look up school level if not provided
        if ($level === null) {
            global $wpdb;
            $level = $wpdb->get_var($wpdb->prepare(
                "SELECT level FROM {$wpdb->prefix}bmn_schools WHERE id = %d",
                $school_id
            ));
        }

        // Use elementary-specific weights for elementary schools
        $is_elementary = stripos($level ?? '', 'Elementary') !== false;
        $weights = $is_elementary ? $this->weights_elementary : $this->weights;

        $components = [
            'mcas_proficiency' => $this->get_mcas_proficiency_score($school_id),
            'graduation_rate'  => $this->get_graduation_score($school_id),
            'masscore'         => $this->get_masscore_score($school_id),
            'attendance'       => $this->get_attendance_score($school_id),
            'ap_performance'   => $this->get_ap_score($school_id),
            'mcas_growth'      => $this->get_mcas_growth_score($school_id),
            'per_pupil'        => $this->get_per_pupil_score($school_id),
            'ratio'            => $this->get_ratio_score($school_id),
        ];

        // CRITICAL: MCAS data is required for a school to be ranked
        // Schools without MCAS (daycares, special ed, alternative programs) should not be ranked
        // This prevents artificial inflation of district scores
        $has_mcas = $components['mcas_proficiency'] !== null;
        if (!$has_mcas) {
            return [
                'has_data' => false,
                'data_count' => 0,
                'confidence_level' => 'no_mcas',
                'composite_score' => null,
                'components' => $components,
                'percentile_rank' => null,
                'state_rank' => null,
                'is_elementary' => $is_elementary,
                'enrollment' => null,
                'reliability_factor' => null,
                'skip_reason' => 'No MCAS data - school not eligible for ranking',
            ];
        }

        // Count how many components have data (only count components with non-zero weight)
        $data_count = 0;
        $weighted_sum = 0;
        $weight_sum = 0;

        foreach ($components as $key => $score) {
            if ($score !== null && isset($weights[$key]) && $weights[$key] > 0) {
                $data_count++;
                $weighted_sum += $score * $weights[$key];
                $weight_sum += $weights[$key];
            }
        }

        // Require minimum 3 data categories for ranking
        // Schools with fewer than 3 data points are not ranked (unfair advantage)
        // Elementary now has 5 factors: mcas, attendance, ratio, growth, per_pupil
        $min_for_ranking = 3;                      // Minimum to be ranked at all
        $min_for_full = $is_elementary ? 5 : 7;   // Comprehensive (no penalty)
        $min_for_good = $is_elementary ? 4 : 5;   // Good (-5% penalty)

        $has_data = $data_count >= $min_for_ranking;

        // Determine confidence level and penalty
        // Only applies to schools that have enough data to be ranked (3+ categories)
        if ($data_count >= $min_for_full) {
            $confidence_level = 'comprehensive';
            $confidence_penalty = 0;
        } elseif ($data_count >= $min_for_good) {
            $confidence_level = 'good';
            $confidence_penalty = 0.05;  // 5% reduction
        } elseif ($data_count >= $min_for_ranking) {
            $confidence_level = 'limited';
            $confidence_penalty = 0.10;  // 10% reduction
        } else {
            // Schools with < 3 data points should not be ranked
            $confidence_level = 'insufficient';
            $confidence_penalty = 1.0;  // Won't be used since has_data = false
        }

        // Normalize composite score to 0-100 scale
        $composite = ($has_data && $weight_sum > 0) ? ($weighted_sum / $weight_sum) : null;

        // Apply confidence penalty to prevent low-data schools from outscoring high-data schools
        if ($composite !== null && $confidence_penalty > 0) {
            $composite = $composite * (1 - $confidence_penalty);
        }

        // Apply enrollment-based reliability factor
        // Small schools/districts get penalized up to 25% to account for statistical unreliability
        $enrollment = $this->get_school_enrollment($school_id);
        $reliability_factor = $this->get_enrollment_reliability_factor($enrollment);
        if ($composite !== null) {
            $composite = $composite * $reliability_factor;
        }

        return [
            'has_data' => $has_data,
            'data_count' => $data_count,
            'confidence_level' => $confidence_level,
            'composite_score' => $composite,
            'components' => $components,
            'percentile_rank' => null, // Set later
            'state_rank' => null, // Set later
            'is_elementary' => $is_elementary,
            'enrollment' => $enrollment,
            'reliability_factor' => $reliability_factor,
        ];
    }

    /**
     * Get MCAS proficiency score for a school (0-100).
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_mcas_proficiency_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_test_scores';

        // Get the target year's average proficiency across all grades
        // Try the exact year first, then fall back to most recent year if not available
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(proficient_or_above_pct)
             FROM {$table}
             WHERE school_id = %d
             AND year = %d
             AND proficient_or_above_pct IS NOT NULL",
            $school_id, $this->year
        ));

        // If no data for target year, try previous year
        if ($result === null) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(proficient_or_above_pct)
                 FROM {$table}
                 WHERE school_id = %d
                 AND year = %d
                 AND proficient_or_above_pct IS NOT NULL",
                $school_id, $this->year - 1
            ));
        }

        return $result !== null ? (float) $result : null;
    }

    /**
     * Get graduation rate score for a school (0-100).
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_graduation_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_features';

        // Try to get data for the target year first
        // Graduation data is stored with feature_name like "Graduation Rate 2024"
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$table}
             WHERE school_id = %d
             AND feature_type = 'graduation'
             AND feature_name LIKE %s
             LIMIT 1",
            $school_id,
            'Graduation Rate ' . $this->year
        ));

        // If no data for target year, try previous year
        if ($result === null) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT feature_value
                 FROM {$table}
                 WHERE school_id = %d
                 AND feature_type = 'graduation'
                 AND feature_name LIKE %s
                 LIMIT 1",
                $school_id,
                'Graduation Rate ' . ($this->year - 1)
            ));
        }

        if ($result === null) {
            return null;
        }

        $value = json_decode($result, true);
        // Try different field names for graduation rate
        if (isset($value['graduation_rate'])) {
            return (float) $value['graduation_rate'];
        }
        if (isset($value['rate'])) {
            return (float) $value['rate'];
        }
        return null;
    }

    /**
     * Get MassCore completion score for a school (0-100).
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_masscore_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_features';

        // Try to get data for the target year first
        // MassCore data is stored with feature_name like "MassCore 2024"
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$table}
             WHERE school_id = %d
             AND feature_type = 'masscore'
             AND feature_name LIKE %s
             LIMIT 1",
            $school_id,
            'MassCore ' . $this->year
        ));

        // If no data for target year, try previous year
        if ($result === null) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT feature_value
                 FROM {$table}
                 WHERE school_id = %d
                 AND feature_type = 'masscore'
                 AND feature_name LIKE %s
                 LIMIT 1",
                $school_id,
                'MassCore ' . ($this->year - 1)
            ));
        }

        if ($result === null) {
            return null;
        }

        $value = json_decode($result, true);
        // Try different field names for MassCore completion
        if (isset($value['masscore_pct'])) {
            return (float) $value['masscore_pct'];
        }
        if (isset($value['pct_complete'])) {
            return (float) $value['pct_complete'];
        }
        return null;
    }

    /**
     * Get attendance score for a school (0-100).
     * Inverse of chronic absence rate.
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_attendance_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_features';

        // Try to get data for the target year first
        // Attendance data is stored with feature_name like "Attendance 2025"
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$table}
             WHERE school_id = %d
             AND feature_type = 'attendance'
             AND feature_name LIKE %s
             LIMIT 1",
            $school_id,
            'Attendance ' . $this->year
        ));

        // If no data for target year, try previous year
        if ($result === null) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT feature_value
                 FROM {$table}
                 WHERE school_id = %d
                 AND feature_type = 'attendance'
                 AND feature_name LIKE %s
                 LIMIT 1",
                $school_id,
                'Attendance ' . ($this->year - 1)
            ));
        }

        if ($result === null) {
            return null;
        }

        $value = json_decode($result, true);

        // Use chronic absence rate if available
        if (isset($value['chronic_absence_rate'])) {
            // Invert: 0% absence = 100 score, 100% absence = 0 score
            return max(0, 100 - (float) $value['chronic_absence_rate']);
        }

        // Otherwise use attendance rate directly
        if (isset($value['attendance_rate'])) {
            return (float) $value['attendance_rate'];
        }

        return null;
    }

    /**
     * Get AP performance score for a school (0-100).
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_ap_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_features';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$table}
             WHERE school_id = %d
             AND feature_type = 'ap_summary'
             ORDER BY id DESC
             LIMIT 1",
            $school_id
        ));

        if ($result === null) {
            return null;
        }

        $value = json_decode($result, true);

        // Use pass rate if available
        if (isset($value['pass_rate'])) {
            return (float) $value['pass_rate'];
        }

        // Otherwise use participation rate
        if (isset($value['participation_rate'])) {
            return (float) $value['participation_rate'];
        }

        return null;
    }

    /**
     * Get MCAS growth score for a school (0-100).
     * Measures year-over-year improvement.
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if insufficient data.
     */
    private function get_mcas_growth_score($school_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_test_scores';

        // Get the target year and previous year data
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(proficient_or_above_pct)
             FROM {$table}
             WHERE school_id = %d
             AND year = %d
             AND proficient_or_above_pct IS NOT NULL",
            $school_id, $this->year
        ));

        $previous = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(proficient_or_above_pct)
             FROM {$table}
             WHERE school_id = %d
             AND year = %d
             AND proficient_or_above_pct IS NOT NULL",
            $school_id, $this->year - 1
        ));

        if ($current === null || $previous === null) {
            return null;
        }

        $current = (float) $current;
        $previous = (float) $previous;

        // Calculate growth as percentage point change
        $growth = $current - $previous;

        // Convert to 0-100 scale:
        // -20 or less = 0, no change = 50, +20 or more = 100
        $score = 50 + ($growth * 2.5);
        return max(0, min(100, $score));
    }

    /**
     * Get per-pupil spending score for a school (0-100).
     * Falls back to district-level spending if school-level is unavailable.
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_per_pupil_score($school_id) {
        global $wpdb;

        $per_pupil = null;

        // First try school-level expenditure data
        $table = $wpdb->prefix . 'bmn_school_features';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$table}
             WHERE school_id = %d
             AND feature_type = 'expenditure'
             ORDER BY id DESC
             LIMIT 1",
            $school_id
        ));

        if ($result !== null) {
            $value = json_decode($result, true);
            if (isset($value['per_pupil_total']) && $value['per_pupil_total'] !== null) {
                $per_pupil = (float) $value['per_pupil_total'];
            } elseif (isset($value['per_pupil_instruction']) && $value['per_pupil_instruction'] !== null) {
                $per_pupil = (float) $value['per_pupil_instruction'];
            }
        }

        // Fallback to district-level spending if school-level is not available
        if ($per_pupil === null || $per_pupil <= 0) {
            $schools_table = $wpdb->prefix . 'bmn_schools';
            $districts_table = $wpdb->prefix . 'bmn_school_districts';

            $district_data = $wpdb->get_var($wpdb->prepare(
                "SELECT d.extra_data
                 FROM {$schools_table} s
                 JOIN {$districts_table} d ON s.district_id = d.id
                 WHERE s.id = %d",
                $school_id
            ));

            if ($district_data) {
                $data = json_decode($district_data, true);
                if (isset($data['expenditure_per_pupil_total']) && $data['expenditure_per_pupil_total'] > 0) {
                    $per_pupil = (float) $data['expenditure_per_pupil_total'];
                }
            }
        }

        if ($per_pupil === null || $per_pupil <= 0) {
            return null;
        }

        // Score based on spending:
        // MA average is around $18,000. Higher = better resources = higher score.
        // $10,000 = 25, $18,000 = 50, $30,000 = 100
        if ($per_pupil >= 30000) {
            return 100;
        } elseif ($per_pupil <= 10000) {
            return 25;
        }

        // Linear scale between 10k-30k
        return 25 + (($per_pupil - 10000) / 20000 * 75);
    }

    /**
     * Get student-teacher ratio score for a school (0-100).
     * Lower ratio = higher score.
     *
     * @param int $school_id School ID.
     * @return float|null Score or null if no data.
     */
    private function get_ratio_score($school_id) {
        global $wpdb;

        // First try school-level from features
        $features_table = $wpdb->prefix . 'bmn_school_features';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value
             FROM {$features_table}
             WHERE school_id = %d
             AND feature_type = 'staffing'
             ORDER BY id DESC
             LIMIT 1",
            $school_id
        ));

        if ($result !== null) {
            $value = json_decode($result, true);
            if (isset($value['student_teacher_ratio'])) {
                $ratio = (float) $value['student_teacher_ratio'];
            } elseif (isset($value['teacher_fte']) && $value['teacher_fte'] > 0) {
                // Calculate from demographics if we have teacher count
                $demo_table = $wpdb->prefix . 'bmn_school_demographics';
                $enrollment = $wpdb->get_var($wpdb->prepare(
                    "SELECT total_students FROM {$demo_table} WHERE school_id = %d ORDER BY year DESC LIMIT 1",
                    $school_id
                ));
                if ($enrollment) {
                    $ratio = (float) $enrollment / (float) $value['teacher_fte'];
                }
            }
        }

        if (!isset($ratio) || $ratio <= 0) {
            return null;
        }

        // Score based on ratio:
        // 10:1 or less = 100, 15:1 = 75, 20:1 = 50, 25:1 = 25, 30:1+ = 0
        if ($ratio <= 10) {
            return 100;
        } elseif ($ratio >= 30) {
            return 0;
        }

        // Linear scale between 10-30
        return 100 - (($ratio - 10) / 20 * 100);
    }

    /**
     * Get school enrollment from demographics table.
     *
     * @param int $school_id School ID.
     * @return int|null Enrollment count or null if not found.
     */
    private function get_school_enrollment($school_id) {
        global $wpdb;
        $demo_table = $wpdb->prefix . 'bmn_school_demographics';

        $enrollment = $wpdb->get_var($wpdb->prepare(
            "SELECT total_students FROM {$demo_table}
             WHERE school_id = %d
             ORDER BY year DESC LIMIT 1",
            $school_id
        ));

        return $enrollment !== null ? (int) $enrollment : null;
    }

    /**
     * Get district total enrollment.
     *
     * @param int $district_id District ID.
     * @return int|null Enrollment count or null if not found.
     */
    private function get_district_enrollment($district_id) {
        global $wpdb;
        $districts_table = $wpdb->prefix . 'bmn_school_districts';

        $enrollment = $wpdb->get_var($wpdb->prepare(
            "SELECT total_students FROM {$districts_table} WHERE id = %d",
            $district_id
        ));

        return $enrollment !== null ? (int) $enrollment : null;
    }

    /**
     * Calculate statistical reliability factor based on enrollment.
     *
     * Larger samples = more reliable = factor closer to 1.0.
     * AGGRESSIVE: Up to 25% penalty for very small districts.
     *
     * Tiers:
     * - Very Large (5000+): 1.00 (no adjustment)
     * - Large (2000-4999): 0.95-1.00 (0-5% penalty)
     * - Medium (500-1999): 0.88-0.95 (5-12% penalty)
     * - Small (200-499): 0.80-0.88 (12-20% penalty)
     * - Very Small (<200): 0.75-0.80 (20-25% penalty)
     *
     * @param int|null $enrollment Total enrollment count.
     * @return float Reliability factor (0.75-1.0).
     */
    private function get_enrollment_reliability_factor($enrollment) {
        if ($enrollment === null || $enrollment <= 0) {
            return 0.75; // Default heavy penalty for unknown enrollment
        }

        if ($enrollment >= 5000) {
            return 1.00; // No penalty for very large
        } elseif ($enrollment >= 2000) {
            // 0-5% penalty for large (0.95-1.00)
            return 0.95 + (($enrollment - 2000) / 3000) * 0.05;
        } elseif ($enrollment >= 500) {
            // 5-12% penalty for medium (0.88-0.95)
            return 0.88 + (($enrollment - 500) / 1500) * 0.07;
        } elseif ($enrollment >= 200) {
            // 12-20% penalty for small (0.80-0.88)
            return 0.80 + (($enrollment - 200) / 300) * 0.08;
        } else {
            // 20-25% penalty for very small (0.75-0.80)
            return 0.75 + ($enrollment / 200) * 0.05;
        }
    }

    /**
     * Calculate percentile ranks and state ranks for all schools.
     *
     * Rankings are calculated separately for each category:
     * - Public Elementary, Public Middle, Public High
     * - Private Elementary, Private Middle, Private High
     *
     * @param array &$scores Array of school scores (passed by reference).
     */
    private function calculate_percentile_ranks(&$scores) {
        global $wpdb;
        $schools_table = $wpdb->prefix . 'bmn_schools';

        // Get school type, level, and grade range for each school
        $school_info = [];
        $school_ids = array_keys($scores);
        if (!empty($school_ids)) {
            $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT id, school_type, level, grades_low, grades_high FROM {$schools_table} WHERE id IN ({$placeholders})",
                ...$school_ids
            ));
            foreach ($results as $row) {
                $school_info[$row->id] = [
                    'type' => $row->school_type ?: 'public',
                    'level' => $row->level ?: 'other',
                    'grades_low' => $row->grades_low,
                    'grades_high' => $row->grades_high,
                ];
            }
        }

        // Group schools by category (type + level)
        $categories = [];
        foreach ($scores as $school_id => $data) {
            if ($data['composite_score'] === null) {
                continue;
            }

            $info = $school_info[$school_id] ?? ['type' => 'public', 'level' => 'other', 'grades_low' => null, 'grades_high' => null];
            $type = $info['type'];
            $level = $info['level'];

            // Normalize type to public/private
            if ($type !== 'private') {
                $type = 'public';
            }

            // Determine level based on grade range for combined/other schools
            if (!in_array($level, ['elementary', 'middle', 'high'])) {
                $level = $this->determine_level_from_grades($info['grades_low'], $info['grades_high']);
            }

            $category = "{$type}_{$level}";
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][$school_id] = $data['composite_score'];

            // Store category in scores for reference
            $scores[$school_id]['category'] = $category;
        }

        // Calculate percentile and state_rank within each category
        foreach ($categories as $category => $category_scores) {
            // Sort by composite score (ascending for percentile calculation)
            asort($category_scores);

            // Calculate percentile for each school in this category
            $total = count($category_scores);
            $rank = 0;

            foreach ($category_scores as $school_id => $composite) {
                $rank++;
                // Percentile = (rank / total) * 100
                $percentile = round(($rank / $total) * 100);
                $scores[$school_id]['percentile_rank'] = $percentile;
            }

            // Now calculate state_rank within category (1 = best, descending order)
            arsort($category_scores);
            $state_rank = 0;

            foreach ($category_scores as $school_id => $composite) {
                $state_rank++;
                $scores[$school_id]['state_rank'] = $state_rank;
            }
        }
    }

    /**
     * Determine school level category based on grade range.
     *
     * Uses the highest grade served to categorize:
     * - Ends at grade 5 or 6 → elementary
     * - Ends at grade 7 or 8 → middle
     * - Ends at grade 9-12 → high
     *
     * @param string|null $grades_low Lowest grade (e.g., 'PK', 'K', '01', '06')
     * @param string|null $grades_high Highest grade (e.g., '05', '08', '12')
     * @return string Level category: 'elementary', 'middle', 'high', or 'other'
     */
    private function determine_level_from_grades($grades_low, $grades_high) {
        if (empty($grades_high)) {
            return 'other';
        }

        // Convert grade to numeric value
        $high_grade = $this->grade_to_number($grades_high);

        if ($high_grade === null) {
            return 'other';
        }

        // Categorize based on highest grade
        if ($high_grade <= 6) {
            return 'elementary';
        } elseif ($high_grade <= 8) {
            return 'middle';
        } elseif ($high_grade <= 12) {
            return 'high';
        }

        return 'other';
    }

    /**
     * Convert grade string to numeric value.
     *
     * @param string $grade Grade string (e.g., 'PK', 'K', '01', '05', '12')
     * @return int|null Numeric grade or null if not parseable
     */
    private function grade_to_number($grade) {
        if (empty($grade)) {
            return null;
        }

        $grade = strtoupper(trim($grade));

        // Handle special cases
        if ($grade === 'PK' || $grade === 'P' || $grade === 'PRE-K') {
            return -1;
        }
        if ($grade === 'K' || $grade === 'KG') {
            return 0;
        }

        // Handle numeric grades (01, 02, ... 12)
        if (is_numeric($grade)) {
            return (int) $grade;
        }

        // Handle grades like "1st", "2nd", etc.
        if (preg_match('/^(\d+)/', $grade, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Store ranking in database.
     *
     * @param int $school_id School ID.
     * @param array $score_data Score data from calculate_school_score().
     */
    private function store_ranking($school_id, $score_data) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_rankings';

        // Prepare data
        $data = [
            'school_id' => $school_id,
            'year' => $this->year,
            'category' => $score_data['category'] ?? null,
            'composite_score' => $score_data['composite_score'],
            'percentile_rank' => $score_data['percentile_rank'],
            'state_rank' => $score_data['state_rank'],
            'mcas_score' => $score_data['components']['mcas_proficiency'],
            'graduation_score' => $score_data['components']['graduation_rate'],
            'masscore_score' => $score_data['components']['masscore'],
            'attendance_score' => $score_data['components']['attendance'],
            'ap_score' => $score_data['components']['ap_performance'],
            'growth_score' => $score_data['components']['mcas_growth'],
            'spending_score' => $score_data['components']['per_pupil'],
            'ratio_score' => $score_data['components']['ratio'],
            'calculated_at' => current_time('mysql'),
        ];

        // Check if ranking already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE school_id = %d AND year = %d",
            $school_id, $this->year
        ));

        if ($exists) {
            $wpdb->update($table, $data, ['id' => $exists]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    /**
     * Get letter grade from composite score (absolute thresholds - DEPRECATED).
     *
     * @param float $score Composite score (0-100).
     * @return string Letter grade (A+, A, A-, B+, etc.).
     * @deprecated Use get_letter_grade_from_percentile() instead for fairer grading.
     */
    public static function get_letter_grade($score) {
        if ($score === null) {
            return 'N/A';
        }

        if ($score >= 97) return 'A+';
        if ($score >= 93) return 'A';
        if ($score >= 90) return 'A-';
        if ($score >= 87) return 'B+';
        if ($score >= 83) return 'B';
        if ($score >= 80) return 'B-';
        if ($score >= 77) return 'C+';
        if ($score >= 73) return 'C';
        if ($score >= 70) return 'C-';
        if ($score >= 67) return 'D+';
        if ($score >= 63) return 'D';
        if ($score >= 60) return 'D-';
        return 'F';
    }

    /**
     * Get letter grade based on percentile rank (relative to other schools).
     *
     * This grading system compares schools to each other rather than using
     * absolute score thresholds. A school in the top 10% gets an A+ regardless
     * of the raw score.
     *
     * Distribution:
     * - A+ : Top 10% (percentile >= 90)
     * - A  : Next 10% (percentile 80-89)
     * - A- : Next 10% (percentile 70-79)
     * - B+ : Next 10% (percentile 60-69)
     * - B  : Next 10% (percentile 50-59)
     * - B- : Next 10% (percentile 40-49)
     * - C+ : Next 10% (percentile 30-39)
     * - C  : Next 10% (percentile 20-29)
     * - C- : Next 10% (percentile 10-19)
     * - D  : Next 5%  (percentile 5-9)
     * - F  : Bottom 5% (percentile < 5)
     *
     * @param int $percentile_rank Percentile rank (0-100, higher is better).
     * @return string Letter grade (A+, A, A-, B+, etc.).
     */
    public static function get_letter_grade_from_percentile($percentile_rank) {
        if ($percentile_rank === null) {
            return 'N/A';
        }

        if ($percentile_rank >= 90) return 'A+';
        if ($percentile_rank >= 80) return 'A';
        if ($percentile_rank >= 70) return 'A-';
        if ($percentile_rank >= 60) return 'B+';
        if ($percentile_rank >= 50) return 'B';
        if ($percentile_rank >= 40) return 'B-';
        if ($percentile_rank >= 30) return 'C+';
        if ($percentile_rank >= 20) return 'C';
        if ($percentile_rank >= 10) return 'C-';
        if ($percentile_rank >= 5) return 'D';
        return 'F';
    }

    /**
     * Get ranking for a specific school.
     *
     * @param int $school_id School ID.
     * @param int|null $year Year (defaults to current).
     * @return object|null Ranking data or null if not found.
     */
    public static function get_school_ranking($school_id, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $table = $wpdb->prefix . 'bmn_school_rankings';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE school_id = %d AND year = %d",
            $school_id, $year
        ));
    }

    /**
     * Get top schools by composite score.
     *
     * @param int $limit Number of schools to return.
     * @param string|null $school_level Filter by level (elementary, middle, high).
     * @param int|null $year Year (defaults to current).
     * @return array Array of school rankings.
     */
    public static function get_top_schools($limit = 10, $school_level = null, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
        $schools_table = $wpdb->prefix . 'bmn_schools';

        $sql = "SELECT r.*, s.name, s.city, s.level
                FROM {$rankings_table} r
                JOIN {$schools_table} s ON r.school_id = s.id
                WHERE r.year = %d
                AND r.composite_score IS NOT NULL";

        $params = [$year];

        if ($school_level) {
            $sql .= " AND s.level = %s";
            $params[] = $school_level;
        }

        $sql .= " ORDER BY r.composite_score DESC LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Get schools above a minimum score threshold.
     *
     * @param float $min_score Minimum composite score.
     * @param int|null $year Year (defaults to current).
     * @return array Array of school IDs.
     */
    public static function get_schools_by_min_score($min_score, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $table = $wpdb->prefix . 'bmn_school_rankings';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT school_id FROM {$table}
             WHERE year = %d AND composite_score >= %f
             ORDER BY composite_score DESC",
            $year, $min_score
        ));
    }

    /**
     * Calculate and store state-wide benchmarks for comparison.
     *
     * Computes averages, medians, and percentiles for various metrics
     * broken down by school category (elementary, middle, high).
     *
     * @param int|null $year Year to calculate for. Defaults to current year.
     * @return array Results with benchmark counts.
     */
    public function calculate_state_benchmarks($year = null) {
        global $wpdb;

        $year = $year ?: $this->year;
        $benchmarks_table = $wpdb->prefix . 'bmn_state_benchmarks';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
        $schools_table = $wpdb->prefix . 'bmn_schools';
        $test_scores_table = $wpdb->prefix . 'bmn_school_test_scores';

        $results = ['year' => $year, 'benchmarks_created' => 0];

        // Categories to calculate benchmarks for
        $categories = ['all', 'elementary', 'middle', 'high'];

        foreach ($categories as $category) {
            // Build category filter
            $category_where = '';
            if ($category !== 'all') {
                $category_where = $wpdb->prepare(" AND s.level = %s", $category);
            }

            // Calculate composite score benchmarks
            $scores = $wpdb->get_col($wpdb->prepare(
                "SELECT r.composite_score
                 FROM {$rankings_table} r
                 JOIN {$schools_table} s ON r.school_id = s.id
                 WHERE r.year = %d AND r.composite_score IS NOT NULL {$category_where}
                 ORDER BY r.composite_score ASC",
                $year
            ));

            if (count($scores) > 0) {
                $this->store_benchmark($year, 'composite_score', $category, null, $scores);
                $results['benchmarks_created']++;
            }

            // Calculate MCAS benchmarks by subject
            foreach (['ELA', 'Math'] as $subject) {
                $mcas_scores = $wpdb->get_col($wpdb->prepare(
                    "SELECT AVG(ts.proficient_or_above_pct) as avg_score
                     FROM {$test_scores_table} ts
                     JOIN {$schools_table} s ON ts.school_id = s.id
                     WHERE ts.year = %d AND ts.subject = %s
                     AND ts.proficient_or_above_pct IS NOT NULL {$category_where}
                     GROUP BY ts.school_id
                     ORDER BY avg_score ASC",
                    $year, $subject
                ));

                if (count($mcas_scores) > 0) {
                    $this->store_benchmark($year, 'mcas', $category, $subject, $mcas_scores);
                    $results['benchmarks_created']++;
                }
            }
        }

        // Log calculation
        if (class_exists('BMN_Schools_Logger')) {
            BMN_Schools_Logger::log('info', 'benchmark', "Calculated state benchmarks for {$year}", $results);
        }

        return $results;
    }

    /**
     * Store a benchmark record.
     *
     * @param int $year Year.
     * @param string $metric_type Type of metric.
     * @param string $category School category.
     * @param string|null $subject Subject for MCAS.
     * @param array $values Sorted array of values.
     */
    private function store_benchmark($year, $metric_type, $category, $subject, $values) {
        global $wpdb;

        $benchmarks_table = $wpdb->prefix . 'bmn_state_benchmarks';
        $count = count($values);

        if ($count === 0) {
            return;
        }

        // Calculate statistics
        $average = array_sum($values) / $count;
        $median = $this->calculate_percentile($values, 50);
        $p25 = $this->calculate_percentile($values, 25);
        $p75 = $this->calculate_percentile($values, 75);

        // Upsert benchmark record
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$benchmarks_table}
             (year, metric_type, category, subject, state_average, state_median, percentile_25, percentile_75, sample_size)
             VALUES (%d, %s, %s, %s, %f, %f, %f, %f, %d)
             ON DUPLICATE KEY UPDATE
             state_average = VALUES(state_average),
             state_median = VALUES(state_median),
             percentile_25 = VALUES(percentile_25),
             percentile_75 = VALUES(percentile_75),
             sample_size = VALUES(sample_size),
             updated_at = NOW()",
            $year, $metric_type, $category, $subject, $average, $median, $p25, $p75, $count
        ));
    }

    /**
     * Calculate percentile value from sorted array.
     *
     * @param array $sorted_values Sorted array of values.
     * @param int $percentile Percentile to calculate (0-100).
     * @return float Percentile value.
     */
    private function calculate_percentile($sorted_values, $percentile) {
        $count = count($sorted_values);
        if ($count === 0) {
            return 0;
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper || $upper >= $count) {
            return $sorted_values[$lower];
        }

        return $sorted_values[$lower] + $fraction * ($sorted_values[$upper] - $sorted_values[$lower]);
    }

    /**
     * Calculate and store district rankings.
     *
     * Aggregates school scores by district and calculates district-level rankings.
     * Districts must have data for at least 2 school levels to be ranked.
     * Combined schools are mapped to appropriate levels based on grade range.
     *
     * @param int|null $year Year to calculate for.
     * @return array Results with district counts.
     */
    public function calculate_district_rankings($year = null) {
        global $wpdb;

        $year = $year ?: $this->year;
        $district_rankings_table = $wpdb->prefix . 'bmn_district_rankings';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';
        $schools_table = $wpdb->prefix . 'bmn_schools';
        $districts_table = $wpdb->prefix . 'bmn_school_districts';

        $results = [
            'year' => $year,
            'districts_ranked' => 0,
            'districts_skipped_no_data' => 0,
            'districts_skipped_incomplete' => 0,
            'districts_removed' => 0,
        ];

        // Track districts that no longer qualify (to remove stale rankings)
        $skipped_district_ids = [];

        // Get all districts with schools
        $districts = $wpdb->get_results(
            "SELECT DISTINCT d.id, d.name
             FROM {$districts_table} d
             JOIN {$schools_table} s ON s.district_id = d.id
             WHERE s.district_id IS NOT NULL"
        );

        $district_scores = [];

        $demographics_table = $wpdb->prefix . 'bmn_school_demographics';

        foreach ($districts as $district) {
            // Get all PUBLIC school scores for this district with grade info
            // Private schools should not affect district ratings
            // Only include schools WITH MCAS data (required for ranking)
            // Also get school_id for enrollment lookup
            $school_data = $wpdb->get_results($wpdb->prepare(
                "SELECT r.composite_score, r.school_id, s.level, s.grades_low, s.grades_high, s.name
                 FROM {$rankings_table} r
                 JOIN {$schools_table} s ON r.school_id = s.id
                 WHERE s.district_id = %d
                 AND r.year = %d
                 AND r.composite_score IS NOT NULL
                 AND r.mcas_score IS NOT NULL
                 AND s.school_type = 'public'",
                $district->id, $year
            ));

            if (count($school_data) === 0) {
                $results['districts_skipped_no_data']++;
                $skipped_district_ids[] = $district->id;
                continue;
            }

            // Initialize score arrays and weighted calculation variables
            $elementary_scores = [];
            $middle_scores = [];
            $high_scores = [];
            $all_scores = [];

            // Variables for enrollment-weighted averaging
            $weighted_sum = 0;
            $total_enrollment = 0;

            // Process each school and assign to appropriate level(s)
            foreach ($school_data as $school) {
                $score = (float) $school->composite_score;
                $all_scores[] = $score;

                // Get school enrollment for weighting
                $school_enrollment = $this->get_school_enrollment($school->school_id);
                $enrollment = max(100, (int) ($school_enrollment ?: 200)); // Minimum 100 to prevent division issues

                // Add to weighted sum
                $weighted_sum += $score * $enrollment;
                $total_enrollment += $enrollment;

                // Determine which levels this school serves
                $levels = $this->determine_school_levels($school->level, $school->grades_low, $school->grades_high, $school->name);

                // Add score to each applicable level
                if (in_array('elementary', $levels)) {
                    $elementary_scores[] = $score;
                }
                if (in_array('middle', $levels)) {
                    $middle_scores[] = $score;
                }
                if (in_array('high', $levels)) {
                    $high_scores[] = $score;
                }
            }

            // Calculate how many distinct levels have data
            $levels_with_data = 0;
            if (count($elementary_scores) > 0) $levels_with_data++;
            if (count($middle_scores) > 0) $levels_with_data++;
            if (count($high_scores) > 0) $levels_with_data++;

            // REQUIREMENT: District must have meaningful data
            // At least 3 schools OR at least 500 total enrollment
            $distinct_schools = count($school_data);
            $district_enrollment = $this->get_district_enrollment($district->id) ?: 0;

            if ($distinct_schools < 3 && $district_enrollment < 500) {
                $results['districts_skipped_incomplete']++;
                $skipped_district_ids[] = $district->id;
                continue;
            }

            // Also require at least 2 levels for meaningful comparison
            if ($levels_with_data < 2) {
                $results['districts_skipped_incomplete']++;
                $skipped_district_ids[] = $district->id;
                continue;
            }

            // Use enrollment-weighted average instead of simple average
            $district_composite = $total_enrollment > 0
                ? $weighted_sum / $total_enrollment
                : array_sum($all_scores) / count($all_scores);

            // Apply district-level reliability factor
            $reliability_factor = $this->get_enrollment_reliability_factor($district_enrollment);
            $district_composite *= $reliability_factor;

            $district_scores[$district->id] = [
                'composite_score' => $district_composite,
                'schools_count' => count($all_scores),
                'levels_with_data' => $levels_with_data,
                'elementary_avg' => count($elementary_scores) > 0 ? array_sum($elementary_scores) / count($elementary_scores) : null,
                'middle_avg' => count($middle_scores) > 0 ? array_sum($middle_scores) / count($middle_scores) : null,
                'high_avg' => count($high_scores) > 0 ? array_sum($high_scores) / count($high_scores) : null,
                'enrollment' => $district_enrollment,
                'reliability_factor' => $reliability_factor,
            ];
        }

        // Sort by composite score to calculate ranks
        uasort($district_scores, fn($a, $b) => ($b['composite_score'] ?? 0) <=> ($a['composite_score'] ?? 0));

        // Calculate percentile ranks and store
        $total_districts = count($district_scores);
        $rank = 0;

        foreach ($district_scores as $district_id => $data) {
            $rank++;
            $percentile = round(100 - (($rank - 1) / max(1, $total_districts - 1)) * 100);
            $letter_grade = self::get_letter_grade_from_percentile($percentile);

            // Upsert district ranking
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$district_rankings_table}
                 (district_id, year, composite_score, percentile_rank, state_rank, letter_grade,
                  schools_count, schools_with_data, elementary_avg, middle_avg, high_avg)
                 VALUES (%d, %d, %f, %d, %d, %s, %d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                 composite_score = VALUES(composite_score),
                 percentile_rank = VALUES(percentile_rank),
                 state_rank = VALUES(state_rank),
                 letter_grade = VALUES(letter_grade),
                 schools_count = VALUES(schools_count),
                 schools_with_data = VALUES(schools_with_data),
                 elementary_avg = VALUES(elementary_avg),
                 middle_avg = VALUES(middle_avg),
                 high_avg = VALUES(high_avg),
                 updated_at = NOW()",
                $district_id, $year, $data['composite_score'], $percentile, $rank, $letter_grade,
                $data['schools_count'], $data['schools_count'],
                $data['elementary_avg'], $data['middle_avg'], $data['high_avg']
            ));

            $results['districts_ranked']++;
        }

        // Remove stale rankings for districts that no longer qualify
        if (!empty($skipped_district_ids)) {
            $placeholders = implode(',', array_fill(0, count($skipped_district_ids), '%d'));
            $query_args = array_merge($skipped_district_ids, [$year]);
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$district_rankings_table} WHERE district_id IN ({$placeholders}) AND year = %d",
                ...$query_args
            ));
            $results['districts_removed'] = $deleted;
        }

        // Log calculation
        if (class_exists('BMN_Schools_Logger')) {
            BMN_Schools_Logger::log('info', 'ranking', "Calculated district rankings for {$year}", $results);
        }

        return $results;
    }

    /**
     * Determine which school levels a school serves based on level field and grade range.
     *
     * Maps "combined" schools to appropriate levels:
     * - PK-05, PK-06, K-05, K-06 → elementary
     * - 06-08, 07-08 → middle
     * - 09-12 → high
     * - PK-08, K-08 → elementary AND middle
     * - PK-12, K-12 → all levels
     *
     * @param string $level School level field (elementary, middle, high, combined, other)
     * @param string $grades_low Lowest grade (e.g., 'PK', 'K', '01', '06')
     * @param string $grades_high Highest grade (e.g., '05', '08', '12')
     * @param string $name School name (for fallback detection)
     * @return array Array of levels this school serves
     */
    private function determine_school_levels($level, $grades_low, $grades_high, $name = '') {
        // If already properly categorized, use that
        if ($level === 'elementary') {
            return ['elementary'];
        }
        if ($level === 'middle') {
            return ['middle'];
        }
        if ($level === 'high') {
            return ['high'];
        }

        // For combined/other, determine from grade range
        $low = $this->grade_to_number($grades_low);
        $high = $this->grade_to_number($grades_high);

        if ($low === null || $high === null) {
            // Fallback: try to detect from name
            if (stripos($name, 'Elementary') !== false) {
                return ['elementary'];
            }
            if (stripos($name, 'Middle') !== false) {
                return ['middle'];
            }
            if (stripos($name, 'High School') !== false || stripos($name, 'Senior High') !== false) {
                return ['high'];
            }
            // Can't determine - don't count this school in level averages
            return [];
        }

        $levels = [];

        // Elementary: serves any grades PK-6
        if ($low <= 6 && $high >= 0) {
            $levels[] = 'elementary';
        }

        // Middle: serves any grades 6-8
        if ($low <= 8 && $high >= 6) {
            $levels[] = 'middle';
        }

        // High: serves any grades 9-12
        if ($high >= 9) {
            $levels[] = 'high';
        }

        return $levels;
    }

    /**
     * Get state benchmark for a specific metric.
     *
     * @param string $metric_type Metric type (composite_score, mcas).
     * @param string $category Category (all, elementary, middle, high).
     * @param string|null $subject Subject for MCAS.
     * @param int|null $year Year.
     * @return object|null Benchmark data.
     */
    public static function get_benchmark($metric_type, $category = 'all', $subject = null, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $table = $wpdb->prefix . 'bmn_state_benchmarks';

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE year = %d AND metric_type = %s AND category = %s",
            $year, $metric_type, $category
        );

        if ($subject) {
            $sql .= $wpdb->prepare(" AND subject = %s", $subject);
        } else {
            $sql .= " AND (subject IS NULL OR subject = '')";
        }

        return $wpdb->get_row($sql);
    }

    /**
     * Get district ranking.
     *
     * @param int $district_id District ID.
     * @param int|null $year Year.
     * @return object|null District ranking data.
     */
    public static function get_district_ranking($district_id, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $table = $wpdb->prefix . 'bmn_district_rankings';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE district_id = %d AND year = %d",
            $district_id, $year
        ));
    }

    /**
     * Generate plain-English highlights for a school.
     *
     * Creates an array of notable features and characteristics that make
     * the school stand out, suitable for display as highlight chips in the UI.
     *
     * @param int $school_id School ID.
     * @param int|null $year Year for data lookup.
     * @return array Array of highlight strings.
     */
    public static function generate_school_highlights($school_id, $year = null) {
        global $wpdb;

        $year = $year ?: (int) date('Y');
        $features_table = $wpdb->prefix . 'bmn_school_features';
        $demographics_table = $wpdb->prefix . 'bmn_school_demographics';
        $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

        $highlights = [];

        // Get school ranking data
        $ranking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$rankings_table} WHERE school_id = %d AND year = %d",
            $school_id, $year
        ));

        // 1. Strong AP Program (if pass rate > 70%)
        $ap_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'ap_summary'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($ap_data) {
            $ap = json_decode($ap_data, true);
            if (!empty($ap['pass_rate']) && $ap['pass_rate'] >= 70) {
                $highlights[] = [
                    'type' => 'ap',
                    'text' => 'Strong AP Program',
                    'detail' => round($ap['pass_rate']) . '% pass rate',
                    'icon' => 'star.fill',
                    'priority' => 1,
                ];
            } elseif (!empty($ap['total_courses']) && $ap['total_courses'] >= 10) {
                $highlights[] = [
                    'type' => 'ap',
                    'text' => 'Wide AP Selection',
                    'detail' => $ap['total_courses'] . ' AP courses',
                    'icon' => 'book.fill',
                    'priority' => 2,
                ];
            }
        }

        // 2. Low Student-Teacher Ratio (if < 15:1)
        $staffing_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'staffing'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($staffing_data) {
            $staffing = json_decode($staffing_data, true);
            if (!empty($staffing['student_teacher_ratio']) && $staffing['student_teacher_ratio'] < 15 && $staffing['student_teacher_ratio'] > 0) {
                $highlights[] = [
                    'type' => 'ratio',
                    'text' => 'Small Class Sizes',
                    'detail' => round($staffing['student_teacher_ratio'], 1) . ':1 ratio',
                    'icon' => 'person.2.fill',
                    'priority' => 2,
                ];
            }
        }

        // 3. Diverse Student Body (Simpson's Index > 0.5)
        $demographics = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$demographics_table}
             WHERE school_id = %d ORDER BY year DESC LIMIT 1",
            $school_id
        ));
        if ($demographics) {
            $percentages = [
                (float) ($demographics->pct_white ?? 0),
                (float) ($demographics->pct_black ?? 0),
                (float) ($demographics->pct_hispanic ?? 0),
                (float) ($demographics->pct_asian ?? 0),
                (float) ($demographics->pct_multiracial ?? 0),
            ];
            // Simpson's Diversity Index: 1 - sum(p^2)
            $sum_squares = 0;
            foreach ($percentages as $pct) {
                $proportion = $pct / 100;
                $sum_squares += $proportion * $proportion;
            }
            $diversity_index = 1 - $sum_squares;

            if ($diversity_index >= 0.6) {
                $highlights[] = [
                    'type' => 'diversity',
                    'text' => 'Diverse Student Body',
                    'detail' => null,
                    'icon' => 'globe.americas.fill',
                    'priority' => 3,
                ];
            }
        }

        // 4. CTE/Career Programs Available
        $pathways_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'pathways'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($pathways_data) {
            $pathways = json_decode($pathways_data, true);

            // Early College
            if (!empty($pathways['has_early_college']) && $pathways['has_early_college']) {
                $highlights[] = [
                    'type' => 'early_college',
                    'text' => 'Early College Program',
                    'detail' => null,
                    'icon' => 'graduationcap.fill',
                    'priority' => 1,
                ];
            }

            // Innovation Pathway
            if (!empty($pathways['has_innovation_pathway']) && $pathways['has_innovation_pathway']) {
                $highlights[] = [
                    'type' => 'innovation',
                    'text' => 'Innovation Pathway',
                    'detail' => null,
                    'icon' => 'lightbulb.fill',
                    'priority' => 2,
                ];
            }

            // CTE Programs
            if (!empty($pathways['has_cte']) && $pathways['has_cte']) {
                $cte_count = !empty($pathways['cte_programs']) ? count($pathways['cte_programs']) : 0;
                $highlights[] = [
                    'type' => 'cte',
                    'text' => 'Career Tech Programs',
                    'detail' => $cte_count > 0 ? $cte_count . ' programs' : null,
                    'icon' => 'wrench.and.screwdriver.fill',
                    'priority' => 2,
                ];
            }
        }

        // 5. High Graduation Rate (if > 95%)
        $graduation_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'graduation'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($graduation_data) {
            $graduation = json_decode($graduation_data, true);
            $rate = $graduation['graduation_rate'] ?? $graduation['rate'] ?? null;
            if ($rate !== null && $rate >= 95) {
                $highlights[] = [
                    'type' => 'graduation',
                    'text' => 'High Graduation Rate',
                    'detail' => round($rate) . '%',
                    'icon' => 'checkmark.seal.fill',
                    'priority' => 1,
                ];
            }
        }

        // 6. Strong Attendance (if chronic absence < 10%)
        $attendance_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'attendance'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($attendance_data) {
            $attendance = json_decode($attendance_data, true);
            $chronic_absence = $attendance['chronic_absence_rate'] ?? null;
            if ($chronic_absence !== null && $chronic_absence < 10) {
                $highlights[] = [
                    'type' => 'attendance',
                    'text' => 'Strong Attendance',
                    'detail' => 'Low absenteeism',
                    'icon' => 'calendar.badge.checkmark',
                    'priority' => 3,
                ];
            }
        }

        // 7. High MassCore Completion (if > 85%)
        $masscore_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'masscore'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($masscore_data) {
            $masscore = json_decode($masscore_data, true);
            $pct = $masscore['masscore_pct'] ?? $masscore['pct_complete'] ?? null;
            if ($pct !== null && $pct >= 85) {
                $highlights[] = [
                    'type' => 'masscore',
                    'text' => 'College Ready Curriculum',
                    'detail' => round($pct) . '% MassCore',
                    'icon' => 'text.book.closed.fill',
                    'priority' => 2,
                ];
            }
        }

        // 8. Improving School (if rank improved significantly from last year)
        if ($ranking && !empty($ranking->state_rank)) {
            $prev_ranking = $wpdb->get_row($wpdb->prepare(
                "SELECT state_rank FROM {$rankings_table}
                 WHERE school_id = %d AND year = %d",
                $school_id, $year - 1
            ));
            if ($prev_ranking && !empty($prev_ranking->state_rank)) {
                $improvement = $prev_ranking->state_rank - $ranking->state_rank;
                if ($improvement >= 20) {
                    $highlights[] = [
                        'type' => 'improving',
                        'text' => 'Rising School',
                        'detail' => '+' . $improvement . ' spots',
                        'icon' => 'arrow.up.right',
                        'priority' => 1,
                    ];
                }
            }
        }

        // 9. Above Average Resources (high per-pupil spending)
        $spending_data = $wpdb->get_var($wpdb->prepare(
            "SELECT feature_value FROM {$features_table}
             WHERE school_id = %d AND feature_type = 'expenditure'
             ORDER BY id DESC LIMIT 1",
            $school_id
        ));
        if ($spending_data) {
            $spending = json_decode($spending_data, true);
            $per_pupil = $spending['per_pupil_total'] ?? $spending['per_pupil_instruction'] ?? null;
            // MA average is around $18,000, well-funded schools are $22,000+
            if ($per_pupil !== null && $per_pupil >= 22000) {
                $highlights[] = [
                    'type' => 'resources',
                    'text' => 'Well Resourced',
                    'detail' => '$' . number_format($per_pupil / 1000, 0) . 'K per student',
                    'icon' => 'dollarsign.circle.fill',
                    'priority' => 3,
                ];
            }
        }

        // 10. Low Discipline Rate (bottom 25% of discipline incidents)
        $discipline_table = $wpdb->prefix . 'bmn_school_discipline';
        $discipline = $wpdb->get_row($wpdb->prepare(
            "SELECT discipline_rate FROM {$discipline_table}
             WHERE school_id = %d
             ORDER BY year DESC LIMIT 1",
            $school_id
        ));
        if ($discipline && $discipline->discipline_rate !== null) {
            // Get the 25th percentile threshold for "low" discipline
            $threshold = $wpdb->get_var(
                "SELECT discipline_rate FROM {$discipline_table}
                 WHERE discipline_rate IS NOT NULL
                 ORDER BY discipline_rate ASC
                 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.25) FROM {$discipline_table} WHERE discipline_rate IS NOT NULL)"
            );
            if ($threshold !== null && $discipline->discipline_rate <= (float) $threshold) {
                $highlights[] = [
                    'type' => 'discipline',
                    'text' => 'Low Discipline Rate',
                    'detail' => round($discipline->discipline_rate, 1) . '% incidents',
                    'icon' => 'hand.raised.fill',
                    'priority' => 2,
                ];
            }
        }

        // 11. Strong Athletics (15+ sports programs)
        $sports_table = $wpdb->prefix . 'bmn_school_sports';
        $sports_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT sport) FROM {$sports_table}
             WHERE school_id = %d
             AND year = (SELECT MAX(year) FROM {$sports_table} WHERE school_id = %d)",
            $school_id, $school_id
        ));
        if ($sports_count !== null && (int) $sports_count >= 15) {
            $highlights[] = [
                'type' => 'athletics',
                'text' => 'Strong Athletics',
                'detail' => $sports_count . ' sports',
                'icon' => 'sportscourt.fill',
                'priority' => 2,
            ];
        }

        // Sort by priority (lower = higher priority)
        usort($highlights, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        // Limit to top 4 highlights
        return array_slice($highlights, 0, 4);
    }

    /**
     * Get highlights for a school (cached).
     *
     * @param int $school_id School ID.
     * @param int|null $year Year.
     * @return array Array of highlight data.
     */
    public static function get_school_highlights($school_id, $year = null) {
        $cache_key = "school_highlights_{$school_id}_{$year}";
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $highlights = self::generate_school_highlights($school_id, $year);
        set_transient($cache_key, $highlights, HOUR_IN_SECONDS);

        return $highlights;
    }
}
