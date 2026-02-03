<?php
/**
 * Update to version 6.12.0
 *
 * Enhanced Market Analytics System
 * Database changes:
 * - Add mld_market_stats_monthly table for pre-computed monthly statistics
 * - Add mld_city_market_summary table for current market state cache
 * - Add mld_agent_performance table for agent/office performance tracking
 * - Add mld_feature_premiums table for property feature value analysis
 * - Add indexes to archive tables for analytics query optimization
 *
 * @package MLS_Listings_Display
 * @since 6.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main update function for 6.12.0
 *
 * @return bool Success status
 */
function mld_update_to_6_12_0() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_prefix = $wpdb->prefix;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $tables_created = 0;
    $errors = array();

    // =========================================================================
    // Table 1: Market Stats Monthly (Pre-computed monthly statistics)
    // =========================================================================
    $table_name = $table_prefix . 'mld_market_stats_monthly';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        year smallint NOT NULL,
        month tinyint NOT NULL,
        city varchar(100) NOT NULL,
        state varchar(10) NOT NULL,
        property_type varchar(50) DEFAULT 'all',

        -- Sales Metrics
        total_sales int DEFAULT 0,
        total_volume decimal(15,2) DEFAULT NULL,
        avg_close_price decimal(12,2) DEFAULT NULL,
        median_close_price decimal(12,2) DEFAULT NULL,
        avg_price_per_sqft decimal(10,2) DEFAULT NULL,
        median_price_per_sqft decimal(10,2) DEFAULT NULL,
        min_close_price decimal(12,2) DEFAULT NULL,
        max_close_price decimal(12,2) DEFAULT NULL,

        -- Velocity Metrics
        avg_dom decimal(5,1) DEFAULT NULL,
        median_dom int DEFAULT NULL,
        avg_sp_lp_ratio decimal(5,2) DEFAULT NULL,
        median_sp_lp_ratio decimal(5,2) DEFAULT NULL,

        -- Supply Metrics
        new_listings int DEFAULT 0,
        expired_listings int DEFAULT 0,
        canceled_listings int DEFAULT 0,
        price_reductions int DEFAULT 0,
        avg_reduction_pct decimal(5,2) DEFAULT NULL,
        avg_reduction_amount decimal(12,2) DEFAULT NULL,

        -- Inventory Snapshot (end of month)
        active_inventory int DEFAULT 0,
        pending_inventory int DEFAULT 0,
        months_of_supply decimal(4,1) DEFAULT NULL,
        absorption_rate decimal(5,2) DEFAULT NULL,

        -- Calculated Metadata
        data_points int DEFAULT 0,
        calculation_date datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY idx_period (city, state, year, month, property_type),
        KEY idx_date (year, month),
        KEY idx_city (city),
        KEY idx_property_type (property_type),
        KEY idx_calculation (calculation_date)
    ) $charset_collate;";

    $result = dbDelta($sql);
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
        $tables_created++;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Created table: {$table_name}");
        }
    } else {
        $errors[] = "Failed to create {$table_name}";
    }

    // =========================================================================
    // Table 2: City Market Summary (Current market state cache)
    // =========================================================================
    $table_name = $table_prefix . 'mld_city_market_summary';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        city varchar(100) NOT NULL,
        state varchar(10) NOT NULL,
        property_type varchar(50) DEFAULT 'all',

        -- Current Inventory
        active_count int DEFAULT 0,
        pending_count int DEFAULT 0,
        new_this_week int DEFAULT 0,
        new_this_month int DEFAULT 0,

        -- Current Price Metrics
        avg_list_price decimal(12,2) DEFAULT NULL,
        median_list_price decimal(12,2) DEFAULT NULL,
        min_list_price decimal(12,2) DEFAULT NULL,
        max_list_price decimal(12,2) DEFAULT NULL,
        avg_price_per_sqft decimal(10,2) DEFAULT NULL,
        median_price_per_sqft decimal(10,2) DEFAULT NULL,

        -- 12-Month Performance
        sold_12m int DEFAULT 0,
        total_volume_12m decimal(15,2) DEFAULT NULL,
        avg_close_price_12m decimal(12,2) DEFAULT NULL,
        median_close_price_12m decimal(12,2) DEFAULT NULL,
        avg_dom_12m decimal(5,1) DEFAULT NULL,
        median_dom_12m int DEFAULT NULL,
        avg_sp_lp_12m decimal(5,2) DEFAULT NULL,

        -- Market Indicators
        months_of_supply decimal(4,1) DEFAULT NULL,
        absorption_rate decimal(5,2) DEFAULT NULL,
        market_heat_index int DEFAULT NULL,
        market_classification varchar(20) DEFAULT NULL,

        -- Price Reduction Stats
        price_reduction_rate decimal(5,2) DEFAULT NULL,
        avg_reduction_pct decimal(5,2) DEFAULT NULL,

        -- Year-over-Year Comparisons
        yoy_price_change_pct decimal(6,2) DEFAULT NULL,
        yoy_sales_change_pct decimal(6,2) DEFAULT NULL,
        yoy_inventory_change_pct decimal(6,2) DEFAULT NULL,
        yoy_dom_change_pct decimal(6,2) DEFAULT NULL,

        -- Metadata
        listing_count int DEFAULT 0,
        last_updated datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY idx_city (city, state, property_type),
        KEY idx_market_heat (market_heat_index),
        KEY idx_classification (market_classification),
        KEY idx_updated (last_updated)
    ) $charset_collate;";

    $result = dbDelta($sql);
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
        $tables_created++;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Created table: {$table_name}");
        }
    } else {
        $errors[] = "Failed to create {$table_name}";
    }

    // =========================================================================
    // Table 3: Agent Performance (Agent/office ranking and metrics)
    // =========================================================================
    $table_name = $table_prefix . 'mld_agent_performance';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        agent_mls_id varchar(50) NOT NULL,
        agent_name varchar(255) DEFAULT NULL,
        agent_email varchar(255) DEFAULT NULL,
        agent_phone varchar(50) DEFAULT NULL,
        office_mls_id varchar(50) DEFAULT NULL,
        office_name varchar(255) DEFAULT NULL,
        city varchar(100) DEFAULT NULL,
        state varchar(10) DEFAULT NULL,
        period_year smallint NOT NULL,
        period_type varchar(20) DEFAULT 'annual',

        -- Transaction Metrics
        transaction_count int DEFAULT 0,
        listing_count int DEFAULT 0,
        buyer_transactions int DEFAULT 0,
        seller_transactions int DEFAULT 0,

        -- Volume Metrics
        total_volume decimal(15,2) DEFAULT NULL,
        listing_volume decimal(15,2) DEFAULT NULL,
        buyer_volume decimal(15,2) DEFAULT NULL,
        avg_sale_price decimal(12,2) DEFAULT NULL,
        median_sale_price decimal(12,2) DEFAULT NULL,

        -- Performance Metrics
        avg_dom decimal(5,1) DEFAULT NULL,
        median_dom int DEFAULT NULL,
        avg_sp_lp_ratio decimal(5,2) DEFAULT NULL,
        close_rate decimal(5,2) DEFAULT NULL,

        -- Market Position
        market_share_pct decimal(5,2) DEFAULT NULL,
        volume_rank int DEFAULT NULL,
        transaction_rank int DEFAULT NULL,

        -- Metadata
        calculation_date datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY idx_agent_period (agent_mls_id, city, period_year, period_type),
        KEY idx_city_rank (city, volume_rank),
        KEY idx_office (office_mls_id),
        KEY idx_volume (total_volume),
        KEY idx_transactions (transaction_count),
        KEY idx_year (period_year)
    ) $charset_collate;";

    $result = dbDelta($sql);
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
        $tables_created++;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Created table: {$table_name}");
        }
    } else {
        $errors[] = "Failed to create {$table_name}";
    }

    // =========================================================================
    // Table 4: Feature Premiums (Property feature value analysis)
    // =========================================================================
    $table_name = $table_prefix . 'mld_feature_premiums';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        city varchar(100) NOT NULL,
        state varchar(10) NOT NULL,
        property_type varchar(50) DEFAULT 'all',
        feature_name varchar(50) NOT NULL,

        -- Premium Values
        premium_amount decimal(12,2) DEFAULT NULL,
        premium_pct decimal(5,2) DEFAULT NULL,
        premium_per_sqft decimal(10,2) DEFAULT NULL,

        -- Statistical Data
        sample_size_with int DEFAULT 0,
        sample_size_without int DEFAULT 0,
        avg_price_with decimal(12,2) DEFAULT NULL,
        avg_price_without decimal(12,2) DEFAULT NULL,
        median_price_with decimal(12,2) DEFAULT NULL,
        median_price_without decimal(12,2) DEFAULT NULL,
        stddev_with decimal(12,2) DEFAULT NULL,
        stddev_without decimal(12,2) DEFAULT NULL,

        -- Confidence Metrics
        confidence_score decimal(3,2) DEFAULT NULL,
        p_value decimal(6,4) DEFAULT NULL,
        is_significant tinyint(1) DEFAULT 0,

        -- Control Variables Used
        control_sqft_range varchar(50) DEFAULT NULL,
        control_bedroom_range varchar(20) DEFAULT NULL,
        control_year_range varchar(20) DEFAULT NULL,

        -- Metadata
        calculation_date datetime DEFAULT CURRENT_TIMESTAMP,
        data_start_date date DEFAULT NULL,
        data_end_date date DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        UNIQUE KEY idx_city_feature (city, state, property_type, feature_name),
        KEY idx_feature (feature_name),
        KEY idx_confidence (confidence_score),
        KEY idx_significant (is_significant),
        KEY idx_calculation (calculation_date)
    ) $charset_collate;";

    $result = dbDelta($sql);
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
        $tables_created++;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Created table: {$table_name}");
        }
    } else {
        $errors[] = "Failed to create {$table_name}";
    }

    // =========================================================================
    // Add Indexes to Archive Tables (for analytics query optimization)
    // =========================================================================
    $indexes_added = mld_add_analytics_indexes_6_12();

    // =========================================================================
    // Schedule Cron Jobs for Analytics
    // =========================================================================
    mld_schedule_analytics_cron_jobs_6_12();

    // =========================================================================
    // Initialize Default Analytics Settings
    // =========================================================================
    mld_init_analytics_settings_6_12();

    // Update plugin version
    update_option('mld_db_version', '6.12.0');

    // Clear any existing caches
    wp_cache_flush();

    // Log results
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[MLD Update 6.12.0] Enhanced Market Analytics system installed");
        error_log("[MLD Update 6.12.0] Tables created: {$tables_created}/4");
        error_log("[MLD Update 6.12.0] Indexes added: {$indexes_added}");
        if (!empty($errors)) {
            error_log("[MLD Update 6.12.0] Errors: " . implode(', ', $errors));
        }
    }

    return $tables_created >= 4;
}

/**
 * Add analytics indexes to archive tables
 *
 * @return int Number of indexes added
 */
function mld_add_analytics_indexes_6_12() {
    global $wpdb;
    $indexes_added = 0;

    // Index definitions: table => array of indexes
    $indexes = array(
        'bme_listings_archive' => array(
            'idx_analytics_status_date' => '(standard_status, close_date)',
            'idx_analytics_agent' => '(list_agent_mls_id)',
            'idx_analytics_office' => '(list_office_mls_id)'
        ),
        'bme_listing_location_archive' => array(
            'idx_analytics_city_state' => '(city(50), state_or_province(10))'
        )
    );

    foreach ($indexes as $table_suffix => $table_indexes) {
        $table_name = $wpdb->prefix . $table_suffix;

        // Check if table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            continue;
        }

        foreach ($table_indexes as $index_name => $index_columns) {
            // Check if index already exists
            $existing = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = '{$index_name}'");

            if (empty($existing)) {
                $sql = "ALTER TABLE {$table_name} ADD INDEX {$index_name} {$index_columns}";
                $result = $wpdb->query($sql);

                if ($result !== false) {
                    $indexes_added++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[MLD Update 6.12.0] Added index {$index_name} to {$table_name}");
                    }
                }
            }
        }
    }

    return $indexes_added;
}

/**
 * Schedule analytics cron jobs
 */
function mld_schedule_analytics_cron_jobs_6_12() {
    // Register custom cron schedules if not already registered
    add_filter('cron_schedules', 'mld_add_analytics_cron_schedules_6_12', 10);

    // Daily analytics update (3 AM)
    if (!wp_next_scheduled('mld_analytics_daily_update')) {
        $tomorrow_3am = strtotime('tomorrow 03:00:00');
        wp_schedule_event($tomorrow_3am, 'daily', 'mld_analytics_daily_update');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Scheduled mld_analytics_daily_update for daily at 3 AM");
        }
    }

    // Hourly city summary refresh
    if (!wp_next_scheduled('mld_analytics_hourly_refresh')) {
        wp_schedule_event(time(), 'hourly', 'mld_analytics_hourly_refresh');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Scheduled mld_analytics_hourly_refresh");
        }
    }

    // Monthly rebuild (1st of month at 2 AM)
    if (!wp_next_scheduled('mld_analytics_monthly_rebuild')) {
        $next_first = strtotime('first day of next month 02:00:00');
        wp_schedule_event($next_first, 'monthly', 'mld_analytics_monthly_rebuild');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Scheduled mld_analytics_monthly_rebuild");
        }
    }

    // Weekly agent performance update (Sunday at 4 AM)
    if (!wp_next_scheduled('mld_analytics_agent_update')) {
        $next_sunday = strtotime('next sunday 04:00:00');
        wp_schedule_event($next_sunday, 'weekly', 'mld_analytics_agent_update');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[MLD Update 6.12.0] Scheduled mld_analytics_agent_update for weekly");
        }
    }
}

/**
 * Add custom cron schedules
 *
 * @param array $schedules Existing schedules
 * @return array Modified schedules
 */
function mld_add_analytics_cron_schedules_6_12($schedules) {
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = array(
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => 'Once Monthly'
        );
    }
    return $schedules;
}

/**
 * Initialize default analytics settings
 */
function mld_init_analytics_settings_6_12() {
    $defaults = array(
        'mld_analytics_enabled' => 1,
        'mld_analytics_cache_ttl_monthly' => 604800,
        'mld_analytics_cache_ttl_summary' => 3600,
        'mld_analytics_cache_ttl_agents' => 86400,
        'mld_analytics_cache_ttl_premiums' => 604800,
        'mld_analytics_min_listings' => 10,
        'mld_analytics_history_months' => 24,
        'mld_analytics_market_heat_weights' => json_encode(array(
            'dom' => 0.25,
            'sp_lp' => 0.30,
            'inventory' => 0.25,
            'absorption' => 0.20
        ))
    );

    foreach ($defaults as $option => $value) {
        if (get_option($option) === false) {
            add_option($option, $value);
        }
    }
}
