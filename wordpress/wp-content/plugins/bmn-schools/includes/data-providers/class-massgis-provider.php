<?php
/**
 * MassGIS Data Provider
 *
 * Imports school location data from MassGIS (Massachusetts Geographic Information System).
 * Source: https://www.mass.gov/info-details/massgis-data-massachusetts-schools-pre-k-through-high-school
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
 * MassGIS Data Provider Class
 *
 * @since 0.2.0
 */
class BMN_Schools_MassGIS_Provider extends BMN_Schools_Base_Provider {

    /**
     * Provider name.
     *
     * @var string
     */
    protected $provider_name = 'massgis';

    /**
     * Display name.
     *
     * @var string
     */
    protected $display_name = 'MassGIS Schools';

    /**
     * Download URL for schools shapefile.
     *
     * @var string
     */
    private $download_url = 'https://s3.us-east-1.amazonaws.com/download.massgis.digital.mass.gov/shapefiles/state/schools.zip';

    /**
     * Run the data sync/import.
     *
     * @since 0.2.0
     * @param array $options Import options.
     * @return array Result with success status and message.
     */
    public function sync($options = []) {
        $start_time = microtime(true);

        $this->update_source_status('syncing');
        BMN_Schools_Logger::import_started($this->provider_name);

        try {
            // Download the shapefile
            $this->log_progress('Downloading MassGIS schools data...');
            $zip_file = $this->download_file($this->download_url, 'schools.zip');

            if (is_wp_error($zip_file)) {
                throw new Exception('Download failed: ' . $zip_file->get_error_message());
            }

            // Extract the shapefile
            $this->log_progress('Extracting shapefile...');
            $extracted_dir = $this->extract_shapefile($zip_file);

            if (is_wp_error($extracted_dir)) {
                throw new Exception('Extraction failed: ' . $extracted_dir->get_error_message());
            }

            // Find and parse the DBF file (contains attribute data)
            $dbf_file = $this->find_dbf_file($extracted_dir);

            if (!$dbf_file) {
                throw new Exception('DBF file not found in shapefile archive');
            }

            // Parse the DBF file
            $this->log_progress('Parsing school data...');
            $schools = $this->parse_dbf($dbf_file);

            if (is_wp_error($schools)) {
                throw new Exception('Parse failed: ' . $schools->get_error_message());
            }

            // Import schools
            $this->log_progress('Importing ' . count($schools) . ' schools...');
            $imported = $this->import_schools($schools);

            // Run post-import cleanup to fix regional school assignments
            $this->log_progress('Running post-import cleanup...');
            $cleanup_results = $this->run_post_import_cleanup();

            // Clean up
            $this->cleanup_temp_files();

            $duration_ms = (microtime(true) - $start_time) * 1000;

            $this->update_source_status('active', $imported);
            BMN_Schools_Logger::import_completed($this->provider_name, $imported, $duration_ms);

            return [
                'success' => true,
                'message' => sprintf('Imported %d schools from MassGIS', $imported),
                'count' => $imported,
                'duration_ms' => $duration_ms,
            ];

        } catch (Exception $e) {
            $this->cleanup_temp_files();
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
     * Extract shapefile from zip.
     *
     * @since 0.2.0
     * @param string $zip_file Path to zip file.
     * @return string|WP_Error Extracted directory or error.
     */
    private function extract_shapefile($zip_file) {
        $upload_dir = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/bmn-schools-temp/massgis/';

        if (!file_exists($extract_dir)) {
            wp_mkdir_p($extract_dir);
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_file);

        if ($result !== true) {
            return new WP_Error('zip_open_failed', 'Could not open zip file');
        }

        $zip->extractTo($extract_dir);
        $zip->close();

        // Delete the zip file
        unlink($zip_file);

        return $extract_dir;
    }

    /**
     * Find DBF file in extracted directory.
     *
     * @since 0.2.0
     * @param string $dir Directory to search.
     * @return string|null Path to DBF file or null.
     */
    private function find_dbf_file($dir) {
        $files = glob($dir . '*.dbf');

        if (empty($files)) {
            // Check subdirectories
            $files = glob($dir . '*/*.dbf');
        }

        return !empty($files) ? $files[0] : null;
    }

    /**
     * Parse DBF file (dBASE format used by shapefiles).
     *
     * @since 0.2.0
     * @param string $dbf_file Path to DBF file.
     * @return array|WP_Error Parsed records or error.
     */
    private function parse_dbf($dbf_file) {
        if (!function_exists('dbase_open')) {
            // Use custom DBF parser if dbase extension not available
            return $this->parse_dbf_manual($dbf_file);
        }

        $db = @dbase_open($dbf_file, 0);
        if (!$db) {
            return new WP_Error('dbf_open_failed', 'Could not open DBF file');
        }

        $records = [];
        $num_records = dbase_numrecords($db);

        for ($i = 1; $i <= $num_records; $i++) {
            $row = dbase_get_record_with_names($db, $i);
            if ($row) {
                unset($row['deleted']);
                $records[] = $row;
            }
        }

        dbase_close($db);

        return $records;
    }

    /**
     * Manual DBF parser (when dbase extension is not available).
     *
     * @since 0.2.0
     * @param string $dbf_file Path to DBF file.
     * @return array|WP_Error Parsed records or error.
     */
    private function parse_dbf_manual($dbf_file) {
        $handle = fopen($dbf_file, 'rb');
        if (!$handle) {
            return new WP_Error('dbf_open_failed', 'Could not open DBF file');
        }

        // Read header
        $header = fread($handle, 32);
        $header = unpack('Cversion/Cyear/Cmonth/Cday/Vrecords/vheaderlen/vrecordlen', $header);

        $num_records = $header['records'];
        $header_len = $header['headerlen'];
        $record_len = $header['recordlen'];

        // Read field descriptors
        fseek($handle, 32);
        $fields = [];

        while (ftell($handle) < $header_len - 1) {
            $field_data = fread($handle, 32);
            if (ord($field_data[0]) == 0x0D) {
                break;
            }

            $field = unpack('a11name/a1type/Voffset/Clength/Cdecimal', $field_data);
            $field['name'] = trim($field['name']);
            $fields[] = $field;
        }

        // Skip to data
        fseek($handle, $header_len);

        // Read records
        $records = [];

        for ($i = 0; $i < $num_records; $i++) {
            $record_data = fread($handle, $record_len);
            if (!$record_data || $record_data[0] == '*') {
                continue; // Deleted record
            }

            $record = [];
            $pos = 1; // Skip delete flag

            foreach ($fields as $field) {
                $value = substr($record_data, $pos, $field['length']);
                $record[$field['name']] = trim($value);
                $pos += $field['length'];
            }

            $records[] = $record;
        }

        fclose($handle);

        return $records;
    }

    /**
     * Import schools from parsed data.
     *
     * @since 0.2.0
     * @param array $schools Parsed school records.
     * @return int Number of schools imported.
     */
    private function import_schools($schools) {
        $imported = 0;
        $batch_count = 0;

        foreach ($schools as $school) {
            $school_data = $this->map_school_data($school);

            if ($school_data) {
                $result = $this->upsert_school($school_data);

                if (!is_wp_error($result)) {
                    $imported++;
                }
            }

            $batch_count++;

            // Log progress every 100 records
            if ($batch_count % 100 === 0) {
                $this->log_progress("Processed {$batch_count} schools...");
            }
        }

        return $imported;
    }

    /**
     * Map MassGIS data to our schema.
     *
     * MassGIS field names may vary. Common fields include:
     * - SCHID or ORG_CODE: School ID
     * - NAME or SCH_NAME: School name
     * - ADDRESS: Street address
     * - TOWN or CITY: City
     * - ZIP: ZIP code
     * - POINT_X, POINT_Y or LON, LAT: Coordinates
     * - TYPE or SCH_TYPE: School type
     * - GRADES: Grade levels
     *
     * @since 0.2.0
     * @param array $row Raw data row.
     * @return array|null Mapped data or null if invalid.
     */
    private function map_school_data($row) {
        // Normalize field names (uppercase)
        $row = array_change_key_case($row, CASE_UPPER);

        // Get school name
        $name = $this->get_field($row, ['NAME', 'SCH_NAME', 'SCHOOL_NAM', 'SCHOOLNAME']);
        if (empty($name)) {
            return null;
        }

        // Get coordinates
        $lat = $this->get_field($row, ['POINT_Y', 'LAT', 'LATITUDE', 'Y']);
        $lng = $this->get_field($row, ['POINT_X', 'LON', 'LONG', 'LONGITUDE', 'X']);

        // Get school ID
        $school_id = $this->get_field($row, ['SCHID', 'ORG_CODE', 'SCHOOL_ID', 'ID', 'NCES_ID']);

        // Determine school type (also checks name for private school patterns)
        $type_raw = $this->get_field($row, ['TYPE', 'SCH_TYPE', 'SCHOOL_TYP', 'CATEGORY']);
        $school_type = $this->map_school_type($type_raw, $name);

        // Get grades
        $grades_raw = $this->get_field($row, ['GRADES', 'GRADE_SPAN', 'GRADELVL']);
        $grades = $this->parse_grades($grades_raw);

        // Get address components
        $address = $this->get_field($row, ['ADDRESS', 'STREET', 'ADDR']);
        $city = $this->get_field($row, ['TOWN', 'CITY', 'MUNICIP']);
        $zip = $this->get_field($row, ['ZIP', 'ZIP_CODE', 'ZIPCODE']);

        // Get district info
        $district_name = $this->get_field($row, ['DISTRICT', 'DIST_NAME', 'DISTNAME']);
        $district_id = $this->get_field($row, ['DISTCODE', 'DIST_CODE', 'DIST_ID']);

        return [
            'state_school_id' => $school_id,
            'name' => $name,
            'school_type' => $school_type,
            'level' => $grades ? $this->determine_level($grades['low'], $grades['high']) : null,
            'grades_low' => $grades ? $grades['low'] : null,
            'grades_high' => $grades ? $grades['high'] : null,
            'address' => $address,
            'city' => $city,
            'state' => 'MA',
            'zip' => $zip,
            'latitude' => !empty($lat) ? floatval($lat) : null,
            'longitude' => !empty($lng) ? floatval($lng) : null,
        ];
    }

    /**
     * Get field value from multiple possible field names.
     *
     * @since 0.2.0
     * @param array $row        Data row.
     * @param array $field_names Possible field names.
     * @return string|null Field value or null.
     */
    private function get_field($row, $field_names) {
        foreach ($field_names as $name) {
            if (!empty($row[$name])) {
                return trim($row[$name]);
            }
        }
        return null;
    }

    /**
     * Map school type to our enum.
     *
     * @since 0.2.0
     * @since 0.6.28 Added name-based detection for private schools
     * @param string $type_raw Raw type string.
     * @param string $name     School name (optional, for additional detection).
     * @return string Normalized type.
     */
    private function map_school_type($type_raw, $name = '') {
        // First check the type field
        if (!empty($type_raw)) {
            $type = strtolower($type_raw);

            if (strpos($type, 'private') !== false || strpos($type, 'parochial') !== false) {
                return 'private';
            }

            if (strpos($type, 'charter') !== false) {
                return 'charter';
            }
        }

        // Check school name for private school indicators
        // Many private schools are incorrectly labeled as public in MassGIS
        if (!empty($name) && $this->is_likely_private_school($name)) {
            return 'private';
        }

        return 'public';
    }

    /**
     * Check if a school is likely private based on its name.
     *
     * @since 0.6.28
     * @param string $name School name.
     * @return bool True if likely private.
     */
    private function is_likely_private_school($name) {
        // Skip charter and innovation schools (they are public)
        if (stripos($name, 'Charter') !== false ||
            stripos($name, 'Innovation') !== false ||
            stripos($name, 'Horace Mann') !== false) {
            return false;
        }

        // Private school name patterns
        $private_patterns = [
            // Religious schools
            'Catholic',
            'St. ',
            'Saint ',
            'Notre Dame',
            'Christian', // but not Charter
            'Hebrew',
            'Jewish',
            'Lutheran',
            'Baptist',
            ' SDA ',
            'SDA School',
            'Seventh Day',
            'Holy ', // but not Holyoke
            'Immaculata',
            'Montessori',
            'Waldorf',
            'Aquinas',

            // Independent/Day schools
            'Day School',
            ', Inc.',
            ', Inc',
            'Country Day',

            // Therapeutic/Special Ed private
            'Therapeutic',
            'JRI ',
            'Devereux',
            'Perkins School',
            'Learning Group',
            'Eagle Hill School',
            'Landmark School',
            'Meadowridge',
            'Collaborative', // but not Charter

            // Known private school names
            'Buxton School',
            'Fusion Academy',
            'CATS Academy',
            'Berkshire School',
            'Brimmer',
            'Belmont Day',
            'Carroll School',
            'Birches School',
            'Willow Hill',
            'Corwin-Russell',
            'Pine Cobble',
            'Brookwood School',
            'Fenn School',
            'Middlesex School',
            'Concord Academy',
        ];

        foreach ($private_patterns as $pattern) {
            if (stripos($name, $pattern) !== false) {
                // Special case: "Holy" should not match "Holyoke"
                if ($pattern === 'Holy ' && stripos($name, 'Holyoke') !== false) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Parse grade range string.
     *
     * @since 0.2.0
     * @param string $grades_raw Raw grades string (e.g., "K-5", "9-12", "PK-8").
     * @return array|null Array with 'low' and 'high' grades.
     */
    private function parse_grades($grades_raw) {
        if (empty($grades_raw)) {
            return null;
        }

        // Handle various formats: "K-5", "PK-8", "09-12", "K,1,2,3,4,5"
        $grades = strtoupper(trim($grades_raw));

        // Try dash-separated format
        if (strpos($grades, '-') !== false) {
            $parts = explode('-', $grades);
            if (count($parts) === 2) {
                return [
                    'low' => trim($parts[0]),
                    'high' => trim($parts[1]),
                ];
            }
        }

        // Try comma-separated format
        if (strpos($grades, ',') !== false) {
            $parts = explode(',', $grades);
            return [
                'low' => trim($parts[0]),
                'high' => trim(end($parts)),
            ];
        }

        // Single grade
        return [
            'low' => $grades,
            'high' => $grades,
        ];
    }

    /**
     * Clean up extracted files.
     *
     * @since 0.2.0
     */
    protected function cleanup_temp_files() {
        parent::cleanup_temp_files();

        $upload_dir = wp_upload_dir();
        $massgis_dir = $upload_dir['basedir'] . '/bmn-schools-temp/massgis/';

        if (is_dir($massgis_dir)) {
            $this->recursive_delete($massgis_dir);
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @since 0.2.0
     * @param string $dir Directory path.
     */
    private function recursive_delete($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursive_delete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
