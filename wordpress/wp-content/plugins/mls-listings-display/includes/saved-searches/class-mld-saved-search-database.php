<?php
/**
 * MLS Listings Display - Saved Search Database Handler
 * 
 * Handles database table creation and updates for the saved search feature
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Database {
    
    /**
     * Database version for schema updates
     */
    const DB_VERSION = '1.13.1';
    
    /**
     * Option name for storing database version
     */
    const VERSION_OPTION = 'mld_saved_search_db_version';
    
    /**
     * Check if database upgrade is needed and run it
     */
    public static function check_and_upgrade() {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::create_tables();
        }
    }

    /**
     * Create or update all saved search related tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create saved searches table
        self::create_saved_searches_table($charset_collate);
        
        // Create saved search results table
        self::create_saved_search_results_table($charset_collate);
        
        // Create email settings table
        self::create_email_settings_table($charset_collate);
        
        // Create property preferences table
        self::create_property_preferences_table($charset_collate);
        
        // Create cron log table
        self::create_cron_log_table($charset_collate);
        
        // Create agent-client relationships table
        self::create_agent_client_relationships_table($charset_collate);
        
        // Create agent profiles table
        self::create_agent_profiles_table($charset_collate);
        
        // Create admin client preferences table
        self::create_admin_client_preferences_table($charset_collate);

        // Create device tokens table for push notifications
        self::create_device_tokens_table($charset_collate);

        // Create user types table for agent/client/admin designation
        self::create_user_types_table($charset_collate);

        // Create saved search activity log table
        self::create_saved_search_activity_table($charset_collate);

        // Create user email preferences table
        self::create_user_email_preferences_table($charset_collate);

        // Create email analytics table
        self::create_email_analytics_table($charset_collate);

        // Create client analytics tables (v1.4.0)
        self::create_client_activity_table($charset_collate);
        self::create_client_sessions_table($charset_collate);
        self::create_client_analytics_summary_table($charset_collate);

        // Create engagement scoring tables (v1.5.0)
        self::create_client_engagement_scores_table($charset_collate);
        self::create_client_property_interest_table($charset_collate);

        // Create agent notification tables (v1.6.0)
        self::create_agent_notification_preferences_table($charset_collate);
        self::create_agent_notification_log_table($charset_collate);
        self::create_client_app_opens_table($charset_collate);

        // Create push notification delivery log table (v1.7.0)
        self::create_push_notification_log_table($charset_collate);

        // Create push notification retry queue table (v1.8.0)
        self::create_push_notification_retry_queue_table($charset_collate);

        // Create user badge count table for unified badge management (v1.9.0)
        self::create_user_badge_counts_table($charset_collate);

        // Create notification engagement tracking table (v1.10.0)
        self::create_notification_engagement_table($charset_collate);

        // Create agent referral tables (v1.12.0)
        self::create_agent_referral_codes_table($charset_collate);
        self::create_referral_signups_table($charset_collate);

        // Create revoked refresh tokens table (v1.13.0)
        self::create_revoked_tokens_table($charset_collate);

        // Add new columns to existing tables (v1.3.0)
        self::upgrade_agent_profiles_table();
        self::upgrade_saved_searches_table();

        // Add read/dismissed status columns to push notification log (v1.11.0)
        self::upgrade_push_notification_log_table();

        // Update database version
        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }
    
    /**
     * Create saved searches table
     */
    private static function create_saved_searches_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_saved_searches';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_by_admin BIGINT(20) UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            filters LONGTEXT NOT NULL,
            polygon_shapes LONGTEXT,
            search_url TEXT,
            notification_frequency ENUM('instant', 'fifteen_min', 'hourly', 'daily', 'weekly') DEFAULT 'fifteen_min',
            digest_enabled TINYINT(1) DEFAULT 1,
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
            KEY idx_last_notified (last_notified_at),
            KEY idx_freq_active_notified (notification_frequency, is_active, last_notified_at)
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create saved search results table
     */
    private static function create_saved_search_results_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_saved_search_results';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_search_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY unique_search_listing (saved_search_id, listing_id),
            KEY idx_notified (notified_at)
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create email settings table
     */
    private static function create_email_settings_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_saved_search_email_settings';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_search_id BIGINT(20) UNSIGNED NOT NULL,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            email_type ENUM('cc', 'bcc', 'none') DEFAULT 'none',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_search_admin (saved_search_id, admin_id),
            KEY idx_admin_id (admin_id)
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create property preferences table
     */
    private static function create_property_preferences_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_property_preferences';
        
        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create cron log table
     */
    private static function create_cron_log_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_saved_search_cron_log';
        
        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create agent-client relationships table
     */
    private static function create_agent_client_relationships_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_agent_client_relationships';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            relationship_status ENUM('active', 'inactive', 'pending') DEFAULT 'active',
            assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            PRIMARY KEY (id),
            UNIQUE KEY unique_agent_client (agent_id, client_id),
            KEY idx_client_id (client_id),
            KEY idx_agent_id (agent_id),
            KEY idx_status (relationship_status),
            KEY idx_agent_status (agent_id, relationship_status)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create agent profiles table
     */
    private static function create_agent_profiles_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_agent_profiles';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL UNIQUE,
            display_name VARCHAR(255),
            phone VARCHAR(20),
            email VARCHAR(255),
            office_name VARCHAR(255),
            office_address TEXT,
            bio TEXT,
            photo_url VARCHAR(500),
            license_number VARCHAR(100),
            specialties TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_active (is_active)
        ) $charset_collate";
        
        dbDelta($sql);
    }
    
    /**
     * Create admin client preferences table
     */
    private static function create_admin_client_preferences_table($charset_collate) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mld_admin_client_preferences';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            default_cc_all BOOLEAN DEFAULT FALSE,
            default_email_type ENUM('cc', 'bcc', 'none') DEFAULT 'none',
            can_view_searches BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_admin_client (admin_id, client_id),
            KEY idx_client_id (client_id)
        ) $charset_collate";
        
        dbDelta($sql);
    }

    /**
     * Create device tokens table for push notifications
     */
    private static function create_device_tokens_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            device_token VARCHAR(255) NOT NULL,
            platform ENUM('ios', 'android') DEFAULT 'ios',
            app_version VARCHAR(20),
            device_model VARCHAR(100),
            is_active BOOLEAN DEFAULT TRUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_token (device_token),
            KEY idx_user_id (user_id),
            KEY idx_active (is_active),
            KEY idx_user_active (user_id, is_active)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create user types table for agent/client/admin designation
     *
     * @since 1.3.0
     */
    private static function create_user_types_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_types';

        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create saved search activity log table for audit trail
     *
     * @since 1.3.0
     */
    private static function create_saved_search_activity_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_saved_search_activity';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_search_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            action_type ENUM('created', 'updated', 'paused', 'resumed', 'deleted', 'frequency_changed', 'note_added', 'shared') NOT NULL,
            action_details JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_saved_search (saved_search_id),
            KEY idx_user (user_id),
            KEY idx_action_type (action_type),
            KEY idx_created_at (created_at)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create user email preferences table
     *
     * @since 1.3.0
     */
    private static function create_user_email_preferences_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_email_preferences';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            digest_enabled TINYINT(1) DEFAULT 0,
            digest_frequency ENUM('daily', 'weekly') DEFAULT 'daily',
            digest_time TIME DEFAULT '08:00:00',
            preferred_format ENUM('html', 'plain') DEFAULT 'html',
            global_pause TINYINT(1) DEFAULT 0,
            timezone VARCHAR(50) DEFAULT 'America/New_York',
            unsubscribed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_id (user_id),
            KEY idx_digest (digest_enabled, digest_frequency)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create email analytics table for tracking opens and clicks
     *
     * @since 1.3.0
     */
    private static function create_email_analytics_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_email_analytics';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email_id VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            search_id BIGINT(20) UNSIGNED DEFAULT NULL,
            email_type ENUM('alert', 'daily_digest', 'weekly_digest', 'welcome', 'agent_intro') NOT NULL,
            sent_at DATETIME NOT NULL,
            opened_at DATETIME DEFAULT NULL,
            open_count INT DEFAULT 0,
            click_count INT DEFAULT 0,
            listings_included TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email_id (email_id),
            KEY idx_user_date (user_id, sent_at),
            KEY idx_search (search_id)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client activity table for raw event tracking
     *
     * @since 1.4.0
     */
    private static function create_client_activity_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_activity';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            activity_type ENUM('property_view', 'property_share', 'search_run', 'filter_used',
                               'favorite_add', 'favorite_remove', 'hidden_add', 'hidden_remove',
                               'search_save', 'login', 'page_view') NOT NULL,
            entity_id VARCHAR(128),
            entity_type VARCHAR(50),
            metadata JSON,
            platform ENUM('ios', 'web', 'unknown') DEFAULT 'unknown',
            device_info VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_date (user_id, created_at),
            KEY idx_session (session_id),
            KEY idx_activity_type (activity_type)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client sessions table for session tracking
     *
     * @since 1.4.0
     */
    private static function create_client_sessions_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_sessions';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME,
            duration_seconds INT UNSIGNED DEFAULT 0,
            platform ENUM('ios', 'web', 'unknown') DEFAULT 'unknown',
            device_type VARCHAR(50),
            app_version VARCHAR(20),
            activity_count INT UNSIGNED DEFAULT 0,
            properties_viewed INT UNSIGNED DEFAULT 0,
            searches_run INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY unique_session (session_id),
            KEY idx_user_dates (user_id, started_at)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client analytics summary table for daily aggregates
     *
     * @since 1.4.0
     */
    private static function create_client_analytics_summary_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_analytics_summary';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            summary_date DATE NOT NULL,
            total_sessions INT UNSIGNED DEFAULT 0,
            total_duration_seconds INT UNSIGNED DEFAULT 0,
            properties_viewed INT UNSIGNED DEFAULT 0,
            unique_properties_viewed INT UNSIGNED DEFAULT 0,
            searches_run INT UNSIGNED DEFAULT 0,
            favorites_added INT UNSIGNED DEFAULT 0,
            engagement_score DECIMAL(5,2) DEFAULT 0.00,
            most_viewed_cities JSON,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_date (user_id, summary_date),
            KEY idx_user_id (user_id),
            KEY idx_date (summary_date)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client engagement scores table for storing calculated scores
     *
     * @since 1.5.0
     */
    private static function create_client_engagement_scores_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_engagement_scores';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            agent_id BIGINT(20) UNSIGNED DEFAULT NULL,
            score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            score_trend ENUM('rising', 'falling', 'stable') DEFAULT 'stable',
            trend_change DECIMAL(5,2) DEFAULT 0.00,
            last_activity_at DATETIME,
            days_since_activity INT UNSIGNED DEFAULT 0,
            time_score DECIMAL(4,2) DEFAULT 0.00,
            view_score DECIMAL(4,2) DEFAULT 0.00,
            search_score DECIMAL(4,2) DEFAULT 0.00,
            engagement_score DECIMAL(4,2) DEFAULT 0.00,
            frequency_score DECIMAL(4,2) DEFAULT 0.00,
            calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user (user_id),
            KEY idx_agent_score (agent_id, score),
            KEY idx_score (score),
            KEY idx_last_activity (last_activity_at)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client property interest table for tracking property-level engagement
     *
     * @since 1.5.0
     */
    private static function create_client_property_interest_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_property_interest';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128) NOT NULL,
            view_count INT UNSIGNED DEFAULT 0,
            total_view_duration INT UNSIGNED DEFAULT 0,
            photo_views INT UNSIGNED DEFAULT 0,
            calculator_used TINYINT(1) DEFAULT 0,
            contact_clicked TINYINT(1) DEFAULT 0,
            shared TINYINT(1) DEFAULT 0,
            favorited TINYINT(1) DEFAULT 0,
            interest_score DECIMAL(5,2) DEFAULT 0.00,
            first_viewed_at DATETIME,
            last_viewed_at DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_listing (user_id, listing_id),
            KEY idx_user_interest (user_id, interest_score),
            KEY idx_listing (listing_id)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create agent notification preferences table
     * Stores per-agent, per-type notification settings
     *
     * @since 1.6.0
     */
    private static function create_agent_notification_preferences_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_agent_notification_preferences';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT(20) UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            email_enabled TINYINT(1) DEFAULT 1,
            push_enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_agent_type (agent_id, notification_type),
            KEY idx_agent_id (agent_id)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create agent notification log table
     * Logs all agent activity notifications sent
     *
     * @since 1.6.0
     */
    private static function create_agent_notification_log_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_agent_notification_log';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            context_data LONGTEXT,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_agent_client (agent_id, client_id),
            KEY idx_type (notification_type),
            KEY idx_created (created_at)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create client app opens table
     * Tracks last notification time for app open debouncing
     *
     * @since 1.6.0
     */
    private static function create_client_app_opens_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_client_app_opens';

        $sql = "CREATE TABLE $table_name (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            last_notified_at DATETIME DEFAULT NULL,
            last_opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create push notification delivery log table
     * Tracks all push notification delivery attempts for analytics and debugging
     *
     * @since 1.7.0
     */
    private static function create_push_notification_log_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        $sql = "CREATE TABLE $table_name (
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_type (notification_type),
            KEY idx_created (created_at),
            KEY idx_source (source_plugin)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create push notification retry queue table
     * Stores failed notifications for retry with exponential backoff
     *
     * @since 1.8.0
     */
    private static function create_push_notification_retry_queue_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';

        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create user badge counts table
     * Tracks unread notification counts per user for unified badge management
     *
     * @since 1.9.0
     */
    private static function create_user_badge_counts_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        $sql = "CREATE TABLE $table_name (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            unread_count INT UNSIGNED DEFAULT 0,
            last_notification_at DATETIME DEFAULT NULL,
            last_read_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create notification engagement tracking table
     * Tracks notification opens, dismissals, and engagement metrics
     *
     * @since 1.10.0
     */
    private static function create_notification_engagement_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_notification_engagement';

        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create agent referral codes table
     * Stores unique referral codes for each agent
     *
     * @since 1.12.0
     */
    private static function create_agent_referral_codes_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_agent_referral_codes';

        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create referral signups table
     * Tracks how each client signed up (organic, referral, agent-created)
     *
     * @since 1.12.0
     */
    private static function create_referral_signups_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_referral_signups';

        $sql = "CREATE TABLE $table_name (
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
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Create revoked refresh tokens table
     * Prevents refresh token reuse by storing hashes of revoked tokens
     *
     * @since 1.13.0
     */
    private static function create_revoked_tokens_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_revoked_tokens';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash VARCHAR(64) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            revoked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_token (token_hash),
            KEY idx_user_id (user_id),
            KEY idx_expires (expires_at)
        ) $charset_collate";

        dbDelta($sql);
    }

    /**
     * Clean up expired revoked tokens (can be called via cron)
     *
     * @since 1.13.0
     */
    public static function cleanup_expired_tokens() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_revoked_tokens';

        // Delete tokens that have expired (no need to track them anymore)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE expires_at < %s",
                current_time('mysql')
            )
        );
    }

    /**
     * Upgrade agent_profiles table with new columns for v1.3.0
     *
     * @since 1.3.0
     */
    private static function upgrade_agent_profiles_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_agent_profiles';

        // Check if columns already exist before adding
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Add title column after display_name
        if (!in_array('title', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN title VARCHAR(100) AFTER display_name");
        }

        // Add social_links column (JSON)
        if (!in_array('social_links', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN social_links JSON AFTER specialties");
        }

        // Add service_areas column
        if (!in_array('service_areas', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN service_areas TEXT AFTER specialties");
        }

        // Add snab_staff_id column for appointment booking integration
        if (!in_array('snab_staff_id', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN snab_staff_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER user_id");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_snab_staff (snab_staff_id)");
        }

        // Add email_signature column for personalized emails
        if (!in_array('email_signature', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN email_signature TEXT AFTER bio");
        }

        // Add custom_greeting column for client emails
        if (!in_array('custom_greeting', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN custom_greeting TEXT AFTER email_signature");
        }
    }

    /**
     * Upgrade saved_searches table with collaboration columns for v1.3.0
     *
     * @since 1.3.0
     */
    private static function upgrade_saved_searches_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_saved_searches';

        // Check if columns already exist before adding
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Add created_by_user_id for tracking who created the search
        if (!in_array('created_by_user_id', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN created_by_user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER user_id");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_created_by_user (created_by_user_id)");
        }

        // Add last_modified_by_user_id for tracking modifications
        if (!in_array('last_modified_by_user_id', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN last_modified_by_user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER created_by_user_id");
        }

        // Add last_modified_at timestamp
        if (!in_array('last_modified_at', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN last_modified_at DATETIME DEFAULT NULL AFTER last_modified_by_user_id");
        }

        // Add is_agent_recommended flag for agent-created searches
        if (!in_array('is_agent_recommended', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN is_agent_recommended TINYINT(1) DEFAULT 0 AFTER last_modified_at");
        }

        // Add agent_notes for internal agent notes on search
        if (!in_array('agent_notes', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN agent_notes TEXT DEFAULT NULL AFTER is_agent_recommended");
        }

        // Add cc_agent_on_notify flag to copy agent on notifications
        if (!in_array('cc_agent_on_notify', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN cc_agent_on_notify TINYINT(1) DEFAULT 1 AFTER agent_notes");
        }
    }

    /**
     * Upgrade push_notification_log table with read/dismissed status columns for v1.11.0
     * Enables server-side notification status tracking for cross-device sync
     *
     * @since 1.11.0
     */
    private static function upgrade_push_notification_log_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return; // Table doesn't exist yet
        }

        // Check if columns already exist before adding
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Add is_read flag for tracking read status
        if (!in_array('is_read', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER source_plugin");
        }

        // Add read_at timestamp
        if (!in_array('read_at', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN read_at DATETIME DEFAULT NULL AFTER is_read");
        }

        // Add is_dismissed flag for tracking dismissed/deleted status
        if (!in_array('is_dismissed', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN is_dismissed TINYINT(1) DEFAULT 0 AFTER read_at");
        }

        // Add dismissed_at timestamp
        if (!in_array('dismissed_at', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN dismissed_at DATETIME DEFAULT NULL AFTER is_dismissed");
        }

        // Add composite index for efficient status queries
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
        $existing_indexes = array_column($indexes, 'Key_name');
        if (!in_array('idx_user_status', $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_user_status (user_id, is_read, is_dismissed)");
        }
    }

    /**
     * Check if tables need to be updated
     */
    public static function maybe_update_tables() {
        $current_version = get_option(self::VERSION_OPTION, '0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::create_tables();
        }
    }
    
    /**
     * Drop all saved search tables (for uninstall)
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = [
            'mld_saved_searches',
            'mld_saved_search_results',
            'mld_saved_search_email_settings',
            'mld_property_preferences',
            'mld_agent_client_relationships',
            'mld_agent_profiles',
            'mld_admin_client_preferences',
            'mld_device_tokens',
            'mld_user_types',
            'mld_saved_search_activity',
            'mld_user_email_preferences',
            'mld_email_analytics',
            'mld_client_activity',
            'mld_client_sessions',
            'mld_client_analytics_summary',
            'mld_client_engagement_scores',
            'mld_client_property_interest',
            'mld_agent_notification_preferences',
            'mld_agent_notification_log',
            'mld_client_app_opens',
            'mld_agent_referral_codes',
            'mld_referral_signups',
            'mld_revoked_tokens'
        ];
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
        }
        
        delete_option(self::VERSION_OPTION);
    }
    
    /**
     * Get table name with prefix
     */
    public static function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'mld_' . $table;
    }
}