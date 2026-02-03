<?php
/**
 * Database Manager Class
 *
 * Handles creation, management, and queries for all school-related database tables.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Database Manager Class
 *
 * @since 0.1.0
 */
class BMN_Schools_Database_Manager {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Charset and collation for table creation.
     *
     * @var string
     */
    private $charset_collate;

    /**
     * Table names with prefix.
     *
     * @var array
     */
    private $tables;

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct() {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();

        // Ensure we use utf8mb4_unicode_520_ci for UNION compatibility
        if (strpos($this->charset_collate, 'utf8mb4') !== false) {
            $this->charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
        }

        $this->tables = [
            'schools' => $wpdb->prefix . 'bmn_schools',
            'districts' => $wpdb->prefix . 'bmn_school_districts',
            'locations' => $wpdb->prefix . 'bmn_school_locations',
            'test_scores' => $wpdb->prefix . 'bmn_school_test_scores',
            'rankings' => $wpdb->prefix . 'bmn_school_rankings',
            'demographics' => $wpdb->prefix . 'bmn_school_demographics',
            'features' => $wpdb->prefix . 'bmn_school_features',
            'attendance_zones' => $wpdb->prefix . 'bmn_school_attendance_zones',
            'data_sources' => $wpdb->prefix . 'bmn_school_data_sources',
            'activity_log' => $wpdb->prefix . 'bmn_schools_activity_log',
            'state_benchmarks' => $wpdb->prefix . 'bmn_state_benchmarks',
            'district_rankings' => $wpdb->prefix . 'bmn_district_rankings',
            'discipline' => $wpdb->prefix . 'bmn_school_discipline',
            'sports' => $wpdb->prefix . 'bmn_school_sports',
        ];
    }

    /**
     * Get table name by key.
     *
     * @since 0.1.0
     * @param string $key Table key.
     * @return string|null Table name with prefix.
     */
    public function get_table($key) {
        return isset($this->tables[$key]) ? $this->tables[$key] : null;
    }

    /**
     * Get all table names.
     *
     * @since 0.1.0
     * @return array Table names.
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Get all table names (alias for get_tables).
     *
     * @since 0.6.12
     * @return array Table names.
     */
    public function get_table_names() {
        return $this->tables;
    }

    /**
     * Create all database tables.
     *
     * @since 0.1.0
     * @return array Results of table creation.
     */
    public function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $results = [];

        $results['schools'] = $this->create_schools_table();
        $results['districts'] = $this->create_districts_table();
        $results['locations'] = $this->create_locations_table();
        $results['test_scores'] = $this->create_test_scores_table();
        $results['rankings'] = $this->create_rankings_table();
        $results['demographics'] = $this->create_demographics_table();
        $results['features'] = $this->create_features_table();
        $results['attendance_zones'] = $this->create_attendance_zones_table();
        $results['data_sources'] = $this->create_data_sources_table();
        $results['activity_log'] = $this->create_activity_log_table();
        $results['state_benchmarks'] = $this->create_state_benchmarks_table();
        $results['district_rankings'] = $this->create_district_rankings_table();
        $results['discipline'] = $this->create_discipline_table();
        $results['sports'] = $this->create_sports_table();

        return $results;
    }

    /**
     * Create schools table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_schools_table() {
        $table_name = $this->tables['schools'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nces_school_id VARCHAR(12) DEFAULT NULL,
            state_school_id VARCHAR(20) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            school_type VARCHAR(20) DEFAULT 'public',
            level VARCHAR(20) DEFAULT NULL,
            grades_low VARCHAR(5) DEFAULT NULL,
            grades_high VARCHAR(5) DEFAULT NULL,
            district_id BIGINT UNSIGNED DEFAULT NULL,
            address VARCHAR(255) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(2) DEFAULT 'MA',
            zip VARCHAR(10) DEFAULT NULL,
            county VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(11,8) DEFAULT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            enrollment INT UNSIGNED DEFAULT NULL,
            student_teacher_ratio DECIMAL(5,2) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_nces_id (nces_school_id),
            KEY idx_state_id (state_school_id),
            KEY idx_city (city),
            KEY idx_zip (zip),
            KEY idx_district (district_id),
            KEY idx_type (school_type),
            KEY idx_level (level),
            KEY idx_location (latitude, longitude)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create districts table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_districts_table() {
        $table_name = $this->tables['districts'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nces_district_id VARCHAR(7) DEFAULT NULL,
            state_district_id VARCHAR(8) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) DEFAULT NULL,
            grades_low VARCHAR(5) DEFAULT NULL,
            grades_high VARCHAR(5) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            county VARCHAR(100) DEFAULT NULL,
            state VARCHAR(2) DEFAULT 'MA',
            total_schools INT UNSIGNED DEFAULT 0,
            total_students INT UNSIGNED DEFAULT 0,
            boundary_geojson LONGTEXT DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_nces_id (nces_district_id),
            KEY idx_state_id (state_district_id),
            KEY idx_city (city),
            KEY idx_county (county)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create school-to-location mapping table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_locations_table() {
        $table_name = $this->tables['locations'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            location_type VARCHAR(20) NOT NULL,
            location_value VARCHAR(100) NOT NULL,
            is_primary TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school (school_id),
            KEY idx_location (location_type, location_value),
            KEY idx_primary (is_primary)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create test scores table (MCAS).
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_test_scores_table() {
        $table_name = $this->tables['test_scores'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            grade VARCHAR(5) DEFAULT NULL,
            subject VARCHAR(50) NOT NULL,
            test_name VARCHAR(100) DEFAULT 'MCAS',
            students_tested INT UNSIGNED DEFAULT NULL,
            proficient_or_above_pct DECIMAL(5,2) DEFAULT NULL,
            advanced_pct DECIMAL(5,2) DEFAULT NULL,
            proficient_pct DECIMAL(5,2) DEFAULT NULL,
            needs_improvement_pct DECIMAL(5,2) DEFAULT NULL,
            warning_pct DECIMAL(5,2) DEFAULT NULL,
            avg_scaled_score DECIMAL(6,2) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school_year (school_id, year),
            KEY idx_subject (subject),
            KEY idx_grade (grade),
            UNIQUE KEY idx_unique_score (school_id, year, grade, subject)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create rankings table.
     *
     * Updated in v0.6.0 to support calculated composite scores.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_rankings_table() {
        $table_name = $this->tables['rankings'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            category VARCHAR(30) DEFAULT NULL,
            composite_score DECIMAL(5,2) DEFAULT NULL,
            percentile_rank INT UNSIGNED DEFAULT NULL,
            state_rank INT UNSIGNED DEFAULT NULL,
            mcas_score DECIMAL(5,2) DEFAULT NULL,
            graduation_score DECIMAL(5,2) DEFAULT NULL,
            masscore_score DECIMAL(5,2) DEFAULT NULL,
            attendance_score DECIMAL(5,2) DEFAULT NULL,
            ap_score DECIMAL(5,2) DEFAULT NULL,
            growth_score DECIMAL(5,2) DEFAULT NULL,
            spending_score DECIMAL(5,2) DEFAULT NULL,
            ratio_score DECIMAL(5,2) DEFAULT NULL,
            calculated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school_year (school_id, year),
            KEY idx_category (category),
            KEY idx_composite (composite_score),
            KEY idx_percentile (percentile_rank),
            KEY idx_state_rank (state_rank),
            UNIQUE KEY idx_unique_ranking (school_id, year)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create demographics table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_demographics_table() {
        $table_name = $this->tables['demographics'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            total_students INT UNSIGNED DEFAULT NULL,
            pct_male DECIMAL(5,2) DEFAULT NULL,
            pct_female DECIMAL(5,2) DEFAULT NULL,
            pct_white DECIMAL(5,2) DEFAULT NULL,
            pct_black DECIMAL(5,2) DEFAULT NULL,
            pct_hispanic DECIMAL(5,2) DEFAULT NULL,
            pct_asian DECIMAL(5,2) DEFAULT NULL,
            pct_native_american DECIMAL(5,2) DEFAULT NULL,
            pct_pacific_islander DECIMAL(5,2) DEFAULT NULL,
            pct_multiracial DECIMAL(5,2) DEFAULT NULL,
            pct_free_reduced_lunch DECIMAL(5,2) DEFAULT NULL,
            pct_english_learner DECIMAL(5,2) DEFAULT NULL,
            pct_special_ed DECIMAL(5,2) DEFAULT NULL,
            avg_class_size DECIMAL(4,1) DEFAULT NULL,
            teacher_count INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school_year (school_id, year),
            UNIQUE KEY idx_unique_demo (school_id, year)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create features table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_features_table() {
        $table_name = $this->tables['features'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            feature_type VARCHAR(50) NOT NULL,
            feature_name VARCHAR(100) NOT NULL,
            feature_value TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school (school_id),
            KEY idx_type (feature_type),
            KEY idx_name (feature_name(50)),
            KEY idx_school_type (school_id, feature_type)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create attendance zones table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_attendance_zones_table() {
        $table_name = $this->tables['attendance_zones'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            zone_type VARCHAR(20) DEFAULT NULL,
            boundary_geojson LONGTEXT DEFAULT NULL,
            source VARCHAR(50) DEFAULT NULL,
            effective_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_school (school_id),
            KEY idx_type (zone_type),
            KEY idx_source (source)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create data sources table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_data_sources_table() {
        $table_name = $this->tables['data_sources'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_name VARCHAR(50) NOT NULL,
            source_type VARCHAR(50) DEFAULT NULL,
            source_url VARCHAR(255) DEFAULT NULL,
            api_key_option VARCHAR(100) DEFAULT NULL,
            last_sync DATETIME DEFAULT NULL,
            next_sync DATETIME DEFAULT NULL,
            records_synced INT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            config LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_source_name (source_name),
            KEY idx_status (status)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create activity log table.
     *
     * @since 0.1.0
     * @return bool Success.
     */
    private function create_activity_log_table() {
        $table_name = $this->tables['activity_log'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(20) NOT NULL,
            type VARCHAR(50) NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_level (level),
            KEY idx_type (type),
            KEY idx_source (source)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create state benchmarks table.
     *
     * Stores aggregate statistics (averages, medians, percentiles) for comparing
     * individual schools against state-wide metrics.
     *
     * @since 0.6.7
     * @return bool Success.
     */
    private function create_state_benchmarks_table() {
        $table_name = $this->tables['state_benchmarks'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year YEAR NOT NULL,
            metric_type VARCHAR(50) NOT NULL,
            category VARCHAR(50) DEFAULT 'all',
            subject VARCHAR(50) DEFAULT NULL,
            state_average DECIMAL(8,2) DEFAULT NULL,
            state_median DECIMAL(8,2) DEFAULT NULL,
            percentile_25 DECIMAL(8,2) DEFAULT NULL,
            percentile_75 DECIMAL(8,2) DEFAULT NULL,
            sample_size INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_unique_benchmark (year, metric_type, category, subject),
            KEY idx_year (year),
            KEY idx_metric (metric_type),
            KEY idx_category (category)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create district rankings table.
     *
     * Stores aggregate rankings for school districts based on their schools'
     * composite scores.
     *
     * @since 0.6.7
     * @return bool Success.
     */
    private function create_district_rankings_table() {
        $table_name = $this->tables['district_rankings'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            district_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            composite_score DECIMAL(5,2) DEFAULT NULL,
            percentile_rank INT UNSIGNED DEFAULT NULL,
            state_rank INT UNSIGNED DEFAULT NULL,
            letter_grade VARCHAR(2) DEFAULT NULL,
            schools_count INT UNSIGNED DEFAULT 0,
            schools_with_data INT UNSIGNED DEFAULT 0,
            elementary_avg DECIMAL(5,2) DEFAULT NULL,
            middle_avg DECIMAL(5,2) DEFAULT NULL,
            high_avg DECIMAL(5,2) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_district_year (district_id, year),
            KEY idx_year (year),
            KEY idx_score (composite_score),
            KEY idx_rank (state_rank)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create discipline table.
     *
     * Stores school safety and discipline data from DESE SSDR reports.
     * Data includes suspension rates, expulsion rates, and other discipline metrics.
     *
     * @since 0.6.22
     * @return bool Success.
     */
    private function create_discipline_table() {
        $table_name = $this->tables['discipline'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            enrollment INT UNSIGNED DEFAULT NULL,
            students_disciplined INT UNSIGNED DEFAULT NULL,
            in_school_suspension_pct DECIMAL(5,2) DEFAULT NULL,
            out_of_school_suspension_pct DECIMAL(5,2) DEFAULT NULL,
            expulsion_pct DECIMAL(5,2) DEFAULT NULL,
            removed_to_alternate_pct DECIMAL(5,2) DEFAULT NULL,
            emergency_removal_pct DECIMAL(5,2) DEFAULT NULL,
            school_based_arrest_pct DECIMAL(5,2) DEFAULT NULL,
            law_enforcement_referral_pct DECIMAL(5,2) DEFAULT NULL,
            discipline_rate DECIMAL(5,2) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_school_year (school_id, year),
            KEY idx_year (year),
            KEY idx_discipline_rate (discipline_rate)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Create school sports table.
     *
     * Stores MIAA sports participation data per school.
     * Data source: MIAA Participation Survey (https://www.miaa.net/about-miaa/participation-survey-data)
     *
     * @since 0.6.24
     * @return bool True if table exists after creation.
     */
    private function create_sports_table() {
        $table_name = $this->tables['sports'];

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id BIGINT UNSIGNED NOT NULL,
            year YEAR NOT NULL,
            sport VARCHAR(100) NOT NULL,
            gender ENUM('Boys', 'Girls', 'Coed') NOT NULL,
            participants INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_school_sport (school_id, year, sport, gender),
            KEY idx_school_id (school_id),
            KEY idx_sport (sport),
            KEY idx_year (year)
        ) {$this->charset_collate};";

        dbDelta($sql);

        return $this->table_exists($table_name);
    }

    /**
     * Check if a table exists.
     *
     * @since 0.1.0
     * @param string $table_name Full table name.
     * @return bool True if exists.
     */
    public function table_exists($table_name) {
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Verify all tables exist.
     *
     * @since 0.1.0
     * @return array Status of each table.
     */
    public function verify_tables() {
        $status = [];

        foreach ($this->tables as $key => $table_name) {
            $status[$key] = [
                'table' => $table_name,
                'exists' => $this->table_exists($table_name),
            ];

            if ($status[$key]['exists']) {
                $status[$key]['count'] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            }
        }

        return $status;
    }

    /**
     * Drop all tables (for uninstall).
     *
     * @since 0.1.0
     * @return bool Success.
     */
    public function drop_tables() {
        foreach ($this->tables as $table_name) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        }

        return true;
    }

    /**
     * Get table statistics.
     *
     * @since 0.1.0
     * @return array Statistics.
     */
    public function get_stats() {
        $stats = [];

        foreach ($this->tables as $key => $table_name) {
            if ($this->table_exists($table_name)) {
                $stats[$key] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            } else {
                $stats[$key] = null;
            }
        }

        return $stats;
    }

    /**
     * Map schools to districts based on city name matching.
     *
     * This method automatically assigns district_id to schools that don't have one,
     * using multiple matching strategies:
     * 1. Exact match: "BOSTON" -> "Boston School District"
     * 2. Regional first position: "ACTON" -> "Acton-Boxborough Regional School District"
     * 3. Regional second position: "YARMOUTH" -> "Dennis-Yarmouth School District"
     *
     * @since 0.6.16
     * @return array Results with counts of mapped schools.
     */
    public function map_schools_to_districts() {
        $schools_table = $this->tables['schools'];
        $districts_table = $this->tables['districts'];
        $results = [
            'total_unmapped_before' => 0,
            'mapped_exact' => 0,
            'mapped_regional_first' => 0,
            'mapped_regional_second' => 0,
            'total_unmapped_after' => 0,
        ];

        // Count unmapped schools before
        $results['total_unmapped_before'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$schools_table} WHERE district_id IS NULL AND city IS NOT NULL"
        );

        if ($results['total_unmapped_before'] === 0) {
            return $results;
        }

        // Strategy 1: Exact match "City School District"
        $mapped = $this->wpdb->query(
            "UPDATE {$schools_table} s
             JOIN {$districts_table} d ON d.name = CONCAT(s.city, ' School District')
             SET s.district_id = d.id
             WHERE s.city IS NOT NULL AND s.district_id IS NULL"
        );
        $results['mapped_exact'] = (int) $mapped;

        // Strategy 2: Regional district - city in first position (e.g., "Acton-Boxborough")
        $mapped = $this->wpdb->query(
            "UPDATE {$schools_table} s
             SET s.district_id = (
                 SELECT d.id FROM {$districts_table} d
                 WHERE d.name LIKE CONCAT(s.city, '-%')
                    OR d.name LIKE CONCAT(s.city, ' %')
                 LIMIT 1
             )
             WHERE s.city IS NOT NULL
             AND s.district_id IS NULL
             AND EXISTS (
                 SELECT 1 FROM {$districts_table} d
                 WHERE d.name LIKE CONCAT(s.city, '-%')
                    OR d.name LIKE CONCAT(s.city, ' %')
             )"
        );
        $results['mapped_regional_first'] = (int) $mapped;

        // Strategy 3: Regional district - city in second position (e.g., "Dennis-Yarmouth")
        $mapped = $this->wpdb->query(
            "UPDATE {$schools_table} s
             SET s.district_id = (
                 SELECT d.id FROM {$districts_table} d
                 WHERE d.name LIKE CONCAT('%-', s.city, '%')
                 LIMIT 1
             )
             WHERE s.city IS NOT NULL
             AND s.district_id IS NULL
             AND EXISTS (
                 SELECT 1 FROM {$districts_table} d
                 WHERE d.name LIKE CONCAT('%-', s.city, '%')
             )"
        );
        $results['mapped_regional_second'] = (int) $mapped;

        // Count remaining unmapped schools
        $results['total_unmapped_after'] = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$schools_table} WHERE district_id IS NULL AND city IS NOT NULL"
        );

        return $results;
    }
}
