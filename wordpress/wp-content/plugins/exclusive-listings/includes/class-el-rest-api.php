<?php
/**
 * REST API class
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_REST_API
 *
 * Handles REST API endpoints for the Exclusive Listings plugin.
 */
class EL_REST_API {

    /**
     * REST API namespace
     * @var string
     */
    const NAMESPACE = 'exclusive-listings/v1';

    /**
     * Constructor
     */
    public function __construct() {
        // Constructor - routes registered via register_routes()
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_routes() {
        // Health check endpoint (public)
        register_rest_route(
            self::NAMESPACE,
            '/health',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_health'),
                'permission_callback' => '__return_true',
            )
        );

        // Diagnostics endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/diagnostics',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_diagnostics'),
                'permission_callback' => array($this, 'check_admin_permission'),
            )
        );

        // Test ID generation endpoint (admin only, for development)
        register_rest_route(
            self::NAMESPACE,
            '/test-id',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'handle_test_id'),
                'permission_callback' => array($this, 'check_admin_permission'),
            )
        );

        // Image optimization stats endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/photos/optimize-stats',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_optimize_stats'),
                'permission_callback' => array($this, 'check_admin_permission'),
            )
        );

        // Batch image optimization endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/photos/optimize',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'handle_optimize_batch'),
                'permission_callback' => array($this, 'check_admin_permission'),
                'args' => array(
                    'batch_size' => array(
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ),
                    'offset' => array(
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    /**
     * Handle health check request
     *
     * Public endpoint that returns basic system health information.
     * Used by monitoring systems (Uptime Robot, Pingdom, etc.)
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_health($request) {
        $plugin = exclusive_listings();
        $database = $plugin->get_database();

        $checks = array(
            'database' => $this->check_database_health($database),
            'dependencies' => $this->check_dependencies(),
        );

        $all_healthy = $checks['database']['healthy'] && $checks['dependencies']['healthy'];

        $response = new WP_REST_Response(
            array(
                'status' => $all_healthy ? 'healthy' : 'degraded',
                'version' => EL_VERSION,
                'timestamp' => current_time('mysql'),
                'checks' => $checks,
            ),
            $all_healthy ? 200 : 503
        );

        // Add cache bypass headers for accurate monitoring
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Kinsta-Cache', 'BYPASS');

        return $response;
    }

    /**
     * Handle diagnostics request
     *
     * Admin-only endpoint that returns detailed system diagnostics.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_diagnostics($request) {
        $plugin = exclusive_listings();
        $database = $plugin->get_database();
        $id_generator = $plugin->get_id_generator();

        $diagnostics = array(
            'plugin' => array(
                'version' => EL_VERSION,
                'db_version' => EL_DB_VERSION,
                'installed_db_version' => get_option('el_db_version', 'not set'),
                'activated_at' => get_option('el_activated_at', 'unknown'),
            ),
            'environment' => array(
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'mysql_version' => $this->get_mysql_version(),
                'timezone' => wp_timezone_string(),
                'memory_limit' => ini_get('memory_limit'),
            ),
            'database' => $database->get_diagnostics(),
            'id_generator' => $id_generator->get_diagnostics(),
            'settings' => get_option('el_settings', array()),
        );

        $response = new WP_REST_Response(array(
            'success' => true,
            'data' => $diagnostics,
        ));

        // Prevent caching of admin data
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');

        return $response;
    }

    /**
     * Handle test ID generation request
     *
     * Admin-only endpoint for testing ID generation.
     * WARNING: This actually generates an ID, so use sparingly.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_test_id($request) {
        // Only allow in development/staging
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Test ID generation only available in debug mode',
                ),
                403
            );
        }

        $plugin = exclusive_listings();
        $id_generator = $plugin->get_id_generator();

        $new_id = $id_generator->generate();

        if (is_wp_error($new_id)) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => $new_id->get_error_message(),
                    'code' => $new_id->get_error_code(),
                ),
                500
            );
        }

        $listing_key = $id_generator->generate_listing_key($new_id);

        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => array(
                    'id' => $new_id,
                    'listing_key' => $listing_key,
                    'is_exclusive' => EL_ID_Generator::is_exclusive($new_id),
                    'message' => 'Test ID generated successfully',
                ),
            )
        );
    }

    /**
     * Check database health
     *
     * @param EL_Database $database
     * @return array Health check result
     */
    private function check_database_health($database) {
        $diagnostics = $database->get_diagnostics();

        // Check if sequence table exists
        $sequence_exists = $diagnostics['plugin_tables']['sequence']['exists'] ?? false;

        // Check if BME tables exist
        $bme_missing = array();
        foreach ($diagnostics['bme_tables'] as $key => $table) {
            if (!$table['exists']) {
                $bme_missing[] = $key;
            }
        }

        $healthy = $sequence_exists && empty($bme_missing);

        return array(
            'healthy' => $healthy,
            'sequence_table' => $sequence_exists,
            'bme_tables_missing' => $bme_missing,
            'next_exclusive_id' => $diagnostics['sequence_status']['next_id'] ?? 1,
            'exclusive_count' => $diagnostics['exclusive_listing_count'],
        );
    }

    /**
     * Check plugin dependencies
     *
     * @return array Dependency check result
     */
    private function check_dependencies() {
        // MLS Listings Display uses MLD_VERSION constant
        $mld_active = defined('MLD_VERSION');

        return array(
            'healthy' => $mld_active,
            'mls_listings_display' => $mld_active,
            'mld_version' => $mld_active ? MLD_VERSION : null,
        );
    }

    /**
     * Check admin permission for protected endpoints
     *
     * Note: WordPress REST API requires X-WP-Nonce header for cookie auth.
     * Use wp.apiFetch() in browser or include nonce in fetch headers.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_admin_permission($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                'You do not have permission to access this endpoint. Ensure you are logged in as admin and include X-WP-Nonce header.',
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Get MySQL version
     *
     * @return string MySQL version
     */
    private function get_mysql_version() {
        global $wpdb;
        return $wpdb->get_var('SELECT VERSION()') ?: 'unknown';
    }

    /**
     * Handle image optimization stats request
     *
     * @since 1.4.5
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_optimize_stats($request) {
        $image_handler = exclusive_listings()->create_image_handler();
        $stats = $image_handler->get_optimization_stats();

        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => $stats,
            )
        );
    }

    /**
     * Handle batch image optimization request
     *
     * @since 1.4.5
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_optimize_batch($request) {
        $batch_size = $request->get_param('batch_size');
        $offset = $request->get_param('offset');

        // Increase PHP limits for image processing
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $image_handler = exclusive_listings()->create_image_handler();
        $results = $image_handler->optimize_existing_photos($batch_size, $offset);

        // Format space saved for readability
        $results['space_saved_formatted'] = size_format($results['space_saved'], 2);

        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => $results,
            )
        );
    }
}
