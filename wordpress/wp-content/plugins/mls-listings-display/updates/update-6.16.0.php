<?php
/**
 * MLD Update to Version 6.16.0
 * CMA Enhancement Features: Save/Load Sessions, ARV Adjustments, PDF Export
 *
 * Features:
 * - Save and load CMA analysis sessions for logged-in users
 * - Store subject property data, filters, comparables, and summary statistics
 * - Track ARV (After Repair Value) adjustments separately
 * - PDF generation path tracking
 *
 * @since 6.16.0
 */

function mld_update_to_6_16_0() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.16.0] Starting CMA saved sessions database update');
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'mld_cma_saved_sessions';

    // Create the CMA saved sessions table
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        session_name VARCHAR(255) NOT NULL,
        description TEXT,
        is_favorite TINYINT(1) DEFAULT 0,

        -- Subject property snapshot
        subject_listing_id VARCHAR(50) NOT NULL,
        subject_property_data JSON NOT NULL COMMENT 'Full subject property snapshot',
        subject_overrides JSON COMMENT 'ARV adjustments if any',

        -- CMA configuration
        cma_filters JSON NOT NULL COMMENT 'All filter settings used',

        -- Results snapshot
        comparables_data JSON COMMENT 'Full comparables array',
        summary_statistics JSON COMMENT 'CMA summary stats',

        -- Quick-access metrics (denormalized for listing views)
        comparables_count INT DEFAULT 0,
        estimated_value_mid DECIMAL(15,2),

        -- PDF tracking
        pdf_path VARCHAR(500),
        pdf_generated_at DATETIME,

        -- Timestamps per CLAUDE.md standards
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,

        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_user_favorite (user_id, is_favorite),
        KEY idx_subject_listing (subject_listing_id),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    dbDelta($sql);

    // Verify table was created
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

    if ($table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.16.0] Created wp_mld_cma_saved_sessions table successfully');
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.16.0] ERROR: Failed to create wp_mld_cma_saved_sessions table');
            error_log('[MLD Update 6.16.0] Last error: ' . $wpdb->last_error);
        }
        return false;
    }

    // Create upload directory for CMA PDF reports
    $upload_dir = wp_upload_dir();
    $cma_dir = $upload_dir['basedir'] . '/cma-reports/';
    if (!file_exists($cma_dir)) {
        wp_mkdir_p($cma_dir);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.16.0] Created CMA reports directory: ' . $cma_dir);
        }
    }

    // Update the database version
    update_option('mld_db_version', '6.16.0');
    update_option('mld_version', '6.16.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.16.0] Database update completed successfully');
    }

    return true;
}
