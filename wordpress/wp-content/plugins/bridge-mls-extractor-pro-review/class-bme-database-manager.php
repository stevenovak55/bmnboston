<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced database manager with performance optimization and intelligent indexing
 *
 * Manages all database operations for the Bridge MLS Extractor Pro plugin including
 * table creation, schema updates, query optimization, and performance monitoring.
 * Implements intelligent indexing strategies and query caching for optimal performance.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 1.0.0
 * @version 3.0.0
 */
class BME_Database_Manager {

    /**
     * @var wpdb WordPress database abstraction object
     */
    private $wpdb;

    /**
     * @var string Database charset and collation
     */
    private $charset_collate;

    /**
     * @var array Array of database table names
     */
    private $tables = [];

    /**
     * @var array Query result cache
     */
    private $query_cache = [];

    /**
     * @var array Query performance statistics
     */
    private $query_stats = [
        'total_queries' => 0,
        'slow_queries' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];

    /**
     * @var float Threshold in seconds for slow query detection
     */
    private $slow_query_threshold = 1.0;

    /**
     * Constructor
     *
     * Initializes the database manager with WordPress database instance
     * and sets up table names and charset configuration.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $this->wpdb->get_charset_collate();
        $this->init_table_names();
    }

    /**
     * Initialize table names, including archive tables and new media/rooms tables
     *
     * Sets up the complete table structure with 18 tables including active/archive
     * separation for listing data and supporting tables for agents, offices, media, etc.
     *
     * @access private
     * @return void
     */
    private function init_table_names() {
        $this->tables = [
            // Active tables
            'listings' => $this->wpdb->prefix . 'bme_listings',
            'listing_details' => $this->wpdb->prefix . 'bme_listing_details',
            'listing_location' => $this->wpdb->prefix . 'bme_listing_location',
            'listing_financial' => $this->wpdb->prefix . 'bme_listing_financial',
            'listing_features' => $this->wpdb->prefix . 'bme_listing_features',

            // Archive (Closed/Off-market) tables
            'listings_archive' => $this->wpdb->prefix . 'bme_listings_archive',
            'listing_details_archive' => $this->wpdb->prefix . 'bme_listing_details_archive',
            'listing_location_archive' => $this->wpdb->prefix . 'bme_listing_location_archive',
            'listing_financial_archive' => $this->wpdb->prefix . 'bme_listing_financial_archive',
            'listing_features_archive' => $this->wpdb->prefix . 'bme_listing_features_archive',

            // Shared & New tables
            'agents' => $this->wpdb->prefix . 'bme_agents',
            'offices' => $this->wpdb->prefix . 'bme_offices',
            'open_houses' => $this->wpdb->prefix . 'bme_open_houses',
            'extraction_logs' => $this->wpdb->prefix . 'bme_extraction_logs',
            'media' => $this->wpdb->prefix . 'bme_media',
            'rooms' => $this->wpdb->prefix . 'bme_rooms',
            'virtual_tours' => $this->wpdb->prefix . 'bme_virtual_tours', // New: Virtual Tours table
            'property_history' => $this->wpdb->prefix . 'bme_property_history', // New: Property history tracking
            'saved_searches' => $this->wpdb->prefix . 'bme_saved_searches', // New: User saved searches
            'favorites' => $this->wpdb->prefix . 'bme_favorites', // New: User favorites
            'activity_logs' => $this->wpdb->prefix . 'bme_activity_logs', // New: Activity logs table
            'api_requests' => $this->wpdb->prefix . 'bme_api_requests', // New: API request tracking
            // 'property_subscriptions' => $this->wpdb->prefix . 'bme_property_subscriptions', // Created by email notifications class when needed

            // Performance Monitoring Tables
            'performance_metrics' => $this->wpdb->prefix . 'bme_performance_metrics',
            'system_alerts' => $this->wpdb->prefix . 'bme_system_alerts',
            'query_performance' => $this->wpdb->prefix . 'bme_query_performance',

            // Phase 2 Optimization Tables
            'listing_summary' => $this->wpdb->prefix . 'bme_listing_summary',
            'search_cache' => $this->wpdb->prefix . 'bme_search_cache',
        ];
    }

    /**
     * Create all database tables atomically.
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Create Active Tables
        $this->create_listings_table($this->tables['listings']);
        $this->create_listing_details_table($this->tables['listing_details']);
        $this->create_listing_location_table($this->tables['listing_location']);
        $this->create_listing_financial_table($this->tables['listing_financial']);
        $this->create_listing_features_table($this->tables['listing_features']);

        // Create Archive Tables
        $this->create_listings_table($this->tables['listings_archive']);
        $this->create_listing_details_table($this->tables['listing_details_archive']);
        $this->create_listing_location_table($this->tables['listing_location_archive']);
        $this->create_listing_financial_table($this->tables['listing_financial_archive']);
        $this->create_listing_features_table($this->tables['listing_features_archive']);

        // Create Shared & New Tables
        $this->create_agents_table();
        $this->create_offices_table();
        $this->create_open_houses_table();
        $this->create_extraction_logs_table();
        $this->create_media_table();
        $this->create_rooms_table();
        $this->create_virtual_tours_table(); // New: Create Virtual Tours table
        $this->create_property_history_table(); // New: Create Property History table
        $this->create_saved_searches_table(); // New: Create Saved Searches table
        $this->create_favorites_table(); // New: Create Favorites table
        $this->create_activity_logs_table(); // New: Create Activity Logs table
        $this->create_api_requests_table(); // New: Create API Requests table
        
        // Performance Monitoring Tables
        $this->create_performance_metrics_table();
        $this->create_system_alerts_table();
        $this->create_query_performance_table();

        // Phase 2 Optimization Tables
        $this->create_summary_table();
        $this->create_search_cache_table();
        $this->create_stored_procedures();
    }

    /**
     * Core listings table - dbDelta compliant. Used for both active and archive.
     */
    private function create_listings_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            listing_id INT UNSIGNED NOT NULL,
            modification_timestamp DATETIME,
            creation_timestamp DATETIME,
            status_change_timestamp DATETIME,
            close_date DATETIME,
            purchase_contract_date DATETIME,
            listing_contract_date DATE,
            original_entry_timestamp DATETIME,
            off_market_date DATETIME,
            standard_status VARCHAR(50),
            mls_status VARCHAR(50),
            property_type VARCHAR(50),
            property_sub_type VARCHAR(50),
            business_type VARCHAR(100),
            list_price DECIMAL(20,2),
            original_list_price DECIMAL(20,2),
            close_price DECIMAL(20,2),
            public_remarks LONGTEXT,
            private_remarks LONGTEXT,
            disclosures LONGTEXT,
            showing_instructions TEXT,
            photos_count INT DEFAULT 0,
            virtual_tour_url_unbranded VARCHAR(255),
            virtual_tour_url_branded VARCHAR(255),
            list_agent_mls_id VARCHAR(50),
            buyer_agent_mls_id VARCHAR(50),
            list_office_mls_id VARCHAR(50),
            buyer_office_mls_id VARCHAR(50),
            mlspin_main_so VARCHAR(50),
            mlspin_main_lo VARCHAR(50),
            mlspin_mse VARCHAR(50),
            mlspin_mgf VARCHAR(50),
            mlspin_deqe VARCHAR(50),
            mlspin_sold_vs_rent VARCHAR(20),
            mlspin_team_member VARCHAR(255),
            private_office_remarks LONGTEXT,
            buyer_agency_compensation VARCHAR(50),
            mlspin_buyer_comp_offered BOOLEAN,
            mlspin_showings_deferral_date DATE,
            mlspin_alert_comments LONGTEXT,
            mlspin_disclosure LONGTEXT,
            mlspin_comp_based_on VARCHAR(100),
            expiration_date DATE,
            contingency VARCHAR(255) NULL DEFAULT NULL,
            mlspin_ant_sold_date DATE NULL DEFAULT NULL,
            mlspin_market_time_property INT NULL DEFAULT NULL,
            buyer_agency_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            sub_agency_compensation VARCHAR(50) NULL DEFAULT NULL,
            sub_agency_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            transaction_broker_compensation VARCHAR(50) NULL DEFAULT NULL,
            transaction_broker_compensation_type VARCHAR(50) NULL DEFAULT NULL,
            listing_agreement VARCHAR(100) NULL DEFAULT NULL,
            listing_service VARCHAR(100) NULL DEFAULT NULL,
            listing_terms TEXT NULL DEFAULT NULL,
            exclusions TEXT NULL DEFAULT NULL,
            possession VARCHAR(255) NULL DEFAULT NULL,
            special_licenses TEXT NULL DEFAULT NULL,
            documents_available TEXT NULL DEFAULT NULL,
            documents_count INT NULL DEFAULT NULL,
            bridge_modification_timestamp DATETIME NULL DEFAULT NULL,
            photos_change_timestamp DATETIME NULL DEFAULT NULL,
            mlspin_listing_alert TEXT NULL DEFAULT NULL,
            mlspin_apod_available BOOLEAN NULL DEFAULT NULL,
            mlspin_sub_agency_offered BOOLEAN NULL DEFAULT NULL,
            mlspin_short_sale_lender_app_reqd BOOLEAN NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_listing_key (listing_key),
            KEY idx_extraction (extraction_id),
            KEY idx_listing_id (listing_id),
            KEY idx_status (standard_status),
            KEY idx_type (property_type),
            KEY idx_price (list_price),
            KEY idx_close_date (close_date),
            KEY idx_timestamps (modification_timestamp, creation_timestamp),
            KEY idx_agents (list_agent_mls_id, buyer_agent_mls_id),
            KEY idx_offices (list_office_mls_id, buyer_office_mls_id),
            FULLTEXT KEY ft_remarks (public_remarks, private_remarks, disclosures)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Extended listing details - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_details_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id INT UNSIGNED NOT NULL,
            bedrooms_total INT,
            bathrooms_total_integer INT,
            bathrooms_full INT,
            bathrooms_half INT,
            living_area DECIMAL(14,2),
            above_grade_finished_area DECIMAL(14,2),
            below_grade_finished_area DECIMAL(14,2),
            living_area_units VARCHAR(20),
            building_area_total DECIMAL(14,2),
            lot_size_acres DECIMAL(20,4),
            lot_size_square_feet DECIMAL(20,2),
            lot_size_area DECIMAL(20,4),
            year_built INT,
            year_built_effective INT,
            year_built_details VARCHAR(100),
            structure_type VARCHAR(100),
            architectural_style VARCHAR(100),
            stories_total INT,
            levels LONGTEXT,
            property_attached_yn BOOLEAN,
            attached_garage_yn BOOLEAN,
            basement LONGTEXT,
            mlspin_market_time_property INT,
            property_condition VARCHAR(100),
            mlspin_complex_complete BOOLEAN,
            mlspin_unit_building VARCHAR(50),
            mlspin_color VARCHAR(50),
            home_warranty_yn BOOLEAN,
            construction_materials LONGTEXT,
            foundation_details LONGTEXT,
            foundation_area DECIMAL(14,2),
            roof LONGTEXT,
            heating LONGTEXT,
            cooling LONGTEXT,
            utilities LONGTEXT,
            sewer LONGTEXT,
            water_source LONGTEXT,
            electric LONGTEXT,
            electric_on_property_yn BOOLEAN,
            mlspin_cooling_units INT,
            mlspin_cooling_zones INT,
            mlspin_heat_zones INT,
            mlspin_heat_units INT,
            mlspin_hot_water VARCHAR(100),
            mlspin_insulation_feature VARCHAR(100),
            interior_features LONGTEXT,
            flooring LONGTEXT,
            appliances LONGTEXT,
            fireplace_features LONGTEXT,
            fireplaces_total INT,
            fireplace_yn BOOLEAN,
            rooms_total INT,
            window_features LONGTEXT,
            door_features LONGTEXT,
            laundry_features LONGTEXT,
            security_features LONGTEXT,
            garage_spaces INT,
            garage_yn BOOLEAN,
            covered_spaces INT,
            parking_total INT,
            parking_features LONGTEXT,
            carport_yn BOOLEAN,
            cooling_yn BOOLEAN NULL DEFAULT NULL,
            number_of_units_total INT NULL DEFAULT NULL,
            bathrooms_total_decimal DECIMAL(5,2) NULL DEFAULT NULL,
            main_level_bedrooms INT NULL DEFAULT NULL,
            main_level_bathrooms INT NULL DEFAULT NULL,
            heating_yn BOOLEAN NULL DEFAULT NULL,
            accessibility_features TEXT NULL DEFAULT NULL,
            body_type VARCHAR(100) NULL DEFAULT NULL,
            building_area_source VARCHAR(50) NULL DEFAULT NULL,
            building_area_units VARCHAR(20) NULL DEFAULT NULL,
            living_area_source VARCHAR(50) NULL DEFAULT NULL,
            year_built_source VARCHAR(50) NULL DEFAULT NULL,
            common_walls VARCHAR(255) NULL DEFAULT NULL,
            gas TEXT NULL DEFAULT NULL,
            mlspin_bedrms_1 INT NULL DEFAULT NULL,
            mlspin_bedrms_2 INT NULL DEFAULT NULL,
            mlspin_bedrms_3 INT NULL DEFAULT NULL,
            mlspin_bedrms_4 INT NULL DEFAULT NULL,
            mlspin_flrs_1 INT NULL DEFAULT NULL,
            mlspin_flrs_2 INT NULL DEFAULT NULL,
            mlspin_flrs_3 INT NULL DEFAULT NULL,
            mlspin_flrs_4 INT NULL DEFAULT NULL,
            mlspin_f_bths_1 INT NULL DEFAULT NULL,
            mlspin_f_bths_2 INT NULL DEFAULT NULL,
            mlspin_f_bths_3 INT NULL DEFAULT NULL,
            mlspin_f_bths_4 INT NULL DEFAULT NULL,
            mlspin_h_bths_1 INT NULL DEFAULT NULL,
            mlspin_h_bths_2 INT NULL DEFAULT NULL,
            mlspin_h_bths_3 INT NULL DEFAULT NULL,
            mlspin_h_bths_4 INT NULL DEFAULT NULL,
            mlspin_levels_1 INT NULL DEFAULT NULL,
            mlspin_levels_2 INT NULL DEFAULT NULL,
            mlspin_levels_3 INT NULL DEFAULT NULL,
            mlspin_levels_4 INT NULL DEFAULT NULL,
            mlspin_square_feet_incl_base BOOLEAN NULL DEFAULT NULL,
            mlspin_square_feet_other TEXT NULL DEFAULT NULL,
            mlspin_year_round BOOLEAN NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Location and geographic data - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_location_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id INT UNSIGNED NOT NULL,
            unparsed_address VARCHAR(255),
            normalized_address VARCHAR(255),
            street_number VARCHAR(50),
            street_dir_prefix VARCHAR(20),
            street_name VARCHAR(100),
            street_dir_suffix VARCHAR(20),
            street_number_numeric INT,
            unit_number VARCHAR(30),
            entry_level VARCHAR(100),
            entry_location VARCHAR(100),
            city VARCHAR(100),
            state_or_province VARCHAR(50),
            postal_code VARCHAR(20),
            postal_code_plus_4 VARCHAR(10),
            county_or_parish VARCHAR(100),
            country VARCHAR(5) DEFAULT 'US',
            mls_area_major VARCHAR(100),
            mls_area_minor VARCHAR(100),
            subdivision_name VARCHAR(100),
            latitude DOUBLE,
            longitude DOUBLE,
            coordinates POINT NOT NULL,
            building_name TEXT,
            elementary_school VARCHAR(100),
            middle_or_junior_school VARCHAR(100),
            high_school VARCHAR(100),
            school_district VARCHAR(100),
            PRIMARY KEY  (listing_id),
            KEY idx_city_state (city, state_or_province),
            KEY idx_postal_code (postal_code),
            KEY idx_subdivision (subdivision_name),
            KEY idx_normalized_address (normalized_address),
            SPATIAL KEY spatial_coordinates (coordinates)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Financial information - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_financial_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id INT UNSIGNED NOT NULL,
            tax_annual_amount DECIMAL(20,2),
            tax_year INT,
            tax_assessed_value DECIMAL(20,2),
            association_yn BOOLEAN,
            association_fee DECIMAL(20,2),
            association_fee_frequency VARCHAR(20),
            association_amenities LONGTEXT,
            association_fee_includes LONGTEXT,
            mlspin_optional_fee DECIMAL(20,2),
            mlspin_opt_fee_includes LONGTEXT,
            mlspin_reqd_own_association BOOLEAN,
            mlspin_no_units_owner_occ INT,
            mlspin_dpr_flag BOOLEAN,
            mlspin_lender_owned BOOLEAN,
            gross_income DECIMAL(20,2),
            gross_scheduled_income DECIMAL(20,2),
            net_operating_income DECIMAL(20,2),
            operating_expense DECIMAL(20,2),
            total_actual_rent DECIMAL(20,2),
            mlspin_seller_discount_pts DECIMAL(5,2),
            financial_data_source VARCHAR(50),
            current_financing VARCHAR(50),
            development_status VARCHAR(50),
            existing_lease_type VARCHAR(50),
            availability_date DATE,
            mlspin_availablenow BOOLEAN,
            lease_term VARCHAR(100),
            rent_includes TEXT,
            mlspin_sec_deposit DECIMAL(20,2),
            mlspin_deposit_reqd BOOLEAN,
            mlspin_insurance_reqd BOOLEAN,
            mlspin_last_mon_reqd BOOLEAN,
            mlspin_first_mon_reqd BOOLEAN,
            mlspin_references_reqd BOOLEAN,
            tax_map_number VARCHAR(50),
            tax_book_number VARCHAR(50),
            tax_block VARCHAR(50),
            tax_lot VARCHAR(50),
            parcel_number VARCHAR(50),
            zoning VARCHAR(50),
            zoning_description VARCHAR(100),
            mlspin_master_page VARCHAR(50),
            mlspin_master_book VARCHAR(50),
            mlspin_page VARCHAR(50),
            mlspin_sewage_district VARCHAR(50),
            water_sewer_expense DECIMAL(14,2),
            electric_expense DECIMAL(14,2),
            insurance_expense DECIMAL(14,2),
            mlspin_list_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_sold_price_per_sqft DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_owner_occ_source VARCHAR(255) NULL DEFAULT NULL,
            mlspin_lead_paint BOOLEAN NULL DEFAULT NULL,
            mlspin_title5 BOOLEAN NULL DEFAULT NULL,
            mlspin_perc_test BOOLEAN NULL DEFAULT NULL,
            mlspin_perc_test_date DATE NULL DEFAULT NULL,
            mlspin_square_feet_disclosures TEXT NULL DEFAULT NULL,
            concessions_amount DECIMAL(20,2) NULL DEFAULT NULL,
            tenant_pays TEXT NULL DEFAULT NULL,
            mlspin_rent1 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent2 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent3 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_rent4 DECIMAL(15,2) NULL DEFAULT NULL,
            mlspin_lease_1 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_2 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_3 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_lease_4 VARCHAR(100) NULL DEFAULT NULL,
            mlspin_market_time_broker INT NULL DEFAULT NULL,
            mlspin_market_time_broker_prev INT NULL DEFAULT NULL,
            mlspin_market_time_property_prev INT NULL DEFAULT NULL,
            mlspin_prev_market_time INT NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id),
            KEY idx_tax_year (tax_year),
            KEY idx_association (association_yn, association_fee)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Property features and amenities - dbDelta compliant. Used for both active and archive.
     */
    private function create_listing_features_table($table_name) {
        $sql = "CREATE TABLE {$table_name} (
            listing_id INT UNSIGNED NOT NULL,
            spa_yn BOOLEAN,
            spa_features LONGTEXT,
            exterior_features LONGTEXT,
            patio_and_porch_features LONGTEXT,
            lot_features LONGTEXT,
            road_surface_type VARCHAR(50),
            road_frontage_type VARCHAR(50),
            road_responsibility VARCHAR(100),
            frontage_length DECIMAL(14,2),
            frontage_type VARCHAR(50),
            fencing VARCHAR(100),
            other_structures LONGTEXT,
            other_equipment LONGTEXT,
            pasture_area DECIMAL(14,2),
            cultivated_area DECIMAL(14,2),
            waterfront_yn BOOLEAN,
            waterfront_features LONGTEXT,
            view LONGTEXT,
            view_yn BOOLEAN,
            community_features LONGTEXT,
            mlspin_waterview_flag BOOLEAN,
            mlspin_waterview_features LONGTEXT,
            green_indoor_air_quality VARCHAR(100),
            green_energy_generation VARCHAR(100),
            horse_yn BOOLEAN,
            horse_amenities LONGTEXT,
            pool_features TEXT,
            pool_private_yn BOOLEAN,
            senior_community_yn BOOLEAN NULL DEFAULT NULL,
            mlspin_outdoor_space_available BOOLEAN NULL DEFAULT NULL,
            pets_allowed BOOLEAN NULL DEFAULT NULL,
            additional_parcels_yn BOOLEAN NULL DEFAULT NULL,
            green_energy_efficient TEXT NULL DEFAULT NULL,
            green_water_conservation TEXT NULL DEFAULT NULL,
            wooded_area DECIMAL(14,2) NULL DEFAULT NULL,
            number_of_lots INT NULL DEFAULT NULL,
            lot_size_units VARCHAR(20) NULL DEFAULT NULL,
            farm_land_area_units VARCHAR(20) NULL DEFAULT NULL,
            mlspin_gre VARCHAR(50) NULL DEFAULT NULL,
            mlspin_hte VARCHAR(50) NULL DEFAULT NULL,
            mlspin_rfs VARCHAR(50) NULL DEFAULT NULL,
            mlspin_rme VARCHAR(50) NULL DEFAULT NULL,
            PRIMARY KEY  (listing_id),
            KEY idx_waterfront (waterfront_yn),
            KEY idx_pool (pool_private_yn),
            KEY idx_view (view_yn),
            FULLTEXT KEY ft_all_features (exterior_features, lot_features, community_features, view)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Agents table with normalized columns for searching.
     */
    private function create_agents_table() {
        $sql = "CREATE TABLE {$this->tables['agents']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_mls_id VARCHAR(50) NOT NULL,
            agent_full_name VARCHAR(255),
            agent_first_name VARCHAR(100),
            agent_last_name VARCHAR(100),
            agent_email VARCHAR(255),
            agent_phone VARCHAR(50),
            office_mls_id VARCHAR(50),
            modification_timestamp DATETIME,
            agent_data LONGTEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_agent_mls_id (agent_mls_id),
            KEY idx_agent_name (agent_last_name, agent_first_name),
            KEY idx_office_mls_id (office_mls_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Offices table with normalized columns for searching.
     */
    private function create_offices_table() {
        $sql = "CREATE TABLE {$this->tables['offices']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            office_mls_id VARCHAR(50) NOT NULL,
            office_name VARCHAR(255),
            office_phone VARCHAR(50),
            office_address VARCHAR(255),
            office_city VARCHAR(100),
            office_state VARCHAR(50),
            office_postal_code VARCHAR(20),
            modification_timestamp DATETIME,
            office_data LONGTEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_office_mls_id (office_mls_id),
            KEY idx_office_name (office_name),
            KEY idx_office_city_state (office_city, office_state)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Open houses table - dbDelta compliant.
     */
    private function create_open_houses_table() {
        $sql = "CREATE TABLE {$this->tables['open_houses']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            open_house_key VARCHAR(128),
            open_house_data LONGTEXT,
            expires_at DATETIME,
            sync_status VARCHAR(20) DEFAULT 'current',
            sync_timestamp DATETIME,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_unique_open_house (listing_id, open_house_key),
            KEY idx_listing_id (listing_id),
            KEY idx_listing_key (listing_key),
            KEY idx_expires_at (expires_at),
            KEY idx_sync_status (sync_status),
            KEY idx_sync_timestamp (sync_timestamp)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Extraction logs table - dbDelta compliant.
     */
    private function create_extraction_logs_table() {
        $sql = "CREATE TABLE {$this->tables['extraction_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(50) NOT NULL,
            message TEXT,
            listings_processed INT DEFAULT 0,
            duration_seconds DECIMAL(10,3),
            memory_peak_mb DECIMAL(10,2),
            api_requests_count INT DEFAULT 0,
            error_details LONGTEXT,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_extraction_id (extraction_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Media table to store repeating media items.
     */
    private function create_media_table() {
        $sql = "CREATE TABLE {$this->tables['media']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL COMMENT 'MLS number as INT',
            listing_key VARCHAR(128) NOT NULL,
            source_table VARCHAR(20) DEFAULT 'active',
            media_key VARCHAR(128) NOT NULL,
            media_url VARCHAR(255) NOT NULL,
            media_category VARCHAR(50),
            description TEXT,
            modification_timestamp DATETIME,
            order_index INT,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_listing_media (listing_key, media_key),
            KEY idx_listing_id (listing_id),
            KEY idx_listing_key (listing_key),
            KEY idx_source_table (source_table),
            KEY idx_category (media_category)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Rooms table to store repeating room details.
     */
    private function create_rooms_table() {
        $sql = "CREATE TABLE {$this->tables['rooms']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL,
            room_type VARCHAR(100) NOT NULL,
            room_level VARCHAR(50),
            room_dimensions VARCHAR(50),
            room_features TEXT,
            PRIMARY KEY  (id),
            KEY idx_listing_id (listing_id),
            KEY idx_room_type_level (room_type, room_level)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * New: Virtual Tours table for supplementary links.
     */
    private function create_virtual_tours_table() {
        $sql = "CREATE TABLE {$this->tables['virtual_tours']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL,
            virtual_tour_link_1 VARCHAR(255) NULL DEFAULT NULL,
            virtual_tour_link_2 VARCHAR(255) NULL DEFAULT NULL,
            virtual_tour_link_3 VARCHAR(255) NULL DEFAULT NULL,
            last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_listing_id (listing_id),
            KEY idx_listing_id (listing_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * New: Property History tracking table.
     */
    private function create_property_history_table() {
        // Create property history tracking table
        $sql = "CREATE TABLE {$this->tables['property_history']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL,
            unparsed_address TEXT NOT NULL,
            normalized_address VARCHAR(255),
            event_type VARCHAR(50) NOT NULL,
            event_date DATETIME NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            field_name VARCHAR(100) NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            old_price DECIMAL(15,2) NULL,
            new_price DECIMAL(15,2) NULL,
            days_on_market INT NULL,
            price_per_sqft DECIMAL(10,2) NULL,
            agent_name VARCHAR(255) NULL,
            office_name VARCHAR(255) NULL,
            additional_data TEXT NULL,
            extraction_log_id BIGINT(20) UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_listing_id (listing_id),
            KEY idx_event_date (event_date),
            KEY idx_event_type (event_type),
            KEY idx_address (unparsed_address(255)),
            KEY idx_normalized_address (normalized_address)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create saved searches table
     */
    private function create_saved_searches_table() {
        $sql = "CREATE TABLE {$this->tables['saved_searches']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            criteria TEXT NOT NULL,
            enable_alerts TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at TIMESTAMP NULL,
            results_count INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_user_name (user_id, name),
            KEY idx_alerts_enabled (enable_alerts),
            KEY idx_created_at (created_at),
            UNIQUE KEY uk_user_search_name (user_id, name)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create favorites table
     */
    private function create_favorites_table() {
        $sql = "CREATE TABLE {$this->tables['favorites']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id INT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_listing_id (listing_id),
            KEY idx_created_at (created_at),
            UNIQUE KEY uk_user_listing (user_id, listing_id)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create activity logs table for comprehensive activity tracking
     */
    private function create_activity_logs_table() {
        $sql = "CREATE TABLE {$this->tables['activity_logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_type VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id VARCHAR(255) NULL,
            mls_id VARCHAR(50) NULL,
            listing_key VARCHAR(255) NULL,
            extraction_id BIGINT(20) UNSIGNED NULL,
            title VARCHAR(500) NOT NULL,
            description TEXT NULL,
            details LONGTEXT NULL,
            old_values LONGTEXT NULL,
            new_values LONGTEXT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            user_id BIGINT(20) UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            related_ids TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_activity_type (activity_type),
            KEY idx_action (action),
            KEY idx_entity_type (entity_type),
            KEY idx_mls_id (mls_id),
            KEY idx_listing_key (listing_key),
            KEY idx_extraction_id (extraction_id),
            KEY idx_severity (severity),
            KEY idx_created_at (created_at),
            KEY idx_user_id (user_id),
            KEY idx_activity_search (activity_type, action, mls_id, created_at),
            FULLTEXT KEY ft_search (title, description)
        ) {$this->charset_collate};";

        dbDelta($sql);
    }

    /**
     * Create API requests table for real usage tracking
     */
    private function create_api_requests_table() {
        $sql = "CREATE TABLE {$this->tables['api_requests']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            extraction_id BIGINT(20) UNSIGNED NULL,
            endpoint VARCHAR(500) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            request_params LONGTEXT NULL,
            response_code INT NOT NULL,
            response_time DECIMAL(8,3) NULL,
            response_size INT NULL,
            listings_count INT NULL DEFAULT 0,
            error_message TEXT NULL,
            user_agent VARCHAR(500) NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_extraction_id (extraction_id),
            KEY idx_endpoint (endpoint(100)),
            KEY idx_response_code (response_code),
            KEY idx_created_at (created_at),
            KEY idx_daily_stats (created_at, response_code),
            KEY idx_performance (response_time, created_at)
        ) {$this->charset_collate};";

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BME: Creating api_requests table with SQL: " . $sql);
        }
        
        $result = dbDelta($sql);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BME: dbDelta result for api_requests table: " . print_r($result, true));
        }
        
        // Verify table was created
        $table_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $this->tables['api_requests']
        ));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BME: api_requests table exists check: " . ($table_exists ? 'YES' : 'NO'));
        }
    }

    /**
     * Verify database installation
     */
    public function verify_installation() {
        $missing_tables = [];
        foreach ($this->tables as $name => $table_name) {
            if ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                $missing_tables[] = $name;
            }
        }

        if (!empty($missing_tables)) {
            // Attempt to recreate the tables automatically
            $this->create_tables();
            // Re-verify after attempting to create
            $missing_tables_after_fix = [];
            foreach ($this->tables as $name => $table_name) {
                if ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
                    $missing_tables_after_fix[] = $name;
                }
            }
            if (!empty($missing_tables_after_fix)) {
                throw new Exception('Missing database tables: ' . implode(', ', $missing_tables_after_fix));
            }
        }

        return true;
    }

    /**
     * Get table name
     */
    public function get_table($table) {
        if (!isset($this->tables[$table])) {
            throw new Exception("Table {$table} not found");
        }
        return $this->tables[$table];
    }

    /**
     * Alias for get_table() for backward compatibility
     */
    public function get_table_name($table) {
        return $this->get_table($table);
    }

    /**
     * Execute prepared query and return results
     */
    public function get_results($query, $params = []) {
        if (!empty($params)) {
            $prepared_query = $this->wpdb->prepare($query, $params);
        } else {
            $prepared_query = $query;
        }
        return $this->wpdb->get_results($prepared_query, ARRAY_A);
    }

    /**
     * Get all table names
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Clean up expired cache entries
     */
    public function cleanup_cache() {
        $threshold = date('Y-m-d H:i:s', strtotime('-30 days'));

        // Clean up old agents (older than 30 days)
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->tables['agents']} WHERE last_updated < %s",
            $threshold
        ));

        // Clean up old offices (older than 30 days)
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->tables['offices']} WHERE last_updated < %s",
            $threshold
        ));
    }

    /**
     * Get database statistics
     */
    public function get_stats() {
        $stats = [];
        foreach ($this->tables as $name => $table) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $stats[$name] = intval($count);
        }
        return $stats;
    }
    
    /**
     * Execute optimized query with performance monitoring and caching
     */
    public function execute_optimized_query($query, $params = [], $cache_duration = 300) {
        $start_time = microtime(true);
        $query_hash = md5($query . serialize($params));
        
        // Check query cache first
        if (isset($this->query_cache[$query_hash])) {
            $cache_entry = $this->query_cache[$query_hash];
            if (time() - $cache_entry['timestamp'] < $cache_duration) {
                $this->query_stats['cache_hits']++;
                return $cache_entry['result'];
            }
        }
        
        $this->query_stats['cache_misses']++;
        $this->query_stats['total_queries']++;
        
        // Prepare and execute query
        if (!empty($params)) {
            $prepared_query = $this->wpdb->prepare($query, $params);
        } else {
            $prepared_query = $query;
        }
        
        $result = $this->wpdb->get_results($prepared_query, ARRAY_A);
        
        $execution_time = microtime(true) - $start_time;
        
        // Log slow queries
        if ($execution_time > $this->slow_query_threshold) {
            $this->query_stats['slow_queries']++;
            $this->log_slow_query($query, $execution_time, $params);
        }
        
        // Cache the result
        $this->query_cache[$query_hash] = [
            'result' => $result,
            'timestamp' => time(),
            'execution_time' => $execution_time
        ];
        
        // Limit cache size
        if (count($this->query_cache) > 100) {
            $this->cleanup_query_cache();
        }
        
        return $result;
    }
    
    /**
     * Create optimized indexes for better query performance
     */
    public function create_performance_indexes() {
        $indexes = [
            // Listings table indexes
            $this->tables['listings'] => [
                'idx_standard_status' => 'standard_status',
                'idx_property_type' => 'property_type',
                'idx_list_price' => 'list_price',
                'idx_modification_timestamp' => 'modification_timestamp',
                'idx_close_date' => 'close_date',
                'idx_listing_status' => '(standard_status, property_type)',
                'idx_search_combo' => '(standard_status, property_type, list_price)',
                'idx_agent_listing' => 'list_agent_mls_id',
                'idx_office_listing' => 'list_office_mls_id'
            ],
            
            // Location table indexes
            $this->tables['listing_location'] => [
                'idx_city' => 'city',
                'idx_state' => 'state_or_province',
                'idx_zip' => 'postal_code',
                'idx_location_search' => '(city, state_or_province)',
                'idx_coordinates' => '(latitude, longitude)',
                // Removed idx_area - column 'area' doesn't exist
                // Available columns: mls_area_major, mls_area_minor
                'idx_mls_area_major' => 'mls_area_major'
            ],
            
            // Financial table indexes
            $this->tables['listing_financial'] => [
                'idx_price_range' => 'list_price',
                // Removed idx_sqft_price - column 'price_per_square_foot' doesn't exist
                // Available columns: mlspin_list_price_per_sqft, mlspin_price_per_sqft, mlspin_sold_price_per_sqft
                'idx_list_price_sqft' => 'mlspin_list_price_per_sqft',
                'idx_taxes' => 'tax_annual_amount'
            ],
            
            // Features table indexes
            $this->tables['listing_features'] => [
                // Note: bedrooms_total, bathrooms_total, building_area_total are in listing_details table
                // Features table contains amenity and feature data
                'idx_waterfront' => 'waterfront_yn',
                'idx_pool' => 'pool_private_yn'
            ],
            
            // Details table indexes (for bedroom/bathroom/building area searches)
            $this->tables['listing_details'] => [
                'idx_bedrooms' => 'bedrooms_total',
                'idx_bathrooms' => 'bathrooms_total_integer',
                'idx_sqft' => 'building_area_total',
                'idx_combo_search' => '(bedrooms_total, bathrooms_total_integer, building_area_total)',
                'idx_year_built' => 'year_built'
            ],
            
            // Agents table indexes
            $this->tables['agents'] => [
                'idx_agent_mls_id' => 'agent_mls_id',
                'idx_agent_name' => '(agent_first_name, agent_last_name)'
            ],
            
            // Open houses table indexes
            $this->tables['open_houses'] => [
                'idx_listing_key' => 'listing_key',
                // Removed idx_open_house_date and idx_upcoming_events - columns don't exist
                // Table only has open_house_data (LONGTEXT) and expires_at columns
                'idx_expires' => 'expires_at'
            ]
        ];
        
        foreach ($indexes as $table => $table_indexes) {
            $this->create_table_indexes($table, $table_indexes);
        }
        
        // Create archive table indexes as well
        $archive_tables = [
            $this->tables['listings_archive'],
            $this->tables['listing_location_archive'],
            $this->tables['listing_financial_archive'],
            $this->tables['listing_features_archive']
        ];
        
        foreach ($archive_tables as $archive_table) {
            if (strpos($archive_table, 'listings_archive') !== false) {
                $this->create_table_indexes($archive_table, $indexes[$this->tables['listings']]);
            } elseif (strpos($archive_table, 'location_archive') !== false) {
                $this->create_table_indexes($archive_table, $indexes[$this->tables['listing_location']]);
            } elseif (strpos($archive_table, 'financial_archive') !== false) {
                $this->create_table_indexes($archive_table, $indexes[$this->tables['listing_financial']]);
            } elseif (strpos($archive_table, 'features_archive') !== false) {
                $this->create_table_indexes($archive_table, $indexes[$this->tables['listing_features']]);
            }
        }
        
        error_log('BME Database: Performance indexes created successfully');
        return true;
    }
    
    /**
     * Create indexes for a specific table
     */
    private function create_table_indexes($table, $indexes) {
        foreach ($indexes as $index_name => $columns) {
            $full_index_name = $index_name;
            
            // Check if index already exists
            $existing_index = $this->wpdb->get_var($this->wpdb->prepare("
                SELECT COUNT(*) 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE table_schema = %s 
                AND table_name = %s 
                AND index_name = %s
            ", DB_NAME, $table, $full_index_name));
            
            if ($existing_index == 0) {
                $sql = "ALTER TABLE {$table} ADD INDEX {$full_index_name} ({$columns})";
                $result = $this->wpdb->query($sql);
                
                if ($result === false) {
                    error_log("BME Database: Failed to create index {$full_index_name} on {$table}");
                } else {
                    error_log("BME Database: Created index {$full_index_name} on {$table}");
                }
            }
        }
    }
    
    /**
     * Add foreign key constraints to ensure referential integrity
     */
    public function add_foreign_key_constraints() {
        global $wpdb;
        $constraints_added = [];
        $errors = [];
        
        // Define foreign key relationships
        $foreign_keys = [
            // listing_details references listings
            [
                'table' => $this->tables['listing_details'],
                'constraint_name' => 'fk_listing_details_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // listing_location references listings
            [
                'table' => $this->tables['listing_location'],
                'constraint_name' => 'fk_listing_location_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // listing_financial references listings
            [
                'table' => $this->tables['listing_financial'],
                'constraint_name' => 'fk_listing_financial_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // listing_features references listings
            [
                'table' => $this->tables['listing_features'],
                'constraint_name' => 'fk_listing_features_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // media references listings
            [
                'table' => $this->tables['media'],
                'constraint_name' => 'fk_media_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // rooms references listings
            [
                'table' => $this->tables['rooms'],
                'constraint_name' => 'fk_rooms_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            // open_houses references listings
            [
                'table' => $this->tables['open_houses'],
                'constraint_name' => 'fk_open_houses_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings'],
                'ref_column' => 'id',
                'on_delete' => 'CASCADE'
            ]
        ];
        
        // Add archive table constraints
        $archive_foreign_keys = [
            [
                'table' => $this->tables['listing_details_archive'],
                'constraint_name' => 'fk_listing_details_archive_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings_archive'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            [
                'table' => $this->tables['listing_location_archive'],
                'constraint_name' => 'fk_listing_location_archive_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings_archive'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            [
                'table' => $this->tables['listing_financial_archive'],
                'constraint_name' => 'fk_listing_financial_archive_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings_archive'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ],
            [
                'table' => $this->tables['listing_features_archive'],
                'constraint_name' => 'fk_listing_features_archive_listing',
                'column' => 'listing_id',
                'ref_table' => $this->tables['listings_archive'],
                'ref_column' => 'listing_id',
                'on_delete' => 'CASCADE'
            ]
        ];
        
        $foreign_keys = array_merge($foreign_keys, $archive_foreign_keys);
        
        foreach ($foreign_keys as $fk) {
            // Check if tables exist
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$fk['table']}'");
            $ref_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$fk['ref_table']}'");
            
            if (!$table_exists || !$ref_table_exists) {
                continue;
            }
            
            // Check if constraint already exists
            $constraint_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                WHERE CONSTRAINT_SCHEMA = DATABASE() 
                AND TABLE_NAME = %s 
                AND CONSTRAINT_NAME = %s 
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                $fk['table'],
                $fk['constraint_name']
            ));
            
            if (!$constraint_exists) {
                // Add the foreign key constraint
                $sql = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE CASCADE",
                    $fk['table'],
                    $fk['constraint_name'],
                    $fk['column'],
                    $fk['ref_table'],
                    $fk['ref_column'],
                    $fk['on_delete']
                );
                
                $result = $wpdb->query($sql);
                
                if ($result === false) {
                    $errors[] = "Failed to add constraint {$fk['constraint_name']}: " . $wpdb->last_error;
                    error_log("BME: Failed to add foreign key {$fk['constraint_name']}: " . $wpdb->last_error);
                } else {
                    $constraints_added[] = $fk['constraint_name'];
                    error_log("BME: Successfully added foreign key {$fk['constraint_name']}");
                }
            }
        }
        
        return [
            'added' => $constraints_added,
            'errors' => $errors
        ];
    }
    
    /**
     * Optimize database tables for better performance
     */
    public function optimize_database_tables() {
        $optimization_results = [];
        
        foreach ($this->tables as $table_name => $table) {
            $start_time = microtime(true);
            
            // Analyze table for optimization recommendations
            $table_status = $this->wpdb->get_row("SHOW TABLE STATUS LIKE '{$table}'", ARRAY_A);
            
            if ($table_status) {
                $optimization_results[$table_name] = [
                    'size_mb' => round(($table_status['Data_length'] + $table_status['Index_length']) / 1024 / 1024, 2),
                    'rows' => $table_status['Rows'],
                    'avg_row_length' => $table_status['Avg_row_length']
                ];
                
                // Optimize table if it's fragmented
                if ($table_status['Data_free'] > 0) {
                    $this->wpdb->query("OPTIMIZE TABLE {$table}");
                    $optimization_results[$table_name]['optimized'] = true;
                    error_log("BME Database: Optimized table {$table}");
                } else {
                    $optimization_results[$table_name]['optimized'] = false;
                }
            }
            
            $optimization_results[$table_name]['optimization_time'] = microtime(true) - $start_time;
        }
        
        return $optimization_results;
    }
    
    /**
     * Get database performance statistics
     */
    public function get_performance_statistics() {
        $stats = [
            'query_stats' => $this->query_stats,
            'cache_size' => count($this->query_cache),
            'slow_query_threshold' => $this->slow_query_threshold
        ];
        
        // Add MySQL performance variables
        $mysql_stats = $this->wpdb->get_results("SHOW STATUS LIKE 'Slow_queries'", ARRAY_A);
        if ($mysql_stats) {
            $stats['mysql_slow_queries'] = $mysql_stats[0]['Value'];
        }
        
        // Get table sizes
        $table_sizes = [];
        foreach ($this->tables as $table_name => $table) {
            $size_query = $this->wpdb->get_row($this->wpdb->prepare("
                SELECT 
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                    table_rows
                FROM information_schema.TABLES 
                WHERE table_schema = %s 
                AND table_name = %s
            ", DB_NAME, $table), ARRAY_A);
            
            if ($size_query) {
                $table_sizes[$table_name] = $size_query;
            }
        }
        
        $stats['table_sizes'] = $table_sizes;
        $stats['hit_rate'] = $this->query_stats['total_queries'] > 0 ? 
            round(($this->query_stats['cache_hits'] / $this->query_stats['total_queries']) * 100, 2) : 0;
        
        return $stats;
    }
    
    /**
     * Analyze and suggest query optimizations
     */
    public function analyze_query_performance($query, $params = []) {
        if (!empty($params)) {
            $prepared_query = $this->wpdb->prepare($query, $params);
        } else {
            $prepared_query = $query;
        }
        
        // Get query execution plan
        $explain_query = "EXPLAIN " . $prepared_query;
        $explain_result = $this->wpdb->get_results($explain_query, ARRAY_A);
        
        $analysis = [
            'query' => $prepared_query,
            'execution_plan' => $explain_result,
            'recommendations' => []
        ];
        
        // Analyze execution plan for optimization opportunities
        foreach ($explain_result as $row) {
            if ($row['type'] === 'ALL') {
                $analysis['recommendations'][] = "Full table scan detected on {$row['table']}. Consider adding an index.";
            }
            
            if ($row['Extra'] && strpos($row['Extra'], 'Using filesort') !== false) {
                $analysis['recommendations'][] = "Filesort operation detected. Consider optimizing ORDER BY clause.";
            }
            
            if ($row['rows'] > 1000) {
                $analysis['recommendations'][] = "Large number of rows examined ({$row['rows']}). Consider adding WHERE conditions or indexes.";
            }
        }
        
        return $analysis;
    }
    
    /**
     * Log slow queries for analysis
     */
    private function log_slow_query($query, $execution_time, $params) {
        $log_entry = [
            'query' => substr($query, 0, 1000), // Limit query length
            'execution_time' => round($execution_time, 4),
            'params' => json_encode($params),
            'timestamp' => date('Y-m-d H:i:s'),
            'backtrace' => wp_debug_backtrace_summary()
        ];
        
        // Store slow queries for analysis
        $slow_queries = get_option('bme_slow_queries', []);
        $slow_queries[] = $log_entry;
        
        // Keep only last 50 slow queries
        if (count($slow_queries) > 50) {
            $slow_queries = array_slice($slow_queries, -50);
        }
        
        update_option('bme_slow_queries', $slow_queries);
        
        error_log("BME Database: Slow query detected ({$execution_time}s): " . substr($query, 0, 200));
    }
    
    /**
     * Migrate virtual tours table from mls_id to listing_id
     * This migration is needed after the database restructuring
     */
    public function migrate_virtual_tours_table() {
        global $wpdb;
        
        // Check if the old mls_id column exists
        $column_exists = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$this->tables['virtual_tours']}' 
            AND COLUMN_NAME = 'mls_id'
        ");
        
        if ($column_exists) {
            // First, backup existing data
            $existing_data = $wpdb->get_results("SELECT * FROM {$this->tables['virtual_tours']}", ARRAY_A);
            
            // Drop the table and recreate with correct structure
            $wpdb->query("DROP TABLE IF EXISTS {$this->tables['virtual_tours']}");
            $this->create_virtual_tours_table();
            
            // Re-insert data with converted listing_id
            foreach ($existing_data as $row) {
                $listing_id = intval($row['mls_id']);
                if ($listing_id > 0) {
                    $wpdb->insert($this->tables['virtual_tours'], [
                        'listing_id' => $listing_id,
                        'virtual_tour_link_1' => $row['virtual_tour_link_1'],
                        'virtual_tour_link_2' => $row['virtual_tour_link_2'],
                        'virtual_tour_link_3' => $row['virtual_tour_link_3']
                    ]);
                }
            }
            
            error_log("BME: Migrated virtual tours table from mls_id to listing_id. Converted " . count($existing_data) . " records.");
        }
    }
    
    /**
     * Clean up old query cache entries
     */
    private function cleanup_query_cache() {
        $current_time = time();
        
        foreach ($this->query_cache as $hash => $entry) {
            if ($current_time - $entry['timestamp'] > 600) { // 10 minutes
                unset($this->query_cache[$hash]);
            }
        }
        
        // If still too large, remove oldest entries
        if (count($this->query_cache) > 50) {
            uasort($this->query_cache, function($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });
            
            $this->query_cache = array_slice($this->query_cache, -50, null, true);
        }
    }
    
    /**
     * Get slow queries for analysis
     */
    public function get_slow_queries() {
        return get_option('bme_slow_queries', []);
    }
    
    /**
     * Clear slow query log
     */
    public function clear_slow_query_log() {
        delete_option('bme_slow_queries');
        $this->query_stats['slow_queries'] = 0;
        return true;
    }
    
    /**
     * Create performance metrics table for monitoring
     */
    private function create_performance_metrics_table() {
        $sql = "CREATE TABLE {$this->tables['performance_metrics']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            metric_type VARCHAR(100) NOT NULL,
            metric_name VARCHAR(255) NOT NULL,
            metric_value DECIMAL(15,4) NOT NULL,
            metric_unit VARCHAR(50) NULL,
            extraction_id BIGINT(20) UNSIGNED NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            page_url VARCHAR(500) NULL,
            memory_usage BIGINT(20) NULL,
            peak_memory BIGINT(20) NULL,
            cpu_usage DECIMAL(5,2) NULL,
            metadata LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_metric_type_name (metric_type, metric_name),
            KEY idx_created_at (created_at),
            KEY idx_extraction_id (extraction_id),
            KEY idx_user_id (user_id)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Create system alerts table for monitoring critical issues
     */
    private function create_system_alerts_table() {
        $sql = "CREATE TABLE {$this->tables['system_alerts']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            alert_type VARCHAR(50) NOT NULL,
            severity ENUM('info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
            component VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            details LONGTEXT NULL,
            threshold_value DECIMAL(15,4) NULL,
            actual_value DECIMAL(15,4) NULL,
            is_resolved BOOLEAN DEFAULT FALSE,
            resolved_at DATETIME NULL,
            resolved_by BIGINT(20) UNSIGNED NULL,
            notification_sent BOOLEAN DEFAULT FALSE,
            notification_sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_alert_type (alert_type),
            KEY idx_severity (severity),
            KEY idx_component (component),
            KEY idx_is_resolved (is_resolved),
            KEY idx_created_at (created_at)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Create query performance table for tracking slow queries
     */
    private function create_query_performance_table() {
        $sql = "CREATE TABLE {$this->tables['query_performance']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            query_hash VARCHAR(32) NOT NULL,
            query_text TEXT NOT NULL,
            query_type VARCHAR(50) NOT NULL,
            execution_time DECIMAL(10,6) NOT NULL,
            rows_examined INT UNSIGNED NULL,
            rows_returned INT UNSIGNED NULL,
            table_names VARCHAR(500) NULL,
            index_used VARCHAR(255) NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            page_url VARCHAR(500) NULL,
            request_id VARCHAR(50) NULL,
            stack_trace TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_query_hash (query_hash),
            KEY idx_query_type (query_type),
            KEY idx_execution_time (execution_time),
            KEY idx_created_at (created_at),
            KEY idx_user_id (user_id)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Create price predictions table
     */
    private function create_price_predictions_table() {
        $table_name = $this->wpdb->prefix . 'bme_price_predictions';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id INT UNSIGNED NOT NULL,
            predicted_price DECIMAL(15,2) NOT NULL,
            confidence DECIMAL(5,4) NOT NULL,
            min_price DECIMAL(15,2) NULL,
            max_price DECIMAL(15,2) NULL,
            factors TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_listing_id (listing_id),
            KEY idx_created_at (created_at)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
    }

    /**
     * Clean up old data according to retention policies
     * 
     * Retention policies:
     * - activity_logs: 90 days
     * - api_requests: 30 days
     * - performance_metrics: 90 days
     * - query_performance: 30 days
     * - system_alerts: 90 days
     */
    public function cleanup_old_data() {
        $tables_to_clean = [
            'activity_logs' => ['days' => 90, 'date_field' => 'created_at'],
            'api_requests' => ['days' => 30, 'date_field' => 'created_at'],
            'performance_metrics' => ['days' => 90, 'date_field' => 'created_at'],
            'query_performance' => ['days' => 30, 'date_field' => 'created_at'],
            'system_alerts' => ['days' => 90, 'date_field' => 'created_at'],
        ];

        $total_deleted = 0;

        foreach ($tables_to_clean as $table => $config) {
            $table_name = $this->wpdb->prefix . 'bme_' . $table;
            $date_field = $config['date_field'];
            $days = $config['days'];

            // Check if table exists
            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
                continue;
            }

            // Delete old records
            $deleted = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$table_name}
                     WHERE {$date_field} < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $days
                )
            );

            if ($deleted > 0) {
                $total_deleted += $deleted;
                error_log("[BME Cleanup] Deleted {$deleted} old records from {$table} (>{$days} days)");
            }
        }

        // Optimize cleaned tables if significant deletions
        if ($total_deleted > 100) {
            foreach ($tables_to_clean as $table => $config) {
                $table_name = $this->wpdb->prefix . 'bme_' . $table;
                if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
                    $this->wpdb->query("OPTIMIZE TABLE {$table_name}");
                }
            }
            error_log("[BME Cleanup] Optimized tables after deleting {$total_deleted} total records");
        }

        return $total_deleted;
    }

    // ========================================================================
    // PHASE 2 OPTIMIZATION METHODS
    // ========================================================================

    /**
     * Create denormalized summary table for optimized queries
     *
     * Creates a denormalized table combining data from listings, details, location,
     * features, and financial tables. This eliminates expensive JOINs for common queries.
     *
     * @since 4.0.3
     * @return bool True on success, false on failure
     */
    public function create_summary_table() {
        $table_name = $this->wpdb->prefix . 'bme_listing_summary';

        $sql = "CREATE TABLE `{$table_name}` (
            `listing_id` INT UNSIGNED PRIMARY KEY,
            `listing_key` VARCHAR(128) UNIQUE,
            `mls_id` VARCHAR(50),
            `property_type` VARCHAR(50),
            `property_sub_type` VARCHAR(50),
            `standard_status` VARCHAR(50),
            `list_price` DECIMAL(20,2),
            `original_list_price` DECIMAL(20,2),
            `close_price` DECIMAL(20,2),
            `price_per_sqft` DECIMAL(10,2),
            `bedrooms_total` INT,
            `bathrooms_total` DECIMAL(3,1),
            `bathrooms_full` INT,
            `bathrooms_half` INT,
            `building_area_total` INT,
            `lot_size_acres` DECIMAL(10,4),
            `year_built` INT,
            `street_number` VARCHAR(50),
            `street_name` VARCHAR(100),
            `unit_number` VARCHAR(50),
            `city` VARCHAR(100),
            `state_or_province` VARCHAR(2),
            `postal_code` VARCHAR(10),
            `county` VARCHAR(100),
            `latitude` DECIMAL(10,8),
            `longitude` DECIMAL(11,8),
            `garage_spaces` INT,
            `has_pool` BOOLEAN DEFAULT FALSE,
            `has_fireplace` BOOLEAN DEFAULT FALSE,
            `has_basement` BOOLEAN DEFAULT FALSE,
            `has_hoa` BOOLEAN DEFAULT FALSE,
            `pet_friendly` BOOLEAN DEFAULT FALSE,
            `main_photo_url` VARCHAR(500),
            `photo_count` INT DEFAULT 0,
            `virtual_tour_url` VARCHAR(500),
            `listing_contract_date` DATE,
            `close_date` DATE,
            `days_on_market` INT,
            `modification_timestamp` TIMESTAMP,
            KEY `idx_price_range` (`list_price`, `property_type`),
            KEY `idx_location` (`city`, `state_or_province`, `property_type`),
            KEY `idx_beds_baths` (`bedrooms_total`, `bathrooms_total`),
            KEY `idx_size_year` (`building_area_total`, `year_built`),
            KEY `idx_geo_location` (`latitude`, `longitude`),
            KEY `idx_status_date` (`standard_status`, `modification_timestamp`),
            KEY `idx_complex_search` (`city`, `property_type`, `list_price`, `bedrooms_total`)
        ) {$this->charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $result = ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

        if ($result) {
            error_log('[BME Phase 2] Summary table created successfully');
        } else {
            error_log('[BME Phase 2] Failed to create summary table');
        }

        return $result;
    }

    /**
     * Create archive summary table for fast archive/sold listing queries
     *
     * Mirrors the structure of bme_listing_summary but for archive statuses
     * (Closed, Expired, Withdrawn, Canceled). Used by mobile API for fast
     * queries without needing 5-table JOINs.
     *
     * @since 4.0.4
     * @return bool True on success, false on failure
     */
    public function create_archive_summary_table() {
        $table_name = $this->wpdb->prefix . 'bme_listing_summary_archive';

        $sql = "CREATE TABLE `{$table_name}` (
            `listing_id` INT UNSIGNED PRIMARY KEY,
            `listing_key` VARCHAR(128) UNIQUE,
            `mls_id` VARCHAR(50),
            `property_type` VARCHAR(50),
            `property_sub_type` VARCHAR(50),
            `standard_status` VARCHAR(50),
            `list_price` DECIMAL(20,2),
            `original_list_price` DECIMAL(20,2),
            `close_price` DECIMAL(20,2),
            `price_per_sqft` DECIMAL(10,2),
            `bedrooms_total` INT,
            `bathrooms_total` DECIMAL(3,1),
            `bathrooms_full` INT,
            `bathrooms_half` INT,
            `building_area_total` INT,
            `lot_size_acres` DECIMAL(10,4),
            `year_built` INT,
            `street_number` VARCHAR(50),
            `street_name` VARCHAR(100),
            `unit_number` VARCHAR(50),
            `city` VARCHAR(100),
            `state_or_province` VARCHAR(2),
            `postal_code` VARCHAR(10),
            `county` VARCHAR(100),
            `latitude` DECIMAL(10,8),
            `longitude` DECIMAL(11,8),
            `garage_spaces` INT,
            `has_pool` BOOLEAN DEFAULT FALSE,
            `has_fireplace` BOOLEAN DEFAULT FALSE,
            `has_basement` BOOLEAN DEFAULT FALSE,
            `has_hoa` BOOLEAN DEFAULT FALSE,
            `pet_friendly` BOOLEAN DEFAULT FALSE,
            `main_photo_url` VARCHAR(500),
            `photo_count` INT DEFAULT 0,
            `virtual_tour_url` VARCHAR(500),
            `listing_contract_date` DATE,
            `close_date` DATE,
            `days_on_market` INT,
            `modification_timestamp` TIMESTAMP,
            `subdivision_name` VARCHAR(100),
            `unparsed_address` VARCHAR(255),
            KEY `idx_price_range` (`list_price`, `property_type`),
            KEY `idx_close_price` (`close_price`, `property_type`),
            KEY `idx_location` (`city`, `state_or_province`, `property_type`),
            KEY `idx_beds_baths` (`bedrooms_total`, `bathrooms_total`),
            KEY `idx_size_year` (`building_area_total`, `year_built`),
            KEY `idx_geo_location` (`latitude`, `longitude`),
            KEY `idx_status_date` (`standard_status`, `close_date`),
            KEY `idx_complex_search` (`city`, `property_type`, `close_price`, `bedrooms_total`)
        ) {$this->charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $result = ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

        if ($result) {
            error_log('[BME Phase 2] Archive summary table created successfully');
        } else {
            error_log('[BME Phase 2] Failed to create archive summary table');
        }

        return $result;
    }

    /**
     * Create search cache table for caching search results
     *
     * Caches frequently-used search queries to improve performance.
     * Uses MD5 hash of search parameters as cache key.
     *
     * @since 4.0.3
     * @return bool True on success, false on failure
     */
    public function create_search_cache_table() {
        $table_name = $this->wpdb->prefix . 'bme_search_cache';

        $sql = "CREATE TABLE `{$table_name}` (
            `cache_key` VARCHAR(64) PRIMARY KEY,
            `search_params` JSON NOT NULL,
            `result_listing_ids` JSON NOT NULL,
            `result_count` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP,
            `hit_count` INT DEFAULT 0,
            KEY `idx_expires` (`expires_at`),
            KEY `idx_created` (`created_at`)
        ) {$this->charset_collate}";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $result = ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

        if ($result) {
            error_log('[BME Phase 2] Search cache table created successfully');
        } else {
            error_log('[BME Phase 2] Failed to create search cache table');
        }

        return $result;
    }

    /**
     * Create stored procedures for Phase 2 optimization
     *
     * Creates the populate_listing_summary() stored procedure that efficiently
     * populates the summary table from the 5 core listing tables.
     *
     * @since 4.0.3
     * @return bool True on success, false on failure
     */
    public function create_stored_procedures() {
        // Drop procedure if it exists
        $this->wpdb->query("DROP PROCEDURE IF EXISTS populate_listing_summary");

        // Create the populate_listing_summary stored procedure with FIXED column names
        $sql = "CREATE PROCEDURE populate_listing_summary()
BEGIN
    DECLARE listing_count INT DEFAULT 0;

    TRUNCATE TABLE {$this->wpdb->prefix}bme_listing_summary;

    REPLACE INTO {$this->wpdb->prefix}bme_listing_summary (
        listing_id,
        listing_key,
        mls_id,
        property_type,
        property_sub_type,
        standard_status,
        list_price,
        original_list_price,
        close_price,
        price_per_sqft,
        bedrooms_total,
        bathrooms_total,
        bathrooms_full,
        bathrooms_half,
        building_area_total,
        lot_size_acres,
        year_built,
        street_number,
        street_name,
        unit_number,
        city,
        state_or_province,
        postal_code,
        county,
        latitude,
        longitude,
        garage_spaces,
        has_pool,
        has_fireplace,
        has_basement,
        has_hoa,
        pet_friendly,
        pets_dogs_allowed,
        pets_cats_allowed,
        pets_no_pets,
        pets_has_restrictions,
        pets_allowed_raw,
        main_photo_url,
        photo_count,
        virtual_tour_url,
        listing_contract_date,
        close_date,
        days_on_market,
        modification_timestamp
    )
    -- Query active table for Active status
    SELECT
        l.listing_id,
        l.listing_key,
        l.listing_key as mls_id,
        l.property_type,
        l.property_sub_type,
        l.standard_status,
        l.list_price,
        l.original_list_price,
        l.close_price,
        CASE
            WHEN d.building_area_total > 0
            THEN l.list_price / d.building_area_total
            ELSE NULL
        END as price_per_sqft,
        d.bedrooms_total,
        d.bathrooms_total_decimal as bathrooms_total,
        d.bathrooms_full,
        d.bathrooms_half,
        d.building_area_total,
        d.lot_size_acres,
        d.year_built,
        loc.street_number,
        loc.street_name,
        loc.unit_number,
        loc.city,
        loc.state_or_province,
        loc.postal_code,
        loc.county_or_parish as county,
        loc.latitude,
        loc.longitude,
        d.garage_spaces,
        CASE WHEN f.pool_private_yn = 1 THEN 1 ELSE 0 END as has_pool,
        CASE WHEN d.fireplace_yn = 1 THEN 1 ELSE 0 END as has_fireplace,
        CASE WHEN d.basement IS NOT NULL AND d.basement != '' THEN 1 ELSE 0 END as has_basement,
        CASE WHEN fin.association_yn = 1 THEN 1 ELSE 0 END as has_hoa,
        CASE WHEN f.pets_allowed = 1 THEN 1 ELSE 0 END as pet_friendly,
        f.pets_dogs_allowed,
        f.pets_cats_allowed,
        f.pets_no_pets,
        f.pets_has_restrictions,
        f.pets_allowed_raw,
        (SELECT media_url FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo' ORDER BY order_index ASC LIMIT 1) as main_photo_url,
        (SELECT COUNT(*) FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo') as photo_count,
        (SELECT virtual_tour_link_1 FROM {$this->wpdb->prefix}bme_virtual_tours WHERE listing_id = l.listing_id LIMIT 1) as virtual_tour_url,
        l.listing_contract_date,
        l.close_date,
        COALESCE(l.mlspin_market_time_property, DATEDIFF(IFNULL(l.close_date, NOW()), l.listing_contract_date)) as days_on_market,
        l.modification_timestamp
    FROM {$this->wpdb->prefix}bme_listings l
    LEFT JOIN {$this->wpdb->prefix}bme_listing_details d ON l.listing_id = d.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_location loc ON l.listing_id = loc.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_financial fin ON l.listing_id = fin.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_features f ON l.listing_id = f.listing_id
    WHERE l.standard_status = 'Active'

    UNION

    -- Query archive table for Closed, Pending, and Active Under Contract
    SELECT
        l.listing_id,
        l.listing_key,
        l.listing_key as mls_id,
        l.property_type,
        l.property_sub_type,
        l.standard_status,
        l.list_price,
        l.original_list_price,
        l.close_price,
        CASE
            WHEN d.building_area_total > 0
            THEN l.list_price / d.building_area_total
            ELSE NULL
        END as price_per_sqft,
        d.bedrooms_total,
        d.bathrooms_total_decimal as bathrooms_total,
        d.bathrooms_full,
        d.bathrooms_half,
        d.building_area_total,
        d.lot_size_acres,
        d.year_built,
        loc.street_number,
        loc.street_name,
        loc.unit_number,
        loc.city,
        loc.state_or_province,
        loc.postal_code,
        loc.county_or_parish as county,
        loc.latitude,
        loc.longitude,
        d.garage_spaces,
        CASE WHEN f.pool_private_yn = 1 THEN 1 ELSE 0 END as has_pool,
        CASE WHEN d.fireplace_yn = 1 THEN 1 ELSE 0 END as has_fireplace,
        CASE WHEN d.basement IS NOT NULL AND d.basement != '' THEN 1 ELSE 0 END as has_basement,
        CASE WHEN fin.association_yn = 1 THEN 1 ELSE 0 END as has_hoa,
        CASE WHEN f.pets_allowed = 1 THEN 1 ELSE 0 END as pet_friendly,
        f.pets_dogs_allowed,
        f.pets_cats_allowed,
        f.pets_no_pets,
        f.pets_has_restrictions,
        f.pets_allowed_raw,
        (SELECT media_url FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo' ORDER BY order_index ASC LIMIT 1) as main_photo_url,
        (SELECT COUNT(*) FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo') as photo_count,
        (SELECT virtual_tour_link_1 FROM {$this->wpdb->prefix}bme_virtual_tours WHERE listing_id = l.listing_id LIMIT 1) as virtual_tour_url,
        l.listing_contract_date,
        l.close_date,
        COALESCE(l.mlspin_market_time_property, DATEDIFF(IFNULL(l.close_date, NOW()), l.listing_contract_date)) as days_on_market,
        l.modification_timestamp
    FROM {$this->wpdb->prefix}bme_listings_archive l
    LEFT JOIN {$this->wpdb->prefix}bme_listing_details_archive d ON l.listing_id = d.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_financial_archive fin ON l.listing_id = fin.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_features_archive f ON l.listing_id = f.listing_id
    WHERE l.standard_status IN ('Closed', 'Pending', 'Active Under Contract');

    SELECT COUNT(*) INTO listing_count FROM {$this->wpdb->prefix}bme_listing_summary;

    INSERT INTO {$this->wpdb->prefix}bme_activity_logs (
        activity_type,
        action,
        title,
        description,
        severity,
        created_at
    ) VALUES (
        'Maintenance',
        'summary_refresh',
        'Summary Table Refresh',
        CONCAT('Populated summary table with ', listing_count, ' listings'),
        'info',
        NOW()
    );

    SELECT listing_count as listings_populated;
END";

        $result = $this->wpdb->query($sql);

        if ($result !== false) {
            error_log('[BME Phase 2] Stored procedure populate_listing_summary created successfully');
            return true;
        } else {
            error_log('[BME Phase 2] Failed to create stored procedure: ' . $this->wpdb->last_error);
            return false;
        }
    }

    /**
     * Create stored procedure for populating the archive summary table
     *
     * Creates the populate_listing_summary_archive() stored procedure that
     * populates the archive summary table from the 5 archive listing tables.
     *
     * @since 4.0.4
     * @return bool True on success, false on failure
     */
    public function create_archive_summary_stored_procedure() {
        // Drop procedure if it exists
        $this->wpdb->query("DROP PROCEDURE IF EXISTS populate_listing_summary_archive");

        // Create the populate_listing_summary_archive stored procedure
        $sql = "CREATE PROCEDURE populate_listing_summary_archive()
BEGIN
    DECLARE listing_count INT DEFAULT 0;

    TRUNCATE TABLE {$this->wpdb->prefix}bme_listing_summary_archive;

    INSERT INTO {$this->wpdb->prefix}bme_listing_summary_archive (
        listing_id,
        listing_key,
        mls_id,
        property_type,
        property_sub_type,
        standard_status,
        list_price,
        original_list_price,
        close_price,
        price_per_sqft,
        bedrooms_total,
        bathrooms_total,
        bathrooms_full,
        bathrooms_half,
        building_area_total,
        lot_size_acres,
        year_built,
        street_number,
        street_name,
        unit_number,
        city,
        state_or_province,
        postal_code,
        county,
        latitude,
        longitude,
        garage_spaces,
        has_pool,
        has_fireplace,
        has_basement,
        has_hoa,
        pet_friendly,
        pets_dogs_allowed,
        pets_cats_allowed,
        pets_no_pets,
        pets_has_restrictions,
        pets_allowed_raw,
        main_photo_url,
        photo_count,
        virtual_tour_url,
        listing_contract_date,
        close_date,
        days_on_market,
        modification_timestamp,
        subdivision_name,
        unparsed_address
    )
    SELECT
        l.listing_id,
        l.listing_key,
        l.listing_key as mls_id,
        l.property_type,
        l.property_sub_type,
        l.standard_status,
        l.list_price,
        l.original_list_price,
        l.close_price,
        CASE
            WHEN d.building_area_total > 0
            THEN COALESCE(l.close_price, l.list_price) / d.building_area_total
            ELSE NULL
        END as price_per_sqft,
        d.bedrooms_total,
        d.bathrooms_total_decimal as bathrooms_total,
        d.bathrooms_full,
        d.bathrooms_half,
        d.building_area_total,
        d.lot_size_acres,
        d.year_built,
        loc.street_number,
        loc.street_name,
        loc.unit_number,
        loc.city,
        loc.state_or_province,
        loc.postal_code,
        loc.county_or_parish as county,
        loc.latitude,
        loc.longitude,
        d.garage_spaces,
        CASE WHEN f.pool_private_yn = 1 THEN 1 ELSE 0 END as has_pool,
        CASE WHEN d.fireplace_yn = 1 THEN 1 ELSE 0 END as has_fireplace,
        CASE WHEN d.basement IS NOT NULL AND d.basement != '' THEN 1 ELSE 0 END as has_basement,
        CASE WHEN fin.association_yn = 1 THEN 1 ELSE 0 END as has_hoa,
        CASE WHEN f.pets_allowed = 1 THEN 1 ELSE 0 END as pet_friendly,
        f.pets_dogs_allowed,
        f.pets_cats_allowed,
        f.pets_no_pets,
        f.pets_has_restrictions,
        f.pets_allowed_raw,
        (SELECT media_url FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo' ORDER BY order_index ASC LIMIT 1) as main_photo_url,
        (SELECT COUNT(*) FROM {$this->wpdb->prefix}bme_media WHERE listing_id = l.listing_id AND media_category = 'Photo') as photo_count,
        (SELECT virtual_tour_link_1 FROM {$this->wpdb->prefix}bme_virtual_tours WHERE listing_id = l.listing_id LIMIT 1) as virtual_tour_url,
        l.listing_contract_date,
        l.close_date,
        COALESCE(l.mlspin_market_time_property, DATEDIFF(IFNULL(l.close_date, NOW()), l.listing_contract_date)) as days_on_market,
        l.modification_timestamp,
        loc.subdivision_name,
        loc.unparsed_address
    FROM {$this->wpdb->prefix}bme_listings_archive l
    LEFT JOIN {$this->wpdb->prefix}bme_listing_details_archive d ON l.listing_id = d.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_financial_archive fin ON l.listing_id = fin.listing_id
    LEFT JOIN {$this->wpdb->prefix}bme_listing_features_archive f ON l.listing_id = f.listing_id
    WHERE l.standard_status IN ('Closed', 'Expired', 'Withdrawn', 'Canceled');

    SELECT COUNT(*) INTO listing_count FROM {$this->wpdb->prefix}bme_listing_summary_archive;

    INSERT INTO {$this->wpdb->prefix}bme_activity_logs (
        activity_type,
        action,
        title,
        description,
        severity,
        created_at
    ) VALUES (
        'Maintenance',
        'archive_summary_refresh',
        'Archive Summary Table Refresh',
        CONCAT('Populated archive summary table with ', listing_count, ' listings'),
        'info',
        NOW()
    );

    SELECT listing_count as listings_populated;
END";

        $result = $this->wpdb->query($sql);

        if ($result !== false) {
            error_log('[BME Phase 2] Stored procedure populate_listing_summary_archive created successfully');
            return true;
        } else {
            error_log('[BME Phase 2] Failed to create archive stored procedure: ' . $this->wpdb->last_error);
            return false;
        }
    }

    /**
     * Refresh the archive listing summary table
     *
     * Calls the stored procedure to repopulate the archive summary table with current data
     * from all archive source tables. This should be run periodically (hourly recommended)
     * or after significant data changes.
     *
     * @since 4.0.4
     * @return int Number of records updated, or false on error
     */
    public function refresh_archive_summary() {
        $table_name = $this->wpdb->prefix . 'bme_listing_summary_archive';

        // Check if archive summary table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $this->create_archive_summary_table();
        }

        // Check if stored procedure exists
        $proc_exists = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()
             AND ROUTINE_NAME = 'populate_listing_summary_archive'"
        );

        if (!$proc_exists) {
            $this->create_archive_summary_stored_procedure();
        }

        // Call stored procedure and capture the returned count
        $start_time = microtime(true);
        $result = $this->wpdb->get_row("CALL populate_listing_summary_archive()");
        $execution_time = microtime(true) - $start_time;

        // Extract the count from the returned result
        $count = isset($result->listings_populated) ? (int) $result->listings_populated : false;

        if ($count !== false) {
            error_log(sprintf(
                '[BME Phase 2] Archive summary table refreshed: %d listings in %.2f seconds',
                $count,
                $execution_time
            ));
        }

        return $count;
    }

    /**
     * Refresh the listing summary table
     *
     * Calls the stored procedure to repopulate the summary table with current data
     * from all source tables. This should be run periodically (hourly recommended)
     * or after significant data changes.
     *
     * @since 4.0.3
     * @return int Number of records updated, or false on error
     */
    public function refresh_listing_summary() {
        $table_name = $this->wpdb->prefix . 'bme_listing_summary';

        // Check if summary table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $this->create_summary_table();
        }

        // Check if stored procedure exists
        $proc_exists = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()
             AND ROUTINE_NAME = 'populate_listing_summary'"
        );

        if (!$proc_exists) {
            error_log('[BME Phase 2] Stored procedure populate_listing_summary not found');
            return false;
        }

        // Call stored procedure and capture the returned count
        $start_time = microtime(true);
        $result = $this->wpdb->get_row("CALL populate_listing_summary()");
        $execution_time = microtime(true) - $start_time;

        // Extract the count from the returned result
        $count = isset($result->listings_populated) ? (int) $result->listings_populated : false;

        if ($count !== false) {
            error_log(sprintf(
                '[BME Phase 2] Summary table refreshed: %d listings in %.2f seconds',
                $count,
                $execution_time
            ));
        }

        return $count;
    }

    /**
     * Search listings using optimized summary table
     *
     * Performs fast searches on the denormalized summary table instead of
     * expensive multi-table JOINs. Up to 8-10x faster than traditional approach.
     *
     * @since 4.0.3
     * @param array $args {
     *     Search parameters
     *
     *     @type string       $city            City to search in
     *     @type int          $min_price       Minimum list price
     *     @type int          $max_price       Maximum list price
     *     @type int          $bedrooms        Minimum bedrooms
     *     @type int          $bathrooms       Minimum bathrooms
     *     @type int          $min_sqft        Minimum square footage
     *     @type int          $max_sqft        Maximum square footage
     *     @type string       $property_type   Property type filter
     *     @type string       $status          Listing status
     *     @type bool         $has_pool        Must have pool
     *     @type bool         $has_fireplace   Must have fireplace
     *     @type int          $limit           Results limit (default 20)
     *     @type int          $offset          Results offset (default 0)
     *     @type string       $orderby         Order by field (default list_price)
     *     @type string       $order           ASC or DESC (default DESC)
     * }
     * @return array|false Array of results or false on error
     */
    public function search_listings_optimized($args = []) {
        $table = $this->wpdb->prefix . 'bme_listing_summary';

        // Check if summary table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('[BME Phase 2] Summary table does not exist, falling back to standard search');
            return false;
        }

        // Default parameters
        $defaults = [
            'city' => '',
            'min_price' => 0,
            'max_price' => 999999999,
            'bedrooms' => 0,
            'bathrooms' => 0,
            'min_sqft' => 0,
            'max_sqft' => 999999,
            'property_type' => '',
            'status' => 'Active',
            'has_pool' => null,
            'has_fireplace' => null,
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'list_price',
            'order' => 'DESC'
        ];

        $args = wp_parse_args($args, $defaults);

        // Build WHERE conditions
        $where = ['1=1'];
        $values = [];

        if (!empty($args['city'])) {
            $where[] = 'city = %s';
            $values[] = $args['city'];
        }

        if ($args['min_price'] > 0) {
            $where[] = 'list_price >= %d';
            $values[] = $args['min_price'];
        }

        if ($args['max_price'] < 999999999) {
            $where[] = 'list_price <= %d';
            $values[] = $args['max_price'];
        }

        if ($args['bedrooms'] > 0) {
            $where[] = 'bedrooms_total >= %d';
            $values[] = $args['bedrooms'];
        }

        if ($args['bathrooms'] > 0) {
            $where[] = 'bathrooms_total >= %f';
            $values[] = $args['bathrooms'];
        }

        if ($args['min_sqft'] > 0) {
            $where[] = 'building_area_total >= %d';
            $values[] = $args['min_sqft'];
        }

        if ($args['max_sqft'] < 999999) {
            $where[] = 'building_area_total <= %d';
            $values[] = $args['max_sqft'];
        }

        if (!empty($args['property_type'])) {
            $where[] = 'property_type = %s';
            $values[] = $args['property_type'];
        }

        if (!empty($args['status'])) {
            $where[] = 'standard_status = %s';
            $values[] = $args['status'];
        }

        if ($args['has_pool'] === true) {
            $where[] = 'has_pool = 1';
        }

        if ($args['has_fireplace'] === true) {
            $where[] = 'has_fireplace = 1';
        }

        // Build ORDER BY
        $allowed_orderby = ['list_price', 'bedrooms_total', 'bathrooms_total', 'building_area_total', 'days_on_market', 'modification_timestamp'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'list_price';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build final query
        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        // Add limit and offset to values
        $values[] = $args['limit'];
        $values[] = $args['offset'];

        // Execute query
        $start_time = microtime(true);
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $values)
        );
        $execution_time = (microtime(true) - $start_time) * 1000; // Convert to milliseconds

        // Log performance
        error_log(sprintf(
            '[BME Phase 2] Optimized search: %d results in %.2f ms',
            count($results),
            $execution_time
        ));

        return $results;
    }

    /**
     * Get cache statistics for monitoring
     *
     * Returns statistics about search cache usage and effectiveness
     *
     * @since 4.0.3
     * @return array Cache statistics
     */
    public function get_cache_statistics() {
        $cache_table = $this->wpdb->prefix . 'bme_search_cache';

        // Check if table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") !== $cache_table) {
            return [
                'total_cached_searches' => 0,
                'total_cache_hits' => 0,
                'average_hit_count' => 0,
                'cache_hit_rate' => 0,
                'most_popular_searches' => []
            ];
        }

        $stats = [
            'total_cached_searches' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}"),
            'total_cache_hits' => $this->wpdb->get_var("SELECT SUM(hit_count) FROM {$cache_table}"),
            'average_hit_count' => $this->wpdb->get_var("SELECT AVG(hit_count) FROM {$cache_table}"),
            'cache_hit_rate' => 0, // Will be calculated
            'most_popular_searches' => []
        ];

        // Get most popular searches
        $popular = $this->wpdb->get_results(
            "SELECT search_params, hit_count, result_count
             FROM {$cache_table}
             ORDER BY hit_count DESC
             LIMIT 10"
        );

        if ($popular) {
            $stats['most_popular_searches'] = array_map(function($row) {
                return [
                    'params' => json_decode($row->search_params, true),
                    'hits' => (int) $row->hit_count,
                    'results' => (int) $row->result_count
                ];
            }, $popular);
        }

        // Calculate hit rate (hits / (hits + total searches))
        if ($stats['total_cached_searches'] > 0) {
            $stats['cache_hit_rate'] = round(
                ($stats['total_cache_hits'] / ($stats['total_cache_hits'] + $stats['total_cached_searches'])) * 100,
                2
            );
        }

        return $stats;
    }

    /**
     * Cleanup expired search cache entries
     *
     * Removes cache entries that have expired based on their TTL
     *
     * @since 4.0.3
     * @return int Number of entries removed
     */
    public function cleanup_search_cache() {
        $cache_table = $this->wpdb->prefix . 'bme_search_cache';

        // Check if table exists
        if ($this->wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") !== $cache_table) {
            return 0;
        }

        $deleted = $this->wpdb->query(
            "DELETE FROM {$cache_table} WHERE expires_at < NOW()"
        );

        if ($deleted > 0) {
            error_log("[BME Phase 2] Cleaned up {$deleted} expired cache entries");
        }

        return $deleted;
    }
}
