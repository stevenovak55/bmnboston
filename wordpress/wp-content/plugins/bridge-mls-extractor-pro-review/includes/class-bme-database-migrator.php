<?php
/**
 * Bridge MLS Extractor Pro Database Migrator
 *
 * Handles all database migrations including schema updates, index creation,
 * and table structure modifications for all 18 Bridge MLS tables.
 * Works with the upgrader system to ensure all database changes are applied
 * correctly during plugin updates.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.30.8
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Database_Migrator {

    /**
     * Migration history option key
     */
    const MIGRATION_HISTORY_OPTION = 'bme_database_migration_history';

    /**
     * Migration status option key
     */
    const MIGRATION_STATUS_OPTION = 'bme_database_migration_status';

    /**
     * Database manager instance
     */
    private $db_manager;

    /**
     * Migration results
     */
    private $results = array();

    /**
     * Constructor
     */
    public function __construct() {
        if (!class_exists('BME_Database_Manager')) {
            require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
        }
        $this->db_manager = new BME_Database_Manager();
    }

    /**
     * Run all migrations from one version to another
     *
     * @param string $from_version Starting version
     * @param string $to_version Target version
     * @return array Migration results
     */
    public function run_all_migrations($from_version, $to_version) {
        error_log("BME Database Migrator: Running migrations from {$from_version} to {$to_version}");

        $start_time = microtime(true);

        // Set migration status
        update_option(self::MIGRATION_STATUS_OPTION, array(
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'from_version' => $from_version,
            'to_version' => $to_version
        ));

        try {
            // Phase 1: Ensure all tables exist
            $this->results['table_creation'] = $this->ensure_all_tables_exist();

            // Phase 1.5: Validate database structure and log any missing columns
            $this->results['structure_validation'] = $this->validate_database_structure();

            // Phase 2: Performance indexes for all 18 tables
            $this->results['performance_indexes'] = $this->apply_all_performance_indexes();

            // Phase 3: Table structure updates
            $this->results['table_updates'] = $this->apply_table_structure_updates($from_version);

            // Phase 4: Data migrations
            $this->results['data_migrations'] = $this->apply_data_migrations($from_version);

            // Phase 5: Spatial indexes optimization
            $this->results['spatial_indexes'] = $this->apply_spatial_indexes();

            // Phase 6: Full-text search indexes
            $this->results['fulltext_indexes'] = $this->apply_fulltext_indexes();

            // Phase 7: Foreign key constraints (optional)
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

            error_log("BME Database Migrator: Migrations completed successfully in {$duration} seconds");

            return $this->results;

        } catch (Exception $e) {
            $error_message = 'BME Database migration failed: ' . $e->getMessage();
            error_log($error_message);

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
     * Ensure all 18 Bridge MLS tables exist
     *
     * @return array Creation results
     */
    private function ensure_all_tables_exist() {
        error_log('BME Database Migrator: Ensuring all tables exist');

        $results = array();

        try {
            // Use existing database manager to create tables
            $this->db_manager->create_tables();
            $this->db_manager->verify_installation();

            // Migrate virtual tours table if needed
            $this->db_manager->migrate_virtual_tours_table();

            $results['tables_created'] = 'success';
            $results['tables_verified'] = 'success';
            $results['virtual_tours_migrated'] = 'success';

            error_log('BME Database Migrator: All tables exist and verified');
            return $results;

        } catch (Exception $e) {
            error_log('BME Database Migrator: Table creation failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Apply performance indexes for all 18 Bridge MLS tables
     *
     * @return array Index creation results
     */
    private function apply_all_performance_indexes() {
        global $wpdb;
        $results = array();

        error_log('BME Database Migrator: Applying performance indexes for all tables');

        try {
            // Core listing table indexes (active and archive)
            $results['listings'] = $this->create_listings_indexes();
            $results['listing_details'] = $this->create_listing_details_indexes();
            $results['listing_location'] = $this->create_listing_location_indexes();
            $results['listing_financial'] = $this->create_listing_financial_indexes();
            $results['listing_features'] = $this->create_listing_features_indexes();

            // Support table indexes
            $results['agents'] = $this->create_agent_indexes();
            $results['offices'] = $this->create_office_indexes();
            $results['open_houses'] = $this->create_open_house_indexes();
            $results['media'] = $this->create_media_indexes();
            $results['rooms'] = $this->create_rooms_indexes();
            $results['virtual_tours'] = $this->create_virtual_tour_indexes();
            $results['property_history'] = $this->create_property_history_indexes();
            $results['activity_logs'] = $this->create_activity_log_indexes();

            // Add summary of skipped indexes
            $results['summary'] = $this->summarize_index_results($results);

            error_log('BME Database Migrator: Performance indexes applied with graceful handling of missing columns');
            return $results;

        } catch (Exception $e) {
            error_log('BME Database Migrator: Performance index application failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Create indexes for listings tables (active and archive)
     *
     * @return array Results
     */
    private function create_listings_indexes() {
        $tables = array(
            $this->get_table_name('bme_listings'),
            $this->get_table_name('bme_listings_archive')
        );

        $results = array();

        foreach ($tables as $table) {
            $table_results = array();

            // Primary search filters
            $table_results['idx_standard_status'] = $this->create_index($table, 'idx_standard_status', array('standard_status'));
            $table_results['idx_property_type'] = $this->create_index($table, 'idx_property_type', array('property_type'));
            $table_results['idx_property_sub_type'] = $this->create_index($table, 'idx_property_sub_type', array('property_sub_type'));

            // Date and price filters
            $table_results['idx_list_date'] = $this->create_index($table, 'idx_list_date', array('list_date'));
            $table_results['idx_modification_timestamp'] = $this->create_index($table, 'idx_modification_timestamp', array('modification_timestamp'));
            $table_results['idx_original_entry_timestamp'] = $this->create_index($table, 'idx_original_entry_timestamp', array('original_entry_timestamp'));

            // Composite indexes for common filter combinations
            $table_results['idx_status_type'] = $this->create_index($table, 'idx_status_type', array('standard_status', 'property_type'));
            $table_results['idx_status_subtype'] = $this->create_index($table, 'idx_status_subtype', array('standard_status', 'property_sub_type'));
            $table_results['idx_status_date'] = $this->create_index($table, 'idx_status_date', array('standard_status', 'list_date'));

            // Performance critical composite indexes
            $table_results['idx_search_combo'] = $this->create_index($table, 'idx_search_combo', array('standard_status', 'property_type', 'list_date'));
            $table_results['idx_listing_agent_combo'] = $this->create_index($table, 'idx_listing_agent_combo', array('standard_status', 'list_agent_mls_id'));

            $results[basename($table)] = $table_results;
        }

        return $results;
    }

    /**
     * Create indexes for listing details tables
     *
     * @return array Results
     */
    private function create_listing_details_indexes() {
        $tables = array(
            $this->get_table_name('bme_listing_details'),
            $this->get_table_name('bme_listing_details_archive')
        );

        $results = array();

        foreach ($tables as $table) {
            $table_results = array();

            // Foreign key optimization
            $table_results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

            // Common search filters
            $table_results['idx_bedrooms'] = $this->create_index($table, 'idx_bedrooms', array('bedrooms_total'));
            $table_results['idx_bathrooms'] = $this->create_index($table, 'idx_bathrooms', array('bathrooms_total'));
            $table_results['idx_living_area'] = $this->create_index($table, 'idx_living_area', array('living_area'));
            $table_results['idx_lot_size'] = $this->create_index($table, 'idx_lot_size', array('lot_size_square_feet'));
            $table_results['idx_year_built'] = $this->create_index($table, 'idx_year_built', array('year_built'));

            // Composite indexes for bedroom/bathroom searches
            $table_results['idx_bed_bath'] = $this->create_index($table, 'idx_bed_bath', array('bedrooms_total', 'bathrooms_total'));
            $table_results['idx_bed_area'] = $this->create_index($table, 'idx_bed_area', array('bedrooms_total', 'living_area'));

            $results[basename($table)] = $table_results;
        }

        return $results;
    }

    /**
     * Create indexes for listing location tables
     *
     * @return array Results
     */
    private function create_listing_location_indexes() {
        $tables = array(
            $this->get_table_name('bme_listing_location'),
            $this->get_table_name('bme_listing_location_archive')
        );

        $results = array();

        foreach ($tables as $table) {
            $table_results = array();

            // Foreign key optimization
            $table_results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

            // Geographic search indexes
            $table_results['idx_city'] = $this->create_index($table, 'idx_city', array('city'));
            $table_results['idx_state'] = $this->create_index($table, 'idx_state', array('state_or_province'));
            $table_results['idx_postal_code'] = $this->create_index($table, 'idx_postal_code', array('postal_code'));
            $table_results['idx_county'] = $this->create_index($table, 'idx_county', array('county_or_parish'));
            $table_results['idx_subdivision'] = $this->create_index($table, 'idx_subdivision', array('subdivision_name'));
            $table_results['idx_mls_area'] = $this->create_index($table, 'idx_mls_area', array('mls_area_major'));

            // Composite geographic indexes
            $table_results['idx_city_state'] = $this->create_index($table, 'idx_city_state', array('city', 'state_or_province'));
            $table_results['idx_city_postal'] = $this->create_index($table, 'idx_city_postal', array('city', 'postal_code'));

            // School district searches
            $table_results['idx_school_district'] = $this->create_index($table, 'idx_school_district', array('elementary_school_district'));
            $table_results['idx_high_school_district'] = $this->create_index($table, 'idx_high_school_district', array('high_school_district'));

            $results[basename($table)] = $table_results;
        }

        return $results;
    }

    /**
     * Create indexes for listing financial tables
     *
     * @return array Results
     */
    private function create_listing_financial_indexes() {
        $tables = array(
            $this->get_table_name('bme_listing_financial'),
            $this->get_table_name('bme_listing_financial_archive')
        );

        $results = array();

        foreach ($tables as $table) {
            $table_results = array();

            // Foreign key optimization
            $table_results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

            // Price-based searches
            $table_results['idx_list_price'] = $this->create_index($table, 'idx_list_price', array('list_price'));
            $table_results['idx_close_price'] = $this->create_index($table, 'idx_close_price', array('close_price'));
            $table_results['idx_original_list_price'] = $this->create_index($table, 'idx_original_list_price', array('original_list_price'));

            // Financial analysis indexes
            $table_results['idx_price_per_sqft'] = $this->create_index($table, 'idx_price_per_sqft', array('price_per_square_foot'));
            $table_results['idx_days_on_market'] = $this->create_index($table, 'idx_days_on_market', array('days_on_market'));
            $table_results['idx_cumulative_dom'] = $this->create_index($table, 'idx_cumulative_dom', array('cumulative_days_on_market'));

            // Tax and assessment indexes
            $table_results['idx_tax_amount'] = $this->create_index($table, 'idx_tax_amount', array('tax_annual_amount'));
            $table_results['idx_assessment'] = $this->create_index($table, 'idx_assessment', array('assessment_total'));

            // Composite price range indexes
            $table_results['idx_price_range'] = $this->create_index($table, 'idx_price_range', array('list_price', 'close_price'));

            $results[basename($table)] = $table_results;
        }

        return $results;
    }

    /**
     * Create indexes for listing features tables
     *
     * @return array Results
     */
    private function create_listing_features_indexes() {
        $tables = array(
            $this->get_table_name('bme_listing_features'),
            $this->get_table_name('bme_listing_features_archive')
        );

        $results = array();

        foreach ($tables as $table) {
            $table_results = array();

            // Foreign key optimization
            $table_results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

            // Popular feature filters - these columns may be missing in some database structures
            $table_results['idx_garage_spaces'] = $this->create_index($table, 'idx_garage_spaces', array('garage_spaces'));
            $table_results['idx_pool'] = $this->create_index($table, 'idx_pool', array('pool_yn'));
            $table_results['idx_waterfront'] = $this->create_index($table, 'idx_waterfront', array('waterfront_yn'));
            $table_results['idx_view'] = $this->create_index($table, 'idx_view', array('view_yn'));
            $table_results['idx_fireplace'] = $this->create_index($table, 'idx_fireplace', array('fireplace_yn'));

            // Architectural features - these columns may be missing in some database structures
            $table_results['idx_architectural_style'] = $this->create_index($table, 'idx_architectural_style', array('architectural_style'));
            $table_results['idx_construction_materials'] = $this->create_index($table, 'idx_construction_materials', array('construction_materials'));

            // Appliances and systems - these columns may be missing in some database structures
            $table_results['idx_heating'] = $this->create_index($table, 'idx_heating', array('heating'));
            $table_results['idx_cooling'] = $this->create_index($table, 'idx_cooling', array('cooling'));

            // Composite feature indexes for luxury searches - will be skipped if any column is missing
            $table_results['idx_luxury_features'] = $this->create_index($table, 'idx_luxury_features', array('pool_yn', 'waterfront_yn', 'view_yn'));

            $results[basename($table)] = $table_results;
        }

        return $results;
    }

    /**
     * Create indexes for agents table
     *
     * @return array Results
     */
    private function create_agent_indexes() {
        $table = $this->get_table_name('bme_agents');
        $results = array();

        // Primary agent lookups
        $results['idx_agent_mls_id'] = $this->create_index($table, 'idx_agent_mls_id', array('agent_mls_id'));
        $results['idx_agent_name'] = $this->create_index($table, 'idx_agent_name', array('agent_first_name', 'agent_last_name'));
        $results['idx_office_mls_id'] = $this->create_index($table, 'idx_office_mls_id', array('office_mls_id'));

        // Performance tracking
        $results['idx_last_updated'] = $this->create_index($table, 'idx_last_updated', array('last_updated'));

        return $results;
    }

    /**
     * Create indexes for offices table
     *
     * @return array Results
     */
    private function create_office_indexes() {
        $table = $this->get_table_name('bme_offices');
        $results = array();

        // Primary office lookups
        $results['idx_office_mls_id'] = $this->create_index($table, 'idx_office_mls_id', array('office_mls_id'));
        $results['idx_office_name'] = $this->create_index($table, 'idx_office_name', array('office_name'));

        // Performance tracking
        $results['idx_last_updated'] = $this->create_index($table, 'idx_last_updated', array('last_updated'));

        return $results;
    }

    /**
     * Create indexes for open houses table
     *
     * @return array Results
     */
    private function create_open_house_indexes() {
        $table = $this->get_table_name('bme_open_houses');
        $results = array();

        // Foreign key and event lookups
        $results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

        // Event date/time columns - these may be missing in some database structures
        $results['idx_event_date'] = $this->create_index($table, 'idx_event_date', array('event_date'));
        $results['idx_start_time'] = $this->create_index($table, 'idx_start_time', array('start_time'));
        $results['idx_end_time'] = $this->create_index($table, 'idx_end_time', array('end_time'));

        // Composite indexes for event queries - will be skipped if any column is missing
        $results['idx_listing_date'] = $this->create_index($table, 'idx_listing_date', array('listing_id', 'event_date'));
        $results['idx_date_time'] = $this->create_index($table, 'idx_date_time', array('event_date', 'start_time'));

        return $results;
    }

    /**
     * Create indexes for media table
     *
     * @return array Results
     */
    private function create_media_indexes() {
        $table = $this->get_table_name('bme_media');
        $results = array();

        // Foreign key optimization
        $results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

        // Media type and category filters - these columns may be missing in some database structures
        $results['idx_media_category'] = $this->create_index($table, 'idx_media_category', array('media_category'));
        $results['idx_media_type'] = $this->create_index($table, 'idx_media_type', array('media_type'));
        $results['idx_order_index'] = $this->create_index($table, 'idx_order_index', array('order_index'));

        // Composite indexes for media queries
        $results['idx_listing_category'] = $this->create_index($table, 'idx_listing_category', array('listing_id', 'media_category'));
        $results['idx_listing_order'] = $this->create_index($table, 'idx_listing_order', array('listing_id', 'order_index'));

        return $results;
    }

    /**
     * Create indexes for rooms table
     *
     * @return array Results
     */
    private function create_rooms_indexes() {
        $table = $this->get_table_name('bme_rooms');
        $results = array();

        // Foreign key optimization
        $results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

        // Room characteristics - some columns may be missing in some database structures
        $results['idx_room_type'] = $this->create_index($table, 'idx_room_type', array('room_type'));
        $results['idx_room_level'] = $this->create_index($table, 'idx_room_level', array('room_level'));
        $results['idx_room_length'] = $this->create_index($table, 'idx_room_length', array('room_length'));
        $results['idx_room_width'] = $this->create_index($table, 'idx_room_width', array('room_width'));

        // Composite room indexes
        $results['idx_listing_type'] = $this->create_index($table, 'idx_listing_type', array('listing_id', 'room_type'));

        return $results;
    }

    /**
     * Create indexes for virtual tours table
     *
     * @return array Results
     */
    private function create_virtual_tour_indexes() {
        $table = $this->get_table_name('bme_virtual_tours');
        $results = array();

        // Foreign key optimization
        $results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

        // Tour characteristics - these columns may be missing in some database structures
        $results['idx_tour_type'] = $this->create_index($table, 'idx_tour_type', array('tour_type'));
        $results['idx_tour_url'] = $this->create_index($table, 'idx_tour_url', array('tour_url'));
        $results['idx_modification_timestamp'] = $this->create_index($table, 'idx_modification_timestamp', array('modification_timestamp'));

        // Composite indexes
        $results['idx_listing_type'] = $this->create_index($table, 'idx_listing_type', array('listing_id', 'tour_type'));

        return $results;
    }

    /**
     * Create indexes for property history table
     *
     * @return array Results
     */
    private function create_property_history_indexes() {
        $table = $this->get_table_name('bme_property_history');
        $results = array();

        // Foreign key optimization
        $results['idx_listing_id'] = $this->create_index($table, 'idx_listing_id', array('listing_id'));

        // Historical tracking - price column may be missing in some database structures
        $results['idx_event_date'] = $this->create_index($table, 'idx_event_date', array('event_date'));
        $results['idx_event_type'] = $this->create_index($table, 'idx_event_type', array('event_type'));
        $results['idx_price'] = $this->create_index($table, 'idx_price', array('price'));

        // Composite indexes for history queries
        $results['idx_listing_date'] = $this->create_index($table, 'idx_listing_date', array('listing_id', 'event_date'));
        $results['idx_type_date'] = $this->create_index($table, 'idx_type_date', array('event_type', 'event_date'));

        return $results;
    }

    /**
     * Create indexes for activity logs table
     *
     * @return array Results
     */
    private function create_activity_log_indexes() {
        $table = $this->get_table_name('bme_activity_logs');
        $results = array();

        // Time-based queries
        $results['idx_created_at'] = $this->create_index($table, 'idx_created_at', array('created_at'));
        $results['idx_severity'] = $this->create_index($table, 'idx_severity', array('severity'));
        $results['idx_action'] = $this->create_index($table, 'idx_action', array('action'));

        // Entity tracking
        $results['idx_entity_type'] = $this->create_index($table, 'idx_entity_type', array('entity_type'));
        $results['idx_entity_id'] = $this->create_index($table, 'idx_entity_id', array('entity_id'));

        // Composite indexes for log analysis
        $results['idx_severity_date'] = $this->create_index($table, 'idx_severity_date', array('severity', 'created_at'));
        $results['idx_action_date'] = $this->create_index($table, 'idx_action_date', array('action', 'created_at'));

        return $results;
    }

    /**
     * Apply table structure updates
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function apply_table_structure_updates($from_version) {
        error_log('BME Database Migrator: Applying table structure updates');

        $results = array();

        try {
            // Add missing columns based on version
            $results['column_additions'] = $this->add_missing_columns($from_version);

            // Update column types
            $results['column_updates'] = $this->update_column_types($from_version);

            // Fix table constraints
            $results['constraint_fixes'] = $this->fix_table_constraints($from_version);

            return $results;

        } catch (Exception $e) {
            error_log('BME Database Migrator: Table structure update failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
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
        if (version_compare($from_version, '3.30.0', '<')) {
            $column_additions['bme_listings'] = array(
                'last_imported_at' => "ADD COLUMN last_imported_at DATETIME AFTER modification_timestamp",
                'import_source' => "ADD COLUMN import_source VARCHAR(50) DEFAULT 'bridge_api' AFTER last_imported_at"
            );

            $column_additions['bme_activity_logs'] = array(
                'user_id' => "ADD COLUMN user_id BIGINT UNSIGNED AFTER id",
                'session_id' => "ADD COLUMN session_id VARCHAR(100) AFTER user_id"
            );
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
                            error_log("BME Database Migrator: Added column {$column_name} to {$table_name}");
                        } else {
                            $results["{$table_key}.{$column_name}"] = 'failed';
                            error_log("BME Database Migrator: Failed to add column {$column_name} to {$table_name}: " . $wpdb->last_error);
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
        if (version_compare($from_version, '3.30.0', '<')) {
            $column_updates['bme_listings'] = array(
                'public_remarks' => "MODIFY COLUMN public_remarks LONGTEXT"
            );

            // Only update marketing_remarks if it exists
            if ($this->column_exists($this->get_table_name('bme_listings'), 'marketing_remarks')) {
                $column_updates['bme_listings']['marketing_remarks'] = "MODIFY COLUMN marketing_remarks LONGTEXT";
            }
        }

        foreach ($column_updates as $table_key => $columns) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                foreach ($columns as $column_name => $sql_fragment) {
                    $sql = "ALTER TABLE {$table_name} {$sql_fragment}";
                    $result = $wpdb->query($sql);

                    if ($result !== false) {
                        $results["{$table_key}.{$column_name}"] = 'updated';
                        error_log("BME Database Migrator: Updated column {$column_name} in {$table_name}");
                    } else {
                        $results["{$table_key}.{$column_name}"] = 'failed';
                        error_log("BME Database Migrator: Failed to update column {$column_name} in {$table_name}: " . $wpdb->last_error);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Fix table constraints
     *
     * @param string $from_version Previous version
     * @return array Results
     */
    private function fix_table_constraints($from_version) {
        $results = array();

        // Add constraint fixes based on version changes
        $results['constraints_fixed'] = 'none_needed';

        return $results;
    }

    /**
     * Apply data migrations
     *
     * @param string $from_version Previous version
     * @return array Results
     */
    private function apply_data_migrations($from_version) {
        error_log('BME Database Migrator: Applying data migrations');

        $results = array();

        try {
            // Version-specific data migrations
            if (version_compare($from_version, '3.30.0', '<')) {
                $results['status_normalization'] = $this->normalize_listing_status_data();
                $results['coordinate_validation'] = $this->validate_coordinate_data();
                $results['duplicate_cleanup'] = $this->cleanup_duplicate_listings();
            }

            return $results;

        } catch (Exception $e) {
            error_log('BME Database Migrator: Data migration failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Normalize listing status data
     *
     * @return bool Success status
     */
    private function normalize_listing_status_data() {
        global $wpdb;

        $tables = array(
            $this->get_table_name('bme_listings'),
            $this->get_table_name('bme_listings_archive')
        );

        $updated_total = 0;

        foreach ($tables as $table) {
            if ($this->table_exists($table)) {
                // Normalize common status variations
                $status_mappings = array(
                    'ACTIVE' => 'Active',
                    'SOLD' => 'Sold',
                    'PENDING' => 'Pending',
                    'EXPIRED' => 'Expired',
                    'WITHDRAWN' => 'Withdrawn'
                );

                foreach ($status_mappings as $old_status => $new_status) {
                    $result = $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET standard_status = %s WHERE standard_status = %s",
                        $new_status,
                        $old_status
                    ));
                    if ($result) {
                        $updated_total += $result;
                    }
                }
            }
        }

        error_log("BME Database Migrator: Normalized status data, updated {$updated_total} rows");
        return true;
    }

    /**
     * Validate coordinate data
     *
     * @return bool Success status
     */
    private function validate_coordinate_data() {
        global $wpdb;

        $tables = array(
            $this->get_table_name('bme_listing_location'),
            $this->get_table_name('bme_listing_location_archive')
        );

        $fixed_total = 0;

        foreach ($tables as $table) {
            if ($this->table_exists($table)) {
                // Fix invalid coordinates (outside reasonable bounds)
                $result = $wpdb->query("
                    UPDATE {$table}
                    SET coordinates = NULL
                    WHERE coordinates IS NOT NULL
                    AND (
                        ST_X(coordinates) < -180 OR ST_X(coordinates) > 180 OR
                        ST_Y(coordinates) < -90 OR ST_Y(coordinates) > 90
                    )
                ");
                if ($result) {
                    $fixed_total += $result;
                }
            }
        }

        error_log("BME Database Migrator: Validated coordinate data, fixed {$fixed_total} rows");
        return true;
    }

    /**
     * Cleanup duplicate listings
     *
     * @return bool Success status
     */
    private function cleanup_duplicate_listings() {
        global $wpdb;

        $table = $this->get_table_name('bme_listings');

        if (!$this->table_exists($table)) {
            return false;
        }

        // Find and remove duplicates based on listing_id
        $result = $wpdb->query("
            DELETE t1 FROM {$table} t1
            INNER JOIN {$table} t2
            WHERE t1.id > t2.id
            AND t1.listing_id = t2.listing_id
        ");

        error_log("BME Database Migrator: Cleaned up duplicate listings, removed {$result} rows");
        return true;
    }

    /**
     * Apply spatial indexes for coordinate columns
     *
     * @return array Results
     */
    private function apply_spatial_indexes() {
        error_log('BME Database Migrator: Applying spatial indexes');

        $results = array();

        $tables = array(
            $this->get_table_name('bme_listing_location'),
            $this->get_table_name('bme_listing_location_archive')
        );

        foreach ($tables as $table) {
            if ($this->table_exists($table) && $this->column_exists($table, 'coordinates')) {
                $results[basename($table)] = $this->create_spatial_index($table, 'idx_coordinates_spatial', array('coordinates'));
            }
        }

        return $results;
    }

    /**
     * Apply full-text search indexes
     *
     * @return array Results
     */
    private function apply_fulltext_indexes() {
        error_log('BME Database Migrator: Applying full-text search indexes');

        $results = array();

        // Full-text indexes for search functionality
        $fulltext_configs = array(
            'bme_listings' => array(
                'idx_remarks_fulltext' => array('public_remarks', 'marketing_remarks')
            ),
            'bme_listings_archive' => array(
                'idx_remarks_fulltext' => array('public_remarks', 'marketing_remarks')
            ),
            'bme_agents' => array(
                'idx_agent_name_fulltext' => array('agent_first_name', 'agent_last_name')
            ),
            'bme_offices' => array(
                'idx_office_name_fulltext' => array('office_name')
            )
        );

        foreach ($fulltext_configs as $table_key => $indexes) {
            $table = $this->get_table_name($table_key);

            if ($this->table_exists($table)) {
                foreach ($indexes as $index_name => $columns) {
                    // Check if the specific columns exist for marketing_remarks
                    if ($index_name === 'idx_remarks_fulltext' && in_array('marketing_remarks', $columns)) {
                        if (!$this->column_exists($table, 'marketing_remarks')) {
                            // Skip marketing_remarks if it doesn't exist, but still try with public_remarks only
                            $filtered_columns = array_filter($columns, function($col) use ($table) {
                                return $this->column_exists($table, $col);
                            });
                            if (!empty($filtered_columns)) {
                                $results["{$table_key}.{$index_name}"] = $this->create_fulltext_index($table, $index_name, $filtered_columns);
                            } else {
                                $results["{$table_key}.{$index_name}"] = 'columns_missing';
                            }
                            continue;
                        }
                    }
                    $results["{$table_key}.{$index_name}"] = $this->create_fulltext_index($table, $index_name, $columns);
                }
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
        error_log('BME Database Migrator: Applying foreign key constraints');

        $results = array();

        try {
            // For now, skip foreign keys to maintain data flexibility
            // Can be added later when data integrity is fully established
            $results['skipped'] = 'Foreign key constraints skipped for data flexibility';

            return $results;

        } catch (Exception $e) {
            error_log('BME Database Migrator: Foreign key constraint application failed - ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Helper methods
     */

    /**
     * Create a standard index with column existence checking
     *
     * @param string $table_name Table name
     * @param string $index_name Index name
     * @param array $columns Columns for the index
     * @return string Result status
     */
    private function create_index($table_name, $index_name, $columns) {
        global $wpdb;

        // Check if table exists first
        if (!$this->table_exists($table_name)) {
            error_log("BME Database Migrator: Skipping index {$index_name} - table {$table_name} does not exist");
            return 'table_not_exists';
        }

        // Check if all columns exist before creating index
        $missing_columns = array();
        foreach ($columns as $column) {
            if (!$this->column_exists($table_name, $column)) {
                $missing_columns[] = $column;
            }
        }

        if (!empty($missing_columns)) {
            error_log("BME Database Migrator: Skipping index {$index_name} on {$table_name} - missing columns: " . implode(', ', $missing_columns));
            return 'columns_missing';
        }

        // Check if index already exists
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM {$table_name}");
        if (in_array($index_name, $existing_indexes)) {
            return 'exists';
        }

        // Create the index
        $columns_sql = implode(', ', array_map(function($col) {
            // Limit text columns to avoid key length issues
            if (in_array($col, array('city', 'state_or_province', 'postal_code', 'county_or_parish', 'subdivision_name', 'mls_area_major', 'office_name', 'agent_first_name', 'agent_last_name'))) {
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
                error_log("BME Database Migrator: Failed to create index {$index_name} on {$table_name}: {$error}");
                return 'failed';
            }
        }

        error_log("BME Database Migrator: Created index {$index_name} on {$table_name}");
        return 'created';
    }

    /**
     * Create a spatial index with column existence checking
     *
     * @param string $table_name Table name
     * @param string $index_name Index name
     * @param array $columns Columns for the index
     * @return string Result status
     */
    private function create_spatial_index($table_name, $index_name, $columns) {
        global $wpdb;

        // Check if table exists first
        if (!$this->table_exists($table_name)) {
            error_log("BME Database Migrator: Skipping spatial index {$index_name} - table {$table_name} does not exist");
            return 'table_not_exists';
        }

        // Check if all columns exist before creating index
        $missing_columns = array();
        foreach ($columns as $column) {
            if (!$this->column_exists($table_name, $column)) {
                $missing_columns[] = $column;
            }
        }

        if (!empty($missing_columns)) {
            error_log("BME Database Migrator: Skipping spatial index {$index_name} on {$table_name} - missing columns: " . implode(', ', $missing_columns));
            return 'columns_missing';
        }

        // Check if index already exists
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM {$table_name}");
        if (in_array($index_name, $existing_indexes)) {
            return 'exists';
        }

        $columns_sql = implode(', ', array_map(function($col) {
            return "`{$col}`";
        }, $columns));

        $sql = "CREATE SPATIAL INDEX `{$index_name}` ON `{$table_name}` ({$columns_sql})";

        $wpdb->suppress_errors(true);
        $result = $wpdb->query($sql);
        $wpdb->suppress_errors(false);

        if ($result === false) {
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate key name') !== false) {
                return 'exists';
            } else {
                error_log("BME Database Migrator: Failed to create spatial index {$index_name} on {$table_name}: {$error}");
                return 'failed';
            }
        }

        error_log("BME Database Migrator: Created spatial index {$index_name} on {$table_name}");
        return 'created';
    }

    /**
     * Create a full-text index with column existence checking
     *
     * @param string $table_name Table name
     * @param string $index_name Index name
     * @param array $columns Columns for the index
     * @return string Result status
     */
    private function create_fulltext_index($table_name, $index_name, $columns) {
        global $wpdb;

        // Check if table exists first
        if (!$this->table_exists($table_name)) {
            error_log("BME Database Migrator: Skipping fulltext index {$index_name} - table {$table_name} does not exist");
            return 'table_not_exists';
        }

        // Check if all columns exist before creating index
        $missing_columns = array();
        foreach ($columns as $column) {
            if (!$this->column_exists($table_name, $column)) {
                $missing_columns[] = $column;
            }
        }

        if (!empty($missing_columns)) {
            error_log("BME Database Migrator: Skipping fulltext index {$index_name} on {$table_name} - missing columns: " . implode(', ', $missing_columns));
            return 'columns_missing';
        }

        // Check if index already exists
        $existing_indexes = $wpdb->get_col("SHOW INDEX FROM {$table_name}");
        if (in_array($index_name, $existing_indexes)) {
            return 'exists';
        }

        $columns_sql = implode(', ', array_map(function($col) {
            return "`{$col}`";
        }, $columns));

        $sql = "CREATE FULLTEXT INDEX `{$index_name}` ON `{$table_name}` ({$columns_sql})";

        $wpdb->suppress_errors(true);
        $result = $wpdb->query($sql);
        $wpdb->suppress_errors(false);

        if ($result === false) {
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate key name') !== false) {
                return 'exists';
            } else {
                error_log("BME Database Migrator: Failed to create fulltext index {$index_name} on {$table_name}: {$error}");
                return 'failed';
            }
        }

        error_log("BME Database Migrator: Created fulltext index {$index_name} on {$table_name}");
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
     * Check if column exists
     *
     * @param string $table_name Table name
     * @param string $column_name Column name
     * @return bool True if exists
     */
    private function column_exists($table_name, $column_name) {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        return in_array($column_name, $columns);
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
     * Summarize index creation results and log details about skipped indexes
     *
     * @param array $results Index creation results
     * @return array Summary information
     */
    private function summarize_index_results($results) {
        $total_indexes = 0;
        $created_indexes = 0;
        $existing_indexes = 0;
        $skipped_columns = 0;
        $skipped_tables = 0;
        $failed_indexes = 0;
        $skipped_details = array();

        foreach ($results as $table_key => $table_results) {
            if (is_array($table_results)) {
                foreach ($table_results as $table_name => $index_results) {
                    if (is_array($index_results)) {
                        foreach ($index_results as $index_name => $status) {
                            $total_indexes++;
                            switch ($status) {
                                case 'created':
                                    $created_indexes++;
                                    break;
                                case 'exists':
                                    $existing_indexes++;
                                    break;
                                case 'columns_missing':
                                    $skipped_columns++;
                                    $skipped_details[] = "{$table_name}.{$index_name} (missing columns)";
                                    break;
                                case 'table_not_exists':
                                    $skipped_tables++;
                                    $skipped_details[] = "{$table_name}.{$index_name} (table missing)";
                                    break;
                                case 'failed':
                                    $failed_indexes++;
                                    break;
                            }
                        }
                    }
                }
            }
        }

        $summary = array(
            'total_indexes' => $total_indexes,
            'created' => $created_indexes,
            'existing' => $existing_indexes,
            'skipped_missing_columns' => $skipped_columns,
            'skipped_missing_tables' => $skipped_tables,
            'failed' => $failed_indexes,
            'skipped_details' => $skipped_details
        );

        // Log summary
        if ($skipped_columns > 0 || $skipped_tables > 0) {
            error_log("BME Database Migrator: Index Summary - Created: {$created_indexes}, Existing: {$existing_indexes}, Skipped (missing columns): {$skipped_columns}, Skipped (missing tables): {$skipped_tables}, Failed: {$failed_indexes}");

            if (!empty($skipped_details)) {
                error_log("BME Database Migrator: Skipped indexes: " . implode(', ', $skipped_details));
            }
        } else {
            error_log("BME Database Migrator: All indexes processed successfully - Created: {$created_indexes}, Existing: {$existing_indexes}");
        }

        return $summary;
    }

    /**
     * Validate database structure and log missing columns that may affect indexing
     *
     * @return array Validation results
     */
    private function validate_database_structure() {
        error_log('BME Database Migrator: Validating database structure');

        $results = array();
        $missing_columns = array();

        // Define expected columns that are commonly missing
        $expected_columns = array(
            'bme_listing_features' => array(
                'garage_spaces', 'pool_yn', 'fireplace_yn', 'architectural_style',
                'construction_materials', 'heating', 'cooling'
            ),
            'bme_open_houses' => array(
                'event_date', 'start_time', 'end_time'
            ),
            'bme_media' => array(
                'media_type'
            ),
            'bme_rooms' => array(
                'room_length', 'room_width'
            ),
            'bme_virtual_tours' => array(
                'tour_type', 'tour_url', 'modification_timestamp'
            ),
            'bme_property_history' => array(
                'price'
            ),
            'bme_listings' => array(
                'marketing_remarks'
            )
        );

        foreach ($expected_columns as $table_key => $columns) {
            $table_name = $this->get_table_name($table_key);

            if ($this->table_exists($table_name)) {
                $table_missing = array();

                foreach ($columns as $column) {
                    if (!$this->column_exists($table_name, $column)) {
                        $table_missing[] = $column;
                        $missing_columns[] = "{$table_key}.{$column}";
                    }
                }

                if (!empty($table_missing)) {
                    $results[$table_key] = array(
                        'status' => 'missing_columns',
                        'missing' => $table_missing
                    );
                } else {
                    $results[$table_key] = array(
                        'status' => 'complete'
                    );
                }
            } else {
                $results[$table_key] = array(
                    'status' => 'table_missing'
                );
            }
        }

        // Log summary
        if (!empty($missing_columns)) {
            error_log("BME Database Migrator: Structure validation found missing columns: " . implode(', ', $missing_columns));
            error_log("BME Database Migrator: These missing columns will cause related indexes to be skipped gracefully");
        } else {
            error_log("BME Database Migrator: Database structure validation complete - all expected columns found");
        }

        return $results;
    }

    /**
     * Drop all custom indexes (for rollback)
     *
     * @return array Results
     */
    public function drop_custom_indexes() {
        global $wpdb;
        $results = array();

        $table_prefixes = array(
            'bme_listings',
            'bme_listing_details',
            'bme_listing_location',
            'bme_listing_financial',
            'bme_listing_features',
            'bme_agents',
            'bme_offices',
            'bme_open_houses',
            'bme_media',
            'bme_rooms',
            'bme_virtual_tours',
            'bme_property_history',
            'bme_activity_logs'
        );

        foreach ($table_prefixes as $table_key) {
            $tables = array($this->get_table_name($table_key));

            // Add archive table if it exists
            if (in_array($table_key, array('bme_listings', 'bme_listing_details', 'bme_listing_location', 'bme_listing_financial', 'bme_listing_features'))) {
                $tables[] = $this->get_table_name($table_key . '_archive');
            }

            foreach ($tables as $table_name) {
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
                        $results["{$table_name}.{$index_name}"] = $result ? 'dropped' : 'failed';
                    }
                }
            }
        }

        return $results;
    }
}