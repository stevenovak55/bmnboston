<?php
/**
 * Database Verification and Repair Tool
 *
 * Provides functionality to verify database integrity and repair issues
 * for the MLS Listings Display plugin.
 *
 * @package MLS_Listings_Display
 * @since 5.2.9
 * @updated 6.6.1 - Added all 40 MLD tables
 * @updated 6.11.14 - Updated form_submissions table schema for AI chatbot tools
 * @updated 6.20.3 - Added mld_cma_value_history table for CMA tracking
 * @updated 6.20.12 - Added notification_data to admin_notifications, page_url to conversations
 * @updated 6.21.0 - Added mld_contact_forms table, updated mld_form_submissions with form_id/form_data columns
 * @updated 6.28.1 - Added 10 BMN Schools plugin tables for comprehensive verification
 * @updated 6.54.1 - Added deferred notifications, client notification preferences, and agent referral tables
 * @updated 6.57.0 - Added mld_recently_viewed_properties table for user property view history
 * @updated 6.58.0 - Added mld_health_history and mld_health_alerts tables for health monitoring
 * @updated 6.68.15 - Verified all 65+ tables for v6.68.15 release
 * @updated 6.75.8 - Added 3 open house system tables (mld_open_houses, mld_open_house_attendees, mld_open_house_notifications)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class MLD_Database_Verify
 *
 * Handles database verification and repair operations
 */
class MLD_Database_Verify {

    /**
     * Instance of this class
     *
     * @var MLD_Database_Verify
     */
    private static $instance = null;

    /**
     * Get instance of this class
     *
     * @return MLD_Database_Verify
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Get list of required tables with their SQL definitions
     *
     * @return array
     */
    public function get_required_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        return array(
            // ===============================================
            // CORE SAVED SEARCH TABLES (5 tables)
            // ===============================================

            'mld_saved_searches' => array(
                'purpose' => 'Stores saved search criteria for users',
                'category' => 'core',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_saved_searches (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    created_by_admin BIGINT(20) UNSIGNED DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    filters LONGTEXT NOT NULL,
                    polygon_shapes LONGTEXT,
                    search_url TEXT,
                    notification_frequency ENUM('instant', 'hourly', 'daily', 'weekly') DEFAULT 'daily',
                    is_active BOOLEAN DEFAULT TRUE,
                    exclude_disliked BOOLEAN DEFAULT TRUE,
                    last_notified_at DATETIME,
                    last_matched_count INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_id (user_id),
                    KEY idx_created_by (created_by_admin),
                    KEY idx_frequency (notification_frequency),
                    KEY idx_active (is_active),
                    KEY idx_last_notified (last_notified_at)
                ) $charset_collate;"
            ),

            'mld_saved_search_results' => array(
                'purpose' => 'Tracks which listings matched each saved search',
                'category' => 'core',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_saved_search_results (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    saved_search_id BIGINT(20) UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    listing_key VARCHAR(128) NOT NULL,
                    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    notified_at DATETIME,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_search_listing (saved_search_id, listing_id),
                    KEY idx_notified (notified_at)
                ) $charset_collate;"
            ),

            'mld_property_preferences' => array(
                'purpose' => 'User liked/disliked properties',
                'category' => 'core',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_property_preferences (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    listing_key VARCHAR(128) NOT NULL,
                    preference_type ENUM('liked', 'disliked') NOT NULL,
                    reason TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_user_listing (user_id, listing_id),
                    KEY idx_user_preference (user_id, preference_type),
                    KEY idx_listing_id (listing_id),
                    KEY idx_created_at (created_at)
                ) $charset_collate;"
            ),

            'mld_saved_search_cron_log' => array(
                'purpose' => 'Logs saved search cron job execution',
                'category' => 'core',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_saved_search_cron_log (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    frequency ENUM('instant', 'hourly', 'daily', 'weekly') NOT NULL,
                    execution_time DATETIME NOT NULL,
                    searches_processed INT DEFAULT 0,
                    notifications_sent INT DEFAULT 0,
                    errors INT DEFAULT 0,
                    execution_duration FLOAT DEFAULT 0,
                    status ENUM('success', 'failed', 'partial') DEFAULT 'success',
                    error_details TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_frequency (frequency),
                    KEY idx_execution_time (execution_time),
                    KEY idx_status (status)
                ) $charset_collate;"
            ),

            'mld_search_cache' => array(
                'purpose' => 'Caches search results for performance',
                'category' => 'core',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_search_cache (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    cache_key varchar(255) NOT NULL,
                    cache_data longtext,
                    expiration datetime NOT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY cache_key (cache_key),
                    KEY expiration (expiration)
                ) $charset_collate;"
            ),

            // ===============================================
            // CMA SYSTEM TABLES (11 tables)
            // ===============================================

            'mld_cma_reports' => array(
                'purpose' => 'Stores Comparative Market Analysis reports',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_reports (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    listing_id varchar(50) DEFAULT NULL,
                    property_address varchar(500) DEFAULT NULL,
                    property_city varchar(100) DEFAULT NULL,
                    property_state varchar(50) DEFAULT NULL,
                    mls_number varchar(100) DEFAULT NULL,
                    estimated_value_low decimal(15,2) DEFAULT NULL,
                    estimated_value_high decimal(15,2) DEFAULT NULL,
                    comparables_count int(11) DEFAULT 0,
                    pdf_path varchar(500) DEFAULT NULL,
                    pdf_url varchar(500) DEFAULT NULL,
                    generated_by varchar(255) DEFAULT NULL,
                    generated_for varchar(255) DEFAULT NULL,
                    include_forecast tinyint(1) DEFAULT 1,
                    include_investment tinyint(1) DEFAULT 1,
                    generated_at datetime DEFAULT CURRENT_TIMESTAMP,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_listing_id (listing_id),
                    KEY property_city_state (property_city, property_state),
                    KEY mls_number (mls_number),
                    KEY generated_at (generated_at)
                ) $charset_collate;"
            ),

            'mld_cma_emails' => array(
                'purpose' => 'Tracks CMA email deliveries',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_emails (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    recipient_email varchar(255) NOT NULL,
                    recipient_name varchar(255) DEFAULT NULL,
                    property_address varchar(500) DEFAULT NULL,
                    agent_name varchar(255) DEFAULT NULL,
                    agent_email varchar(255) DEFAULT NULL,
                    pdf_attached tinyint(1) DEFAULT 0,
                    sent_at datetime NOT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY recipient_email (recipient_email),
                    KEY sent_at (sent_at),
                    KEY agent_email (agent_email)
                ) $charset_collate;"
            ),

            'mld_cma_settings' => array(
                'purpose' => 'CMA configuration and settings',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_settings (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    setting_key varchar(100) NOT NULL,
                    setting_value longtext DEFAULT NULL,
                    setting_type varchar(50) DEFAULT 'string',
                    city varchar(100) DEFAULT NULL,
                    state varchar(50) DEFAULT NULL,
                    property_type varchar(100) DEFAULT NULL,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_setting (setting_key, city, state, property_type),
                    KEY city_state (city, state)
                ) $charset_collate;"
            ),

            'mld_cma_templates' => array(
                'purpose' => 'Saved CMA filter templates/presets',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_templates (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) UNSIGNED NOT NULL,
                    template_name varchar(255) NOT NULL,
                    filters longtext NOT NULL COMMENT 'JSON encoded filter settings',
                    adjustments longtext DEFAULT NULL COMMENT 'JSON encoded adjustment overrides',
                    is_default tinyint(1) DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_templates (user_id, is_default),
                    KEY idx_template_name (template_name(100))
                ) $charset_collate;"
            ),

            'mld_cma_valuation_history' => array(
                'purpose' => 'Tracks CMA valuations over time',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_valuation_history (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    listing_id varchar(50) NOT NULL,
                    estimated_value_low decimal(15,2) DEFAULT NULL,
                    estimated_value_mid decimal(15,2) DEFAULT NULL,
                    estimated_value_high decimal(15,2) DEFAULT NULL,
                    confidence_score int(11) DEFAULT NULL COMMENT 'Confidence score 0-100',
                    comparables_used int(11) DEFAULT NULL,
                    market_conditions longtext DEFAULT NULL COMMENT 'JSON encoded market data snapshot',
                    filters_used longtext DEFAULT NULL COMMENT 'JSON encoded filter settings',
                    generated_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_listing_timeline (listing_id, generated_at),
                    KEY idx_generated_at (generated_at)
                ) $charset_collate;"
            ),

            'mld_market_adjustment_factors' => array(
                'purpose' => 'Localized CMA adjustment values by city/property type',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_market_adjustment_factors (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    city varchar(100) DEFAULT NULL,
                    state varchar(2) DEFAULT NULL,
                    property_type varchar(50) DEFAULT NULL,
                    factor_type varchar(50) NOT NULL COMMENT 'sqft/garage/pool/age/etc',
                    factor_value decimal(10,2) NOT NULL,
                    confidence decimal(5,2) DEFAULT NULL COMMENT 'Statistical confidence 0-100',
                    sample_size int(11) DEFAULT NULL COMMENT 'Number of data points used',
                    effective_date date NOT NULL,
                    expires_at date DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_location_factor (city, state, factor_type, effective_date),
                    KEY idx_type_factor (property_type, factor_type, effective_date),
                    KEY idx_expiration (expires_at)
                ) $charset_collate;"
            ),

            'mld_cma_comparable_cache' => array(
                'purpose' => 'Pre-computed comparable sets cache',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_comparable_cache (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    subject_listing_id varchar(50) NOT NULL,
                    filter_hash varchar(64) NOT NULL COMMENT 'MD5 hash of filter settings',
                    comparable_ids longtext NOT NULL COMMENT 'JSON array of comparable listing IDs',
                    summary_stats longtext DEFAULT NULL COMMENT 'JSON encoded summary statistics',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    expires_at datetime NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_subject_filters (subject_listing_id, filter_hash),
                    KEY idx_expiration (expires_at)
                ) $charset_collate;"
            ),

            'mld_cma_comparables_cache' => array(
                'purpose' => 'Caches comparable properties for CMA reports',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_comparables_cache (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    cache_key varchar(255) NOT NULL,
                    property_data longtext,
                    comparables_data longtext,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    expires_at datetime DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY cache_key (cache_key),
                    KEY expires_at (expires_at)
                ) $charset_collate;"
            ),

            'mld_cma_analytics' => array(
                'purpose' => 'CMA performance analytics and metrics',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_analytics (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    subject_listing_id varchar(50) DEFAULT NULL,
                    city varchar(100) DEFAULT NULL,
                    property_type varchar(50) DEFAULT NULL,
                    price_range varchar(20) DEFAULT NULL COMMENT 'Price bucket for analytics',
                    comparables_found int DEFAULT 0,
                    top_comps_count int DEFAULT 0,
                    confidence_score decimal(5,2) DEFAULT NULL,
                    execution_time_ms decimal(10,2) DEFAULT NULL COMMENT 'Total execution time in milliseconds',
                    query_time_ms decimal(10,2) DEFAULT NULL COMMENT 'Database query time in milliseconds',
                    filters_json longtext COMMENT 'JSON of filters used',
                    error_message varchar(500) DEFAULT NULL,
                    success tinyint(1) DEFAULT 1 COMMENT '1=success 0=error',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_city (city),
                    KEY idx_created (created_at),
                    KEY idx_success (success),
                    KEY idx_property_type (property_type)
                ) $charset_collate;"
            ),

            'mld_user_property_data' => array(
                'purpose' => 'User-contributed property characteristics for CMA adjustments',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_user_property_data (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    listing_id varchar(50) NOT NULL COMMENT 'References BME listing_id',
                    road_type varchar(20) DEFAULT NULL COMMENT 'main_road/neighborhood_road/unknown',
                    road_type_updated_by bigint unsigned DEFAULT NULL COMMENT 'User ID who last updated',
                    road_type_updated_at datetime DEFAULT NULL,
                    property_condition varchar(30) DEFAULT NULL COMMENT 'new/fully_renovated/some_updates/needs_updating/distressed/unknown',
                    condition_updated_by bigint unsigned DEFAULT NULL,
                    condition_updated_at datetime DEFAULT NULL,
                    is_new_construction tinyint(1) DEFAULT 0 COMMENT 'Auto-flagged if year_built within 3 years',
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY listing_id (listing_id),
                    KEY road_type (road_type),
                    KEY property_condition (property_condition),
                    KEY road_type_updated_at (road_type_updated_at),
                    KEY condition_updated_at (condition_updated_at)
                ) $charset_collate;"
            ),

            'mld_cma_saved_sessions' => array(
                'purpose' => 'Stores saved CMA analysis sessions for users (including standalone CMAs)',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_saved_sessions (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    session_name VARCHAR(255) NOT NULL,
                    description TEXT,
                    is_favorite TINYINT(1) DEFAULT 0,
                    is_standalone TINYINT(1) DEFAULT 0 COMMENT '1 for standalone CMAs without MLS listing',
                    standalone_slug VARCHAR(255) DEFAULT NULL COMMENT 'URL slug for standalone CMA pages',
                    subject_listing_id VARCHAR(50) NOT NULL,
                    subject_property_data JSON NOT NULL COMMENT 'Full subject property snapshot',
                    subject_overrides JSON COMMENT 'ARV adjustments if any',
                    cma_filters JSON NOT NULL COMMENT 'All filter settings used',
                    comparables_data JSON COMMENT 'Full comparables array',
                    summary_statistics JSON COMMENT 'CMA summary stats',
                    comparables_count INT DEFAULT 0,
                    estimated_value_mid DECIMAL(15,2),
                    pdf_path VARCHAR(500),
                    pdf_generated_at DATETIME,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_user_id (user_id),
                    KEY idx_user_favorite (user_id, is_favorite),
                    KEY idx_subject_listing (subject_listing_id),
                    KEY idx_created_at (created_at),
                    KEY idx_is_standalone (is_standalone),
                    KEY idx_standalone_slug (standalone_slug)
                ) $charset_collate;"
            ),

            'mld_cma_value_history' => array(
                'purpose' => 'Tracks CMA valuations over time for properties (v6.20.0)',
                'category' => 'cma',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_cma_value_history (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    property_address VARCHAR(255) NOT NULL,
                    property_city VARCHAR(100) DEFAULT NULL,
                    property_state VARCHAR(10) DEFAULT NULL,
                    property_zip VARCHAR(20) DEFAULT NULL,
                    listing_id VARCHAR(50) DEFAULT NULL,
                    session_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    user_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    estimated_value_low DECIMAL(15,2) DEFAULT NULL,
                    estimated_value_mid DECIMAL(15,2) DEFAULT NULL,
                    estimated_value_high DECIMAL(15,2) DEFAULT NULL,
                    weighted_value_mid DECIMAL(15,2) DEFAULT NULL,
                    comparables_count INT(11) DEFAULT 0,
                    top_comps_count INT(11) DEFAULT 0,
                    confidence_score DECIMAL(5,2) DEFAULT NULL,
                    confidence_level VARCHAR(20) DEFAULT NULL,
                    avg_price_per_sqft DECIMAL(10,2) DEFAULT NULL,
                    filters_used LONGTEXT DEFAULT NULL,
                    is_arv_mode TINYINT(1) DEFAULT 0,
                    arv_overrides LONGTEXT DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_property_address (property_address(100), property_city(50)),
                    KEY idx_listing_id (listing_id),
                    KEY idx_session_id (session_id),
                    KEY idx_user_id (user_id),
                    KEY idx_created_at (created_at),
                    KEY idx_city_state (property_city(50), property_state)
                ) $charset_collate;"
            ),

            // ===============================================
            // NOTIFICATION SYSTEM TABLES (8 tables)
            // ===============================================

            'mld_search_activity_matches' => array(
                'purpose' => 'Matches between activity log and saved searches',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_search_activity_matches (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    activity_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    saved_search_id BIGINT UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL DEFAULT 'new_listing',
                    match_score INT DEFAULT 100,
                    notification_status ENUM('pending', 'sent', 'failed', 'throttled') DEFAULT 'pending',
                    notified_at DATETIME,
                    notification_channels TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_activity (activity_log_id),
                    KEY idx_search (saved_search_id),
                    KEY idx_listing (listing_id),
                    KEY idx_status (notification_status),
                    KEY idx_created (created_at)
                ) $charset_collate;"
            ),

            'mld_notification_preferences' => array(
                'purpose' => 'User notification preferences per search',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_preferences (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    saved_search_id BIGINT UNSIGNED DEFAULT NULL,
                    instant_app_notifications BOOLEAN DEFAULT TRUE,
                    instant_email_notifications BOOLEAN DEFAULT TRUE,
                    instant_sms_notifications BOOLEAN DEFAULT FALSE,
                    quiet_hours_start TIME DEFAULT '22:00:00',
                    quiet_hours_end TIME DEFAULT '08:00:00',
                    max_daily_notifications INT DEFAULT 50,
                    notification_types TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_search (user_id, saved_search_id),
                    KEY idx_user (user_id)
                ) $charset_collate;"
            ),

            'mld_notification_throttle' => array(
                'purpose' => 'Daily notification count tracking per user/search',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_throttle (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    saved_search_id BIGINT UNSIGNED NOT NULL,
                    notification_date DATE NOT NULL,
                    notification_count INT DEFAULT 0,
                    last_notification_at DATETIME,
                    throttled_count INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_search_date (user_id, saved_search_id, notification_date),
                    KEY idx_date (notification_date)
                ) $charset_collate;"
            ),

            'mld_notification_queue' => array(
                'purpose' => 'Queue for delayed/throttled notifications',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_queue (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    saved_search_id BIGINT UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL,
                    listing_data JSON,
                    reason_blocked ENUM('quiet_hours', 'daily_limit', 'rate_limited', 'bulk_import', 'system') DEFAULT 'system',
                    retry_after DATETIME NOT NULL,
                    retry_attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    status ENUM('queued', 'processing', 'sent', 'failed', 'expired') DEFAULT 'queued',
                    processed_at DATETIME,
                    error_message TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_retry (retry_after, status),
                    KEY idx_user (user_id),
                    KEY idx_search (saved_search_id),
                    KEY idx_listing (listing_id),
                    KEY idx_status (status),
                    KEY idx_created (created_at)
                ) $charset_collate;"
            ),

            'mld_notification_history' => array(
                'purpose' => 'Complete history of all sent notifications',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_history (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    listing_id VARCHAR(50),
                    notification_type VARCHAR(50) NOT NULL,
                    template_used VARCHAR(100),
                    subject TEXT,
                    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('sent', 'failed', 'bounced') DEFAULT 'sent',
                    error_message TEXT,
                    metadata JSON,
                    KEY idx_user_listing (user_id, listing_id),
                    KEY idx_user_type (user_id, notification_type),
                    KEY idx_sent_at (sent_at),
                    KEY idx_status (status)
                ) $charset_collate;"
            ),

            'mld_notification_analytics' => array(
                'purpose' => 'Notification delivery analytics and metrics',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_analytics (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    user_id bigint unsigned NOT NULL,
                    notification_type varchar(50) NOT NULL,
                    channel varchar(20) NOT NULL,
                    listing_id varchar(50) DEFAULT NULL,
                    sent_at datetime NOT NULL,
                    delivery_status enum('sent','failed','bounced') DEFAULT 'sent',
                    error_message text,
                    response_time_ms int DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user (user_id),
                    KEY idx_type (notification_type),
                    KEY idx_channel (channel),
                    KEY idx_sent_at (sent_at),
                    KEY idx_listing (listing_id)
                ) $charset_collate;"
            ),

            'mld_notification_tracker' => array(
                'purpose' => 'Tracks sent notifications to prevent duplicates',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_tracker (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    user_id bigint unsigned NOT NULL,
                    mls_number varchar(50) NOT NULL,
                    search_id bigint unsigned NOT NULL,
                    notification_type varchar(50) DEFAULT 'listing_update',
                    sent_at datetime NOT NULL,
                    email_sent tinyint(1) DEFAULT 1,
                    buddyboss_sent tinyint(1) DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_notification (user_id, mls_number, search_id),
                    KEY user_id (user_id),
                    KEY mls_number (mls_number),
                    KEY search_id (search_id),
                    KEY sent_at (sent_at),
                    KEY idx_user_id (user_id),
                    KEY idx_mls_number (mls_number),
                    KEY idx_search_id (search_id),
                    KEY idx_sent_at (sent_at),
                    KEY idx_notification_type (notification_type)
                ) $charset_collate;"
            ),

            'mld_saved_search_email_settings' => array(
                'purpose' => 'Admin CC/BCC preferences for saved search notifications',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_saved_search_email_settings (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    saved_search_id bigint unsigned NOT NULL,
                    admin_id bigint unsigned NOT NULL,
                    email_type enum('cc','bcc','none') DEFAULT 'none',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_search_admin (saved_search_id, admin_id),
                    KEY idx_admin_id (admin_id)
                ) $charset_collate;"
            ),

            // ===============================================
            // AGENT/CLIENT MANAGEMENT TABLES (3 tables)
            // ===============================================

            'mld_agent_profiles' => array(
                'purpose' => 'Agent profile information',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_profiles (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    user_id bigint unsigned NOT NULL,
                    agency_name varchar(255) DEFAULT NULL,
                    license_number varchar(100) DEFAULT NULL,
                    phone varchar(20) DEFAULT NULL,
                    email varchar(255) DEFAULT NULL,
                    bio text,
                    specializations text,
                    service_areas text,
                    profile_image_url varchar(500) DEFAULT NULL,
                    is_verified tinyint(1) DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    display_name varchar(255) DEFAULT NULL,
                    office_name varchar(255) DEFAULT NULL,
                    office_address text,
                    photo_url varchar(500) DEFAULT NULL,
                    specialties text,
                    is_active tinyint(1) DEFAULT 1,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_user (user_id),
                    KEY idx_verified (is_verified),
                    KEY idx_user_id (user_id),
                    KEY idx_active (is_active),
                    KEY idx_email (email(50)),
                    KEY idx_is_active (is_active),
                    KEY idx_created_at (created_at)
                ) $charset_collate;"
            ),

            'mld_agent_client_relationships' => array(
                'purpose' => 'Links agents to their clients',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_client_relationships (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    agent_id bigint unsigned NOT NULL,
                    client_id bigint unsigned NOT NULL,
                    status enum('active','inactive') DEFAULT 'active',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    relationship_status enum('active','inactive','pending') DEFAULT 'active',
                    assigned_date datetime DEFAULT CURRENT_TIMESTAMP,
                    notes text,
                    is_active tinyint(1) DEFAULT 1,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_agent_client (agent_id, client_id),
                    KEY idx_agent (agent_id),
                    KEY idx_client (client_id),
                    KEY idx_status (status),
                    KEY idx_client_id (client_id),
                    KEY idx_agent_id (agent_id),
                    KEY idx_agent_client (agent_id, client_id),
                    KEY idx_client_status (client_id, status),
                    KEY idx_is_active (is_active),
                    KEY idx_agent_active (agent_id, is_active)
                ) $charset_collate;"
            ),

            'mld_admin_client_preferences' => array(
                'purpose' => 'Admin preferences for managing client notifications',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_admin_client_preferences (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    admin_id bigint unsigned NOT NULL,
                    client_id bigint unsigned NOT NULL,
                    email_copy_type enum('cc','bcc','none') DEFAULT 'none',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    default_cc_all tinyint(1) DEFAULT 0,
                    default_email_type enum('cc','bcc','none') DEFAULT 'none',
                    can_view_searches tinyint(1) DEFAULT 1,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_admin_client (admin_id, client_id),
                    KEY idx_admin (admin_id),
                    KEY idx_client (client_id),
                    KEY idx_client_id (client_id)
                ) $charset_collate;"
            ),

            // ===============================================
            // CHATBOT SYSTEM TABLES (8 tables)
            // ===============================================

            'mld_chat_conversations' => array(
                'purpose' => 'AI chatbot conversation tracking with context persistence (v6.14.0)',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_conversations (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id varchar(100) NOT NULL,
                    user_id bigint(20) UNSIGNED DEFAULT NULL,
                    user_email varchar(255) DEFAULT NULL,
                    user_name varchar(255) DEFAULT NULL,
                    user_phone varchar(50) DEFAULT NULL,
                    conversation_status varchar(20) DEFAULT 'active',
                    conversation_summary text DEFAULT NULL,
                    total_messages int(11) DEFAULT 0,
                    last_message_at datetime DEFAULT NULL,
                    started_at datetime DEFAULT CURRENT_TIMESTAMP,
                    ended_at datetime DEFAULT NULL,
                    idle_since datetime DEFAULT NULL,
                    summary_sent tinyint(1) DEFAULT 0,
                    summary_sent_at datetime DEFAULT NULL,
                    user_ip varchar(45) DEFAULT NULL,
                    user_agent text DEFAULT NULL,
                    page_url varchar(500) DEFAULT NULL,
                    collected_info longtext DEFAULT NULL COMMENT 'v6.14.0: JSON collected user info',
                    search_context longtext DEFAULT NULL COMMENT 'v6.14.0: JSON active search criteria',
                    shown_properties longtext DEFAULT NULL COMMENT 'v6.14.0: JSON recently shown properties',
                    active_property_id varchar(50) DEFAULT NULL COMMENT 'v6.14.0: Current property being discussed',
                    conversation_state varchar(50) DEFAULT 'initial_greeting' COMMENT 'v6.14.0: Current conversation state',
                    agent_assigned_id int DEFAULT NULL COMMENT 'v6.14.0: Assigned agent user ID',
                    agent_assigned_at datetime DEFAULT NULL,
                    agent_connected_at datetime DEFAULT NULL,
                    property_interest varchar(255) DEFAULT NULL COMMENT 'v6.14.0: Primary property interest',
                    lead_score int DEFAULT 0 COMMENT 'v6.14.0: Lead quality score 0-100',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY session_id (session_id),
                    KEY user_id (user_id),
                    KEY user_email (user_email),
                    KEY conversation_status (conversation_status),
                    KEY started_at (started_at),
                    KEY idle_since (idle_since),
                    KEY idx_conversation_state (conversation_state),
                    KEY idx_active_property (active_property_id),
                    KEY idx_agent_assigned (agent_assigned_id),
                    KEY idx_user_email_lookup (user_email, user_name)
                ) $charset_collate;"
            ),

            'mld_chat_messages' => array(
                'purpose' => 'Individual chatbot messages',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_messages (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    conversation_id bigint(20) UNSIGNED NOT NULL,
                    session_id varchar(100) NOT NULL,
                    sender_type varchar(20) NOT NULL,
                    message_text text NOT NULL,
                    ai_provider varchar(50) DEFAULT NULL,
                    ai_model varchar(100) DEFAULT NULL,
                    ai_tokens_used int(11) DEFAULT NULL,
                    ai_context_injected text DEFAULT NULL,
                    response_time_ms int(11) DEFAULT NULL,
                    is_fallback tinyint(1) DEFAULT 0,
                    fallback_reason varchar(255) DEFAULT NULL,
                    message_metadata longtext DEFAULT NULL,
                    admin_notified tinyint(1) DEFAULT 0,
                    admin_notification_sent_at datetime DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY conversation_id (conversation_id),
                    KEY session_id (session_id),
                    KEY sender_type (sender_type),
                    KEY created_at (created_at),
                    KEY admin_notified (admin_notified)
                ) $charset_collate;"
            ),

            'mld_chat_sessions' => array(
                'purpose' => 'Active chatbot session management',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_sessions (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id varchar(100) NOT NULL,
                    conversation_id bigint(20) UNSIGNED DEFAULT NULL,
                    session_status varchar(20) DEFAULT 'active',
                    last_activity_at datetime DEFAULT CURRENT_TIMESTAMP,
                    idle_timeout_minutes int(11) DEFAULT 10,
                    window_closed tinyint(1) DEFAULT 0,
                    window_closed_at datetime DEFAULT NULL,
                    page_url varchar(500) DEFAULT NULL,
                    referrer_url varchar(500) DEFAULT NULL,
                    device_type varchar(50) DEFAULT NULL,
                    browser varchar(100) DEFAULT NULL,
                    session_data longtext DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY session_id (session_id),
                    KEY conversation_id (conversation_id),
                    KEY session_status (session_status),
                    KEY last_activity_at (last_activity_at),
                    KEY window_closed (window_closed)
                ) $charset_collate;"
            ),

            'mld_chat_settings' => array(
                'purpose' => 'Chatbot configuration and AI settings',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_settings (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    setting_key varchar(100) NOT NULL,
                    setting_value longtext DEFAULT NULL,
                    setting_type varchar(50) DEFAULT 'string',
                    setting_category varchar(50) DEFAULT 'general',
                    is_encrypted tinyint(1) DEFAULT 0,
                    description text DEFAULT NULL,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_setting_key (setting_key),
                    KEY setting_category (setting_category)
                ) $charset_collate;"
            ),

            'mld_chat_knowledge_base' => array(
                'purpose' => 'Daily website content scan for AI context',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_knowledge_base (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    content_type varchar(50) NOT NULL,
                    content_id varchar(100) DEFAULT NULL,
                    content_title varchar(500) DEFAULT NULL,
                    content_text longtext DEFAULT NULL,
                    content_summary text DEFAULT NULL,
                    content_url varchar(500) DEFAULT NULL,
                    content_metadata longtext DEFAULT NULL,
                    embedding_vector longtext DEFAULT NULL,
                    relevance_score float DEFAULT 1.0,
                    scan_date datetime DEFAULT CURRENT_TIMESTAMP,
                    is_active tinyint(1) DEFAULT 1,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY content_type (content_type),
                    KEY content_id (content_id),
                    KEY scan_date (scan_date),
                    KEY is_active (is_active),
                    FULLTEXT KEY content_search (content_title, content_text, content_summary)
                ) $charset_collate;"
            ),

            'mld_chat_faq_library' => array(
                'purpose' => 'FAQ library for fallback responses',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_faq_library (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    question text NOT NULL,
                    answer longtext NOT NULL,
                    keywords varchar(500) DEFAULT NULL,
                    category varchar(100) DEFAULT NULL,
                    usage_count int(11) DEFAULT 0,
                    last_used_at datetime DEFAULT NULL,
                    is_active tinyint(1) DEFAULT 1,
                    priority int(11) DEFAULT 0,
                    created_by bigint(20) UNSIGNED DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY category (category),
                    KEY is_active (is_active),
                    KEY priority (priority),
                    FULLTEXT KEY faq_search (question, answer, keywords)
                ) $charset_collate;"
            ),

            'mld_chat_admin_notifications' => array(
                'purpose' => 'Queue for admin chatbot notifications',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_admin_notifications (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    conversation_id bigint(20) UNSIGNED NOT NULL,
                    message_id bigint(20) UNSIGNED NOT NULL,
                    notification_type varchar(50) DEFAULT 'new_message',
                    admin_email varchar(255) NOT NULL,
                    notification_status varchar(20) DEFAULT 'pending',
                    notification_data longtext DEFAULT NULL,
                    sent_at datetime DEFAULT NULL,
                    error_message text DEFAULT NULL,
                    retry_count int(11) DEFAULT 0,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY conversation_id (conversation_id),
                    KEY message_id (message_id),
                    KEY notification_status (notification_status),
                    KEY admin_email (admin_email),
                    KEY created_at (created_at)
                ) $charset_collate;"
            ),

            'mld_chat_email_summaries' => array(
                'purpose' => 'Email summaries of chatbot conversations',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_email_summaries (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    conversation_id bigint(20) UNSIGNED NOT NULL,
                    recipient_email varchar(255) NOT NULL,
                    recipient_name varchar(255) DEFAULT NULL,
                    summary_html longtext DEFAULT NULL,
                    summary_text longtext DEFAULT NULL,
                    properties_discussed text DEFAULT NULL,
                    next_steps text DEFAULT NULL,
                    trigger_reason varchar(50) DEFAULT NULL,
                    email_status varchar(20) DEFAULT 'pending',
                    sent_at datetime DEFAULT NULL,
                    error_message text DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY conversation_id (conversation_id),
                    KEY recipient_email (recipient_email),
                    KEY email_status (email_status),
                    KEY created_at (created_at)
                ) $charset_collate;"
            ),

            // ===============================================
            // PROPERTY DATA TABLES (3 tables)
            // ===============================================

            'mld_property_views' => array(
                'purpose' => 'Tracks property view statistics',
                'category' => 'property_data',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_property_views (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    listing_id varchar(50) NOT NULL,
                    user_id bigint(20) UNSIGNED DEFAULT NULL,
                    ip_address varchar(45),
                    viewed_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY listing_id (listing_id),
                    KEY user_id (user_id),
                    KEY viewed_at (viewed_at)
                ) $charset_collate;"
            ),

            'mld_form_submissions' => array(
                'purpose' => 'Contact forms, tour requests, AI chatbot submissions, and custom form submissions',
                'category' => 'property_data',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_form_submissions (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    form_id bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Links to mld_contact_forms for custom forms',
                    form_data JSON COMMENT 'Dynamic field data from custom forms',
                    form_type varchar(50) NOT NULL,
                    property_mls varchar(50) DEFAULT NULL,
                    property_address text DEFAULT NULL,
                    first_name varchar(100) DEFAULT NULL,
                    last_name varchar(100) DEFAULT NULL,
                    email varchar(255) DEFAULT NULL,
                    phone varchar(20) DEFAULT NULL,
                    message longtext,
                    tour_type varchar(50) DEFAULT NULL,
                    preferred_date date DEFAULT NULL,
                    preferred_time varchar(50) DEFAULT NULL,
                    status enum('new','contacted','qualified','closed') DEFAULT 'new',
                    source varchar(100) DEFAULT NULL COMMENT 'e.g. ai_chatbot/website/manual/custom_form',
                    utm_source varchar(100) DEFAULT NULL,
                    utm_medium varchar(100) DEFAULT NULL,
                    utm_campaign varchar(100) DEFAULT NULL,
                    agent_id bigint unsigned DEFAULT NULL,
                    ip_address varchar(45) DEFAULT NULL,
                    user_agent text,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_form_id (form_id),
                    KEY idx_form_type (form_type),
                    KEY idx_property_mls (property_mls),
                    KEY idx_email (email(50)),
                    KEY idx_status (status),
                    KEY idx_created_at (created_at),
                    KEY idx_type_status (form_type, status),
                    KEY idx_status_date (status, created_at)
                ) $charset_collate;"
            ),

            'mld_listing_id_map' => array(
                'purpose' => 'Maps MLS numbers to internal IDs across active/archive tables',
                'category' => 'property_data',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_listing_id_map (
                    mls_number varchar(50) NOT NULL,
                    internal_id bigint unsigned NOT NULL,
                    table_source enum('active','archive') NOT NULL,
                    last_updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (mls_number),
                    KEY idx_internal_id (internal_id),
                    KEY idx_source (table_source)
                ) $charset_collate;"
            ),

            // ===============================================
            // LOCATION/SCHOOLS TABLES (3 tables)
            // ===============================================

            'mld_schools' => array(
                'purpose' => 'School information database',
                'category' => 'location',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_schools (
                    id int NOT NULL AUTO_INCREMENT,
                    osm_id bigint DEFAULT NULL,
                    name varchar(255) NOT NULL,
                    school_type varchar(50) DEFAULT NULL,
                    grades varchar(50) DEFAULT NULL,
                    school_level varchar(20) DEFAULT NULL,
                    address varchar(255) DEFAULT NULL,
                    city varchar(100) DEFAULT NULL,
                    state varchar(50) DEFAULT 'Massachusetts',
                    postal_code varchar(20) DEFAULT NULL,
                    latitude decimal(10,7) DEFAULT NULL,
                    longitude decimal(10,7) DEFAULT NULL,
                    phone varchar(50) DEFAULT NULL,
                    website varchar(255) DEFAULT NULL,
                    rating decimal(3,1) DEFAULT NULL,
                    rating_source varchar(50) DEFAULT NULL,
                    student_count int DEFAULT NULL,
                    student_teacher_ratio decimal(4,1) DEFAULT NULL,
                    district varchar(255) DEFAULT NULL,
                    district_id int DEFAULT NULL,
                    data_source varchar(50) DEFAULT 'OpenStreetMap',
                    amenities text,
                    last_updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    level varchar(50) DEFAULT 'Elementary',
                    type varchar(50) DEFAULT 'Public',
                    PRIMARY KEY (id),
                    UNIQUE KEY osm_id (osm_id),
                    KEY idx_location (latitude, longitude),
                    KEY idx_city (city),
                    KEY idx_type (school_type),
                    KEY idx_level (school_level),
                    KEY idx_rating (rating),
                    KEY idx_district (district_id),
                    KEY idx_name (name),
                    KEY idx_state (state)
                ) $charset_collate;"
            ),

            'mld_property_schools' => array(
                'purpose' => 'Links properties to nearby schools',
                'category' => 'location',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_property_schools (
                    id int NOT NULL AUTO_INCREMENT,
                    listing_id varchar(50) NOT NULL,
                    school_id int NOT NULL,
                    distance_miles decimal(4,2) DEFAULT NULL,
                    drive_time_minutes int DEFAULT NULL,
                    walk_time_minutes int DEFAULT NULL,
                    assigned_school tinyint(1) DEFAULT 0,
                    school_level varchar(20) DEFAULT NULL,
                    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                    distance decimal(5,2) DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY listing_school (listing_id, school_id),
                    KEY idx_listing (listing_id),
                    KEY idx_school (school_id),
                    KEY idx_distance (distance_miles),
                    KEY idx_assigned (assigned_school),
                    KEY idx_listing_id (listing_id),
                    KEY idx_school_id (school_id)
                ) $charset_collate;"
            ),

            'mld_city_boundaries' => array(
                'purpose' => 'Cached city boundary polygons',
                'category' => 'location',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_city_boundaries (
                    id int NOT NULL AUTO_INCREMENT,
                    city varchar(100) NOT NULL,
                    state varchar(50) NOT NULL,
                    country varchar(50) DEFAULT 'USA',
                    boundary_type varchar(50) DEFAULT 'city',
                    display_name varchar(255) DEFAULT NULL,
                    boundary_data longtext NOT NULL,
                    bbox_north decimal(10,7) DEFAULT NULL,
                    bbox_south decimal(10,7) DEFAULT NULL,
                    bbox_east decimal(10,7) DEFAULT NULL,
                    bbox_west decimal(10,7) DEFAULT NULL,
                    fetched_at timestamp DEFAULT CURRENT_TIMESTAMP,
                    last_used timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY city_state_type (city, state, boundary_type),
                    KEY last_used_idx (last_used),
                    KEY idx_city (city(50)),
                    KEY idx_state (state),
                    KEY idx_boundary_type (boundary_type),
                    KEY idx_city_state (city(50), state),
                    KEY idx_display_name (display_name)
                ) $charset_collate;"
            ),

            // ===============================================
            // CHATBOT ENHANCED TABLES (8 tables) - Added 6.10.5
            // ===============================================

            'mld_chat_agent_assignments' => array(
                'purpose' => 'Tracks agent assignments to chat conversations',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_agent_assignments (
                    id int NOT NULL AUTO_INCREMENT,
                    conversation_id int NOT NULL,
                    agent_id int DEFAULT NULL,
                    agent_name varchar(255) DEFAULT NULL,
                    agent_email varchar(255) DEFAULT NULL,
                    agent_phone varchar(50) DEFAULT NULL,
                    assignment_type enum('automatic','manual','round_robin','availability') DEFAULT 'automatic',
                    assignment_reason text,
                    notification_sent tinyint(1) DEFAULT 0,
                    notification_sent_at datetime DEFAULT NULL,
                    agent_responded tinyint(1) DEFAULT 0,
                    agent_responded_at datetime DEFAULT NULL,
                    response_time_seconds int DEFAULT NULL,
                    lead_status enum('new','contacted','qualified','converted','lost') DEFAULT 'new',
                    lead_notes text,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_conversation (conversation_id),
                    KEY idx_agent (agent_id),
                    KEY idx_status (lead_status),
                    KEY idx_notification (notification_sent),
                    KEY idx_created (created_at)
                ) $charset_collate;"
            ),

            'mld_chat_data_references' => array(
                'purpose' => 'Stores data reference patterns for chatbot queries',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_data_references (
                    id int NOT NULL AUTO_INCREMENT,
                    reference_type varchar(50) NOT NULL,
                    reference_key varchar(100) NOT NULL,
                    table_name varchar(100) DEFAULT NULL,
                    query_template text,
                    parameters text,
                    description text,
                    patterns text,
                    confidence_threshold decimal(3,2) DEFAULT 0.70,
                    usage_count int DEFAULT 0,
                    last_used datetime DEFAULT NULL,
                    is_active tinyint(1) DEFAULT 1,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_reference (reference_type, reference_key),
                    KEY idx_type (reference_type),
                    KEY idx_active (is_active),
                    KEY idx_usage (usage_count)
                ) $charset_collate;"
            ),

            'mld_chat_query_patterns' => array(
                'purpose' => 'Stores query patterns for chatbot database lookups',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_query_patterns (
                    id int NOT NULL AUTO_INCREMENT,
                    pattern_name varchar(100) NOT NULL,
                    pattern_regex text NOT NULL,
                    query_type varchar(50) NOT NULL,
                    query_template text NOT NULL,
                    required_params text,
                    example_question text,
                    success_rate decimal(5,2) DEFAULT 0.00,
                    usage_count int DEFAULT 0,
                    is_active tinyint(1) DEFAULT 1,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_pattern (pattern_name),
                    KEY idx_type (query_type),
                    KEY idx_active (is_active),
                    KEY idx_usage (usage_count)
                ) $charset_collate;"
            ),

            'mld_chat_response_cache' => array(
                'purpose' => 'Caches chatbot responses for performance',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_response_cache (
                    id int NOT NULL AUTO_INCREMENT,
                    question_hash varchar(64) NOT NULL,
                    question text NOT NULL,
                    response text NOT NULL,
                    response_type enum('faq','data_query','ai_generated','template') DEFAULT 'ai_generated',
                    context_hash varchar(64) DEFAULT NULL,
                    confidence_score decimal(3,2) DEFAULT 0.00,
                    tokens_used int DEFAULT 0,
                    hit_count int DEFAULT 0,
                    last_accessed datetime DEFAULT NULL,
                    expires_at datetime DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_question (question_hash, context_hash),
                    KEY idx_type (response_type),
                    KEY idx_expires (expires_at),
                    KEY idx_hits (hit_count)
                ) $charset_collate;"
            ),

            'mld_chat_state_history' => array(
                'purpose' => 'Tracks conversation state transitions',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_state_history (
                    id int NOT NULL AUTO_INCREMENT,
                    conversation_id int NOT NULL,
                    from_state varchar(50) DEFAULT NULL,
                    to_state varchar(50) NOT NULL,
                    trigger_message text,
                    collected_data text,
                    transition_time datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_conversation (conversation_id),
                    KEY idx_states (from_state, to_state),
                    KEY idx_time (transition_time)
                ) $charset_collate;"
            ),

            'mld_chat_training' => array(
                'purpose' => 'Stores training examples for chatbot improvement',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_chat_training (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    example_type enum('good','bad','needs_improvement') NOT NULL DEFAULT 'good',
                    user_message text NOT NULL,
                    ai_response text NOT NULL,
                    feedback_notes text,
                    conversation_context text,
                    ai_provider varchar(50) DEFAULT NULL,
                    ai_model varchar(100) DEFAULT NULL,
                    tokens_used int DEFAULT NULL,
                    rating tinyint DEFAULT NULL COMMENT 'Optional 1-5 rating',
                    tags varchar(255) DEFAULT NULL COMMENT 'Comma-separated tags',
                    created_by bigint(20) unsigned DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_example_type (example_type),
                    KEY idx_created_at (created_at),
                    KEY idx_created_by (created_by)
                ) $charset_collate;"
            ),

            'mld_prompt_usage' => array(
                'purpose' => 'Tracks prompt variant usage for A/B testing',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_prompt_usage (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    conversation_id bigint(20) unsigned NOT NULL,
                    variant_id bigint(20) unsigned DEFAULT NULL,
                    prompt_used text NOT NULL,
                    user_rating tinyint DEFAULT NULL,
                    response_time_ms int DEFAULT NULL,
                    tokens_used int DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_conversation (conversation_id),
                    KEY idx_variant (variant_id),
                    KEY idx_created_at (created_at),
                    KEY idx_rating (user_rating)
                ) $charset_collate;"
            ),

            'mld_prompt_variants' => array(
                'purpose' => 'Stores prompt variants for A/B testing',
                'category' => 'chatbot',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_prompt_variants (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    variant_name varchar(100) NOT NULL,
                    prompt_content text NOT NULL,
                    is_active tinyint(1) DEFAULT 1,
                    weight int DEFAULT 50 COMMENT 'Traffic percentage (0-100)',
                    total_uses int DEFAULT 0,
                    total_ratings int DEFAULT 0,
                    average_rating decimal(3,2) DEFAULT NULL,
                    positive_feedback int DEFAULT 0,
                    negative_feedback int DEFAULT 0,
                    created_by bigint(20) unsigned DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_active (is_active),
                    KEY idx_created_at (created_at)
                ) $charset_collate;"
            )
,

            // ===============================================
            // MARKET ANALYTICS TABLES (4 tables) - Added v6.12.0
            // ===============================================

            'mld_market_stats_monthly' => array(
                'purpose' => 'Pre-computed monthly market statistics for analytics',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_market_stats_monthly (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    year smallint NOT NULL,
                    month tinyint NOT NULL,
                    city varchar(100) NOT NULL,
                    state varchar(10) NOT NULL,
                    property_type varchar(50) DEFAULT 'all',
                    total_sales int DEFAULT 0,
                    total_volume decimal(15,2) DEFAULT NULL,
                    avg_close_price decimal(12,2) DEFAULT NULL,
                    median_close_price decimal(12,2) DEFAULT NULL,
                    avg_dom decimal(5,1) DEFAULT NULL,
                    median_dom int DEFAULT NULL,
                    avg_sp_lp_ratio decimal(5,2) DEFAULT NULL,
                    new_listings int DEFAULT 0,
                    active_inventory int DEFAULT 0,
                    pending_inventory int DEFAULT 0,
                    months_of_supply decimal(4,1) DEFAULT NULL,
                    absorption_rate decimal(5,2) DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_period (city, state, year, month, property_type),
                    KEY idx_city (city)
                ) $charset_collate;"
            ),

            'mld_city_market_summary' => array(
                'purpose' => 'Current market state cache for cities',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_city_market_summary (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    city varchar(100) NOT NULL,
                    state varchar(10) NOT NULL,
                    property_type varchar(50) DEFAULT 'all',
                    active_count int DEFAULT 0,
                    pending_count int DEFAULT 0,
                    avg_list_price decimal(12,2) DEFAULT NULL,
                    median_list_price decimal(12,2) DEFAULT NULL,
                    sold_12m int DEFAULT 0,
                    avg_close_price_12m decimal(12,2) DEFAULT NULL,
                    avg_dom_12m decimal(5,1) DEFAULT NULL,
                    avg_sp_lp_12m decimal(5,2) DEFAULT NULL,
                    months_of_supply decimal(4,1) DEFAULT NULL,
                    market_heat_index int DEFAULT NULL,
                    market_classification varchar(20) DEFAULT NULL,
                    last_updated datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_city (city, state, property_type)
                ) $charset_collate;"
            ),

            'mld_agent_performance' => array(
                'purpose' => 'Agent and office performance metrics',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_performance (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    agent_mls_id varchar(50) NOT NULL,
                    agent_name varchar(255) DEFAULT NULL,
                    office_mls_id varchar(50) DEFAULT NULL,
                    office_name varchar(255) DEFAULT NULL,
                    city varchar(100) DEFAULT NULL,
                    state varchar(10) DEFAULT NULL,
                    period_year smallint NOT NULL,
                    transaction_count int DEFAULT 0,
                    total_volume decimal(15,2) DEFAULT NULL,
                    avg_sale_price decimal(12,2) DEFAULT NULL,
                    avg_dom decimal(5,1) DEFAULT NULL,
                    volume_rank int DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_agent_period (agent_mls_id, city, period_year),
                    KEY idx_city_rank (city, volume_rank)
                ) $charset_collate;"
            ),

            'mld_feature_premiums' => array(
                'purpose' => 'Property feature value premium analysis',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_feature_premiums (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    city varchar(100) NOT NULL,
                    state varchar(10) NOT NULL,
                    property_type varchar(50) DEFAULT 'all',
                    feature_name varchar(50) NOT NULL,
                    premium_amount decimal(12,2) DEFAULT NULL,
                    premium_pct decimal(6,2) DEFAULT NULL,
                    sample_with int DEFAULT 0,
                    sample_without int DEFAULT 0,
                    confidence_level varchar(20) DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_feature (city, state, property_type, feature_name)
                ) $charset_collate;"
            ),

            // ===============================================
            // CONTACT FORMS TABLES (1 table) - Added in 6.21.0
            // ===============================================

            'mld_contact_forms' => array(
                'purpose' => 'Stores custom contact form configurations with drag-and-drop field builder',
                'category' => 'contact_forms',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_contact_forms (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    form_name varchar(255) NOT NULL,
                    form_slug varchar(100) NOT NULL,
                    description text,
                    fields JSON NOT NULL COMMENT 'Array of field definitions',
                    settings JSON NOT NULL COMMENT 'Form-specific settings',
                    notification_settings JSON COMMENT 'Per-form notification overrides',
                    status enum('active','draft','archived') DEFAULT 'active',
                    submission_count int UNSIGNED DEFAULT 0,
                    created_by bigint(20) UNSIGNED,
                    created_at datetime NOT NULL,
                    updated_at datetime NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_form_slug (form_slug),
                    KEY idx_status (status),
                    KEY idx_created_at (created_at)
                ) $charset_collate;"
            ),

            // ===============================================
            // BMN SCHOOLS PLUGIN TABLES (10 tables) - Added v6.28.1
            // ===============================================

            'bmn_schools' => array(
                'purpose' => 'Massachusetts schools directory with location and enrollment data',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_schools (
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
                ) $charset_collate;"
            ),

            'bmn_school_districts' => array(
                'purpose' => 'School district information with GeoJSON boundaries and spending data',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_districts (
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
                    extra_data LONGTEXT DEFAULT NULL COMMENT 'JSON spending data including teacher salary and expenditure per pupil',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_nces_id (nces_district_id),
                    KEY idx_state_id (state_district_id),
                    KEY idx_city (city),
                    KEY idx_county (county)
                ) $charset_collate;"
            ),

            'bmn_school_locations' => array(
                'purpose' => 'School-to-location mapping for attendance zones',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_locations (
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
                ) $charset_collate;"
            ),

            'bmn_school_test_scores' => array(
                'purpose' => 'MCAS test scores by school, year, grade, and subject',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_test_scores (
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
                ) $charset_collate;"
            ),

            'bmn_school_rankings' => array(
                'purpose' => 'Third-party school ratings (GreatSchools, SchoolDigger)',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_rankings (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    school_id BIGINT UNSIGNED NOT NULL,
                    source VARCHAR(50) NOT NULL,
                    rating DECIMAL(3,1) DEFAULT NULL,
                    rating_band VARCHAR(20) DEFAULT NULL,
                    rank_state INT UNSIGNED DEFAULT NULL,
                    rank_district INT UNSIGNED DEFAULT NULL,
                    rank_percentile DECIMAL(5,2) DEFAULT NULL,
                    rating_date DATE DEFAULT NULL,
                    raw_data LONGTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_school_source (school_id, source),
                    KEY idx_rating (rating),
                    KEY idx_date (rating_date)
                ) $charset_collate;"
            ),

            'bmn_school_demographics' => array(
                'purpose' => 'Student demographics by school and year (enrollment, race, ELL, SPED)',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_demographics (
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
                ) $charset_collate;"
            ),

            'bmn_school_features' => array(
                'purpose' => 'School features and programs (AP, staffing, graduation rates, attendance)',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_features (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    school_id BIGINT UNSIGNED NOT NULL,
                    feature_type VARCHAR(50) NOT NULL,
                    feature_name VARCHAR(100) NOT NULL,
                    feature_value TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_school (school_id),
                    KEY idx_type (feature_type),
                    KEY idx_name (feature_name(50))
                ) $charset_collate;"
            ),

            'bmn_school_attendance_zones' => array(
                'purpose' => 'Individual school attendance boundary polygons',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_attendance_zones (
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
                ) $charset_collate;"
            ),

            'bmn_school_data_sources' => array(
                'purpose' => 'Tracks data source sync status and import history',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_school_data_sources (
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
                ) $charset_collate;"
            ),

            'bmn_schools_activity_log' => array(
                'purpose' => 'Activity and error logging for BMN Schools plugin',
                'category' => 'schools',
                'sql' => "CREATE TABLE {$wpdb->prefix}bmn_schools_activity_log (
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
                ) $charset_collate;"
            ),

            // ===============================================
            // PUBLIC ANALYTICS TABLES (5 tables) - Added v6.43.1
            // ===============================================

            'mld_public_sessions' => array(
                'purpose' => 'Tracks anonymous visitor sessions for site analytics',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_public_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id VARCHAR(64) NOT NULL,
                    visitor_id VARCHAR(64) DEFAULT NULL,
                    user_id BIGINT UNSIGNED DEFAULT NULL,
                    platform VARCHAR(20) DEFAULT 'web',
                    device_type VARCHAR(20) DEFAULT NULL,
                    browser VARCHAR(50) DEFAULT NULL,
                    browser_version VARCHAR(20) DEFAULT NULL,
                    os VARCHAR(50) DEFAULT NULL,
                    os_version VARCHAR(20) DEFAULT NULL,
                    screen_width INT UNSIGNED DEFAULT NULL,
                    screen_height INT UNSIGNED DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    country VARCHAR(2) DEFAULT NULL,
                    region VARCHAR(100) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    referrer VARCHAR(500) DEFAULT NULL,
                    referrer_domain VARCHAR(255) DEFAULT NULL,
                    utm_source VARCHAR(100) DEFAULT NULL,
                    utm_medium VARCHAR(100) DEFAULT NULL,
                    utm_campaign VARCHAR(100) DEFAULT NULL,
                    utm_content VARCHAR(100) DEFAULT NULL,
                    utm_term VARCHAR(100) DEFAULT NULL,
                    landing_page VARCHAR(500) DEFAULT NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_activity_at DATETIME DEFAULT NULL,
                    ended_at DATETIME DEFAULT NULL,
                    page_views INT UNSIGNED DEFAULT 0,
                    events_count INT UNSIGNED DEFAULT 0,
                    properties_viewed INT UNSIGNED DEFAULT 0,
                    searches_performed INT UNSIGNED DEFAULT 0,
                    bounce TINYINT(1) DEFAULT 1,
                    duration_seconds INT UNSIGNED DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_session_id (session_id),
                    KEY idx_visitor_id (visitor_id),
                    KEY idx_user_id (user_id),
                    KEY idx_started_at (started_at),
                    KEY idx_platform (platform),
                    KEY idx_city (city),
                    KEY idx_referrer_domain (referrer_domain(100))
                ) $charset_collate;"
            ),

            'mld_public_events' => array(
                'purpose' => 'Individual tracking events from visitors (30-day retention)',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_public_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id VARCHAR(64) NOT NULL,
                    visitor_id VARCHAR(64) DEFAULT NULL,
                    user_id BIGINT UNSIGNED DEFAULT NULL,
                    event_type VARCHAR(50) NOT NULL,
                    event_category VARCHAR(50) DEFAULT NULL,
                    page_url VARCHAR(500) DEFAULT NULL,
                    page_title VARCHAR(255) DEFAULT NULL,
                    listing_id VARCHAR(50) DEFAULT NULL,
                    listing_key VARCHAR(128) DEFAULT NULL,
                    search_query TEXT DEFAULT NULL,
                    search_filters JSON DEFAULT NULL,
                    search_results_count INT DEFAULT NULL,
                    element_id VARCHAR(100) DEFAULT NULL,
                    element_text VARCHAR(255) DEFAULT NULL,
                    extra_data JSON DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_session_id (session_id),
                    KEY idx_visitor_id (visitor_id),
                    KEY idx_user_id (user_id),
                    KEY idx_event_type (event_type),
                    KEY idx_created_at (created_at),
                    KEY idx_listing_id (listing_id),
                    KEY idx_cleanup (created_at)
                ) $charset_collate;"
            ),

            'mld_analytics_hourly' => array(
                'purpose' => 'Pre-aggregated hourly analytics statistics',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_analytics_hourly (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    hour_start DATETIME NOT NULL,
                    platform VARCHAR(20) DEFAULT 'all',
                    metric_type VARCHAR(50) NOT NULL,
                    metric_key VARCHAR(100) DEFAULT NULL,
                    metric_value BIGINT DEFAULT 0,
                    unique_sessions INT DEFAULT 0,
                    unique_visitors INT DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_hour_metric (hour_start, platform, metric_type, metric_key(50)),
                    KEY idx_hour_start (hour_start),
                    KEY idx_metric_type (metric_type)
                ) $charset_collate;"
            ),

            'mld_analytics_daily' => array(
                'purpose' => 'Pre-aggregated daily analytics statistics (permanent)',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_analytics_daily (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    date DATE NOT NULL,
                    platform VARCHAR(20) DEFAULT 'all',
                    metric_type VARCHAR(50) NOT NULL,
                    metric_key VARCHAR(100) DEFAULT NULL,
                    metric_value BIGINT DEFAULT 0,
                    unique_sessions INT DEFAULT 0,
                    unique_visitors INT DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_date_metric (date, platform, metric_type, metric_key(50)),
                    KEY idx_date (date),
                    KEY idx_metric_type (metric_type)
                ) $charset_collate;"
            ),

            'mld_realtime_presence' => array(
                'purpose' => 'Real-time visitor presence tracking (MEMORY engine for speed)',
                'category' => 'analytics',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_realtime_presence (
                    session_id VARCHAR(64) NOT NULL,
                    visitor_id VARCHAR(64) DEFAULT NULL,
                    user_id BIGINT UNSIGNED DEFAULT NULL,
                    platform VARCHAR(20) DEFAULT 'web',
                    current_page VARCHAR(500) DEFAULT NULL,
                    current_listing_id VARCHAR(50) DEFAULT NULL,
                    city VARCHAR(100) DEFAULT NULL,
                    last_seen DATETIME NOT NULL,
                    PRIMARY KEY (session_id),
                    KEY idx_last_seen (last_seen),
                    KEY idx_platform (platform),
                    KEY idx_user_id (user_id)
                ) ENGINE=MEMORY $charset_collate;"
            ),

            // ===============================================
            // AGENT ACTIVITY TABLES (4 tables) - Added v6.43.1
            // ===============================================

            'mld_shared_properties' => array(
                'purpose' => 'Properties shared by agents with their clients',
                'category' => 'agent',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_shared_properties (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    agent_id BIGINT(20) UNSIGNED NOT NULL,
                    client_id BIGINT(20) UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    listing_key VARCHAR(128) NOT NULL,
                    agent_note TEXT,
                    shared_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    viewed_at DATETIME DEFAULT NULL,
                    view_count INT UNSIGNED DEFAULT 0,
                    client_response ENUM('none', 'interested', 'not_interested') DEFAULT 'none',
                    client_note TEXT,
                    is_dismissed TINYINT(1) DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_agent_client_listing (agent_id, client_id, listing_id),
                    KEY idx_agent_id (agent_id),
                    KEY idx_client_id (client_id),
                    KEY idx_listing_id (listing_id),
                    KEY idx_shared_at (shared_at),
                    KEY idx_client_response (client_response)
                ) $charset_collate;"
            ),

            'mld_agent_notification_preferences' => array(
                'purpose' => 'Per-agent notification preferences for client activity alerts',
                'category' => 'agent',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_notification_preferences (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    agent_id BIGINT UNSIGNED NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    email_enabled TINYINT(1) DEFAULT 1,
                    push_enabled TINYINT(1) DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_agent_type (agent_id, notification_type),
                    KEY idx_agent_id (agent_id)
                ) $charset_collate;"
            ),

            'mld_agent_notification_log' => array(
                'purpose' => 'Log of notifications sent to agents about client activity',
                'category' => 'agent',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_notification_log (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    agent_id BIGINT UNSIGNED NOT NULL,
                    client_id BIGINT UNSIGNED NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    channel VARCHAR(20) NOT NULL,
                    listing_id VARCHAR(50) DEFAULT NULL,
                    message_preview VARCHAR(255) DEFAULT NULL,
                    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_agent_id (agent_id),
                    KEY idx_client_id (client_id),
                    KEY idx_sent_at (sent_at),
                    KEY idx_type_channel (notification_type, channel)
                ) $charset_collate;"
            ),

            'mld_client_app_opens' => array(
                'purpose' => 'Tracks client app opens for 2-hour debounce on notifications',
                'category' => 'agent',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_client_app_opens (
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    last_notified_at DATETIME DEFAULT NULL,
                    last_opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) $charset_collate;"
            ),

            // ===============================================
            // DEFERRED NOTIFICATIONS & CLIENT PREFERENCES (v6.50.x)
            // ===============================================

            'mld_deferred_notifications' => array(
                'purpose' => 'Queue for notifications blocked by quiet hours (v6.50.7)',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_deferred_notifications (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    listing_id VARCHAR(50) DEFAULT NULL,
                    payload LONGTEXT NOT NULL,
                    deliver_after DATETIME NOT NULL,
                    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
                    error_message TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    processed_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_user_status (user_id, status),
                    KEY idx_deliver_after (deliver_after, status),
                    KEY idx_listing (listing_id)
                ) $charset_collate;"
            ),

            'mld_client_notification_preferences' => array(
                'purpose' => 'Per-user notification preferences with quiet hours (v6.48.2)',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_client_notification_preferences (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    new_listing_push TINYINT(1) DEFAULT 1,
                    new_listing_email TINYINT(1) DEFAULT 1,
                    price_change_push TINYINT(1) DEFAULT 1,
                    price_change_email TINYINT(1) DEFAULT 1,
                    status_change_push TINYINT(1) DEFAULT 1,
                    status_change_email TINYINT(1) DEFAULT 1,
                    open_house_push TINYINT(1) DEFAULT 1,
                    open_house_email TINYINT(1) DEFAULT 1,
                    saved_search_push TINYINT(1) DEFAULT 1,
                    saved_search_email TINYINT(1) DEFAULT 1,
                    quiet_hours_enabled TINYINT(1) DEFAULT 0,
                    quiet_hours_start TIME DEFAULT '22:00:00',
                    quiet_hours_end TIME DEFAULT '08:00:00',
                    user_timezone VARCHAR(50) DEFAULT 'America/New_York',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_user (user_id)
                ) $charset_collate;"
            ),

            // ===============================================
            // AGENT REFERRAL SYSTEM (v6.52.0)
            // ===============================================

            'mld_agent_referral_codes' => array(
                'purpose' => 'Unique referral codes for agents to share (v6.52.0)',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_agent_referral_codes (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    agent_user_id BIGINT(20) UNSIGNED NOT NULL,
                    referral_code VARCHAR(50) NOT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_code (referral_code),
                    KEY idx_agent (agent_user_id),
                    KEY idx_active (is_active)
                ) $charset_collate;"
            ),

            'mld_referral_signups' => array(
                'purpose' => 'Tracks how each client signed up - organic, referral, or agent-created (v6.52.0)',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_referral_signups (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    client_user_id BIGINT(20) UNSIGNED NOT NULL,
                    agent_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                    referral_code VARCHAR(50) DEFAULT NULL,
                    signup_source ENUM('organic', 'referral_link', 'agent_created') NOT NULL DEFAULT 'organic',
                    platform ENUM('web', 'ios', 'admin') DEFAULT 'web',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_client (client_user_id),
                    KEY idx_agent (agent_user_id),
                    KEY idx_code (referral_code),
                    KEY idx_source (signup_source),
                    KEY idx_created (created_at)
                ) $charset_collate;"
            ),

            // ===============================================
            // PUSH NOTIFICATION INFRASTRUCTURE (v6.48.x)
            // ===============================================

            'mld_device_tokens' => array(
                'purpose' => 'APNs device tokens for push notifications',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_device_tokens (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    device_token VARCHAR(255) NOT NULL,
                    platform ENUM('ios', 'android') DEFAULT 'ios',
                    app_version VARCHAR(20),
                    device_model VARCHAR(100),
                    is_active BOOLEAN DEFAULT TRUE,
                    is_sandbox BOOLEAN DEFAULT FALSE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_token (device_token),
                    KEY idx_user_id (user_id),
                    KEY idx_active (is_active)
                ) $charset_collate;"
            ),

            'mld_push_notification_log' => array(
                'purpose' => 'Push notification delivery log with read/dismissed status (v6.48.x+)',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_push_notification_log (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    device_token VARCHAR(255) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT,
                    payload LONGTEXT,
                    status ENUM('sent', 'failed', 'skipped') NOT NULL DEFAULT 'sent',
                    apns_status_code INT DEFAULT NULL,
                    apns_reason VARCHAR(100) DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    is_sandbox BOOLEAN DEFAULT FALSE,
                    source_plugin VARCHAR(50) DEFAULT 'mld',
                    is_read TINYINT(1) DEFAULT 0,
                    read_at DATETIME DEFAULT NULL,
                    is_dismissed TINYINT(1) DEFAULT 0,
                    dismissed_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_id (user_id),
                    KEY idx_status (status),
                    KEY idx_type (notification_type),
                    KEY idx_created (created_at),
                    KEY idx_source (source_plugin),
                    KEY idx_user_status (user_id, is_read, is_dismissed)
                ) $charset_collate;"
            ),

            'mld_push_retry_queue' => array(
                'purpose' => 'Queue for failed push notifications with exponential backoff',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_push_retry_queue (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    device_token VARCHAR(255) NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT,
                    payload LONGTEXT NOT NULL,
                    is_sandbox BOOLEAN DEFAULT FALSE,
                    source_plugin VARCHAR(50) DEFAULT 'mld',
                    retry_count INT UNSIGNED DEFAULT 0,
                    max_retries INT UNSIGNED DEFAULT 5,
                    last_error VARCHAR(255) DEFAULT NULL,
                    last_attempt_at DATETIME DEFAULT NULL,
                    next_retry_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('pending', 'processing', 'completed', 'failed', 'expired') DEFAULT 'pending',
                    PRIMARY KEY (id),
                    KEY idx_status_next (status, next_retry_at),
                    KEY idx_user_id (user_id),
                    KEY idx_source (source_plugin),
                    KEY idx_created (created_at)
                ) $charset_collate;"
            ),

            'mld_user_badge_counts' => array(
                'purpose' => 'Server-side badge count tracking per user',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_user_badge_counts (
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    unread_count INT UNSIGNED DEFAULT 0,
                    last_notification_at DATETIME DEFAULT NULL,
                    last_read_at DATETIME DEFAULT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) $charset_collate;"
            ),

            'mld_notification_engagement' => array(
                'purpose' => 'Notification engagement tracking (opens, dismissals, clicks)',
                'category' => 'notifications',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_notification_engagement (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    notification_type VARCHAR(50) NOT NULL,
                    action ENUM('delivered', 'opened', 'dismissed', 'clicked') NOT NULL,
                    listing_id VARCHAR(50) DEFAULT NULL,
                    saved_search_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    appointment_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    platform ENUM('ios', 'android', 'web') DEFAULT 'ios',
                    device_model VARCHAR(100) DEFAULT NULL,
                    app_version VARCHAR(20) DEFAULT NULL,
                    metadata JSON DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_date (user_id, created_at),
                    KEY idx_notification_type (notification_type),
                    KEY idx_action (action),
                    KEY idx_listing (listing_id),
                    KEY idx_search (saved_search_id)
                ) $charset_collate;"
            ),

            'mld_user_types' => array(
                'purpose' => 'User type classification (client, agent, admin)',
                'category' => 'agents',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_user_types (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    user_type ENUM('client', 'agent', 'admin') NOT NULL DEFAULT 'client',
                    promoted_by BIGINT(20) UNSIGNED DEFAULT NULL,
                    promoted_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_user_id (user_id),
                    KEY idx_user_type (user_type),
                    KEY idx_promoted_by (promoted_by)
                ) $charset_collate;"
            ),

            // ===============================================
            // RECENTLY VIEWED PROPERTIES (v6.57.0)
            // ===============================================

            'mld_recently_viewed_properties' => array(
                'purpose' => 'Tracks property view history for users (stores 7 days)',
                'category' => 'user_activity',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_recently_viewed_properties (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    listing_key VARCHAR(128) NOT NULL,
                    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    view_source ENUM('search', 'saved_search', 'shared', 'notification', 'direct', 'favorites') DEFAULT 'search',
                    platform ENUM('ios', 'web', 'admin') DEFAULT 'ios',
                    ip_address VARCHAR(45) NULL COMMENT 'IP address for anonymous visitors (user_id=0)',
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_user_listing (user_id, listing_id),
                    KEY idx_user_id (user_id),
                    KEY idx_listing_id (listing_id),
                    KEY idx_viewed_at (viewed_at),
                    KEY idx_user_viewed (user_id, viewed_at),
                    KEY idx_listing_viewed (listing_id, viewed_at)
                ) $charset_collate;"
            ),

            // ===============================================
            // HEALTH MONITORING SYSTEM (v6.58.0)
            // ===============================================

            'mld_health_history' => array(
                'purpose' => 'Stores health check history for trending and diagnostics',
                'category' => 'health',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_health_history (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    check_time DATETIME NOT NULL,
                    overall_status ENUM('healthy', 'degraded', 'unhealthy') NOT NULL,
                    mld_status VARCHAR(20) DEFAULT NULL,
                    schools_status VARCHAR(20) DEFAULT NULL,
                    snab_status VARCHAR(20) DEFAULT NULL,
                    response_time_ms INT UNSIGNED DEFAULT NULL,
                    check_source ENUM('cron', 'cli', 'external', 'admin') DEFAULT 'admin',
                    issues_count INT UNSIGNED DEFAULT 0,
                    details LONGTEXT DEFAULT NULL COMMENT 'JSON-encoded full check results',
                    KEY idx_check_time (check_time),
                    KEY idx_status (overall_status),
                    KEY idx_source (check_source)
                ) $charset_collate;"
            ),

            'mld_health_alerts' => array(
                'purpose' => 'Logs health alert emails sent to prevent spam',
                'category' => 'health',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_health_alerts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    alert_time DATETIME NOT NULL,
                    severity ENUM('warning', 'critical') NOT NULL,
                    component VARCHAR(50) DEFAULT NULL,
                    message TEXT DEFAULT NULL,
                    resolved_at DATETIME DEFAULT NULL,
                    KEY idx_alert_time (alert_time),
                    KEY idx_severity (severity),
                    KEY idx_component (component)
                ) $charset_collate;"
            ),

            // ===============================================
            // OPEN HOUSE SYSTEM TABLES (3 tables) - Added v6.75.8
            // ===============================================

            'mld_open_houses' => array(
                'purpose' => 'Stores open house events created by agents',
                'category' => 'open_house',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_open_houses (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    agent_user_id bigint unsigned NOT NULL,
                    listing_id varchar(50) DEFAULT NULL,
                    property_address varchar(255) DEFAULT NULL,
                    property_city varchar(100) DEFAULT NULL,
                    property_state varchar(2) DEFAULT 'MA',
                    property_zip varchar(10) DEFAULT NULL,
                    property_type varchar(50) DEFAULT NULL,
                    beds int DEFAULT NULL,
                    baths decimal(3,1) DEFAULT NULL,
                    list_price decimal(20,2) DEFAULT NULL,
                    photo_url varchar(500) DEFAULT NULL,
                    latitude decimal(10,8) DEFAULT NULL,
                    longitude decimal(11,8) DEFAULT NULL,
                    event_date date NOT NULL,
                    start_time time NOT NULL,
                    end_time time NOT NULL,
                    status enum('scheduled','active','completed','cancelled') DEFAULT 'scheduled',
                    notes text DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_agent (agent_user_id),
                    KEY idx_date (event_date),
                    KEY idx_status (status),
                    KEY idx_listing (listing_id)
                ) $charset_collate;"
            ),

            'mld_open_house_attendees' => array(
                'purpose' => 'Tracks attendees who sign in at open house events',
                'category' => 'open_house',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_open_house_attendees (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    open_house_id bigint unsigned NOT NULL,
                    local_uuid varchar(36) DEFAULT NULL,
                    first_name varchar(100) NOT NULL,
                    last_name varchar(100) NOT NULL,
                    email varchar(255) NOT NULL,
                    phone varchar(20) NOT NULL,
                    is_agent tinyint(1) DEFAULT 0,
                    agent_brokerage varchar(255) DEFAULT NULL,
                    agent_visit_purpose varchar(50) DEFAULT NULL,
                    agent_has_buyer tinyint(1) DEFAULT NULL,
                    agent_buyer_timeline varchar(50) DEFAULT NULL,
                    agent_network_interest tinyint(1) DEFAULT NULL,
                    working_with_agent varchar(20) DEFAULT 'no',
                    other_agent_name varchar(255) DEFAULT NULL,
                    other_agent_brokerage varchar(255) DEFAULT NULL,
                    other_agent_phone varchar(20) DEFAULT NULL,
                    other_agent_email varchar(255) DEFAULT NULL,
                    buying_timeline varchar(50) DEFAULT 'just_browsing',
                    pre_approved varchar(20) DEFAULT 'not_sure',
                    lender_name varchar(255) DEFAULT NULL,
                    how_heard_about varchar(100) DEFAULT NULL,
                    consent_to_follow_up tinyint(1) DEFAULT 0,
                    consent_to_email tinyint(1) DEFAULT 0,
                    consent_to_text tinyint(1) DEFAULT 0,
                    ma_disclosure_acknowledged tinyint(1) DEFAULT 0,
                    ma_disclosure_timestamp datetime DEFAULT NULL,
                    interest_level varchar(50) DEFAULT 'unknown',
                    agent_notes text DEFAULT NULL,
                    user_id bigint unsigned DEFAULT NULL,
                    priority_score int DEFAULT 0,
                    auto_crm_processed tinyint(1) DEFAULT 0,
                    auto_search_created tinyint(1) DEFAULT 0,
                    auto_search_id int DEFAULT NULL,
                    signed_in_at datetime DEFAULT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_local_uuid (local_uuid),
                    KEY idx_open_house (open_house_id),
                    KEY idx_email (email),
                    KEY idx_signed_in (signed_in_at),
                    KEY idx_user_id (user_id),
                    KEY idx_is_agent (is_agent),
                    KEY idx_priority (priority_score)
                ) $charset_collate;"
            ),

            'mld_open_house_notifications' => array(
                'purpose' => 'Tracks notifications sent for open house events (new/reminder)',
                'category' => 'open_house',
                'sql' => "CREATE TABLE {$wpdb->prefix}mld_open_house_notifications (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    open_house_id bigint unsigned NOT NULL,
                    user_id bigint unsigned NOT NULL,
                    notification_type enum('new','reminder') NOT NULL,
                    sent_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_oh_user_type (open_house_id, user_id, notification_type),
                    KEY idx_user (user_id),
                    KEY idx_sent (sent_at)
                ) $charset_collate;"
            )
        );
    }

    /**
     * Verify all database tables exist
     *
     * @return array Results of verification
     */
    public function verify_tables() {
        global $wpdb;
        $results = array();
        $tables = $this->get_required_tables();

        foreach ($tables as $table_suffix => $table_info) {
            $table_name = $wpdb->prefix . $table_suffix;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            $results[$table_suffix] = array(
                'exists' => !empty($exists),
                'table_name' => $table_name,
                'purpose' => $table_info['purpose'],
                'category' => $table_info['category']
            );
        }

        return $results;
    }

    /**
     * Repair missing tables
     *
     * @param array $missing_tables Optional array of specific tables to repair
     * @return array Results of repair operations
     */
    public function repair_tables($missing_tables = null) {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $results = array();
        $tables = $this->get_required_tables();

        // If specific tables provided, only repair those
        if (is_array($missing_tables)) {
            $tables = array_intersect_key($tables, array_flip($missing_tables));
        }

        foreach ($tables as $table_suffix => $table_info) {
            $table_name = $wpdb->prefix . $table_suffix;

            // Check if table exists first
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            if (empty($exists)) {
                // Create the table
                dbDelta($table_info['sql']);

                // Verify it was created
                $created = $wpdb->get_var($wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table_name
                ));

                $results[$table_suffix] = array(
                    'status' => !empty($created) ? 'created' : 'failed',
                    'table_name' => $table_name
                );
            } else {
                $results[$table_suffix] = array(
                    'status' => 'already_exists',
                    'table_name' => $table_name
                );
            }
        }

        return $results;
    }

    /**
     * Check database version
     *
     * @return array Version information
     */
    public function check_version() {
        $plugin_version = defined('MLD_VERSION') ? MLD_VERSION : 'Unknown';
        $db_version = get_option('mld_db_version', 'Not set');

        return array(
            'plugin_version' => $plugin_version,
            'database_version' => $db_version,
            'versions_match' => ($plugin_version === $db_version)
        );
    }

    /**
     * Run comprehensive database health check
     *
     * @return array Health check results
     */
    public function health_check() {
        global $wpdb;

        $results = array(
            'tables' => $this->verify_tables(),
            'version' => $this->check_version(),
            'issues' => array(),
            'recommendations' => array()
        );

        // Check for missing tables
        foreach ($results['tables'] as $table => $info) {
            if (!$info['exists']) {
                $results['issues'][] = "Table {$info['table_name']} is missing";
                $results['recommendations'][] = "Run repair to create {$table} table";
            }
        }

        // Check version mismatch
        if (!$results['version']['versions_match']) {
            $results['issues'][] = "Database version mismatch";
            $results['recommendations'][] = "Update database version to match plugin version";
        }

        // Check for orphaned records (only if tables exist)
        if (isset($results['tables']['mld_saved_searches']) && $results['tables']['mld_saved_searches']['exists']) {
            $orphaned_searches = $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$wpdb->prefix}mld_saved_searches s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE u.ID IS NULL
            ");

            if ($orphaned_searches > 0) {
                $results['issues'][] = "{$orphaned_searches} orphaned saved searches found";
                $results['recommendations'][] = "Clean up orphaned saved search records";
            }
        }

        $results['health_score'] = empty($results['issues']) ? 100 : max(0, 100 - (count($results['issues']) * 10));

        return $results;
    }

    /**
     * Clean orphaned records
     *
     * @return array Cleanup results
     */
    public function cleanup_orphaned_records() {
        global $wpdb;
        $results = array();

        // Clean orphaned saved searches
        $deleted_searches = $wpdb->query("
            DELETE s FROM {$wpdb->prefix}mld_saved_searches s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            WHERE u.ID IS NULL
        ");

        $results['saved_searches'] = $deleted_searches;

        // Clean expired cache
        $deleted_cache = $wpdb->query("
            DELETE FROM {$wpdb->prefix}mld_search_cache
            WHERE expiration < NOW()
        ");

        $results['expired_cache'] = $deleted_cache;

        // Clean old CMA cache
        $deleted_cma_cache = $wpdb->query("
            DELETE FROM {$wpdb->prefix}mld_cma_comparables_cache
            WHERE expires_at < NOW()
        ");

        $results['expired_cma_cache'] = $deleted_cma_cache;

        return $results;
    }

    /**
     * Get table count by category
     *
     * @return array Category counts
     */
    public function get_category_counts() {
        $tables = $this->get_required_tables();
        $categories = array();

        foreach ($tables as $table_suffix => $table_info) {
            $category = $table_info['category'];
            if (!isset($categories[$category])) {
                $categories[$category] = 0;
            }
            $categories[$category]++;
        }

        return $categories;
    }

    /**
     * Parse column definitions from CREATE TABLE SQL
     *
     * @param string $sql The CREATE TABLE SQL statement
     * @return array Array of column definitions with name, type, and full definition
     */
    private function parse_columns_from_sql($sql) {
        $columns = array();

        // Extract content between first ( and last )
        if (!preg_match('/CREATE TABLE[^(]+\((.+)\)[^)]*$/s', $sql, $matches)) {
            return $columns;
        }

        $content = $matches[1];

        // Split by comma, but be careful of commas inside parentheses (like DECIMAL(10,2))
        $parts = array();
        $current = '';
        $paren_depth = 0;

        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            if ($char === '(') {
                $paren_depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $paren_depth--;
                $current .= $char;
            } elseif ($char === ',' && $paren_depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if (trim($current)) {
            $parts[] = trim($current);
        }

        // Parse each part
        foreach ($parts as $part) {
            $part = trim($part);

            // Skip constraints (PRIMARY KEY, KEY, INDEX, UNIQUE, FULLTEXT, FOREIGN KEY, CONSTRAINT)
            if (preg_match('/^(PRIMARY\s+KEY|KEY|INDEX|UNIQUE|FULLTEXT|FOREIGN\s+KEY|CONSTRAINT)\s/i', $part)) {
                continue;
            }

            // Extract column name and type
            // Column name is the first word (possibly backtick-quoted)
            if (preg_match('/^[`]?(\w+)[`]?\s+(\w+)/i', $part, $col_match)) {
                $col_name = strtolower($col_match[1]);
                $col_type = strtoupper($col_match[2]);

                $columns[$col_name] = array(
                    'name' => $col_name,
                    'type' => $col_type,
                    'definition' => $part
                );
            }
        }

        return $columns;
    }

    /**
     * Get actual columns from a database table
     *
     * @param string $table_name Full table name
     * @return array Array of column info from database
     */
    private function get_actual_columns($table_name) {
        global $wpdb;

        $columns = array();
        $results = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}", ARRAY_A);

        if ($results) {
            foreach ($results as $row) {
                $col_name = strtolower($row['Field']);
                $columns[$col_name] = array(
                    'name' => $col_name,
                    'type' => strtoupper($row['Type']),
                    'null' => $row['Null'],
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra']
                );
            }
        }

        return $columns;
    }

    /**
     * Verify all columns exist in database tables
     *
     * @param array $tables_to_check Optional array of specific table suffixes to check
     * @return array Results of column verification
     */
    public function verify_columns($tables_to_check = null) {
        global $wpdb;

        $results = array();
        $tables = $this->get_required_tables();

        // Filter to specific tables if requested
        if (is_array($tables_to_check)) {
            $tables = array_intersect_key($tables, array_flip($tables_to_check));
        }

        foreach ($tables as $table_suffix => $table_info) {
            $table_name = $wpdb->prefix . $table_suffix;

            // First check if table exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table_name
            ));

            if (empty($exists)) {
                $results[$table_suffix] = array(
                    'status' => 'table_missing',
                    'table_name' => $table_name,
                    'expected_columns' => array(),
                    'actual_columns' => array(),
                    'missing_columns' => array(),
                    'extra_columns' => array()
                );
                continue;
            }

            // Parse expected columns from SQL
            $expected_columns = $this->parse_columns_from_sql($table_info['sql']);

            // Get actual columns from database
            $actual_columns = $this->get_actual_columns($table_name);

            // Find missing columns (in expected but not in actual)
            $missing = array_diff_key($expected_columns, $actual_columns);

            // Find extra columns (in actual but not in expected) - informational only
            $extra = array_diff_key($actual_columns, $expected_columns);

            $results[$table_suffix] = array(
                'status' => empty($missing) ? 'ok' : 'missing_columns',
                'table_name' => $table_name,
                'expected_count' => count($expected_columns),
                'actual_count' => count($actual_columns),
                'missing_columns' => array_keys($missing),
                'missing_definitions' => $missing,
                'extra_columns' => array_keys($extra)
            );
        }

        return $results;
    }

    /**
     * Repair missing columns in database tables
     *
     * @param array $tables_to_repair Optional array of specific table suffixes to repair
     * @return array Results of repair operations
     */
    public function repair_columns($tables_to_repair = null) {
        global $wpdb;

        $results = array();

        // First verify to find missing columns
        $verification = $this->verify_columns($tables_to_repair);

        foreach ($verification as $table_suffix => $info) {
            if ($info['status'] === 'table_missing') {
                // Table is missing - use repair_tables() instead
                $results[$table_suffix] = array(
                    'status' => 'skipped',
                    'reason' => 'Table does not exist - use repair_tables() first',
                    'columns_added' => array()
                );
                continue;
            }

            if ($info['status'] === 'ok') {
                $results[$table_suffix] = array(
                    'status' => 'ok',
                    'reason' => 'All columns already exist',
                    'columns_added' => array()
                );
                continue;
            }

            // Table exists but has missing columns
            $columns_added = array();
            $errors = array();

            foreach ($info['missing_definitions'] as $col_name => $col_info) {
                // Build ALTER TABLE statement
                $definition = $col_info['definition'];
                $table_name = $info['table_name'];

                // Try to add the column
                $sql = "ALTER TABLE {$table_name} ADD COLUMN {$definition}";

                $result = $wpdb->query($sql);

                if ($result !== false) {
                    $columns_added[] = $col_name;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD DB Verify] Added column {$col_name} to {$table_name}");
                    }
                } else {
                    $errors[$col_name] = $wpdb->last_error;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD DB Verify] Failed to add column {$col_name} to {$table_name}: " . $wpdb->last_error);
                    }
                }
            }

            $results[$table_suffix] = array(
                'status' => empty($errors) ? 'repaired' : 'partial',
                'columns_added' => $columns_added,
                'errors' => $errors
            );
        }

        return $results;
    }

    /**
     * Get summary of column verification issues
     *
     * @return array Summary with counts and details
     */
    public function get_column_issues_summary() {
        $verification = $this->verify_columns();

        $summary = array(
            'tables_checked' => count($verification),
            'tables_ok' => 0,
            'tables_missing' => 0,
            'tables_with_missing_columns' => 0,
            'total_missing_columns' => 0,
            'issues' => array()
        );

        foreach ($verification as $table_suffix => $info) {
            if ($info['status'] === 'ok') {
                $summary['tables_ok']++;
            } elseif ($info['status'] === 'table_missing') {
                $summary['tables_missing']++;
                $summary['issues'][] = array(
                    'table' => $table_suffix,
                    'type' => 'table_missing',
                    'message' => "Table {$info['table_name']} does not exist"
                );
            } elseif ($info['status'] === 'missing_columns') {
                $summary['tables_with_missing_columns']++;
                $summary['total_missing_columns'] += count($info['missing_columns']);
                $summary['issues'][] = array(
                    'table' => $table_suffix,
                    'type' => 'missing_columns',
                    'message' => "Table {$info['table_name']} is missing columns: " . implode(', ', $info['missing_columns']),
                    'missing' => $info['missing_columns']
                );
            }
        }

        return $summary;
    }

    /**
     * Run comprehensive database health check (enhanced with column verification)
     *
     * @return array Health check results
     */
    public function health_check_full() {
        global $wpdb;

        $results = array(
            'tables' => $this->verify_tables(),
            'columns' => $this->get_column_issues_summary(),
            'version' => $this->check_version(),
            'issues' => array(),
            'recommendations' => array()
        );

        // Check for missing tables
        foreach ($results['tables'] as $table => $info) {
            if (!$info['exists']) {
                $results['issues'][] = "Table {$info['table_name']} is missing";
                $results['recommendations'][] = "Run 'Fix All Issues' to create {$table} table";
            }
        }

        // Check for missing columns
        if ($results['columns']['total_missing_columns'] > 0) {
            foreach ($results['columns']['issues'] as $issue) {
                if ($issue['type'] === 'missing_columns') {
                    $results['issues'][] = $issue['message'];
                    $results['recommendations'][] = "Run 'Fix All Issues' to add missing columns to {$issue['table']}";
                }
            }
        }

        // Check version mismatch
        if (!$results['version']['versions_match']) {
            $results['issues'][] = "Database version ({$results['version']['database_version']}) doesn't match plugin version ({$results['version']['plugin_version']})";
            $results['recommendations'][] = "Deactivate and reactivate the plugin to run upgrades";
        }

        // Check for orphaned records (only if tables exist)
        if (isset($results['tables']['mld_saved_searches']) && $results['tables']['mld_saved_searches']['exists']) {
            $orphaned_searches = $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$wpdb->prefix}mld_saved_searches s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE u.ID IS NULL
            ");

            if ($orphaned_searches > 0) {
                $results['issues'][] = "{$orphaned_searches} orphaned saved searches found";
                $results['recommendations'][] = "Clean up orphaned saved search records";
            }
        }

        // Calculate health score
        $issue_count = count($results['issues']);
        $results['health_score'] = $issue_count === 0 ? 100 : max(0, 100 - ($issue_count * 10));

        // Status summary
        $results['status'] = $issue_count === 0 ? 'healthy' : ($issue_count <= 3 ? 'warning' : 'critical');

        return $results;
    }

    /**
     * Repair all issues (tables and columns)
     *
     * @return array Results of all repair operations
     */
    public function repair_all() {
        $results = array(
            'tables' => array(),
            'columns' => array(),
            'summary' => array(
                'tables_created' => 0,
                'columns_added' => 0,
                'errors' => 0
            )
        );

        // First repair missing tables
        $table_results = $this->repair_tables();
        $results['tables'] = $table_results;

        foreach ($table_results as $table => $info) {
            if ($info['status'] === 'created') {
                $results['summary']['tables_created']++;
            } elseif ($info['status'] === 'failed') {
                $results['summary']['errors']++;
            }
        }

        // Then repair missing columns
        $column_results = $this->repair_columns();
        $results['columns'] = $column_results;

        foreach ($column_results as $table => $info) {
            if (isset($info['columns_added'])) {
                $results['summary']['columns_added'] += count($info['columns_added']);
            }
            if (isset($info['errors']) && !empty($info['errors'])) {
                $results['summary']['errors'] += count($info['errors']);
            }
        }

        // Update version if repairs were made
        if ($results['summary']['tables_created'] > 0 || $results['summary']['columns_added'] > 0) {
            update_option('mld_db_version', defined('MLD_VERSION') ? MLD_VERSION : '6.20.12');
        }

        return $results;
    }
}
