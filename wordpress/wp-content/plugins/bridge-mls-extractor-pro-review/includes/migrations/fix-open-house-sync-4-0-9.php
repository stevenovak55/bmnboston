<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration to fix open house sync issue - Version 4.0.9
 *
 * Problem: Subsequent open houses for the same property were being deleted
 * because all existing open houses were marked as pending_deletion during sync.
 *
 * Solution: Only mark expired open houses as pending_deletion to preserve
 * future open houses that might not be in every API response.
 *
 * @since 4.0.9
 */
class BME_Fix_Open_House_Sync_409 {

    /**
     * Run the migration
     */
    public static function run() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bme_open_houses';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            error_log("BME Migration 4.0.9: Open houses table doesn't exist, skipping migration");
            return false;
        }

        try {
            // Clean up any orphaned open houses that have expired but weren't deleted
            $cleaned_count = $wpdb->query(
                "DELETE FROM {$table_name}
                 WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                 AND sync_status = 'pending_deletion'"
            );

            if ($cleaned_count > 0) {
                error_log("BME Migration 4.0.9: Cleaned up {$cleaned_count} expired open houses");
            }

            // Reset sync_status for all current open houses to ensure clean state
            $reset_count = $wpdb->query(
                "UPDATE {$table_name}
                 SET sync_status = 'current'
                 WHERE expires_at >= NOW()
                 OR expires_at IS NULL"
            );

            if ($reset_count > 0) {
                error_log("BME Migration 4.0.9: Reset sync_status for {$reset_count} current/future open houses");
            }

            // Update the migration status
            update_option('bme_open_house_sync_fix_409', true);
            update_option('bme_open_house_sync_fix_409_date', current_time('mysql'));

            error_log("BME Migration 4.0.9: Open house sync fix migration completed successfully");

            return true;

        } catch (Exception $e) {
            error_log("BME Migration 4.0.9 Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if migration has been run
     */
    public static function is_migrated() {
        return get_option('bme_open_house_sync_fix_409', false);
    }
}
