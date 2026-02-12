<?php
/**
 * Plugin Name: Bridge MLS Extractor Pro - Optimized
 * Description: High-performance MLS data extraction with normalized database architecture and concurrent API processing
 * Version: 4.0.33
 * Author: AZ Home Solutions LLC
 * Text Domain: bridge-mls-extractor-pro
 *
 * Changelog v4.0.33:
 * - FIX: fetch_resource_in_chunks() now follows pagination (@odata.nextLink) to prevent open house data loss
 * - FIX: Incremental open house sync now fetches COMPLETE open house set before processing, preventing deletion of unmodified open houses
 *
 * Changelog v4.0.32:
 * - Pet detail columns now synced to summary table (pets_dogs_allowed, pets_cats_allowed, etc.)
 * - process_listing_summary() now calls parse_pets_allowed_details() and populates pet columns
 * - Enables pet filtering from fast summary table queries
 *
 * Changelog v4.0.31:
 * - Fixed archival bugs: summary table now properly routes to active/archive tables
 * - Fixed $archived_statuses array (removed Pending, Active Under Contract)
 * - Fixed is_listing_archived() to use class-level method
 * - Added move_summary_data() function for proper summary archival
 *
 * Changelog v4.0.30:
 * - Fixed virtual_tour_url being overwritten with empty string during listing updates
 * - process_listing_summary() now queries bme_virtual_tours table (authoritative source)
 * - MLSPIN doesn't provide VirtualTourURLUnbranded/VirtualTourURLBranded fields
 *
 * Changelog v4.0.29:
 * - Fixed timezone handling: use UTC time() everywhere, display with wp_date()
 * - Reverted incorrect current_time('timestamp') usage back to time()
 *
 * Changelog v4.0.28:
 * - Fixed duplicate transient lock conflict causing "system not ready" failures
 * - Removed redundant running check from health check (cron manager handles it)
 * - Fixed memory limit parsing for unlimited memory (-1) causing false "low memory" errors
 *
 * Changelog v4.0.27:
 * - Fixed cron not running extractions due to timezone mismatch
 * - Changed time() to current_time('timestamp') in extraction completion handler
 * - Extractions now properly run on 15-minute schedule
 *
 * Changelog v4.0.26:
 * - Removed redundant market analytics feature (use MLD plugin analytics instead)
 * - Removed files: class-bme-market-analytics.php, class-bme-analytics-dashboard.php
 * - Removed assets: analytics-dashboard.js, analytics-dashboard.css
 * - Cleaned up related code in admin, database manager, and user manager
 *
 * Changelog v4.0.25:
 * - Fixed timezone handling violations (time() -> current_time('timestamp'))
 * - Affected files: class-bme-cron-manager.php, class-bme-batch-manager.php, class-bme-extraction-engine.php
 * - Fixes user-facing timestamps: cron stats, activity logs, extraction scheduling, log cleanup
 * - Addresses WordPress timezone (America/New_York) vs server UTC mismatch
 *
 * Changelog v4.0.24:
 * - Added granular pet filters: dogs, cats, no pets, restrictions
 * - New columns: pets_dogs_allowed, pets_cats_allowed, pets_no_pets, pets_has_restrictions, pets_allowed_raw
 * - Updated stored procedures to include new pet columns
 *
 * Changelog v4.0.23:
 * - Fixed Last Run timestamp not updating after manual extraction runs
 * - Added _bme_last_run_time update to extraction completion handler
 *
 * Changelog v4.0.22:
 * - Fixed pets_allowed data processing from RESO PetsAllowed array to boolean
 * - Updated stored procedures to pull pet_friendly from features table
 * - Summary tables now correctly populate pet_friendly field
 *
 * Changelog v4.0.20:
 * - Added proactive stuck extraction detection and auto-recovery
 * - Fallback check now scans ALL batch extractions for stuck states
 * - Auto-recovers extractions stuck mid-session (no lock, stale progress)
 *
 * Changelog v4.0.19:
 * - Fixed Performance Dashboard cron display (removed phantom hooks)
 * - Added Batch Fallback Check to cron monitoring
 *
 * Changelog v4.0.18:
 * - Fixed large extraction stalling bug (6000+ listings)
 * - Added retry scheduling with verification for batch sessions
 * - Added immediate cron ping backup trigger
 * - Added 2-minute fallback check for batch continuations
 * - Extended lock during session waiting periods
 * - Fixed stale detection for batch waiting state
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BME_PRO_VERSION', '4.0.33'); // v4.0.33: Open house sync pagination + incremental sync safety
define('BME_VERSION', '4.0.33'); // v4.0.33: Open house sync pagination + incremental sync safety
define('BME_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BME_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BME_PLUGIN_FILE', __FILE__);
define('BME_CACHE_GROUP', 'bme_pro');
define('BME_API_TIMEOUT', 60);
define('BME_BATCH_SIZE', 100);
define('BME_CACHE_DURATION', 3600); // 1 hour

// Bootstrap new PSR-4 architecture
require_once BME_PLUGIN_DIR . 'src/autoload.php';

// Explicitly require core class files
require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-cache-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-api-client.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-data-processor.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-extraction-engine.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-admin.php';

// Load upgrade system classes
require_once BME_PLUGIN_DIR . 'includes/class-bme-database-migrator.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-upgrader.php';
// Note: class-bme-admin-legacy.php removed in v4.0.17 (deprecated, all functionality in BME_Admin)
require_once BME_PLUGIN_DIR . 'includes/class-bme-cron-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-post-types.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-virtual-tour-importer.php'; // New: Require Virtual Tour Importer
require_once BME_PLUGIN_DIR . 'includes/class-bme-address-normalizer.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-property-history-tracker.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-background-processor.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-batch-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-error-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-asset-optimizer.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-user-manager.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-advanced-search.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-saved-searches.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-property-comparison.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-email-notifications.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-activity-logger.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-query-tracker.php';
require_once BME_PLUGIN_DIR . 'includes/class-bme-update-manager.php';

// Note: Sync classes (BME_Sync_Health_Checker, BME_Sync_Coordinator, BME_Sync_Verifier)
// are available in includes/ but disabled to prevent memory issues.

/**
 * Main plugin class implementing singleton pattern
 */
final class Bridge_MLS_Extractor_Pro {

    private static $instance = null;
    private $container = [];

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_core_services();
    }

    /**
     * Initialize core services that don't require translations
     */
    private function init_core_services() {
        $this->container['db'] = new BME_Database_Manager();
        $this->container['cache'] = new BME_Cache_Manager();
        $this->container['api'] = new BME_API_Client();
        
        // Add Activity Logger for comprehensive activity tracking
        $this->container['activity_logger'] = new BME_Activity_Logger($this->container['db']);

        // Initialize query performance tracker
        $this->container['query_tracker'] = BME_Query_Tracker::get_instance();

        // Initialize batch manager
        $this->container['batch_manager'] = new BME_Batch_Manager($this->container['api']);
        
        // Extraction engine initialization moved to init_translation_services()
        
        // New: Add Virtual Tour Importer to the container
        $this->container['vt_importer'] = new BME_Virtual_Tour_Importer($this->container['db']);
        
        // Add Asset Optimizer for CDN and performance optimization
        $this->container['asset_optimizer'] = new BME_Asset_Optimizer();
        
        // Core services only - translation-dependent services moved to init_translation_services()
    }

    /**
     * Initialize services that require translations (called after textdomain loading)
     */
    private function init_translation_services() {
        // Add Data Processor (uses translation functions)
        $this->container['processor'] = new BME_Data_Processor($this->container['db'], $this->container['cache'], $this->container['activity_logger']);

        // Enhanced extraction engine with batch management and status detection
        $this->container['extractor'] = new BME_Extraction_Engine(
            $this->container['api'],
            $this->container['processor'],
            $this->container['cache'],
            $this->container['db'],  // Pass db for status detection
            $this->container['batch_manager'],  // Pass batch manager
            $this->container['activity_logger']  // Pass activity logger
        );

        // Add User Manager for authentication and role management
        $this->container['user_manager'] = new BME_User_Manager();

        // Add Advanced Search functionality
        $this->container['advanced_search'] = new BME_Advanced_Search($this->container['db'], $this->container['cache']);

        // Add Saved Searches and Favorites functionality
        $this->container['saved_searches'] = new BME_Saved_Searches($this->container['db'], $this->container['cache']);

        // Add Property Comparison functionality
        $this->container['property_comparison'] = new BME_Property_Comparison($this->container['db'], $this->container['cache']);

        // Add Email Notifications functionality
        $this->container['email_notifications'] = new BME_Email_Notifications(
            $this->container['db'],
            $this->container['cache'],
            $this->container['saved_searches']
        );

        // Add Update Manager for automatic database maintenance
        $this->container['update_manager'] = new BME_Update_Manager($this->container['db']);

        // Add Admin interface to container (needed for admin pages AND AJAX requests)
        if (is_admin() || wp_doing_ajax()) {
            $this->container['admin'] = new BME_Admin($this);
        }
    }

    /**
     * Get service from container.
     */
    public function get($service) {
        if (!isset($this->container[$service])) {
            error_log("BME Error: Attempted to get service '{$service}' but it was not found in the container.");
            throw new Exception("Service {$service} not found");
        }
        return $this->container[$service];
    }

    /**
     * Checks if the database is installed and up-to-date. Runs on every load.
     * This acts as a safeguard against failed activations or manual updates.
     */
    public function check_and_install_db() {
        $installed_version = get_option('bme_pro_version');
        if (version_compare($installed_version, BME_PRO_VERSION, '<')) {
            self::activate_plugin();
        } else {
            // Run migration check even if version hasn't changed
            // This ensures migration happens if database was restructured
            require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
            $db_manager = new BME_Database_Manager();
            $db_manager->migrate_virtual_tours_table();
        }
    }

    /**
     * Plugin activation: Creates tables and schedules cron jobs.
     */
    public static function activate_plugin() {
        // Manually require the Database Manager as autoloader might not be ready.
        require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
        // New: Manually require Virtual Tour Importer
        require_once BME_PLUGIN_DIR . 'includes/class-bme-virtual-tour-importer.php';
        // Require Update Manager for migrations
        require_once BME_PLUGIN_DIR . 'includes/class-bme-update-manager.php';

        try {
            $db_manager = new BME_Database_Manager();
            $db_manager->create_tables();
            $db_manager->verify_installation();

            // Run migration for virtual tours table if needed
            $db_manager->migrate_virtual_tours_table();

            // Run performance indexes migration
            if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php')) {
                require_once BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php';
                BME_Add_Performance_Indexes::run();
            }

            // Run update manager to handle all migrations and fixes
            $update_manager = new BME_Update_Manager($db_manager);
            $update_manager->run_update_process();

            // Run upgrade system on activation
            $upgrader = new BME_Upgrader();
            if ($upgrader->needs_upgrade()) {
                $upgrader->run_upgrade();
            }

            // Set activation flag and update version at the end of successful installation
            update_option('bme_pro_activated', true);
            update_option('bme_pro_version', BME_PRO_VERSION);

            // Set transient for Analytics V2 notice
            set_transient('bme_analytics_v2_activated', true, 3600);

            // Schedule all cron jobs on activation
            // Main cron (every 15 minutes)
            if (!wp_next_scheduled('bme_pro_cron_hook')) {
                wp_schedule_event(time(), 'every_15_minutes', 'bme_pro_cron_hook');
            }

            // Cleanup cron (hourly)
            if (!wp_next_scheduled('bme_pro_cleanup_hook')) {
                wp_schedule_event(time(), 'hourly', 'bme_pro_cleanup_hook');
            }

            // Virtual Tour import cron (hourly)
            if (!wp_next_scheduled('bme_pro_import_virtual_tours_hook')) {
                wp_schedule_event(time(), 'hourly', 'bme_pro_import_virtual_tours_hook');
            }

            // Data cleanup cron (daily)
            if (!wp_next_scheduled('bme_data_cleanup_hook')) {
                wp_schedule_event(time(), 'daily', 'bme_data_cleanup_hook');
            }

            // Summary table refresh - NO LONGER NEEDED (v4.0.14)
            // Summary table is now written in real-time during extraction
            // See process_listing_summary() in class-bme-data-processor.php
            // Keeping hook registered but empty for backwards compatibility
            // if (!wp_next_scheduled('bme_refresh_summary_hook')) {
            //     wp_schedule_event(time(), 'hourly', 'bme_refresh_summary_hook');
            // }

            // Search cache cleanup (daily)
            if (!wp_next_scheduled('bme_cleanup_cache_hook')) {
                wp_schedule_event(time(), 'daily', 'bme_cleanup_cache_hook');
            }

            // Cache statistics cleanup (daily)
            if (!wp_next_scheduled('bme_cache_stats_cleanup')) {
                wp_schedule_event(time(), 'daily', 'bme_cache_stats_cleanup');
            }

            // Email notifications
            if (!wp_next_scheduled('bme_send_search_alerts')) {
                wp_schedule_event(time(), 'hourly', 'bme_send_search_alerts');
            }

            if (!wp_next_scheduled('bme_send_price_alerts')) {
                wp_schedule_event(time(), 'daily', 'bme_send_price_alerts');
            }

            if (!wp_next_scheduled('bme_send_status_alerts')) {
                wp_schedule_event(time(), 'every_15_minutes', 'bme_send_status_alerts');
            }

        } catch (Exception $e) {
            error_log('BME Pro Activation Error: ' . $e->getMessage());
            set_transient('bme_pro_activation_error', 'Plugin activation failed: ' . $e->getMessage(), 60);
        }
    }

    /**
     * Plugin deactivation: Clears cron and optionally deletes all data.
     */
    public function deactivate() {
        // Clear all cron hooks
        $cron_hooks = [
            'bme_pro_cron_hook',
            'bme_pro_cleanup_hook',
            'bme_pro_cache_cleanup',
            'bme_pro_import_virtual_tours_hook',
            'bme_data_cleanup_hook',
            'bme_refresh_summary_hook',
            'bme_cleanup_cache_hook',
            'bme_cache_stats_cleanup',
            'bme_scheduled_extraction',
            'bme_send_search_alerts',
            'bme_send_price_alerts',
            'bme_send_status_alerts'
        ];
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        $delete_on_deactivation = get_option('bme_pro_delete_on_deactivation', false);

        if ($delete_on_deactivation) {
            self::cleanup_plugin_data();
            delete_option('bme_pro_delete_on_deactivation');
        }
    }

    /**
     * Centralized function to clean up all plugin data.
     */
    public static function cleanup_plugin_data() {
        global $wpdb;

        try {
            if (!class_exists('BME_Database_Manager')) {
                require_once BME_PLUGIN_DIR . 'includes/class-bme-database-manager.php';
            }
            $db_manager = new BME_Database_Manager();
            $tables = $db_manager->get_tables();

            foreach (array_reverse($tables) as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }

            // Clean up custom posts and their meta data
            $posts = get_posts(['post_type' => 'bme_extraction', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']);
            foreach ($posts as $post_id) {
                // Delete all post meta with _bme prefix before deleting the post
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '_bme_%'", $post_id));
                wp_delete_post($post_id, true);
            }
            
            // Clean up any remaining _bme post meta that might be on other post types
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_bme_%'");

            $options_to_delete = [
                // Core plugin options
                'bme_pro_api_credentials', 'bme_pro_performance_settings', 'bme_pro_delete_on_uninstall',
                'bme_pro_delete_on_deactivation', 'bme_pro_activated', 'bme_pro_version',
                
                // Cron and scheduling options
                'bme_pro_cron_stats', 'bme_pro_cron_activity', 'bme_pro_last_cron_check',
                
                // Virtual tour options
                'bme_pro_vt_file_url', 'bme_pro_last_vt_import_time', 'bme_pro_last_vt_import_status', 'bme_pro_last_vt_import_duration',
                
                // Database performance and error tracking
                'bme_slow_queries', 'bme_critical_alerts',
                
                // Asset optimization and CDN
                'bme_pro_cdn_config', 'bme_pro_asset_optimization',
                
                // Batch processing and cleanup flags
                'bme_batch_fallback_triggers', 'bme_background_cleanup_complete',
                
                // Search and analytics
                'bme_search_analytics'
            ];
            foreach ($options_to_delete as $option) {
                delete_option($option);
            }

            $cron_hooks = [ 'bme_pro_cron_hook', 'bme_pro_cleanup_hook', 'bme_pro_cache_cleanup', 'bme_pro_import_virtual_tours_hook' ]; // New: Add VT import hook
            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }

            // Clean up user meta, comment meta, and term meta
            $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'bme_pro_%' OR meta_key LIKE 'bme_%'");
            $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE 'bme_pro_%' OR meta_key LIKE 'bme_%'");
            if (isset($wpdb->termmeta)) {
                $wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'bme_pro_%' OR meta_key LIKE 'bme_%'");
            }
            
            // Clean up all transients and autoload options
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_pro_%' OR option_name LIKE '_transient_timeout_bme_pro_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_%' OR option_name LIKE '_transient_timeout_bme_%'");
            
            // Delete remaining bme_* options not in the protected list
            // SECURITY: Use proper prepared statement with placeholders
            if (!empty($options_to_delete)) {
                $placeholders = implode(',', array_fill(0, count($options_to_delete), '%s'));
                $sql = $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bme_%%' AND option_name NOT IN ($placeholders)",
                    $options_to_delete
                );
                $wpdb->query($sql);
            } else {
                // If no options to keep, delete all bme_* options
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bme_%'");
            }

            // Clean up WordPress-specific caches and transients
            wp_cache_flush(); // Flush all object cache
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group(BME_CACHE_GROUP);
            }
            
            // Clean up site transients (network-wide)
            if (is_multisite()) {
                $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'bme_pro_%' OR meta_key LIKE 'bme_%'");
                delete_site_transient('bme_pro_network_settings');
            }
            
            // Clean up any admin notices or update notices
            delete_transient('bme_pro_activation_error');
            delete_transient('bme_pro_admin_notices');
            
            // Clean up any activation/update flags
            delete_option('bme_pro_db_version');
            delete_option('bme_pro_installation_time');
            delete_option('bme_pro_first_activation');

            error_log('Bridge MLS Extractor Pro: Plugin data cleanup completed successfully.');

        } catch (Exception $e) {
            error_log('Bridge MLS Extractor Pro: Cleanup error - ' . $e->getMessage());
        }
    }

    /**
     * Initialize plugin (runs on 'init' action)
     */
    public function init() {
        // Load textdomain FIRST before any translation functions
        load_plugin_textdomain('bridge-mls-extractor-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize translation-dependent services
        $this->init_translation_services();

        // Now safe to use translation functions
        $cpt = new BME_Post_Types();
        $cpt->register();

        // Initialize background processor for batch session continuation
        new BME_Background_Processor($this->get('extractor'));

        // New: Pass virtual tour importer to cron manager
        new BME_Cron_Manager($this->get('extractor'), $this->get('vt_importer'));
    }

    public function __clone() { _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION); }
    public function __wakeup() { _doing_it_wrong(__FUNCTION__, 'Singleton pattern violation', BME_PRO_VERSION); }
}

/**
 * Global function to return the main plugin instance
 */
function bme_pro() {
    return Bridge_MLS_Extractor_Pro::instance();
}

// Register activation hook at root level (MUST be outside any function)
register_activation_hook(__FILE__, ['Bridge_MLS_Extractor_Pro', 'activate_plugin']);

/**
 * Initializes the plugin and its hooks.
 */
function bme_pro_init() {
    $plugin_instance = bme_pro();

    // Run the database health check on every load.
    $plugin_instance->check_and_install_db();

    // Register deactivation hook
    register_deactivation_hook(__FILE__, [$plugin_instance, 'deactivate']);
    add_action('init', [$plugin_instance, 'init']);

    // Admin component is already instantiated in container if needed
    
}
add_action('plugins_loaded', 'bme_pro_init');

/**
 * Check for plugin upgrades on admin_init
 * This ensures upgrades are applied when the plugin is updated via WordPress admin
 */
function bme_pro_check_upgrades() {
    // Only run on admin pages to avoid frontend performance impact
    if (!is_admin()) {
        return;
    }

    // Use the upgrader check_upgrades method
    BME_Upgrader::check_upgrades();
}
add_action('admin_init', 'bme_pro_check_upgrades');


// Handle performance indexes action
add_action('admin_post_bme_run_performance_indexes', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bme_performance_indexes')) {
        wp_die('Invalid nonce');
    }
    
    // Run the migration
    if (file_exists(BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php')) {
        require_once BME_PLUGIN_DIR . 'includes/migrations/add-performance-indexes.php';
        BME_Add_Performance_Indexes::run();
    }
    
    // Redirect back with success message
    wp_redirect(add_query_arg('bme_indexes_added', '1', wp_get_referer()));
    exit;
});
// Register data cleanup cron hook
add_action("bme_data_cleanup_hook", function() {
    $plugin = $GLOBALS["bme_pro_instance"] ?? null;
    if ($plugin && isset($plugin->container["db"])) {
        $deleted = $plugin->container["db"]->cleanup_old_data();
        if ($deleted > 0) {
            error_log("[BME Data Cleanup] Completed: {$deleted} records deleted");
        }
    }
});

// ========================================================================
// PHASE 2 OPTIMIZATION: Cron Jobs for Summary Table & Cache Management
// ========================================================================

/**
 * Summary table refresh hook - DEPRECATED in v4.0.14
 *
 * The summary table is now written in real-time during extraction.
 * See process_listing_summary() in class-bme-data-processor.php
 *
 * This hook is kept for backwards compatibility but does nothing.
 * MLD and other plugins may still trigger it, which is harmless.
 *
 * @since 4.0.3 Original implementation with stored procedure
 * @since 4.0.14 Deprecated - summary written during extraction
 * @since 4.0.17 Removed empty callback - hook no longer registered
 */
// NOTE: bme_refresh_summary_hook callback removed in v4.0.17
// Summary table is written in real-time during extraction since v4.0.14

/**
 * Cleanup expired search cache entries daily
 *
 * Removes cache entries that have exceeded their TTL to prevent
 * database bloat and ensure fresh results.
 */
add_action('bme_cleanup_cache_hook', function() {
    $plugin = bme_pro();
    if ($plugin) {
        $db = $plugin->get('db');
        if ($db) {
            $deleted = $db->cleanup_search_cache();
            if ($deleted > 0) {
                error_log("[BME Phase 2] Cache cleanup completed: {$deleted} expired entries removed");
            }
        }
    }
});

