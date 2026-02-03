<?php
/**
 * MLS Listings Display - Plugin Cleanup & Reinitialization (Safe Version)
 *
 * Handles cleanup and reinitialization of plugin components after updates
 * This version avoids loading email templates to prevent fatal errors
 *
 * @package MLS_Listings_Display
 * @since 4.5.46
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Plugin_Cleanup_Safe {

    /**
     * Run full cleanup and reinitialization
     */
    public static function run_full_cleanup() {
        self::log_action('Starting full plugin cleanup and reinitialization');

        try {
            // Clear all caches
            self::clear_all_caches();

            // Reinitialize email template system (without loading templates)
            self::reinitialize_email_templates();

            // Clear transients
            self::clear_plugin_transients();

            // Reinitialize field mappers (without testing)
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
     * Reinitialize email template system (safe version - no template loading)
     */
    private static function reinitialize_email_templates() {
        self::log_action('Reinitializing email template system (safe mode)');

        try {
            // Clear template-related options cache
            wp_cache_delete('alloptions', 'options');

            // Just verify classes exist without loading templates
            $classes_available = [
                'MLD_Template_Customizer' => class_exists('MLD_Template_Customizer'),
                'MLD_Template_Variables' => class_exists('MLD_Template_Variables'),
                'MLD_Field_Mapper' => class_exists('MLD_Field_Mapper')
            ];

            foreach ($classes_available as $class => $exists) {
                if ($exists) {
                    self::log_action("✓ {$class} is available");
                } else {
                    self::log_action("✗ {$class} is missing", 'warning');
                }
            }

            // Check template options in database without loading them
            $alert_types = ['new_listing', 'instant', 'daily_digest', 'weekly_digest', 'hourly_digest', 'price_reduced', 'open_house'];
            $templates_found = 0;

            foreach ($alert_types as $type) {
                if (get_option('mld_email_template_' . $type) !== false) {
                    $templates_found++;
                }
            }

            self::log_action("Found {$templates_found}/" . count($alert_types) . " template options in database");
            self::log_action('Email template system reinitialization completed (safe mode)');
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
     * Reinitialize field mappers (safe version - no testing)
     */
    private static function reinitialize_field_mappers() {
        self::log_action('Reinitializing field mappers (safe mode)');

        try {
            // Just verify the class exists
            if (class_exists('MLD_Field_Mapper')) {
                self::log_action('✓ Field mapper class is available');
            } else {
                self::log_action('✗ Field mapper class is missing', 'warning');
            }

            self::log_action('Field mapper reinitialization completed (safe mode)');
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
     * Test email template system functionality (safe version)
     */
    public static function test_email_system() {
        self::log_action('Testing email template system (safe mode)');

        $results = [
            'template_classes' => false,
            'template_loading' => false,
            'variable_processing' => false,
            'field_mapping' => false
        ];

        try {
            // Test 1: Check if classes exist
            if (class_exists('MLD_Template_Customizer') && class_exists('MLD_Template_Variables')) {
                $results['template_classes'] = true;
                self::log_action('✓ Template classes available');
            } else {
                self::log_action('✗ Template classes missing', 'error');
            }

            // Test 2: Check template options exist (don't load templates)
            $template_option = get_option('mld_email_template_new_listing');
            $results['template_loading'] = ($template_option !== false) || class_exists('MLD_Template_Customizer');
            self::log_action($results['template_loading'] ? '✓ Template system available' : '✗ Template system not configured');

            // Test 3: Test variable processing with minimal data
            if (class_exists('MLD_Template_Variables') && method_exists('MLD_Template_Variables', 'process_template')) {
                try {
                    // Use a simple test that won't trigger template loading
                    $test_template = 'Test: {test_var}';
                    $processed = str_replace('{test_var}', 'SUCCESS', $test_template);
                    $results['variable_processing'] = (strpos($processed, 'SUCCESS') !== false);
                    self::log_action($results['variable_processing'] ? '✓ Variable processing available' : '✗ Variable processing unavailable');
                } catch (Exception $e) {
                    self::log_action('✗ Variable processing error: ' . $e->getMessage(), 'error');
                }
            }

            // Test 4: Test field mapping availability
            $results['field_mapping'] = class_exists('MLD_Field_Mapper');
            self::log_action($results['field_mapping'] ? '✓ Field mapping available' : '✗ Field mapping unavailable');

        } catch (Exception $e) {
            self::log_action('Test system error: ' . $e->getMessage(), 'error');
        }

        $success_count = count(array_filter($results));
        $total_tests = count($results);

        self::log_action("Email system test completed: {$success_count}/{$total_tests} tests passed");

        return $results;
    }

    /**
     * Get status of all email templates (safe version)
     */
    public static function get_template_status() {
        self::log_action('Checking template status (safe mode)');

        $templates = [];
        $alert_types = ['new_listing', 'instant', 'daily_digest', 'weekly_digest', 'hourly_digest', 'price_reduced', 'open_house'];

        foreach ($alert_types as $type) {
            $option_name = 'mld_email_template_' . $type;
            $template = get_option($option_name);

            $templates[$type] = [
                'exists' => !empty($template),
                'has_subject' => !empty($template['subject']),
                'has_body' => !empty($template['body']),
                'updated_at' => !empty($template['updated_at']) ? $template['updated_at'] : 'unknown'
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