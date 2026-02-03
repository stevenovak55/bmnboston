<?php
/**
 * MLS Listings Display Database Migrator
 *
 * Handles all database migrations including schema updates, index creation,
 * and table structure modifications. Works with the upgrader system to ensure
 * all database changes are applied correctly during plugin updates.
 *
 * @package MLS_Listings_Display
 * @since 4.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Database_Migrator {

    /**
     * Migration history option key
     */
    const MIGRATION_HISTORY_OPTION = 'mld_database_migration_history';

    /**
     * Migration status option key
     */
    const MIGRATION_STATUS_OPTION = 'mld_database_migration_status';

    /**
     * Migration results
     */
    private $results = array();

    /**
     * Run all migrations from one version to another
     *
     * @param string $from_version Starting version
     * @param string $to_version Target version
     * @return array Migration results
     */
    public function run_all_migrations($from_version, $to_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Database Migrator: Running migrations from {$from_version} to {$to_version}");
        }

        $start_time = microtime(true);

        // Set migration status
        update_option(self::MIGRATION_STATUS_OPTION, array(
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'from_version' => $from_version,
            'to_version' => $to_version
        ));

        try {
            // Phase 1: Performance indexes
            $this->results['performance_indexes'] = $this->apply_performance_indexes();

            // Phase 2: Table structure updates
            $this->results['table_updates'] = $this->apply_table_updates($from_version);

            // Phase 3: Data migrations
            $this->results['data_migrations'] = $this->apply_data_migrations($from_version);

            // Phase 4: Index optimizations
            $this->results['index_optimizations'] = $this->apply_index_optimizations();

            // Phase 5: Foreign key constraints
            $this->results['foreign_keys'] = $this->apply_foreign_key_constraints();

            // Calculate migration duration
            $duration = round(microtime(true) - $start_time, 2);
            $this->results['duration'] = $duration;

            // Update migration status to completed
            update_option(self::MIGRATION_STATUS_OPTION, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'from_version' => $from_version,
                'to_version' => $to_version,
                'duration' => $duration,
                'results' => $this->results
            ));

            // Store migration history
            $this->store_migration_history($from_version, $to_version, $this->results);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Database Migrator: Migrations completed successfully in {$duration} seconds");
            }

            return $this->results;

        } catch (Exception $e) {
            $error_message = 'Database migration failed: ' . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($error_message);
            }

            // Update migration status to failed
            update_option(self::MIGRATION_STATUS_OPTION, array(
                'status' => 'failed',
                'failed_at' => current_time('mysql'),
                'from_version' => $from_version,
                'to_version' => $to_version,
                'error' => $error_message,
                'results' => $this->results
            ));

            $this->results['error'] = $error_message;
            return $this->results;
        }
    }

    /**
     * Apply performance indexes
     *
     * @return array Index creation results
     */
    private function apply_performance_indexes() {
        global $wpdb;
        $results = array();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Migrator: Applying performance indexes');
        }

        try {
            // Core MLS Display table indexes
            $results['form_submissions'] = $this->create_form_submission_indexes();
            $results['city_boundaries'] = $this->create_city_boundary_indexes();
            $results['schools'] = $this->create_school_indexes();
            $results['saved_searches'] = $this->create_saved_search_indexes();
            $results['notifications'] = $this->create_notification_indexes();
            $results['agent_management'] = $this->create_agent_management_indexes();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Performance indexes applied successfully');
            }
            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Performance index application failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Create form submission indexes
     *
     * @return array Results
     */
    private function create_form_submission_indexes() {
        $table = $this->get_table_name('mld_form_submissions');
        $results = array();

        if (!$this->table_exists($table)) {
            return $results;
        }

        $existing_columns = $this->get_table_columns($table);
        $indexes = array();

        // Only create indexes for columns that exist
        if (in_array('created_at', $existing_columns)) {
            $indexes['idx_created_at'] = array('created_at');
        }
        if (in_array('form_type', $existing_columns)) {
            $indexes['idx_form_type'] = array('form_type');
        }
        if (in_array('status', $existing_columns)) {
            $indexes['idx_status'] = array('status');
        }
        if (in_array('property_mls', $existing_columns)) {
            $indexes['idx_property_mls'] = array('property_mls');
        }
        if (in_array('email', $existing_columns)) {
            $indexes['idx_email'] = array('email');
        }
        if (in_array('form_type', $existing_columns) && in_array('status', $existing_columns)) {
            $indexes['idx_type_status'] = array('form_type', 'status');
        }
        if (in_array('status', $existing_columns) && in_array('created_at', $existing_columns)) {
            $indexes['idx_status_date'] = array('status', 'created_at');
        }

        foreach ($indexes as $index_name => $columns) {
            $results[$index_name] = $this->create_index($table, $index_name, $columns);
        }

        return $results;
    }

    /**
     * Create city boundary indexes
     *
     * @return array Results
     */
    private function create_city_boundary_indexes() {
        $table = $this->get_table_name('mld_city_boundaries');
        $results = array();

        if (!$this->table_exists($table)) {
            return $results;
        }

        $existing_columns = $this->get_table_columns($table);
        $indexes = array();

        // Only create indexes for columns that exist
        if (in_array('city', $existing_columns)) {
            $indexes['idx_city'] = array('city');
        }
        if (in_array('state', $existing_columns)) {
            $indexes['idx_state'] = array('state');
        }
        if (in_array('boundary_type', $existing_columns)) {
            $indexes['idx_boundary_type'] = array('boundary_type');
        }
        if (in_array('city', $existing_columns) && in_array('state', $existing_columns)) {
            $indexes['idx_city_state'] = array('city', 'state');
        }
        if (in_array('display_name', $existing_columns)) {
            $indexes['idx_display_name'] = array('display_name');
        }
        if (in_array('created_at', $existing_columns)) {
            $indexes['idx_created_at'] = array('created_at');
        }

        foreach ($indexes as $index_name => $columns) {
            $results[$index_name] = $this->create_index($table, $index_name, $columns);
        }

        return $results;
    }

    /**
     * Create school indexes
     *
     * @return array Results
     */
    private function create_school_indexes() {
        $schools_table = $this->get_table_name('mld_schools');
        $property_schools_table = $this->get_table_name('mld_property_schools');
        $results = array();

        // Schools table indexes
        if ($this->table_exists($schools_table)) {
            $existing_columns = $this->get_table_columns($schools_table);
            $school_indexes = array();

            // Only create indexes for columns that exist
            // Check for name column (could be 'name' or 'school_name')
            if (in_array('name', $existing_columns)) {
                $school_indexes['idx_name'] = array('name');
            } elseif (in_array('school_name', $existing_columns)) {
                $school_indexes['idx_school_name'] = array('school_name');
            }

            if (in_array('district_name', $existing_columns)) {
                $school_indexes['idx_district_name'] = array('district_name');
            }

            // Check for type column (could be 'type' or 'school_type')
            if (in_array('type', $existing_columns)) {
                $school_indexes['idx_type'] = array('type');
            } elseif (in_array('school_type', $existing_columns)) {
                $school_indexes['idx_school_type'] = array('school_type');
            }

            if (in_array('level', $existing_columns)) {
                $school_indexes['idx_level'] = array('level');
            }

            if (in_array('city', $existing_columns)) {
                $school_indexes['idx_city'] = array('city');
            }

            if (in_array('state', $existing_columns)) {
                $school_indexes['idx_state'] = array('state');
            }

            // Check for rating column (could be 'rating' or 'school_rating')
            if (in_array('rating', $existing_columns)) {
                $school_indexes['idx_rating'] = array('rating');
            } elseif (in_array('school_rating', $existing_columns)) {
                $school_indexes['idx_school_rating'] = array('school_rating');
            }

            foreach ($school_indexes as $index_name => $columns) {
                $results['schools_' . $index_name] = $this->create_index($schools_table, $index_name, $columns);
            }
        }

        // Property-schools relationship indexes
        if ($this->table_exists($property_schools_table)) {
            $existing_columns = $this->get_table_columns($property_schools_table);
            $property_indexes = array();

            // Check for listing_id or property_mls
            if (in_array('listing_id', $existing_columns)) {
                $property_indexes['idx_listing_id'] = array('listing_id');
            } elseif (in_array('property_mls', $existing_columns)) {
                $property_indexes['idx_property_mls'] = array('property_mls');
            }

            if (in_array('school_id', $existing_columns)) {
                $property_indexes['idx_school_id'] = array('school_id');
            }

            if (in_array('distance', $existing_columns)) {
                $property_indexes['idx_distance'] = array('distance');
            }

            if (in_array('school_type', $existing_columns)) {
                $property_indexes['idx_school_type'] = array('school_type');
            }

            foreach ($property_indexes as $index_name => $columns) {
                $results['property_schools_' . $index_name] = $this->create_index($property_schools_table, $index_name, $columns);
            }
        }

        return $results;
    }

    /**
     * Create saved search indexes
     *
     * @return array Results
     */
    private function create_saved_search_indexes() {
        $results = array();

        // Saved searches table
        $saved_searches_table = $this->get_table_name('mld_saved_searches');
        if ($this->table_exists($saved_searches_table)) {
            // Get existing columns to avoid creating indexes on non-existent columns
            $existing_columns = $this->get_table_columns($saved_searches_table);

            // Build indexes only for columns that exist
            $search_indexes = array();

            // Always try user_id since it's required
            if (in_array('user_id', $existing_columns)) {
                $search_indexes['idx_user_id'] = array('user_id');
            }

            // Check for is_active column
            if (in_array('is_active', $existing_columns)) {
                $search_indexes['idx_is_active'] = array('is_active');
                if (in_array('user_id', $existing_columns)) {
                    $search_indexes['idx_user_active'] = array('user_id', 'is_active');
                }
            }

            // Check for notification_frequency or frequency column
            if (in_array('notification_frequency', $existing_columns)) {
                $search_indexes['idx_notification_frequency'] = array('notification_frequency');
            } elseif (in_array('frequency', $existing_columns)) {
                $search_indexes['idx_frequency'] = array('frequency');
            }

            // Check for created_at column
            if (in_array('created_at', $existing_columns)) {
                $search_indexes['idx_created_at'] = array('created_at');
            }

            // Check for last_notified or last_run column
            if (in_array('last_notified', $existing_columns)) {
                $search_indexes['idx_last_notified'] = array('last_notified');
            } elseif (in_array('last_run', $existing_columns)) {
                $search_indexes['idx_last_run'] = array('last_run');
            }

            foreach ($search_indexes as $index_name => $columns) {
                $results['saved_searches_' . $index_name] = $this->create_index($saved_searches_table, $index_name, $columns);
            }
        }

        // Saved search results table
        $results_table = $this->get_table_name('mld_saved_search_results');
        if ($this->table_exists($results_table)) {
            // Check if table actually has the expected columns
            $existing_columns = $this->get_table_columns($results_table);
            $result_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('search_id', $existing_columns)) {
                $result_indexes['idx_search_id'] = array('search_id');
            }
            if (in_array('property_mls', $existing_columns)) {
                $result_indexes['idx_property_mls'] = array('property_mls');
            }
            if (in_array('match_type', $existing_columns)) {
                $result_indexes['idx_match_type'] = array('match_type');
            }
            if (in_array('is_new', $existing_columns)) {
                $result_indexes['idx_is_new'] = array('is_new');
                if (in_array('search_id', $existing_columns)) {
                    $result_indexes['idx_search_new'] = array('search_id', 'is_new');
                }
            }
            if (in_array('created_at', $existing_columns)) {
                $result_indexes['idx_created_at'] = array('created_at');
            }

            foreach ($result_indexes as $index_name => $columns) {
                $results['search_results_' . $index_name] = $this->create_index($results_table, $index_name, $columns);
            }
        }

        return $results;
    }

    /**
     * Create notification indexes
     *
     * @return array Results
     */
    private function create_notification_indexes() {
        $results = array();

        // Notification tracker table (actual table used in the plugin)
        $tracker_table = $this->get_table_name('mld_notification_tracker');
        if ($this->table_exists($tracker_table)) {
            $existing_columns = $this->get_table_columns($tracker_table);
            $tracker_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('user_id', $existing_columns)) {
                $tracker_indexes['idx_user_id'] = array('user_id');
            }
            if (in_array('mls_number', $existing_columns)) {
                $tracker_indexes['idx_mls_number'] = array('mls_number');
            }
            if (in_array('search_id', $existing_columns)) {
                $tracker_indexes['idx_search_id'] = array('search_id');
            }
            if (in_array('sent_at', $existing_columns)) {
                $tracker_indexes['idx_sent_at'] = array('sent_at');
            }
            if (in_array('notification_type', $existing_columns)) {
                $tracker_indexes['idx_notification_type'] = array('notification_type');
            }

            foreach ($tracker_indexes as $index_name => $columns) {
                $results['tracker_' . $index_name] = $this->create_index($tracker_table, $index_name, $columns);
            }
        }

        // Notification queue table (if it exists)
        $queue_table = $this->get_table_name('mld_notification_queue');
        if ($this->table_exists($queue_table)) {
            $existing_columns = $this->get_table_columns($queue_table);
            $queue_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('status', $existing_columns)) {
                $queue_indexes['idx_status'] = array('status');
            }
            if (in_array('priority', $existing_columns)) {
                $queue_indexes['idx_priority'] = array('priority');
            }
            if (in_array('user_id', $existing_columns)) {
                $queue_indexes['idx_user_id'] = array('user_id');
            }
            if (in_array('search_id', $existing_columns)) {
                $queue_indexes['idx_search_id'] = array('search_id');
            }
            if (in_array('created_at', $existing_columns)) {
                $queue_indexes['idx_created_at'] = array('created_at');
            }
            if (in_array('status', $existing_columns) && in_array('priority', $existing_columns)) {
                $queue_indexes['idx_status_priority'] = array('status', 'priority');
            }

            foreach ($queue_indexes as $index_name => $columns) {
                $results['queue_' . $index_name] = $this->create_index($queue_table, $index_name, $columns);
            }
        }

        // Notification history table (if it exists)
        $history_table = $this->get_table_name('mld_notification_history');
        if ($this->table_exists($history_table)) {
            $existing_columns = $this->get_table_columns($history_table);
            $history_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('user_id', $existing_columns)) {
                $history_indexes['idx_user_id'] = array('user_id');
            }
            if (in_array('notification_type', $existing_columns)) {
                $history_indexes['idx_notification_type'] = array('notification_type');
            }
            if (in_array('status', $existing_columns)) {
                $history_indexes['idx_status'] = array('status');
            }
            if (in_array('sent_at', $existing_columns)) {
                $history_indexes['idx_sent_at'] = array('sent_at');
            }
            if (in_array('created_at', $existing_columns)) {
                $history_indexes['idx_created_at'] = array('created_at');
            }

            foreach ($history_indexes as $index_name => $columns) {
                $results['history_' . $index_name] = $this->create_index($history_table, $index_name, $columns);
            }
        }

        return $results;
    }

    /**
     * Create agent management indexes
     *
     * @return array Results
     */
    private function create_agent_management_indexes() {
        $results = array();

        // Agent profiles table
        $agents_table = $this->get_table_name('mld_agent_profiles');
        if ($this->table_exists($agents_table)) {
            $existing_columns = $this->get_table_columns($agents_table);
            $agent_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('agent_id', $existing_columns)) {
                $agent_indexes['idx_agent_id'] = array('agent_id');
            }
            if (in_array('user_id', $existing_columns)) {
                $agent_indexes['idx_user_id'] = array('user_id');
            }
            if (in_array('email', $existing_columns)) {
                $agent_indexes['idx_email'] = array('email');
            }
            if (in_array('is_active', $existing_columns)) {
                $agent_indexes['idx_is_active'] = array('is_active');
            }
            if (in_array('created_at', $existing_columns)) {
                $agent_indexes['idx_created_at'] = array('created_at');
            }

            foreach ($agent_indexes as $index_name => $columns) {
                $results['agents_' . $index_name] = $this->create_index($agents_table, $index_name, $columns);
            }
        }

        // Agent-client relationships table
        $relationships_table = $this->get_table_name('mld_agent_client_relationships');
        if ($this->table_exists($relationships_table)) {
            $existing_columns = $this->get_table_columns($relationships_table);
            $relationship_indexes = array();

            // Only create indexes for columns that exist
            if (in_array('agent_id', $existing_columns)) {
                $relationship_indexes['idx_agent_id'] = array('agent_id');
            }
            if (in_array('client_id', $existing_columns)) {
                $relationship_indexes['idx_client_id'] = array('client_id');
            }
            if (in_array('relationship_type', $existing_columns)) {
                $relationship_indexes['idx_relationship_type'] = array('relationship_type');
            }
            if (in_array('is_active', $existing_columns)) {
                $relationship_indexes['idx_is_active'] = array('is_active');
                if (in_array('agent_id', $existing_columns)) {
                    $relationship_indexes['idx_agent_active'] = array('agent_id', 'is_active');
                }
            }

            foreach ($relationship_indexes as $index_name => $columns) {
                $results['relationships_' . $index_name] = $this->create_index($relationships_table, $index_name, $columns);
            }
        }

        return $results;
    }

    /**
     * Apply table structure updates
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function apply_table_updates($from_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Migrator: Applying table structure updates');
        }

        $results = array();

        try {
            // Ensure all required tables exist
            $results['table_creation'] = $this->ensure_tables_exist();

            // Add missing columns
            $results['column_additions'] = $this->add_missing_columns($from_version);

            // Update column types
            $results['column_updates'] = $this->update_column_types($from_version);

            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Table structure update failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Ensure all required tables exist
     *
     * @return array Results
     */
    private function ensure_tables_exist() {
        global $wpdb;
        $results = array();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        // List of required tables with their schemas
        $required_tables = array(
            'mld_form_submissions' => "CREATE TABLE IF NOT EXISTS {table_name} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_type VARCHAR(50) NOT NULL,
                property_mls VARCHAR(50),
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                email VARCHAR(255),
                phone VARCHAR(20),
                message TEXT,
                status ENUM('new', 'contacted', 'qualified', 'closed') DEFAULT 'new',
                source VARCHAR(100),
                agent_id BIGINT UNSIGNED,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) {charset_collate}",

            'mld_city_boundaries' => "CREATE TABLE IF NOT EXISTS {table_name} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                city VARCHAR(100) NOT NULL,
                state VARCHAR(50) NOT NULL,
                boundary_type VARCHAR(50) DEFAULT 'city',
                display_name VARCHAR(150),
                boundary_data LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) {charset_collate}",

            'mld_saved_searches' => "CREATE TABLE IF NOT EXISTS {table_name} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                search_name VARCHAR(255) NOT NULL,
                search_criteria LONGTEXT,
                is_active BOOLEAN DEFAULT TRUE,
                frequency ENUM('instant', 'hourly', 'daily', 'weekly') DEFAULT 'daily',
                last_run DATETIME,
                last_email_sent DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) {charset_collate}"
        );

        foreach ($required_tables as $table_key => $sql_template) {
            $table_name = $this->get_table_name($table_key);
            $sql = str_replace(array('{table_name}', '{charset_collate}'), array($table_name, $charset_collate), $sql_template);

            if (!$this->table_exists($table_name)) {
                dbDelta($sql);
                $results[$table_key] = 'created';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Database Migrator: Created table {$table_name}");
                }
            } else {
                $results[$table_key] = 'exists';
            }
        }

        return $results;
    }

    /**
     * Add missing columns
     *
     * @param string $from_version Previous version
     * @return array Results
     */
    private function add_missing_columns($from_version) {
        global $wpdb;
        $results = array();

        // Define columns to add based on version
        $column_additions = array();

        // Version-specific column additions
        if (version_compare($from_version, '4.8.0', '<')) {
            // Check if source column exists before trying to use it in AFTER clause
            $form_table = $this->get_table_name('mld_form_submissions');
            if ($this->table_exists($form_table)) {
                $existing_cols = $this->get_table_columns($form_table);

                $column_additions['mld_form_submissions'] = array();

                // Add columns with appropriate AFTER clauses based on what exists
                if (in_array('source', $existing_cols)) {
                    $column_additions['mld_form_submissions']['utm_source'] = "ADD COLUMN utm_source VARCHAR(100) AFTER source";
                } else {
                    $column_additions['mld_form_submissions']['utm_source'] = "ADD COLUMN utm_source VARCHAR(100)";
                }

                // Subsequent columns reference previously added ones
                $column_additions['mld_form_submissions']['utm_medium'] = "ADD COLUMN utm_medium VARCHAR(100)";
                $column_additions['mld_form_submissions']['utm_campaign'] = "ADD COLUMN utm_campaign VARCHAR(100)";
                $column_additions['mld_form_submissions']['user_agent'] = "ADD COLUMN user_agent TEXT";
                $column_additions['mld_form_submissions']['ip_address'] = "ADD COLUMN ip_address VARCHAR(45)";
            }
        }

        foreach ($column_additions as $table_key => $columns) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                $existing_columns = $this->get_table_columns($table_name);

                foreach ($columns as $column_name => $sql_fragment) {
                    if (!in_array($column_name, $existing_columns)) {
                        $sql = "ALTER TABLE {$table_name} {$sql_fragment}";
                        $result = $wpdb->query($sql);

                        if ($result !== false) {
                            $results["{$table_key}.{$column_name}"] = 'added';
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("MLD Database Migrator: Added column {$column_name} to {$table_name}");
                            }
                        } else {
                            $results["{$table_key}.{$column_name}"] = 'failed';
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("MLD Database Migrator: Failed to add column {$column_name} to {$table_name}: " . $wpdb->last_error);
                            }
                        }
                    } else {
                        $results["{$table_key}.{$column_name}"] = 'exists';
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Update column types
     *
     * @param string $from_version Previous version
     * @return array Results
     */
    private function update_column_types($from_version) {
        global $wpdb;
        $results = array();

        // Define column type updates based on version
        $column_updates = array();

        // Version-specific column updates
        if (version_compare($from_version, '4.8.0', '<')) {
            $column_updates['mld_form_submissions'] = array(
                'message' => "MODIFY COLUMN message LONGTEXT"
            );
        }

        foreach ($column_updates as $table_key => $columns) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                foreach ($columns as $column_name => $sql_fragment) {
                    $sql = "ALTER TABLE {$table_name} {$sql_fragment}";
                    $result = $wpdb->query($sql);

                    if ($result !== false) {
                        $results["{$table_key}.{$column_name}"] = 'updated';
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD Database Migrator: Updated column {$column_name} in {$table_name}");
                        }
                    } else {
                        $results["{$table_key}.{$column_name}"] = 'failed';
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD Database Migrator: Failed to update column {$column_name} in {$table_name}: " . $wpdb->last_error);
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Apply data migrations
     *
     * @param string $from_version Previous version
     * @return array Results
     */
    private function apply_data_migrations($from_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Migrator: Applying data migrations');
        }

        $results = array();

        try {
            // Version-specific data migrations
            if (version_compare($from_version, '4.8.0', '<')) {
                $results['form_status_migration'] = $this->migrate_form_status_data();
                $results['search_criteria_migration'] = $this->migrate_search_criteria_data();
            }

            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Data migration failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Migrate form status data
     *
     * @return bool Success status
     */
    private function migrate_form_status_data() {
        global $wpdb;

        $table_name = $this->get_table_name('mld_form_submissions');

        if (!$this->table_exists($table_name)) {
            return false;
        }

        // Update any NULL status values to 'new'
        $result = $wpdb->query("UPDATE {$table_name} SET status = 'new' WHERE status IS NULL");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Database Migrator: Migrated form status data, updated {$result} rows");
        }
        return true;
    }

    /**
     * Migrate search criteria data
     *
     * @return bool Success status
     */
    private function migrate_search_criteria_data() {
        global $wpdb;

        $table_name = $this->get_table_name('mld_saved_searches');

        if (!$this->table_exists($table_name)) {
            return false;
        }

        // Check if search_criteria column exists
        $existing_columns = $this->get_table_columns($table_name);
        if (!in_array('search_criteria', $existing_columns)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Database Migrator: search_criteria column does not exist in {$table_name}, skipping migration");
            }
            return true;
        }

        // Ensure search_criteria is valid JSON
        $searches = $wpdb->get_results("SELECT id, search_criteria FROM {$table_name} WHERE search_criteria IS NOT NULL");

        $updated = 0;
        foreach ($searches as $search) {
            $criteria = $search->search_criteria;

            // Check if it's valid JSON
            $decoded = json_decode($criteria, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Try to fix common JSON issues
                $fixed_criteria = json_encode(array('legacy_criteria' => $criteria));
                $wpdb->update(
                    $table_name,
                    array('search_criteria' => $fixed_criteria),
                    array('id' => $search->id),
                    array('%s'),
                    array('%d')
                );
                $updated++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Database Migrator: Migrated search criteria data, updated {$updated} rows");
        }
        return true;
    }

    /**
     * Apply index optimizations
     *
     * @return array Results
     */
    private function apply_index_optimizations() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Migrator: Applying index optimizations');
        }

        $results = array();

        try {
            // Analyze table performance
            $results['analysis'] = $this->analyze_table_performance();

            // Optimize existing indexes
            $results['optimization'] = $this->optimize_existing_indexes();

            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Index optimization failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Analyze table performance
     *
     * @return array Analysis results
     */
    private function analyze_table_performance() {
        global $wpdb;
        $results = array();

        $tables = array(
            'mld_form_submissions',
            'mld_saved_searches',
            'mld_saved_search_results',
            'mld_city_boundaries'
        );

        foreach ($tables as $table_key) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                // Get table statistics
                $stats = $wpdb->get_row("
                    SELECT
                        table_rows as row_count,
                        data_length as data_size,
                        index_length as index_size,
                        (data_length + index_length) as total_size
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    AND table_name = '{$table_name}'
                ");

                if ($stats) {
                    $results[$table_key] = array(
                        'rows' => $stats->row_count,
                        'data_size' => $stats->data_size,
                        'index_size' => $stats->index_size,
                        'total_size' => $stats->total_size
                    );
                }
            }
        }

        return $results;
    }

    /**
     * Optimize existing indexes
     *
     * @return array Results
     */
    private function optimize_existing_indexes() {
        global $wpdb;
        $results = array();

        $tables = array(
            'mld_form_submissions',
            'mld_saved_searches',
            'mld_saved_search_results'
        );

        foreach ($tables as $table_key) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                // Optimize table
                $result = $wpdb->query("OPTIMIZE TABLE {$table_name}");
                $results[$table_key] = $result ? 'optimized' : 'failed';
            }
        }

        return $results;
    }

    /**
     * Apply foreign key constraints
     *
     * @return array Results
     */
    private function apply_foreign_key_constraints() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Database Migrator: Applying foreign key constraints');
        }

        $results = array();

        try {
            // For now, skip foreign keys as they can cause issues with data import
            // Can be added later when data integrity is established
            $results['skipped'] = 'Foreign key constraints skipped for data flexibility';

            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Database Migrator: Foreign key constraint application failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Helper method to create an index
     *
     * @param string $table_name Table name
     * @param string $index_name Index name
     * @param array $columns Columns for the index
     * @return string Result status
     */
    private function create_index($table_name, $index_name, $columns) {
        global $wpdb;

        // Check if index already exists
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM {$table_name}");
        if (in_array($index_name, $existing_indexes)) {
            return 'exists';
        }

        // Create the index
        $columns_sql = implode(', ', array_map(function($col) {
            // Limit text columns to avoid key length issues
            if (in_array($col, array('email', 'search_name', 'city', 'state', 'form_type'))) {
                return "`{$col}`(50)";
            }
            return "`{$col}`";
        }, $columns));

        $sql = "CREATE INDEX `{$index_name}` ON `{$table_name}` ({$columns_sql})";

        $wpdb->suppress_errors(true);
        $result = $wpdb->query($sql);
        $wpdb->suppress_errors(false);

        if ($result === false) {
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate key name') !== false) {
                return 'exists';
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Database Migrator: Failed to create index {$index_name} on {$table_name}: {$error}");
                }
                return 'failed';
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Database Migrator: Created index {$index_name} on {$table_name}");
        }
        return 'created';
    }

    /**
     * Get full table name with prefix
     *
     * @param string $table_key Table key
     * @return string Full table name
     */
    private function get_table_name($table_key) {
        global $wpdb;
        return $wpdb->prefix . $table_key;
    }

    /**
     * Check if table exists
     *
     * @param string $table_name Table name
     * @return bool True if exists
     */
    private function table_exists($table_name) {
        global $wpdb;
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        return !empty($result);
    }

    /**
     * Get table columns
     *
     * @param string $table_name Table name
     * @return array Column names
     */
    private function get_table_columns($table_name) {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        return $columns ?: array();
    }

    /**
     * Store migration history
     *
     * @param string $from_version Previous version
     * @param string $to_version New version
     * @param array $results Migration results
     */
    private function store_migration_history($from_version, $to_version, $results) {
        $history = get_option(self::MIGRATION_HISTORY_OPTION, array());

        $migration_record = array(
            'from_version' => $from_version,
            'to_version' => $to_version,
            'migrated_at' => current_time('mysql'),
            'duration' => $results['duration'] ?? 0,
            'success' => !isset($results['error']),
            'results' => $results
        );

        array_unshift($history, $migration_record);

        // Keep only last 20 migration records
        $history = array_slice($history, 0, 20);

        update_option(self::MIGRATION_HISTORY_OPTION, $history);
    }

    /**
     * Get migration status
     *
     * @return array Migration status
     */
    public function get_migration_status() {
        return get_option(self::MIGRATION_STATUS_OPTION, array('status' => 'none'));
    }

    /**
     * Get migration history
     *
     * @return array Migration history
     */
    public function get_migration_history() {
        return get_option(self::MIGRATION_HISTORY_OPTION, array());
    }

    /**
     * Drop all custom indexes (for rollback)
     *
     * @return array Results
     */
    public function drop_custom_indexes() {
        global $wpdb;
        $results = array();

        $tables = array(
            'mld_form_submissions',
            'mld_city_boundaries',
            'mld_saved_searches',
            'mld_saved_search_results',
            'mld_notification_queue',
            'mld_notification_history'
        );

        foreach ($tables as $table_key) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                // Get all indexes except PRIMARY
                $indexes = $wpdb->get_col("
                    SELECT DISTINCT index_name
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                    AND table_name = '{$table_name}'
                    AND index_name != 'PRIMARY'
                ");

                foreach ($indexes as $index_name) {
                    $sql = "DROP INDEX `{$index_name}` ON `{$table_name}`";
                    $result = $wpdb->query($sql);
                    $results["{$table_key}.{$index_name}"] = $result ? 'dropped' : 'failed';
                }
            }
        }

        return $results;
    }
}