<?php
/**
 * Update to version 6.70.0
 *
 * Open House Sign-In System Refinement
 * - Adds agent visitor detection fields to attendees table
 * - Adds CRM integration fields for client conversion
 * - Adds priority scoring for attendee sorting
 *
 * @package MLS_Listings_Display
 * @since 6.70.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.70.0 update
 *
 * @return bool Success status
 */
function mld_update_to_6_70_0() {
    global $wpdb;

    $attendees_table = $wpdb->prefix . 'mld_open_house_attendees';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$attendees_table}'");
    if (!$table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.0] Attendees table does not exist, skipping');
        }
        return false;
    }

    $errors = array();

    // Add is_agent column (branch indicator: 0 = buyer, 1 = agent)
    $is_agent_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'is_agent'");
    if (!$is_agent_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN is_agent TINYINT(1) NOT NULL DEFAULT 0
            COMMENT '0=buyer visitor, 1=real estate agent visitor'
            AFTER phone");
        if ($result === false) {
            $errors[] = 'is_agent';
        }
    }

    // Add agent_brokerage column
    $agent_brokerage_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'agent_brokerage'");
    if (!$agent_brokerage_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN agent_brokerage VARCHAR(200) DEFAULT NULL
            COMMENT 'Agent company/brokerage name'
            AFTER is_agent");
        if ($result === false) {
            $errors[] = 'agent_brokerage';
        }
    }

    // Add agent_visit_purpose column (enum-like)
    $agent_visit_purpose_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'agent_visit_purpose'");
    if (!$agent_visit_purpose_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN agent_visit_purpose VARCHAR(30) DEFAULT NULL
            COMMENT 'previewing, comps, networking, curiosity, other'
            AFTER agent_brokerage");
        if ($result === false) {
            $errors[] = 'agent_visit_purpose';
        }
    }

    // Add agent_has_buyer column
    $agent_has_buyer_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'agent_has_buyer'");
    if (!$agent_has_buyer_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN agent_has_buyer TINYINT(1) DEFAULT NULL
            COMMENT 'Does the agent have a buyer interested in this property'
            AFTER agent_visit_purpose");
        if ($result === false) {
            $errors[] = 'agent_has_buyer';
        }
    }

    // Add agent_buyer_timeline column
    $agent_buyer_timeline_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'agent_buyer_timeline'");
    if (!$agent_buyer_timeline_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN agent_buyer_timeline VARCHAR(30) DEFAULT NULL
            COMMENT 'When might the agent buyer make an offer'
            AFTER agent_has_buyer");
        if ($result === false) {
            $errors[] = 'agent_buyer_timeline';
        }
    }

    // Add agent_network_interest column
    $agent_network_interest_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'agent_network_interest'");
    if (!$agent_network_interest_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN agent_network_interest TINYINT(1) DEFAULT NULL
            COMMENT 'Is the agent open to networking/referrals'
            AFTER agent_buyer_timeline");
        if ($result === false) {
            $errors[] = 'agent_network_interest';
        }
    }

    // Add user_id column for CRM integration (links to wp_users)
    $user_id_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'user_id'");
    if (!$user_id_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN user_id BIGINT UNSIGNED DEFAULT NULL
            COMMENT 'FK to wp_users when converted to CRM client'
            AFTER agent_notes");
        if ($result === false) {
            $errors[] = 'user_id';
        } else {
            // Add index for user_id lookups
            $wpdb->query("ALTER TABLE {$attendees_table} ADD INDEX idx_user_id (user_id)");
        }
    }

    // Add priority_score column for sorting
    $priority_score_exists = $wpdb->get_var("SHOW COLUMNS FROM {$attendees_table} LIKE 'priority_score'");
    if (!$priority_score_exists) {
        $result = $wpdb->query("ALTER TABLE {$attendees_table}
            ADD COLUMN priority_score INT DEFAULT 0
            COMMENT 'Calculated lead priority: Hot(80-100), Warm(50-79), Cool(0-49)'
            AFTER user_id");
        if ($result === false) {
            $errors[] = 'priority_score';
        } else {
            // Add index for sorting by priority
            $wpdb->query("ALTER TABLE {$attendees_table} ADD INDEX idx_priority (priority_score)");
        }
    }

    // Add index on is_agent for filtering
    $is_agent_index_exists = $wpdb->get_var("SHOW INDEX FROM {$attendees_table} WHERE Key_name = 'idx_is_agent'");
    if (!$is_agent_index_exists) {
        $wpdb->query("ALTER TABLE {$attendees_table} ADD INDEX idx_is_agent (is_agent)");
    }

    // Verify columns were added
    if (!empty($errors)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.70.0] Failed to add columns: ' . implode(', ', $errors));
        }
        return false;
    }

    // Update version
    update_option('mld_db_version', '6.70.0');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.70.0] Agent detection and CRM fields added successfully');
    }

    return true;
}
