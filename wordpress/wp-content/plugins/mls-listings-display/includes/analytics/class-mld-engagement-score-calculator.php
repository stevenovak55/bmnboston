<?php
/**
 * MLS Listings Display - Engagement Score Calculator
 *
 * Calculates client engagement scores based on activity data.
 * Score ranges from 0-100 with component breakdown.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.40.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Engagement_Score_Calculator {

    /**
     * Maximum possible engagement score
     */
    const MAX_SCORE = 100;

    /**
     * Daily decay rate for recency calculation
     */
    const DECAY_RATE = 0.95;

    /**
     * Component weight constants (must sum to 100)
     */
    const WEIGHT_TIME = 25;       // Time investment
    const WEIGHT_VIEWS = 25;      // View depth
    const WEIGHT_SEARCH = 20;     // Search behavior
    const WEIGHT_INTENT = 20;     // Intent signals
    const WEIGHT_FREQUENCY = 10;  // Frequency/recency

    /**
     * Calculate engagement score for a user
     *
     * @param int $user_id User ID
     * @param int $days Number of days to analyze (default 30)
     * @return array Score data with component breakdown
     */
    public static function calculate_score($user_id, $days = 30) {
        $components = self::calculate_component_scores($user_id, $days);

        $base_score =
            $components['time_score'] +
            $components['view_score'] +
            $components['search_score'] +
            $components['intent_score'] +
            $components['frequency_score'];

        // Apply recency decay
        $days_since_activity = $components['days_since_activity'];
        $final_score = self::apply_recency_decay($base_score, $days_since_activity);

        return array(
            'score' => round($final_score, 2),
            'base_score' => round($base_score, 2),
            'components' => $components,
            'days_since_activity' => $days_since_activity,
            'last_activity_at' => $components['last_activity_at']
        );
    }

    /**
     * Calculate individual component scores
     *
     * @param int $user_id User ID
     * @param int $days Number of days to analyze
     * @return array Component scores
     */
    public static function calculate_component_scores($user_id, $days = 30) {
        global $wpdb;

        $activity_table = $wpdb->prefix . 'mld_client_activity';
        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        $preferences_table = $wpdb->prefix . 'mld_property_preferences';

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Get session data (time investment)
        $session_data = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as session_count,
                SUM(duration_seconds) as total_duration,
                SUM(properties_viewed) as properties_viewed,
                MAX(started_at) as last_session
            FROM {$sessions_table}
            WHERE user_id = %d
            AND started_at >= %s
        ", $user_id, $date_threshold));

        // Get activity counts by type
        $activity_counts = $wpdb->get_results($wpdb->prepare("
            SELECT
                activity_type,
                COUNT(*) as count
            FROM {$activity_table}
            WHERE user_id = %d
            AND created_at >= %s
            GROUP BY activity_type
        ", $user_id, $date_threshold), OBJECT_K);

        // Get unique properties viewed
        $unique_properties = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT entity_id)
            FROM {$activity_table}
            WHERE user_id = %d
            AND created_at >= %s
            AND activity_type = 'property_view'
            AND entity_type = 'property'
        ", $user_id, $date_threshold));

        // Get favorites count
        $favorites_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$preferences_table}
            WHERE user_id = %d
            AND preference_type = 'liked'
        ", $user_id));

        // Get time on detail pages (from metadata)
        $detail_time = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(JSON_EXTRACT(metadata, '$.duration_seconds'))
            FROM {$activity_table}
            WHERE user_id = %d
            AND created_at >= %s
            AND activity_type = 'time_on_page'
            AND entity_type = 'property'
        ", $user_id, $date_threshold));

        // Get sessions in last 7 days (for frequency)
        $recent_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$sessions_table}
            WHERE user_id = %d
            AND started_at >= %s
        ", $user_id, date('Y-m-d H:i:s', strtotime('-7 days'))));

        // Get last activity time
        $last_activity = $wpdb->get_var($wpdb->prepare("
            SELECT MAX(created_at)
            FROM {$activity_table}
            WHERE user_id = %d
        ", $user_id));

        $days_since_activity = $last_activity
            ? floor((time() - strtotime($last_activity)) / 86400)
            : 999;

        // Calculate component scores
        $time_score = self::get_time_score(
            (int) ($session_data->total_duration ?? 0),
            (int) ($detail_time ?? 0)
        );

        $view_score = self::get_view_score(
            (int) ($unique_properties ?? 0),
            self::get_activity_count($activity_counts, 'photo_view'),
            self::get_activity_count($activity_counts, 'calculator_use'),
            self::get_activity_count($activity_counts, 'school_info_view')
        );

        $search_score = self::get_search_score(
            self::get_activity_count($activity_counts, 'search_run') +
            self::get_activity_count($activity_counts, 'search_execute'),
            self::get_activity_count($activity_counts, 'filter_apply') +
            self::get_activity_count($activity_counts, 'filter_used'),
            self::get_activity_count($activity_counts, 'search_save')
        );

        $intent_score = self::get_intent_score(
            (int) $favorites_count,
            self::get_activity_count($activity_counts, 'contact_click'),
            self::get_activity_count($activity_counts, 'schedule_click') +
            self::get_activity_count($activity_counts, 'schedule_showing_click')
        );

        $frequency_score = self::get_frequency_score(
            (int) $recent_sessions,
            $days_since_activity
        );

        return array(
            'time_score' => $time_score,
            'view_score' => $view_score,
            'search_score' => $search_score,
            'intent_score' => $intent_score,
            'frequency_score' => $frequency_score,
            'days_since_activity' => $days_since_activity,
            'last_activity_at' => $last_activity,
            'raw_data' => array(
                'session_count' => (int) ($session_data->session_count ?? 0),
                'total_duration_seconds' => (int) ($session_data->total_duration ?? 0),
                'properties_viewed' => (int) ($unique_properties ?? 0),
                'searches_run' => self::get_activity_count($activity_counts, 'search_run') +
                                  self::get_activity_count($activity_counts, 'search_execute'),
                'favorites_count' => (int) $favorites_count,
                'recent_sessions_7d' => (int) $recent_sessions
            )
        );
    }

    /**
     * Get activity count from results array
     *
     * @param array $activity_counts Activity counts by type
     * @param string $type Activity type
     * @return int Count
     */
    private static function get_activity_count($activity_counts, $type) {
        return isset($activity_counts[$type]) ? (int) $activity_counts[$type]->count : 0;
    }

    /**
     * Calculate time investment score (max 25 points)
     *
     * @param int $session_duration Total session duration in seconds
     * @param int $detail_time Time on property detail pages in seconds
     * @return float Score (0-25)
     */
    private static function get_time_score($session_duration, $detail_time) {
        $session_minutes = $session_duration / 60;
        $detail_minutes = $detail_time / 60;

        // 0.5 points per minute of session time, capped at 60 minutes = 30 * 0.5 = 15 points
        // But we cap at weight value
        $session_points = min($session_minutes * 0.5, 15);

        // 1 point per minute on detail pages, capped at 10 points
        $detail_points = min($detail_minutes * 1, 10);

        return min($session_points + $detail_points, self::WEIGHT_TIME);
    }

    /**
     * Calculate view depth score (max 25 points)
     *
     * @param int $unique_properties Unique properties viewed
     * @param int $photo_views Number of photo views
     * @param int $calculator_uses Calculator usage count
     * @param int $school_views School info views
     * @return float Score (0-25)
     */
    private static function get_view_score($unique_properties, $photo_views, $calculator_uses, $school_views) {
        // 2 points per unique property, capped at 10 properties = 20 points
        $property_points = min($unique_properties * 2, 20);

        // 0.5 points per photo beyond first per property, capped at 5 points
        $photo_points = min($photo_views * 0.1, 5);

        // 3 points for using calculator (capped at 1)
        $calculator_points = min($calculator_uses, 1) * 3;

        // 2 points for viewing school info (capped at 1)
        $school_points = min($school_views, 1) * 2;

        return min($property_points + $photo_points + $calculator_points + $school_points, self::WEIGHT_VIEWS);
    }

    /**
     * Calculate search behavior score (max 20 points)
     *
     * @param int $searches_run Searches executed
     * @param int $filters_applied Filters used
     * @param int $saved_searches Searches saved
     * @return float Score (0-20)
     */
    private static function get_search_score($searches_run, $filters_applied, $saved_searches) {
        // 3 points per search, capped at 5 searches = 15 points
        $search_points = min($searches_run * 3, 15);

        // 0.5 points per filter, capped at 10 points
        $filter_points = min($filters_applied * 0.5, 5);

        // 5 points per saved search, capped at 2 = 10 points
        $saved_points = min($saved_searches * 5, 10);

        return min($search_points + $filter_points + $saved_points, self::WEIGHT_SEARCH);
    }

    /**
     * Calculate intent signals score (max 20 points)
     *
     * @param int $favorites Properties favorited
     * @param int $contact_clicks Contact button clicks
     * @param int $showing_requests Showing requests
     * @return float Score (0-20)
     */
    private static function get_intent_score($favorites, $contact_clicks, $showing_requests) {
        // 4 points per favorite, capped at 4 = 16 points
        $favorite_points = min($favorites * 4, 16);

        // 5 points per contact click, capped at 3 = 15 points
        $contact_points = min($contact_clicks * 5, 15);

        // 10 points for scheduling showing (very strong intent)
        $showing_points = min($showing_requests, 1) * 10;

        return min($favorite_points + $contact_points + $showing_points, self::WEIGHT_INTENT);
    }

    /**
     * Calculate frequency score (max 10 points)
     *
     * @param int $recent_sessions Sessions in last 7 days
     * @param int $days_since_activity Days since last activity
     * @return float Score (0-10)
     */
    private static function get_frequency_score($recent_sessions, $days_since_activity) {
        // 2 points per session in last 7 days, capped at 5 sessions = 10 points
        $session_points = min($recent_sessions * 2, 10);

        // Reduce if inactive
        if ($days_since_activity > 7) {
            $session_points = $session_points * 0.5;
        }

        return min($session_points, self::WEIGHT_FREQUENCY);
    }

    /**
     * Apply recency decay to base score
     *
     * @param float $base_score Base engagement score
     * @param int $days_since_activity Days since last activity
     * @return float Decayed score
     */
    public static function apply_recency_decay($base_score, $days_since_activity) {
        if ($days_since_activity <= 0) {
            return $base_score;
        }

        // Apply exponential decay
        $decay_factor = pow(self::DECAY_RATE, $days_since_activity);

        return $base_score * $decay_factor;
    }

    /**
     * Calculate score trend (rising, falling, stable)
     *
     * @param int $user_id User ID
     * @return array Trend data
     */
    public static function calculate_score_trend($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_engagement_scores';

        // Get current score
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT score, calculated_at
            FROM {$table}
            WHERE user_id = %d
            ORDER BY calculated_at DESC
            LIMIT 1
        ", $user_id));

        if (!$current) {
            return array(
                'trend' => 'stable',
                'change' => 0.00
            );
        }

        // Calculate new score
        $new_score_data = self::calculate_score($user_id);
        $new_score = $new_score_data['score'];
        $old_score = (float) $current->score;

        $change = $new_score - $old_score;

        // Determine trend
        $trend = 'stable';
        if ($change > 2) {
            $trend = 'rising';
        } elseif ($change < -2) {
            $trend = 'falling';
        }

        return array(
            'trend' => $trend,
            'change' => round($change, 2),
            'old_score' => $old_score,
            'new_score' => $new_score
        );
    }

    /**
     * Calculate and store score for a user
     *
     * @param int $user_id User ID
     * @return bool Success
     */
    public static function calculate_and_store($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_engagement_scores';
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';

        // Calculate current score
        $score_data = self::calculate_score($user_id);
        $components = $score_data['components'];

        // Get trend data
        $trend_data = self::calculate_score_trend($user_id);

        // Get agent ID for this client
        $agent_id = $wpdb->get_var($wpdb->prepare("
            SELECT agent_id
            FROM {$relationships_table}
            WHERE client_id = %d
            AND relationship_status = 'active'
            LIMIT 1
        ", $user_id));

        // Upsert score
        $result = $wpdb->replace(
            $table,
            array(
                'user_id' => $user_id,
                'agent_id' => $agent_id,
                'score' => $score_data['score'],
                'score_trend' => $trend_data['trend'],
                'trend_change' => $trend_data['change'],
                'last_activity_at' => $components['last_activity_at'],
                'days_since_activity' => $components['days_since_activity'],
                'time_score' => $components['time_score'],
                'view_score' => $components['view_score'],
                'search_score' => $components['search_score'],
                'engagement_score' => $components['intent_score'], // Using intent as "engagement"
                'frequency_score' => $components['frequency_score'],
                'calculated_at' => current_time('mysql')
            ),
            array('%d', '%d', '%f', '%s', '%f', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%s')
        );

        return $result !== false;
    }

    /**
     * Batch calculate scores for multiple users
     *
     * @param array $user_ids Array of user IDs
     * @return int Number of scores updated
     */
    public static function batch_calculate_scores($user_ids) {
        $updated = 0;

        foreach ($user_ids as $user_id) {
            if (self::calculate_and_store($user_id)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get all clients that need score calculation
     *
     * @param int $days_threshold Only include clients active in last X days
     * @return array User IDs
     */
    public static function get_clients_needing_calculation($days_threshold = 30) {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'mld_client_sessions';
        $user_types_table = $wpdb->prefix . 'mld_user_types';

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days_threshold} days"));

        return $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT s.user_id
            FROM {$sessions_table} s
            LEFT JOIN {$user_types_table} ut ON s.user_id = ut.user_id
            WHERE s.started_at >= %s
            AND (ut.user_type IS NULL OR ut.user_type = 'client')
        ", $date_threshold));
    }

    /**
     * Get engagement score for a user
     *
     * @param int $user_id User ID
     * @param bool $calculate_if_missing Calculate if no stored score exists
     * @return array|null Score data or null
     */
    public static function get_score($user_id, $calculate_if_missing = true) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_engagement_scores';

        $score = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE user_id = %d
        ", $user_id), ARRAY_A);

        if ($score) {
            return $score;
        }

        if ($calculate_if_missing) {
            self::calculate_and_store($user_id);
            return self::get_score($user_id, false);
        }

        return null;
    }

    /**
     * Get engagement scores for an agent's clients
     *
     * @param int $agent_id Agent user ID
     * @param string $sort_by Sort field (score, last_activity, trend)
     * @param string $order Sort order (ASC, DESC)
     * @return array Client scores
     */
    public static function get_agent_client_scores($agent_id, $sort_by = 'score', $order = 'DESC') {
        global $wpdb;

        $scores_table = $wpdb->prefix . 'mld_client_engagement_scores';
        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $users_table = $wpdb->users;

        $valid_sorts = array('score', 'last_activity_at', 'days_since_activity', 'trend_change');
        $sort_by = in_array($sort_by, $valid_sorts) ? $sort_by : 'score';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                es.*,
                u.display_name as client_name,
                u.user_email as client_email
            FROM {$relationships_table} r
            LEFT JOIN {$scores_table} es ON r.client_id = es.user_id
            LEFT JOIN {$users_table} u ON r.client_id = u.ID
            WHERE r.agent_id = %d
            AND r.relationship_status = 'active'
            ORDER BY es.{$sort_by} {$order}
        ", $agent_id), ARRAY_A);
    }
}
