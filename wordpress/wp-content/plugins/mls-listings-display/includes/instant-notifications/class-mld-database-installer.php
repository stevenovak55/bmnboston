<?php
/**
 * MLD Instant Notifications - Database Installer
 *
 * Creates and manages database tables for the instant notification system
 *
 * @package MLS_Listings_Display
 * @subpackage Instant_Notifications
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Database_Installer {

    /**
     * Install all required database tables
     *
     * @return bool True on success, false on failure
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Create search activity matches table
        $table_matches = $wpdb->prefix . 'mld_search_activity_matches';
        $sql_matches = "CREATE TABLE IF NOT EXISTS $table_matches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_log_id BIGINT UNSIGNED NOT NULL,
            saved_search_id BIGINT UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL,
            match_score INT DEFAULT 100,
            notification_status ENUM('pending', 'sent', 'failed', 'throttled') DEFAULT 'pending',
            notified_at DATETIME,
            notification_channels JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_activity (activity_log_id),
            KEY idx_search (saved_search_id),
            KEY idx_listing (listing_id),
            KEY idx_status (notification_status),
            KEY idx_created (created_at)
        ) $charset_collate";

        // Create notification preferences table
        $table_preferences = $wpdb->prefix . 'mld_notification_preferences';
        $sql_preferences = "CREATE TABLE IF NOT EXISTS $table_preferences (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            saved_search_id BIGINT UNSIGNED DEFAULT NULL,
            instant_app_notifications BOOLEAN DEFAULT TRUE,
            instant_email_notifications BOOLEAN DEFAULT TRUE,
            instant_sms_notifications BOOLEAN DEFAULT FALSE,
            quiet_hours_enabled BOOLEAN DEFAULT TRUE,
            quiet_hours_start TIME DEFAULT '22:00:00',
            quiet_hours_end TIME DEFAULT '08:00:00',
            throttling_enabled BOOLEAN DEFAULT TRUE,
            max_daily_notifications INT DEFAULT 50,
            notification_types JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_search (user_id, saved_search_id),
            KEY idx_user (user_id)
        ) $charset_collate";

        // Create notification throttle table
        $table_throttle = $wpdb->prefix . 'mld_notification_throttle';
        $sql_throttle = "CREATE TABLE IF NOT EXISTS $table_throttle (
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
        ) $charset_collate";

        // Create notification queue table
        $table_queue = $wpdb->prefix . 'mld_notification_queue';
        $sql_queue = "CREATE TABLE IF NOT EXISTS $table_queue (
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
        ) $charset_collate";

        // Create notification history table for tracking all sent notifications
        $table_history = $wpdb->prefix . 'mld_notification_history';
        $sql_history = "CREATE TABLE IF NOT EXISTS $table_history (
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
        ) $charset_collate";

        // Execute SQL queries
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $results = [];
        $results[] = dbDelta($sql_matches);
        $results[] = dbDelta($sql_preferences);
        $results[] = dbDelta($sql_throttle);
        $results[] = dbDelta($sql_queue);
        $results[] = dbDelta($sql_history);

        // Log installation results
        if (defined('WP_DEBUG') && WP_DEBUG) {
            foreach ($results as $result) {
                if (!empty($result)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('MLD Instant Notifications DB Install: ' . print_r($result, true));
                    }
                }
            }
        }

        // Update database version
        update_option('mld_instant_notifications_db_version', '1.3.0');

        return true;
    }

    /**
     * Check if tables exist
     *
     * @return bool True if all tables exist
     */
    public static function tables_exist() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'mld_search_activity_matches',
            $wpdb->prefix . 'mld_notification_preferences',
            $wpdb->prefix . 'mld_notification_throttle',
            $wpdb->prefix . 'mld_notification_queue',
            $wpdb->prefix . 'mld_notification_history'
        ];

        foreach ($tables as $table) {
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if ($result !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop all instant notification tables
     *
     * @return bool True on success
     */
    public static function uninstall() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'mld_search_activity_matches',
            $wpdb->prefix . 'mld_notification_preferences',
            $wpdb->prefix . 'mld_notification_throttle',
            $wpdb->prefix . 'mld_notification_queue'
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }

        delete_option('mld_instant_notifications_db_version');

        return true;
    }
}
