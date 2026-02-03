<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple Sync Health Checker - Lightweight integrity verification
 *
 * Provides basic sync health monitoring without memory overhead.
 * Focuses on the most critical open house sync issues.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.30.2
 * @version 1.0.0
 */
class BME_Sync_Health_Checker {

    /**
     * @var BME_Database_Manager Database manager instance
     */
    private $db_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db_manager = bme_pro()->get('db');
    }

    /**
     * Run basic health check
     *
     * @return array Health check results
     */
    public function run_health_check() {
        $results = [
            'timestamp' => current_time('mysql'),
            'issues_found' => [],
            'auto_fixes_applied' => 0,
            'status' => 'healthy'
        ];

        try {
            // Check for pending deletion records
            $pending_deletion_issues = $this->check_pending_deletion_records();
            if (!empty($pending_deletion_issues)) {
                $results['issues_found'][] = $pending_deletion_issues;
                if ($this->auto_fix_pending_deletion()) {
                    $results['auto_fixes_applied']++;
                }
            }

            // Check for orphaned open houses
            $orphaned_issues = $this->check_orphaned_open_houses();
            if (!empty($orphaned_issues)) {
                $results['issues_found'][] = $orphaned_issues;
                if ($this->auto_fix_orphaned_records($orphaned_issues['orphaned_listing_ids'])) {
                    $results['auto_fixes_applied']++;
                }
            }

            // Check for very old sync timestamps
            $stale_sync_issues = $this->check_stale_sync_timestamps();
            if (!empty($stale_sync_issues)) {
                $results['issues_found'][] = $stale_sync_issues;
            }

            // Determine overall status
            if (count($results['issues_found']) > 2) {
                $results['status'] = 'needs_attention';
            } elseif (count($results['issues_found']) > 0) {
                $results['status'] = 'minor_issues';
            }

        } catch (Exception $e) {
            $results['status'] = 'error';
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Check for records stuck in pending_deletion status
     */
    private function check_pending_deletion_records() {
        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');

        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$table}
            WHERE sync_status = %s
        ", 'pending_deletion'));

        if ($count > 0) {
            return [
                'type' => 'pending_deletion_records',
                'count' => $count,
                'severity' => 'medium',
                'auto_fixable' => true,
                'message' => "Found {$count} open house records stuck in pending_deletion status"
            ];
        }

        return null;
    }

    /**
     * Check for orphaned open houses (listing no longer exists)
     */
    private function check_orphaned_open_houses() {
        global $wpdb;
        $open_houses_table = $this->db_manager->get_table('open_houses');
        $listings_table = $this->db_manager->get_table('listings');

        $orphaned = $wpdb->get_results("
            SELECT oh.listing_id, COUNT(*) as count
            FROM {$open_houses_table} oh
            LEFT JOIN {$listings_table} l ON oh.listing_id = l.listing_id
            WHERE l.listing_id IS NULL
            GROUP BY oh.listing_id
            LIMIT 20
        ");

        if (!empty($orphaned)) {
            $total_orphaned = array_sum(array_column($orphaned, 'count'));
            return [
                'type' => 'orphaned_open_houses',
                'count' => count($orphaned),
                'total_records' => $total_orphaned,
                'orphaned_listing_ids' => array_column($orphaned, 'listing_id'),
                'severity' => 'low',
                'auto_fixable' => true,
                'message' => "Found {$total_orphaned} orphaned open house records from " . count($orphaned) . " non-existent listings"
            ];
        }

        return null;
    }

    /**
     * Check for very old sync timestamps
     */
    private function check_stale_sync_timestamps() {
        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');

        $threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stale_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$table}
            WHERE sync_timestamp < %s
            AND sync_timestamp IS NOT NULL
        ", $threshold));

        if ($stale_count > 50) {
            return [
                'type' => 'stale_sync_timestamps',
                'count' => $stale_count,
                'threshold' => $threshold,
                'severity' => 'low',
                'auto_fixable' => false,
                'message' => "Found {$stale_count} open house records with sync timestamps older than 7 days"
            ];
        }

        return null;
    }

    /**
     * Auto-fix pending deletion records
     */
    private function auto_fix_pending_deletion() {
        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');

        try {
            $deleted = $wpdb->delete($table, ['sync_status' => 'pending_deletion']);

            if ($deleted > 0) {
                error_log("BME Health Check: Auto-fixed {$deleted} pending deletion records");
                return true;
            }
        } catch (Exception $e) {
            error_log("BME Health Check: Failed to auto-fix pending deletion records: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Auto-fix orphaned records
     */
    private function auto_fix_orphaned_records($orphaned_listing_ids) {
        if (empty($orphaned_listing_ids)) {
            return false;
        }

        global $wpdb;
        $table = $this->db_manager->get_table('open_houses');

        try {
            // Only clean up a limited number to avoid performance issues
            $ids_to_clean = array_slice($orphaned_listing_ids, 0, 10);
            $placeholders = implode(',', array_fill(0, count($ids_to_clean), '%s'));

            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE listing_id IN ({$placeholders})",
                $ids_to_clean
            ));

            if ($deleted > 0) {
                error_log("BME Health Check: Auto-fixed {$deleted} orphaned open house records");
                return true;
            }
        } catch (Exception $e) {
            error_log("BME Health Check: Failed to auto-fix orphaned records: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Get health check summary for admin display
     */
    public function get_health_summary() {
        $last_check = get_option('bme_last_health_check', null);

        if (!$last_check) {
            return [
                'status' => 'unknown',
                'message' => 'No health check has been run yet',
                'last_check' => null
            ];
        }

        $status = $last_check['status'] ?? 'unknown';
        $issues_count = count($last_check['issues_found'] ?? []);
        $auto_fixes = $last_check['auto_fixes_applied'] ?? 0;

        $message = '';
        switch ($status) {
            case 'healthy':
                $message = 'All sync operations are running smoothly';
                break;
            case 'minor_issues':
                $message = "Found {$issues_count} minor issue(s)";
                if ($auto_fixes > 0) {
                    $message .= ", {$auto_fixes} auto-fixed";
                }
                break;
            case 'needs_attention':
                $message = "Found {$issues_count} issues that may need attention";
                break;
            case 'error':
                $message = 'Health check encountered an error';
                break;
        }

        return [
            'status' => $status,
            'message' => $message,
            'last_check' => $last_check['timestamp'] ?? null,
            'issues_count' => $issues_count,
            'auto_fixes_applied' => $auto_fixes
        ];
    }

    /**
     * Run and save health check
     */
    public function run_and_save_health_check() {
        $results = $this->run_health_check();
        update_option('bme_last_health_check', $results);

        // Log significant issues
        if ($results['status'] !== 'healthy') {
            $issues_count = count($results['issues_found']);
            error_log("BME Health Check: Status '{$results['status']}' with {$issues_count} issue(s) found");
        }

        return $results;
    }
}