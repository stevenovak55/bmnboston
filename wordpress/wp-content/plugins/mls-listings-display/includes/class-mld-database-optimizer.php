<?php
/**
 * Database Optimization Handler for MLS Listings Display
 *
 * Manages database indexes, analyzes query performance, and provides
 * optimization recommendations.
 *
 * @package MLS_Listings_Display
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Database_Optimizer {

    /**
     * Critical indexes for performance
     * Updated based on actual database structure analysis
     */
    private static $required_indexes = [
        // BME Listings tables - most indexes already exist!
        'wp_bme_listings' => [
            // These already exist: idx_status, idx_type, idx_price, idx_close_date,
            // idx_property_type, idx_mld_modification_timestamp, idx_mld_list_price
            // Only need to add these:
            'idx_mls_status' => ['mls_status'],
            'idx_updated_at' => ['updated_at'],
            'idx_created_at' => ['created_at']
        ],
        'wp_bme_listings_archive' => [
            // These already exist: idx_status, idx_type, idx_price, idx_close_date
            // Only need to add:
            'idx_mls_status' => ['mls_status'],
            'idx_updated_at' => ['updated_at'],
            'idx_created_at' => ['created_at']
        ],
        // Bridge MLS Listings - fully indexed already!
        'wp_bridge_mls_listings' => [
            // All necessary indexes exist - this table is well optimized
        ],
        // MLD Saved Searches - well indexed
        'wp_mld_saved_searches' => [
            // Already has idx_user_id, idx_created, idx_frequency, idx_active
            // Just need:
            'idx_updated_at' => ['updated_at']
        ],
        // MLD Search Results - adequately indexed
        'wp_mld_saved_search_results' => [
            // Already has unique_search_listing, idx_notified, idx_listing_key
            // No additional indexes needed
        ]
    ];

    /**
     * Check and create missing indexes
     *
     * @return array Results of index creation
     */
    public static function optimizeIndexes() {
        global $wpdb;
        $results = [];

        MLD_Performance_Monitor::startTimer('database_optimization');

        foreach (self::$required_indexes as $table => $indexes) {
            // Handle table prefixes correctly
            if (strpos($table, 'wp_') === 0) {
                // Replace 'wp_' with actual prefix
                $table_name = $wpdb->prefix . substr($table, 3);
            } else {
                $table_name = $table;
            }

            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));

            if (!$table_exists) {
                $results[$table] = ['status' => 'skipped', 'message' => 'Table does not exist'];
                continue;
            }

            // Get existing indexes
            $existing_indexes = $wpdb->get_results(
                "SHOW INDEX FROM `$table_name`",
                ARRAY_A
            );

            $existing_index_names = array_column($existing_indexes, 'Key_name');

            foreach ($indexes as $index_name => $columns) {
                if (!in_array($index_name, $existing_index_names)) {
                    $result = self::createIndex($table_name, $index_name, $columns);
                    $results[$table][$index_name] = $result;
                } else {
                    $results[$table][$index_name] = ['status' => 'exists'];
                }
            }
        }

        MLD_Performance_Monitor::endTimer('database_optimization');

        return $results;
    }

    /**
     * Create a database index
     *
     * @param string $table Table name
     * @param string $index_name Index name
     * @param array $columns Column names
     * @return array Result of index creation
     */
    private static function createIndex($table, $index_name, $columns) {
        global $wpdb;

        $columns_str = implode('`, `', $columns);
        $sql = "ALTER TABLE `$table` ADD INDEX `$index_name` (`$columns_str`)";

        try {
            $wpdb->query($sql);

            if ($wpdb->last_error) {
                return [
                    'status' => 'error',
                    'message' => $wpdb->last_error
                ];
            }

            MLD_Logger::info("Created index $index_name on table $table");

            return [
                'status' => 'created',
                'columns' => $columns
            ];
        } catch (Exception $e) {
            MLD_Logger::error("Failed to create index $index_name on $table", ['error' => $e->getMessage()]);

            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Analyze table statistics and optimize
     *
     * @param string $table Table name
     * @return array Analysis results
     */
    public static function analyzeTable($table) {
        global $wpdb;

        $results = [];

        // Get table size
        $size_query = $wpdb->prepare(
            "SELECT
                table_rows AS row_count,
                ROUND(data_length / 1024 / 1024, 2) AS data_size_mb,
                ROUND(index_length / 1024 / 1024, 2) AS index_size_mb,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_size_mb
            FROM information_schema.tables
            WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        );

        $results['size'] = $wpdb->get_row($size_query, ARRAY_A);

        // Run ANALYZE TABLE to update statistics
        $wpdb->query("ANALYZE TABLE `$table`");
        $results['analyzed'] = true;

        // Check for fragmentation (only for MyISAM/InnoDB)
        $engine = $wpdb->get_var("SELECT ENGINE FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "' AND table_name = '$table'");

        if (in_array($engine, ['MyISAM', 'InnoDB'])) {
            $frag_query = "SELECT
                ROUND((data_free / (data_length + index_length + data_free)) * 100, 2) AS fragmentation_percent
                FROM information_schema.tables
                WHERE table_schema = '" . DB_NAME . "' AND table_name = '$table'";

            $fragmentation = $wpdb->get_var($frag_query);
            $results['fragmentation'] = $fragmentation;

            // Optimize if fragmentation is high
            if ($fragmentation > 10) {
                $wpdb->query("OPTIMIZE TABLE `$table`");
                $results['optimized'] = true;
                MLD_Logger::info("Optimized table $table due to {$fragmentation}% fragmentation");
            }
        }

        return $results;
    }

    /**
     * Get slow queries related to MLD
     *
     * @param int $limit Number of queries to return
     * @return array Slow queries
     */
    public static function getSlowQueries($limit = 10) {
        global $wpdb;

        // This requires slow query log to be enabled
        // Check if we have access to mysql.slow_log table
        $has_slow_log = $wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = 'mysql' AND table_name = 'slow_log'"
        );

        if (!$has_slow_log) {
            return ['error' => 'Slow query log not available'];
        }

        $queries = $wpdb->get_results($wpdb->prepare(
            "SELECT
                query_time,
                lock_time,
                rows_sent,
                rows_examined,
                sql_text
            FROM mysql.slow_log
            WHERE sql_text LIKE '%bme_%' OR sql_text LIKE '%mld_%'
            ORDER BY query_time DESC
            LIMIT %d",
            $limit
        ), ARRAY_A);

        return $queries;
    }

    /**
     * Generate optimization recommendations
     *
     * @return array Recommendations
     */
    public static function getRecommendations() {
        global $wpdb;
        $recommendations = [];

        // Check for missing indexes
        $missing_indexes = [];
        foreach (self::$required_indexes as $table => $indexes) {
            // Handle table prefixes correctly
            if (strpos($table, 'wp_') === 0) {
                // Replace 'wp_' with actual prefix
                $table_name = $wpdb->prefix . substr($table, 3);
            } else {
                $table_name = $table;
            }

            $existing_indexes = $wpdb->get_results("SHOW INDEX FROM `$table_name`", ARRAY_A);
            $existing = array_unique(array_column($existing_indexes, 'Key_name'));
            foreach ($indexes as $index_name => $columns) {
                if (!in_array($index_name, $existing)) {
                    $missing_indexes[] = "$table_name.$index_name";
                }
            }
        }

        if (!empty($missing_indexes)) {
            $recommendations[] = [
                'type' => 'critical',
                'title' => 'Missing Indexes',
                'description' => 'The following indexes are missing and should be created for optimal performance',
                'items' => $missing_indexes,
                'action' => 'Run MLD_Database_Optimizer::optimizeIndexes()'
            ];
        }

        // Check table sizes
        $large_tables = $wpdb->get_results(
            "SELECT
                table_name,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                table_rows
            FROM information_schema.tables
            WHERE table_schema = '" . DB_NAME . "'
                AND (table_name LIKE 'bme_%' OR table_name LIKE '%mld_%')
                AND (data_length + index_length) > 104857600
            ORDER BY (data_length + index_length) DESC",
            ARRAY_A
        );

        if (!empty($large_tables)) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Large Tables',
                'description' => 'These tables are over 100MB and may benefit from partitioning or archival',
                'items' => array_map(function($t) {
                    return "{$t['table_name']}: {$t['size_mb']}MB ({$t['table_rows']} rows)";
                }, $large_tables)
            ];
        }

        // Check query cache status
        if (!MLD_Query_Cache::is_enabled()) {
            $recommendations[] = [
                'type' => 'info',
                'title' => 'Query Cache Disabled',
                'description' => 'The MLD query cache is currently disabled. Enabling it can improve performance for repeated queries.',
                'action' => 'Enable in MLD Settings or define MLD_ENABLE_QUERY_CACHE in wp-config.php'
            ];
        }

        return $recommendations;
    }

    /**
     * Schedule regular optimization tasks
     */
    public static function scheduleOptimization() {
        if (!wp_next_scheduled('mld_database_optimization')) {
            wp_schedule_event(time(), 'weekly', 'mld_database_optimization');
        }

        add_action('mld_database_optimization', [__CLASS__, 'runScheduledOptimization']);
    }

    /**
     * Run scheduled optimization tasks
     */
    public static function runScheduledOptimization() {
        MLD_Logger::info('Starting scheduled database optimization');

        // Optimize indexes
        $index_results = self::optimizeIndexes();

        // Analyze and optimize tables
        $tables = ['bme_listings', 'bme_listings_archive'];
        foreach ($tables as $table) {
            $analysis = self::analyzeTable($table);
            MLD_Logger::info("Analyzed table $table", $analysis);
        }

        // Log recommendations
        $recommendations = self::getRecommendations();
        if (!empty($recommendations)) {
            MLD_Logger::warning('Database optimization recommendations', $recommendations);
        }

        MLD_Logger::info('Completed scheduled database optimization');
    }
}