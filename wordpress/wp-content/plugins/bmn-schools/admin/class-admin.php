<?php
/**
 * Admin Class
 *
 * Handles admin menus, settings pages, and admin functionality.
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Admin Class
 *
 * @since 0.1.0
 */
class BMN_Schools_Admin {

    /**
     * The database manager instance.
     *
     * @var BMN_Schools_Database_Manager
     */
    private $db_manager;

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct() {
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
        $this->db_manager = new BMN_Schools_Database_Manager();
    }

    /**
     * Initialize admin hooks.
     *
     * @since 0.1.0
     */
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX handlers
        add_action('wp_ajax_bmn_schools_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_bmn_schools_run_import', [$this, 'ajax_run_import']);
        add_action('wp_ajax_bmn_schools_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_bmn_schools_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_bmn_schools_run_geocode', [$this, 'ajax_run_geocode']);
        add_action('wp_ajax_bmn_schools_geocode_status', [$this, 'ajax_geocode_status']);
        add_action('wp_ajax_bmn_schools_get_import_stats', [$this, 'ajax_get_import_stats']);
        add_action('wp_ajax_bmn_schools_calculate_rankings', [$this, 'ajax_calculate_rankings']);
        add_action('wp_ajax_bmn_schools_upload_discipline', [$this, 'ajax_upload_discipline']);
        add_action('wp_ajax_bmn_schools_upload_sports', [$this, 'ajax_upload_sports']);

        // Cron handlers for automated sync
        add_action('bmn_schools_annual_sync', [$this, 'run_annual_sync']);
    }

    /**
     * Add admin menu pages.
     *
     * @since 0.1.0
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('BMN Schools', 'bmn-schools'),
            __('BMN Schools', 'bmn-schools'),
            'manage_options',
            'bmn-schools',
            [$this, 'render_dashboard'],
            'dashicons-welcome-learn-more',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'bmn-schools',
            __('Dashboard', 'bmn-schools'),
            __('Dashboard', 'bmn-schools'),
            'manage_options',
            'bmn-schools',
            [$this, 'render_dashboard']
        );

        // Settings submenu
        add_submenu_page(
            'bmn-schools',
            __('Settings', 'bmn-schools'),
            __('Settings', 'bmn-schools'),
            'manage_options',
            'bmn-schools-settings',
            [$this, 'render_settings']
        );

        // Data Sources submenu
        add_submenu_page(
            'bmn-schools',
            __('Data Sources', 'bmn-schools'),
            __('Data Sources', 'bmn-schools'),
            'manage_options',
            'bmn-schools-sources',
            [$this, 'render_data_sources']
        );

        // Import submenu
        add_submenu_page(
            'bmn-schools',
            __('Import Data', 'bmn-schools'),
            __('Import Data', 'bmn-schools'),
            'manage_options',
            'bmn-schools-import',
            [$this, 'render_import']
        );

        // Activity Log submenu
        add_submenu_page(
            'bmn-schools',
            __('Activity Log', 'bmn-schools'),
            __('Activity Log', 'bmn-schools'),
            'manage_options',
            'bmn-schools-logs',
            [$this, 'render_activity_log']
        );
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @since 0.1.0
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'bmn-schools') === false) {
            return;
        }

        wp_enqueue_style(
            'bmn-schools-admin',
            BMN_SCHOOLS_PLUGIN_URL . 'admin/css/admin.css',
            [],
            BMN_SCHOOLS_VERSION
        );

        wp_enqueue_script(
            'bmn-schools-admin',
            BMN_SCHOOLS_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            BMN_SCHOOLS_VERSION,
            true
        );

        wp_localize_script('bmn-schools-admin', 'bmnSchoolsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bmn_schools_admin'),
            'strings' => [
                'confirmSync' => __('Are you sure you want to run a sync? This may take several minutes.', 'bmn-schools'),
                'confirmClearLogs' => __('Are you sure you want to clear old log entries?', 'bmn-schools'),
                'syncing' => __('Syncing...', 'bmn-schools'),
                'syncComplete' => __('Sync complete!', 'bmn-schools'),
                'syncError' => __('Sync failed. Check the activity log for details.', 'bmn-schools'),
            ]
        ]);
    }

    /**
     * Register plugin settings.
     *
     * @since 0.1.0
     */
    public function register_settings() {
        register_setting('bmn_schools_settings', 'bmn_schools_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        register_setting('bmn_schools_settings', 'bmn_schools_sync_settings');
        register_setting('bmn_schools_settings', 'bmn_schools_api_credentials', [
            'sanitize_callback' => [$this, 'sanitize_api_credentials']
        ]);
    }

    /**
     * Sanitize general settings.
     *
     * @since 0.1.0
     * @param array $input Raw input.
     * @return array Sanitized input.
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        $sanitized['enable_cache'] = !empty($input['enable_cache']);
        $sanitized['cache_duration'] = absint($input['cache_duration'] ?? 1800);
        $sanitized['debug_mode'] = !empty($input['debug_mode']);
        $sanitized['default_state'] = sanitize_text_field($input['default_state'] ?? 'MA');
        $sanitized['results_per_page'] = absint($input['results_per_page'] ?? 20);

        return $sanitized;
    }

    /**
     * Sanitize API credentials (encrypt sensitive data).
     *
     * @since 0.1.0
     * @param array $input Raw input.
     * @return array Sanitized input.
     */
    public function sanitize_api_credentials($input) {
        $sanitized = [];

        $keys = ['schooldigger_key', 'greatschools_key', 'attom_key'];

        foreach ($keys as $key) {
            if (isset($input[$key])) {
                $sanitized[$key] = sanitize_text_field($input[$key]);
            }
        }

        return $sanitized;
    }

    /**
     * Render dashboard page.
     *
     * @since 0.1.0
     */
    public function render_dashboard() {
        // Get statistics
        $table_stats = $this->db_manager->verify_tables();
        $data_stats = $this->db_manager->get_stats();

        // Get recent activity
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';
        $recent_logs = BMN_Schools_Logger::get_logs(['limit' => 10]);
        $log_stats = BMN_Schools_Logger::get_stats();

        // Get data source status
        global $wpdb;
        $sources_table = $this->db_manager->get_table('data_sources');
        $data_sources = [];
        if ($this->db_manager->table_exists($sources_table)) {
            $data_sources = $wpdb->get_results("SELECT * FROM {$sources_table} ORDER BY source_name ASC");
        }

        // Get settings
        $settings = get_option('bmn_schools_settings', []);

        include BMN_SCHOOLS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render settings page.
     *
     * @since 0.1.0
     */
    public function render_settings() {
        $settings = get_option('bmn_schools_settings', []);
        $sync_settings = get_option('bmn_schools_sync_settings', []);
        $api_credentials = get_option('bmn_schools_api_credentials', []);

        include BMN_SCHOOLS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Render data sources page.
     *
     * @since 0.1.0
     */
    public function render_data_sources() {
        global $wpdb;

        $sources_table = $this->db_manager->get_table('data_sources');
        $data_sources = [];

        if ($this->db_manager->table_exists($sources_table)) {
            $data_sources = $wpdb->get_results("SELECT * FROM {$sources_table} ORDER BY source_name ASC");
        }

        include BMN_SCHOOLS_PLUGIN_DIR . 'admin/views/data-sources.php';
    }

    /**
     * Render activity log page.
     *
     * @since 0.1.0
     */
    public function render_activity_log() {
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        // Get filter parameters
        $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : null;
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 50;

        $logs = BMN_Schools_Logger::get_logs([
            'level' => $level,
            'type' => $type,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $log_stats = BMN_Schools_Logger::get_stats();

        include BMN_SCHOOLS_PLUGIN_DIR . 'admin/views/activity-log.php';
    }

    /**
     * Render import page.
     *
     * @since 0.2.0
     */
    public function render_import() {
        global $wpdb;

        // Get source status
        $sources_table = $this->db_manager->get_table('data_sources');
        $source_status = [];

        if ($this->db_manager->table_exists($sources_table)) {
            $sources = $wpdb->get_results("SELECT source_name, status FROM {$sources_table}");
            foreach ($sources as $source) {
                $source_status[$source->source_name] = $source->status;
            }
        }

        include BMN_SCHOOLS_PLUGIN_DIR . 'admin/views/import.php';
    }

    /**
     * AJAX handler for running sync.
     *
     * @since 0.1.0
     */
    public function ajax_run_sync() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $source = isset($_POST['source']) ? sanitize_text_field($_POST['source']) : 'all';

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        BMN_Schools_Logger::log('info', 'sync', 'Manual sync triggered', [
            'source' => $source,
            'user_id' => get_current_user_id()
        ]);

        // Note: This is intentionally a lightweight placeholder.
        // Full data sync is handled by:
        // 1. Import Data page (ajax_run_import) - manual per-source imports
        // 2. run_annual_sync() - scheduled cron for September 1st annual refresh
        // This button logs the trigger for audit purposes only.

        wp_send_json_success([
            'message' => 'Sync initiated for: ' . $source,
            'source' => $source
        ]);
    }

    /**
     * AJAX handler for running data import.
     *
     * @since 0.2.0
     */
    public function ajax_run_import() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        // Increase limits for long-running imports
        set_time_limit(600); // 10 minutes
        ini_set('memory_limit', '512M');

        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        $options = isset($_POST['options']) ? json_decode(stripslashes($_POST['options']), true) : [];

        if (empty($provider)) {
            wp_send_json_error(['message' => 'No provider specified']);
        }

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        // Check for special DESE sub-imports (demographics, ap, graduation, etc.)
        $dese_actions = [
            'dese_demographics' => 'import_demographics',
            'dese_ap' => 'import_ap_data',
            'dese_graduation' => 'import_graduation_rates',
            'dese_attendance' => 'import_attendance',
            'dese_staffing' => 'import_staffing',
            'dese_spending' => 'import_district_spending',
            'dese_masscore' => 'import_masscore',
            'dese_school_expenditures' => 'import_school_expenditures',
            'dese_pathways' => 'import_pathways',
            'dese_early_college' => 'import_early_college',
            'dese_college_outcomes' => 'import_college_outcomes',
            'dese_discipline' => 'import_discipline',
        ];

        if (isset($dese_actions[$provider])) {
            // Load DESE provider for sub-imports
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/data-providers/class-dese-provider.php';

            try {
                $dese = new BMN_Schools_DESE_Provider();
                $method = $dese_actions[$provider];
                $result = $dese->$method($options);

                // Update sync status
                $count = isset($result['imported']) ? $result['imported'] : 0;
                if (isset($result['updated'])) {
                    $count += $result['updated'];
                }
                self::update_source_sync($provider, $count);

                if (isset($result['success']) && $result['success']) {
                    wp_send_json_success($result);
                } elseif (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                } else {
                    wp_send_json_success($result);
                }
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Import failed: ' . $e->getMessage()]);
            }
            return;
        }

        // Get the provider class for standard imports
        $provider_class = $this->get_provider_class($provider);

        if (!$provider_class) {
            wp_send_json_error(['message' => 'Unknown provider: ' . $provider]);
        }

        try {
            $provider_instance = new $provider_class();
            $result = $provider_instance->sync($options);

            // Update sync status
            $count = isset($result['count']) ? $result['count'] : 0;
            if (isset($result['imported'])) {
                $count = $result['imported'];
            }
            self::update_source_sync($provider, $count);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            BMN_Schools_Logger::log('error', 'import', 'Import failed: ' . $e->getMessage(), [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            wp_send_json_error([
                'message' => 'Import failed: ' . $e->getMessage(),
                'count' => 0
            ]);
        }
    }

    /**
     * Get provider class name.
     *
     * @since 0.2.0
     * @param string $provider Provider name.
     * @return string|null Provider class name or null.
     */
    private function get_provider_class($provider) {
        $providers = [
            'massgis' => 'BMN_Schools_MassGIS_Provider',
            'ma_dese' => 'BMN_Schools_DESE_Provider',
            'nces_edge' => 'BMN_Schools_NCES_Edge_Provider',
            'boston_open_data' => 'BMN_Schools_Boston_Provider',
        ];

        if (!isset($providers[$provider])) {
            return null;
        }

        $class = $providers[$provider];

        // Load the provider file
        $file_map = [
            'massgis' => 'class-massgis-provider.php',
            'ma_dese' => 'class-dese-provider.php',
            'nces_edge' => 'class-nces-edge-provider.php',
            'boston_open_data' => 'class-boston-provider.php',
        ];

        $file = BMN_SCHOOLS_PLUGIN_DIR . 'includes/data-providers/' . $file_map[$provider];

        if (file_exists($file)) {
            require_once $file;
            return $class;
        }

        return null;
    }

    /**
     * AJAX handler for clearing old logs.
     *
     * @since 0.1.0
     */
    public function ajax_clear_logs() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $days = isset($_POST['days']) ? absint($_POST['days']) : 30;

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';
        $deleted = BMN_Schools_Logger::clear_old_logs($days);

        wp_send_json_success([
            'message' => sprintf('Cleared %d log entries older than %d days', $deleted, $days),
            'deleted' => $deleted
        ]);
    }

    /**
     * AJAX handler for getting log entries.
     *
     * @since 0.1.0
     */
    public function ajax_get_logs() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $level = isset($_POST['level']) ? sanitize_text_field($_POST['level']) : null;
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : null;
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        $logs = BMN_Schools_Logger::get_logs([
            'level' => $level,
            'type' => $type,
            'limit' => $limit,
        ]);

        wp_send_json_success(['logs' => $logs]);
    }

    /**
     * Get status badge HTML.
     *
     * @since 0.1.0
     * @param string $status Status string.
     * @return string HTML badge.
     */
    public static function get_status_badge($status) {
        $classes = [
            'active' => 'success',
            'pending' => 'warning',
            'disabled' => 'secondary',
            'error' => 'danger',
            'syncing' => 'info',
        ];

        $class = isset($classes[$status]) ? $classes[$status] : 'secondary';

        return sprintf(
            '<span class="bmn-badge bmn-badge-%s">%s</span>',
            esc_attr($class),
            esc_html(ucfirst($status))
        );
    }

    /**
     * Get level badge HTML.
     *
     * @since 0.1.0
     * @param string $level Log level.
     * @return string HTML badge.
     */
    public static function get_level_badge($level) {
        $classes = [
            'debug' => 'secondary',
            'info' => 'info',
            'warning' => 'warning',
            'error' => 'danger',
        ];

        $class = isset($classes[$level]) ? $classes[$level] : 'secondary';

        return sprintf(
            '<span class="bmn-badge bmn-badge-%s">%s</span>',
            esc_attr($class),
            esc_html(ucfirst($level))
        );
    }

    /**
     * AJAX handler for geocoding status.
     *
     * @since 0.5.1
     */
    public function ajax_geocode_status() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bmn_schools';

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-geocoder.php';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $with_coords = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE latitude IS NOT NULL AND longitude IS NOT NULL"
        );
        $pending = BMN_Schools_Geocoder::get_pending_count();

        wp_send_json_success([
            'total' => $total,
            'with_coords' => $with_coords,
            'pending' => $pending,
            'percent' => $total > 0 ? round(($with_coords / $total) * 100, 1) : 0,
        ]);
    }

    /**
     * AJAX handler for running geocoding.
     *
     * @since 0.5.1
     */
    public function ajax_run_geocode() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        $city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';

        // Increase limits
        set_time_limit($limit * 2 + 60);

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-geocoder.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        // If city specified, geocode just that city
        if (!empty($city)) {
            global $wpdb;
            $table = $wpdb->prefix . 'bmn_schools';

            $schools = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, address, city, state
                 FROM {$table}
                 WHERE city = %s
                 AND (latitude IS NULL OR longitude IS NULL)
                 AND address IS NOT NULL AND address != ''",
                $city
            ));

            $stats = [
                'total' => count($schools),
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];

            foreach ($schools as $school) {
                if (empty($school->address)) {
                    $stats['skipped']++;
                    continue;
                }

                $state = $school->state ?: 'MA';
                $coords = BMN_Schools_Geocoder::geocode($school->address, $school->city, $state);

                if ($coords) {
                    $wpdb->update(
                        $table,
                        ['latitude' => $coords['lat'], 'longitude' => $coords['lng']],
                        ['id' => $school->id],
                        ['%f', '%f'],
                        ['%d']
                    );
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }

                usleep(1100000); // Rate limit
            }

            BMN_Schools_Logger::log('info', 'geocode', "Geocoded {$city} schools", $stats);

            wp_send_json_success([
                'message' => "Geocoded {$stats['success']} schools in {$city}",
                'stats' => $stats,
            ]);
        } else {
            // General batch geocoding
            $stats = BMN_Schools_Geocoder::geocode_schools($limit);

            BMN_Schools_Logger::log('info', 'geocode', 'Batch geocoding complete', $stats);

            wp_send_json_success([
                'message' => "Geocoded {$stats['success']} of {$stats['total']} schools",
                'stats' => $stats,
                'remaining' => BMN_Schools_Geocoder::get_pending_count(),
            ]);
        }
    }

    /**
     * AJAX handler for getting import statistics.
     *
     * @since 0.5.3
     */
    public function ajax_get_import_stats() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;

        // Get counts from actual tables
        $stats = [
            'massgis' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_schools"),
                'label' => 'schools',
            ],
            'ma_dese' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_test_scores"),
                'label' => 'test scores',
            ],
            'nces_edge' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_districts WHERE boundary_geojson IS NOT NULL"),
                'label' => 'district boundaries',
            ],
            'boston_open_data' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_schools WHERE city = 'Boston'"),
                'label' => 'Boston schools',
            ],
            'dese_demographics' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_demographics"),
                'label' => 'demographics records',
            ],
            'dese_ap' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type IN ('ap_course', 'ap_summary')"),
                'label' => 'AP records',
            ],
            'dese_graduation' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'graduation'"),
                'label' => 'graduation records',
            ],
            'dese_attendance' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'attendance'"),
                'label' => 'attendance records',
            ],
            'dese_staffing' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'staffing'"),
                'label' => 'staffing records',
            ],
            'dese_spending' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_districts WHERE extra_data IS NOT NULL"),
                'label' => 'districts with spending',
            ],
            'dese_masscore' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'masscore'"),
                'label' => 'MassCore records',
            ],
            'dese_school_expenditures' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'expenditure'"),
                'label' => 'expenditure records',
            ],
            'dese_pathways' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'pathways'"),
                'label' => 'pathways records',
            ],
            'dese_early_college' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_features WHERE feature_type = 'early_college'"),
                'label' => 'Early College records',
            ],
            'dese_college_outcomes' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_districts WHERE extra_data LIKE '%college_outcomes%'"),
                'label' => 'districts with outcomes',
            ],
            'dese_discipline' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_districts WHERE extra_data LIKE '%discipline%'"),
                'label' => 'districts with discipline',
            ],
            'rankings' => [
                'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_rankings WHERE composite_score IS NOT NULL"),
                'label' => 'schools ranked',
            ],
        ];

        // Get last sync times from data_sources table
        $sources = $wpdb->get_results(
            "SELECT source_name, last_sync, records_synced FROM {$wpdb->prefix}bmn_school_data_sources"
        );

        foreach ($sources as $source) {
            if (isset($stats[$source->source_name])) {
                $stats[$source->source_name]['last_sync'] = $source->last_sync;
                $stats[$source->source_name]['synced_count'] = (int) $source->records_synced;
            }
        }

        wp_send_json_success($stats);
    }

    /**
     * Update data source sync status.
     *
     * @since 0.5.3
     * @param string $source_name Source name.
     * @param int $records_synced Number of records synced.
     */
    public static function update_source_sync($source_name, $records_synced) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'bmn_school_data_sources',
            [
                'last_sync' => current_time('mysql'),
                'records_synced' => $records_synced,
                'status' => 'active',
            ],
            ['source_name' => $source_name],
            ['%s', '%d', '%s'],
            ['%s']
        );
    }

    /**
     * AJAX handler for calculating school rankings.
     *
     * @since 0.6.0
     */
    public function ajax_calculate_rankings() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        // Increase limits for long-running calculations
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';
        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        try {
            $calculator = new BMN_Schools_Ranking_Calculator();
            $results = $calculator->calculate_all_rankings();

            // Also calculate district rankings (fixed: was missing before)
            $year = isset($results['year']) ? $results['year'] : date('Y');
            $dist_results = $calculator->calculate_district_rankings($year);
            $districts_ranked = is_array($dist_results) ? count($dist_results) : 0;

            // Update data source sync status
            self::update_source_sync('rankings', $results['ranked']);

            wp_send_json_success([
                'message' => 'Rankings calculated successfully',
                'total' => $results['total'],
                'ranked' => $results['ranked'],
                'skipped' => $results['skipped'],
                'year' => $results['year'],
                'districts_ranked' => $districts_ranked,
            ]);
        } catch (Exception $e) {
            BMN_Schools_Logger::log('error', 'ranking', 'Ranking calculation failed: ' . $e->getMessage());

            wp_send_json_error([
                'message' => 'Calculation failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Run annual data sync via WordPress cron.
     *
     * This method is called automatically on September 1st each year
     * to refresh all school data from MA DESE and recalculate rankings.
     *
     * @since 0.6.16
     * @return array Results of the sync operation.
     */
    public function run_annual_sync() {
        // Increase limits for long-running sync
        set_time_limit(1800);  // 30 minutes
        ini_set('memory_limit', '1G');

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';

        BMN_Schools_Logger::log('info', 'sync', 'Starting annual data sync');

        $results = [];
        $year = date('Y');

        try {
            // 1. Import MCAS test scores
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/data-providers/class-dese-provider.php';
            $dese = new BMN_Schools_DESE_Provider();
            $results['mcas'] = $dese->sync(['years' => [$year]]);
            BMN_Schools_Logger::log('info', 'sync', 'MCAS sync completed', $results['mcas']);

            // 2. Import demographics
            $results['demographics'] = $dese->import_demographics();
            BMN_Schools_Logger::log('info', 'sync', 'Demographics import completed');

            // 3. Import graduation rates
            $results['graduation'] = $dese->import_graduation_rates(['years' => [$year]]);
            BMN_Schools_Logger::log('info', 'sync', 'Graduation rates import completed');

            // 4. Import attendance data
            $results['attendance'] = $dese->import_attendance(['years' => [$year]]);
            BMN_Schools_Logger::log('info', 'sync', 'Attendance import completed');

            // 5. Import AP data
            $results['ap'] = $dese->import_ap_data(['years' => [$year]]);
            BMN_Schools_Logger::log('info', 'sync', 'AP data import completed');

            // 6. Import staffing data
            $results['staffing'] = $dese->import_staffing(['years' => [$year]]);
            BMN_Schools_Logger::log('info', 'sync', 'Staffing data import completed');

            // 7. Map schools to districts (ensures new schools get district assignments)
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-database-manager.php';
            $db_manager = new BMN_Schools_Database_Manager();
            $results['district_mapping'] = $db_manager->map_schools_to_districts();
            BMN_Schools_Logger::log('info', 'sync', 'School-to-district mapping completed', $results['district_mapping']);

            // 8. Recalculate all school rankings
            require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-ranking-calculator.php';
            $calculator = new BMN_Schools_Ranking_Calculator();
            $results['school_rankings'] = $calculator->calculate_all_rankings();
            BMN_Schools_Logger::log('info', 'sync', 'School rankings calculated', $results['school_rankings']);

            // 9. Recalculate district rankings
            $results['district_rankings'] = $calculator->calculate_district_rankings($year);
            $districts_count = is_array($results['district_rankings']) ? count($results['district_rankings']) : 0;
            BMN_Schools_Logger::log('info', 'sync', 'District rankings calculated', ['count' => $districts_count]);

            // 10. Update last sync timestamp
            update_option('bmn_schools_last_annual_sync', current_time('mysql'));

            // 11. Log completion
            BMN_Schools_Logger::log('info', 'sync', 'Annual data sync completed successfully', [
                'year' => $year,
                'results' => array_map(function($v) {
                    return is_array($v) ? count($v) : $v;
                }, $results)
            ]);

            // 12. Send admin notification
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                $schools_mapped = isset($results['district_mapping']['mapped_exact'])
                    ? ($results['district_mapping']['mapped_exact'] +
                       $results['district_mapping']['mapped_regional_first'] +
                       $results['district_mapping']['mapped_regional_second'])
                    : 0;

                wp_mail(
                    $admin_email,
                    'BMN Schools: Annual Data Sync Complete',
                    sprintf(
                        "School data has been refreshed for %d.\n\n" .
                        "Schools mapped to districts: %d\n" .
                        "Schools ranked: %d\n" .
                        "Districts ranked: %d\n\n" .
                        "View details in WordPress Admin > BMN Schools > Activity Log",
                        $year,
                        $schools_mapped,
                        isset($results['school_rankings']['ranked']) ? $results['school_rankings']['ranked'] : 0,
                        $districts_count
                    )
                );
            }

        } catch (Exception $e) {
            BMN_Schools_Logger::log('error', 'sync', 'Annual sync failed: ' . $e->getMessage());

            // Send error notification
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                wp_mail(
                    $admin_email,
                    'BMN Schools: Annual Sync FAILED',
                    sprintf(
                        "The annual data sync failed.\n\nError: %s\n\nPlease check the activity log for details.",
                        $e->getMessage()
                    )
                );
            }

            throw $e;
        }

        return $results;
    }

    /**
     * AJAX handler for uploading discipline data file.
     *
     * @since 0.6.23
     */
    public function ajax_upload_discipline() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (empty($_FILES['discipline_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['discipline_file'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error: ' . $file['error']]);
        }

        // Check file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            wp_send_json_error(['message' => 'Invalid file type. Please upload CSV or Excel file.']);
        }

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';
        BMN_Schools_Logger::log('info', 'import', 'Discipline file upload started', ['file' => $file['name']]);

        try {
            // Parse the file
            $data = $this->parse_discipline_file($file['tmp_name'], $ext);

            if (empty($data)) {
                wp_send_json_error(['message' => 'No valid data found in file']);
            }

            // Import the data
            $result = $this->import_discipline_data($data);

            // Update sync status
            self::update_source_sync('dese_discipline', $result['updated']);

            BMN_Schools_Logger::log('info', 'import', 'Discipline import completed', $result);

            wp_send_json_success([
                'message' => sprintf('Successfully updated %d districts', $result['updated']),
                'updated' => $result['updated'],
                'not_found' => $result['not_found'],
                'total' => count($data),
            ]);

        } catch (Exception $e) {
            BMN_Schools_Logger::log('error', 'import', 'Discipline import failed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Import failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Parse discipline data file (CSV or Excel).
     *
     * @since 0.6.23
     * @param string $filepath Path to uploaded file.
     * @param string $ext File extension.
     * @return array Parsed data.
     */
    private function parse_discipline_file($filepath, $ext) {
        $data = [];

        if ($ext === 'csv') {
            $data = $this->parse_discipline_csv($filepath);
        } else {
            // Excel file - try to use PhpSpreadsheet or SimpleXLSX
            $data = $this->parse_discipline_excel($filepath);
        }

        return $data;
    }

    /**
     * Parse CSV discipline file.
     *
     * @since 0.6.23
     * @param string $filepath Path to CSV file.
     * @return array Parsed data.
     */
    private function parse_discipline_csv($filepath) {
        $data = [];
        $handle = fopen($filepath, 'r');

        if (!$handle) {
            throw new Exception('Could not open file');
        }

        $headers = null;
        $row_num = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            // Skip title row (first row with merged cells usually)
            if ($row_num === 1 && (empty($row[1]) || strpos($row[0], 'Discipline') !== false)) {
                continue;
            }

            // Header row
            if ($headers === null && stripos($row[0], 'District') !== false) {
                $headers = $row;
                continue;
            }

            // Skip if no headers yet
            if ($headers === null) {
                continue;
            }

            // Data row
            if (empty($row[0]) || empty($row[1])) {
                continue;
            }

            $record = $this->map_discipline_row($row);
            if ($record) {
                $data[] = $record;
            }
        }

        fclose($handle);
        return $data;
    }

    /**
     * Parse Excel discipline file.
     *
     * @since 0.6.23
     * @param string $filepath Path to Excel file.
     * @return array Parsed data.
     */
    private function parse_discipline_excel($filepath) {
        // Try using WordPress's built-in spreadsheet parser if available
        // Otherwise fall back to simple XML parsing for xlsx

        $data = [];
        $zip = new ZipArchive();

        if ($zip->open($filepath) !== true) {
            throw new Exception('Could not open Excel file. Please save as CSV and try again.');
        }

        // Read shared strings
        $shared_strings = [];
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_xml) {
            $xml = simplexml_load_string($shared_xml);
            foreach ($xml->si as $si) {
                $shared_strings[] = (string) $si->t;
            }
        }

        // Read sheet1
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheet_xml) {
            $zip->close();
            throw new Exception('Could not read worksheet');
        }

        $xml = simplexml_load_string($sheet_xml);
        $rows = [];

        foreach ($xml->sheetData->row as $row_el) {
            $row = [];
            foreach ($row_el->c as $cell) {
                $value = '';
                if (isset($cell->v)) {
                    $value = (string) $cell->v;
                    // Check if it's a shared string reference
                    if (isset($cell['t']) && (string) $cell['t'] === 's') {
                        $value = $shared_strings[(int) $value] ?? $value;
                    }
                }
                $row[] = $value;
            }
            $rows[] = $row;
        }

        $zip->close();

        // Process rows similar to CSV
        $headers = null;
        foreach ($rows as $i => $row) {
            // Skip title row
            if ($i === 0 && (empty($row[1]) || strpos($row[0] ?? '', 'Discipline') !== false)) {
                continue;
            }

            // Header row
            if ($headers === null && isset($row[0]) && stripos($row[0], 'District') !== false) {
                $headers = $row;
                continue;
            }

            if ($headers === null) {
                continue;
            }

            if (empty($row[0]) || empty($row[1])) {
                continue;
            }

            $record = $this->map_discipline_row($row);
            if ($record) {
                $data[] = $record;
            }
        }

        return $data;
    }

    /**
     * Map a discipline data row to structured array.
     *
     * Expected columns:
     * 0: District Name
     * 1: District Code
     * 2: Students
     * 3: Students Disciplined
     * 4: % In-School Suspension
     * 5: % Out-of-School Suspension
     * 6: % Expulsion
     * 7: % Removed to Alternate Setting
     * 8: % Emergency Removal
     * 9: % Students with School-Based Arrest
     * 10: % Students with Non-Arrest Law Enforcement Referral
     *
     * @since 0.6.23
     * @param array $row Row data.
     * @return array|null Mapped record or null.
     */
    private function map_discipline_row($row) {
        $district_name = trim($row[0] ?? '');
        $district_code = trim($row[1] ?? '');

        if (empty($district_name) || empty($district_code)) {
            return null;
        }

        return [
            'district_name' => $district_name,
            'district_code' => $district_code,
            'enrollment' => $this->parse_discipline_number($row[2] ?? null),
            'students_disciplined' => $this->parse_discipline_number($row[3] ?? null),
            'in_school_suspension_pct' => $this->parse_discipline_percent($row[4] ?? null),
            'out_of_school_suspension_pct' => $this->parse_discipline_percent($row[5] ?? null),
            'expulsion_pct' => $this->parse_discipline_percent($row[6] ?? null),
            'removed_to_alternate_pct' => $this->parse_discipline_percent($row[7] ?? null),
            'emergency_removal_pct' => $this->parse_discipline_percent($row[8] ?? null),
            'school_based_arrest_pct' => $this->parse_discipline_percent($row[9] ?? null),
            'law_enforcement_referral_pct' => $this->parse_discipline_percent($row[10] ?? null),
            'discipline_rate' => $this->calculate_discipline_rate($row),
        ];
    }

    /**
     * Parse a number from discipline data.
     *
     * @since 0.6.23
     * @param mixed $value Input value.
     * @return int|null Parsed number.
     */
    private function parse_discipline_number($value) {
        if ($value === null || $value === '' || $value === 'N/A' || $value === '-') {
            return null;
        }
        $cleaned = str_replace([',', ' '], '', trim($value));
        return is_numeric($cleaned) ? (int) $cleaned : null;
    }

    /**
     * Parse a percentage from discipline data.
     *
     * @since 0.6.23
     * @param mixed $value Input value.
     * @return float|null Parsed percentage.
     */
    private function parse_discipline_percent($value) {
        if ($value === null || $value === '' || $value === 'N/A' || $value === '-') {
            return null;
        }
        $cleaned = str_replace(['%', ' '], '', trim($value));
        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /**
     * Calculate combined discipline rate.
     *
     * @since 0.6.23
     * @param array $row Data row.
     * @return float Combined rate.
     */
    private function calculate_discipline_rate($row) {
        $oss = $this->parse_discipline_percent($row[5] ?? null) ?? 0;
        $exp = $this->parse_discipline_percent($row[6] ?? null) ?? 0;
        $emr = $this->parse_discipline_percent($row[8] ?? null) ?? 0;
        return round($oss + $exp + $emr, 2);
    }

    /**
     * Import parsed discipline data into database.
     *
     * @since 0.6.23
     * @param array $data Parsed discipline data.
     * @return array Results with updated/not_found counts.
     */
    private function import_discipline_data($data) {
        global $wpdb;

        $updated = 0;
        $not_found = 0;
        $year = (int) date('Y'); // Current year for 2024-25 school year

        foreach ($data as $record) {
            $district_name = $record['district_name'];

            // Clean up name - remove common suffixes
            $clean_name = preg_replace('/\s*\(District\)\s*$/i', '', $district_name);
            $clean_name = preg_replace('/\s*Charter Public\s*$/i', '', $clean_name);
            $clean_name = preg_replace('/\s*Regional\s*$/i', '', $clean_name);
            $clean_name = trim($clean_name);

            // Try to find matching district(s)
            $districts = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, extra_data FROM {$wpdb->prefix}bmn_school_districts
                 WHERE name = %s OR name = %s OR name LIKE %s",
                $clean_name,
                $clean_name . ' School District',
                $clean_name . '%'
            ));

            if (empty($districts)) {
                $not_found++;
                continue;
            }

            // Build discipline data object
            $discipline = [
                'year' => $year,
                'enrollment' => $record['enrollment'],
                'students_disciplined' => $record['students_disciplined'],
                'in_school_suspension_pct' => $record['in_school_suspension_pct'],
                'out_of_school_suspension_pct' => $record['out_of_school_suspension_pct'],
                'expulsion_pct' => $record['expulsion_pct'],
                'removed_to_alternate_pct' => $record['removed_to_alternate_pct'],
                'emergency_removal_pct' => $record['emergency_removal_pct'],
                'school_based_arrest_pct' => $record['school_based_arrest_pct'],
                'law_enforcement_referral_pct' => $record['law_enforcement_referral_pct'],
                'discipline_rate' => $record['discipline_rate'],
            ];

            // Update all matching districts
            foreach ($districts as $district) {
                $extra_data = $district->extra_data ? json_decode($district->extra_data, true) : [];
                $extra_data['discipline'] = $discipline;

                $result = $wpdb->update(
                    $wpdb->prefix . 'bmn_school_districts',
                    ['extra_data' => json_encode($extra_data)],
                    ['id' => $district->id],
                    ['%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $updated++;
                }
            }
        }

        return [
            'updated' => $updated,
            'not_found' => $not_found,
        ];
    }

    /**
     * AJAX handler for uploading sports data file.
     *
     * @since 0.6.24
     */
    public function ajax_upload_sports() {
        check_ajax_referer('bmn_schools_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        if (empty($_FILES['sports_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['sports_file'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error: ' . $file['error']]);
        }

        // Check file type
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            wp_send_json_error(['message' => 'Invalid file type. Please upload CSV or Excel file.']);
        }

        require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-logger.php';
        BMN_Schools_Logger::log('info', 'import', 'Sports file upload started', ['file' => $file['name']]);

        try {
            // Parse the file
            $data = $this->parse_sports_file($file['tmp_name'], $ext);

            if (empty($data)) {
                wp_send_json_error(['message' => 'No valid data found in file']);
            }

            // Import the data
            $result = $this->import_sports_data($data);

            // Update sync status
            self::update_source_sync('miaa_sports', $result['records_imported']);

            BMN_Schools_Logger::log('info', 'import', 'Sports import completed', $result);

            wp_send_json_success([
                'message' => sprintf('Successfully imported %d sport records for %d schools', $result['records_imported'], $result['schools_matched']),
                'records_imported' => $result['records_imported'],
                'schools_matched' => $result['schools_matched'],
                'schools_not_found' => $result['schools_not_found'],
                'total_schools' => count($data),
            ]);

        } catch (Exception $e) {
            BMN_Schools_Logger::log('error', 'import', 'Sports import failed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Import failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Parse sports data file (CSV).
     *
     * Expected format from MIAA PDF conversion:
     * season, school, boys_Baseball, boys_Basketball, ... girls_Baseball, ... total_boys, total_girls, total_school, source_page
     *
     * @since 0.6.24
     * @param string $filepath Path to uploaded file.
     * @param string $ext File extension.
     * @return array Parsed data - array of school records with sports arrays.
     */
    private function parse_sports_file($filepath, $ext) {
        $data = [];

        if ($ext === 'csv') {
            $data = $this->parse_sports_csv($filepath);
        } else {
            throw new Exception('Please convert to CSV format before uploading.');
        }

        return $data;
    }

    /**
     * Parse CSV sports file from MIAA data.
     *
     * @since 0.6.24
     * @param string $filepath Path to CSV file.
     * @return array Parsed data.
     */
    private function parse_sports_csv($filepath) {
        $data = [];
        $handle = fopen($filepath, 'r');

        if (!$handle) {
            throw new Exception('Could not open file');
        }

        $headers = null;
        $row_num = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            // First row is headers
            if ($headers === null) {
                $headers = $row;
                continue;
            }

            // Skip empty rows
            if (empty($row[1])) {
                continue;
            }

            $record = $this->map_sports_row($row, $headers);
            if ($record && !empty($record['sports'])) {
                $data[] = $record;
            }
        }

        fclose($handle);
        return $data;
    }

    /**
     * Map a sports data row to structured array.
     *
     * @since 0.6.24
     * @param array $row Row data.
     * @param array $headers Column headers.
     * @return array|null Mapped record or null.
     */
    private function map_sports_row($row, $headers) {
        // Get school name (column 1)
        $school_name = trim($row[1] ?? '');
        if (empty($school_name)) {
            return null;
        }

        // Get season/year (column 0)
        $season = trim($row[0] ?? '2024-25');
        // Extract year from "2024-25" format - use the first year
        preg_match('/(\d{4})/', $season, $matches);
        $year = isset($matches[1]) ? (int) $matches[1] : (int) date('Y');

        // Get totals
        $total_boys = 0;
        $total_girls = 0;
        $total_school = 0;

        // Find total columns by header name
        foreach ($headers as $i => $header) {
            if (stripos($header, 'total_boys') !== false) {
                $total_boys = (int) ($row[$i] ?? 0);
            } elseif (stripos($header, 'total_girls') !== false) {
                $total_girls = (int) ($row[$i] ?? 0);
            } elseif (stripos($header, 'total_school') !== false) {
                $total_school = (int) ($row[$i] ?? 0);
            }
        }

        // Parse individual sports
        $sports = [];
        foreach ($headers as $i => $header) {
            // Skip non-sport columns
            if (in_array(strtolower($header), ['season', 'school', 'source_page']) ||
                stripos($header, 'total_') !== false) {
                continue;
            }

            $participants = (int) ($row[$i] ?? 0);
            if ($participants <= 0) {
                continue;
            }

            // Parse header like "boys_Basketball" or "girls_Cross Country"
            if (preg_match('/^(boys|girls)_(.+)$/i', $header, $matches)) {
                $gender = ucfirst(strtolower($matches[1])); // "Boys" or "Girls"
                $sport = trim($matches[2]);

                // Normalize sport names
                $sport = str_replace(['Swim/Dive', 'Swim/Div'], 'Swimming/Diving', $sport);
                $sport = str_replace(' - ', ' ', $sport); // "Ski - Alpine" -> "Ski Alpine"

                $sports[] = [
                    'sport' => $sport,
                    'gender' => $gender,
                    'participants' => $participants,
                ];
            }
        }

        return [
            'school_name' => $school_name,
            'year' => $year,
            'total_boys' => $total_boys,
            'total_girls' => $total_girls,
            'total_participants' => $total_school,
            'sports' => $sports,
        ];
    }

    /**
     * Import parsed sports data into database.
     *
     * @since 0.6.24
     * @param array $data Parsed sports data.
     * @return array Results with counts.
     */
    private function import_sports_data($data) {
        global $wpdb;

        $records_imported = 0;
        $schools_matched = 0;
        $schools_not_found = 0;

        $sports_table = $wpdb->prefix . 'bmn_school_sports';

        foreach ($data as $record) {
            $school_name = $record['school_name'];
            $year = $record['year'];

            // Try to find matching school
            $school = $this->find_school_by_name($school_name);

            if (!$school) {
                $schools_not_found++;
                continue;
            }

            $schools_matched++;

            // Delete existing sports data for this school/year
            $wpdb->delete(
                $sports_table,
                [
                    'school_id' => $school->id,
                    'year' => $year,
                ],
                ['%d', '%d']
            );

            // Insert new sports data
            foreach ($record['sports'] as $sport_data) {
                $result = $wpdb->insert(
                    $sports_table,
                    [
                        'school_id' => $school->id,
                        'year' => $year,
                        'sport' => $sport_data['sport'],
                        'gender' => $sport_data['gender'],
                        'participants' => $sport_data['participants'],
                    ],
                    ['%d', '%d', '%s', '%s', '%d']
                );

                if ($result) {
                    $records_imported++;
                }
            }
        }

        return [
            'records_imported' => $records_imported,
            'schools_matched' => $schools_matched,
            'schools_not_found' => $schools_not_found,
        ];
    }

    /**
     * Find a school by name with fuzzy matching.
     *
     * @since 0.6.24
     * @param string $name School name from MIAA data.
     * @return object|null School record or null.
     */
    private function find_school_by_name($name) {
        global $wpdb;

        // Clean up the name
        $clean_name = trim($name);

        // Common abbreviations and suffixes to handle
        $search_variations = [
            $clean_name,
        ];

        // Try removing common suffixes
        $suffixes = [
            ' H.S.', ' HS', ' High School', ' High', ' Reg H.S.', ' Reg. High School',
            ' Regional High School', ' Regional HS', ' Reg HS',
            ' Voc/Tech HS', ' Voc/Tech', ' Vocational Technical High School',
            ' RVT High School', ' RVT HS',
            ' Charter School', ' Charter Public School', ' Charter', ' Public',
            ' Academy', ' Acad.', ' School', ' Schl.', ' Sch.',
            ' Mid/High School', ' Middle/High School',
        ];

        foreach ($suffixes as $suffix) {
            if (stripos($clean_name, $suffix) !== false) {
                $base = preg_replace('/' . preg_quote($suffix, '/') . '$/i', '', $clean_name);
                $search_variations[] = trim($base);
                $search_variations[] = trim($base) . ' High School';
                $search_variations[] = trim($base) . ' High';
            }
        }

        // Add variations with "Regional"
        if (stripos($clean_name, 'Reg') !== false) {
            $without_reg = preg_replace('/\s+Reg\.?\s*/i', ' ', $clean_name);
            $search_variations[] = trim($without_reg);
        }

        // Try each variation
        foreach ($search_variations as $search_name) {
            if (empty($search_name)) {
                continue;
            }

            // Exact match first
            $school = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}bmn_schools WHERE name = %s LIMIT 1",
                $search_name
            ));

            if ($school) {
                return $school;
            }

            // Try LIKE match
            $school = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}bmn_schools WHERE name LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($search_name) . '%'
            ));

            if ($school) {
                return $school;
            }
        }

        // Try matching just the first word (city name) + "High"
        $words = explode(' ', $clean_name);
        if (count($words) > 1) {
            $first_word = $words[0];
            // Handle hyphenated names like "Acton-Boxborough"
            if (strpos($first_word, '-') !== false) {
                $parts = explode('-', $first_word);
                $first_word = $parts[0];
            }

            // Try city name + High School pattern
            $school = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}bmn_schools
                 WHERE name LIKE %s
                 LIMIT 1",
                $wpdb->esc_like($first_word) . '%High%'
            ));

            if ($school) {
                return $school;
            }
        }

        return null;
    }
}
