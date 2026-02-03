<?php
/**
 * Performance Administration Interface
 *
 * Provides admin interface for performance monitoring and optimization
 *
 * @package MLS_Listings_Display
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Performance_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_mld_optimize_database', [$this, 'ajax_optimize_database']);
        add_action('wp_ajax_mld_analyze_table', [$this, 'ajax_analyze_table']);
        add_action('wp_ajax_mld_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_mld_get_current_metrics', [$this, 'ajax_get_current_metrics']);
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        add_submenu_page(
            'mls_listings_display',  // Changed to match the actual parent menu slug
            'Performance',
            'Performance',
            'manage_options',
            'mld-performance',
            [$this, 'render_performance_page']
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'mld-performance') !== false) {
            wp_enqueue_script(
                'mld-performance-admin',
                MLD_PLUGIN_URL . 'admin/js/performance-admin.js',
                ['jquery'],
                MLD_VERSION,
                true
            );

            wp_localize_script('mld-performance-admin', 'mld_performance', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mld_performance_nonce')
            ]);

            wp_enqueue_style(
                'mld-performance-admin',
                MLD_PLUGIN_URL . 'admin/css/performance-admin.css',
                [],
                MLD_VERSION
            );
        }
    }

    /**
     * Render performance page
     */
    public function render_performance_page() {
        // Get performance data
        $recommendations = MLD_Database_Optimizer::getRecommendations();
        $performance_summary = MLD_Performance_Monitor::getSummary();
        $cache_enabled = defined('MLD_ENABLE_QUERY_CACHE') && MLD_ENABLE_QUERY_CACHE;

        ?>
        <div class="wrap mld-performance-admin">
            <h1>MLS Listings Display - Performance Dashboard</h1>

            <!-- Quick Links Bar -->
            <div style="background: #f0f8ff; border: 1px solid #007cba; border-radius: 5px; padding: 15px; margin: 20px 0;">
                <strong>🚀 Performance Dashboard:</strong>
                <a href="<?php echo plugins_url('admin/performance-summary.php', dirname(__FILE__)); ?>" target="_blank" class="button button-primary" style="margin-left: 15px;">
                    📈 Performance Summary
                </a>
            </div>

            <div class="mld-performance-grid">
                <!-- Performance Metrics -->
                <div class="mld-card">
                    <h2>Current Performance Metrics</h2>
                    <div class="mld-metrics">
                        <div class="metric">
                            <span class="label">Memory Usage:</span>
                            <span class="value"><?php echo number_format($performance_summary['memory']['current'] / 1048576, 2); ?> MB</span>
                        </div>
                        <div class="metric">
                            <span class="label">Peak Memory:</span>
                            <span class="value"><?php echo number_format($performance_summary['memory']['peak'] / 1048576, 2); ?> MB</span>
                        </div>
                        <div class="metric">
                            <span class="label">Total Queries:</span>
                            <span class="value"><?php echo $performance_summary['total_queries']; ?></span>
                        </div>
                        <div class="metric">
                            <span class="label">Cache Status:</span>
                            <span class="value <?php echo $cache_enabled ? 'enabled' : 'disabled'; ?>">
                                <?php echo $cache_enabled ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Database Optimization -->
                <div class="mld-card">
                    <h2>Database Optimization</h2>
                    <p>Optimize database indexes and tables for better performance.</p>
                    <button id="mld-optimize-database" class="button button-primary">
                        Run Optimization
                    </button>
                    <div id="optimization-results" class="results-container"></div>
                </div>

                <!-- Recommendations -->
                <?php if (!empty($recommendations)): ?>
                <div class="mld-card full-width">
                    <h2>Performance Recommendations</h2>
                    <div class="recommendations">
                        <?php foreach ($recommendations as $rec): ?>
                        <div class="recommendation <?php echo $rec['type']; ?>">
                            <h3><?php echo esc_html($rec['title']); ?></h3>
                            <p><?php echo esc_html($rec['description']); ?></p>
                            <?php if (!empty($rec['items'])): ?>
                            <ul>
                                <?php foreach ($rec['items'] as $item): ?>
                                <li><?php echo esc_html($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                            <?php if (!empty($rec['action'])): ?>
                            <p class="action">Action: <code><?php echo esc_html($rec['action']); ?></code></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cache Management -->
                <div class="mld-card">
                    <h2>Cache Management</h2>
                    <p>Clear various caches to ensure fresh data.</p>
                    <div class="cache-actions">
                        <button class="button clear-cache" data-cache="query">Clear Query Cache</button>
                        <button class="button clear-cache" data-cache="transients">Clear Transients</button>
                        <button class="button clear-cache" data-cache="all">Clear All Caches</button>
                    </div>
                    <div id="cache-results" class="results-container"></div>
                </div>

                <!-- Table Analysis -->
                <div class="mld-card">
                    <h2>Table Analysis</h2>
                    <p>Analyze and optimize individual database tables.</p>
                    <select id="table-selector">
                        <option value="">Select a table...</option>
                        <option value="<?php echo $GLOBALS['wpdb']->prefix; ?>bme_listings">BME Listings</option>
                        <option value="<?php echo $GLOBALS['wpdb']->prefix; ?>bme_listings_archive">BME Listings Archive</option>
                        <option value="<?php echo $GLOBALS['wpdb']->prefix; ?>mld_saved_searches">Saved Searches</option>
                        <option value="<?php echo $GLOBALS['wpdb']->prefix; ?>mld_saved_search_results">Search Results</option>
                        <option value="<?php echo $GLOBALS['wpdb']->prefix; ?>bridge_mls_listings">Bridge MLS Listings</option>
                    </select>
                    <button id="analyze-table" class="button">Analyze Table</button>
                    <div id="table-analysis-results" class="results-container"></div>
                </div>

                <!-- Slow Operations -->
                <?php if (!empty($performance_summary['slow_operations'])): ?>
                <div class="mld-card full-width">
                    <h2>Recent Slow Operations</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Operation</th>
                                <th>Duration</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performance_summary['slow_operations'] as $op): ?>
                            <tr>
                                <td><?php echo esc_html($op['name']); ?></td>
                                <td><?php echo number_format($op['duration'], 2); ?>ms</td>
                                <td><?php echo !empty($op['metadata']) ? esc_html(json_encode($op['metadata'])) : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Configuration Guide -->
            <div class="mld-card full-width">
                <h2>Performance Configuration</h2>
                <p>Add these constants to your <code>wp-config.php</code> file to enable performance features:</p>
                <pre>
// Enable query caching
define('MLD_ENABLE_QUERY_CACHE', true);

// Enable performance monitoring
define('MLD_PERFORMANCE_MONITORING', true);

// Enable device detection debugging
define('MLD_DEVICE_DEBUG', false);
                </pre>
            </div>

            <!-- Performance Tips -->
            <div class="mld-card full-width" style="background: #f8f9fa; border: 2px solid #007cba;">
                <h2>🛠️ Performance Optimization Tips</h2>
                <ul style="margin-top: 15px;">
                    <li>Enable query caching in wp-config.php</li>
                    <li>Optimize database tables regularly</li>
                    <li>Monitor performance metrics consistently</li>
                    <li>Clear cache after making configuration changes</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for database optimization
     */
    public function ajax_optimize_database() {
        check_ajax_referer('mld_performance_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $results = MLD_Database_Optimizer::optimizeIndexes();

        wp_send_json_success($results);
    }

    /**
     * AJAX handler for table analysis
     */
    public function ajax_analyze_table() {
        check_ajax_referer('mld_performance_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $table = sanitize_text_field($_POST['table']);

        if (empty($table)) {
            wp_send_json_error('No table specified');
        }

        // Make sure we're using the correct table name with prefix
        global $wpdb;

        // If the table doesn't have a prefix, add it
        if (strpos($table, $wpdb->prefix) === false && strpos($table, 'bme_') === 0) {
            $table = $wpdb->prefix . $table;
        }

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));

        if (!$table_exists) {
            wp_send_json_error('Table does not exist: ' . $table);
        }

        $results = MLD_Database_Optimizer::analyzeTable($table);

        wp_send_json_success($results);
    }

    /**
     * AJAX handler for cache clearing
     */
    public function ajax_clear_cache() {
        check_ajax_referer('mld_performance_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $cache_type = sanitize_text_field($_POST['cache_type']);
        $cleared = [];

        switch ($cache_type) {
            case 'query':
                // Clear query cache transients
                global $wpdb;
                $count = $wpdb->query(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_mld_query_%'
                    OR option_name LIKE '_transient_timeout_mld_query_%'"
                );
                $cleared['query_cache'] = $count / 2; // Divide by 2 because of timeout entries
                break;

            case 'transients':
                // Clear all MLD transients
                global $wpdb;
                $count = $wpdb->query(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_mld_%'
                    OR option_name LIKE '_transient_timeout_mld_%'"
                );
                $cleared['transients'] = $count / 2;
                break;

            case 'all':
                // Clear everything
                global $wpdb;
                $count = $wpdb->query(
                    "DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_mld_%'
                    OR option_name LIKE '_transient_timeout_mld_%'"
                );
                $cleared['all_caches'] = $count / 2;

                // Also reset performance metrics
                MLD_Performance_Monitor::reset();
                $cleared['performance_metrics'] = true;
                break;

            default:
                wp_send_json_error('Invalid cache type');
        }

        wp_send_json_success($cleared);
    }

    /**
     * AJAX handler for getting current metrics
     */
    public function ajax_get_current_metrics() {
        check_ajax_referer('mld_performance_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $metrics = [
            'Memory Usage:' => number_format(memory_get_usage(true) / 1048576, 2) . ' MB',
            'Peak Memory:' => number_format(memory_get_peak_usage(true) / 1048576, 2) . ' MB',
            'Total Queries:' => get_num_queries(),
            'Cache Status:' => MLD_Query_Cache::is_enabled() ? 'Enabled' : 'Disabled'
        ];

        wp_send_json_success($metrics);
    }
}