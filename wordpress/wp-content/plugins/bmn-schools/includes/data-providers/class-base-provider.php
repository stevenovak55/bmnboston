<?php
/**
 * Base Data Provider Class
 *
 * Abstract base class for all data providers. Handles common functionality
 * like HTTP requests, rate limiting, logging, and error handling.
 *
 * @package BMN_Schools
 * @since 0.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Base Data Provider Class
 *
 * @since 0.2.0
 */
abstract class BMN_Schools_Base_Provider {

    /**
     * Provider name identifier.
     *
     * @var string
     */
    protected $provider_name = '';

    /**
     * Provider display name.
     *
     * @var string
     */
    protected $display_name = '';

    /**
     * Database manager instance.
     *
     * @var BMN_Schools_Database_Manager
     */
    protected $db;

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    protected $wpdb;

    /**
     * Rate limit: requests per minute.
     *
     * @var int
     */
    protected $rate_limit = 60;

    /**
     * Delay between requests in microseconds.
     *
     * @var int
     */
    protected $request_delay = 100000; // 100ms default

    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    protected $timeout = 30;

    /**
     * Import batch size.
     *
     * @var int
     */
    protected $batch_size = 100;

    /**
     * Constructor.
     *
     * @since 0.2.0
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        $this->db = new BMN_Schools_Database_Manager();
    }

    /**
     * Get provider name.
     *
     * @since 0.2.0
     * @return string
     */
    public function get_name() {
        return $this->provider_name;
    }

    /**
     * Get display name.
     *
     * @since 0.2.0
     * @return string
     */
    public function get_display_name() {
        return $this->display_name;
    }

    /**
     * Run the data sync/import.
     *
     * @since 0.2.0
     * @param array $options Import options.
     * @return array Result with success status and message.
     */
    abstract public function sync($options = []);

    /**
     * Validate provider configuration.
     *
     * @since 0.2.0
     * @return bool|WP_Error True if valid, WP_Error on failure.
     */
    public function validate() {
        return true;
    }

    /**
     * Maximum retry attempts for failed requests.
     *
     * @var int
     */
    protected $max_retries = 3;

    /**
     * Make an HTTP GET request with exponential backoff retry.
     *
     * @since 0.2.0
     * @since 0.6.36 Added exponential backoff retry logic
     * @param string $url     Request URL.
     * @param array  $args    Request arguments.
     * @return array|WP_Error Response or error.
     */
    protected function http_get($url, $args = []) {
        $default_args = [
            'timeout' => $this->timeout,
            'headers' => [
                'User-Agent' => 'BMN-Schools-Plugin/' . BMN_SCHOOLS_VERSION,
                'Accept' => 'application/json',
            ],
        ];

        $args = wp_parse_args($args, $default_args);

        $last_error = null;
        $attempt = 0;

        while ($attempt < $this->max_retries) {
            $start_time = microtime(true);
            $response = wp_remote_get($url, $args);
            $duration_ms = (microtime(true) - $start_time) * 1000;

            // Log the API call
            $response_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);

            BMN_Schools_Logger::api_call(
                $this->provider_name,
                $url,
                $response_code,
                $duration_ms
            );

            if (!is_wp_error($response)) {
                // Check for server errors that should be retried
                if ($response_code >= 500 && $attempt < $this->max_retries - 1) {
                    $attempt++;
                    $delay = pow(2, $attempt) * 1000000; // Exponential backoff: 2s, 4s, 8s
                    BMN_Schools_Logger::log('warning', 'api',
                        sprintf('Server error %d, retrying in %ds (attempt %d/%d)',
                            $response_code, $delay / 1000000, $attempt + 1, $this->max_retries),
                        ['url' => $url]
                    );
                    usleep($delay);
                    continue;
                }

                // Rate limiting delay on success
                usleep($this->request_delay);
                return $response;
            }

            // Store error and retry
            $last_error = $response;
            $attempt++;

            if ($attempt < $this->max_retries) {
                $delay = pow(2, $attempt) * 1000000; // Exponential backoff: 2s, 4s, 8s
                BMN_Schools_Logger::log('warning', 'api',
                    sprintf('Request failed, retrying in %ds (attempt %d/%d): %s',
                        $delay / 1000000, $attempt + 1, $this->max_retries, $response->get_error_message()),
                    ['url' => $url]
                );
                usleep($delay);
            }
        }

        // All retries exhausted
        BMN_Schools_Logger::api_error(
            $this->provider_name,
            $url,
            $last_error ? $last_error->get_error_message() : 'Max retries exceeded'
        );

        return $last_error ?: new WP_Error('max_retries', 'Maximum retry attempts exceeded');
    }

    /**
     * Download a file from URL.
     *
     * @since 0.2.0
     * @param string $url      File URL.
     * @param string $filename Local filename (optional).
     * @return string|WP_Error Local file path or error.
     */
    protected function download_file($url, $filename = null) {
        $start_time = microtime(true);

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp_file = download_url($url, $this->timeout);
        $duration_ms = (microtime(true) - $start_time) * 1000;

        if (is_wp_error($temp_file)) {
            BMN_Schools_Logger::api_error(
                $this->provider_name,
                $url,
                'Download failed: ' . $temp_file->get_error_message()
            );
            return $temp_file;
        }

        BMN_Schools_Logger::api_call(
            $this->provider_name,
            $url,
            200,
            $duration_ms
        );

        // Move to plugin temp directory if filename specified
        if ($filename) {
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/bmn-schools-temp/';

            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $target_file = $target_dir . $filename;
            rename($temp_file, $target_file);
            return $target_file;
        }

        return $temp_file;
    }

    /**
     * Parse CSV file.
     *
     * @since 0.2.0
     * @param string $file_path  Path to CSV file.
     * @param array  $options    Parse options.
     * @return array|WP_Error Parsed rows or error.
     */
    protected function parse_csv($file_path, $options = []) {
        $defaults = [
            'has_header' => true,
            'delimiter' => ',',
            'enclosure' => '"',
            'skip_rows' => 0,
            'limit' => 0,
        ];

        $options = wp_parse_args($options, $defaults);

        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'CSV file not found: ' . $file_path);
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('file_open_error', 'Could not open CSV file');
        }

        $rows = [];
        $headers = null;
        $row_count = 0;

        // Skip rows if needed
        for ($i = 0; $i < $options['skip_rows']; $i++) {
            fgetcsv($handle, 0, $options['delimiter'], $options['enclosure']);
        }

        while (($data = fgetcsv($handle, 0, $options['delimiter'], $options['enclosure'])) !== false) {
            if ($options['has_header'] && $headers === null) {
                // Clean header names
                $headers = array_map(function($h) {
                    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $h)));
                }, $data);
                continue;
            }

            if ($options['has_header'] && $headers) {
                // Combine with headers
                if (count($data) === count($headers)) {
                    $rows[] = array_combine($headers, $data);
                }
            } else {
                $rows[] = $data;
            }

            $row_count++;

            if ($options['limit'] > 0 && $row_count >= $options['limit']) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Parse JSON response.
     *
     * @since 0.2.0
     * @param string|array $response HTTP response or JSON string.
     * @return array|WP_Error Parsed data or error.
     */
    protected function parse_json($response) {
        if (is_array($response) && isset($response['body'])) {
            $body = wp_remote_retrieve_body($response);
        } else {
            $body = $response;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_parse_error', 'JSON parse error: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Insert or update a school record.
     *
     * @since 0.2.0
     * @param array $data School data.
     * @return int|WP_Error School ID or error.
     */
    protected function upsert_school($data) {
        $table = $this->db->get_table('schools');

        // Check if school exists by NCES ID or state ID
        $existing_id = null;

        if (!empty($data['nces_school_id'])) {
            $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE nces_school_id = %s",
                $data['nces_school_id']
            ));
        }

        if (!$existing_id && !empty($data['state_school_id'])) {
            $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE state_school_id = %s",
                $data['state_school_id']
            ));
        }

        // Clean data
        $clean_data = $this->sanitize_school_data($data);

        if ($existing_id) {
            // Update
            $clean_data['updated_at'] = current_time('mysql');
            $this->wpdb->update($table, $clean_data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            // Insert
            $clean_data['created_at'] = current_time('mysql');
            $this->wpdb->insert($table, $clean_data);
            return $this->wpdb->insert_id;
        }
    }

    /**
     * Insert or update a district record.
     *
     * @since 0.2.0
     * @param array $data District data.
     * @return int|WP_Error District ID or error.
     */
    protected function upsert_district($data) {
        $table = $this->db->get_table('districts');

        // Check if district exists by NCES ID or state ID
        $existing_id = null;

        if (!empty($data['nces_district_id'])) {
            $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE nces_district_id = %s",
                $data['nces_district_id']
            ));
        }

        if (!$existing_id && !empty($data['state_district_id'])) {
            $existing_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE state_district_id = %s",
                $data['state_district_id']
            ));
        }

        // Clean data
        $clean_data = $this->sanitize_district_data($data);

        if ($existing_id) {
            // Update
            $clean_data['updated_at'] = current_time('mysql');
            $this->wpdb->update($table, $clean_data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            // Insert
            $clean_data['created_at'] = current_time('mysql');
            $this->wpdb->insert($table, $clean_data);
            return $this->wpdb->insert_id;
        }
    }

    /**
     * Sanitize school data for database.
     *
     * @since 0.2.0
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    protected function sanitize_school_data($data) {
        $allowed_fields = [
            'nces_school_id', 'state_school_id', 'name', 'school_type', 'level',
            'grades_low', 'grades_high', 'district_id', 'address', 'city', 'state',
            'zip', 'county', 'latitude', 'longitude', 'phone', 'website',
            'enrollment', 'student_teacher_ratio'
        ];

        $clean = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];

                // Type-specific sanitization
                switch ($field) {
                    case 'latitude':
                    case 'longitude':
                    case 'student_teacher_ratio':
                        $clean[$field] = $value !== '' ? floatval($value) : null;
                        break;

                    case 'enrollment':
                    case 'district_id':
                        $clean[$field] = $value !== '' ? intval($value) : null;
                        break;

                    case 'state':
                        $clean[$field] = strtoupper(substr(sanitize_text_field($value), 0, 2));
                        break;

                    case 'school_type':
                        $valid_types = ['public', 'private', 'charter'];
                        $value = strtolower(sanitize_text_field($value));
                        $clean[$field] = in_array($value, $valid_types) ? $value : 'public';
                        break;

                    case 'level':
                        $valid_levels = ['elementary', 'middle', 'high', 'combined'];
                        $value = strtolower(sanitize_text_field($value));
                        $clean[$field] = in_array($value, $valid_levels) ? $value : null;
                        break;

                    default:
                        $clean[$field] = sanitize_text_field($value);
                }
            }
        }

        return $clean;
    }

    /**
     * Sanitize district data for database.
     *
     * @since 0.2.0
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    protected function sanitize_district_data($data) {
        $allowed_fields = [
            'nces_district_id', 'state_district_id', 'name', 'type',
            'grades_low', 'grades_high', 'city', 'county', 'state',
            'total_schools', 'total_students', 'boundary_geojson',
            'website', 'phone'
        ];

        $clean = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];

                switch ($field) {
                    case 'total_schools':
                    case 'total_students':
                        $clean[$field] = $value !== '' ? intval($value) : 0;
                        break;

                    case 'state':
                        $clean[$field] = strtoupper(substr(sanitize_text_field($value), 0, 2));
                        break;

                    case 'boundary_geojson':
                        // Validate JSON
                        if (is_string($value)) {
                            $decoded = json_decode($value);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $clean[$field] = $value;
                            }
                        } elseif (is_array($value)) {
                            $clean[$field] = wp_json_encode($value);
                        }
                        break;

                    default:
                        $clean[$field] = sanitize_text_field($value);
                }
            }
        }

        return $clean;
    }

    /**
     * Update data source status.
     *
     * @since 0.2.0
     * @param string $status       New status.
     * @param int    $records      Records synced count.
     * @param string $error        Error message (optional).
     */
    protected function update_source_status($status, $records = 0, $error = null) {
        $table = $this->db->get_table('data_sources');

        $data = [
            'status' => $status,
            'records_synced' => $records,
            'updated_at' => current_time('mysql'),
        ];

        if ($status === 'active') {
            $data['last_sync'] = current_time('mysql');
            $data['error_message'] = null;
        }

        if ($error) {
            $data['error_message'] = $error;
        }

        $this->wpdb->update(
            $table,
            $data,
            ['source_name' => $this->provider_name]
        );
    }

    /**
     * Log import progress.
     *
     * @since 0.2.0
     * @param string $message Progress message.
     * @param array  $context Additional context.
     */
    protected function log_progress($message, $context = []) {
        $context['source'] = $this->provider_name;

        BMN_Schools_Logger::log('info', 'import', $message, $context);
    }

    /**
     * Log import error.
     *
     * @since 0.2.0
     * @param string $error   Error message.
     * @param array  $context Additional context.
     */
    protected function log_error($error, $context = []) {
        BMN_Schools_Logger::import_failed($this->provider_name, $error, $context);
    }

    /**
     * Clean up temporary files.
     *
     * @since 0.2.0
     */
    protected function cleanup_temp_files() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/bmn-schools-temp/';

        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Determine school level from grade range.
     *
     * @since 0.2.0
     * @param string $low_grade  Lowest grade.
     * @param string $high_grade Highest grade.
     * @return string School level.
     */
    protected function determine_level($low_grade, $high_grade) {
        // Convert to numbers (PK=-1, KG=0, 1-12)
        $low = $this->grade_to_number($low_grade);
        $high = $this->grade_to_number($high_grade);

        if ($low === null || $high === null) {
            return null;
        }

        // Elementary: PK-5
        if ($high <= 5) {
            return 'elementary';
        }

        // Middle: 6-8
        if ($low >= 6 && $high <= 8) {
            return 'middle';
        }

        // High: 9-12
        if ($low >= 9) {
            return 'high';
        }

        // Combined: spans multiple levels
        return 'combined';
    }

    /**
     * Convert grade string to number.
     *
     * @since 0.2.0
     * @param string $grade Grade string.
     * @return int|null Grade number.
     */
    protected function grade_to_number($grade) {
        $grade = strtoupper(trim($grade));

        $map = [
            'PK' => -1, 'PRE-K' => -1, 'PREK' => -1,
            'KG' => 0, 'K' => 0, 'KINDERGARTEN' => 0,
            '01' => 1, '02' => 2, '03' => 3, '04' => 4, '05' => 5,
            '06' => 6, '07' => 7, '08' => 8, '09' => 9,
            '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5,
            '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10,
            '11' => 11, '12' => 12,
        ];

        return isset($map[$grade]) ? $map[$grade] : null;
    }

    /**
     * Run post-import cleanup to fix regional school assignments.
     *
     * This method reassigns regional schools to their correct districts
     * and fixes any private schools incorrectly marked as public.
     *
     * @since 0.6.28
     * @return array Results of cleanup operations.
     */
    protected function run_post_import_cleanup() {
        $results = [
            'regional_schools_fixed' => 0,
            'private_schools_fixed' => 0,
        ];

        $schools_table = $this->db->get_table('schools');
        $districts_table = $this->db->get_table('districts');

        // Regional school to district mapping
        $regional_mappings = $this->get_regional_school_mappings();

        foreach ($regional_mappings as $pattern => $district_name) {
            // Find the district ID
            $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$districts_table} WHERE name LIKE %s LIMIT 1",
                '%' . $district_name . '%'
            ));

            if (!$district_id) {
                continue;
            }

            // Find and update matching schools
            $updated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$schools_table}
                 SET district_id = %d
                 WHERE name LIKE %s AND (district_id != %d OR district_id IS NULL)",
                $district_id,
                '%' . $pattern . '%',
                $district_id
            ));

            $results['regional_schools_fixed'] += $updated;
        }

        $this->log_progress("Post-import cleanup: Fixed {$results['regional_schools_fixed']} regional school assignments");

        return $results;
    }

    /**
     * Get regional school to district mappings.
     *
     * @since 0.6.28
     * @return array Pattern => district name mappings.
     */
    protected function get_regional_school_mappings() {
        return [
            'Lincoln-Sudbury Regional' => 'Lincoln-Sudbury School District',
            'Dover-Sherborn Regional' => 'Dover-Sherborn School District',
            'Concord Carlisle High' => 'Concord-Carlisle School District',
            'Somerset Berkley Regional' => 'Somerset-Berkley School District',
            'Algonquin Regional' => 'Northborough-Southborough School District',
            'King Philip Regional' => 'King Philip School District',
            'Nauset Regional' => 'Nauset School District',
            'Triton Regional' => 'Triton School District',
            'Pentucket Regional' => 'Pentucket School District',
            'Wachusett Regional' => 'Wachusett School District',
            'Gateway Regional' => 'Gateway School District',
            'Hoosac Valley' => 'Hoosac Valley School District',
            'Monument Mountain Regional' => 'Berkshire Hills School District',
            'W.E.B. Du Bois Regional' => 'Berkshire Hills School District',
            'Muddy Brook Regional' => 'Berkshire Hills School District',
            'Nashoba Regional' => 'Nashoba School District',
            'Monomoy Regional' => 'Monomoy Regional School District',
            'Quabbin Regional' => 'Quabbin School District',
            'Quaboag Regional' => 'Quaboag Regional School District',
            'Mohawk Trail Regional' => 'Mohawk Trail School District',
            'Mount Greylock Regional' => 'Mount Greylock School District',
            'Mount Everett Regional' => 'Mount Everett Regional School District',
            'Pioneer Valley Regional' => 'Pioneer Valley School District',
            'Manchester Essex Regional' => 'Manchester Essex Regional School District',
            'Narragansett Regional' => 'Narragansett Regional School District',
            'Narragansett Middle' => 'Narragansett Regional School District',
            'North Middlesex Regional' => 'North Middlesex Regional School District',
            'Tantasqua Regional' => 'Sturbridge School District',
        ];
    }
}
