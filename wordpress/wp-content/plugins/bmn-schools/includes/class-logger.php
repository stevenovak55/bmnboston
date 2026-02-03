<?php
/**
 * Activity Logger Class
 *
 * Handles logging for debugging and tracking data imports, API calls, and errors.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Activity Logger Class
 *
 * @since 0.1.0
 */
class BMN_Schools_Logger {

    /**
     * Log levels.
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /**
     * Log types.
     */
    const TYPE_IMPORT = 'import';
    const TYPE_API_CALL = 'api_call';
    const TYPE_SYNC = 'sync';
    const TYPE_ERROR = 'error';
    const TYPE_ADMIN = 'admin';
    const TYPE_INIT = 'init';

    /**
     * Table name for activity log.
     *
     * @var string
     */
    private static $table_name = null;

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct() {
        self::init_table_name();
    }

    /**
     * Initialize the table name.
     *
     * @since 0.1.0
     */
    private static function init_table_name() {
        if (is_null(self::$table_name)) {
            global $wpdb;
            self::$table_name = $wpdb->prefix . 'bmn_schools_activity_log';
        }
    }

    /**
     * Log a message.
     *
     * @since 0.1.0
     * @param string $level   Log level (debug, info, warning, error).
     * @param string $type    Log type (import, api_call, sync, error, admin).
     * @param string $message Human-readable message.
     * @param array  $context Additional context data (will be JSON encoded).
     * @return bool|int False on failure, log ID on success.
     */
    public static function log($level, $type, $message, $context = []) {
        global $wpdb;

        self::init_table_name();

        // Skip debug logs unless debug mode is enabled
        if ($level === self::LEVEL_DEBUG && (!defined('BMN_SCHOOLS_DEBUG') || !BMN_SCHOOLS_DEBUG)) {
            return false;
        }

        // Also log errors to error_log
        if ($level === self::LEVEL_ERROR) {
            error_log(sprintf('[BMN Schools] %s: %s - %s', $type, $message, json_encode($context)));
        }

        // Check if table exists (may not during activation)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '" . self::$table_name . "'") === self::$table_name;

        if (!$table_exists) {
            // Fall back to error_log if table doesn't exist
            if ($level !== self::LEVEL_DEBUG) {
                error_log(sprintf('[BMN Schools] %s [%s]: %s', $level, $type, $message));
            }
            return false;
        }

        $data = [
            'level' => $level,
            'type' => $type,
            'source' => isset($context['source']) ? $context['source'] : null,
            'message' => $message,
            'context' => !empty($context) ? wp_json_encode($context) : null,
            'duration_ms' => isset($context['duration_ms']) ? intval($context['duration_ms']) : null,
            'user_id' => get_current_user_id() ?: null,
        ];

        $result = $wpdb->insert(self::$table_name, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Log import start.
     *
     * @since 0.1.0
     * @param string $source Source name (nces, dese, etc.).
     * @param int    $count  Expected record count.
     */
    public static function import_started($source, $count = 0) {
        self::log(self::LEVEL_INFO, self::TYPE_IMPORT, 'Import started', [
            'source' => $source,
            'expected_count' => $count
        ]);
    }

    /**
     * Log import completion.
     *
     * @since 0.1.0
     * @param string $source      Source name.
     * @param int    $count       Records imported.
     * @param float  $duration_ms Duration in milliseconds.
     */
    public static function import_completed($source, $count, $duration_ms = null) {
        self::log(self::LEVEL_INFO, self::TYPE_IMPORT, 'Import completed', [
            'source' => $source,
            'count' => $count,
            'duration_ms' => $duration_ms
        ]);
    }

    /**
     * Log import failure.
     *
     * @since 0.1.0
     * @param string $source Source name.
     * @param string $error  Error message.
     * @param array  $context Additional context.
     */
    public static function import_failed($source, $error, $context = []) {
        $context['source'] = $source;
        $context['error'] = $error;
        self::log(self::LEVEL_ERROR, self::TYPE_IMPORT, 'Import failed: ' . $error, $context);
    }

    /**
     * Log API call.
     *
     * @since 0.1.0
     * @param string $provider     Provider name (nces, dese, attom, etc.).
     * @param string $endpoint     API endpoint.
     * @param int    $response_code HTTP response code.
     * @param float  $duration_ms  Duration in milliseconds.
     */
    public static function api_call($provider, $endpoint, $response_code, $duration_ms = null) {
        $level = ($response_code >= 200 && $response_code < 300) ? self::LEVEL_DEBUG : self::LEVEL_WARNING;

        self::log($level, self::TYPE_API_CALL, 'API call to ' . $provider, [
            'source' => $provider,
            'endpoint' => $endpoint,
            'response_code' => $response_code,
            'duration_ms' => $duration_ms
        ]);
    }

    /**
     * Log API error.
     *
     * @since 0.1.0
     * @param string $provider Provider name.
     * @param string $endpoint API endpoint.
     * @param string $error    Error message.
     * @param array  $context  Additional context.
     */
    public static function api_error($provider, $endpoint, $error, $context = []) {
        $context['source'] = $provider;
        $context['endpoint'] = $endpoint;
        $context['error'] = $error;

        self::log(self::LEVEL_ERROR, self::TYPE_API_CALL, 'API error: ' . $error, $context);
    }

    /**
     * Get recent log entries.
     *
     * @since 0.1.0
     * @param array $args Query arguments.
     * @return array Log entries.
     */
    public static function get_logs($args = []) {
        global $wpdb;

        self::init_table_name();

        $defaults = [
            'level' => null,
            'type' => null,
            'source' => null,
            'limit' => 100,
            'offset' => 0,
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [];
        $params = [];

        if ($args['level']) {
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }

        if ($args['type']) {
            $where[] = 'type = %s';
            $params[] = $args['type'];
        }

        if ($args['source']) {
            $where[] = 'source = %s';
            $params[] = $args['source'];
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM " . self::$table_name . " {$where_sql} ORDER BY timestamp {$order} LIMIT %d OFFSET %d";
        $params[] = $args['limit'];
        $params[] = $args['offset'];

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Clear old log entries.
     *
     * @since 0.1.0
     * @param int $days_old Delete entries older than this many days.
     * @return int Number of deleted entries.
     */
    public static function clear_old_logs($days_old = 30) {
        global $wpdb;

        self::init_table_name();

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::$table_name . " WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));

        if ($deleted > 0) {
            self::log(self::LEVEL_INFO, self::TYPE_ADMIN, 'Cleared old log entries', [
                'deleted_count' => $deleted,
                'older_than_days' => $days_old
            ]);
        }

        return $deleted;
    }

    /**
     * Get log statistics.
     *
     * @since 0.1.0
     * @return array Statistics.
     */
    public static function get_stats() {
        global $wpdb;

        self::init_table_name();

        $stats = [
            'total' => 0,
            'by_level' => [],
            'by_type' => [],
            'errors_today' => 0,
        ];

        // Total count
        $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name);

        // By level
        $levels = $wpdb->get_results(
            "SELECT level, COUNT(*) as count FROM " . self::$table_name . " GROUP BY level"
        );
        foreach ($levels as $row) {
            $stats['by_level'][$row->level] = (int) $row->count;
        }

        // By type
        $types = $wpdb->get_results(
            "SELECT type, COUNT(*) as count FROM " . self::$table_name . " GROUP BY type"
        );
        foreach ($types as $row) {
            $stats['by_type'][$row->type] = (int) $row->count;
        }

        // Errors today
        $stats['errors_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::$table_name . " WHERE level = 'error' AND DATE(timestamp) = CURDATE()"
        );

        return $stats;
    }
}
