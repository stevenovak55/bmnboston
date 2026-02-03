<?php
/**
 * MLD Property Change Detector
 *
 * Detects price reductions and status changes on properties that users have favorited.
 * Runs as a cron job every 15 minutes.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Change_Detector {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'mld_property_change_detection';

    /**
     * Option key for tracking last check
     */
    const LAST_CHECK_OPTION = 'mld_property_change_last_check';

    /**
     * Initialize the detector
     */
    public static function init() {
        // Register cron action
        add_action(self::CRON_HOOK, [__CLASS__, 'detect_changes']);

        // Self-healing: ensure cron is scheduled
        add_action('admin_init', [__CLASS__, 'maybe_schedule_event'], 20);
    }

    /**
     * Schedule the cron event if not already scheduled
     */
    public static function maybe_schedule_event() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'mld_fifteen_minutes', self::CRON_HOOK);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Property Change Detector: Scheduled cron event');
            }
        }
    }

    /**
     * Unschedule the cron event
     */
    public static function unschedule_event() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Main detection method - runs every 15 minutes
     *
     * @return array Results of the detection process
     */
    public static function detect_changes() {
        global $wpdb;

        $start_time = microtime(true);
        $results = [
            'price_reductions' => 0,
            'status_changes' => 0,
            'properties_checked' => 0,
            'errors' => []
        ];

        try {
            // Get last check timestamp
            $last_check = get_option(self::LAST_CHECK_OPTION, current_time('mysql', true));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Property Change Detector: Starting detection since {$last_check}");
            }

            // 1. Detect price reductions
            $price_changes = self::detect_price_reductions($last_check);
            $results['price_reductions'] = count($price_changes);

            // 2. Detect status changes (Active -> Pending, Pending -> Sold)
            $status_changes = self::detect_status_changes($last_check);
            $results['status_changes'] = count($status_changes);

            // 3. Store detected changes in the database
            if (!empty($price_changes) || !empty($status_changes)) {
                self::store_changes($price_changes, $status_changes);
            }

            // Update last check timestamp
            update_option(self::LAST_CHECK_OPTION, current_time('mysql', true));

            $results['properties_checked'] = $results['price_reductions'] + $results['status_changes'];

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MLD Property Change Detector: Completed - Price reductions: %d, Status changes: %d',
                    $results['price_reductions'],
                    $results['status_changes']
                ));
            }

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Property Change Detector Error: ' . $e->getMessage());
            }
        }

        // Store results for monitoring
        $results['execution_time'] = round(microtime(true) - $start_time, 2);
        $results['last_run'] = current_time('mysql');
        update_option('mld_property_change_detector_results', $results);

        return $results;
    }

    /**
     * Detect price reductions on favorited properties
     *
     * Only detects REDUCTIONS (not increases) per user preference.
     *
     * @param string $since_timestamp Only check properties modified after this time
     * @return array Array of price reduction records
     */
    private static function detect_price_reductions($since_timestamp) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $favorites_table = $wpdb->prefix . 'mld_favorites';
        $changes_table = $wpdb->prefix . 'mld_property_changes';

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") !== $summary_table) {
            return [];
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$favorites_table}'") !== $favorites_table) {
            return [];
        }

        // Find properties that:
        // 1. Are favorited by at least one user (favorites table uses listing_key, not listing_id)
        // 2. Have been modified recently
        // 3. Have a lower price than original_list_price (price reduction)
        // 4. Haven't already been notified for this reduction
        $sql = $wpdb->prepare("
            SELECT DISTINCT
                s.listing_id,
                s.listing_key,
                s.list_price as current_price,
                s.original_list_price as previous_price,
                s.street_number,
                s.street_name,
                s.city,
                s.state_or_province,
                s.standard_status,
                s.main_photo_url,
                s.modification_timestamp
            FROM {$summary_table} s
            INNER JOIN {$favorites_table} f ON s.listing_key = f.listing_id
            WHERE s.modification_timestamp > %s
            AND s.original_list_price IS NOT NULL
            AND s.original_list_price > 0
            AND s.list_price < s.original_list_price
            AND s.standard_status = 'Active'
            AND NOT EXISTS (
                SELECT 1 FROM {$changes_table} c
                WHERE c.listing_id = s.listing_id
                AND c.change_type = 'price_reduction'
                AND c.value_current = s.list_price
                AND c.detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )
            ORDER BY s.modification_timestamp DESC
            LIMIT 500
        ", $since_timestamp);

        $results = $wpdb->get_results($sql);

        if (defined('WP_DEBUG') && WP_DEBUG && !empty($results)) {
            error_log('MLD Property Change Detector: Found ' . count($results) . ' price reductions');
        }

        return $results ?: [];
    }

    /**
     * Detect status changes on favorited properties
     *
     * Detects: Active -> Pending, Pending -> Sold/Closed
     *
     * @param string $since_timestamp Only check properties modified after this time
     * @return array Array of status change records
     */
    private static function detect_status_changes($since_timestamp) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
        $favorites_table = $wpdb->prefix . 'mld_favorites';
        $changes_table = $wpdb->prefix . 'mld_property_changes';

        $status_changes = [];

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") !== $summary_table) {
            return [];
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$favorites_table}'") !== $favorites_table) {
            return [];
        }

        // 1. Find Active -> Pending changes (properties still in active table but now Pending)
        // Note: favorites table stores listing_key in its listing_id column
        $pending_sql = $wpdb->prepare("
            SELECT DISTINCT
                s.listing_id,
                s.listing_key,
                s.list_price as current_price,
                'Active' as previous_status,
                s.standard_status as current_status,
                s.street_number,
                s.street_name,
                s.city,
                s.state_or_province,
                s.main_photo_url,
                s.modification_timestamp
            FROM {$summary_table} s
            INNER JOIN {$favorites_table} f ON s.listing_key = f.listing_id
            WHERE s.modification_timestamp > %s
            AND s.standard_status = 'Pending'
            AND NOT EXISTS (
                SELECT 1 FROM {$changes_table} c
                WHERE c.listing_id = s.listing_id
                AND c.change_type = 'status_change'
                AND c.value_current = 'Pending'
                AND c.detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )
            ORDER BY s.modification_timestamp DESC
            LIMIT 200
        ", $since_timestamp);

        $pending_results = $wpdb->get_results($pending_sql);
        if (!empty($pending_results)) {
            $status_changes = array_merge($status_changes, $pending_results);
        }

        // 2. Find Pending/Active -> Sold/Closed (properties moved to archive table)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$archive_table}'") === $archive_table) {
            $sold_sql = $wpdb->prepare("
                SELECT DISTINCT
                    a.listing_id,
                    a.listing_key,
                    a.close_price as current_price,
                    'Active' as previous_status,
                    a.standard_status as current_status,
                    a.street_number,
                    a.street_name,
                    a.city,
                    a.state_or_province,
                    a.main_photo_url,
                    a.close_date as modification_timestamp
                FROM {$archive_table} a
                INNER JOIN {$favorites_table} f ON a.listing_key = f.listing_id
                WHERE a.close_date > DATE(%s)
                AND a.standard_status IN ('Closed', 'Sold')
                AND NOT EXISTS (
                    SELECT 1 FROM {$changes_table} c
                    WHERE c.listing_id = a.listing_id
                    AND c.change_type = 'status_change'
                    AND c.value_current IN ('Closed', 'Sold')
                    AND c.detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                )
                ORDER BY a.close_date DESC
                LIMIT 200
            ", $since_timestamp);

            $sold_results = $wpdb->get_results($sold_sql);
            if (!empty($sold_results)) {
                $status_changes = array_merge($status_changes, $sold_results);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG && !empty($status_changes)) {
            error_log('MLD Property Change Detector: Found ' . count($status_changes) . ' status changes');
        }

        return $status_changes;
    }

    /**
     * Store detected changes in the database
     *
     * @param array $price_changes Price reduction records
     * @param array $status_changes Status change records
     */
    private static function store_changes($price_changes, $status_changes) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_property_changes';

        // Create table if it doesn't exist
        self::maybe_create_table();

        // Store price reductions
        foreach ($price_changes as $change) {
            $percentage = 0;
            if ($change->previous_price > 0) {
                $percentage = round(
                    (($change->previous_price - $change->current_price) / $change->previous_price) * 100,
                    2
                );
            }

            $wpdb->insert($table, [
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'change_type' => 'price_reduction',
                'value_previous' => (string) $change->previous_price,
                'value_current' => (string) $change->current_price,
                'percentage_change' => $percentage,
                'detected_at' => current_time('mysql'),
                'notified' => 0
            ], ['%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d']);
        }

        // Store status changes
        foreach ($status_changes as $change) {
            $wpdb->insert($table, [
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'change_type' => 'status_change',
                'value_previous' => $change->previous_status ?? 'Active',
                'value_current' => $change->current_status,
                'percentage_change' => null,
                'detected_at' => current_time('mysql'),
                'notified' => 0
            ], ['%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d']);
        }
    }

    /**
     * Create the property changes table if it doesn't exist
     */
    public static function maybe_create_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_property_changes';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id VARCHAR(50) NOT NULL,
            listing_key VARCHAR(128),
            change_type ENUM('price_reduction', 'status_change') NOT NULL,
            value_previous VARCHAR(100),
            value_current VARCHAR(100),
            percentage_change DECIMAL(5,2),
            detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_listing (listing_id),
            KEY idx_change_type (change_type),
            KEY idx_detected (detected_at),
            KEY idx_notified (notified)
        ) {$charset_collate}";

        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Property Change Detector: Created property_changes table');
        }
    }

    /**
     * Get unnotified changes for sending notifications
     *
     * @param int $limit Maximum number of changes to return
     * @return array Array of change records with user info
     */
    public static function get_pending_notifications($limit = 100) {
        global $wpdb;

        $changes_table = $wpdb->prefix . 'mld_property_changes';
        $favorites_table = $wpdb->prefix . 'mld_favorites';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$changes_table}'") !== $changes_table) {
            return [];
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$favorites_table}'") !== $favorites_table) {
            return [];
        }

        // Get unnotified changes with the users who favorited these properties
        // Note: favorites table stores listing_key in its listing_id column, user_id in user_id column
        $sql = $wpdb->prepare("
            SELECT
                c.id as change_id,
                c.listing_id,
                c.listing_key,
                c.change_type,
                c.value_previous,
                c.value_current,
                c.percentage_change,
                c.detected_at,
                f.user_id,
                COALESCE(s.street_number, '') as street_number,
                COALESCE(s.street_name, '') as street_name,
                COALESCE(s.city, '') as city,
                COALESCE(s.main_photo_url, '') as photo_url
            FROM {$changes_table} c
            INNER JOIN {$favorites_table} f ON c.listing_key = f.listing_id
            LEFT JOIN {$summary_table} s ON c.listing_id = s.listing_id
            WHERE c.notified = 0
            AND c.detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY c.detected_at DESC, c.id ASC
            LIMIT %d
        ", $limit);

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Mark changes as notified
     *
     * @param array $change_ids Array of change IDs to mark
     */
    public static function mark_as_notified($change_ids) {
        global $wpdb;

        if (empty($change_ids)) {
            return;
        }

        $table = $wpdb->prefix . 'mld_property_changes';
        $ids = implode(',', array_map('intval', $change_ids));

        $wpdb->query("UPDATE {$table} SET notified = 1 WHERE id IN ({$ids})");
    }

    /**
     * Cleanup old change records (older than 30 days)
     */
    public static function cleanup_old_changes() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_property_changes';

        $deleted = $wpdb->query(
            "DELETE FROM {$table} WHERE detected_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("MLD Property Change Detector: Cleaned up {$deleted} old change records");
        }

        return $deleted;
    }

    /**
     * Get detector status for admin dashboard
     *
     * @return array Status information
     */
    public static function get_status() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_property_changes';
        $results = get_option('mld_property_change_detector_results', []);

        $status = [
            'scheduled' => wp_next_scheduled(self::CRON_HOOK) !== false,
            'next_run' => wp_next_scheduled(self::CRON_HOOK),
            'last_run' => $results['last_run'] ?? null,
            'last_execution_time' => $results['execution_time'] ?? null,
            'last_price_reductions' => $results['price_reductions'] ?? 0,
            'last_status_changes' => $results['status_changes'] ?? 0
        ];

        // Count pending notifications
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $status['pending_notifications'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE notified = 0"
            );
            $status['total_changes_24h'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE detected_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        }

        return $status;
    }
}
