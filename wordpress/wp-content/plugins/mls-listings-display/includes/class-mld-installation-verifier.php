<?php
/**
 * Installation Verifier
 *
 * Verifies and repairs the plugin installation, ensuring all tables and components are properly set up.
 * This is especially important for fresh installations on new sites.
 *
 * @package MLS_Listings_Display
 * @since 4.5.0
 */

class MLD_Installation_Verifier {

    /**
     * Run complete installation verification and repair
     *
     * @return array Results of verification and repair
     */
    public static function verify_and_repair() {
        $results = [
            'status' => 'success',
            'tables_created' => [],
            'tables_repaired' => [],
            'errors' => [],
            'notifications_enabled' => false
        ];

        // 1. Verify and create core tables
        $core_tables = self::verify_core_tables();
        $results['tables_created'] = array_merge($results['tables_created'], $core_tables['created']);
        $results['tables_repaired'] = array_merge($results['tables_repaired'], $core_tables['repaired']);

        // 2. Verify and create instant notification tables
        $notification_tables = self::verify_notification_tables();
        $results['tables_created'] = array_merge($results['tables_created'], $notification_tables['created']);
        $results['tables_repaired'] = array_merge($results['tables_repaired'], $notification_tables['repaired']);

        // 3. Verify BuddyBoss integration
        $buddyboss_status = self::verify_buddyboss_integration();
        $results['buddyboss_enabled'] = $buddyboss_status;

        // 4. Verify and set up cron jobs
        $cron_status = self::verify_cron_jobs();
        $results['cron_jobs'] = $cron_status;

        // 5. Set default options if missing
        self::set_default_options();

        // 6. Initialize instant notifications system
        if (class_exists('MLD_Instant_Notifications_Init')) {
            MLD_Instant_Notifications_Init::get_instance();
            $results['notifications_enabled'] = true;
        }

        // Log the verification
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Installation Verification Results: ' . json_encode($results));
        }

        return $results;
    }

    /**
     * Verify and create core tables
     */
    private static function verify_core_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $results = ['created' => [], 'repaired' => []];

        // Define all core tables with their SQL
        $tables = [
            'mld_saved_searches' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_saved_searches (
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
            ) $charset_collate",

            'mld_saved_search_results' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_saved_search_results (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                saved_search_id BIGINT(20) UNSIGNED NOT NULL,
                listing_id VARCHAR(50) NOT NULL,
                listing_key VARCHAR(128) NOT NULL,
                first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                notified_at DATETIME,
                PRIMARY KEY (id),
                UNIQUE KEY unique_search_listing (saved_search_id, listing_id),
                KEY idx_notified (notified_at)
            ) $charset_collate",

            'mld_property_preferences' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_property_preferences (
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
            ) $charset_collate",

            'mld_saved_search_cron_log' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_saved_search_cron_log (
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
            ) $charset_collate"
        ];

        foreach ($tables as $table_name => $sql) {
            $full_table_name = $wpdb->prefix . $table_name;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'");

            if (!$exists) {
                dbDelta($sql);
                $results['created'][] = $full_table_name;
            } else {
                // Table exists, check if it needs updates
                dbDelta($sql);
                $results['repaired'][] = $full_table_name;
            }
        }

        return $results;
    }

    /**
     * Verify and create instant notification tables
     */
    private static function verify_notification_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $results = ['created' => [], 'repaired' => []];

        $tables = [
            'mld_search_activity_matches' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_search_activity_matches (
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
            ) $charset_collate",

            'mld_notification_preferences' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_notification_preferences (
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
            ) $charset_collate",

            'mld_notification_throttle' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mld_notification_throttle (
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
            ) $charset_collate"
        ];

        foreach ($tables as $table_name => $sql) {
            $full_table_name = $wpdb->prefix . $table_name;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'");

            if (!$exists) {
                dbDelta($sql);
                $results['created'][] = $full_table_name;
            } else {
                // Table exists, check if it needs updates
                dbDelta($sql);
                $results['repaired'][] = $full_table_name;
            }
        }

        return $results;
    }

    /**
     * Verify BuddyBoss integration
     */
    private static function verify_buddyboss_integration() {
        if (!class_exists('BuddyPress')) {
            return false;
        }

        // Ensure BuddyBoss component is registered
        if (class_exists('MLD_BuddyBoss_Integration')) {
            $integration = MLD_BuddyBoss_Integration::get_instance();

            // Force re-registration
            add_action('bp_setup_globals', function() use ($integration) {
                $integration->setup_globals();
            }, 5);

            return true;
        }

        return false;
    }

    /**
     * Verify and set up cron jobs
     */
    private static function verify_cron_jobs() {
        $cron_jobs = [
            'mld_instant_notifications_cleanup' => 'daily'
        ];

        $results = [];

        // First, register custom cron schedule if needed
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display' => __('Every Minute', 'mld')
                ];
            }
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = [
                    'interval' => 604800,
                    'display' => __('Weekly', 'mld')
                ];
            }
            return $schedules;
        });

        foreach ($cron_jobs as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $schedule, $hook);
                $results[$hook] = 'scheduled';
            } else {
                $results[$hook] = 'exists';
            }
        }

        return $results;
    }

    /**
     * Set default options
     */
    private static function set_default_options() {
        // Instant notification options
        $options = [
            'mld_instant_notifications_enabled' => true,
            'mld_instant_bulk_threshold' => 10,
            'mld_instant_quiet_hours_start' => '22:00',
            'mld_instant_quiet_hours_end' => '08:00',
            'mld_instant_notifications_db_version' => '1.0.0'
        ];

        foreach ($options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                update_option($option_name, $default_value);
            }
        }
    }

    /**
     * Run verification on admin init if needed
     */
    public static function maybe_run_verification() {
        // Check if verification has been run recently
        $last_verification = get_option('mld_last_installation_verification');

        // Run verification if:
        // 1. Never run before
        // 2. Plugin was recently updated
        // 3. Manual trigger via admin
        if (!$last_verification ||
            (isset($_GET['mld_verify_installation']) && current_user_can('manage_options'))) {

            $results = self::verify_and_repair();

            // Store verification timestamp
            update_option('mld_last_installation_verification', current_time('timestamp'));

            // Show admin notice if triggered manually
            if (isset($_GET['mld_verify_installation'])) {
                add_action('admin_notices', function() use ($results) {
                    $class = $results['status'] === 'success' ? 'notice-success' : 'notice-warning';
                    $message = 'MLD Installation Verification Complete: ';
                    $message .= count($results['tables_created']) . ' tables created, ';
                    $message .= count($results['tables_repaired']) . ' tables verified/repaired.';

                    if ($results['notifications_enabled']) {
                        $message .= ' Instant notifications system is active.';
                    }

                    printf('<div class="%1$s notice is-dismissible"><p>%2$s</p></div>',
                        esc_attr($class), esc_html($message));
                });
            }
        }
    }
}

// Hook into admin_init to run verification when needed
add_action('admin_init', ['MLD_Installation_Verifier', 'maybe_run_verification']);
