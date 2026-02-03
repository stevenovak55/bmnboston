<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync Integrity Verifier - Detects and resolves sync inconsistencies
 *
 * Provides comprehensive verification of sync integrity, particularly for
 * open houses and related data. Includes auto-recovery mechanisms for
 * common sync issues.
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 3.31
 * @version 1.0.0
 */
class BME_Sync_Verifier {

    /**
     * @var BME_Database_Manager Database manager instance
     */
    private $db_manager;

    /**
     * @var BME_Activity_Logger Activity logger instance
     */
    private $activity_logger;

    /**
     * @var array Verification results
     */
    private $verification_results;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db_manager = bme_pro()->get('db');
        $this->activity_logger = bme_pro()->get('activity_logger');
        $this->init_verification_results();
    }

    /**
     * Perform comprehensive sync integrity verification
     *
     * @param int|null $extraction_id Optional extraction ID to verify specific sync
     * @return array Verification results with issues and recommendations
     */
    public function verify_sync_integrity($extraction_id = null) {
        $this->init_verification_results();

        try {
            // Core data integrity checks
            $this->verify_listing_data_integrity();
            $this->verify_open_house_integrity();
            $this->verify_related_data_consistency();

            // Specific extraction verification if provided
            if ($extraction_id) {
                $this->verify_extraction_specific_integrity($extraction_id);
            }

            // Check for data freshness
            $this->verify_data_freshness();

            $this->log_verification_results();

        } catch (Exception $e) {
            $this->verification_results['critical_errors'][] = [
                'type' => 'verification_exception',
                'message' => 'Verification process failed: ' . $e->getMessage(),
                'timestamp' => current_time('mysql')
            ];
        }

        return $this->verification_results;
    }

    /**
     * Auto-recover from detected sync issues
     *
     * @param array $verification_results Results from verify_sync_integrity()
     * @return array Recovery results
     */
    public function auto_recover_sync_issues($verification_results = null) {
        if ($verification_results === null) {
            $verification_results = $this->verify_sync_integrity();
        }

        $recovery_results = [
            'recovered_issues' => 0,
            'failed_recoveries' => 0,
            'recovery_actions' => []
        ];

        try {
            // Recover open house issues
            if (!empty($verification_results['open_house_issues'])) {
                $recovery_results = array_merge_recursive(
                    $recovery_results,
                    $this->recover_open_house_issues($verification_results['open_house_issues'])
                );
            }

            // Recover orphaned data
            if (!empty($verification_results['orphaned_data'])) {
                $recovery_results = array_merge_recursive(
                    $recovery_results,
                    $this->recover_orphaned_data($verification_results['orphaned_data'])
                );
            }

            // Clean up stale sync statuses
            if (!empty($verification_results['stale_data'])) {
                $recovery_results = array_merge_recursive(
                    $recovery_results,
                    $this->cleanup_stale_data($verification_results['stale_data'])
                );
            }

        } catch (Exception $e) {
            $recovery_results['failed_recoveries']++;
            $recovery_results['recovery_actions'][] = [
                'action' => 'recovery_exception',
                'status' => 'failed',
                'message' => 'Recovery process failed: ' . $e->getMessage()
            ];
        }

        return $recovery_results;
    }

    /**
     * Verify listing data integrity
     */
    private function verify_listing_data_integrity() {
        global $wpdb;

        // Check for listings without required related data
        $listings_table = $this->db_manager->get_table('listings');
        $location_table = $this->db_manager->get_table('listing_location');
        $details_table = $this->db_manager->get_table('listing_details');

        // Find listings missing location data
        $missing_location = $wpdb->get_results("
            SELECT l.listing_id, l.list_price, l.standard_status
            FROM {$listings_table} l
            LEFT JOIN {$location_table} ll ON l.listing_id = ll.listing_id
            WHERE l.standard_status = 'Active'
            AND ll.listing_id IS NULL
            LIMIT 100
        ");

        if (!empty($missing_location)) {
            $this->verification_results['data_integrity_issues'][] = [
                'type' => 'missing_location_data',
                'count' => count($missing_location),
                'sample_listings' => array_slice($missing_location, 0, 5),
                'severity' => 'medium'
            ];
        }

        // Find listings missing details data
        $missing_details = $wpdb->get_results("
            SELECT l.listing_id, l.list_price, l.standard_status
            FROM {$listings_table} l
            LEFT JOIN {$details_table} ld ON l.listing_id = ld.listing_id
            WHERE l.standard_status = 'Active'
            AND ld.listing_id IS NULL
            LIMIT 100
        ");

        if (!empty($missing_details)) {
            $this->verification_results['data_integrity_issues'][] = [
                'type' => 'missing_details_data',
                'count' => count($missing_details),
                'sample_listings' => array_slice($missing_details, 0, 5),
                'severity' => 'medium'
            ];
        }
    }

    /**
     * Verify open house data integrity
     */
    private function verify_open_house_integrity() {
        global $wpdb;

        $open_houses_table = $this->db_manager->get_table('open_houses');
        $listings_table = $this->db_manager->get_table('listings');

        // Check for open houses with pending_deletion status (incomplete sync)
        $pending_deletion_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$open_houses_table}
            WHERE sync_status = %s
        ", 'pending_deletion'));

        if ($pending_deletion_count > 0) {
            $this->verification_results['open_house_issues'][] = [
                'type' => 'pending_deletion_records',
                'count' => $pending_deletion_count,
                'severity' => 'high',
                'auto_recoverable' => true
            ];
        }

        // Check for orphaned open houses (listing no longer exists)
        $orphaned_open_houses = $wpdb->get_results("
            SELECT oh.listing_id, COUNT(*) as open_house_count
            FROM {$open_houses_table} oh
            LEFT JOIN {$listings_table} l ON oh.listing_id = l.listing_id
            WHERE l.listing_id IS NULL
            GROUP BY oh.listing_id
            LIMIT 50
        ");

        if (!empty($orphaned_open_houses)) {
            $this->verification_results['orphaned_data'][] = [
                'type' => 'orphaned_open_houses',
                'count' => count($orphaned_open_houses),
                'total_records' => array_sum(array_column($orphaned_open_houses, 'open_house_count')),
                'sample_data' => array_slice($orphaned_open_houses, 0, 5),
                'severity' => 'medium',
                'auto_recoverable' => true
            ];
        }

        // Check for very old sync timestamps
        $stale_threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stale_open_houses = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$open_houses_table}
            WHERE sync_timestamp < %s
            AND sync_timestamp IS NOT NULL
        ", $stale_threshold));

        if ($stale_open_houses > 0) {
            $this->verification_results['stale_data'][] = [
                'type' => 'stale_open_house_sync',
                'count' => $stale_open_houses,
                'threshold' => $stale_threshold,
                'severity' => 'low',
                'auto_recoverable' => true
            ];
        }

        // Check for invalid JSON in open_house_data
        $invalid_json_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$open_houses_table}
            WHERE open_house_data IS NOT NULL
            AND JSON_VALID(open_house_data) = 0
        ");

        if ($invalid_json_count > 0) {
            $this->verification_results['open_house_issues'][] = [
                'type' => 'invalid_json_data',
                'count' => $invalid_json_count,
                'severity' => 'medium',
                'auto_recoverable' => false
            ];
        }
    }

    /**
     * Verify related data consistency
     */
    private function verify_related_data_consistency() {
        global $wpdb;

        $listings_table = $this->db_manager->get_table('listings');
        $agents_table = $this->db_manager->get_table('agents');
        $media_table = $this->db_manager->get_table('media');

        // Check for listings referencing non-existent agents
        $missing_agents = $wpdb->get_results("
            SELECT l.listing_id, l.list_agent_mls_id
            FROM {$listings_table} l
            LEFT JOIN {$agents_table} a ON l.list_agent_mls_id = a.agent_mls_id
            WHERE l.list_agent_mls_id IS NOT NULL
            AND l.list_agent_mls_id != ''
            AND a.agent_mls_id IS NULL
            AND l.standard_status = 'Active'
            LIMIT 50
        ");

        if (!empty($missing_agents)) {
            $this->verification_results['related_data_issues'][] = [
                'type' => 'missing_agent_references',
                'count' => count($missing_agents),
                'sample_data' => array_slice($missing_agents, 0, 5),
                'severity' => 'low'
            ];
        }

        // Check for listings without any media
        $listings_without_media = $wpdb->get_var("
            SELECT COUNT(DISTINCT l.listing_id)
            FROM {$listings_table} l
            LEFT JOIN {$media_table} m ON l.listing_id = m.listing_id
            WHERE l.standard_status = 'Active'
            AND m.listing_id IS NULL
        ");

        if ($listings_without_media > 0) {
            $this->verification_results['related_data_issues'][] = [
                'type' => 'listings_without_media',
                'count' => $listings_without_media,
                'severity' => 'low'
            ];
        }
    }

    /**
     * Verify extraction-specific integrity
     */
    private function verify_extraction_specific_integrity($extraction_id) {
        // Check last extraction activity
        if ($this->activity_logger) {
            $recent_activities = $this->activity_logger->get_extraction_activities($extraction_id, 1);

            if (empty($recent_activities)) {
                $this->verification_results['extraction_issues'][] = [
                    'type' => 'no_recent_activity',
                    'extraction_id' => $extraction_id,
                    'severity' => 'medium'
                ];
            } else {
                $last_activity = $recent_activities[0];
                $last_run_time = strtotime($last_activity['created_at']);
                $hours_since_last_run = (time() - $last_run_time) / 3600;

                if ($hours_since_last_run > 48) {
                    $this->verification_results['extraction_issues'][] = [
                        'type' => 'stale_extraction_activity',
                        'extraction_id' => $extraction_id,
                        'hours_since_last_run' => round($hours_since_last_run, 1),
                        'severity' => 'low'
                    ];
                }
            }
        }
    }

    /**
     * Verify data freshness
     */
    private function verify_data_freshness() {
        global $wpdb;

        $listings_table = $this->db_manager->get_table('listings');

        // Check for very old active listings (may indicate sync issues)
        $stale_threshold = date('Y-m-d H:i:s', strtotime('-30 days'));
        $stale_active_listings = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$listings_table}
            WHERE standard_status = 'Active'
            AND modification_timestamp < %s
        ", $stale_threshold));

        if ($stale_active_listings > 100) {
            $this->verification_results['freshness_issues'][] = [
                'type' => 'stale_active_listings',
                'count' => $stale_active_listings,
                'threshold' => $stale_threshold,
                'severity' => 'medium'
            ];
        }
    }

    /**
     * Recover open house issues
     */
    private function recover_open_house_issues($open_house_issues) {
        global $wpdb;
        $recovery_results = ['recovered_issues' => 0, 'failed_recoveries' => 0, 'recovery_actions' => []];

        foreach ($open_house_issues as $issue) {
            try {
                switch ($issue['type']) {
                    case 'pending_deletion_records':
                        $deleted = $wpdb->delete(
                            $this->db_manager->get_table('open_houses'),
                            ['sync_status' => 'pending_deletion']
                        );

                        $recovery_results['recovery_actions'][] = [
                            'action' => 'cleanup_pending_deletion',
                            'status' => 'success',
                            'records_affected' => $deleted
                        ];
                        $recovery_results['recovered_issues']++;
                        break;

                    default:
                        $recovery_results['recovery_actions'][] = [
                            'action' => 'unknown_open_house_issue',
                            'status' => 'skipped',
                            'issue_type' => $issue['type']
                        ];
                        break;
                }
            } catch (Exception $e) {
                $recovery_results['failed_recoveries']++;
                $recovery_results['recovery_actions'][] = [
                    'action' => 'recover_open_house_issue',
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $recovery_results;
    }

    /**
     * Recover orphaned data
     */
    private function recover_orphaned_data($orphaned_data) {
        global $wpdb;
        $recovery_results = ['recovered_issues' => 0, 'failed_recoveries' => 0, 'recovery_actions' => []];

        foreach ($orphaned_data as $orphan) {
            try {
                switch ($orphan['type']) {
                    case 'orphaned_open_houses':
                        $orphaned_listing_ids = array_column($orphan['sample_data'], 'listing_id');
                        if (!empty($orphaned_listing_ids)) {
                            $placeholders = implode(',', array_fill(0, count($orphaned_listing_ids), '%s'));
                            $deleted = $wpdb->query($wpdb->prepare(
                                "DELETE FROM {$this->db_manager->get_table('open_houses')} WHERE listing_id IN ({$placeholders})",
                                $orphaned_listing_ids
                            ));

                            $recovery_results['recovery_actions'][] = [
                                'action' => 'cleanup_orphaned_open_houses',
                                'status' => 'success',
                                'records_affected' => $deleted
                            ];
                            $recovery_results['recovered_issues']++;
                        }
                        break;

                    default:
                        $recovery_results['recovery_actions'][] = [
                            'action' => 'unknown_orphaned_data',
                            'status' => 'skipped',
                            'orphan_type' => $orphan['type']
                        ];
                        break;
                }
            } catch (Exception $e) {
                $recovery_results['failed_recoveries']++;
                $recovery_results['recovery_actions'][] = [
                    'action' => 'recover_orphaned_data',
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $recovery_results;
    }

    /**
     * Cleanup stale data
     */
    private function cleanup_stale_data($stale_data) {
        global $wpdb;
        $recovery_results = ['recovered_issues' => 0, 'failed_recoveries' => 0, 'recovery_actions' => []];

        foreach ($stale_data as $stale) {
            try {
                switch ($stale['type']) {
                    case 'stale_open_house_sync':
                        // Update sync timestamps for stale records
                        $updated = $wpdb->update(
                            $this->db_manager->get_table('open_houses'),
                            ['sync_timestamp' => current_time('mysql', true)],
                            ['sync_timestamp <' => $stale['threshold']]
                        );

                        $recovery_results['recovery_actions'][] = [
                            'action' => 'refresh_stale_sync_timestamps',
                            'status' => 'success',
                            'records_affected' => $updated
                        ];
                        $recovery_results['recovered_issues']++;
                        break;

                    default:
                        $recovery_results['recovery_actions'][] = [
                            'action' => 'unknown_stale_data',
                            'status' => 'skipped',
                            'stale_type' => $stale['type']
                        ];
                        break;
                }
            } catch (Exception $e) {
                $recovery_results['failed_recoveries']++;
                $recovery_results['recovery_actions'][] = [
                    'action' => 'cleanup_stale_data',
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $recovery_results;
    }

    /**
     * Initialize verification results structure
     */
    private function init_verification_results() {
        $this->verification_results = [
            'verification_timestamp' => current_time('mysql'),
            'data_integrity_issues' => [],
            'open_house_issues' => [],
            'related_data_issues' => [],
            'orphaned_data' => [],
            'stale_data' => [],
            'freshness_issues' => [],
            'extraction_issues' => [],
            'critical_errors' => [],
            'summary' => [
                'total_issues' => 0,
                'high_severity' => 0,
                'medium_severity' => 0,
                'low_severity' => 0,
                'auto_recoverable' => 0
            ]
        ];
    }

    /**
     * Log verification results
     */
    private function log_verification_results() {
        $all_issues = array_merge(
            $this->verification_results['data_integrity_issues'],
            $this->verification_results['open_house_issues'],
            $this->verification_results['related_data_issues'],
            $this->verification_results['orphaned_data'],
            $this->verification_results['stale_data'],
            $this->verification_results['freshness_issues'],
            $this->verification_results['extraction_issues']
        );

        $this->verification_results['summary']['total_issues'] = count($all_issues);

        foreach ($all_issues as $issue) {
            $severity = $issue['severity'] ?? 'low';
            $this->verification_results['summary'][$severity . '_severity']++;

            if ($issue['auto_recoverable'] ?? false) {
                $this->verification_results['summary']['auto_recoverable']++;
            }
        }

        if ($this->verification_results['summary']['total_issues'] > 0) {
            error_log("BME Sync Verifier: Found {$this->verification_results['summary']['total_issues']} sync integrity issues");
        }

        if ($this->activity_logger) {
            $this->activity_logger->log_activity(
                'sync_verification',
                'Sync integrity verification completed',
                $this->verification_results['summary'],
                ['severity' => $this->verification_results['summary']['high_severity'] > 0 ? 'warning' : 'info']
            );
        }
    }

    /**
     * Get summary of verification results
     */
    public function get_verification_summary($verification_results = null) {
        if ($verification_results === null) {
            $verification_results = $this->verification_results;
        }

        return $verification_results['summary'] ?? [];
    }

    /**
     * Check if auto-recovery is recommended
     */
    public function should_auto_recover($verification_results = null) {
        if ($verification_results === null) {
            $verification_results = $this->verification_results;
        }

        $summary = $verification_results['summary'] ?? [];

        // Only auto-recover if there are recoverable issues and no critical errors
        return ($summary['auto_recoverable'] ?? 0) > 0 &&
               empty($verification_results['critical_errors']);
    }
}