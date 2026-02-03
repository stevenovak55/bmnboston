<?php
/**
 * Client Analytics Hooks
 *
 * Handles hooks for agent-client events to ensure data integrity
 * and real-time engagement score updates.
 *
 * @package MLS_Listings_Display
 * @since 6.41.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Client_Analytics_Hooks {

    /**
     * Initialize all hooks
     *
     * @since 6.41.0
     */
    public static function init() {
        // Assignment hooks - fire when agent assigned to client
        add_action('mld_agent_assigned_to_client', array(__CLASS__, 'on_client_assigned'), 10, 3);
        add_action('mld_client_agent_changed', array(__CLASS__, 'on_agent_changed'), 10, 3);

        // Activity hooks - real-time score updates
        add_action('mld_client_activity_recorded', array(__CLASS__, 'on_activity'), 10, 3);

        // Cleanup hooks - when users are deleted
        add_action('delete_user', array(__CLASS__, 'on_user_deleted'), 10, 1);

        // Scheduled recalculation handler
        add_action('mld_recalculate_score', array(__CLASS__, 'handle_scheduled_recalculation'), 10, 1);
    }

    /**
     * Handle client assignment to agent
     *
     * Called when an agent is assigned to a client for the first time
     * or when a new agent is assigned (replacing the old one).
     *
     * @since 6.41.0
     * @param int   $agent_id  The agent's user ID
     * @param int   $client_id The client's user ID
     * @param array $options   Additional options from assignment
     */
    public static function on_client_assigned($agent_id, $client_id, $options = array()) {
        global $wpdb;

        // Ensure calculator is loaded
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
        }

        // Update agent_id in engagement scores table if record exists
        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$scores_table} WHERE user_id = %d",
            $client_id
        ));

        if ($existing) {
            // Update the agent_id to the new agent
            $wpdb->update(
                $scores_table,
                array('agent_id' => $agent_id),
                array('user_id' => $client_id),
                array('%d'),
                array('%d')
            );
        }

        // Calculate initial engagement score for this client
        MLD_Engagement_Score_Calculator::calculate_and_store($client_id);

        // Log the assignment for debugging
        error_log("MLD Analytics: Client {$client_id} assigned to agent {$agent_id}, engagement score calculated");
    }

    /**
     * Handle agent change for client
     *
     * Called when a client is reassigned from one agent to another.
     *
     * @since 6.41.0
     * @param int $client_id    The client's user ID
     * @param int $old_agent_id The previous agent's user ID
     * @param int $new_agent_id The new agent's user ID
     */
    public static function on_agent_changed($client_id, $old_agent_id, $new_agent_id) {
        global $wpdb;

        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';

        // Update agent_id in engagement scores table
        $wpdb->update(
            $scores_table,
            array('agent_id' => $new_agent_id),
            array('user_id' => $client_id),
            array('%d'),
            array('%d')
        );

        // Log the change
        error_log("MLD Analytics: Client {$client_id} reassigned from agent {$old_agent_id} to agent {$new_agent_id}");
    }

    /**
     * Handle activity recorded for client
     *
     * Called when a client activity is recorded. Queues a debounced
     * score recalculation to avoid excessive processing.
     *
     * @since 6.41.0
     * @param int    $user_id       The user's ID
     * @param string $activity_type The type of activity
     * @param array  $metadata      Additional activity metadata
     */
    public static function on_activity($user_id, $activity_type, $metadata = array()) {
        // Check if user is a client (has an agent assigned)
        global $wpdb;
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';

        $is_client = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relationships_table}
             WHERE client_id = %d AND relationship_status = 'active'",
            $user_id
        ));

        if (!$is_client) {
            return; // Not a client, skip score recalculation
        }

        // Queue debounced recalculation
        self::queue_recalculation($user_id);
    }

    /**
     * Queue a debounced score recalculation
     *
     * Uses transients to debounce (avoid duplicate calculations within 60 seconds).
     * Schedules the actual calculation via wp_schedule_single_event.
     *
     * @since 6.41.0
     * @param int $user_id The user's ID
     */
    public static function queue_recalculation($user_id) {
        $transient_key = 'mld_score_pending_' . $user_id;

        // Check if already queued (debounce)
        if (get_transient($transient_key)) {
            return; // Already queued, skip
        }

        // Set debounce flag (60 seconds)
        set_transient($transient_key, time(), 60);

        // Schedule calculation in 60 seconds
        if (!wp_next_scheduled('mld_recalculate_score', array($user_id))) {
            wp_schedule_single_event(time() + 60, 'mld_recalculate_score', array($user_id));
        }
    }

    /**
     * Handle scheduled score recalculation
     *
     * Called by wp_schedule_single_event after the debounce period.
     *
     * @since 6.41.0
     * @param int $user_id The user's ID
     */
    public static function handle_scheduled_recalculation($user_id) {
        // Clear debounce flag
        delete_transient('mld_score_pending_' . $user_id);

        // Ensure calculator is loaded
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/class-mld-engagement-score-calculator.php';
        }

        // Calculate and store the score
        MLD_Engagement_Score_Calculator::calculate_and_store($user_id);
    }

    /**
     * Handle user deletion
     *
     * Cleans up orphaned records when a user is deleted.
     *
     * @since 6.41.0
     * @param int $user_id The deleted user's ID
     */
    public static function on_user_deleted($user_id) {
        global $wpdb;

        // Delete engagement scores for this user
        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $wpdb->delete($scores_table, array('user_id' => $user_id), array('%d'));

        // Delete property interest records for this user
        $interest_table = $wpdb->prefix . 'mld_client_property_interest';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$interest_table}'") === $interest_table) {
            $wpdb->delete($interest_table, array('user_id' => $user_id), array('%d'));
        }

        // Delete client activity records for this user
        $activity_table = $wpdb->prefix . 'mld_client_activity';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$activity_table}'") === $activity_table) {
            $wpdb->delete($activity_table, array('user_id' => $user_id), array('%d'));
        }

        // Delete client sessions for this user
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$sessions_table}'") === $sessions_table) {
            $wpdb->delete($sessions_table, array('user_id' => $user_id), array('%d'));
        }

        // Note: Agent-client relationships are handled by MLD_Agent_Client_Manager

        error_log("MLD Analytics: Cleaned up analytics data for deleted user {$user_id}");
    }
}
