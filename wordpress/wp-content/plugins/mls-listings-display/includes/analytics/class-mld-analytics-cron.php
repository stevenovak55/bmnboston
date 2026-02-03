<?php
/**
 * Analytics Cron Jobs
 *
 * Handles scheduled aggregation of client activity data
 *
 * @package MLS_Listings_Display
 * @since 6.37.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Analytics_Cron {

    /**
     * Initialize cron hooks
     */
    public static function init() {
        // Register cron schedules
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedules'));

        // Register cron hooks
        add_action('mld_hourly_analytics_aggregation', array(__CLASS__, 'hourly_aggregation'));
        add_action('mld_daily_analytics_summary', array(__CLASS__, 'daily_summary'));
        add_action('mld_session_cleanup', array(__CLASS__, 'session_cleanup'));

        // New v6.40.0 cron hooks
        add_action('mld_daily_engagement_scores', array(__CLASS__, 'calculate_all_engagement_scores'));
        add_action('mld_hourly_property_interest', array(__CLASS__, 'aggregate_property_interests'));

        // Schedule crons if not already scheduled
        if (!wp_next_scheduled('mld_hourly_analytics_aggregation')) {
            wp_schedule_event(time(), 'hourly', 'mld_hourly_analytics_aggregation');
        }

        if (!wp_next_scheduled('mld_daily_analytics_summary')) {
            wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', 'mld_daily_analytics_summary');
        }

        if (!wp_next_scheduled('mld_session_cleanup')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'mld_session_cleanup');
        }

        // Schedule new v6.40.0 crons
        if (!wp_next_scheduled('mld_daily_engagement_scores')) {
            wp_schedule_event(strtotime('tomorrow 4:00am'), 'daily', 'mld_daily_engagement_scores');
        }

        if (!wp_next_scheduled('mld_hourly_property_interest')) {
            wp_schedule_event(time(), 'hourly', 'mld_hourly_property_interest');
        }
    }

    /**
     * Add custom cron schedules
     */
    public static function add_cron_schedules($schedules) {
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 Minutes', 'mls-listings-display'),
        );
        return $schedules;
    }

    /**
     * Hourly aggregation - Update session statistics
     *
     * Calculates activity counts and duration for active sessions
     */
    public static function hourly_aggregation() {
        global $wpdb;

        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';

        // Update session statistics from activity data
        $wpdb->query("
            UPDATE {$sessions_table} s
            SET
                activity_count = (
                    SELECT COUNT(*)
                    FROM {$activity_table} a
                    WHERE a.session_id = s.session_id
                ),
                properties_viewed = (
                    SELECT COUNT(*)
                    FROM {$activity_table} a
                    WHERE a.session_id = s.session_id
                    AND a.activity_type = 'property_view'
                ),
                searches_run = (
                    SELECT COUNT(*)
                    FROM {$activity_table} a
                    WHERE a.session_id = s.session_id
                    AND a.activity_type = 'search_run'
                )
            WHERE s.ended_at IS NULL
        ");

        error_log('[MLD Analytics] Hourly aggregation completed');
    }

    /**
     * Daily summary - Calculate engagement scores and aggregate stats
     *
     * Runs at 3am daily to calculate engagement scores for the previous day
     */
    public static function daily_summary() {
        global $wpdb;

        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        $summary_table = $wpdb->prefix . 'mld_client_analytics_summary';

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Get all users with activity yesterday
        $users = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT user_id
            FROM {$activity_table}
            WHERE DATE(created_at) = %s
        ", $yesterday));

        foreach ($users as $user_id) {
            // Calculate session stats
            $session_stats = $wpdb->get_row($wpdb->prepare("
                SELECT
                    COUNT(*) as total_sessions,
                    COALESCE(SUM(duration_seconds), 0) as total_duration,
                    COALESCE(SUM(properties_viewed), 0) as properties_viewed,
                    COALESCE(SUM(searches_run), 0) as searches_run
                FROM {$sessions_table}
                WHERE user_id = %d
                AND DATE(started_at) = %s
            ", $user_id, $yesterday));

            // Calculate activity breakdown
            $activities = $wpdb->get_results($wpdb->prepare("
                SELECT
                    activity_type,
                    COUNT(*) as count
                FROM {$activity_table}
                WHERE user_id = %d
                AND DATE(created_at) = %s
                GROUP BY activity_type
            ", $user_id, $yesterday));

            $activity_counts = array();
            foreach ($activities as $activity) {
                $activity_counts[$activity->activity_type] = (int) $activity->count;
            }

            // Calculate unique properties viewed
            $unique_properties = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT entity_id)
                FROM {$activity_table}
                WHERE user_id = %d
                AND DATE(created_at) = %s
                AND activity_type = 'property_view'
            ", $user_id, $yesterday));

            // Get most viewed cities
            $cities = $wpdb->get_results($wpdb->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.city')) as city,
                    COUNT(*) as view_count
                FROM {$activity_table}
                WHERE user_id = %d
                AND DATE(created_at) = %s
                AND activity_type = 'property_view'
                AND metadata IS NOT NULL
                GROUP BY JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.city'))
                ORDER BY view_count DESC
                LIMIT 5
            ", $user_id, $yesterday));

            $most_viewed_cities = array();
            foreach ($cities as $city) {
                if ($city->city) {
                    $most_viewed_cities[$city->city] = (int) $city->view_count;
                }
            }

            // Calculate engagement score
            $engagement_score = self::calculate_engagement_score(
                (int) $session_stats->total_duration / 60, // Convert to minutes
                (int) $session_stats->properties_viewed,
                (int) $session_stats->searches_run,
                $activity_counts['favorite_add'] ?? 0,
                $activity_counts['filter_used'] ?? 0
            );

            // Insert or update summary
            $wpdb->query($wpdb->prepare("
                INSERT INTO {$summary_table}
                (user_id, summary_date, total_sessions, total_duration_seconds,
                 properties_viewed, unique_properties_viewed, searches_run,
                 favorites_added, engagement_score, most_viewed_cities)
                VALUES (%d, %s, %d, %d, %d, %d, %d, %d, %f, %s)
                ON DUPLICATE KEY UPDATE
                    total_sessions = VALUES(total_sessions),
                    total_duration_seconds = VALUES(total_duration_seconds),
                    properties_viewed = VALUES(properties_viewed),
                    unique_properties_viewed = VALUES(unique_properties_viewed),
                    searches_run = VALUES(searches_run),
                    favorites_added = VALUES(favorites_added),
                    engagement_score = VALUES(engagement_score),
                    most_viewed_cities = VALUES(most_viewed_cities)
            ",
                $user_id,
                $yesterday,
                $session_stats->total_sessions,
                $session_stats->total_duration,
                $session_stats->properties_viewed,
                $unique_properties,
                $session_stats->searches_run,
                $activity_counts['favorite_add'] ?? 0,
                $engagement_score,
                json_encode($most_viewed_cities)
            ));
        }

        error_log('[MLD Analytics] Daily summary completed for ' . count($users) . ' users');
    }

    /**
     * Session cleanup - End stale sessions
     *
     * Marks sessions as ended if no activity for 30 minutes
     */
    public static function session_cleanup() {
        global $wpdb;

        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';

        $thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));

        // Find sessions with no recent activity
        $stale_sessions = $wpdb->get_results($wpdb->prepare("
            SELECT s.id, s.session_id, s.started_at,
                   (SELECT MAX(created_at) FROM {$activity_table} WHERE session_id = s.session_id) as last_activity
            FROM {$sessions_table} s
            WHERE s.ended_at IS NULL
            AND (
                SELECT MAX(created_at)
                FROM {$activity_table}
                WHERE session_id = s.session_id
            ) < %s
        ", $thirty_minutes_ago));

        foreach ($stale_sessions as $session) {
            $last_activity = $session->last_activity ?: $session->started_at;
            $started = strtotime($session->started_at);
            $ended = strtotime($last_activity);
            $duration = max(0, $ended - $started);

            // End the session
            $wpdb->update(
                $sessions_table,
                array(
                    'ended_at' => $last_activity,
                    'duration_seconds' => $duration,
                ),
                array('id' => $session->id),
                array('%s', '%d'),
                array('%d')
            );
        }

        if (count($stale_sessions) > 0) {
            error_log('[MLD Analytics] Ended ' . count($stale_sessions) . ' stale sessions');
        }
    }

    /**
     * Calculate engagement score (0-100)
     *
     * @param float $minutes Session time in minutes
     * @param int $properties_viewed Number of properties viewed
     * @param int $searches_run Number of searches run
     * @param int $favorites_added Number of favorites added
     * @param int $filters_used Number of filters used
     * @return float Engagement score 0-100
     */
    public static function calculate_engagement_score($minutes, $properties_viewed, $searches_run, $favorites_added, $filters_used) {
        // Score breakdown (max 100):
        // - Session time: max 30 points (0.5 per minute, capped at 60 minutes)
        // - Properties viewed: max 25 points (2 per property, capped at 12.5)
        // - Searches run: max 20 points (4 per search, capped at 5)
        // - Favorites added: max 15 points (5 per favorite, capped at 3)
        // - Filters used: max 10 points (1 per filter, capped at 10)

        $time_score = min(30, $minutes * 0.5);
        $properties_score = min(25, $properties_viewed * 2);
        $searches_score = min(20, $searches_run * 4);
        $favorites_score = min(15, $favorites_added * 5);
        $filters_score = min(10, $filters_used * 1);

        return round($time_score + $properties_score + $searches_score + $favorites_score + $filters_score, 2);
    }

    /**
     * Calculate engagement scores for all active clients
     *
     * Runs daily at 4am. Uses the new MLD_Engagement_Score_Calculator
     * to calculate comprehensive engagement scores for all users with
     * recent activity.
     *
     * @since 6.40.0
     */
    public static function calculate_all_engagement_scores() {
        global $wpdb;

        // Check if calculator class exists
        if (!class_exists('MLD_Engagement_Score_Calculator')) {
            error_log('[MLD Analytics] Engagement score calculator class not found');
            return;
        }

        $activity_table = $wpdb->prefix . 'mld_client_activity';

        // Get all users with activity in the last 30 days
        $thirty_days_ago = date('Y-m-d H:i:s', current_time('timestamp') - (30 * DAY_IN_SECONDS));

        $user_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT user_id
            FROM {$activity_table}
            WHERE created_at >= %s
        ", $thirty_days_ago));

        if (empty($user_ids)) {
            error_log('[MLD Analytics] No users with recent activity to calculate scores');
            return;
        }

        $start_time = microtime(true);
        $success_count = 0;
        $error_count = 0;

        foreach ($user_ids as $user_id) {
            try {
                $score = MLD_Engagement_Score_Calculator::calculate_and_store((int) $user_id);
                if ($score !== false) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            } catch (Exception $e) {
                error_log('[MLD Analytics] Error calculating score for user ' . $user_id . ': ' . $e->getMessage());
                $error_count++;
            }
        }

        $duration = round(microtime(true) - $start_time, 2);
        error_log("[MLD Analytics] Daily engagement scores completed: {$success_count} calculated, {$error_count} errors in {$duration}s");
    }

    /**
     * Aggregate property interest data from activity events
     *
     * Runs hourly. Processes property_view events and updates
     * the property interest table with view counts and durations.
     *
     * @since 6.40.0
     */
    public static function aggregate_property_interests() {
        global $wpdb;

        // Check if tracker class exists
        if (!class_exists('MLD_Property_Interest_Tracker')) {
            error_log('[MLD Analytics] Property interest tracker class not found');
            return;
        }

        $activity_table = $wpdb->prefix . 'mld_client_activity';

        // Get property views from the last hour that haven't been processed
        $one_hour_ago = date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS);
        $now = current_time('mysql');

        // Get aggregated view data per user/property from the last hour
        $views = $wpdb->get_results($wpdb->prepare("
            SELECT
                user_id,
                entity_id as listing_id,
                COUNT(*) as view_count,
                SUM(CASE
                    WHEN metadata IS NOT NULL AND JSON_VALID(metadata)
                    THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.duration')), 0)
                    ELSE 0
                END) as total_duration
            FROM {$activity_table}
            WHERE activity_type = 'property_view'
            AND created_at >= %s
            AND created_at < %s
            AND entity_id IS NOT NULL
            AND entity_id != ''
            GROUP BY user_id, entity_id
        ", $one_hour_ago, $now));

        if (empty($views)) {
            return; // No views to process
        }

        $start_time = microtime(true);
        $processed = 0;

        foreach ($views as $view) {
            if (empty($view->user_id) || empty($view->listing_id)) {
                continue;
            }

            // Update the property interest tracker
            $result = MLD_Property_Interest_Tracker::update_interest(
                (int) $view->user_id,
                $view->listing_id,
                (int) $view->view_count,
                (int) $view->total_duration
            );

            if ($result) {
                $processed++;
            }
        }

        // Also process other high-value actions
        self::aggregate_property_actions($one_hour_ago, $now);

        $duration = round(microtime(true) - $start_time, 2);
        if ($processed > 0) {
            error_log("[MLD Analytics] Hourly property interest aggregation: {$processed} property views processed in {$duration}s");
        }
    }

    /**
     * Aggregate high-value property actions (calculator, contact, favorite)
     *
     * @param string $start_time Start time for activity query
     * @param string $end_time End time for activity query
     * @since 6.40.0
     */
    private static function aggregate_property_actions($start_time, $end_time) {
        global $wpdb;

        $activity_table = $wpdb->prefix . 'mld_client_activity';

        // Map activity types to property interest action names
        $action_map = array(
            'calculator_use' => 'calculator_used',
            'contact_click' => 'contact_clicked',
            'favorite_add' => 'favorited',
            'property_share' => 'shared',
        );

        foreach ($action_map as $activity_type => $action_name) {
            $actions = $wpdb->get_results($wpdb->prepare("
                SELECT user_id, entity_id as listing_id
                FROM {$activity_table}
                WHERE activity_type = %s
                AND created_at >= %s
                AND created_at < %s
                AND entity_id IS NOT NULL
                AND entity_id != ''
            ", $activity_type, $start_time, $end_time));

            foreach ($actions as $action) {
                if (empty($action->user_id) || empty($action->listing_id)) {
                    continue;
                }

                MLD_Property_Interest_Tracker::record_action(
                    (int) $action->user_id,
                    $action->listing_id,
                    $action_name
                );
            }
        }
    }

    /**
     * Unschedule all cron jobs (for plugin deactivation)
     */
    public static function unschedule_all() {
        wp_clear_scheduled_hook('mld_hourly_analytics_aggregation');
        wp_clear_scheduled_hook('mld_daily_analytics_summary');
        wp_clear_scheduled_hook('mld_session_cleanup');
        wp_clear_scheduled_hook('mld_daily_engagement_scores');
        wp_clear_scheduled_hook('mld_hourly_property_interest');
    }
}
