<?php
/**
 * MLD Public Analytics Database
 *
 * Handles database operations for public site analytics tracking.
 * Manages sessions, events, aggregations, and real-time presence data.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Public_Analytics_Database
 *
 * Database layer for cross-platform (Web + iOS) analytics tracking.
 */
class MLD_Public_Analytics_Database {

    /**
     * Database version for tracking schema updates
     */
    const DB_VERSION = '1.0.0';

    /**
     * Option name for storing database version
     */
    const VERSION_OPTION = 'mld_public_analytics_db_version';

    /**
     * Table names (without prefix)
     */
    const TABLE_SESSIONS = 'mld_public_sessions';
    const TABLE_EVENTS = 'mld_public_events';
    const TABLE_HOURLY = 'mld_analytics_hourly';
    const TABLE_DAILY = 'mld_analytics_daily';
    const TABLE_PRESENCE = 'mld_realtime_presence';

    /**
     * Singleton instance
     *
     * @var MLD_Public_Analytics_Database
     */
    private static $instance = null;

    /**
     * WordPress database object
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Table name cache
     *
     * @var array
     */
    private $tables = array();

    /**
     * Get singleton instance
     *
     * @return MLD_Public_Analytics_Database
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

        // Cache table names with prefix
        $this->tables = array(
            'sessions' => $wpdb->prefix . self::TABLE_SESSIONS,
            'events'   => $wpdb->prefix . self::TABLE_EVENTS,
            'hourly'   => $wpdb->prefix . self::TABLE_HOURLY,
            'daily'    => $wpdb->prefix . self::TABLE_DAILY,
            'presence' => $wpdb->prefix . self::TABLE_PRESENCE,
        );
    }

    /**
     * Get table name
     *
     * @param string $table Table key (sessions, events, hourly, daily, presence)
     * @return string Full table name with prefix
     */
    public function get_table($table) {
        return isset($this->tables[$table]) ? $this->tables[$table] : '';
    }

    /**
     * Create all analytics tables
     *
     * Called on plugin activation.
     *
     * @return bool True on success
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $this->wpdb->get_charset_collate();
        $results = array();

        // 1. Sessions table
        $results[] = $this->create_sessions_table($charset_collate);

        // 2. Events table
        $results[] = $this->create_events_table($charset_collate);

        // 3. Hourly aggregation table
        $results[] = $this->create_hourly_table($charset_collate);

        // 4. Daily aggregation table
        $results[] = $this->create_daily_table($charset_collate);

        // 5. Real-time presence table (MEMORY engine)
        $results[] = $this->create_presence_table($charset_collate);

        // Update version option
        update_option(self::VERSION_OPTION, self::DB_VERSION);

        return !in_array(false, $results, true);
    }

    /**
     * Create sessions table
     *
     * @param string $charset_collate Charset collation string
     * @return bool Success
     */
    private function create_sessions_table($charset_collate) {
        $table = $this->tables['sessions'];

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            visitor_hash VARCHAR(64) DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            platform ENUM('web_desktop', 'web_mobile', 'web_tablet', 'ios_app') NOT NULL DEFAULT 'web_desktop',
            ip_address VARCHAR(45) DEFAULT NULL,
            country_code VARCHAR(2) DEFAULT NULL,
            country_name VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(11,8) DEFAULT NULL,
            referrer_url TEXT DEFAULT NULL,
            referrer_domain VARCHAR(255) DEFAULT NULL,
            utm_source VARCHAR(100) DEFAULT NULL,
            utm_medium VARCHAR(100) DEFAULT NULL,
            utm_campaign VARCHAR(100) DEFAULT NULL,
            utm_term VARCHAR(255) DEFAULT NULL,
            utm_content VARCHAR(255) DEFAULT NULL,
            device_type ENUM('desktop', 'mobile', 'tablet') DEFAULT 'desktop',
            browser VARCHAR(50) DEFAULT NULL,
            browser_version VARCHAR(20) DEFAULT NULL,
            os VARCHAR(50) DEFAULT NULL,
            os_version VARCHAR(20) DEFAULT NULL,
            screen_width SMALLINT UNSIGNED DEFAULT NULL,
            screen_height SMALLINT UNSIGNED DEFAULT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            page_views INT UNSIGNED DEFAULT 0,
            property_views INT UNSIGNED DEFAULT 0,
            searches INT UNSIGNED DEFAULT 0,
            is_bounce TINYINT(1) DEFAULT 1,
            is_bot TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY visitor_hash (visitor_hash),
            KEY user_id (user_id),
            KEY platform (platform),
            KEY country_code (country_code),
            KEY city (city),
            KEY device_type (device_type),
            KEY first_seen (first_seen),
            KEY last_seen (last_seen),
            KEY is_bot (is_bot)
        ) {$charset_collate};";

        dbDelta($sql);

        return $this->table_exists('sessions');
    }

    /**
     * Create events table
     *
     * @param string $charset_collate Charset collation string
     * @return bool Success
     */
    private function create_events_table($charset_collate) {
        $table = $this->tables['events'];

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_category VARCHAR(50) DEFAULT NULL,
            platform ENUM('web_desktop', 'web_mobile', 'web_tablet', 'ios_app') NOT NULL DEFAULT 'web_desktop',
            page_url TEXT DEFAULT NULL,
            page_path VARCHAR(500) DEFAULT NULL,
            page_title VARCHAR(255) DEFAULT NULL,
            page_type VARCHAR(50) DEFAULT NULL,
            listing_id VARCHAR(50) DEFAULT NULL,
            listing_key VARCHAR(64) DEFAULT NULL,
            property_city VARCHAR(100) DEFAULT NULL,
            property_price INT UNSIGNED DEFAULT NULL,
            property_beds TINYINT UNSIGNED DEFAULT NULL,
            property_baths DECIMAL(3,1) DEFAULT NULL,
            search_query TEXT DEFAULT NULL,
            search_results_count INT UNSIGNED DEFAULT NULL,
            click_target VARCHAR(255) DEFAULT NULL,
            click_element VARCHAR(100) DEFAULT NULL,
            scroll_depth TINYINT UNSIGNED DEFAULT NULL,
            time_on_page INT UNSIGNED DEFAULT NULL,
            event_data JSON DEFAULT NULL,
            event_timestamp DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY event_category (event_category),
            KEY platform (platform),
            KEY page_type (page_type),
            KEY listing_id (listing_id),
            KEY event_timestamp (event_timestamp),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);

        return $this->table_exists('events');
    }

    /**
     * Create hourly aggregation table
     *
     * @param string $charset_collate Charset collation string
     * @return bool Success
     */
    private function create_hourly_table($charset_collate) {
        $table = $this->tables['hourly'];

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hour_timestamp DATETIME NOT NULL,
            unique_sessions INT UNSIGNED DEFAULT 0,
            new_sessions INT UNSIGNED DEFAULT 0,
            returning_sessions INT UNSIGNED DEFAULT 0,
            page_views INT UNSIGNED DEFAULT 0,
            property_views INT UNSIGNED DEFAULT 0,
            search_count INT UNSIGNED DEFAULT 0,
            bounce_sessions INT UNSIGNED DEFAULT 0,
            avg_session_duration INT UNSIGNED DEFAULT 0,
            avg_pages_per_session DECIMAL(5,2) DEFAULT 0,
            avg_scroll_depth DECIMAL(5,2) DEFAULT 0,
            platform_breakdown JSON DEFAULT NULL,
            device_breakdown JSON DEFAULT NULL,
            country_breakdown JSON DEFAULT NULL,
            top_cities JSON DEFAULT NULL,
            referrer_breakdown JSON DEFAULT NULL,
            top_pages JSON DEFAULT NULL,
            top_properties JSON DEFAULT NULL,
            top_searches JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY hour_timestamp (hour_timestamp),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);

        return $this->table_exists('hourly');
    }

    /**
     * Create daily aggregation table
     *
     * @param string $charset_collate Charset collation string
     * @return bool Success
     */
    private function create_daily_table($charset_collate) {
        $table = $this->tables['daily'];

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date DATE NOT NULL,
            unique_sessions INT UNSIGNED DEFAULT 0,
            new_sessions INT UNSIGNED DEFAULT 0,
            returning_sessions INT UNSIGNED DEFAULT 0,
            unique_visitors INT UNSIGNED DEFAULT 0,
            page_views INT UNSIGNED DEFAULT 0,
            property_views INT UNSIGNED DEFAULT 0,
            search_count INT UNSIGNED DEFAULT 0,
            contact_form_submissions INT UNSIGNED DEFAULT 0,
            favorite_actions INT UNSIGNED DEFAULT 0,
            bounce_sessions INT UNSIGNED DEFAULT 0,
            bounce_rate DECIMAL(5,2) DEFAULT 0,
            avg_session_duration INT UNSIGNED DEFAULT 0,
            avg_pages_per_session DECIMAL(5,2) DEFAULT 0,
            avg_scroll_depth DECIMAL(5,2) DEFAULT 0,
            sessions_change_pct DECIMAL(6,2) DEFAULT NULL,
            pageviews_change_pct DECIMAL(6,2) DEFAULT NULL,
            platform_breakdown JSON DEFAULT NULL,
            device_breakdown JSON DEFAULT NULL,
            country_breakdown JSON DEFAULT NULL,
            top_cities JSON DEFAULT NULL,
            referrer_breakdown JSON DEFAULT NULL,
            utm_breakdown JSON DEFAULT NULL,
            top_pages JSON DEFAULT NULL,
            top_properties JSON DEFAULT NULL,
            top_searches JSON DEFAULT NULL,
            browser_breakdown JSON DEFAULT NULL,
            os_breakdown JSON DEFAULT NULL,
            screen_sizes JSON DEFAULT NULL,
            hourly_distribution JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY date (date),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);

        return $this->table_exists('daily');
    }

    /**
     * Create real-time presence table (MEMORY engine)
     *
     * @param string $charset_collate Charset collation string
     * @return bool Success
     */
    private function create_presence_table($charset_collate) {
        $table = $this->tables['presence'];

        // Drop existing table first (MEMORY tables don't persist)
        $this->wpdb->query("DROP TABLE IF EXISTS {$table}");

        // Use MEMORY engine for fast reads/writes
        // Falls back to InnoDB if MEMORY not available
        $sql = "CREATE TABLE {$table} (
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            platform ENUM('web_desktop', 'web_mobile', 'web_tablet', 'ios_app') NOT NULL DEFAULT 'web_desktop',
            current_page TEXT DEFAULT NULL,
            current_page_type VARCHAR(50) DEFAULT NULL,
            current_listing_id VARCHAR(50) DEFAULT NULL,
            device_type ENUM('desktop', 'mobile', 'tablet') DEFAULT 'desktop',
            country_code VARCHAR(2) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            last_heartbeat DATETIME NOT NULL,
            PRIMARY KEY (session_id),
            KEY platform (platform),
            KEY current_page_type (current_page_type),
            KEY device_type (device_type),
            KEY last_heartbeat (last_heartbeat)
        ) ENGINE=MEMORY {$charset_collate};";

        $result = $this->wpdb->query($sql);

        // If MEMORY engine fails, try InnoDB
        if ($result === false) {
            $sql = str_replace('ENGINE=MEMORY', 'ENGINE=InnoDB', $sql);
            $this->wpdb->query($sql);
        }

        return $this->table_exists('presence');
    }

    /**
     * Check if table exists
     *
     * @param string $table Table key
     * @return bool
     */
    public function table_exists($table) {
        $table_name = $this->get_table($table);
        if (empty($table_name)) {
            return false;
        }
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Check if all tables exist
     *
     * @return bool
     */
    public function all_tables_exist() {
        foreach (array_keys($this->tables) as $table) {
            if (!$this->table_exists($table)) {
                return false;
            }
        }
        return true;
    }

    // =========================================================================
    // SESSION OPERATIONS
    // =========================================================================

    /**
     * Create or update session
     *
     * @param array $data Session data
     * @return bool|int Session ID on success, false on failure
     */
    public function upsert_session($data) {
        $table = $this->tables['sessions'];

        $defaults = array(
            'session_id'     => '',
            'platform'       => 'web_desktop',
            'first_seen'     => current_time('mysql'),
            'last_seen'      => current_time('mysql'),
            'page_views'     => 0,
            'property_views' => 0,
            'searches'       => 0,
            'is_bounce'      => 1,
            'is_bot'         => 0,
        );

        $data = wp_parse_args($data, $defaults);

        // Check if session exists
        $existing = $this->get_session($data['session_id']);

        if ($existing) {
            // Update existing session
            $update_data = array(
                'last_seen'      => current_time('mysql'),
                'page_views'     => $existing->page_views + ($data['page_views'] ?? 0),
                'property_views' => $existing->property_views + ($data['property_views'] ?? 0),
                'searches'       => $existing->searches + ($data['searches'] ?? 0),
            );

            // Update bounce status if more than one page view
            if ($update_data['page_views'] > 1) {
                $update_data['is_bounce'] = 0;
            }

            // Update user_id if newly logged in
            if (!empty($data['user_id']) && empty($existing->user_id)) {
                $update_data['user_id'] = $data['user_id'];
            }

            $result = $this->wpdb->update(
                $table,
                $update_data,
                array('session_id' => $data['session_id']),
                array('%s', '%d', '%d', '%d', '%d', '%d'),
                array('%s')
            );

            return $result !== false ? $existing->id : false;
        } else {
            // Insert new session
            $insert_data = array(
                'session_id'      => $data['session_id'],
                'visitor_hash'    => $data['visitor_hash'] ?? null,
                'user_id'         => $data['user_id'] ?? null,
                'platform'        => $data['platform'],
                'ip_address'      => $data['ip_address'] ?? null,
                'country_code'    => $data['country_code'] ?? null,
                'country_name'    => $data['country_name'] ?? null,
                'region'          => $data['region'] ?? null,
                'city'            => $data['city'] ?? null,
                'latitude'        => $data['latitude'] ?? null,
                'longitude'       => $data['longitude'] ?? null,
                'referrer_url'    => $data['referrer_url'] ?? null,
                'referrer_domain' => $data['referrer_domain'] ?? null,
                'utm_source'      => $data['utm_source'] ?? null,
                'utm_medium'      => $data['utm_medium'] ?? null,
                'utm_campaign'    => $data['utm_campaign'] ?? null,
                'utm_term'        => $data['utm_term'] ?? null,
                'utm_content'     => $data['utm_content'] ?? null,
                'device_type'     => $data['device_type'] ?? 'desktop',
                'browser'         => $data['browser'] ?? null,
                'browser_version' => $data['browser_version'] ?? null,
                'os'              => $data['os'] ?? null,
                'os_version'      => $data['os_version'] ?? null,
                'screen_width'    => $data['screen_width'] ?? null,
                'screen_height'   => $data['screen_height'] ?? null,
                'first_seen'      => $data['first_seen'],
                'last_seen'       => $data['last_seen'],
                'page_views'      => $data['page_views'],
                'property_views'  => $data['property_views'],
                'searches'        => $data['searches'],
                'is_bounce'       => $data['is_bounce'],
                'is_bot'          => $data['is_bot'],
            );

            $result = $this->wpdb->insert($table, $insert_data);

            return $result !== false ? $this->wpdb->insert_id : false;
        }
    }

    /**
     * Get session by session_id
     *
     * @param string $session_id Session ID
     * @return object|null Session row
     */
    public function get_session($session_id) {
        $table = $this->tables['sessions'];
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s",
            $session_id
        ));
    }

    /**
     * Get session by visitor hash
     *
     * @param string $visitor_hash Visitor hash
     * @return object|null Most recent session for visitor
     */
    public function get_session_by_visitor($visitor_hash) {
        $table = $this->tables['sessions'];
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE visitor_hash = %s ORDER BY last_seen DESC LIMIT 1",
            $visitor_hash
        ));
    }

    // =========================================================================
    // EVENT OPERATIONS
    // =========================================================================

    /**
     * Insert event
     *
     * @param array $data Event data
     * @return bool|int Event ID on success, false on failure
     */
    public function insert_event($data) {
        $table = $this->tables['events'];

        $defaults = array(
            'session_id'      => '',
            'event_type'      => 'page_view',
            'platform'        => 'web_desktop',
            'event_timestamp' => current_time('mysql'),
        );

        $data = wp_parse_args($data, $defaults);

        $insert_data = array(
            'session_id'           => $data['session_id'],
            'event_type'           => $data['event_type'],
            'event_category'       => $data['event_category'] ?? null,
            'platform'             => $data['platform'],
            'page_url'             => $data['page_url'] ?? null,
            'page_path'            => $data['page_path'] ?? null,
            'page_title'           => $data['page_title'] ?? null,
            'page_type'            => $data['page_type'] ?? null,
            'listing_id'           => $data['listing_id'] ?? null,
            'listing_key'          => $data['listing_key'] ?? null,
            'property_city'        => $data['property_city'] ?? null,
            'property_price'       => $data['property_price'] ?? null,
            'property_beds'        => $data['property_beds'] ?? null,
            'property_baths'       => $data['property_baths'] ?? null,
            'search_query'         => $data['search_query'] ?? null,
            'search_results_count' => $data['search_results_count'] ?? null,
            'click_target'         => $data['click_target'] ?? null,
            'click_element'        => $data['click_element'] ?? null,
            'scroll_depth'         => $data['scroll_depth'] ?? null,
            'time_on_page'         => $data['time_on_page'] ?? null,
            'event_data'           => isset($data['event_data']) ? wp_json_encode($data['event_data']) : null,
            'event_timestamp'      => $data['event_timestamp'],
        );

        $result = $this->wpdb->insert($table, $insert_data);

        return $result !== false ? $this->wpdb->insert_id : false;
    }

    /**
     * Batch insert events
     *
     * @param array $events Array of event data arrays
     * @return int Number of events inserted
     */
    public function batch_insert_events($events) {
        if (empty($events)) {
            return 0;
        }

        $inserted = 0;
        foreach ($events as $event) {
            if ($this->insert_event($event)) {
                $inserted++;
            }
        }

        return $inserted;
    }

    // =========================================================================
    // PRESENCE OPERATIONS (Real-time)
    // =========================================================================

    /**
     * Update presence (heartbeat)
     *
     * @param array $data Presence data
     * @return bool Success
     */
    public function update_presence($data) {
        $table = $this->tables['presence'];

        $presence_data = array(
            'session_id'         => $data['session_id'],
            'user_id'            => $data['user_id'] ?? null,
            'platform'           => $data['platform'] ?? 'web_desktop',
            'current_page'       => $data['current_page'] ?? null,
            'current_page_type'  => $data['current_page_type'] ?? null,
            'current_listing_id' => $data['current_listing_id'] ?? null,
            'device_type'        => $data['device_type'] ?? 'desktop',
            'country_code'       => $data['country_code'] ?? null,
            'city'               => $data['city'] ?? null,
            'last_heartbeat'     => current_time('mysql'),
        );

        // Use REPLACE INTO for upsert
        $columns = implode(', ', array_keys($presence_data));
        $placeholders = implode(', ', array_fill(0, count($presence_data), '%s'));
        $values = array_values($presence_data);

        $sql = "REPLACE INTO {$table} ({$columns}) VALUES ({$placeholders})";

        return $this->wpdb->query($this->wpdb->prepare($sql, $values)) !== false;
    }

    /**
     * Remove presence
     *
     * @param string $session_id Session ID
     * @return bool Success
     */
    public function remove_presence($session_id) {
        $table = $this->tables['presence'];
        return $this->wpdb->delete($table, array('session_id' => $session_id), array('%s')) !== false;
    }

    /**
     * Get active visitors count
     *
     * @param int $minutes Minutes threshold (default 5)
     * @return int Count of active visitors
     */
    public function get_active_visitors_count($minutes = 5) {
        $table = $this->tables['presence'];
        // Use WordPress current_time for consistency with last_heartbeat storage
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));

        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE last_heartbeat >= %s",
            $threshold
        ));
    }

    /**
     * Get active visitors
     *
     * @param int $minutes Minutes threshold (default 5)
     * @return array Active visitor data
     */
    public function get_active_visitors($minutes = 5) {
        $table = $this->tables['presence'];
        // Use WordPress current_time for consistency with last_heartbeat storage
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE last_heartbeat >= %s ORDER BY last_heartbeat DESC",
            $threshold
        ));
    }

    /**
     * Cleanup stale presence entries
     *
     * @param int $minutes Stale threshold in minutes (default 5)
     * @return int Number of rows deleted
     */
    public function cleanup_stale_presence($minutes = 5) {
        $table = $this->tables['presence'];
        // Use WordPress current_time for consistency with last_heartbeat storage
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));

        return $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE last_heartbeat < %s",
            $threshold
        ));
    }

    // =========================================================================
    // ADMIN DASHBOARD QUERIES
    // =========================================================================

    /**
     * Get real-time dashboard data
     *
     * @return array Dashboard data
     */
    public function get_realtime_data() {
        $presence_table = $this->tables['presence'];
        $events_table = $this->tables['events'];
        // Use WordPress current_time for consistency with last_heartbeat storage
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - (5 * 60));

        // Active visitors
        $active_count = $this->get_active_visitors_count();

        // Active visitors by platform
        $by_platform = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT platform, COUNT(*) as count
             FROM {$presence_table}
             WHERE last_heartbeat >= %s
             GROUP BY platform",
            $threshold
        ), OBJECT_K);

        // Active visitors by page type
        $by_page_type = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT current_page_type, COUNT(*) as count
             FROM {$presence_table}
             WHERE last_heartbeat >= %s AND current_page_type IS NOT NULL
             GROUP BY current_page_type",
            $threshold
        ), OBJECT_K);

        // Active visitors by device
        $by_device = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT device_type, COUNT(*) as count
             FROM {$presence_table}
             WHERE last_heartbeat >= %s
             GROUP BY device_type",
            $threshold
        ), OBJECT_K);

        // Active visitors by country
        $by_country = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT country_code, COUNT(*) as count
             FROM {$presence_table}
             WHERE last_heartbeat >= %s AND country_code IS NOT NULL
             GROUP BY country_code
             ORDER BY count DESC
             LIMIT 10",
            $threshold
        ));

        // Recent activity stream (last 20 events from last 5 minutes)
        // Enhanced query with property details and visitor location
        $sessions_table = $this->tables['sessions'];
        $listing_summary_table = $this->wpdb->prefix . 'bme_listing_summary';

        // Property URLs can be in two formats:
        // 1. Simple: /property/73124902/
        // 2. SEO-friendly: /property/89-glendower-rd-boston-ma-02131-9-bed-3-bath-for-sale-73436358/
        // Additionally, stored listing_id might be a listing_key hash (32 char hex) - handle both
        $activity_stream = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                e.event_type,
                e.event_category,
                e.platform,
                e.page_type,
                e.page_path,
                e.page_title,
                COALESCE(l.listing_id, e.listing_id) as listing_id,
                e.property_city,
                e.property_price,
                e.property_beds,
                e.property_baths,
                e.search_query,
                e.search_results_count,
                e.scroll_depth,
                e.time_on_page,
                e.event_timestamp,
                s.city as visitor_city,
                s.country_code as visitor_country,
                s.device_type,
                s.browser,
                l.street_number,
                l.street_name,
                l.city as property_city_db,
                l.list_price,
                l.bedrooms_total,
                l.bathrooms_total,
                l.main_photo_url,
                l.property_sub_type
             FROM {$events_table} e
             LEFT JOIN {$sessions_table} s ON e.session_id = s.session_id
             LEFT JOIN {$listing_summary_table} l ON (
                -- Method 1: Extract listing_id from URL (simple format)
                (e.page_path LIKE '/property/%%'
                 AND SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1) REGEXP '^[0-9]+$'
                 AND l.listing_id = SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1))
                -- Method 2: Extract listing_id from SEO URL (after last hyphen)
                OR (e.page_path LIKE '/property/%%'
                    AND SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1) REGEXP '^[0-9]+$'
                    AND l.listing_id = SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1))
                -- Method 3: Stored listing_id is a hash - match by listing_key
                OR (LENGTH(e.listing_id) = 32 AND l.listing_key = e.listing_id)
                -- Method 4: Stored listing_id is an MLS number - direct match
                OR (e.listing_id REGEXP '^[0-9]+$' AND l.listing_id = e.listing_id)
             )
             WHERE e.event_timestamp >= %s
             ORDER BY e.event_timestamp DESC
             LIMIT 30",
            $threshold
        ));

        // Calculate flattened counts for JS compatibility
        $web_count = 0;
        $ios_count = 0;
        foreach ($by_platform as $platform => $data) {
            if (strpos($platform, 'web') === 0) {
                $web_count += (int) $data->count;
            } elseif ($platform === 'ios_app') {
                $ios_count = (int) $data->count;
            }
        }

        // Convert event timestamps from server time to WordPress timezone (v6.45.4 fix)
        // This ensures JS displays correct relative times like "5m ago" instead of "Just now"
        $server_tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $wp_tz = new DateTimeZone(wp_timezone_string());
        foreach ($activity_stream as &$event) {
            if (!empty($event->event_timestamp)) {
                $dt = new DateTime($event->event_timestamp, $server_tz);
                $dt->setTimezone($wp_tz);
                $event->event_timestamp = $dt->format('Y-m-d H:i:s');
            }
        }
        unset($event); // Break reference

        return array(
            // Flattened fields for JS compatibility
            'total'           => $active_count,
            'web'             => $web_count,
            'ios_app'         => $ios_count,
            // Detailed breakdowns
            'active_count'    => $active_count,
            'by_platform'     => $by_platform,
            'by_page_type'    => $by_page_type,
            'by_device'       => $by_device,
            'by_country'      => $by_country,
            'activity_stream' => $activity_stream,
            'timestamp'       => current_time('mysql'),
        );
    }

    /**
     * Get enhanced activity stream with user info, pagination, and filtering
     *
     * @since 6.47.0
     *
     * @param array $args {
     *     Optional. Arguments to filter and paginate results.
     *
     *     @type int    $limit         Number of results per page. Default 50.
     *     @type int    $offset        Offset for pagination. Default 0.
     *     @type string $start_date    Start date (Y-m-d H:i:s or relative like '7 days ago'). Default 7 days ago.
     *     @type string $end_date      End date (Y-m-d H:i:s). Default now.
     *     @type string $platform      Filter by platform: 'web', 'ios', or empty for all.
     *     @type bool   $logged_in_only Only show logged-in users. Default false.
     *     @type string $event_type    Filter by event type (comma-separated).
     * }
     * @return array {
     *     @type array  $events      Array of event objects with user/session data.
     *     @type int    $total       Total matching events (for pagination).
     *     @type bool   $has_more    Whether there are more results.
     *     @type int    $page        Current page number.
     *     @type int    $per_page    Results per page.
     * }
     */
    public function get_activity_stream_enhanced($args = array()) {
        $defaults = array(
            'limit'          => 50,
            'offset'         => 0,
            'start_date'     => date('Y-m-d H:i:s', strtotime('-7 days')),
            'end_date'       => current_time('mysql'),
            'platform'       => '',
            'logged_in_only' => false,
            'event_type'     => '',
        );

        $args = wp_parse_args($args, $defaults);

        $events_table = $this->tables['events'];
        $sessions_table = $this->tables['sessions'];
        $listing_summary_table = $this->wpdb->prefix . 'bme_listing_summary';
        $users_table = $this->wpdb->users;

        // Build WHERE conditions
        $where = array("e.event_timestamp BETWEEN %s AND %s");
        $params = array($args['start_date'], $args['end_date']);

        // Platform filter - supports both generic (web, ios) and specific (web_desktop, etc.)
        if (!empty($args['platform'])) {
            if ($args['platform'] === 'web') {
                $where[] = "e.platform IN ('web_desktop', 'web_mobile', 'web_tablet')";
            } elseif ($args['platform'] === 'ios') {
                $where[] = "e.platform = 'ios_app'";
            } elseif (in_array($args['platform'], array('web_desktop', 'web_mobile', 'web_tablet', 'ios_app'))) {
                // Specific platform filter (v6.47.1)
                $where[] = $this->wpdb->prepare("e.platform = %s", $args['platform']);
            }
        }

        // Logged-in only filter
        if ($args['logged_in_only']) {
            $where[] = "s.user_id IS NOT NULL AND s.user_id > 0";
        }

        // Event type filter
        if (!empty($args['event_type'])) {
            $types = array_map('trim', explode(',', $args['event_type']));
            $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
            $where[] = "e.event_type IN ({$type_placeholders})";
            $params = array_merge($params, $types);
        }

        $where_clause = implode(' AND ', $where);

        // Get total count for pagination
        $count_sql = "SELECT COUNT(*)
                      FROM {$events_table} e
                      LEFT JOIN {$sessions_table} s ON e.session_id = s.session_id
                      WHERE {$where_clause}";
        $total = (int) $this->wpdb->get_var($this->wpdb->prepare($count_sql, $params));

        // Main query with user info and property details
        $sql = "SELECT
                    e.id,
                    e.session_id,
                    e.event_type,
                    e.event_category,
                    e.platform,
                    e.page_type,
                    e.page_path,
                    e.page_title,
                    e.page_url,
                    COALESCE(l.listing_id, e.listing_id) as listing_id,
                    e.property_city,
                    e.property_price,
                    e.property_beds,
                    e.property_baths,
                    e.search_query,
                    e.search_results_count,
                    e.scroll_depth,
                    e.time_on_page,
                    e.event_timestamp,
                    -- Session data
                    s.user_id,
                    s.visitor_hash,
                    s.referrer_domain,
                    s.referrer_url,
                    s.utm_source,
                    s.utm_medium,
                    s.utm_campaign,
                    s.city as visitor_city,
                    s.region as visitor_region,
                    s.country_code as visitor_country,
                    s.country_name as visitor_country_name,
                    s.device_type,
                    s.browser,
                    s.os,
                    s.page_views as session_page_views,
                    s.property_views as session_property_views,
                    s.first_seen as session_start,
                    -- User data (for logged-in users)
                    u.display_name as user_display_name,
                    u.user_email,
                    -- Property data
                    l.street_number,
                    l.street_name,
                    l.city as property_city_db,
                    l.list_price,
                    l.bedrooms_total,
                    l.bathrooms_total,
                    l.main_photo_url,
                    l.property_sub_type
                FROM {$events_table} e
                LEFT JOIN {$sessions_table} s ON e.session_id = s.session_id
                LEFT JOIN {$users_table} u ON s.user_id = u.ID
                LEFT JOIN {$listing_summary_table} l ON (
                    -- Method 1: Extract listing_id from URL (simple format)
                    (e.page_path LIKE '/property/%%'
                     AND SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1) REGEXP '^[0-9]+$'
                     AND l.listing_id = SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1))
                    -- Method 2: Extract listing_id from SEO URL (after last hyphen)
                    OR (e.page_path LIKE '/property/%%'
                        AND SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1) REGEXP '^[0-9]+$'
                        AND l.listing_id = SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1))
                    -- Method 3: Stored listing_id is a hash - match by listing_key
                    OR (LENGTH(e.listing_id) = 32 AND l.listing_key = e.listing_id)
                    -- Method 4: Stored listing_id is an MLS number - direct match
                    OR (e.listing_id REGEXP '^[0-9]+$' AND l.listing_id = e.listing_id)
                )
                WHERE {$where_clause}
                ORDER BY e.event_timestamp DESC
                LIMIT %d OFFSET %d";

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        $events = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));

        // Convert timestamps to WordPress timezone
        $server_tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $wp_tz = new DateTimeZone(wp_timezone_string());

        foreach ($events as &$event) {
            if (!empty($event->event_timestamp)) {
                $dt = new DateTime($event->event_timestamp, $server_tz);
                $dt->setTimezone($wp_tz);
                $event->event_timestamp = $dt->format('Y-m-d H:i:s');
            }

            // Check if this is a returning visitor
            $event->is_returning = $this->is_returning_visitor($event->visitor_hash, $event->session_id);

            // Normalize referrer to friendly source name
            $event->source_name = $this->normalize_source_name($event->referrer_domain);
        }
        unset($event);

        return array(
            'events'   => $events,
            'total'    => $total,
            'has_more' => ($args['offset'] + count($events)) < $total,
            'page'     => floor($args['offset'] / $args['limit']) + 1,
            'per_page' => $args['limit'],
        );
    }

    /**
     * Check if visitor is returning (has previous sessions)
     *
     * @since 6.47.0
     *
     * @param string $visitor_hash Visitor hash
     * @param string $current_session_id Current session ID to exclude
     * @return bool True if returning visitor
     */
    private function is_returning_visitor($visitor_hash, $current_session_id) {
        if (empty($visitor_hash)) {
            return false;
        }

        $sessions_table = $this->tables['sessions'];
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table}
             WHERE visitor_hash = %s AND session_id != %s",
            $visitor_hash,
            $current_session_id
        ));

        return (int) $count > 0;
    }

    /**
     * Normalize referrer domain to friendly source name
     *
     * @since 6.47.0
     *
     * @param string|null $referrer_domain Referrer domain
     * @return string Friendly source name
     */
    private function normalize_source_name($referrer_domain) {
        if (empty($referrer_domain)) {
            return 'Direct';
        }

        $domain = strtolower($referrer_domain);

        $source_map = array(
            'google'     => 'Google',
            'bing'       => 'Bing',
            'yahoo'      => 'Yahoo',
            'duckduckgo' => 'DuckDuckGo',
            'baidu'      => 'Baidu',
            'yandex'     => 'Yandex',
            'facebook'   => 'Facebook',
            'fb.'        => 'Facebook',
            'instagram'  => 'Instagram',
            'twitter'    => 'Twitter/X',
            't.co'       => 'Twitter/X',
            'linkedin'   => 'LinkedIn',
            'pinterest'  => 'Pinterest',
            'reddit'     => 'Reddit',
            'chatgpt'    => 'ChatGPT',
            'openai'     => 'ChatGPT',
            'tiktok'     => 'TikTok',
            'youtube'    => 'YouTube',
        );

        foreach ($source_map as $pattern => $name) {
            if (strpos($domain, $pattern) !== false) {
                return $name;
            }
        }

        return $referrer_domain;
    }

    /**
     * Get session journey (all events for a session)
     *
     * @since 6.47.0
     *
     * @param string $session_id Session ID
     * @return array {
     *     @type object $session  Session metadata.
     *     @type array  $events   Array of events in chronological order.
     * }
     */
    public function get_session_journey($session_id) {
        $events_table = $this->tables['events'];
        $sessions_table = $this->tables['sessions'];
        $listing_summary_table = $this->wpdb->prefix . 'bme_listing_summary';
        $users_table = $this->wpdb->users;

        // Get session metadata
        $session = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                s.*,
                u.display_name as user_display_name,
                u.user_email
             FROM {$sessions_table} s
             LEFT JOIN {$users_table} u ON s.user_id = u.ID
             WHERE s.session_id = %s",
            $session_id
        ));

        if (!$session) {
            return array(
                'session' => null,
                'events'  => array(),
            );
        }

        // Add friendly source name
        $session->source_name = $this->normalize_source_name($session->referrer_domain);

        // Get all events for this session
        $events = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                e.id,
                e.event_type,
                e.event_category,
                e.platform,
                e.page_type,
                e.page_path,
                e.page_title,
                e.page_url,
                COALESCE(l.listing_id, e.listing_id) as listing_id,
                e.property_city,
                e.search_query,
                e.search_results_count,
                e.scroll_depth,
                e.time_on_page,
                e.event_timestamp,
                l.street_number,
                l.street_name,
                l.city as property_city_db,
                l.list_price,
                l.main_photo_url
             FROM {$events_table} e
             LEFT JOIN {$listing_summary_table} l ON (
                (e.page_path LIKE '/property/%%'
                 AND SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1) REGEXP '^[0-9]+$'
                 AND l.listing_id = SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1))
                OR (e.page_path LIKE '/property/%%'
                    AND SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1) REGEXP '^[0-9]+$'
                    AND l.listing_id = SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1))
                OR (LENGTH(e.listing_id) = 32 AND l.listing_key = e.listing_id)
                OR (e.listing_id REGEXP '^[0-9]+$' AND l.listing_id = e.listing_id)
             )
             WHERE e.session_id = %s
             ORDER BY e.event_timestamp ASC",
            $session_id
        ));

        // Convert timestamps
        $server_tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $wp_tz = new DateTimeZone(wp_timezone_string());

        foreach ($events as &$event) {
            if (!empty($event->event_timestamp)) {
                $dt = new DateTime($event->event_timestamp, $server_tz);
                $dt->setTimezone($wp_tz);
                $event->event_timestamp = $dt->format('Y-m-d H:i:s');
            }
        }
        unset($event);

        return array(
            'session' => $session,
            'events'  => $events,
        );
    }

    /**
     * Get statistics for date range
     *
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param string|null $platform Filter by platform (optional)
     * @return array Statistics
     */
    public function get_stats($start_date, $end_date, $platform = null) {
        $daily_table = $this->tables['daily'];
        $sessions_table = $this->tables['sessions'];
        $events_table = $this->tables['events'];

        // Try to get from daily aggregates first
        $where_platform = $platform ? $this->wpdb->prepare(" AND platform = %s", $platform) : '';

        // Get aggregated stats
        $stats = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                SUM(unique_sessions) as total_sessions,
                SUM(page_views) as total_pageviews,
                SUM(property_views) as total_property_views,
                SUM(search_count) as total_searches,
                AVG(bounce_rate) as avg_bounce_rate,
                AVG(avg_session_duration) as avg_session_duration,
                AVG(avg_pages_per_session) as avg_pages_per_session
             FROM {$daily_table}
             WHERE date BETWEEN %s AND %s" . $where_platform,
            $start_date,
            $end_date
        ));

        // If aggregates are empty, fall back to raw session/event data
        if (empty($stats->total_sessions)) {
            $platform_where = $platform ? $this->wpdb->prepare(" AND platform = %s", $platform) : '';

            // Query raw sessions
            $stats = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT session_id) as total_sessions,
                    SUM(page_views) as total_pageviews,
                    SUM(property_views) as total_property_views,
                    SUM(searches) as total_searches,
                    AVG(CASE WHEN is_bounce = 1 THEN 100 ELSE 0 END) as avg_bounce_rate,
                    AVG(TIMESTAMPDIFF(SECOND, first_seen, last_seen)) as avg_session_duration,
                    AVG(page_views) as avg_pages_per_session
                 FROM {$sessions_table}
                 WHERE DATE(first_seen) BETWEEN %s AND %s
                   AND is_bot = 0" . $platform_where,
                $start_date,
                $end_date
            ));
        }

        // Get comparison with previous period
        $days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
        $prev_start = date('Y-m-d', strtotime($start_date) - ($days * 86400));
        $prev_end = date('Y-m-d', strtotime($start_date) - 86400);

        $prev_stats = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                COUNT(DISTINCT session_id) as total_sessions,
                SUM(page_views) as total_pageviews,
                SUM(property_views) as total_property_views
             FROM {$sessions_table}
             WHERE DATE(first_seen) BETWEEN %s AND %s
               AND is_bot = 0",
            $prev_start,
            $prev_end
        ));

        // Calculate changes
        $sessions_change = $this->calculate_change($prev_stats->total_sessions ?? 0, $stats->total_sessions ?? 0);
        $pageviews_change = $this->calculate_change($prev_stats->total_pageviews ?? 0, $stats->total_pageviews ?? 0);
        $property_views_change = $this->calculate_change($prev_stats->total_property_views ?? 0, $stats->total_property_views ?? 0);

        return array(
            'total_sessions'        => (int) ($stats->total_sessions ?? 0),
            'total_pageviews'       => (int) ($stats->total_pageviews ?? 0),
            'total_property_views'  => (int) ($stats->total_property_views ?? 0),
            'total_searches'        => (int) ($stats->total_searches ?? 0),
            'avg_bounce_rate'       => round($stats->avg_bounce_rate ?? 0, 1),
            'avg_session_duration'  => (int) ($stats->avg_session_duration ?? 0),
            'avg_pages_per_session' => round($stats->avg_pages_per_session ?? 0, 1),
            'sessions_change'       => $sessions_change,
            'pageviews_change'      => $pageviews_change,
            'property_views_change' => $property_views_change,
        );
    }

    /**
     * Get trend data for charts
     *
     * Falls back to raw session data if aggregation tables are empty or have no real data.
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param string $granularity 'hourly' or 'daily'
     * @return array Trend data
     */
    public function get_trends($start_date, $end_date, $granularity = 'daily') {
        $sessions_table = $this->tables['sessions'];

        if ($granularity === 'hourly') {
            $table = $this->tables['hourly'];
            $date_col = 'hour_timestamp';
        } else {
            $table = $this->tables['daily'];
            $date_col = 'date';
        }

        // Try aggregation tables first
        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$date_col} as timestamp,
                    unique_sessions, page_views, property_views, search_count,
                    platform_breakdown
             FROM {$table}
             WHERE {$date_col} BETWEEN %s AND %s
             ORDER BY {$date_col} ASC",
            $start_date,
            $end_date
        ));

        // Check if we have meaningful data (not just zeros)
        $has_data = false;
        foreach ($results as $row) {
            if (($row->unique_sessions ?? 0) > 0 || ($row->page_views ?? 0) > 0) {
                $has_data = true;
                break;
            }
        }

        // Fall back to raw event data if aggregates are empty/zeros (v6.45.4 fix)
        // Query events table directly for accurate counts, not session aggregates
        if (empty($results) || !$has_data) {
            $events_table = $this->tables['events'];

            if ($granularity === 'hourly') {
                $results = $this->wpdb->get_results($this->wpdb->prepare(
                    "SELECT
                        DATE_FORMAT(event_timestamp, '%%Y-%%m-%%d %%H:00:00') as timestamp,
                        COUNT(DISTINCT session_id) as unique_sessions,
                        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) as page_views,
                        SUM(CASE WHEN event_type = 'property_view' THEN 1 ELSE 0 END) as property_views,
                        SUM(CASE WHEN event_type IN ('search', 'search_execute') THEN 1 ELSE 0 END) as search_count,
                        NULL as platform_breakdown
                     FROM {$events_table}
                     WHERE event_timestamp BETWEEN %s AND %s
                     GROUP BY DATE_FORMAT(event_timestamp, '%%Y-%%m-%%d %%H:00:00')
                     ORDER BY timestamp ASC",
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                ));
            } else {
                $results = $this->wpdb->get_results($this->wpdb->prepare(
                    "SELECT
                        DATE(event_timestamp) as timestamp,
                        COUNT(DISTINCT session_id) as unique_sessions,
                        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) as page_views,
                        SUM(CASE WHEN event_type = 'property_view' THEN 1 ELSE 0 END) as property_views,
                        SUM(CASE WHEN event_type IN ('search', 'search_execute') THEN 1 ELSE 0 END) as search_count,
                        NULL as platform_breakdown
                     FROM {$events_table}
                     WHERE DATE(event_timestamp) BETWEEN %s AND %s
                     GROUP BY DATE(event_timestamp)
                     ORDER BY timestamp ASC",
                    $start_date,
                    $end_date
                ));
            }
        }

        return $results;
    }

    /**
     * Get top content
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param string $type 'pages' or 'properties'
     * @param int $limit Number of results
     * @return array Top content
     */
    public function get_top_content($start_date, $end_date, $type = 'pages', $limit = 10) {
        $events_table = $this->tables['events'];
        $listing_summary_table = $this->wpdb->prefix . 'bme_listing_summary';

        if ($type === 'properties') {
            // Property URLs can be in two formats:
            // 1. Simple: /property/73124902/
            // 2. SEO-friendly: /property/89-glendower-rd-boston-ma-02131-9-bed-3-bath-for-sale-73436358/
            // Additionally, stored listing_id might be a listing_key hash (32 char hex)
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT
                        COALESCE(l.listing_id, e.listing_id) as listing_id,
                        COUNT(*) as views,
                        COUNT(DISTINCT e.session_id) as unique_viewers,
                        MAX(e.property_price) as price,
                        l.street_number,
                        l.street_name,
                        l.city as property_city,
                        l.list_price,
                        l.bedrooms_total,
                        l.bathrooms_total,
                        l.main_photo_url,
                        l.property_sub_type
                 FROM {$events_table} e
                 LEFT JOIN {$listing_summary_table} l ON (
                    -- Method 1: Extract from URL (simple format /property/12345/)
                    (e.page_path LIKE '/property/%%'
                     AND SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1) REGEXP '^[0-9]+$'
                     AND l.listing_id = SUBSTRING_INDEX(SUBSTRING_INDEX(e.page_path, '/property/', -1), '/', 1))
                    -- Method 2: Extract from SEO URL (after last hyphen)
                    OR (e.page_path LIKE '/property/%%'
                        AND SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1) REGEXP '^[0-9]+$'
                        AND l.listing_id = SUBSTRING_INDEX(TRIM(TRAILING '/' FROM e.page_path), '-', -1))
                    -- Method 3: Stored listing_id is a hash - match by listing_key
                    OR (LENGTH(e.listing_id) = 32 AND l.listing_key = e.listing_id)
                    -- Method 4: Stored listing_id is an MLS number
                    OR (e.listing_id REGEXP '^[0-9]+$' AND l.listing_id = e.listing_id)
                 )
                 WHERE e.event_type = 'property_view'
                   AND e.event_timestamp BETWEEN %s AND %s
                 GROUP BY COALESCE(l.listing_id, e.listing_id), l.street_number, l.street_name, l.city,
                          l.list_price, l.bedrooms_total, l.bathrooms_total, l.main_photo_url, l.property_sub_type
                 HAVING listing_id IS NOT NULL
                 ORDER BY views DESC
                 LIMIT %d",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit
            ));
        } else {
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT page_path, page_title, page_type,
                        COUNT(*) as views,
                        COUNT(DISTINCT session_id) as unique_viewers,
                        AVG(time_on_page) as avg_time_on_page
                 FROM {$events_table}
                 WHERE event_type = 'page_view'
                   AND event_timestamp BETWEEN %s AND %s
                   AND page_path IS NOT NULL
                 GROUP BY page_path, page_title, page_type
                 ORDER BY views DESC
                 LIMIT %d",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit
            ));
        }
    }

    /**
     * Get traffic sources
     *
     * v6.46.0: Normalizes search engine domains (google.com, google.co.uk -> Google)
     * and groups all variants together for accurate reporting.
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param int $limit Number of results
     * @return array Traffic sources
     */
    public function get_traffic_sources($start_date, $end_date, $limit = 10) {
        $sessions_table = $this->tables['sessions'];

        // Use CASE to normalize search engine domains before grouping
        // This consolidates google.com, google.co.uk, google.de etc. into "Google"
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT
                CASE
                    WHEN referrer_domain IS NULL OR referrer_domain = '' THEN 'Direct'
                    WHEN referrer_domain LIKE '%%google%%' THEN 'Google'
                    WHEN referrer_domain LIKE '%%bing%%' THEN 'Bing'
                    WHEN referrer_domain LIKE '%%yahoo%%' THEN 'Yahoo'
                    WHEN referrer_domain LIKE '%%duckduckgo%%' THEN 'DuckDuckGo'
                    WHEN referrer_domain LIKE '%%baidu%%' THEN 'Baidu'
                    WHEN referrer_domain LIKE '%%yandex%%' THEN 'Yandex'
                    WHEN referrer_domain LIKE '%%facebook%%' OR referrer_domain LIKE '%%fb.%%' THEN 'Facebook'
                    WHEN referrer_domain LIKE '%%instagram%%' THEN 'Instagram'
                    WHEN referrer_domain LIKE '%%twitter%%' OR referrer_domain LIKE '%%t.co%%' THEN 'Twitter/X'
                    WHEN referrer_domain LIKE '%%linkedin%%' THEN 'LinkedIn'
                    WHEN referrer_domain LIKE '%%pinterest%%' THEN 'Pinterest'
                    WHEN referrer_domain LIKE '%%reddit%%' THEN 'Reddit'
                    WHEN referrer_domain LIKE '%%chatgpt%%' OR referrer_domain LIKE '%%openai%%' THEN 'ChatGPT'
                    ELSE referrer_domain
                END as source,
                COUNT(*) as sessions,
                SUM(page_views) as page_views,
                AVG(is_bounce) * 100 as bounce_rate
             FROM {$sessions_table}
             WHERE first_seen BETWEEN %s AND %s
               AND is_bot = 0
             GROUP BY source
             ORDER BY sessions DESC
             LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $limit
        ));
    }

    /**
     * Get geographic distribution
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @param string $level 'country' or 'city'
     * @param int $limit Number of results
     * @return array Geographic data
     */
    public function get_geographic_data($start_date, $end_date, $level = 'country', $limit = 10) {
        $sessions_table = $this->tables['sessions'];

        if ($level === 'city') {
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT city, country_code, region,
                        COUNT(*) as sessions,
                        SUM(page_views) as page_views
                 FROM {$sessions_table}
                 WHERE first_seen BETWEEN %s AND %s
                   AND is_bot = 0
                   AND city IS NOT NULL
                 GROUP BY city, country_code, region
                 ORDER BY sessions DESC
                 LIMIT %d",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit
            ));
        } else {
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT country_code, country_name,
                        COUNT(*) as sessions,
                        SUM(page_views) as page_views
                 FROM {$sessions_table}
                 WHERE first_seen BETWEEN %s AND %s
                   AND is_bot = 0
                   AND country_code IS NOT NULL
                 GROUP BY country_code, country_name
                 ORDER BY sessions DESC
                 LIMIT %d",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $limit
            ));
        }
    }

    // =========================================================================
    // CLEANUP OPERATIONS
    // =========================================================================

    /**
     * Delete events older than retention period
     *
     * @param int $days Days to retain (default 30)
     * @return int Number of rows deleted
     */
    public function cleanup_old_events($days = 30) {
        $table = $this->tables['events'];
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $threshold
        ));
    }

    /**
     * Delete sessions older than retention period
     *
     * @param int $days Days to retain (default 30)
     * @return int Number of rows deleted
     */
    public function cleanup_old_sessions($days = 30) {
        $table = $this->tables['sessions'];
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table} WHERE last_seen < %s",
            $threshold
        ));
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Calculate percentage change
     *
     * @param float $old Old value
     * @param float $new New value
     * @return float|null Percentage change
     */
    private function calculate_change($old, $new) {
        if (empty($old)) {
            return $new > 0 ? 100 : null;
        }
        return round((($new - $old) / $old) * 100, 1);
    }

    /**
     * Drop all analytics tables
     *
     * Use with caution - for testing only.
     *
     * @return bool Success
     */
    public function drop_all_tables() {
        foreach ($this->tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option(self::VERSION_OPTION);
        return true;
    }

    /**
     * Get database statistics with breakdowns
     *
     * Returns table row counts plus platform, device, and browser breakdowns.
     *
     * @return array Stats including counts and breakdowns
     */
    public function get_db_stats() {
        $sessions_table = $this->tables['sessions'];

        // Get table row counts
        $stats = array();
        foreach ($this->tables as $key => $table) {
            $stats[$key] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        // Get platform breakdown from sessions
        $platforms_result = $this->wpdb->get_results(
            "SELECT platform, COUNT(*) as count
             FROM {$sessions_table}
             WHERE is_bot = 0
             GROUP BY platform"
        );

        $platforms = array(
            'web_desktop' => 0,
            'web_mobile'  => 0,
            'web_tablet'  => 0,
            'ios_app'     => 0,
        );
        foreach ($platforms_result as $row) {
            if (isset($platforms[$row->platform])) {
                $platforms[$row->platform] = (int) $row->count;
            }
        }
        $stats['platforms'] = $platforms;

        // Get device breakdown
        $devices_result = $this->wpdb->get_results(
            "SELECT device_type, COUNT(*) as count
             FROM {$sessions_table}
             WHERE is_bot = 0
             GROUP BY device_type"
        );

        $devices = array(
            'desktop' => 0,
            'mobile'  => 0,
            'tablet'  => 0,
        );
        foreach ($devices_result as $row) {
            if (isset($devices[$row->device_type])) {
                $devices[$row->device_type] = (int) $row->count;
            }
        }
        $stats['devices'] = $devices;

        // Get browser breakdown (top 5)
        $browsers_result = $this->wpdb->get_results(
            "SELECT browser, COUNT(*) as count
             FROM {$sessions_table}
             WHERE is_bot = 0 AND browser IS NOT NULL AND browser != ''
             GROUP BY browser
             ORDER BY count DESC
             LIMIT 5"
        );

        $browsers = array();
        foreach ($browsers_result as $row) {
            $browsers[$row->browser] = (int) $row->count;
        }
        $stats['browsers'] = $browsers;

        return $stats;
    }
}
