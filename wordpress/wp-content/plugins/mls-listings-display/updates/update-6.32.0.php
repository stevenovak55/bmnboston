<?php
/**
 * MLS Listings Display - Update 6.32.0
 *
 * Migration script for the User Type System and Agent-Client Collaboration features.
 *
 * This update:
 * 1. Creates new tables (user_types, saved_search_activity, email_preferences, email_analytics)
 * 2. Adds new columns to agent_profiles and saved_searches tables
 * 3. Migrates existing WordPress admin users to admin type
 * 4. Migrates existing agent profiles to agent type
 * 5. Initializes all other users as client type
 *
 * @package MLS_Listings_Display
 * @since 6.32.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.32.0 migration
 *
 * @return array Migration results
 */
function mld_run_update_6_32_0() {
    global $wpdb;

    $results = array(
        'success' => true,
        'messages' => array(),
        'stats' => array(
            'tables_created' => 0,
            'columns_added' => 0,
            'admins_migrated' => 0,
            'agents_migrated' => 0,
            'clients_migrated' => 0,
        ),
    );

    // Step 1: Run database schema updates
    $results['messages'][] = 'Running database schema updates...';

    if (class_exists('MLD_Saved_Search_Database')) {
        MLD_Saved_Search_Database::create_tables();
        $results['messages'][] = 'Database tables created/updated.';
    } else {
        $results['messages'][] = 'Warning: MLD_Saved_Search_Database class not found. Tables may not be created.';
    }

    // Step 2: Verify user_types table exists
    $user_types_table = $wpdb->prefix . 'mld_user_types';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$user_types_table}'") === $user_types_table;

    if (!$table_exists) {
        $results['messages'][] = 'Error: User types table was not created. Migration cannot proceed.';
        $results['success'] = false;
        return $results;
    }

    $results['messages'][] = 'User types table verified.';
    $results['stats']['tables_created']++;

    // Step 3: Migrate WordPress administrators to admin type
    $results['messages'][] = 'Migrating WordPress administrators...';

    $admin_users = get_users(array('role' => 'administrator'));
    $now = current_time('mysql');

    foreach ($admin_users as $admin) {
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$user_types_table} WHERE user_id = %d",
            $admin->ID
        ));

        if (!$exists) {
            $wpdb->insert(
                $user_types_table,
                array(
                    'user_id'    => $admin->ID,
                    'user_type'  => 'admin',
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%d', '%s', '%s', '%s')
            );
            $results['stats']['admins_migrated']++;
        }
    }

    $results['messages'][] = sprintf('Migrated %d WordPress administrators.', $results['stats']['admins_migrated']);

    // Step 4: Migrate existing agent profiles to agent type
    $results['messages'][] = 'Migrating existing agent profiles...';

    $agent_profiles_table = $wpdb->prefix . 'mld_agent_profiles';
    $agent_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$agent_profiles_table}'") === $agent_profiles_table;

    if ($agent_table_exists) {
        $agents = $wpdb->get_results("SELECT user_id FROM {$agent_profiles_table} WHERE is_active = 1");

        foreach ($agents as $agent) {
            // Check if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$user_types_table} WHERE user_id = %d",
                $agent->user_id
            ));

            if (!$exists) {
                $wpdb->insert(
                    $user_types_table,
                    array(
                        'user_id'    => $agent->user_id,
                        'user_type'  => 'agent',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ),
                    array('%d', '%s', '%s', '%s')
                );
                $results['stats']['agents_migrated']++;
            } else {
                // Update to agent if not already admin
                $current_type = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_type FROM {$user_types_table} WHERE user_id = %d",
                    $agent->user_id
                ));

                if ($current_type !== 'admin') {
                    $wpdb->update(
                        $user_types_table,
                        array('user_type' => 'agent', 'updated_at' => $now),
                        array('user_id' => $agent->user_id),
                        array('%s', '%s'),
                        array('%d')
                    );
                    $results['stats']['agents_migrated']++;
                }
            }
        }

        $results['messages'][] = sprintf('Migrated %d agent profiles.', $results['stats']['agents_migrated']);
    } else {
        $results['messages'][] = 'No agent profiles table found, skipping agent migration.';
    }

    // Step 5: Initialize remaining users as clients
    $results['messages'][] = 'Initializing remaining users as clients...';

    // Get users who don't have a type record yet (subscribers and other roles)
    $users_without_type = $wpdb->get_results(
        "SELECT u.ID
         FROM {$wpdb->users} u
         LEFT JOIN {$user_types_table} ut ON u.ID = ut.user_id
         WHERE ut.id IS NULL"
    );

    foreach ($users_without_type as $user) {
        $wpdb->insert(
            $user_types_table,
            array(
                'user_id'    => $user->ID,
                'user_type'  => 'client',
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s')
        );
        $results['stats']['clients_migrated']++;
    }

    $results['messages'][] = sprintf('Initialized %d users as clients.', $results['stats']['clients_migrated']);

    // Step 6: Verify agent_profiles table has new columns
    $results['messages'][] = 'Verifying agent_profiles table columns...';

    if ($agent_table_exists) {
        $columns = $wpdb->get_col("DESCRIBE {$agent_profiles_table}", 0);

        $new_columns = array('title', 'social_links', 'service_areas', 'snab_staff_id', 'email_signature', 'custom_greeting');
        $missing_columns = array_diff($new_columns, $columns);

        if (empty($missing_columns)) {
            $results['messages'][] = 'All new agent_profiles columns present.';
            $results['stats']['columns_added'] = count($new_columns);
        } else {
            $results['messages'][] = 'Warning: Some columns may be missing from agent_profiles: ' . implode(', ', $missing_columns);
        }
    }

    // Step 7: Verify saved_searches table has new columns
    $saved_searches_table = $wpdb->prefix . 'mld_saved_searches';
    $ss_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$saved_searches_table}'") === $saved_searches_table;

    if ($ss_table_exists) {
        $ss_columns = $wpdb->get_col("DESCRIBE {$saved_searches_table}", 0);

        $new_ss_columns = array('created_by_user_id', 'last_modified_by_user_id', 'last_modified_at', 'is_agent_recommended', 'agent_notes', 'cc_agent_on_notify');
        $missing_ss_columns = array_diff($new_ss_columns, $ss_columns);

        if (empty($missing_ss_columns)) {
            $results['messages'][] = 'All new saved_searches columns present.';
        } else {
            $results['messages'][] = 'Warning: Some columns may be missing from saved_searches: ' . implode(', ', $missing_ss_columns);
        }
    }

    // Step 8: Update database version option
    update_option('mld_saved_search_db_version', '1.3.0');
    $results['messages'][] = 'Database version updated to 1.3.0';

    // Final summary
    $total_migrated = $results['stats']['admins_migrated'] + $results['stats']['agents_migrated'] + $results['stats']['clients_migrated'];
    $results['messages'][] = sprintf(
        'Migration complete. Total users processed: %d (Admins: %d, Agents: %d, Clients: %d)',
        $total_migrated,
        $results['stats']['admins_migrated'],
        $results['stats']['agents_migrated'],
        $results['stats']['clients_migrated']
    );

    return $results;
}

/**
 * Check if this update needs to run
 *
 * @return bool
 */
function mld_needs_update_6_32_0() {
    global $wpdb;

    // Check if user_types table exists and has data
    $user_types_table = $wpdb->prefix . 'mld_user_types';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$user_types_table}'") === $user_types_table;

    if (!$table_exists) {
        return true;
    }

    // Check if any users are in the table
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$user_types_table}");

    return $count == 0;
}

/**
 * AJAX handler for running the update from admin
 */
function mld_ajax_run_update_6_32_0() {
    check_ajax_referer('mld_run_update', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $results = mld_run_update_6_32_0();

    wp_send_json_success($results);
}
add_action('wp_ajax_mld_run_update_6_32_0', 'mld_ajax_run_update_6_32_0');

/**
 * Show admin notice if update is needed
 */
function mld_admin_notice_update_6_32_0() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!mld_needs_update_6_32_0()) {
        return;
    }

    $run_url = wp_nonce_url(
        admin_url('admin.php?page=mld-settings&run_update=6.32.0'),
        'mld_run_update_6_32_0'
    );

    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong>MLS Listings Display:</strong> Database update required for version 6.32.0 (User Type System).
            <a href="<?php echo esc_url($run_url); ?>" class="button button-primary" style="margin-left: 10px;">Run Update</a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'mld_admin_notice_update_6_32_0');

/**
 * Handle manual update trigger from URL
 */
function mld_handle_manual_update_6_32_0() {
    if (!isset($_GET['run_update']) || $_GET['run_update'] !== '6.32.0') {
        return;
    }

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mld_run_update_6_32_0')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $results = mld_run_update_6_32_0();

    // Store results for display
    set_transient('mld_update_6_32_0_results', $results, 60);

    // Redirect to remove URL parameters
    wp_redirect(admin_url('admin.php?page=mld-settings&update_complete=6.32.0'));
    exit;
}
add_action('admin_init', 'mld_handle_manual_update_6_32_0');

/**
 * Show update results notice
 */
function mld_show_update_results_6_32_0() {
    if (!isset($_GET['update_complete']) || $_GET['update_complete'] !== '6.32.0') {
        return;
    }

    $results = get_transient('mld_update_6_32_0_results');
    if (!$results) {
        return;
    }

    delete_transient('mld_update_6_32_0_results');

    $class = $results['success'] ? 'notice-success' : 'notice-error';

    ?>
    <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
        <p><strong>MLS Listings Display 6.32.0 Update Results:</strong></p>
        <ul style="margin-left: 20px; list-style: disc;">
            <?php foreach ($results['messages'] as $message) : ?>
                <li><?php echo esc_html($message); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}
add_action('admin_notices', 'mld_show_update_results_6_32_0');
