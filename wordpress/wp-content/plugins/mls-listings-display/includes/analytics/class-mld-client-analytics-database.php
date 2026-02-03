<?php
/**
 * MLS Listings Display - Client Analytics Database Helper
 *
 * Database operations for client activity tracking and analytics
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.37.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Client_Analytics_Database {

    /**
     * Table names
     */
    private static function get_activity_table() {
        global $wpdb;
        return $wpdb->prefix . 'mld_client_activity';
    }

    private static function get_sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'mld_client_sessions';
    }

    private static function get_summary_table() {
        global $wpdb;
        return $wpdb->prefix . 'mld_client_analytics_summary';
    }

    /**
     * Record a single activity event
     *
     * @param int    $user_id      WordPress user ID
     * @param string $session_id   Client session identifier
     * @param string $activity_type Type of activity
     * @param array  $data         Additional activity data
     * @return int|false Activity ID or false on failure
     */
    public static function record_activity($user_id, $session_id, $activity_type, $data = array()) {
        global $wpdb;

        // Valid activity types (expanded v6.38.0)
        $valid_types = array(
            // Basic events
            'property_view', 'property_share', 'search_run', 'filter_used',
            'favorite_add', 'favorite_remove', 'hidden_add', 'hidden_remove',
            'search_save', 'login', 'page_view',

            // Enhanced search & filter events (v6.38.0)
            'search_execute', 'filter_apply', 'filter_clear',
            'filter_modal_open', 'filter_modal_close', 'autocomplete_select',

            // Map interaction events
            'map_zoom', 'map_pan', 'map_draw_start', 'map_draw_complete',
            'marker_click', 'cluster_click',

            // Property detail events
            'photo_view', 'photo_lightbox_open', 'photo_lightbox_close',
            'tab_click', 'video_play', 'street_view_open',
            'calculator_use', 'school_info_view', 'similar_homes_click',

            // User action events
            'contact_click', 'contact_form_submit', 'share_click',

            // Saved search events
            'saved_search_view', 'saved_search_edit', 'saved_search_delete', 'alert_toggle',

            // Engagement events
            'time_on_page', 'scroll_depth',
        );

        if (!in_array($activity_type, $valid_types)) {
            return false;
        }

        $insert_data = array(
            'user_id' => absint($user_id),
            'session_id' => sanitize_text_field($session_id),
            'activity_type' => $activity_type,
            'entity_id' => isset($data['entity_id']) ? sanitize_text_field($data['entity_id']) : null,
            'entity_type' => isset($data['entity_type']) ? sanitize_text_field($data['entity_type']) : null,
            'metadata' => isset($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'platform' => isset($data['platform']) ? $data['platform'] : 'unknown',
            'device_info' => isset($data['device_info']) ? sanitize_text_field(substr($data['device_info'], 0, 255)) : null,
            'created_at' => current_time('mysql'),
        );

        $result = $wpdb->insert(self::get_activity_table(), $insert_data);

        if ($result) {
            // Update session counters
            self::increment_session_counter($session_id, $activity_type);
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Record multiple activity events at once (batch)
     *
     * @param array $activities Array of activity data
     * @return array Results with counts
     */
    public static function record_batch_activities($activities) {
        $success_count = 0;
        $fail_count = 0;

        foreach ($activities as $activity) {
            $result = self::record_activity(
                $activity['user_id'],
                $activity['session_id'],
                $activity['activity_type'],
                $activity
            );

            if ($result) {
                $success_count++;
            } else {
                $fail_count++;
            }
        }

        return array(
            'success_count' => $success_count,
            'fail_count' => $fail_count,
            'total' => count($activities),
        );
    }

    /**
     * Start a new session
     *
     * @param int    $user_id     WordPress user ID
     * @param string $session_id  Client-generated session ID
     * @param array  $device_data Device information
     * @return bool Success
     */
    public static function start_session($user_id, $session_id, $device_data = array()) {
        global $wpdb;

        // Check if session already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE session_id = %s",
            self::get_sessions_table(),
            $session_id
        ));

        if ($existing) {
            return true; // Already started
        }

        $insert_data = array(
            'session_id' => sanitize_text_field($session_id),
            'user_id' => absint($user_id),
            'started_at' => current_time('mysql'),
            'platform' => isset($device_data['platform']) ? $device_data['platform'] : 'unknown',
            'device_type' => isset($device_data['device_type']) ? sanitize_text_field($device_data['device_type']) : null,
            'app_version' => isset($device_data['app_version']) ? sanitize_text_field($device_data['app_version']) : null,
            'activity_count' => 0,
            'properties_viewed' => 0,
            'searches_run' => 0,
        );

        return $wpdb->insert(self::get_sessions_table(), $insert_data) !== false;
    }

    /**
     * End a session
     *
     * @param string $session_id Session identifier
     * @return bool Success
     */
    public static function end_session($session_id) {
        global $wpdb;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE session_id = %s",
            self::get_sessions_table(),
            $session_id
        ));

        if (!$session) {
            return false;
        }

        $started = strtotime($session->started_at);
        $now = current_time('timestamp');
        $duration = max(0, $now - $started);

        return $wpdb->update(
            self::get_sessions_table(),
            array(
                'ended_at' => current_time('mysql'),
                'duration_seconds' => $duration,
            ),
            array('session_id' => $session_id)
        ) !== false;
    }

    /**
     * Increment session activity counters
     *
     * @param string $session_id    Session identifier
     * @param string $activity_type Type of activity
     */
    private static function increment_session_counter($session_id, $activity_type) {
        global $wpdb;

        $counters = array('activity_count' => 1);

        if ($activity_type === 'property_view') {
            $counters['properties_viewed'] = 1;
        } elseif ($activity_type === 'search_run') {
            $counters['searches_run'] = 1;
        }

        $set_clause = array();
        foreach ($counters as $column => $increment) {
            $set_clause[] = "{$column} = {$column} + {$increment}";
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET " . implode(', ', $set_clause) . " WHERE session_id = %s",
            self::get_sessions_table(),
            $session_id
        ));
    }

    /**
     * Get analytics summary for a client
     *
     * @param int $user_id    Client user ID
     * @param int $days       Number of days to look back
     * @return array Analytics summary
     */
    public static function get_client_analytics($user_id, $days = 30) {
        global $wpdb;

        $user_id = absint($user_id);
        $since = date('Y-m-d', strtotime("-{$days} days"));

        // Get aggregate stats from summary table
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(total_sessions) as total_sessions,
                SUM(total_duration_seconds) as total_duration,
                SUM(properties_viewed) as total_properties_viewed,
                SUM(unique_properties_viewed) as unique_properties_viewed,
                SUM(searches_run) as total_searches,
                SUM(favorites_added) as total_favorites,
                AVG(engagement_score) as avg_engagement
            FROM %i
            WHERE user_id = %d AND summary_date >= %s",
            self::get_summary_table(),
            $user_id,
            $since
        ));

        // Get recent activity counts by type
        $activity_breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_type, COUNT(*) as count
            FROM %i
            WHERE user_id = %d AND created_at >= %s
            GROUP BY activity_type",
            self::get_activity_table(),
            $user_id,
            $since . ' 00:00:00'
        ), OBJECT_K);

        // Get most recent activity
        $last_activity = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM %i WHERE user_id = %d",
            self::get_activity_table(),
            $user_id
        ));

        // Get session count and platform breakdown
        $platforms = $wpdb->get_results($wpdb->prepare(
            "SELECT platform, COUNT(*) as count
            FROM %i
            WHERE user_id = %d AND started_at >= %s
            GROUP BY platform",
            self::get_sessions_table(),
            $user_id,
            $since . ' 00:00:00'
        ), OBJECT_K);

        return array(
            'user_id' => $user_id,
            'period_days' => $days,
            'total_sessions' => (int) ($summary->total_sessions ?? 0),
            'total_duration_minutes' => round(($summary->total_duration ?? 0) / 60, 1),
            'properties_viewed' => (int) ($summary->total_properties_viewed ?? 0),
            'unique_properties_viewed' => (int) ($summary->unique_properties_viewed ?? 0),
            'searches_run' => (int) ($summary->total_searches ?? 0),
            'favorites_added' => (int) ($summary->total_favorites ?? 0),
            'engagement_score' => round((float) ($summary->avg_engagement ?? 0), 1),
            'activity_breakdown' => array_map(function($item) {
                return (int) $item->count;
            }, $activity_breakdown),
            'platform_breakdown' => array_map(function($item) {
                return (int) $item->count;
            }, $platforms),
            'last_activity' => $last_activity,
        );
    }

    /**
     * Get activity timeline for a client
     *
     * @param int $user_id Client user ID
     * @param int $limit   Max activities to return
     * @param int $offset  Offset for pagination
     * @return array Activity timeline
     */
    public static function get_client_activity_timeline($user_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id, activity_type, entity_id, entity_type,
                metadata, platform, created_at
            FROM %i
            WHERE user_id = %d
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            self::get_activity_table(),
            absint($user_id),
            absint($limit),
            absint($offset)
        ));

        // Enrich with entity details
        return array_map(function($activity) {
            $enriched = array(
                'id' => (int) $activity->id,
                'activity_type' => $activity->activity_type,
                'entity_id' => $activity->entity_id,
                'entity_type' => $activity->entity_type,
                'platform' => $activity->platform,
                'created_at' => $activity->created_at,
                'description' => self::get_activity_description($activity),
            );

            if ($activity->metadata) {
                $enriched['metadata'] = json_decode($activity->metadata, true);
            }

            return $enriched;
        }, $activities);
    }

    /**
     * Get human-readable activity description
     *
     * @param object $activity Activity record
     * @return string Description
     */
    private static function get_activity_description($activity) {
        $descriptions = array(
            // Basic events
            'property_view' => 'Viewed a property',
            'property_share' => 'Shared a property',
            'search_run' => 'Ran a property search',
            'filter_used' => 'Applied search filters',
            'favorite_add' => 'Added property to favorites',
            'favorite_remove' => 'Removed property from favorites',
            'hidden_add' => 'Hidden a property',
            'hidden_remove' => 'Unhidden a property',
            'search_save' => 'Saved a search',
            'login' => 'Logged in',
            'page_view' => 'Viewed a page',

            // Enhanced search & filter events (v6.38.0)
            'search_execute' => 'Executed a search',
            'filter_apply' => 'Applied a filter',
            'filter_clear' => 'Cleared a filter',
            'filter_modal_open' => 'Opened filter modal',
            'filter_modal_close' => 'Closed filter modal',
            'autocomplete_select' => 'Selected autocomplete suggestion',

            // Map interaction events
            'map_zoom' => 'Zoomed the map',
            'map_pan' => 'Panned the map',
            'map_draw_start' => 'Started drawing on map',
            'map_draw_complete' => 'Completed draw search area',
            'marker_click' => 'Clicked a map marker',
            'cluster_click' => 'Clicked a property cluster',

            // Property detail events
            'photo_view' => 'Viewed a photo',
            'photo_lightbox_open' => 'Opened photo gallery',
            'photo_lightbox_close' => 'Closed photo gallery',
            'tab_click' => 'Clicked a tab',
            'video_play' => 'Started video',
            'street_view_open' => 'Opened street view',
            'calculator_use' => 'Used mortgage calculator',
            'school_info_view' => 'Viewed school information',
            'similar_homes_click' => 'Clicked similar home',

            // User action events
            'contact_click' => 'Clicked contact button',
            'contact_form_submit' => 'Submitted contact form',
            'share_click' => 'Clicked share button',

            // Saved search events
            'saved_search_view' => 'Viewed saved search results',
            'saved_search_edit' => 'Edited a saved search',
            'saved_search_delete' => 'Deleted a saved search',
            'alert_toggle' => 'Toggled search alerts',

            // Engagement events
            'time_on_page' => 'Time on page',
            'scroll_depth' => 'Scrolled page',
        );

        $base = $descriptions[$activity->activity_type] ?? 'Unknown activity';

        // Add entity context if available
        if ($activity->entity_id && $activity->entity_type === 'property') {
            $base .= " ({$activity->entity_id})";
        }

        return $base;
    }

    /**
     * Get analytics for all clients of an agent
     *
     * @param int $agent_id Agent user ID
     * @param int $days     Number of days to look back
     * @return array Analytics for all clients
     */
    public static function get_agent_clients_analytics($agent_id, $days = 30) {
        global $wpdb;

        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';
        $since = date('Y-m-d', strtotime("-{$days} days"));

        // Get all clients for this agent
        $clients = $wpdb->get_col($wpdb->prepare(
            "SELECT client_id FROM %i WHERE agent_id = %d AND relationship_status = 'active'",
            $relationships_table,
            absint($agent_id)
        ));

        if (empty($clients)) {
            return array(
                'clients' => array(),
                'totals' => array(
                    'total_sessions' => 0,
                    'total_properties_viewed' => 0,
                    'total_searches' => 0,
                    'active_clients' => 0,
                ),
            );
        }

        $results = array();
        $totals = array(
            'total_sessions' => 0,
            'total_properties_viewed' => 0,
            'total_searches' => 0,
            'active_clients' => 0,
        );

        foreach ($clients as $client_id) {
            $analytics = self::get_client_analytics($client_id, $days);

            // Get client user data
            $user = get_userdata($client_id);
            $analytics['email'] = $user ? $user->user_email : '';
            $analytics['name'] = $user ? $user->display_name : 'Unknown';

            $results[] = $analytics;

            // Update totals
            $totals['total_sessions'] += $analytics['total_sessions'];
            $totals['total_properties_viewed'] += $analytics['properties_viewed'];
            $totals['total_searches'] += $analytics['searches_run'];

            if ($analytics['total_sessions'] > 0) {
                $totals['active_clients']++;
            }
        }

        // Sort by engagement score descending
        usort($results, function($a, $b) {
            return $b['engagement_score'] <=> $a['engagement_score'];
        });

        return array(
            'clients' => $results,
            'totals' => $totals,
            'period_days' => $days,
        );
    }

    /**
     * Clean up stale sessions (ended but not marked as ended)
     *
     * @param int $stale_hours Hours after which a session is considered stale
     * @return int Number of sessions cleaned up
     */
    public static function cleanup_stale_sessions($stale_hours = 4) {
        global $wpdb;

        $stale_time = date('Y-m-d H:i:s', strtotime("-{$stale_hours} hours"));

        // Find sessions that started but never ended
        $stale_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT session_id, started_at FROM %i
            WHERE ended_at IS NULL AND started_at < %s",
            self::get_sessions_table(),
            $stale_time
        ));

        $cleaned = 0;
        foreach ($stale_sessions as $session) {
            // Calculate duration based on last activity or 1 hour default
            $last_activity = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM %i WHERE session_id = %s",
                self::get_activity_table(),
                $session->session_id
            ));

            $end_time = $last_activity ?: date('Y-m-d H:i:s', strtotime($session->started_at) + 3600);
            $duration = max(0, strtotime($end_time) - strtotime($session->started_at));

            $wpdb->update(
                self::get_sessions_table(),
                array(
                    'ended_at' => $end_time,
                    'duration_seconds' => $duration,
                ),
                array('session_id' => $session->session_id)
            );
            $cleaned++;
        }

        return $cleaned;
    }
}
