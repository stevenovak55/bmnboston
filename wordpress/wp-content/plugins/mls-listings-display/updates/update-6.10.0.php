<?php
/**
 * Update to version 6.10.x
 *
 * Version 6.10.x contains code-only changes with no database migrations:
 * - 6.10.0: Direct property selection fix (status filter bypass for specific searches)
 * - 6.10.1: Debug cleanup (backend-only detection of specific property searches)
 * - 6.10.2: Archive support for direct property selection (queries both active + archive)
 * - 6.10.3: Complete property search fix (multi-unit views show all historical sales)
 * - 6.10.4: Summary table bypass for MLS Number/Address searches
 * - 6.10.5: Database verification tool updated with 8 chatbot tables
 * - 6.10.6: CMA adjustment refinements (industry-standard property valuations)
 * - 6.10.7: Fixed wpdb::prepare() warnings in chatbot files
 *
 * No database migrations required - code-only updates.
 *
 * @package MLS_Listings_Display
 * @since 6.10.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update to version 6.10.x
 *
 * Since these versions contain only code changes (no schema modifications),
 * this function simply updates the version tracking option.
 *
 * @return bool True on success
 */
function mld_update_to_6_10_0() {
    // Log the update for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD: Running update to 6.10.x - code-only update, no database changes');
    }

    // Update the database version option
    update_option('mld_db_version', '6.10.7');

    // Log completion
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD: Update to 6.10.7 completed successfully');
    }

    return true;
}

/**
 * Get list of changes in 6.10.x versions
 *
 * @return array List of changes by version
 */
function mld_get_6_10_x_changes() {
    return array(
        '6.10.0' => 'Direct property selection fix - status filter bypass for MLS/Address searches',
        '6.10.1' => 'Debug cleanup - backend-only specific property detection',
        '6.10.2' => 'Archive support - direct property selection queries active + archive tables',
        '6.10.3' => 'Property search fix - multi-unit views show all historical sales',
        '6.10.4' => 'Summary table bypass - MLS Number/Address searches use main tables',
        '6.10.5' => 'Database verification - added 8 missing chatbot tables to verification tool',
        '6.10.6' => 'CMA adjustments - industry-standard percentage-based calculations with caps',
        '6.10.7' => 'Code quality - fixed wpdb::prepare() warnings in 8 chatbot files',
    );
}
