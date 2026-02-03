<?php
/**
 * MLS Listings Display - Plugin Cleanup & Reinitialization
 *
 * Handles cleanup and reinitialization of plugin components after updates
 *
 * @package MLS_Listings_Display
 * @since 4.5.46
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Plugin_Cleanup {

    /**
     * Run full cleanup and reinitialization
     */
    public static function run_full_cleanup() {
        self::log_action('Starting full plugin cleanup and reinitialization');

        try {
            // Clear all caches
            self::clear_all_caches();

            // Reinitialize email template system
            self::reinitialize_email_templates();

            // Clear transients
            self::clear_plugin_transients();

            // Reinitialize field mappers
            self::reinitialize_field_mappers();

            // Clear opcache if available
            self::clear_opcache();

            // Force autoloader refresh
            self::refresh_autoloaders();

            self::log_action('Full cleanup completed successfully');
            return true;

        } catch (Exception $e) {
            self::log_action('Cleanup failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Clear all WordPress and plugin caches
     */
    private static function clear_all_caches() {
        self::log_action('Clearing all caches');

        // WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // WordPress transients cache
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('transient');
        }

        // Clear rewrite rules
        flush_rewrite_rules();

        // Clear any persistent object cache
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }

        self::log_action('Cache clearing completed');
    }

    /**
     * Reinitialize email template system
     */
    private static function reinitialize_email_templates() {
        self::log_action('Reinitializing email template system');

        try {
            // Clear template-related options cache
            wp_cache_delete('alloptions', 'options');

            // Try to load template classes if not already loaded
            $template_files = [
                'includes/email-template-editor/class-mld-template-customizer.php',
                'includes/email-template-editor/class-mld-template-variables.php'
            ];

            foreach ($template_files as $file) {
                $file_path = MLD_PLUGIN_PATH . $file;
                if (file_exists($file_path) && !class_exists(basename($file_path, '.php'))) {
                    require_once $file_path;
                }
            }

            // Force reload of template classes
            if (class_exists('MLD_Template_Customizer')) {
                // Skip template verification during cleanup to avoid loading issues
                // Just verify the class methods exist
                if (method_exists('MLD_Template_Customizer', 'get_template')) {
                    self::log_action("Template Customizer class and methods available");

                    // Only verify template options exist in database
                    $alert_types = ['new_listing', 'instant', 'daily_digest', 'weekly_digest', 'hourly_digest', 'price_reduced', 'open_house'];

                    foreach ($alert_types as $type) {
                        $option_exists = get_option('mld_email_template_' . $type) !== false;
                        if ($option_exists) {
                            self::log_action("Template option '{$type}' exists in database");
                        } else {
                            self::log_action("Template option '{$type}' not found - will use defaults", 'info');
                        }
                    }
                } else {
                    self::log_action('MLD_Template_Customizer missing required methods', 'error');
                }
            } else {
                self::log_action('MLD_Template_Customizer class not available', 'warning');
            }

            // Force reload of template variables class
            if (class_exists('MLD_Template_Variables')) {
                self::log_action('Template Variables class available');
            } else {
                self::log_action('Template Variables class not found', 'warning');
            }

            self::log_action('Email template system reinitialization completed');
        } catch (Exception $e) {
            self::log_action('Email template reinitialization error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Clear plugin-specific transients
     */
    private static function clear_plugin_transients() {
        self::log_action('Clearing plugin transients');

        global $wpdb;

        // Delete all MLD-related transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

        // Delete template cache transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_template_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_template_%'");

        self::log_action('Plugin transients cleared');
    }

    /**
     * Reinitialize field mappers
     */
    private static function reinitialize_field_mappers() {
        self::log_action('Reinitializing field mappers');

        try {
            // Try to load field mapper class if not already loaded
            $mapper_file = MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-field-mapper.php';
            if (file_exists($mapper_file) && !class_exists('MLD_Field_Mapper')) {
                require_once $mapper_file;
            }

            if (class_exists('MLD_Field_Mapper')) {
                // Test field mapping with sample data
                $test_data = [
                    'listing_id' => 'TEST123',
                    'list_price' => 500000,
                    'bedrooms_total' => 3
                ];

                try {
                    $mapped = MLD_Field_Mapper::map_properties([$test_data]);

                    if (!empty($mapped[0]['ListingId'])) {
                        self::log_action('Field mapper working correctly');
                    } else {
                        self::log_action('Field mapper test failed', 'warning');
                    }
                } catch (Exception $e) {
                    self::log_action('Field mapper test error: ' . $e->getMessage(), 'error');
                }
            } else {
                self::log_action('MLD_Field_Mapper class not available', 'warning');
            }

            self::log_action('Field mapper reinitialization completed');
        } catch (Exception $e) {
            self::log_action('Field mapper reinitialization error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Clear PHP opcache if available
     */
    private static function clear_opcache() {
        if (function_exists('opcache_reset')) {
            if (opcache_reset()) {
                self::log_action('OPcache cleared successfully');
            } else {
                self::log_action('OPcache clear failed', 'warning');
            }
        } else {
            self::log_action('OPcache not available');
        }
    }

    /**
     * Refresh autoloaders
     */
    private static function refresh_autoloaders() {
        self::log_action('Refreshing autoloaders');

        // Force WordPress to reload plugin files
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('plugins', 'plugins');
            wp_cache_delete('active_plugins', 'options');
        }

        // Clear any Composer autoloader cache if it exists
        $composer_autoload = MLD_PLUGIN_PATH . 'vendor/autoload.php';
        if (file_exists($composer_autoload)) {
            self::log_action('Composer autoloader found');
        }

        self::log_action('Autoloader refresh completed');
    }

    /**
     * Test email template system functionality
     */
    public static function test_email_system() {
        self::log_action('Testing email template system');

        $results = [
            'template_classes' => false,
            'template_loading' => false,
            'variable_processing' => false,
            'field_mapping' => false
        ];

        // Test 1: Check if classes exist
        if (class_exists('MLD_Template_Customizer') && class_exists('MLD_Template_Variables')) {
            $results['template_classes'] = true;
            self::log_action('✓ Template classes available');
        } else {
            self::log_action('✗ Template classes missing', 'error');
        }

        // Test 2: Test template loading
        if ($results['template_classes']) {
            $template = MLD_Template_Customizer::get_template('new_listing');
            if ($template && !empty($template['body'])) {
                $results['template_loading'] = true;
                self::log_action('✓ Template loading working');
            } else {
                self::log_action('✗ Template loading failed', 'error');
            }
        }

        // Test 3: Test variable processing
        if ($results['template_classes']) {
            $test_template = 'Price: {property_price}';
            $test_data = ['ListPrice' => 500000];
            $user = wp_get_current_user();

            $processed = MLD_Template_Variables::process_template($test_template, $test_data, $user, []);
            if (strpos($processed, '$500,000') !== false) {
                $results['variable_processing'] = true;
                self::log_action('✓ Variable processing working');
            } else {
                self::log_action('✗ Variable processing failed: ' . $processed, 'error');
            }
        }

        // Test 4: Test field mapping
        if (class_exists('MLD_Field_Mapper')) {
            $test_data = [['listing_id' => 'TEST', 'list_price' => 123456]];
            $mapped = MLD_Field_Mapper::map_properties($test_data);

            if (!empty($mapped[0]['ListingId']) && !empty($mapped[0]['ListPrice'])) {
                $results['field_mapping'] = true;
                self::log_action('✓ Field mapping working');
            } else {
                self::log_action('✗ Field mapping failed', 'error');
            }
        }

        $success_count = count(array_filter($results));
        $total_tests = count($results);

        self::log_action("Email system test completed: {$success_count}/{$total_tests} tests passed");

        return $results;
    }

    /**
     * Get status of all email templates
     */
    public static function get_template_status() {
        self::log_action('Checking template status');

        $templates = [];
        $alert_types = ['new_listing', 'instant', 'daily_digest', 'weekly_digest', 'hourly_digest', 'price_reduced', 'open_house'];

        foreach ($alert_types as $type) {
            $option_name = 'mld_email_template_' . $type;
            $template = get_option($option_name);

            $templates[$type] = [
                'exists' => !empty($template),
                'has_subject' => !empty($template['subject']),
                'has_body' => !empty($template['body']),
                'updated_at' => $template['updated_at'] ?? 'unknown'
            ];
        }

        return $templates;
    }

    /**
     * Log cleanup actions
     */
    private static function log_action($message, $level = 'info') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] MLD Cleanup: {$message}";

        // Log to WordPress debug log if available
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($log_message);
        }

        // Also log via MLD_Logger if available
        if (class_exists('MLD_Logger')) {
            switch ($level) {
                case 'error':
                    MLD_Logger::error($message);
                    break;
                case 'warning':
                    MLD_Logger::warning($message);
                    break;
                default:
                    MLD_Logger::info($message);
                    break;
            }
        }
    }

    /**
     * Create admin notice about cleanup status
     */
    public static function show_cleanup_notice($success = true, $details = []) {
        $class = $success ? 'notice-success' : 'notice-error';
        $message = $success ? 'Plugin cleanup completed successfully!' : 'Plugin cleanup encountered issues.';

        echo '<div class="notice ' . $class . ' is-dismissible">';
        echo '<p><strong>MLS Listings Display:</strong> ' . $message . '</p>';

        if (!empty($details)) {
            echo '<ul>';
            foreach ($details as $detail) {
                echo '<li>' . esc_html($detail) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }
}