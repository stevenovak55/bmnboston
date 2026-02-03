<?php
/**
 * Update to version 6.69.0
 *
 * Open House Sign-In System
 * - Creates wp_mld_open_houses table for agent open house events
 * - Creates wp_mld_open_house_attendees table for visitor sign-ins
 *
 * @package MLS_Listings_Display
 * @since 6.69.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.69.0 update
 *
 * @return bool Success status
 */
function mld_update_to_6_69_0() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Create wp_mld_open_houses table
    $open_houses_table = $wpdb->prefix . 'mld_open_houses';
    $sql_open_houses = "CREATE TABLE IF NOT EXISTS {$open_houses_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        agent_user_id BIGINT UNSIGNED NOT NULL,
        listing_id VARCHAR(20) DEFAULT NULL COMMENT 'MLS number if linked to MLS property',
        property_address VARCHAR(255) NOT NULL,
        property_city VARCHAR(100) NOT NULL,
        property_state VARCHAR(50) NOT NULL DEFAULT 'MA',
        property_zip VARCHAR(20) NOT NULL,
        property_type VARCHAR(50) DEFAULT NULL,
        beds INT DEFAULT NULL,
        baths DECIMAL(3,1) DEFAULT NULL,
        list_price INT DEFAULT NULL,
        photo_url VARCHAR(500) DEFAULT NULL,
        latitude DECIMAL(10,7) DEFAULT NULL,
        longitude DECIMAL(10,7) DEFAULT NULL,
        event_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled, active, completed, cancelled',
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_agent (agent_user_id),
        INDEX idx_date (event_date),
        INDEX idx_status (status),
        INDEX idx_listing (listing_id),
        FOREIGN KEY (agent_user_id) REFERENCES {$wpdb->users}(ID) ON DELETE CASCADE
    ) $charset_collate;";

    dbDelta($sql_open_houses);

    // Create wp_mld_open_house_attendees table
    $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';
    $sql_attendees = "CREATE TABLE IF NOT EXISTS {$attendees_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        open_house_id BIGINT UNSIGNED NOT NULL,
        local_uuid VARCHAR(36) NOT NULL COMMENT 'UUID from iOS for offline dedup',

        -- Contact Info (Required)
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,

        -- Agent Relationship
        working_with_agent VARCHAR(20) NOT NULL DEFAULT 'no' COMMENT 'no, yes_other, yes_this_agent',
        other_agent_name VARCHAR(200) DEFAULT NULL,
        other_agent_brokerage VARCHAR(200) DEFAULT NULL,

        -- Buying Intent
        buying_timeline VARCHAR(30) NOT NULL DEFAULT 'just_browsing' COMMENT 'just_browsing, 0_to_3_months, 3_to_6_months, 6_plus',
        pre_approved VARCHAR(20) NOT NULL DEFAULT 'not_sure' COMMENT 'yes, no, not_sure',
        lender_name VARCHAR(200) DEFAULT NULL,

        -- Marketing Attribution
        how_heard_about VARCHAR(50) DEFAULT NULL COMMENT 'signage, online_ad, zillow, redfin, realtor_com, facebook, instagram, friend, agent_marketing, drive_by, other',

        -- Consent (GDPR/Privacy)
        consent_to_follow_up TINYINT(1) NOT NULL DEFAULT 1,
        consent_to_email TINYINT(1) NOT NULL DEFAULT 1,
        consent_to_text TINYINT(1) NOT NULL DEFAULT 0,

        -- Agent Assessment
        interest_level VARCHAR(20) DEFAULT 'unknown' COMMENT 'not_interested, somewhat, very_interested, unknown',
        agent_notes TEXT DEFAULT NULL,

        -- Timestamps
        signed_in_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_open_house (open_house_id),
        INDEX idx_email (email),
        INDEX idx_signed_in (signed_in_at),
        UNIQUE KEY unique_local_uuid (local_uuid),
        FOREIGN KEY (open_house_id) REFERENCES {$open_houses_table}(id) ON DELETE CASCADE
    ) $charset_collate;";

    dbDelta($sql_attendees);

    // Verify tables were created
    $open_houses_exists = $wpdb->get_var("SHOW TABLES LIKE '{$open_houses_table}'");
    $attendees_exists = $wpdb->get_var("SHOW TABLES LIKE '{$attendees_table}'");

    if (!$open_houses_exists || !$attendees_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.69.0] Failed to create Open House tables');
            error_log('[MLD Update 6.69.0] open_houses: ' . ($open_houses_exists ? 'exists' : 'missing'));
            error_log('[MLD Update 6.69.0] attendees: ' . ($attendees_exists ? 'exists' : 'missing'));
        }
        return false;
    }

    // Update version
    update_option('mld_db_version', '6.69.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.69.0] Open House tables created successfully');
    }

    return true;
}
