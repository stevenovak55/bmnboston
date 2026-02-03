<?php
/**
 * MLS Listings Display - Referral Manager
 *
 * Handles agent referral codes, default agent assignment, and referral tracking.
 *
 * @package MLS_Listings_Display
 * @subpackage Referrals
 * @since 6.52.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Referral_Manager {

    /**
     * Option key for default agent
     */
    const DEFAULT_AGENT_OPTION = 'mld_default_agent_user_id';

    /**
     * Default referral code length
     */
    const DEFAULT_CODE_LENGTH = 8;

    /**
     * Referral signup sources
     */
    const SOURCE_ORGANIC = 'organic';
    const SOURCE_REFERRAL = 'referral_link';
    const SOURCE_AGENT_CREATED = 'agent_created';

    /**
     * Get the referral codes table name
     */
    public static function get_codes_table() {
        global $wpdb;
        return $wpdb->prefix . 'mld_agent_referral_codes';
    }

    /**
     * Get the referral signups table name
     */
    public static function get_signups_table() {
        global $wpdb;
        return $wpdb->prefix . 'mld_referral_signups';
    }

    /**
     * Generate a unique referral code for an agent
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @param string|null $custom_code Optional custom code (will be validated)
     * @return array|WP_Error Code data on success, WP_Error on failure
     */
    public static function generate_referral_code($agent_user_id, $custom_code = null) {
        global $wpdb;

        // Verify user is an agent
        if (!self::is_valid_agent($agent_user_id)) {
            return new WP_Error('not_agent', 'User is not a valid agent');
        }

        // Check if agent already has an active code
        $existing = self::get_agent_referral_code($agent_user_id);
        if ($existing && !is_wp_error($existing)) {
            return new WP_Error('code_exists', 'Agent already has an active referral code', $existing);
        }

        // Generate or validate code
        if (!empty($custom_code)) {
            $code = self::sanitize_code($custom_code);

            // Check length (3-20 characters)
            if (strlen($code) < 3 || strlen($code) > 20) {
                return new WP_Error('invalid_code_length', 'Code must be between 3 and 20 characters');
            }

            // Check if custom code already exists
            if (self::code_exists($code)) {
                return new WP_Error('code_taken', 'This referral code is already in use');
            }
        } else {
            // Auto-generate unique code
            $code = self::generate_unique_code($agent_user_id);
        }

        // Insert the code
        $result = $wpdb->insert(
            self::get_codes_table(),
            array(
                'agent_user_id' => $agent_user_id,
                'referral_code' => $code,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create referral code');
        }

        return array(
            'id' => $wpdb->insert_id,
            'referral_code' => $code,
            'referral_url' => self::get_referral_url($code),
            'agent_user_id' => $agent_user_id,
        );
    }

    /**
     * Get an agent's active referral code
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @return array|null|WP_Error Code data or null if none exists
     */
    public static function get_agent_referral_code($agent_user_id) {
        global $wpdb;

        $code = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_codes_table() . "
             WHERE agent_user_id = %d AND is_active = 1
             ORDER BY created_at DESC LIMIT 1",
            $agent_user_id
        ), ARRAY_A);

        if (!$code) {
            return null;
        }

        $code['referral_url'] = self::get_referral_url($code['referral_code']);

        return $code;
    }

    /**
     * Get or create an agent's referral code
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @return array|WP_Error Code data
     */
    public static function get_or_create_agent_code($agent_user_id) {
        $existing = self::get_agent_referral_code($agent_user_id);

        if ($existing) {
            return $existing;
        }

        return self::generate_referral_code($agent_user_id);
    }

    /**
     * Update an agent's referral code (regenerate or set custom)
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @param string|null $new_code New custom code, or null to auto-generate
     * @return array|WP_Error Updated code data
     */
    public static function update_referral_code($agent_user_id, $new_code = null) {
        global $wpdb;

        // Deactivate existing codes
        $wpdb->update(
            self::get_codes_table(),
            array('is_active' => 0, 'updated_at' => current_time('mysql')),
            array('agent_user_id' => $agent_user_id),
            array('%d', '%s'),
            array('%d')
        );

        // Generate new code
        return self::generate_referral_code($agent_user_id, $new_code);
    }

    /**
     * Regenerate referral code (deactivate old one and create new auto-generated code)
     *
     * @param int $agent_user_id WordPress user ID of the agent
     * @return array|WP_Error New code data on success, WP_Error on failure
     */
    public static function regenerate_referral_code($agent_user_id) {
        return self::update_referral_code($agent_user_id, null);
    }

    /**
     * Validate a referral code
     *
     * @param string $code The referral code to validate
     * @return bool True if valid and active
     */
    public static function validate_referral_code($code) {
        global $wpdb;

        $code = self::sanitize_code($code);
        if (empty($code)) {
            return false;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_codes_table() . "
             WHERE referral_code = %s AND is_active = 1",
            $code
        ));

        return (int) $exists > 0;
    }

    /**
     * Get agent data by referral code
     *
     * @param string $code The referral code
     * @return array|null Agent data or null if not found
     */
    public static function get_agent_by_code($code) {
        global $wpdb;

        $code = self::sanitize_code($code);
        if (empty($code)) {
            return null;
        }

        $code_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_codes_table() . "
             WHERE referral_code = %s AND is_active = 1",
            $code
        ), ARRAY_A);

        if (!$code_data) {
            return null;
        }

        // Get agent profile
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_agent_for_api($code_data['agent_user_id']);
            if ($agent) {
                $agent['referral_code'] = $code;
                return $agent;
            }
        }

        // Fallback to basic user data
        $user = get_userdata($code_data['agent_user_id']);
        if (!$user) {
            return null;
        }

        return array(
            'id' => $user->ID,
            'user_id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'referral_code' => $code,
        );
    }

    /**
     * Get default agent user ID
     *
     * @return int|null Agent user ID or null if not set
     */
    public static function get_default_agent() {
        $agent_id = get_option(self::DEFAULT_AGENT_OPTION, 0);

        if (empty($agent_id)) {
            return null;
        }

        // Verify agent still exists and is active
        if (!self::is_valid_agent($agent_id)) {
            // Clear invalid default
            delete_option(self::DEFAULT_AGENT_OPTION);
            return null;
        }

        return (int) $agent_id;
    }

    /**
     * Set default agent
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @return bool True on success
     */
    public static function set_default_agent($agent_user_id) {
        if (!self::is_valid_agent($agent_user_id)) {
            return false;
        }

        return update_option(self::DEFAULT_AGENT_OPTION, $agent_user_id);
    }

    /**
     * Clear default agent
     *
     * @return bool True on success
     */
    public static function clear_default_agent() {
        return delete_option(self::DEFAULT_AGENT_OPTION);
    }

    /**
     * Assign client to agent on registration
     *
     * @param int $client_user_id The new client's WordPress user ID
     * @param string|null $referral_code Referral code used (if any)
     * @param string $source Signup source (organic, referral_link, agent_created)
     * @param string $platform Platform (web, ios, admin)
     * @return array|WP_Error Assignment result
     */
    public static function assign_client_on_register($client_user_id, $referral_code = null, $source = null, $platform = 'web') {
        global $wpdb;

        $agent_user_id = null;
        $used_code = null;

        // Determine assignment source
        if (!empty($referral_code)) {
            $referral_code = self::sanitize_code($referral_code);

            if (self::validate_referral_code($referral_code)) {
                $agent_data = self::get_agent_by_code($referral_code);
                if ($agent_data) {
                    $agent_user_id = $agent_data['user_id'] ?? $agent_data['id'];
                    $used_code = $referral_code;
                    $source = self::SOURCE_REFERRAL;
                }
            }
        }

        // Fall back to default agent for organic signups
        if (empty($agent_user_id) && $source !== self::SOURCE_AGENT_CREATED) {
            $agent_user_id = self::get_default_agent();
            $source = self::SOURCE_ORGANIC;
        }

        // Log the referral signup (even if no agent assigned)
        self::log_referral_signup($client_user_id, $agent_user_id, $used_code, $source, $platform);

        // If we have an agent, create the relationship
        if (!empty($agent_user_id)) {
            if (class_exists('MLD_Agent_Client_Manager')) {
                $result = MLD_Agent_Client_Manager::assign_agent_to_client($agent_user_id, $client_user_id, array(
                    'notes' => $source === self::SOURCE_REFERRAL
                        ? sprintf('Signed up via referral link (code: %s)', $used_code)
                        : 'Automatically assigned as default agent',
                ));

                if (is_wp_error($result)) {
                    return $result;
                }
            }

            return array(
                'success' => true,
                'agent_user_id' => $agent_user_id,
                'source' => $source,
                'referral_code' => $used_code,
            );
        }

        return array(
            'success' => true,
            'agent_user_id' => null,
            'source' => $source,
            'message' => 'No default agent configured',
        );
    }

    /**
     * Log a referral signup
     *
     * @param int $client_user_id The client's WordPress user ID
     * @param int|null $agent_user_id The assigned agent's user ID (if any)
     * @param string|null $referral_code The referral code used (if any)
     * @param string $source Signup source
     * @param string $platform Platform
     */
    public static function log_referral_signup($client_user_id, $agent_user_id, $referral_code, $source, $platform) {
        global $wpdb;

        $wpdb->insert(
            self::get_signups_table(),
            array(
                'client_user_id' => $client_user_id,
                'agent_user_id' => $agent_user_id ?: 0,
                'referral_code' => $referral_code,
                'signup_source' => $source ?: self::SOURCE_ORGANIC,
                'platform' => $platform,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get referral statistics for an agent
     *
     * @param int $agent_user_id The agent's WordPress user ID
     * @return array Statistics
     */
    public static function get_agent_referral_stats($agent_user_id) {
        global $wpdb;

        $table = self::get_signups_table();

        // Total signups via referral
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE agent_user_id = %d AND signup_source = %s",
            $agent_user_id, self::SOURCE_REFERRAL
        ));

        // This month's signups
        $this_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE agent_user_id = %d
             AND signup_source = %s
             AND YEAR(created_at) = YEAR(CURRENT_DATE())
             AND MONTH(created_at) = MONTH(CURRENT_DATE())",
            $agent_user_id, self::SOURCE_REFERRAL
        ));

        // Last signup date
        $last_signup = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM $table
             WHERE agent_user_id = %d AND signup_source = %s
             ORDER BY created_at DESC LIMIT 1",
            $agent_user_id, self::SOURCE_REFERRAL
        ));

        // Breakdown by platform
        $by_platform = $wpdb->get_results($wpdb->prepare(
            "SELECT platform, COUNT(*) as count FROM $table
             WHERE agent_user_id = %d AND signup_source = %s
             GROUP BY platform",
            $agent_user_id, self::SOURCE_REFERRAL
        ), ARRAY_A);

        $platform_counts = array('web' => 0, 'ios' => 0, 'admin' => 0);
        foreach ($by_platform as $row) {
            $platform_counts[$row['platform']] = (int) $row['count'];
        }

        return array(
            'total_signups' => $total,
            'this_month' => $this_month,
            'last_signup' => $last_signup,
            'by_platform' => $platform_counts,
        );
    }

    /**
     * Get all referral statistics (admin view)
     *
     * @return array Global statistics
     */
    public static function get_all_referral_stats() {
        global $wpdb;

        $signups_table = self::get_signups_table();
        $codes_table = self::get_codes_table();

        // Total signups by source
        $by_source = $wpdb->get_results(
            "SELECT signup_source, COUNT(*) as count FROM $signups_table GROUP BY signup_source",
            ARRAY_A
        );

        $source_counts = array(
            self::SOURCE_ORGANIC => 0,
            self::SOURCE_REFERRAL => 0,
            self::SOURCE_AGENT_CREATED => 0,
        );
        foreach ($by_source as $row) {
            $source_counts[$row['signup_source']] = (int) $row['count'];
        }

        // Active referral codes count
        $active_codes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $codes_table WHERE is_active = 1"
        );

        // Top referring agents
        $top_agents = $wpdb->get_results($wpdb->prepare(
            "SELECT agent_user_id, COUNT(*) as signup_count
             FROM $signups_table
             WHERE signup_source = %s AND agent_user_id > 0
             GROUP BY agent_user_id
             ORDER BY signup_count DESC
             LIMIT 10",
            self::SOURCE_REFERRAL
        ), ARRAY_A);

        return array(
            'by_source' => $source_counts,
            'active_codes' => $active_codes,
            'top_agents' => $top_agents,
            'default_agent_id' => self::get_default_agent(),
        );
    }

    /**
     * Build the full referral URL for a code
     *
     * @param string $code The referral code
     * @return string The full URL
     */
    public static function get_referral_url($code) {
        return home_url('/signup/?ref=' . urlencode($code));
    }

    /**
     * Check if a code already exists
     *
     * @param string $code The code to check
     * @return bool True if exists
     */
    private static function code_exists($code) {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_codes_table() . " WHERE referral_code = %s",
            $code
        ));

        return (int) $exists > 0;
    }

    /**
     * Generate a unique referral code
     *
     * @param int $agent_user_id Used to seed the code generation
     * @return string Unique code
     */
    private static function generate_unique_code($agent_user_id) {
        $attempts = 0;
        $max_attempts = 10;

        // Try to generate a code based on user name first
        $user = get_userdata($agent_user_id);
        if ($user) {
            $name_base = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $user->display_name));
            $name_base = substr($name_base, 0, 6);

            if (strlen($name_base) >= 3) {
                $code = $name_base . rand(100, 999);
                if (!self::code_exists($code)) {
                    return $code;
                }
            }
        }

        // Fall back to random generation
        while ($attempts < $max_attempts) {
            $code = self::generate_random_code();
            if (!self::code_exists($code)) {
                return $code;
            }
            $attempts++;
        }

        // Last resort: use timestamp
        return 'REF' . time() . rand(10, 99);
    }

    /**
     * Generate a random alphanumeric code
     *
     * @param int $length Code length
     * @return string Random code
     */
    private static function generate_random_code($length = self::DEFAULT_CODE_LENGTH) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Exclude confusing chars (I, O, 0, 1)
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $code;
    }

    /**
     * Sanitize a referral code
     *
     * @param string $code The code to sanitize
     * @return string Sanitized code
     */
    private static function sanitize_code($code) {
        // Remove whitespace and convert to uppercase
        $code = strtoupper(trim($code));

        // Only allow alphanumeric characters
        $code = preg_replace('/[^A-Z0-9]/', '', $code);

        return $code;
    }

    /**
     * Check if a user is a valid, active agent
     *
     * @param int $user_id The WordPress user ID
     * @return bool True if valid agent
     */
    private static function is_valid_agent($user_id) {
        if (empty($user_id)) {
            return false;
        }

        // Check if user exists
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Check user type if available
        if (class_exists('MLD_User_Type_Manager')) {
            $type = MLD_User_Type_Manager::get_user_type($user_id);
            if (!in_array($type, array('agent', 'admin'))) {
                return false;
            }
        }

        // Check if agent profile exists and is active
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_agent_by_user_id($user_id);
            if (!$agent || empty($agent->is_active)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle agent deactivation - clear default if this was the default agent
     *
     * @param int $agent_user_id The agent being deactivated
     */
    public static function on_agent_deactivated($agent_user_id) {
        $default = self::get_default_agent();

        if ($default === (int) $agent_user_id) {
            self::clear_default_agent();

            // Notify admin
            $admin_email = get_option('admin_email');
            $agent_name = get_userdata($agent_user_id)->display_name ?? 'Unknown';

            wp_mail(
                $admin_email,
                '[BMN Boston] Default Agent Deactivated',
                sprintf(
                    "The default agent (%s) has been deactivated.\n\n" .
                    "Please set a new default agent in the WordPress admin:\n" .
                    "MLS Display > Agents\n\n" .
                    "Until a new default is set, organic signups will not be automatically assigned to an agent.",
                    $agent_name
                )
            );
        }

        // Deactivate their referral codes
        global $wpdb;
        $wpdb->update(
            self::get_codes_table(),
            array('is_active' => 0, 'updated_at' => current_time('mysql')),
            array('agent_user_id' => $agent_user_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    // =========================================================================
    // DATA CLEANUP UTILITIES (Added v6.74.8)
    // =========================================================================

    /**
     * Clean up orphaned referral data
     *
     * Identifies and optionally removes:
     * 1. Referral codes for agents that no longer exist
     * 2. Referral signups for clients that no longer exist
     * 3. Referral signups referencing agents that no longer exist
     *
     * @param bool $dry_run If true, only report what would be cleaned
     * @return array Results of the cleanup operation
     */
    public static function cleanup_orphaned_referrals($dry_run = true) {
        global $wpdb;

        $codes_table = self::get_codes_table();
        $signups_table = self::get_signups_table();

        $results = [
            'dry_run' => $dry_run,
            'orphaned_codes' => [],
            'orphaned_signups_no_client' => [],
            'orphaned_signups_no_agent' => [],
            'total_cleaned' => 0,
        ];

        // 1. Find referral codes where agent no longer exists
        $orphaned_codes = $wpdb->get_results(
            "SELECT rc.id, rc.agent_user_id, rc.referral_code, rc.is_active
             FROM {$codes_table} rc
             LEFT JOIN {$wpdb->users} u ON rc.agent_user_id = u.ID
             WHERE u.ID IS NULL"
        );

        foreach ($orphaned_codes as $code) {
            $results['orphaned_codes'][] = [
                'id' => $code->id,
                'agent_user_id' => $code->agent_user_id,
                'referral_code' => $code->referral_code,
                'is_active' => $code->is_active,
            ];
        }

        // 2. Find referral signups where client no longer exists
        $orphaned_clients = $wpdb->get_results(
            "SELECT rs.id, rs.client_user_id, rs.agent_user_id, rs.referral_code, rs.signup_source
             FROM {$signups_table} rs
             LEFT JOIN {$wpdb->users} u ON rs.client_user_id = u.ID
             WHERE u.ID IS NULL"
        );

        foreach ($orphaned_clients as $signup) {
            $results['orphaned_signups_no_client'][] = [
                'id' => $signup->id,
                'client_user_id' => $signup->client_user_id,
                'agent_user_id' => $signup->agent_user_id,
                'referral_code' => $signup->referral_code,
                'signup_source' => $signup->signup_source,
            ];
        }

        // 3. Find referral signups where agent no longer exists (but client does)
        $orphaned_agents = $wpdb->get_results(
            "SELECT rs.id, rs.client_user_id, rs.agent_user_id, rs.referral_code, rs.signup_source,
                    uc.display_name as client_name
             FROM {$signups_table} rs
             INNER JOIN {$wpdb->users} uc ON rs.client_user_id = uc.ID
             LEFT JOIN {$wpdb->users} ua ON rs.agent_user_id = ua.ID
             WHERE rs.agent_user_id > 0 AND ua.ID IS NULL"
        );

        foreach ($orphaned_agents as $signup) {
            $results['orphaned_signups_no_agent'][] = [
                'id' => $signup->id,
                'client_user_id' => $signup->client_user_id,
                'client_name' => $signup->client_name,
                'agent_user_id' => $signup->agent_user_id,
                'referral_code' => $signup->referral_code,
            ];
        }

        // Perform cleanup if not a dry run
        if (!$dry_run) {
            // Delete orphaned referral codes
            if (!empty($results['orphaned_codes'])) {
                $orphan_ids = array_column($results['orphaned_codes'], 'id');
                $placeholders = implode(',', array_fill(0, count($orphan_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$codes_table} WHERE id IN ({$placeholders})",
                    $orphan_ids
                ));
                $results['total_cleaned'] += count($orphan_ids);
            }

            // Delete orphaned signups (client deleted)
            if (!empty($results['orphaned_signups_no_client'])) {
                $orphan_ids = array_column($results['orphaned_signups_no_client'], 'id');
                $placeholders = implode(',', array_fill(0, count($orphan_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$signups_table} WHERE id IN ({$placeholders})",
                    $orphan_ids
                ));
                $results['total_cleaned'] += count($orphan_ids);
            }

            // For signups where agent is deleted but client exists,
            // set agent_user_id to 0 (unassigned) instead of deleting
            if (!empty($results['orphaned_signups_no_agent'])) {
                $orphan_ids = array_column($results['orphaned_signups_no_agent'], 'id');
                $placeholders = implode(',', array_fill(0, count($orphan_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$signups_table} SET agent_user_id = 0 WHERE id IN ({$placeholders})",
                    $orphan_ids
                ));
                $results['total_cleaned'] += count($orphan_ids);
            }
        }

        $results['summary'] = sprintf(
            'Found: %d orphaned codes, %d signups with deleted clients, %d signups with deleted agents. %s',
            count($results['orphaned_codes']),
            count($results['orphaned_signups_no_client']),
            count($results['orphaned_signups_no_agent']),
            $dry_run ? 'Dry run - no changes made.' : "Cleaned {$results['total_cleaned']} records."
        );

        return $results;
    }

    /**
     * Get referral system statistics for debugging
     *
     * @return array Statistics about the referral system
     */
    public static function get_referral_stats() {
        global $wpdb;

        $codes_table = self::get_codes_table();
        $signups_table = self::get_signups_table();

        $stats = [];

        // Referral codes stats
        $stats['codes'] = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$codes_table}"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$codes_table} WHERE is_active = 1"),
            'inactive' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$codes_table} WHERE is_active = 0"),
        ];

        // Signups by source
        $source_counts = $wpdb->get_results(
            "SELECT signup_source, COUNT(*) as count FROM {$signups_table} GROUP BY signup_source"
        );
        $stats['signups_by_source'] = [];
        foreach ($source_counts as $row) {
            $stats['signups_by_source'][$row->signup_source] = (int) $row->count;
        }

        // Signups by platform
        $platform_counts = $wpdb->get_results(
            "SELECT platform, COUNT(*) as count FROM {$signups_table} GROUP BY platform"
        );
        $stats['signups_by_platform'] = [];
        foreach ($platform_counts as $row) {
            $stats['signups_by_platform'][$row->platform] = (int) $row->count;
        }

        // Orphaned record counts
        $stats['orphaned_codes'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$codes_table} rc
             LEFT JOIN {$wpdb->users} u ON rc.agent_user_id = u.ID
             WHERE u.ID IS NULL"
        );

        $stats['orphaned_signups_no_client'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$signups_table} rs
             LEFT JOIN {$wpdb->users} u ON rs.client_user_id = u.ID
             WHERE u.ID IS NULL"
        );

        $stats['orphaned_signups_no_agent'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$signups_table} rs
             INNER JOIN {$wpdb->users} uc ON rs.client_user_id = uc.ID
             LEFT JOIN {$wpdb->users} ua ON rs.agent_user_id = ua.ID
             WHERE rs.agent_user_id > 0 AND ua.ID IS NULL"
        );

        // Default agent
        $stats['default_agent_id'] = self::get_default_agent();

        return $stats;
    }
}
