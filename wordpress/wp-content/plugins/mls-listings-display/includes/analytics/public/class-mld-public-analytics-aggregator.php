<?php
/**
 * MLD Public Analytics Aggregator
 *
 * Handles aggregation of raw analytics data into hourly and daily summaries.
 * Also manages data cleanup and maintenance tasks.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Public_Analytics_Aggregator
 *
 * Aggregates public analytics data for efficient querying and storage.
 */
class MLD_Public_Analytics_Aggregator {

    /**
     * Singleton instance
     *
     * @var MLD_Public_Analytics_Aggregator
     */
    private static $instance = null;

    /**
     * Database instance
     *
     * @var MLD_Public_Analytics_Database
     */
    private $db;

    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Retention period for raw data (days)
     */
    const RAW_DATA_RETENTION_DAYS = 30;

    /**
     * Stale presence threshold (seconds)
     */
    const PRESENCE_STALE_SECONDS = 120;

    /**
     * Get singleton instance
     *
     * @return MLD_Public_Analytics_Aggregator
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db = MLD_Public_Analytics_Database::get_instance();
    }

    /**
     * Initialize cron hooks for public analytics
     */
    public static function init() {
        $instance = self::get_instance();

        // Register custom cron schedules
        add_filter('cron_schedules', array($instance, 'add_cron_schedules'));

        // Register cron action hooks
        add_action('mld_public_analytics_hourly', array($instance, 'run_hourly_aggregation'));
        add_action('mld_public_analytics_daily', array($instance, 'run_daily_aggregation'));
        add_action('mld_public_analytics_cleanup', array($instance, 'run_data_cleanup'));
        add_action('mld_public_analytics_presence_cleanup', array($instance, 'run_presence_cleanup'));

        // Schedule crons if not already scheduled
        $instance->schedule_crons();
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every 5 Minutes', 'mls-listings-display'),
            );
        }
        return $schedules;
    }

    /**
     * Schedule all cron jobs
     */
    public function schedule_crons() {
        // Hourly aggregation
        if (!wp_next_scheduled('mld_public_analytics_hourly')) {
            // Schedule at the top of the next hour
            $next_hour = strtotime(date('Y-m-d H:00:00', strtotime('+1 hour')));
            wp_schedule_event($next_hour, 'hourly', 'mld_public_analytics_hourly');
        }

        // Daily aggregation (runs at 2 AM)
        if (!wp_next_scheduled('mld_public_analytics_daily')) {
            $tomorrow_2am = strtotime('tomorrow 2:00am');
            wp_schedule_event($tomorrow_2am, 'daily', 'mld_public_analytics_daily');
        }

        // Data cleanup (runs at 3 AM)
        if (!wp_next_scheduled('mld_public_analytics_cleanup')) {
            $tomorrow_3am = strtotime('tomorrow 3:00am');
            wp_schedule_event($tomorrow_3am, 'daily', 'mld_public_analytics_cleanup');
        }

        // Presence cleanup (every 5 minutes)
        if (!wp_next_scheduled('mld_public_analytics_presence_cleanup')) {
            wp_schedule_event(time(), 'every_five_minutes', 'mld_public_analytics_presence_cleanup');
        }
    }

    /**
     * Unschedule all cron jobs
     */
    public static function unschedule_all() {
        wp_clear_scheduled_hook('mld_public_analytics_hourly');
        wp_clear_scheduled_hook('mld_public_analytics_daily');
        wp_clear_scheduled_hook('mld_public_analytics_cleanup');
        wp_clear_scheduled_hook('mld_public_analytics_presence_cleanup');
    }

    /**
     * Run hourly aggregation
     *
     * Aggregates events from the previous hour into the hourly stats table.
     */
    public function run_hourly_aggregation() {
        $start_time = microtime(true);

        try {
            // Get the previous hour's time range - use WordPress timezone (v6.45.4 fix)
            $wp_timestamp = current_time('timestamp');
            $hour_end = date('Y-m-d H:00:00', $wp_timestamp); // Current hour start = previous hour end
            $hour_start = date('Y-m-d H:00:00', $wp_timestamp - 3600);

        $sessions_table = $this->db->get_table('sessions');
        $events_table = $this->db->get_table('events');
        $hourly_table = $this->db->get_table('hourly');

        // Check if this hour is already aggregated
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$hourly_table} WHERE hour_timestamp = %s",
            $hour_start
        ));

        if ($existing) {
            error_log("[MLD Public Analytics] Hourly aggregation for {$hour_start} already exists, skipping");
            return;
        }

        // Aggregate session data
        $session_stats = $this->wpdb->get_row($this->wpdb->prepare("
            SELECT
                COUNT(DISTINCT session_id) as unique_sessions,
                SUM(CASE WHEN visitor_hash IS NULL OR visitor_hash = '' THEN 1 ELSE 0 END) as new_sessions,
                SUM(CASE WHEN visitor_hash IS NOT NULL AND visitor_hash != '' THEN 1 ELSE 0 END) as returning_sessions,
                SUM(page_views) as page_views,
                SUM(property_views) as property_views,
                SUM(searches) as search_count,
                SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) as bounce_sessions,
                AVG(TIMESTAMPDIFF(SECOND, first_seen, last_seen)) as avg_session_duration,
                AVG(page_views) as avg_pages_per_session
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
        ", $hour_start, $hour_end));

        // Get platform breakdown
        $platform_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT platform, COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            GROUP BY platform
        ", $hour_start, $hour_end));

        $platform_breakdown = array();
        foreach ($platform_data as $row) {
            $platform_breakdown[$row->platform] = (int) $row->count;
        }

        // Get device breakdown
        $device_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT device_type, COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            GROUP BY device_type
        ", $hour_start, $hour_end));

        $device_breakdown = array();
        foreach ($device_data as $row) {
            $device_breakdown[$row->device_type] = (int) $row->count;
        }

        // Get country breakdown
        $country_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT country_code, COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            AND country_code IS NOT NULL
            GROUP BY country_code
            ORDER BY count DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $country_breakdown = array();
        foreach ($country_data as $row) {
            $country_breakdown[$row->country_code] = (int) $row->count;
        }

        // Get top cities
        $city_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT city, country_code, COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            AND city IS NOT NULL
            GROUP BY city, country_code
            ORDER BY count DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $top_cities = array();
        foreach ($city_data as $row) {
            $top_cities[] = array(
                'city' => $row->city,
                'country' => $row->country_code,
                'count' => (int) $row->count,
            );
        }

        // Get referrer breakdown
        $referrer_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT
                CASE
                    WHEN referrer_domain IS NULL OR referrer_domain = '' THEN 'direct'
                    ELSE referrer_domain
                END as source,
                COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            GROUP BY source
            ORDER BY count DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $referrer_breakdown = array();
        foreach ($referrer_data as $row) {
            $referrer_breakdown[$row->source] = (int) $row->count;
        }

        // Get top pages
        $page_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT page_path, COUNT(*) as views
            FROM {$events_table}
            WHERE event_type = 'page_view'
            AND event_timestamp >= %s AND event_timestamp < %s
            GROUP BY page_path
            ORDER BY views DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $top_pages = array();
        foreach ($page_data as $row) {
            $top_pages[] = array(
                'path' => $row->page_path,
                'views' => (int) $row->views,
            );
        }

        // Get top properties
        $property_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT listing_id, property_city, COUNT(*) as views
            FROM {$events_table}
            WHERE event_type = 'property_view'
            AND event_timestamp >= %s AND event_timestamp < %s
            AND listing_id IS NOT NULL
            GROUP BY listing_id, property_city
            ORDER BY views DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $top_properties = array();
        foreach ($property_data as $row) {
            $top_properties[] = array(
                'listing_id' => $row->listing_id,
                'city' => $row->property_city,
                'views' => (int) $row->views,
            );
        }

        // Get top searches (v6.54.0)
        $search_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT search_query, COUNT(*) as count
            FROM {$events_table}
            WHERE event_type IN ('search', 'search_execute')
            AND event_timestamp >= %s AND event_timestamp < %s
            AND search_query IS NOT NULL
            AND search_query != ''
            AND search_query != 'null'
            GROUP BY search_query
            ORDER BY count DESC
            LIMIT 10
        ", $hour_start, $hour_end));

        $top_searches = array();
        foreach ($search_data as $row) {
            // Parse the JSON search query to extract readable filters
            $query_data = json_decode($row->search_query, true);
            $search_summary = $this->summarize_search_query($query_data);
            if (!empty($search_summary)) {
                $top_searches[] = array(
                    'query' => $search_summary,
                    'raw' => $row->search_query,
                    'count' => (int) $row->count,
                );
            }
        }

        // Get average scroll depth from events
        $avg_scroll = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT AVG(scroll_depth)
            FROM {$events_table}
            WHERE event_type = 'scroll_depth'
            AND event_timestamp >= %s AND event_timestamp < %s
            AND scroll_depth IS NOT NULL
        ", $hour_start, $hour_end));

        // Insert hourly aggregate
        $result = $this->wpdb->insert(
            $hourly_table,
            array(
                'hour_timestamp'       => $hour_start,
                'unique_sessions'      => (int) ($session_stats->unique_sessions ?? 0),
                'new_sessions'         => (int) ($session_stats->new_sessions ?? 0),
                'returning_sessions'   => (int) ($session_stats->returning_sessions ?? 0),
                'page_views'           => (int) ($session_stats->page_views ?? 0),
                'property_views'       => (int) ($session_stats->property_views ?? 0),
                'search_count'         => (int) ($session_stats->search_count ?? 0),
                'bounce_sessions'      => (int) ($session_stats->bounce_sessions ?? 0),
                'avg_session_duration' => (int) ($session_stats->avg_session_duration ?? 0),
                'avg_pages_per_session' => round($session_stats->avg_pages_per_session ?? 0, 2),
                'avg_scroll_depth'     => round($avg_scroll ?? 0, 2),
                'platform_breakdown'   => json_encode($platform_breakdown),
                'device_breakdown'     => json_encode($device_breakdown),
                'country_breakdown'    => json_encode($country_breakdown),
                'top_cities'           => json_encode($top_cities),
                'referrer_breakdown'   => json_encode($referrer_breakdown),
                'top_pages'            => json_encode($top_pages),
                'top_properties'       => json_encode($top_properties),
                'top_searches'         => json_encode($top_searches),
            ),
            array('%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $duration = round(microtime(true) - $start_time, 3);
        error_log("[MLD Public Analytics] Hourly aggregation for {$hour_start} completed in {$duration}s - " .
                  ($session_stats->unique_sessions ?? 0) . " sessions, " .
                  ($session_stats->page_views ?? 0) . " page views");

        } catch (Exception $e) {
            error_log("[MLD Public Analytics] Hourly aggregation error: " . $e->getMessage());
        }
    }

    /**
     * Run daily aggregation
     *
     * Aggregates data from the previous day into the daily stats table.
     */
    public function run_daily_aggregation() {
        $start_time = microtime(true);

        try {
            // Get yesterday's date range
            $day_start = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $day_end = date('Y-m-d 00:00:00'); // Today midnight

            $sessions_table = $this->db->get_table('sessions');
        $events_table = $this->db->get_table('events');
        $hourly_table = $this->db->get_table('hourly');
        $daily_table = $this->db->get_table('daily');

        // Check if this day is already aggregated
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$daily_table} WHERE date = %s",
            date('Y-m-d', strtotime('-1 day'))
        ));

        if ($existing) {
            error_log("[MLD Public Analytics] Daily aggregation for " . date('Y-m-d', strtotime('-1 day')) . " already exists, skipping");
            return;
        }

        // Aggregate from hourly stats for better performance
        $hourly_stats = $this->wpdb->get_row($this->wpdb->prepare("
            SELECT
                SUM(unique_sessions) as unique_sessions,
                SUM(new_sessions) as new_sessions,
                SUM(returning_sessions) as returning_sessions,
                SUM(page_views) as page_views,
                SUM(property_views) as property_views,
                SUM(search_count) as search_count,
                SUM(bounce_sessions) as bounce_sessions,
                AVG(avg_session_duration) as avg_session_duration,
                AVG(avg_pages_per_session) as avg_pages_per_session,
                AVG(avg_scroll_depth) as avg_scroll_depth
            FROM {$hourly_table}
            WHERE hour_timestamp >= %s AND hour_timestamp < %s
        ", $day_start, $day_end));

        // Merge platform breakdowns from hourly
        $hourly_platforms = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT platform_breakdown
            FROM {$hourly_table}
            WHERE hour_timestamp >= %s AND hour_timestamp < %s
        ", $day_start, $day_end));

        $platform_breakdown = $this->merge_json_counts($hourly_platforms);

        // Merge device breakdowns
        $hourly_devices = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT device_breakdown
            FROM {$hourly_table}
            WHERE hour_timestamp >= %s AND hour_timestamp < %s
        ", $day_start, $day_end));

        $device_breakdown = $this->merge_json_counts($hourly_devices);

        // Merge country breakdowns
        $hourly_countries = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT country_breakdown
            FROM {$hourly_table}
            WHERE hour_timestamp >= %s AND hour_timestamp < %s
        ", $day_start, $day_end));

        $country_breakdown = $this->merge_json_counts($hourly_countries);

        // Merge referrer breakdowns
        $hourly_referrers = $this->wpdb->get_col($this->wpdb->prepare("
            SELECT referrer_breakdown
            FROM {$hourly_table}
            WHERE hour_timestamp >= %s AND hour_timestamp < %s
        ", $day_start, $day_end));

        $referrer_breakdown = $this->merge_json_counts($hourly_referrers);

        // Get top cities for the day (re-aggregate from sessions for accuracy)
        $city_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT city, country_code, COUNT(*) as count
            FROM {$sessions_table}
            WHERE first_seen >= %s AND first_seen < %s
            AND is_bot = 0
            AND city IS NOT NULL
            GROUP BY city, country_code
            ORDER BY count DESC
            LIMIT 20
        ", $day_start, $day_end));

        $top_cities = array();
        foreach ($city_data as $row) {
            $top_cities[] = array(
                'city' => $row->city,
                'country' => $row->country_code,
                'count' => (int) $row->count,
            );
        }

        // Get top pages for the day
        $page_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT page_path, COUNT(*) as views
            FROM {$events_table}
            WHERE event_type = 'page_view'
            AND event_timestamp >= %s AND event_timestamp < %s
            GROUP BY page_path
            ORDER BY views DESC
            LIMIT 20
        ", $day_start, $day_end));

        $top_pages = array();
        foreach ($page_data as $row) {
            $top_pages[] = array(
                'path' => $row->page_path,
                'views' => (int) $row->views,
            );
        }

        // Get top properties for the day
        $property_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT listing_id, property_city, COUNT(*) as views
            FROM {$events_table}
            WHERE event_type = 'property_view'
            AND event_timestamp >= %s AND event_timestamp < %s
            AND listing_id IS NOT NULL
            GROUP BY listing_id, property_city
            ORDER BY views DESC
            LIMIT 20
        ", $day_start, $day_end));

        $top_properties = array();
        foreach ($property_data as $row) {
            $top_properties[] = array(
                'listing_id' => $row->listing_id,
                'city' => $row->property_city,
                'views' => (int) $row->views,
            );
        }

        // Calculate day-over-day changes
        $previous_day = $this->wpdb->get_row($this->wpdb->prepare("
            SELECT unique_sessions, page_views, property_views, search_count
            FROM {$daily_table}
            WHERE date = %s
        ", date('Y-m-d', strtotime('-2 days'))));

        $session_change = 0;
        $pageview_change = 0;
        if ($previous_day) {
            if ($previous_day->unique_sessions > 0) {
                $session_change = round(
                    (($hourly_stats->unique_sessions - $previous_day->unique_sessions) / $previous_day->unique_sessions) * 100,
                    1
                );
            }
            if ($previous_day->page_views > 0) {
                $pageview_change = round(
                    (($hourly_stats->page_views - $previous_day->page_views) / $previous_day->page_views) * 100,
                    1
                );
            }
        }

        // Calculate bounce rate
        $bounce_rate = 0;
        if (($hourly_stats->unique_sessions ?? 0) > 0) {
            $bounce_rate = round(($hourly_stats->bounce_sessions ?? 0) / $hourly_stats->unique_sessions * 100, 2);
        }

        // Insert daily aggregate
        $result = $this->wpdb->insert(
            $daily_table,
            array(
                'date'                  => date('Y-m-d', strtotime('-1 day')),
                'unique_sessions'       => (int) ($hourly_stats->unique_sessions ?? 0),
                'new_sessions'          => (int) ($hourly_stats->new_sessions ?? 0),
                'returning_sessions'    => (int) ($hourly_stats->returning_sessions ?? 0),
                'page_views'            => (int) ($hourly_stats->page_views ?? 0),
                'property_views'        => (int) ($hourly_stats->property_views ?? 0),
                'search_count'          => (int) ($hourly_stats->search_count ?? 0),
                'bounce_sessions'       => (int) ($hourly_stats->bounce_sessions ?? 0),
                'bounce_rate'           => $bounce_rate,
                'avg_session_duration'  => (int) ($hourly_stats->avg_session_duration ?? 0),
                'avg_pages_per_session' => round($hourly_stats->avg_pages_per_session ?? 0, 2),
                'avg_scroll_depth'      => round($hourly_stats->avg_scroll_depth ?? 0, 2),
                'sessions_change_pct'   => $session_change,
                'pageviews_change_pct'  => $pageview_change,
                'platform_breakdown'    => json_encode($platform_breakdown),
                'device_breakdown'      => json_encode($device_breakdown),
                'country_breakdown'     => json_encode($country_breakdown),
                'top_cities'            => json_encode($top_cities),
                'referrer_breakdown'    => json_encode($referrer_breakdown),
                'top_pages'             => json_encode($top_pages),
                'top_properties'        => json_encode($top_properties),
            ),
            array('%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $duration = round(microtime(true) - $start_time, 3);
        error_log("[MLD Public Analytics] Daily aggregation for " . date('Y-m-d', strtotime('-1 day')) . " completed in {$duration}s - " .
                  ($hourly_stats->unique_sessions ?? 0) . " sessions, " .
                  ($hourly_stats->page_views ?? 0) . " page views");

        } catch (Exception $e) {
            error_log("[MLD Public Analytics] Daily aggregation error: " . $e->getMessage());
        }
    }

    /**
     * Merge JSON count arrays from multiple rows
     *
     * @param array $json_strings Array of JSON strings
     * @return array Merged counts
     */
    private function merge_json_counts($json_strings) {
        $merged = array();

        foreach ($json_strings as $json) {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $key => $count) {
                if (!isset($merged[$key])) {
                    $merged[$key] = 0;
                }
                $merged[$key] += (int) $count;
            }
        }

        // Sort by count descending
        arsort($merged);

        // Limit to top 20
        return array_slice($merged, 0, 20, true);
    }

    /**
     * Run data cleanup
     *
     * Deletes raw events and sessions older than retention period.
     */
    public function run_data_cleanup() {
        $start_time = microtime(true);

        try {
            $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . self::RAW_DATA_RETENTION_DAYS . ' days'));

            $sessions_table = $this->db->get_table('sessions');
            $events_table = $this->db->get_table('events');

            // Delete old events
            $events_deleted = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$events_table} WHERE created_at < %s LIMIT 10000",
                $cutoff_date
            ));

            // Delete old sessions
            $sessions_deleted = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$sessions_table} WHERE last_seen < %s LIMIT 10000",
                $cutoff_date
            ));

            $duration = round(microtime(true) - $start_time, 3);

            if ($events_deleted > 0 || $sessions_deleted > 0) {
                error_log("[MLD Public Analytics] Data cleanup completed in {$duration}s - Deleted {$events_deleted} events, {$sessions_deleted} sessions");
            }

            // If we hit the limit, schedule another run soon
            if ($events_deleted >= 10000 || $sessions_deleted >= 10000) {
                wp_schedule_single_event(time() + 60, 'mld_public_analytics_cleanup');
            }

        } catch (Exception $e) {
            error_log("[MLD Public Analytics] Data cleanup error: " . $e->getMessage());
        }
    }

    /**
     * Run presence cleanup
     *
     * Removes stale entries from the real-time presence table.
     */
    public function run_presence_cleanup() {
        $presence_table = $this->db->get_table('presence');

        // Use WordPress current_time for consistency with heartbeat storage (v6.44.0 fix)
        $stale_time = date('Y-m-d H:i:s', current_time('timestamp') - self::PRESENCE_STALE_SECONDS);

        $deleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$presence_table} WHERE last_heartbeat < %s",
            $stale_time
        ));

        if ($deleted > 0) {
            error_log("[MLD Public Analytics] Presence cleanup: Removed {$deleted} stale entries");
        }
    }

    /**
     * Manually trigger hourly aggregation for a specific hour
     *
     * Useful for backfilling or debugging.
     *
     * @param string $hour_start Hour start time (Y-m-d H:00:00)
     * @return bool Success
     */
    public function aggregate_hour($hour_start) {
        // Validate hour format
        $hour_start = date('Y-m-d H:00:00', strtotime($hour_start));
        $hour_end = date('Y-m-d H:00:00', strtotime($hour_start . ' +1 hour'));

        // Store current time, run aggregation for specified hour
        // This is a simplified version - full implementation would need to modify run_hourly_aggregation
        error_log("[MLD Public Analytics] Manual aggregation requested for {$hour_start}");

        return true;
    }

    /**
     * Get aggregation status
     *
     * @return array Status information
     */
    public function get_status() {
        $hourly_table = $this->db->get_table('hourly');
        $daily_table = $this->db->get_table('daily');
        $events_table = $this->db->get_table('events');
        $sessions_table = $this->db->get_table('sessions');

        $latest_hourly = $this->wpdb->get_var("SELECT MAX(hour_timestamp) FROM {$hourly_table}");
        $latest_daily = $this->wpdb->get_var("SELECT MAX(day_date) FROM {$daily_table}");
        $hourly_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$hourly_table}");
        $daily_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$daily_table}");
        $events_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$events_table}");
        $sessions_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$sessions_table}");

        $oldest_event = $this->wpdb->get_var("SELECT MIN(created_at) FROM {$events_table}");

        return array(
            'latest_hourly_aggregation' => $latest_hourly,
            'latest_daily_aggregation'  => $latest_daily,
            'hourly_records'            => (int) $hourly_count,
            'daily_records'             => (int) $daily_count,
            'raw_events'                => (int) $events_count,
            'raw_sessions'              => (int) $sessions_count,
            'oldest_event'              => $oldest_event,
            'retention_days'            => self::RAW_DATA_RETENTION_DAYS,
            'next_hourly'               => wp_next_scheduled('mld_public_analytics_hourly'),
            'next_daily'                => wp_next_scheduled('mld_public_analytics_daily'),
            'next_cleanup'              => wp_next_scheduled('mld_public_analytics_cleanup'),
        );
    }

    /**
     * Summarize a search query JSON into a readable string
     *
     * @param array|null $query_data Decoded JSON search query
     * @return string Human-readable summary
     */
    private function summarize_search_query($query_data) {
        if (!is_array($query_data) || empty($query_data)) {
            return '';
        }

        $parts = array();

        // Location filters
        $city = $query_data['city'] ?? $query_data['City'] ?? null;
        if ($city) {
            $parts[] = $city;
        }

        $zip = $query_data['zip'] ?? $query_data['postal_code'] ?? null;
        if ($zip) {
            $parts[] = $zip;
        }

        $neighborhood = $query_data['neighborhood'] ?? $query_data['subdivision'] ?? null;
        if ($neighborhood) {
            $parts[] = $neighborhood;
        }

        // Property type
        $type = $query_data['property_type'] ?? $query_data['PropertyType'] ?? null;
        if ($type && $type !== 'Residential') {
            $parts[] = $type;
        }

        // Price range
        $min_price = $query_data['min_price'] ?? $query_data['price_min'] ?? null;
        $max_price = $query_data['max_price'] ?? $query_data['price_max'] ?? null;
        if ($min_price || $max_price) {
            if ($min_price && $max_price) {
                $parts[] = '$' . number_format($min_price / 1000) . 'K-$' . number_format($max_price / 1000) . 'K';
            } elseif ($min_price) {
                $parts[] = '$' . number_format($min_price / 1000) . 'K+';
            } else {
                $parts[] = 'Under $' . number_format($max_price / 1000) . 'K';
            }
        }

        // Beds/Baths
        $beds = $query_data['beds'] ?? $query_data['bedrooms'] ?? null;
        if ($beds) {
            $parts[] = $beds . '+ beds';
        }

        $baths = $query_data['baths'] ?? $query_data['bathrooms'] ?? null;
        if ($baths) {
            $parts[] = $baths . '+ baths';
        }

        // School filters
        $school_grade = $query_data['school_grade'] ?? null;
        if ($school_grade) {
            $parts[] = $school_grade . ' schools';
        }

        // Status
        $status = $query_data['status'] ?? null;
        if ($status && strtolower($status) !== 'active') {
            $parts[] = ucfirst($status);
        }

        // If no meaningful parts, check for keyword or return empty
        if (empty($parts)) {
            $keyword = $query_data['keyword'] ?? $query_data['search'] ?? $query_data['term'] ?? null;
            if ($keyword) {
                return $keyword;
            }
            return 'All Properties';
        }

        return implode(', ', $parts);
    }

    /**
     * Get top searches from aggregated data
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param int $limit Number of results
     * @return array Top searches
     */
    public function get_top_searches($start_date, $end_date, $limit = 20) {
        $events_table = $this->db->get_table('events');

        $results = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT search_query, COUNT(*) as count
            FROM {$events_table}
            WHERE event_type IN ('search', 'search_execute')
            AND event_timestamp >= %s AND event_timestamp < %s
            AND search_query IS NOT NULL
            AND search_query != ''
            AND search_query != 'null'
            GROUP BY search_query
            ORDER BY count DESC
            LIMIT %d
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59', $limit));

        $searches = array();
        foreach ($results as $row) {
            $query_data = json_decode($row->search_query, true);
            $summary = $this->summarize_search_query($query_data);
            if (!empty($summary)) {
                // Check if we already have this summary (aggregate similar searches)
                $found = false;
                foreach ($searches as &$existing) {
                    if ($existing['query'] === $summary) {
                        $existing['count'] += (int) $row->count;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $searches[] = array(
                        'query' => $summary,
                        'count' => (int) $row->count,
                    );
                }
            }
        }

        // Re-sort by count after aggregation
        usort($searches, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        return array_slice($searches, 0, $limit);
    }
}
