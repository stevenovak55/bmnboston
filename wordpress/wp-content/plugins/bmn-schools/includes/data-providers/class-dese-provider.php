<?php
/**
 * MA DESE Data Provider
 *
 * Imports data from Massachusetts Department of Elementary and Secondary Education.
 * Uses the E2C Hub Socrata API for MCAS scores and enrollment data.
 *
 * Data Sources:
 * - MCAS Achievement Results: https://educationtocareer.data.mass.gov/resource/i9w6-niyt
 * - Enrollment Demographics: https://educationtocareer.data.mass.gov/resource/t8td-gens
 *
 * @package BMN_Schools
 * @since 0.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/data-providers/class-base-provider.php';

/**
 * MA DESE Data Provider Class
 *
 * @since 0.2.0
 */
class BMN_Schools_DESE_Provider extends BMN_Schools_Base_Provider {

    /**
     * Provider name.
     *
     * @var string
     */
    protected $provider_name = 'ma_dese';

    /**
     * Display name.
     *
     * @var string
     */
    protected $display_name = 'MA DESE / MCAS';

    /**
     * E2C Hub API base URL.
     *
     * @var string
     */
    private $api_base = 'https://educationtocareer.data.mass.gov/resource/';

    /**
     * MCAS Achievement Results dataset ID.
     *
     * @var string
     */
    private $mcas_dataset = 'i9w6-niyt';

    /**
     * Enrollment Demographics dataset ID.
     *
     * @var string
     */
    private $enrollment_dataset = 't8td-gens';

    /**
     * Rate limit delay (Socrata allows 1000 requests/hour for unauthenticated).
     *
     * @var int
     */
    protected $request_delay = 250000; // 250ms

    /**
     * Run the data sync/import.
     *
     * @since 0.2.0
     * @param array $options Import options.
     * @return array Result with success status and message.
     */
    public function sync($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => [], // Empty = all available years
            'org_types' => ['Public School', 'Charter School'],
            'student_group' => 'All Students',
            'limit' => 0, // 0 = no limit
        ];

        $options = wp_parse_args($options, $defaults);

        $this->update_source_status('syncing');
        BMN_Schools_Logger::import_started($this->provider_name);

        try {
            // Get available years
            $years = $options['years'];
            if (empty($years)) {
                $years = $this->get_available_years();
                $this->log_progress('Found years: ' . implode(', ', $years));
            }

            $total_imported = 0;

            foreach ($options['org_types'] as $org_type) {
                foreach ($years as $year) {
                    $this->log_progress("Importing MCAS data for {$org_type} - {$year}...");

                    $count = $this->import_mcas_for_year($year, $org_type, $options['student_group']);
                    $total_imported += $count;

                    $this->log_progress("Imported {$count} MCAS records for {$org_type} - {$year}");
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $this->update_source_status('active', $total_imported);
            BMN_Schools_Logger::import_completed($this->provider_name, $total_imported, $duration_ms);

            return [
                'success' => true,
                'message' => sprintf('Imported %d MCAS test score records', $total_imported),
                'count' => $total_imported,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->update_source_status('error', 0, $e->getMessage());
            $this->log_error($e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'count' => 0,
            ];
        }
    }

    /**
     * Get available years from the API.
     *
     * @since 0.2.0
     * @return array Available years.
     */
    private function get_available_years() {
        $url = $this->api_base . $this->mcas_dataset . '.json?$select=distinct%20sy&$order=sy%20DESC';

        $response = $this->http_get($url);

        if (is_wp_error($response)) {
            return ['2025', '2024', '2023']; // Fallback
        }

        $data = $this->parse_json($response);

        if (is_wp_error($data)) {
            return ['2025', '2024', '2023'];
        }

        $years = [];
        foreach ($data as $row) {
            if (!empty($row['sy'])) {
                $years[] = $row['sy'];
            }
        }

        return $years;
    }

    /**
     * Import MCAS data for a specific year.
     *
     * @since 0.2.0
     * @param string $year         School year (e.g., '2025').
     * @param string $org_type     Organization type (e.g., 'Public School').
     * @param string $student_group Student group filter.
     * @return int Number of records imported.
     */
    private function import_mcas_for_year($year, $org_type, $student_group) {
        $imported = 0;
        $offset = 0;
        $limit = 1000; // Socrata page size

        while (true) {
            $url = $this->build_mcas_url($year, $org_type, $student_group, $limit, $offset);

            $response = $this->http_get($url);

            if (is_wp_error($response)) {
                $this->log_error('API request failed: ' . $response->get_error_message());
                break;
            }

            $data = $this->parse_json($response);

            if (is_wp_error($data) || empty($data)) {
                break;
            }

            foreach ($data as $row) {
                $result = $this->import_mcas_record($row);
                if ($result) {
                    $imported++;
                }
            }

            if (count($data) < $limit) {
                break; // Last page
            }

            $offset += $limit;
        }

        return $imported;
    }

    /**
     * Build MCAS API URL with filters.
     *
     * @since 0.2.0
     * @param string $year         School year.
     * @param string $org_type     Organization type.
     * @param string $student_group Student group.
     * @param int    $limit        Results limit.
     * @param int    $offset       Results offset.
     * @return string API URL.
     */
    private function build_mcas_url($year, $org_type, $student_group, $limit, $offset) {
        $params = [
            '$where' => sprintf(
                "sy='%s' AND org_type='%s' AND stu_grp='%s'",
                $year,
                $org_type,
                $student_group
            ),
            '$limit' => $limit,
            '$offset' => $offset,
            '$order' => 'org_code,subject_code,test_grade',
        ];

        return $this->api_base . $this->mcas_dataset . '.json?' . http_build_query($params);
    }

    /**
     * Import a single MCAS record.
     *
     * @since 0.2.0
     * @param array $row Raw API data.
     * @return bool Success.
     */
    private function import_mcas_record($row) {
        // Get or create the school
        $school_id = $this->get_or_create_school($row);

        if (!$school_id) {
            return false;
        }

        // Map the MCAS data
        $test_data = [
            'school_id' => $school_id,
            'year' => $this->map_year($row['sy']),
            'grade' => $row['test_grade'] ?? null,
            'subject' => $this->map_subject($row['subject_code'] ?? ''),
            'test_name' => 'MCAS',
            'students_tested' => !empty($row['stu_cnt']) ? intval($row['stu_cnt']) : null,
            'proficient_or_above_pct' => !empty($row['m_plus_e_pct']) ? floatval($row['m_plus_e_pct']) * 100 : null,
            'advanced_pct' => !empty($row['e_pct']) ? floatval($row['e_pct']) * 100 : null,
            'proficient_pct' => !empty($row['m_pct']) ? floatval($row['m_pct']) * 100 : null,
            'needs_improvement_pct' => !empty($row['pm_pct']) ? floatval($row['pm_pct']) * 100 : null,
            'warning_pct' => !empty($row['nm_pct']) ? floatval($row['nm_pct']) * 100 : null,
            'avg_scaled_score' => !empty($row['avg_scaled_score']) ? floatval($row['avg_scaled_score']) : null,
        ];

        return $this->upsert_test_score($test_data);
    }

    /**
     * Get or create a school from MCAS data.
     *
     * @since 0.2.0
     * @param array $row MCAS data row.
     * @return int|null School ID or null.
     */
    private function get_or_create_school($row) {
        if (empty($row['org_code']) || empty($row['org_name'])) {
            return null;
        }

        $table = $this->db->get_table('schools');

        // Check if school exists by state ID
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if ($school_id) {
            return $school_id;
        }

        // Create the school
        $school_data = [
            'state_school_id' => $row['org_code'],
            'name' => $row['org_name'],
            'school_type' => $this->map_org_type($row['org_type'] ?? 'Public School'),
            'state' => 'MA',
        ];

        // Add district info if available
        if (!empty($row['dist_code']) && !empty($row['dist_name'])) {
            $district_id = $this->get_or_create_district($row['dist_code'], $row['dist_name']);
            if ($district_id) {
                $school_data['district_id'] = $district_id;
            }
        }

        return $this->upsert_school($school_data);
    }

    /**
     * Get or create a district.
     *
     * @since 0.2.0
     * @param string $dist_code District code.
     * @param string $dist_name District name.
     * @return int|null District ID or null.
     */
    private function get_or_create_district($dist_code, $dist_name) {
        $table = $this->db->get_table('districts');

        // Check if district exists
        $district_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE state_district_id = %s",
            $dist_code
        ));

        if ($district_id) {
            return $district_id;
        }

        // Create the district
        return $this->upsert_district([
            'state_district_id' => $dist_code,
            'name' => $dist_name,
            'state' => 'MA',
        ]);
    }

    /**
     * Insert or update a test score record.
     *
     * @since 0.2.0
     * @param array $data Test score data.
     * @return bool Success.
     */
    private function upsert_test_score($data) {
        $table = $this->db->get_table('test_scores');

        // Check if record exists
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE school_id = %d AND year = %s AND grade = %s AND subject = %s",
            $data['school_id'],
            $data['year'],
            $data['grade'] ?? '',
            $data['subject']
        ));

        if ($existing_id) {
            $data['updated_at'] = current_time('mysql');
            $this->wpdb->update($table, $data, ['id' => $existing_id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($table, $data);
        }

        return true;
    }

    /**
     * Map school year to year number.
     *
     * @since 0.2.0
     * @param string $sy School year (e.g., '2025' means 2024-25).
     * @return string Year.
     */
    private function map_year($sy) {
        // DESE uses the end year (2025 = 2024-25 school year)
        return $sy;
    }

    /**
     * Map subject code to name.
     *
     * @since 0.2.0
     * @param string $code Subject code.
     * @return string Subject name.
     */
    private function map_subject($code) {
        $subjects = [
            'ELA' => 'English Language Arts',
            'MATH' => 'Mathematics',
            'SCI' => 'Science',
            'STE' => 'Science and Technology/Engineering',
        ];

        return isset($subjects[$code]) ? $subjects[$code] : $code;
    }

    /**
     * Map organization type to school type.
     *
     * @since 0.2.0
     * @param string $org_type Organization type.
     * @return string School type.
     */
    private function map_org_type($org_type) {
        if (strpos($org_type, 'Charter') !== false) {
            return 'charter';
        }
        return 'public';
    }

    /**
     * Import school enrollment data (demographics).
     *
     * Imports enrollment demographics from E2C Hub dataset t8td-gens.
     * Data includes: race/ethnicity, gender, free/reduced lunch, ELL, special ed.
     *
     * @since 0.5.2
     * @param array $options Import options.
     * @return array Result with success status and count.
     */
    public function import_demographics($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => [], // Empty = most recent year
            'org_types' => ['School'], // Enrollment data uses 'School' for all school-level data
            'limit' => 0, // 0 = no limit
        ];

        $options = wp_parse_args($options, $defaults);

        $this->log_progress('Starting demographics import...');

        try {
            // Get available years
            $years = $options['years'];
            if (empty($years)) {
                $years = $this->get_available_enrollment_years();
                $this->log_progress('Found enrollment years: ' . implode(', ', array_slice($years, 0, 5)));
                // Default to most recent 3 years
                $years = array_slice($years, 0, 3);
            }

            $total_imported = 0;
            $total_updated = 0;
            $total_skipped = 0;

            foreach ($years as $year) {
                foreach ($options['org_types'] as $org_type) {
                    $this->log_progress("Importing demographics for {$org_type} - {$year}...");

                    $result = $this->import_demographics_for_year($year, $org_type);
                    $total_imported += $result['imported'];
                    $total_updated += $result['updated'];
                    $total_skipped += $result['skipped'];

                    $this->log_progress("Year {$year} {$org_type}: {$result['imported']} imported, {$result['updated']} updated, {$result['skipped']} skipped");
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $message = sprintf(
                'Demographics import complete: %d imported, %d updated, %d skipped',
                $total_imported,
                $total_updated,
                $total_skipped
            );

            $this->log_progress($message);

            return [
                'success' => true,
                'message' => $message,
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Demographics import failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'imported' => 0,
            ];
        }
    }

    /**
     * Get available years from enrollment dataset.
     *
     * @since 0.5.2
     * @return array Available years.
     */
    private function get_available_enrollment_years() {
        $url = $this->api_base . $this->enrollment_dataset . '.json?$select=distinct%20sy&$order=sy%20DESC&$limit=20';

        $response = $this->http_get($url);

        if (is_wp_error($response)) {
            return ['2025', '2024', '2023']; // Fallback
        }

        $data = $this->parse_json($response);

        if (is_wp_error($data)) {
            return ['2025', '2024', '2023'];
        }

        $years = [];
        foreach ($data as $row) {
            if (!empty($row['sy'])) {
                $years[] = $row['sy'];
            }
        }

        return $years;
    }

    /**
     * Import demographics for a specific year.
     *
     * @since 0.5.2
     * @param string $year     School year (e.g., '2025').
     * @param string $org_type Organization type.
     * @return array Counts: imported, updated, skipped.
     */
    private function import_demographics_for_year($year, $org_type) {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $offset = 0;
        $limit = 1000; // Socrata page size

        while (true) {
            $url = $this->build_enrollment_url($year, $org_type, $limit, $offset);

            $response = $this->http_get($url);

            if (is_wp_error($response)) {
                $this->log_error('Enrollment API request failed: ' . $response->get_error_message());
                break;
            }

            $data = $this->parse_json($response);

            if (is_wp_error($data) || empty($data)) {
                break;
            }

            foreach ($data as $row) {
                $result = $this->import_demographics_record($row, $year);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            if (count($data) < $limit) {
                break; // Last page
            }

            $offset += $limit;
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Build enrollment API URL with filters.
     *
     * @since 0.5.2
     * @param string $year     School year.
     * @param string $org_type Organization type.
     * @param int    $limit    Results limit.
     * @param int    $offset   Results offset.
     * @return string API URL.
     */
    private function build_enrollment_url($year, $org_type, $limit, $offset) {
        $params = [
            '$where' => sprintf(
                "sy='%s' AND org_type='%s'",
                $year,
                $org_type
            ),
            '$limit' => $limit,
            '$offset' => $offset,
            '$order' => 'org_code',
        ];

        return $this->api_base . $this->enrollment_dataset . '.json?' . http_build_query($params);
    }

    /**
     * Import a single demographics record.
     *
     * @since 0.5.2
     * @param array  $row  Raw API data.
     * @param string $year School year.
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function import_demographics_record($row, $year) {
        // Find matching school by state_school_id (org_code)
        if (empty($row['org_code'])) {
            return 'skipped';
        }

        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) {
            // School not in our database
            return 'skipped';
        }

        // Map the demographics data
        // API fields: aian_pct, as_pct, baa_pct, hl_pct, mnhl_pct, nhpi_pct, wh_pct
        //             fe_pct, ma_pct, nb_pct
        //             el_pct (ELL), li_pct (low income), ecd_pct (economically disadvantaged), swd_pct (special ed)
        $demo_data = [
            'school_id' => $school_id,
            'year' => intval($year),
            'total_students' => !empty($row['total_cnt']) ? intval($row['total_cnt']) : null,
            'pct_male' => $this->parse_percentage($row['ma_pct'] ?? null),
            'pct_female' => $this->parse_percentage($row['fe_pct'] ?? null),
            'pct_white' => $this->parse_percentage($row['wh_pct'] ?? null),
            'pct_black' => $this->parse_percentage($row['baa_pct'] ?? null),
            'pct_hispanic' => $this->parse_percentage($row['hl_pct'] ?? null),
            'pct_asian' => $this->parse_percentage($row['as_pct'] ?? null),
            'pct_native_american' => $this->parse_percentage($row['aian_pct'] ?? null),
            'pct_pacific_islander' => $this->parse_percentage($row['nhpi_pct'] ?? null),
            'pct_multiracial' => $this->parse_percentage($row['mnhl_pct'] ?? null),
            // Use economically disadvantaged if available, fall back to low income
            'pct_free_reduced_lunch' => $this->parse_percentage($row['ecd_pct'] ?? $row['li_pct'] ?? null),
            'pct_english_learner' => $this->parse_percentage($row['el_pct'] ?? null),
            'pct_special_ed' => $this->parse_percentage($row['swd_pct'] ?? null),
        ];

        return $this->upsert_demographics($demo_data);
    }

    /**
     * Parse percentage value from API.
     *
     * API returns percentages as decimals (0.25 = 25%) or as whole numbers.
     *
     * @since 0.5.2
     * @param mixed $value Raw value.
     * @return float|null Percentage value.
     */
    private function parse_percentage($value) {
        if ($value === null || $value === '') {
            return null;
        }

        $float_val = floatval($value);

        // If value is between 0 and 1, it's a decimal - convert to percentage
        if ($float_val > 0 && $float_val <= 1) {
            return round($float_val * 100, 2);
        }

        // Otherwise it's already a percentage
        return round($float_val, 2);
    }

    /**
     * Insert or update a demographics record.
     *
     * @since 0.5.2
     * @param array $data Demographics data.
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function upsert_demographics($data) {
        $table = $this->db->get_table('demographics');

        // Check if record exists
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE school_id = %d AND year = %d",
            $data['school_id'],
            $data['year']
        ));

        if ($existing_id) {
            $data['updated_at'] = current_time('mysql');
            $this->wpdb->update($table, $data, ['id' => $existing_id]);
            return 'updated';
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($table, $data);
            return 'imported';
        }
    }

    /**
     * Import AP (Advanced Placement) course data.
     *
     * Imports AP course offerings and performance from E2C Hub dataset 787a-3wen.
     * Stores as features with type 'ap_course' and 'ap_summary'.
     *
     * @since 0.5.2
     * @param array $options Import options.
     * @return array Result with success status and count.
     */
    public function import_ap_data($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024'], // Most recent complete year
            'limit' => 0,
        ];

        $options = wp_parse_args($options, $defaults);

        $this->log_progress('Starting AP course data import...');

        try {
            $total_imported = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $schools_processed = 0;

            foreach ($options['years'] as $year) {
                $this->log_progress("Importing AP data for {$year}...");

                $result = $this->import_ap_for_year($year);
                $total_imported += $result['imported'];
                $total_updated += $result['updated'];
                $total_skipped += $result['skipped'];
                $schools_processed += $result['schools'];

                $this->log_progress("Year {$year}: {$result['schools']} schools, {$result['imported']} features imported");
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $message = sprintf(
                'AP import complete: %d schools, %d features imported, %d updated, %d skipped',
                $schools_processed,
                $total_imported,
                $total_updated,
                $total_skipped
            );

            $this->log_progress($message);

            return [
                'success' => true,
                'message' => $message,
                'schools' => $schools_processed,
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('AP import failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'imported' => 0,
            ];
        }
    }

    /**
     * Import AP data for a specific year.
     *
     * @since 0.5.2
     * @param string $year School year.
     * @return array Counts.
     */
    private function import_ap_for_year($year) {
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $schools = [];
        $offset = 0;
        $limit = 1000;

        // AP dataset ID
        $ap_dataset = '787a-3wen';

        while (true) {
            $params = [
                '$where' => "sy='{$year}' AND org_type='School' AND stu_grp='All Students'",
                '$limit' => $limit,
                '$offset' => $offset,
                '$order' => 'org_code,subj',
            ];

            $url = $this->api_base . $ap_dataset . '.json?' . http_build_query($params);
            $response = $this->http_get($url);

            if (is_wp_error($response)) {
                $this->log_error('AP API request failed: ' . $response->get_error_message());
                break;
            }

            $data = $this->parse_json($response);

            if (is_wp_error($data) || empty($data)) {
                break;
            }

            foreach ($data as $row) {
                $result = $this->import_ap_record($row, $year);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }

                // Track unique schools
                if (!empty($row['org_code']) && $result !== 'skipped') {
                    $schools[$row['org_code']] = true;
                }
            }

            if (count($data) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'schools' => count($schools),
        ];
    }

    /**
     * Import a single AP record as a feature.
     *
     * @since 0.5.2
     * @param array  $row  Raw API data.
     * @param string $year School year.
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function import_ap_record($row, $year) {
        if (empty($row['org_code']) || empty($row['subj'])) {
            return 'skipped';
        }

        // Find matching school
        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) {
            return 'skipped';
        }

        // Determine feature type and name
        $subject = $row['subj'];
        $is_summary = ($subject === 'All Subjects');

        $feature_type = $is_summary ? 'ap_summary' : 'ap_course';
        $feature_name = $is_summary ? "AP Program {$year}" : $subject;

        // Build feature value
        $feature_value = [
            'year' => intval($year),
            'tests_taken' => intval($row['tests_taken'] ?? 0),
        ];

        // Add score breakdown if available
        if (!empty($row['pct_3_5'])) {
            $feature_value['pass_rate'] = round(floatval($row['pct_3_5']) * 100, 1);
        }
        if (!empty($row['score_5'])) {
            $feature_value['score_5'] = intval($row['score_5']);
        }
        if (!empty($row['score_4'])) {
            $feature_value['score_4'] = intval($row['score_4']);
        }
        if (!empty($row['score_3'])) {
            $feature_value['score_3'] = intval($row['score_3']);
        }

        // For summary, count total courses
        if ($is_summary && !empty($row['subj_cat'])) {
            $feature_value['category'] = $row['subj_cat'];
        }

        return $this->upsert_feature($school_id, $feature_type, $feature_name, wp_json_encode($feature_value));
    }

    /**
     * Insert or update a feature record.
     *
     * @since 0.5.2
     * @param int    $school_id    School ID.
     * @param string $feature_type Feature type.
     * @param string $feature_name Feature name.
     * @param string $feature_value Feature value (JSON).
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function upsert_feature($school_id, $feature_type, $feature_name, $feature_value) {
        $table = $this->db->get_table('features');

        // Check if record exists
        $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE school_id = %d AND feature_type = %s AND feature_name = %s",
            $school_id,
            $feature_type,
            $feature_name
        ));

        $data = [
            'school_id' => $school_id,
            'feature_type' => $feature_type,
            'feature_name' => $feature_name,
            'feature_value' => $feature_value,
        ];

        if ($existing_id) {
            $this->wpdb->update($table, $data, ['id' => $existing_id]);
            return 'updated';
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($table, $data);
            return 'imported';
        }
    }

    /**
     * Import graduation rate data.
     *
     * Imports graduation rates from E2C Hub dataset n2xa-p822.
     * Stores 4-year and 5-year graduation rates, dropout rates.
     *
     * @since 0.5.2
     * @param array $options Import options.
     * @return array Result with success status and count.
     */
    public function import_graduation_rates($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024'],
            'rate_type' => '4-Year Adjusted Cohort Graduation Rate',
        ];

        $options = wp_parse_args($options, $defaults);
        $this->log_progress('Starting graduation rates import...');

        try {
            $total_imported = 0;
            $total_updated = 0;
            $total_skipped = 0;

            $grad_dataset = 'n2xa-p822';

            foreach ($options['years'] as $year) {
                $this->log_progress("Importing graduation rates for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND org_type='School' AND stu_grp='All Students' AND grad_rate_type='{$options['rate_type']}'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code',
                    ];

                    $url = $this->api_base . $grad_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $result = $this->import_graduation_record($row, $year);
                        if ($result === 'imported') $total_imported++;
                        elseif ($result === 'updated') $total_updated++;
                        else $total_skipped++;
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: processed");
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "Graduation rates: {$total_imported} imported, {$total_updated} updated",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Graduation rates import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import a single graduation rate record.
     */
    private function import_graduation_record($row, $year) {
        if (empty($row['org_code'])) return 'skipped';

        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) return 'skipped';

        $feature_value = [
            'year' => intval($year),
            'graduation_rate' => $this->parse_percentage($row['grad_pct'] ?? null),
            'dropout_rate' => $this->parse_percentage($row['drpout_pct'] ?? null),
            'still_in_school' => $this->parse_percentage($row['in_sch_pct'] ?? null),
            'ged_rate' => $this->parse_percentage($row['ged_pct'] ?? null),
            'cohort_count' => intval($row['cohort_cnt'] ?? 0),
        ];

        return $this->upsert_feature($school_id, 'graduation', "Graduation Rate {$year}", wp_json_encode($feature_value));
    }

    /**
     * Import student attendance data.
     *
     * Imports attendance rates from E2C Hub dataset ak6h-9k7x.
     * Stores attendance rate, chronic absence rate.
     *
     * @since 0.5.2
     * @param array $options Import options.
     * @return array Result with success status and count.
     */
    public function import_attendance($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2025'],
            'attend_period' => 'End of Year',
        ];

        $options = wp_parse_args($options, $defaults);
        $this->log_progress('Starting attendance data import...');

        try {
            $total_imported = 0;
            $total_updated = 0;
            $total_skipped = 0;

            $attend_dataset = 'ak6h-9k7x';

            foreach ($options['years'] as $year) {
                $this->log_progress("Importing attendance for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND org_type='School' AND stu_grp='All Students' AND attend_period='{$options['attend_period']}'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code',
                    ];

                    $url = $this->api_base . $attend_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $result = $this->import_attendance_record($row, $year);
                        if ($result === 'imported') $total_imported++;
                        elseif ($result === 'updated') $total_updated++;
                        else $total_skipped++;
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: processed");
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "Attendance: {$total_imported} imported, {$total_updated} updated",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Attendance import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import a single attendance record.
     */
    private function import_attendance_record($row, $year) {
        if (empty($row['org_code'])) return 'skipped';

        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) return 'skipped';

        $feature_value = [
            'year' => intval($year),
            'attendance_rate' => $this->parse_percentage($row['attend_rate'] ?? null),
            'chronic_absence_rate' => $this->parse_percentage($row['pct_chron_abs_10'] ?? null),
            'avg_absences' => floatval($row['cnt_avg_abs'] ?? 0),
            'pct_absent_10_days' => $this->parse_percentage($row['pct_abs_10_days'] ?? null),
        ];

        return $this->upsert_feature($school_id, 'attendance', "Attendance {$year}", wp_json_encode($feature_value));
    }

    /**
     * Import staffing data from E2C Hub.
     *
     * Dataset: j5ue-xkfn (Staff by job class with demographics)
     * Aggregates teacher FTE counts per school.
     *
     * @since 0.5.3
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_staffing($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => [2025],
        ];
        $options = wp_parse_args($options, $defaults);

        $staffing_dataset = 'j5ue-xkfn';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;

        try {
            // We'll aggregate by school: sum up teacher FTEs, total staff FTEs
            $school_staff = [];

            foreach ($options['years'] as $year) {
                $this->log_progress("Importing staffing for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND org_type='School'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code',
                    ];

                    $url = $this->api_base . $staffing_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $org_code = $row['org_code'] ?? '';
                        if (empty($org_code)) continue;

                        $key = "{$org_code}_{$year}";
                        if (!isset($school_staff[$key])) {
                            $school_staff[$key] = [
                                'org_code' => $org_code,
                                'year' => intval($year),
                                'teacher_fte' => 0.0,
                                'total_staff_fte' => 0.0,
                                'admin_fte' => 0.0,
                                'support_fte' => 0.0,
                            ];
                        }

                        $fte = floatval($row['fte_total'] ?? 0);
                        $jobclass = $row['jobclass'] ?? '';
                        $jobclass_cat = $row['jobclass_cat'] ?? '';

                        $school_staff[$key]['total_staff_fte'] += $fte;

                        // Teachers
                        if ($jobclass === 'Teacher' || $jobclass === 'Co-teacher') {
                            $school_staff[$key]['teacher_fte'] += $fte;
                        }
                        // Administrators
                        elseif ($jobclass_cat === 'Administrators') {
                            $school_staff[$key]['admin_fte'] += $fte;
                        }
                        // Support staff
                        elseif (strpos($jobclass_cat, 'Support') !== false) {
                            $school_staff[$key]['support_fte'] += $fte;
                        }
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: fetched " . count($school_staff) . " school staff records");
            }

            // Now save aggregated data as features
            $schools_table = $this->db->get_table('schools');

            foreach ($school_staff as $staff) {
                $school_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
                    $staff['org_code']
                ));

                if (!$school_id) {
                    $total_skipped++;
                    continue;
                }

                $feature_value = [
                    'year' => $staff['year'],
                    'teacher_fte' => round($staff['teacher_fte'], 1),
                    'total_staff_fte' => round($staff['total_staff_fte'], 1),
                    'admin_fte' => round($staff['admin_fte'], 1),
                    'support_fte' => round($staff['support_fte'], 1),
                ];

                $result = $this->upsert_feature(
                    $school_id,
                    'staffing',
                    "Staffing {$staff['year']}",
                    wp_json_encode($feature_value)
                );

                if ($result === 'imported') $total_imported++;
                elseif ($result === 'updated') $total_updated++;
                else $total_skipped++;
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "Staffing: {$total_imported} imported, {$total_updated} updated",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Staffing import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import district per-pupil spending and staffing data from E2C Hub.
     *
     * Dataset: er3w-dyti (Per-Pupil Expenditures)
     * Includes: expenditures, teacher salaries, student-teacher ratios, staff FTEs.
     *
     * @since 0.5.3
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_district_spending($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => [2024],
        ];
        $options = wp_parse_args($options, $defaults);

        $spending_dataset = 'er3w-dyti';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;

        try {
            // Aggregate by district
            $district_data = [];

            foreach ($options['years'] as $year) {
                $this->log_progress("Importing district spending for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'dist_code',
                    ];

                    $url = $this->api_base . $spending_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $dist_code = $row['dist_code'] ?? '';
                        if (empty($dist_code)) continue;

                        $key = "{$dist_code}_{$year}";
                        if (!isset($district_data[$key])) {
                            $district_data[$key] = [
                                'dist_code' => $dist_code,
                                'dist_name' => $row['dist_name'] ?? '',
                                'year' => intval($year),
                                'expenditures' => [],
                                'teacher_salary' => null,
                                'teacher_fte' => null,
                                'teachers_per_100' => null,
                                'staff' => [],
                            ];
                        }

                        $cat = $row['ind_cat'] ?? '';
                        $subcat = $row['ind_subcat'] ?? '';
                        $value = floatval($row['ind_value'] ?? 0);

                        // Teacher Salaries
                        if ($cat === 'Teacher Salaries') {
                            if ($subcat === 'Average Teacher Salary') {
                                $district_data[$key]['teacher_salary'] = $value;
                            } elseif ($subcat === 'Teacher FTE') {
                                $district_data[$key]['teacher_fte'] = $value;
                            } elseif ($subcat === 'Teachers per 100 FTE students') {
                                $district_data[$key]['teachers_per_100'] = $value;
                            }
                        }
                        // Expenditures
                        elseif ($cat === 'Expenditures Per Pupil') {
                            $district_data[$key]['expenditures'][$subcat] = $value;
                        }
                        // Other Staff
                        elseif ($cat === 'Other Staff') {
                            $district_data[$key]['staff'][$subcat] = $value;
                        }
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: fetched " . count($district_data) . " district records");
            }

            // Save to districts table
            $districts_table = $this->db->get_table('districts');

            foreach ($district_data as $data) {
                // Try to find district by state_district_id first, then by name
                $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$districts_table} WHERE state_district_id = %s",
                    $data['dist_code']
                ));

                // If not found, try matching by name (strip " (District)" suffix from API)
                if (!$district_id) {
                    $clean_name = preg_replace('/\s*\(District\)\s*$/i', '', $data['dist_name']);
                    $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$districts_table} WHERE name = %s OR name LIKE %s",
                        $clean_name,
                        $clean_name . '%'
                    ));
                }

                if (!$district_id) {
                    $total_skipped++;
                    continue;
                }

                // Build spending data JSON
                $spending_json = wp_json_encode([
                    'year' => $data['year'],
                    'teacher_salary_avg' => $data['teacher_salary'],
                    'teacher_fte' => $data['teacher_fte'],
                    'teachers_per_100_students' => $data['teachers_per_100'],
                    'expenditure_per_pupil_total' => $data['expenditures']['Total Expenditures'] ?? null,
                    'expenditure_per_pupil_teachers' => $data['expenditures']['Teachers'] ?? null,
                    'expenditure_per_pupil_admin' => $data['expenditures']['Administration'] ?? null,
                    'staff_instructional_coach_fte' => $data['staff']['Instructional Coach FTE'] ?? null,
                    'staff_paraprofessional_fte' => $data['staff']['Paraprofessional FTE'] ?? null,
                    'staff_instructional_support_fte' => $data['staff']['Instructional Support FTE'] ?? null,
                ]);

                // Update district record with spending data
                $result = $this->wpdb->update(
                    $districts_table,
                    ['extra_data' => $spending_json],
                    ['id' => $district_id],
                    ['%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $total_imported++;
                } else {
                    $total_skipped++;
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "District spending: {$total_imported} updated, {$total_skipped} skipped",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('District spending import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Backward compatibility wrapper.
     *
     * @since 0.2.0
     * @deprecated Use import_demographics() instead.
     * @param string $year School year.
     * @return int Number of records imported.
     */
    public function import_enrollment($year = null) {
        trigger_error('import_enrollment() is deprecated. Use import_demographics() instead.', E_USER_DEPRECATED);

        $options = [];
        if ($year) {
            $options['years'] = [$year];
        }
        $result = $this->import_demographics($options);
        return $result['imported'] ?? 0;
    }

    /**
     * Import MassCore curriculum completion data.
     *
     * Dataset: a9ye-ac8e (MassCore Curriculum Completion)
     * MassCore is MA's recommended college-ready curriculum.
     * Stores completion percentage as a feature.
     *
     * @since 0.6.0
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_masscore($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024', '2023', '2022'],
        ];
        $options = wp_parse_args($options, $defaults);

        $masscore_dataset = 'a9ye-ac8e';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;

        try {
            foreach ($options['years'] as $year) {
                $this->log_progress("Importing MassCore completion for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND org_type='School' AND stu_grp='All Students'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code',
                    ];

                    $url = $this->api_base . $masscore_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        $this->log_error('MassCore API request failed: ' . $response->get_error_message());
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $result = $this->import_masscore_record($row, $year);
                        if ($result === 'imported') $total_imported++;
                        elseif ($result === 'updated') $total_updated++;
                        else $total_skipped++;
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: processed");
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "MassCore: {$total_imported} imported, {$total_updated} updated, {$total_skipped} skipped",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('MassCore import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import a single MassCore record.
     *
     * @since 0.6.0
     * @param array  $row  Raw API data.
     * @param string $year School year.
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function import_masscore_record($row, $year) {
        if (empty($row['org_code'])) return 'skipped';

        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) return 'skipped';

        $feature_value = [
            'year' => intval($year),
            'graduates_count' => intval($row['cnt_hs_grad'] ?? 0),
            'masscore_count' => intval($row['cnt_compl_masscore'] ?? 0),
            'masscore_pct' => $this->parse_percentage($row['pct_compl_masscore'] ?? null),
        ];

        return $this->upsert_feature($school_id, 'masscore', "MassCore {$year}", wp_json_encode($feature_value));
    }

    /**
     * Import school-level expenditure data.
     *
     * Dataset: i5up-aez6 (School Expenditures by Spending Category)
     * Contains per-pupil spending and various indicators at school level.
     *
     * @since 0.6.0
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_school_expenditures($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024'],
        ];
        $options = wp_parse_args($options, $defaults);

        $expenditure_dataset = 'i5up-aez6';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;
        $schools_processed = [];

        try {
            foreach ($options['years'] as $year) {
                $this->log_progress("Importing school expenditures for {$year}...");

                $offset = 0;
                $limit = 1000;
                $school_data = [];

                // Fetch all records and aggregate by school
                while (true) {
                    $params = [
                        '$where' => "sy='{$year}'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code,ind_cat,ind_subcat',
                    ];

                    $url = $this->api_base . $expenditure_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        $this->log_error('School expenditures API request failed: ' . $response->get_error_message());
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $org_code = $row['org_code'] ?? '';
                        if (empty($org_code)) continue;

                        $key = "{$org_code}_{$year}";
                        if (!isset($school_data[$key])) {
                            $school_data[$key] = [
                                'org_code' => $org_code,
                                'year' => intval($year),
                                'indicators' => [],
                            ];
                        }

                        $cat = $row['ind_cat'] ?? '';
                        $subcat = $row['ind_subcat'] ?? '';
                        $value = $row['ind_value'] ?? null;

                        if ($cat && $subcat && $value !== null) {
                            $school_data[$key]['indicators']["{$cat}|{$subcat}"] = floatval($value);
                        }
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: aggregated " . count($school_data) . " school records");

                // Save aggregated data as features
                $schools_table = $this->db->get_table('schools');

                foreach ($school_data as $data) {
                    $school_id = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
                        $data['org_code']
                    ));

                    if (!$school_id) {
                        $total_skipped++;
                        continue;
                    }

                    // Extract key metrics
                    $feature_value = [
                        'year' => $data['year'],
                        'per_pupil_total' => $data['indicators']['Expenditures Per Pupil|Total Expenditures Per Pupil'] ?? null,
                        'per_pupil_instruction' => $data['indicators']['Expenditures Per Pupil|Instruction Expenditures Per Pupil'] ?? null,
                        'per_pupil_support' => $data['indicators']['Expenditures Per Pupil|Pupil Support Expenditures Per Pupil'] ?? null,
                        'student_fte' => $data['indicators']['Student Demographics|Student FTE'] ?? null,
                        'low_income_pct' => $data['indicators']['Student Demographics|Low-Income % Headcount'] ?? null,
                        'ell_pct' => $data['indicators']['Student Demographics|English learner % Headcount'] ?? null,
                        'sped_pct' => $data['indicators']['Student Demographics|Students with disabilities % Headcount'] ?? null,
                    ];

                    $result = $this->upsert_feature(
                        $school_id,
                        'expenditure',
                        "Expenditure {$data['year']}",
                        wp_json_encode($feature_value)
                    );

                    if ($result === 'imported') $total_imported++;
                    elseif ($result === 'updated') $total_updated++;
                    else $total_skipped++;

                    $schools_processed[$data['org_code']] = true;
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "School expenditures: {$total_imported} imported, {$total_updated} updated, {$total_skipped} skipped (" . count($schools_processed) . " schools)",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'schools' => count($schools_processed),
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('School expenditures import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import pathways and programs enrollment data.
     *
     * Dataset: 9p45-t37j (Pathways/Programs Enrollment)
     * Contains CTE, Early College, Innovation Pathways participation.
     *
     * @since 0.6.0
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_pathways($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024'],
        ];
        $options = wp_parse_args($options, $defaults);

        $pathways_dataset = '9p45-t37j';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;
        $schools_processed = [];

        try {
            foreach ($options['years'] as $year) {
                $this->log_progress("Importing pathways/programs for {$year}...");

                $offset = 0;
                $limit = 1000;
                $school_programs = [];

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND org_type='School'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code,pathway,program',
                    ];

                    $url = $this->api_base . $pathways_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        $this->log_error('Pathways API request failed: ' . $response->get_error_message());
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $org_code = $row['org_code'] ?? '';
                        if (empty($org_code)) continue;

                        $key = "{$org_code}_{$year}";
                        if (!isset($school_programs[$key])) {
                            $school_programs[$key] = [
                                'org_code' => $org_code,
                                'year' => intval($year),
                                'total_students' => intval($row['totalstu'] ?? 0),
                                'pathways' => [],
                            ];
                        }

                        $pathway = $row['pathway'] ?? '';
                        $program = $row['program'] ?? '';
                        $count = intval($row['program_cnt'] ?? 0);

                        if ($pathway && $count > 0) {
                            if (!isset($school_programs[$key]['pathways'][$pathway])) {
                                $school_programs[$key]['pathways'][$pathway] = [
                                    'programs' => [],
                                    'total_students' => 0,
                                ];
                            }
                            $school_programs[$key]['pathways'][$pathway]['programs'][] = $program;
                            $school_programs[$key]['pathways'][$pathway]['total_students'] += $count;
                        }
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: aggregated " . count($school_programs) . " school records");

                // Save aggregated data
                $schools_table = $this->db->get_table('schools');

                foreach ($school_programs as $data) {
                    $school_id = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
                        $data['org_code']
                    ));

                    if (!$school_id) {
                        $total_skipped++;
                        continue;
                    }

                    // Calculate summary metrics
                    $has_cte = isset($data['pathways']['Career Technical Education (Chapter 74 Programs)']);
                    $has_early_college = isset($data['pathways']['Early College']);
                    $has_innovation = isset($data['pathways']['Innovation Pathway']);

                    $cte_students = $data['pathways']['Career Technical Education (Chapter 74 Programs)']['total_students'] ?? 0;
                    $early_college_students = $data['pathways']['Early College']['total_students'] ?? 0;
                    $innovation_students = $data['pathways']['Innovation Pathway']['total_students'] ?? 0;

                    $feature_value = [
                        'year' => $data['year'],
                        'total_students' => $data['total_students'],
                        'has_cte' => $has_cte,
                        'has_early_college' => $has_early_college,
                        'has_innovation_pathway' => $has_innovation,
                        'cte_students' => $cte_students,
                        'early_college_students' => $early_college_students,
                        'innovation_students' => $innovation_students,
                        'cte_pct' => $data['total_students'] > 0 ? round($cte_students / $data['total_students'] * 100, 1) : 0,
                        'pathways_detail' => $data['pathways'],
                    ];

                    $result = $this->upsert_feature(
                        $school_id,
                        'pathways',
                        "Pathways {$data['year']}",
                        wp_json_encode($feature_value)
                    );

                    if ($result === 'imported') $total_imported++;
                    elseif ($result === 'updated') $total_updated++;
                    else $total_skipped++;

                    $schools_processed[$data['org_code']] = true;
                }
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "Pathways: {$total_imported} imported, {$total_updated} updated (" . count($schools_processed) . " schools)",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'schools' => count($schools_processed),
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Pathways import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import Early College participation data.
     *
     * Dataset: p2yd-4gvj (Early College Participation)
     * Shows students enrolled in Early College programs by grade.
     *
     * @since 0.6.0
     * @param array $options Import options.
     * @return array Result with success status.
     */
    public function import_early_college($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'years' => ['2024'],
        ];
        $options = wp_parse_args($options, $defaults);

        $ec_dataset = 'p2yd-4gvj';
        $total_imported = 0;
        $total_updated = 0;
        $total_skipped = 0;

        try {
            foreach ($options['years'] as $year) {
                $this->log_progress("Importing Early College participation for {$year}...");

                $offset = 0;
                $limit = 1000;

                while (true) {
                    $params = [
                        '$where' => "sy='{$year}' AND stu_grp='All Students'",
                        '$limit' => $limit,
                        '$offset' => $offset,
                        '$order' => 'org_code',
                    ];

                    $url = $this->api_base . $ec_dataset . '.json?' . http_build_query($params);
                    $response = $this->http_get($url);

                    if (is_wp_error($response)) {
                        $this->log_error('Early College API request failed: ' . $response->get_error_message());
                        break;
                    }

                    $data = $this->parse_json($response);
                    if (is_wp_error($data) || empty($data)) {
                        break;
                    }

                    foreach ($data as $row) {
                        $result = $this->import_early_college_record($row, $year);
                        if ($result === 'imported') $total_imported++;
                        elseif ($result === 'updated') $total_updated++;
                        else $total_skipped++;
                    }

                    if (count($data) < $limit) break;
                    $offset += $limit;
                }

                $this->log_progress("Year {$year}: processed");
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            return [
                'success' => true,
                'message' => "Early College: {$total_imported} imported, {$total_updated} updated",
                'imported' => $total_imported,
                'updated' => $total_updated,
                'skipped' => $total_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Early College import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    /**
     * Import a single Early College record.
     *
     * @since 0.6.0
     * @param array  $row  Raw API data.
     * @param string $year School year.
     * @return string 'imported', 'updated', or 'skipped'.
     */
    private function import_early_college_record($row, $year) {
        if (empty($row['org_code'])) return 'skipped';

        $schools_table = $this->db->get_table('schools');
        $school_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
            $row['org_code']
        ));

        if (!$school_id) return 'skipped';

        $feature_value = [
            'year' => intval($year),
            'college_name' => $row['ceeb_name'] ?? '',
            'college_code' => $row['ceeb_code'] ?? '',
            'g09_count' => intval($row['g09_cnt'] ?? 0),
            'g10_count' => intval($row['g10_cnt'] ?? 0),
            'g11_count' => intval($row['g11_cnt'] ?? 0),
            'g12_count' => intval($row['g12_cnt'] ?? 0),
            'total_count' => intval($row['all_cnt'] ?? 0),
        ];

        return $this->upsert_feature($school_id, 'early_college', "Early College {$year}", wp_json_encode($feature_value));
    }

    /**
     * Import college enrollment outcomes by district.
     *
     * Data is at district level from E2C Hub vj54-j4q3 dataset.
     * Shows where high school graduates go: 2-year, 4-year, out-of-state, employed.
     *
     * @since 0.6.18
     * @param array $options Import options.
     * @return array Result.
     */
    public function import_college_outcomes($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'year' => '2021', // Latest available year
        ];

        $options = wp_parse_args($options, $defaults);
        $year = $options['year'];

        $this->log_progress("Starting college outcomes import for {$year}...");

        try {
            $districts_updated = 0;
            $districts_skipped = 0;

            // College outcomes dataset ID
            $outcomes_dataset = 'vj54-j4q3';

            // Fetch all outcome types for the year (district level only, not state)
            $params = [
                '$where' => "hs_grad_year='{$year}' AND district_code != '00000000'",
                '$limit' => 5000,
                '$order' => 'district_code',
            ];

            $url = $this->api_base . $outcomes_dataset . '.json?' . http_build_query($params);
            $response = $this->http_get($url);

            if (is_wp_error($response)) {
                throw new Exception('API request failed: ' . $response->get_error_message());
            }

            $data = $this->parse_json($response);

            if (is_wp_error($data) || empty($data)) {
                throw new Exception('No data returned from API');
            }

            $this->log_progress("Fetched " . count($data) . " outcome records");

            // Group by district
            $districts = [];
            foreach ($data as $row) {
                $district_code = $row['district_code'];
                if (!isset($districts[$district_code])) {
                    $districts[$district_code] = [
                        'name' => $row['district_name'],
                        'grad_count' => intval($row['grad_count']),
                        'outcomes' => [],
                    ];
                }
                $outcome_type = $row['outcome_type'];
                $outcome_count = intval($row['outcome_count'] ?? 0);
                $districts[$district_code]['outcomes'][$outcome_type] = $outcome_count;
            }

            $this->log_progress("Processing " . count($districts) . " districts");

            // Update each district's extra_data
            $districts_table = $this->db->get_table('districts');

            foreach ($districts as $district_code => $district_data) {
                // Find district by state_district_id
                $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$districts_table} WHERE state_district_id = %s",
                    $district_code
                ));

                if (!$district_id) {
                    $districts_skipped++;
                    continue;
                }

                // Calculate percentages
                $grad_count = $district_data['grad_count'];
                if ($grad_count <= 0) {
                    $districts_skipped++;
                    continue;
                }

                $outcomes = $district_data['outcomes'];
                $total_postsecondary = intval($outcomes['Total Postsecondary Enrollment'] ?? 0);
                $in_state_4yr = intval($outcomes['In-State Public 4-Year'] ?? 0);
                $in_state_2yr = intval($outcomes['In-State Public 2-Year'] ?? 0);
                $in_state_private = intval($outcomes['In-State Private'] ?? 0);
                $out_of_state = intval($outcomes['Out-of-State'] ?? 0);
                $employed = intval($outcomes['Total Employed'] ?? 0);

                $college_outcomes = [
                    'year' => intval($year),
                    'grad_count' => $grad_count,
                    'total_postsecondary_pct' => round(($total_postsecondary / $grad_count) * 100, 1),
                    'four_year_pct' => round((($in_state_4yr + $in_state_private + $out_of_state) / $grad_count) * 100, 1),
                    'two_year_pct' => round(($in_state_2yr / $grad_count) * 100, 1),
                    'out_of_state_pct' => round(($out_of_state / $grad_count) * 100, 1),
                    'employed_pct' => round(($employed / $grad_count) * 100, 1),
                ];

                // Get existing extra_data and merge
                $existing_extra = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT extra_data FROM {$districts_table} WHERE id = %d",
                    $district_id
                ));

                $extra_data = $existing_extra ? json_decode($existing_extra, true) : [];
                if (!is_array($extra_data)) {
                    $extra_data = [];
                }
                $extra_data['college_outcomes'] = $college_outcomes;

                // Update district
                $this->wpdb->update(
                    $districts_table,
                    ['extra_data' => wp_json_encode($extra_data)],
                    ['id' => $district_id],
                    ['%s'],
                    ['%d']
                );

                $districts_updated++;
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $message = sprintf(
                'College outcomes import complete: %d districts updated, %d skipped',
                $districts_updated,
                $districts_skipped
            );

            $this->log_progress($message);

            return [
                'success' => true,
                'message' => $message,
                'updated' => $districts_updated,
                'skipped' => $districts_skipped,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('College outcomes import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'updated' => 0];
        }
    }

    /**
     * Import discipline data from DESE SSDR reports.
     *
     * This method imports school discipline data from the School Safety Discipline Report.
     * Data source: https://profiles.doe.mass.edu/statereport/ssdr.aspx
     *
     * The data includes:
     * - In-school suspension percentages
     * - Out-of-school suspension percentages
     * - Expulsion percentages
     * - Emergency removal percentages
     * - Law enforcement referrals
     *
     * @since 0.6.22
     * @param array $options Import options.
     *   - year: Academic year (e.g., '2024' for 2023-24)
     *   - data: Array of discipline records to import (if provided directly)
     * @return array Result with success status and statistics.
     */
    public function import_discipline($options = []) {
        $start_time = microtime(true);

        $defaults = [
            'year' => date('Y'), // Current year
            'data' => null,      // Direct data array (for CSV import)
        ];

        $options = wp_parse_args($options, $defaults);
        $year = $options['year'];

        $this->log_progress("Starting discipline data import for {$year}...");

        try {
            $schools_updated = 0;
            $schools_skipped = 0;
            $schools_not_found = 0;

            $discipline_table = $this->db->get_table('discipline');
            $schools_table = $this->db->get_table('schools');

            // If data is provided directly, use it
            if (!empty($options['data']) && is_array($options['data'])) {
                $data = $options['data'];
                $this->log_progress("Processing " . count($data) . " provided discipline records");
            } else {
                // Try to fetch from DESE SSDR page (web scraping)
                $data = $this->fetch_ssdr_data($year);

                if (empty($data)) {
                    throw new Exception("No discipline data available for {$year}. Please provide data manually or check the DESE SSDR website.");
                }

                $this->log_progress("Fetched " . count($data) . " discipline records from SSDR");
            }

            // Process each record
            foreach ($data as $row) {
                // Get org_code (school identifier)
                $org_code = $row['org_code'] ?? $row['school_code'] ?? null;
                $org_name = $row['org_name'] ?? $row['school_name'] ?? null;

                if (!$org_code) {
                    $schools_skipped++;
                    continue;
                }

                // Find school by state_school_id (org_code)
                $school_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$schools_table} WHERE state_school_id = %s",
                    $org_code
                ));

                if (!$school_id) {
                    $schools_not_found++;
                    continue;
                }

                // Parse discipline data
                $enrollment = $this->parse_number($row['students'] ?? $row['enrollment'] ?? null);
                $students_disciplined = $this->parse_number($row['students_disciplined'] ?? null);
                $in_school_pct = $this->parse_percent($row['in_school_suspension_pct'] ?? $row['iss_pct'] ?? null);
                $out_school_pct = $this->parse_percent($row['out_of_school_suspension_pct'] ?? $row['oss_pct'] ?? null);
                $expulsion_pct = $this->parse_percent($row['expulsion_pct'] ?? null);
                $removed_alt_pct = $this->parse_percent($row['removed_to_alternate_pct'] ?? $row['alternate_pct'] ?? null);
                $emergency_pct = $this->parse_percent($row['emergency_removal_pct'] ?? null);
                $arrest_pct = $this->parse_percent($row['school_based_arrest_pct'] ?? $row['arrest_pct'] ?? null);
                $law_ref_pct = $this->parse_percent($row['law_enforcement_referral_pct'] ?? $row['law_ref_pct'] ?? null);

                // Calculate combined discipline rate (OSS + Expulsion + Emergency)
                $discipline_rate = null;
                if ($out_school_pct !== null || $expulsion_pct !== null || $emergency_pct !== null) {
                    $discipline_rate = ($out_school_pct ?? 0) + ($expulsion_pct ?? 0) + ($emergency_pct ?? 0);
                }

                // Insert or update discipline record
                $existing = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$discipline_table} WHERE school_id = %d AND year = %d",
                    $school_id,
                    $year
                ));

                $record = [
                    'school_id' => $school_id,
                    'year' => $year,
                    'enrollment' => $enrollment,
                    'students_disciplined' => $students_disciplined,
                    'in_school_suspension_pct' => $in_school_pct,
                    'out_of_school_suspension_pct' => $out_school_pct,
                    'expulsion_pct' => $expulsion_pct,
                    'removed_to_alternate_pct' => $removed_alt_pct,
                    'emergency_removal_pct' => $emergency_pct,
                    'school_based_arrest_pct' => $arrest_pct,
                    'law_enforcement_referral_pct' => $law_ref_pct,
                    'discipline_rate' => $discipline_rate,
                ];

                if ($existing) {
                    $this->wpdb->update($discipline_table, $record, ['id' => $existing]);
                } else {
                    $this->wpdb->insert($discipline_table, $record);
                }

                $schools_updated++;
            }

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $message = sprintf(
                'Discipline data import complete: %d schools updated, %d skipped, %d not found in database',
                $schools_updated,
                $schools_skipped,
                $schools_not_found
            );

            $this->log_progress($message);

            // Update data source timestamp
            $this->update_source_status('complete', 'discipline');

            return [
                'success' => true,
                'message' => $message,
                'updated' => $schools_updated,
                'skipped' => $schools_skipped,
                'not_found' => $schools_not_found,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->log_error('Discipline data import failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'updated' => 0];
        }
    }

    /**
     * Fetch SSDR data from DESE website.
     *
     * Note: This is a fallback method. The primary import method should be
     * providing data directly from a CSV export from the DESE website.
     *
     * @since 0.6.22
     * @param string $year Academic year.
     * @return array Discipline records or empty array.
     */
    private function fetch_ssdr_data($year) {
        // The DESE SSDR website requires form submission and session handling
        // For now, return empty array and expect data to be provided via CSV
        $this->log_progress("SSDR web fetch not implemented - please import via CSV");
        return [];
    }

    /**
     * Parse a number from various formats.
     *
     * @since 0.6.22
     * @param mixed $value Input value.
     * @return int|null Parsed integer or null.
     */
    private function parse_number($value) {
        if ($value === null || $value === '' || $value === 'N/A' || $value === '-') {
            return null;
        }
        return intval(str_replace(',', '', $value));
    }

    /**
     * Parse a percentage from various formats.
     *
     * @since 0.6.22
     * @param mixed $value Input value.
     * @return float|null Parsed percentage or null.
     */
    private function parse_percent($value) {
        if ($value === null || $value === '' || $value === 'N/A' || $value === '-') {
            return null;
        }
        // Remove % sign if present
        $value = str_replace('%', '', $value);
        return floatval($value);
    }

    /**
     * Get discipline statistics.
     *
     * @since 0.6.22
     * @return array Statistics about discipline data.
     */
    public function get_discipline_stats() {
        $discipline_table = $this->db->get_table('discipline');

        $stats = [
            'total_records' => (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$discipline_table}"),
            'schools_with_data' => (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT school_id) FROM {$discipline_table}"),
            'years' => $this->wpdb->get_col("SELECT DISTINCT year FROM {$discipline_table} ORDER BY year DESC"),
        ];

        // Get average discipline rate
        $stats['avg_discipline_rate'] = $this->wpdb->get_var(
            "SELECT AVG(discipline_rate) FROM {$discipline_table} WHERE discipline_rate IS NOT NULL"
        );

        // Get 25th percentile (for "Low Discipline Rate" threshold)
        $count = $stats['total_records'];
        if ($count > 0) {
            $offset = (int) floor($count * 0.25);
            $stats['low_discipline_threshold'] = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT discipline_rate FROM {$discipline_table}
                 WHERE discipline_rate IS NOT NULL
                 ORDER BY discipline_rate ASC
                 LIMIT 1 OFFSET %d",
                $offset
            ));
        }

        return $stats;
    }
}
