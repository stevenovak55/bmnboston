<?php
/**
 * Update to version 5.2.0
 *
 * Database changes for CMA enhancements:
 * - Add mld_cma_emails table for tracking email deliveries
 * - Add mld_cma_settings table for configuration storage
 * - Add mld_cma_reports table for tracking generated reports
 *
 * @package MLS_Listings_Display
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function mld_update_to_5_2_0() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table 1: CMA Email Tracking
    $table_name = $table_prefix . 'mld_cma_emails';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255) DEFAULT NULL,
        property_address varchar(500) DEFAULT NULL,
        agent_name varchar(255) DEFAULT NULL,
        agent_email varchar(255) DEFAULT NULL,
        pdf_attached tinyint(1) DEFAULT 0,
        sent_at datetime NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY recipient_email (recipient_email),
        KEY sent_at (sent_at),
        KEY agent_email (agent_email)
    ) $charset_collate;";

    dbDelta($sql);

    // Table 2: CMA Settings/Configuration
    $table_name = $table_prefix . 'mld_cma_settings';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value longtext DEFAULT NULL,
        setting_type varchar(50) DEFAULT 'string',
        city varchar(100) DEFAULT NULL,
        state varchar(50) DEFAULT NULL,
        property_type varchar(100) DEFAULT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_setting (setting_key, city, state, property_type),
        KEY city_state (city, state)
    ) $charset_collate;";

    dbDelta($sql);

    // Table 3: CMA Generated Reports Tracking
    $table_name = $table_prefix . 'mld_cma_reports';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        property_address varchar(500) DEFAULT NULL,
        property_city varchar(100) DEFAULT NULL,
        property_state varchar(50) DEFAULT NULL,
        mls_number varchar(100) DEFAULT NULL,
        estimated_value_low decimal(15,2) DEFAULT NULL,
        estimated_value_high decimal(15,2) DEFAULT NULL,
        comparables_count int(11) DEFAULT 0,
        pdf_path varchar(500) DEFAULT NULL,
        pdf_url varchar(500) DEFAULT NULL,
        generated_by varchar(255) DEFAULT NULL,
        generated_for varchar(255) DEFAULT NULL,
        include_forecast tinyint(1) DEFAULT 1,
        include_investment tinyint(1) DEFAULT 1,
        generated_at datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY property_city_state (property_city, property_state),
        KEY mls_number (mls_number),
        KEY generated_at (generated_at)
    ) $charset_collate;";

    dbDelta($sql);

    // Insert default CMA settings
    $defaults = array(
        // Market data cache duration (in seconds)
        array(
            'setting_key' => 'market_data_cache_duration',
            'setting_value' => '3600',
            'setting_type' => 'integer'
        ),
        // Forecast cache duration (in seconds)
        array(
            'setting_key' => 'forecast_cache_duration',
            'setting_value' => '21600',
            'setting_type' => 'integer'
        ),
        // Default PDF report title
        array(
            'setting_key' => 'default_report_title',
            'setting_value' => 'Comparative Market Analysis',
            'setting_type' => 'string'
        ),
        // Enable/disable forecast in reports
        array(
            'setting_key' => 'enable_forecast',
            'setting_value' => '1',
            'setting_type' => 'boolean'
        ),
        // Enable/disable investment analysis
        array(
            'setting_key' => 'enable_investment_analysis',
            'setting_value' => '1',
            'setting_type' => 'boolean'
        ),
        // Default email template
        array(
            'setting_key' => 'default_email_template',
            'setting_value' => 'default',
            'setting_type' => 'string'
        ),
    );

    $settings_table = $table_prefix . 'mld_cma_settings';
    foreach ($defaults as $default) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$settings_table} WHERE setting_key = %s AND city IS NULL",
            $default['setting_key']
        ));

        if (!$exists) {
            $wpdb->insert(
                $settings_table,
                $default,
                array('%s', '%s', '%s')
            );
        }
    }

    // Update plugin version
    update_option('mld_db_version', '5.2.0');

    // Clear any existing caches
    mld_clear_all_cma_caches();

    return true;
}

/**
 * Clear all CMA-related caches
 */
function mld_clear_all_cma_caches() {
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // Clear market data cache
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_prefix}options WHERE option_name LIKE %s",
            '_transient_mld_market_%'
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_prefix}options WHERE option_name LIKE %s",
            '_transient_timeout_mld_market_%'
        )
    );

    // Clear forecast cache
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_prefix}options WHERE option_name LIKE %s",
            '_transient_mld_forecast_%'
        )
    );

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_prefix}options WHERE option_name LIKE %s",
            '_transient_timeout_mld_forecast_%'
        )
    );
}
