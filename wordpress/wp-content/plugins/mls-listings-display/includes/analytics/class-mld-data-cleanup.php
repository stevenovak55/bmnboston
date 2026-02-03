<?php
/**
 * Data Cleanup
 *
 * One-time cleanup script for orphaned analytics data.
 * Run via WP-CLI or admin action after major changes.
 *
 * @package MLS_Listings_Display
 * @since 6.41.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Data_Cleanup {

    /**
     * Run comprehensive data cleanup
     *
     * Called via WP-CLI: wp eval "MLD_Data_Cleanup::run_cleanup();"
     * Or via admin action hook
     *
     * @since 6.41.0
     * @return array Results summary with counts for each operation
     */
    public static function run_cleanup() {
        global $wpdb;

        $results = array(
            'orphaned_scores_fixed' => 0,
            'deleted_nonexistent_users' => 0,
            'old_inactive_deleted' => 0,
            'scores_recalculated' => 0,
            'orphaned_activity_deleted' => 0,
            'orphaned_sessions_deleted' => 0,
            'orphaned_interests_deleted' => 0,
            'errors' => array(),
        );

        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        $interest_table = $wpdb->prefix . 'mld_client_property_interest';

        // 1. Fix orphaned engagement scores - update agent_id from active relationships
        try {
            $orphaned_scores = $wpdb->query("
                UPDATE {$scores_table} es
                INNER JOIN {$relationships_table} r
                    ON es.user_id = r.client_id AND r.relationship_status = 'active'
                SET es.agent_id = r.agent_id
                WHERE es.agent_id IS NULL
                   OR es.agent_id = 0
                   OR es.agent_id NOT IN (
                       SELECT DISTINCT agent_id
                       FROM {$relationships_table}
                       WHERE relationship_status = 'active'
                   )
            ");
            $results['orphaned_scores_fixed'] = $orphaned_scores !== false ? $orphaned_scores : 0;
        } catch (Exception $e) {
            $results['errors'][] = 'orphaned_scores: ' . $e->getMessage();
        }

        // 2. Delete engagement scores for users that no longer exist
        try {
            $deleted = $wpdb->query("
                DELETE es FROM {$scores_table} es
                LEFT JOIN {$wpdb->users} u ON es.user_id = u.ID
                WHERE u.ID IS NULL
            ");
            $results['deleted_nonexistent_users'] = $deleted !== false ? $deleted : 0;
        } catch (Exception $e) {
            $results['errors'][] = 'nonexistent_users: ' . $e->getMessage();
        }

        // 3. Delete inactive relationships older than 6 months
        try {
            $old_inactive = $wpdb->query("
                DELETE FROM {$relationships_table}
                WHERE relationship_status = 'inactive'
                AND assigned_date < DATE_SUB(NOW(), INTERVAL 6 MONTH)
            ");
            $results['old_inactive_deleted'] = $old_inactive !== false ? $old_inactive : 0;
        } catch (Exception $e) {
            $results['errors'][] = 'old_inactive: ' . $e->getMessage();
        }

        // 4. Delete activity records for users that no longer exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$activity_table}'") === $activity_table) {
            try {
                $deleted_activity = $wpdb->query("
                    DELETE a FROM {$activity_table} a
                    LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
                    WHERE u.ID IS NULL
                ");
                $results['orphaned_activity_deleted'] = $deleted_activity !== false ? $deleted_activity : 0;
            } catch (Exception $e) {
                $results['errors'][] = 'orphaned_activity: ' . $e->getMessage();
            }
        }

        // 5. Delete session records for users that no longer exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table) {
            try {
                $deleted_sessions = $wpdb->query("
                    DELETE s FROM {$sessions_table} s
                    LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                    WHERE s.user_id IS NOT NULL AND u.ID IS NULL
                ");
                $results['orphaned_sessions_deleted'] = $deleted_sessions !== false ? $deleted_sessions : 0;
            } catch (Exception $e) {
                $results['errors'][] = 'orphaned_sessions: ' . $e->getMessage();
            }
        }

        // 6. Delete property interest records for users that no longer exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$interest_table}'") === $interest_table) {
            try {
                $deleted_interests = $wpdb->query("
                    DELETE pi FROM {$interest_table} pi
                    LEFT JOIN {$wpdb->users} u ON pi.user_id = u.ID
                    WHERE u.ID IS NULL
                ");
                $results['orphaned_interests_deleted'] = $deleted_interests !== false ? $deleted_interests : 0;
            } catch (Exception $e) {
                $results['errors'][] = 'orphaned_interests: ' . $e->getMessage();
            }
        }

        // 7. Recalculate engagement scores for all active clients
        try {
            // Ensure calculator is loaded
            if (!class_exists('MLD_Engagement_Score_Calculator')) {
                require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
            }

            $active_clients = $wpdb->get_col("
                SELECT DISTINCT client_id
                FROM {$relationships_table}
                WHERE relationship_status = 'active'
            ");

            foreach ($active_clients as $client_id) {
                MLD_Engagement_Score_Calculator::calculate_and_store((int) $client_id);
            }

            $results['scores_recalculated'] = count($active_clients);
        } catch (Exception $e) {
            $results['errors'][] = 'recalculation: ' . $e->getMessage();
        }

        // Log results
        error_log('MLD Data Cleanup Results: ' . json_encode($results));

        return $results;
    }

    /**
     * Run cleanup for a specific agent's clients only
     *
     * Useful for targeted cleanup after agent reassignments.
     *
     * @since 6.41.0
     * @param int $agent_id The agent's user ID
     * @return array Results summary
     */
    public static function run_agent_cleanup($agent_id) {
        global $wpdb;

        $results = array(
            'clients_found' => 0,
            'scores_recalculated' => 0,
            'errors' => array(),
        );

        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';

        // Get all active clients for this agent
        $clients = $wpdb->get_col($wpdb->prepare("
            SELECT client_id
            FROM {$relationships_table}
            WHERE agent_id = %d AND relationship_status = 'active'
        ", $agent_id));

        $results['clients_found'] = count($clients);

        if (empty($clients)) {
            return $results;
        }

        // Ensure calculator is loaded
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
        }

        // Update agent_id and recalculate scores for each client
        foreach ($clients as $client_id) {
            try {
                // Ensure score record has correct agent_id
                $wpdb->query($wpdb->prepare("
                    UPDATE {$scores_table}
                    SET agent_id = %d
                    WHERE user_id = %d
                ", $agent_id, $client_id));

                // Recalculate score
                MLD_Engagement_Score_Calculator::calculate_and_store((int) $client_id);
                $results['scores_recalculated']++;
            } catch (Exception $e) {
                $results['errors'][] = "client_{$client_id}: " . $e->getMessage();
            }
        }

        error_log("MLD Agent Cleanup ({$agent_id}): " . json_encode($results));

        return $results;
    }

    /**
     * Get cleanup status/preview without making changes
     *
     * @since 6.41.0
     * @return array Preview of what would be cleaned up
     */
    public static function get_cleanup_preview() {
        global $wpdb;

        $preview = array(
            'orphaned_scores' => 0,
            'nonexistent_user_scores' => 0,
            'old_inactive_relationships' => 0,
            'orphaned_activity' => 0,
            'orphaned_sessions' => 0,
            'orphaned_interests' => 0,
            'active_clients_needing_recalc' => 0,
        );

        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        $interest_table = $wpdb->prefix . 'mld_client_property_interest';

        // Count orphaned scores
        $preview['orphaned_scores'] = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$scores_table} es
            WHERE es.agent_id IS NULL
               OR es.agent_id = 0
               OR es.agent_id NOT IN (
                   SELECT DISTINCT agent_id
                   FROM {$relationships_table}
                   WHERE relationship_status = 'active'
               )
        ");

        // Count scores for nonexistent users
        $preview['nonexistent_user_scores'] = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$scores_table} es
            LEFT JOIN {$wpdb->users} u ON es.user_id = u.ID
            WHERE u.ID IS NULL
        ");

        // Count old inactive relationships
        $preview['old_inactive_relationships'] = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$relationships_table}
            WHERE relationship_status = 'inactive'
            AND assigned_date < DATE_SUB(NOW(), INTERVAL 6 MONTH)
        ");

        // Count orphaned activity
        if ($wpdb->get_var("SHOW TABLES LIKE '{$activity_table}'") === $activity_table) {
            $preview['orphaned_activity'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$activity_table} a
                LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
                WHERE u.ID IS NULL
            ");
        }

        // Count orphaned sessions
        if ($wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table) {
            $preview['orphaned_sessions'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$sessions_table} s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE s.user_id IS NOT NULL AND u.ID IS NULL
            ");
        }

        // Count orphaned interests
        if ($wpdb->get_var("SHOW TABLES LIKE '{$interest_table}'") === $interest_table) {
            $preview['orphaned_interests'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$interest_table} pi
                LEFT JOIN {$wpdb->users} u ON pi.user_id = u.ID
                WHERE u.ID IS NULL
            ");
        }

        // Count active clients
        $preview['active_clients_needing_recalc'] = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT client_id)
            FROM {$relationships_table}
            WHERE relationship_status = 'active'
        ");

        return $preview;
    }

    /**
     * Register admin action for cleanup
     *
     * @since 6.41.0
     */
    public static function register_admin_action() {
        add_action('admin_post_mld_run_data_cleanup', array(__CLASS__, 'handle_admin_cleanup'));
    }

    /**
     * Handle admin cleanup action
     *
     * @since 6.41.0
     */
    public static function handle_admin_cleanup() {
        // Verify user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'mls-listings-display'));
        }

        // Verify nonce
        check_admin_referer('mld_data_cleanup_action');

        // Run cleanup
        $results = self::run_cleanup();

        // Store results in transient for display
        set_transient('mld_cleanup_results', $results, 60);

        // Redirect back to admin page
        wp_redirect(add_query_arg(
            array(
                'page' => 'mld-analytics',
                'cleanup' => 'complete',
            ),
            admin_url('admin.php')
        ));
        exit;
    }
}
