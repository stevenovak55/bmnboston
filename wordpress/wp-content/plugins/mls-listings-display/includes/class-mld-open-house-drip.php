<?php
/**
 * Open House Email Drip Sequence Engine
 *
 * Manages automated follow-up email sequences for open house attendees.
 * Enrolls eligible attendees (unrepresented buyers with consent) when an
 * open house ends, then sends a timed series of emails via WP-Cron.
 *
 * Sequence:
 *   1. Thank You (1 day after)   - Property recap, agent intro
 *   2. Similar Properties (3 days) - Nearby listings they might like
 *   3. Buyer Resources (7 days)   - Financing tips, market insights
 *   4. Check-in (14 days)         - New listings in the area
 *   5. Final Touch (30 days)      - Market update, invitation to reconnect
 *
 * @package MLS_Listings_Display
 * @since 6.77.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Open_House_Drip {

    /**
     * Cron hook for processing drip queue
     */
    const CRON_HOOK = 'mld_open_house_drip_process';

    /**
     * Database version for drip tables
     */
    const DB_VERSION = '1.0.0';

    /**
     * Option key for tracking DB version
     */
    const DB_VERSION_OPTION = 'mld_open_house_drip_db_version';

    /**
     * Drip sequence definition: step => delay in days
     */
    const SEQUENCE_STEPS = array(
        1 => 1,   // Thank You - 1 day after
        2 => 3,   // Similar Properties - 3 days after
        3 => 7,   // Buyer Resources - 7 days after
        4 => 14,  // Check-in - 14 days after
        5 => 30,  // Final Touch - 30 days after
    );

    /**
     * Step labels for display
     */
    const STEP_LABELS = array(
        1 => 'Thank You',
        2 => 'Similar Properties',
        3 => 'Buyer Resources',
        4 => 'Check-in',
        5 => 'Final Touch',
    );

    /**
     * Initialize the drip engine
     */
    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'process_drip_queue'));
        add_action('admin_init', array(__CLASS__, 'maybe_schedule_cron'), 20);
        add_action('init', array(__CLASS__, 'maybe_create_tables'));
    }

    // ------------------------------------------------------------------
    //  DATABASE
    // ------------------------------------------------------------------

    /**
     * Create tables if needed
     */
    public static function maybe_create_tables() {
        $current = get_option(self::DB_VERSION_OPTION, '0');
        if (version_compare($current, self::DB_VERSION, '<')) {
            self::create_tables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Create drip sequence tables
     */
    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Enrollments: tracks each attendee's drip progress
        $enrollments_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';
        $sql_enrollments = "CREATE TABLE IF NOT EXISTS {$enrollments_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            open_house_id BIGINT UNSIGNED NOT NULL,
            attendee_id BIGINT UNSIGNED NOT NULL,
            agent_user_id BIGINT UNSIGNED NOT NULL,
            attendee_email VARCHAR(255) NOT NULL,
            attendee_first_name VARCHAR(100) DEFAULT '',
            attendee_last_name VARCHAR(100) DEFAULT '',
            current_step TINYINT UNSIGNED DEFAULT 0,
            total_steps TINYINT UNSIGNED DEFAULT 5,
            status ENUM('active','paused','completed','cancelled') DEFAULT 'active',
            enrolled_at DATETIME NOT NULL,
            next_send_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            property_address VARCHAR(255) DEFAULT '',
            property_city VARCHAR(100) DEFAULT '',
            listing_id VARCHAR(20) DEFAULT '',
            listing_key VARCHAR(128) DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY unique_enrollment (open_house_id, attendee_id),
            KEY idx_status_next (status, next_send_at),
            KEY idx_agent (agent_user_id),
            KEY idx_attendee_email (attendee_email)
        ) {$charset};";

        // Send log: tracks each individual email sent
        $log_table = $wpdb->prefix . 'mld_open_house_drip_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS {$log_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            enrollment_id BIGINT UNSIGNED NOT NULL,
            step TINYINT UNSIGNED NOT NULL,
            step_label VARCHAR(50) DEFAULT '',
            sent_at DATETIME NOT NULL,
            status ENUM('sent','failed') DEFAULT 'sent',
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_enrollment (enrollment_id),
            KEY idx_sent_at (sent_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_enrollments);
        dbDelta($sql_log);
    }

    // ------------------------------------------------------------------
    //  CRON SCHEDULING
    // ------------------------------------------------------------------

    /**
     * Schedule the cron if not already
     */
    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Open House Drip: Scheduled hourly cron');
            }
        }
    }

    /**
     * Unschedule cron
     */
    public static function unschedule_cron() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // ------------------------------------------------------------------
    //  ENROLLMENT
    // ------------------------------------------------------------------

    /**
     * Enroll eligible attendees from a completed open house
     *
     * Eligible = non-agent + consent_to_follow_up + has email + not already enrolled
     *
     * @param int $open_house_id  The open house that just ended
     * @return int Number of attendees enrolled
     */
    public static function enroll_open_house_attendees($open_house_id) {
        global $wpdb;

        $oh_table = $wpdb->prefix . 'mld_open_houses';
        $att_table = $wpdb->prefix . 'mld_open_house_attendees';
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        // Get open house info
        $open_house = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$oh_table} WHERE id = %d",
            $open_house_id
        ));

        if (!$open_house) {
            return 0;
        }

        // Get eligible attendees: non-agents with consent and email
        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$att_table} a
             LEFT JOIN {$enroll_table} e ON e.open_house_id = a.open_house_id AND e.attendee_id = a.id
             WHERE a.open_house_id = %d
               AND (a.is_agent IS NULL OR a.is_agent = 0)
               AND a.consent_to_follow_up = 1
               AND a.email IS NOT NULL AND a.email != ''
               AND e.id IS NULL",
            $open_house_id
        ));

        if (empty($attendees)) {
            return 0;
        }

        $now = current_time('mysql');
        $enrolled = 0;

        // Calculate first send time: 1 day from now
        $first_send = new DateTime($now, wp_timezone());
        $first_send->modify('+' . self::SEQUENCE_STEPS[1] . ' days');
        // Send at 10 AM local time for better open rates
        $first_send->setTime(10, 0, 0);

        foreach ($attendees as $attendee) {
            $result = $wpdb->insert($enroll_table, array(
                'open_house_id'      => $open_house_id,
                'attendee_id'        => (int) $attendee->id,
                'agent_user_id'      => (int) $open_house->agent_user_id,
                'attendee_email'     => sanitize_email($attendee->email),
                'attendee_first_name' => sanitize_text_field($attendee->first_name ?? ''),
                'attendee_last_name' => sanitize_text_field($attendee->last_name ?? ''),
                'current_step'       => 0,
                'total_steps'        => count(self::SEQUENCE_STEPS),
                'status'             => 'active',
                'enrolled_at'        => $now,
                'next_send_at'       => $first_send->format('Y-m-d H:i:s'),
                'property_address'   => trim(($open_house->property_address ?? '') . ', ' . ($open_house->property_city ?? ''), ', '),
                'property_city'      => $open_house->property_city ?? '',
                'listing_id'         => $open_house->listing_id ?? '',
                'listing_key'        => $open_house->listing_key ?? '',
            ), array(
                '%d', '%d', '%d', '%s', '%s', '%s',
                '%d', '%d', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
            ));

            if ($result) {
                $enrolled++;
            }
        }

        if ($enrolled > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Open House Drip: Enrolled {$enrolled} attendees from OH #{$open_house_id}");
        }

        return $enrolled;
    }

    // ------------------------------------------------------------------
    //  CRON PROCESSOR
    // ------------------------------------------------------------------

    /**
     * Process the drip queue - called by WP-Cron hourly
     *
     * Finds active enrollments whose next_send_at has passed,
     * sends the next email in their sequence, and advances the step.
     */
    public static function process_drip_queue() {
        global $wpdb;

        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';
        $now = current_time('mysql');

        // Get enrollments due for their next email (batch of 50)
        $due = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$enroll_table}
             WHERE status = 'active'
               AND next_send_at IS NOT NULL
               AND next_send_at <= %s
             ORDER BY next_send_at ASC
             LIMIT 50",
            $now
        ));

        if (empty($due)) {
            return;
        }

        $processed = 0;
        $failed = 0;

        foreach ($due as $enrollment) {
            $next_step = (int) $enrollment->current_step + 1;

            // Validate step exists
            if (!isset(self::SEQUENCE_STEPS[$next_step])) {
                // Sequence complete
                self::complete_enrollment($enrollment->id);
                continue;
            }

            // Send the email
            $sent = self::send_drip_email($enrollment, $next_step);

            // Log the send
            self::log_send($enrollment->id, $next_step, $sent);

            if ($sent) {
                // Advance to next step
                self::advance_enrollment($enrollment->id, $next_step);
                $processed++;
            } else {
                $failed++;
                // Don't advance - will retry on next cron run
                // But bump next_send_at by 1 hour to avoid hammering
                $retry = new DateTime($now, wp_timezone());
                $retry->modify('+1 hour');
                $wpdb->update($enroll_table,
                    array('next_send_at' => $retry->format('Y-m-d H:i:s')),
                    array('id' => $enrollment->id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Open House Drip: Processed {$processed}, failed {$failed}");
        }
    }

    /**
     * Advance enrollment to next step or complete
     */
    private static function advance_enrollment($enrollment_id, $completed_step) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        $next_step = $completed_step + 1;

        if (!isset(self::SEQUENCE_STEPS[$next_step])) {
            // All steps done
            self::complete_enrollment($enrollment_id);
            return;
        }

        // Calculate next send time
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT enrolled_at FROM {$enroll_table} WHERE id = %d",
            $enrollment_id
        ));

        $next_send = new DateTime($enrollment->enrolled_at, wp_timezone());
        $next_send->modify('+' . self::SEQUENCE_STEPS[$next_step] . ' days');
        $next_send->setTime(10, 0, 0); // 10 AM local

        $wpdb->update($enroll_table,
            array(
                'current_step' => $completed_step,
                'next_send_at' => $next_send->format('Y-m-d H:i:s'),
            ),
            array('id' => $enrollment_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    /**
     * Mark enrollment as completed
     */
    private static function complete_enrollment($enrollment_id) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        $wpdb->update($enroll_table,
            array(
                'status'       => 'completed',
                'current_step' => count(self::SEQUENCE_STEPS),
                'next_send_at' => null,
                'completed_at' => current_time('mysql'),
            ),
            array('id' => $enrollment_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Log a drip send
     */
    private static function log_send($enrollment_id, $step, $success, $error = '') {
        global $wpdb;
        $log_table = $wpdb->prefix . 'mld_open_house_drip_log';

        $wpdb->insert($log_table, array(
            'enrollment_id' => $enrollment_id,
            'step'          => $step,
            'step_label'    => self::STEP_LABELS[$step] ?? "Step {$step}",
            'sent_at'       => current_time('mysql'),
            'status'        => $success ? 'sent' : 'failed',
            'error_message' => $success ? null : ($error ?: 'wp_mail failed'),
        ), array('%d', '%d', '%s', '%s', '%s', '%s'));
    }

    // ------------------------------------------------------------------
    //  ENROLLMENT MANAGEMENT (for REST API)
    // ------------------------------------------------------------------

    /**
     * Get drip enrollments for an agent
     *
     * @param int    $agent_user_id  Agent user ID
     * @param string $status         Filter by status (optional)
     * @return array
     */
    public static function get_agent_enrollments($agent_user_id, $status = null) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        $where = $wpdb->prepare("agent_user_id = %d", $agent_user_id);
        if ($status) {
            $where .= $wpdb->prepare(" AND status = %s", $status);
        }

        $enrollments = $wpdb->get_results(
            "SELECT * FROM {$enroll_table} WHERE {$where} ORDER BY enrolled_at DESC"
        );

        return array_map(array(__CLASS__, 'format_enrollment'), $enrollments);
    }

    /**
     * Get drip status for a specific open house
     *
     * @param int $open_house_id
     * @param int $agent_user_id
     * @return array
     */
    public static function get_open_house_drip_status($open_house_id, $agent_user_id) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';
        $log_table = $wpdb->prefix . 'mld_open_house_drip_log';

        $enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$enroll_table}
             WHERE open_house_id = %d AND agent_user_id = %d
             ORDER BY attendee_last_name ASC, attendee_first_name ASC",
            $open_house_id, $agent_user_id
        ));

        $result = array();
        foreach ($enrollments as $enrollment) {
            $formatted = self::format_enrollment($enrollment);

            // Add send history
            $sends = $wpdb->get_results($wpdb->prepare(
                "SELECT step, step_label, sent_at, status FROM {$log_table}
                 WHERE enrollment_id = %d ORDER BY step ASC",
                $enrollment->id
            ));

            $formatted['send_history'] = array_map(function($s) {
                return array(
                    'step'       => (int) $s->step,
                    'step_label' => $s->step_label,
                    'sent_at'    => $s->sent_at,
                    'status'     => $s->status,
                );
            }, $sends);

            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * Pause a drip enrollment
     *
     * @param int $enrollment_id
     * @param int $agent_user_id  For ownership verification
     * @return bool
     */
    public static function pause_enrollment($enrollment_id, $agent_user_id) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        return (bool) $wpdb->update($enroll_table,
            array('status' => 'paused'),
            array('id' => $enrollment_id, 'agent_user_id' => $agent_user_id, 'status' => 'active'),
            array('%s'),
            array('%d', '%d', '%s')
        );
    }

    /**
     * Resume a paused drip enrollment
     *
     * @param int $enrollment_id
     * @param int $agent_user_id  For ownership verification
     * @return bool
     */
    public static function resume_enrollment($enrollment_id, $agent_user_id) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        // Get current enrollment to recalculate next send
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$enroll_table}
             WHERE id = %d AND agent_user_id = %d AND status = 'paused'",
            $enrollment_id, $agent_user_id
        ));

        if (!$enrollment) {
            return false;
        }

        $next_step = (int) $enrollment->current_step + 1;
        if (!isset(self::SEQUENCE_STEPS[$next_step])) {
            self::complete_enrollment($enrollment_id);
            return true;
        }

        // Schedule next send 1 day from now (give a buffer after unpause)
        $next_send = new DateTime('now', wp_timezone());
        $next_send->modify('+1 day');
        $next_send->setTime(10, 0, 0);

        return (bool) $wpdb->update($enroll_table,
            array(
                'status'       => 'active',
                'next_send_at' => $next_send->format('Y-m-d H:i:s'),
            ),
            array('id' => $enrollment_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * Cancel a drip enrollment
     *
     * @param int $enrollment_id
     * @param int $agent_user_id  For ownership verification
     * @return bool
     */
    public static function cancel_enrollment($enrollment_id, $agent_user_id) {
        global $wpdb;
        $enroll_table = $wpdb->prefix . 'mld_open_house_drip_enrollments';

        return (bool) $wpdb->update($enroll_table,
            array(
                'status'       => 'cancelled',
                'next_send_at' => null,
            ),
            array('id' => $enrollment_id, 'agent_user_id' => $agent_user_id),
            array('%s', '%s'),
            array('%d', '%d')
        );
    }

    /**
     * Format an enrollment for API response
     */
    private static function format_enrollment($enrollment) {
        return array(
            'id'                  => (int) $enrollment->id,
            'open_house_id'       => (int) $enrollment->open_house_id,
            'attendee_id'         => (int) $enrollment->attendee_id,
            'attendee_email'      => $enrollment->attendee_email,
            'attendee_first_name' => $enrollment->attendee_first_name,
            'attendee_last_name'  => $enrollment->attendee_last_name,
            'current_step'        => (int) $enrollment->current_step,
            'total_steps'         => (int) $enrollment->total_steps,
            'current_step_label'  => self::STEP_LABELS[(int) $enrollment->current_step] ?? 'Not started',
            'next_step_label'     => self::STEP_LABELS[(int) $enrollment->current_step + 1] ?? null,
            'status'              => $enrollment->status,
            'enrolled_at'         => $enrollment->enrolled_at,
            'next_send_at'        => $enrollment->next_send_at,
            'completed_at'        => $enrollment->completed_at,
            'property_address'    => $enrollment->property_address,
            'property_city'       => $enrollment->property_city,
            'listing_id'          => $enrollment->listing_id,
        );
    }

    // ------------------------------------------------------------------
    //  EMAIL SENDING
    // ------------------------------------------------------------------

    /**
     * Send a drip email for the given enrollment and step
     *
     * @param object $enrollment  Enrollment row
     * @param int    $step        Step number (1-5)
     * @return bool Whether email was sent
     */
    private static function send_drip_email($enrollment, $step) {
        $to = $enrollment->attendee_email;
        $first_name = $enrollment->attendee_first_name ?: 'there';

        // Get agent info
        $agent = get_userdata((int) $enrollment->agent_user_id);
        $agent_name = $agent ? $agent->display_name : 'Your Agent';
        $agent_email = $agent ? $agent->user_email : '';
        $agent_phone = '';

        // Try to get agent phone from profile
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent_profile = MLD_Agent_Client_Manager::get_agent_for_api((int) $enrollment->agent_user_id);
            if ($agent_profile) {
                $agent_phone = $agent_profile['phone'] ?? '';
            }
        }

        // Build subject and body based on step
        $subject = self::get_step_subject($step, $enrollment);
        $html = self::get_step_html($step, $enrollment, $agent_name, $agent_email, $agent_phone);

        // Email headers - use agent as sender
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if (class_exists('MLD_Email_Utilities')) {
            $headers = MLD_Email_Utilities::get_email_headers(null);
        }
        // Override From to agent
        if ($agent_email && $agent_name) {
            $headers = array_filter($headers, function($h) {
                return stripos($h, 'from:') === false;
            });
            $headers[] = "From: {$agent_name} <{$agent_email}>";
        }

        $sent = wp_mail($to, $subject, $html, $headers);

        if (!$sent && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Open House Drip: Failed to send step {$step} to {$to}");
        }

        return $sent;
    }

    /**
     * Get email subject for a drip step
     */
    private static function get_step_subject($step, $enrollment) {
        $property = $enrollment->property_address ?: 'the property';
        $first_name = $enrollment->attendee_first_name ?: '';

        switch ($step) {
            case 1:
                return "Thanks for visiting {$property}!";
            case 2:
                return "Properties you might love near {$enrollment->property_city}";
            case 3:
                return "Buyer resources to help your home search";
            case 4:
                return $first_name ? "{$first_name}, any updates on your home search?" : "Any updates on your home search?";
            case 5:
                return "Market update for {$enrollment->property_city}";
            default:
                return "A message from your open house visit";
        }
    }

    /**
     * Get HTML email body for a drip step
     */
    private static function get_step_html($step, $enrollment, $agent_name, $agent_email, $agent_phone) {
        $first_name = $enrollment->attendee_first_name ?: 'there';
        $property = $enrollment->property_address ?: 'the open house';
        $city = $enrollment->property_city ?: '';
        $listing_id = $enrollment->listing_id ?: '';

        $property_url = '';
        if ($listing_id) {
            $property_url = home_url('/property/' . $listing_id . '/');
        }

        $search_url = home_url('/search/');
        if ($city) {
            $search_url .= '#City=' . urlencode($city);
        }

        // Agent contact block (reused across all steps)
        $agent_block = self::build_agent_contact_block($agent_name, $agent_email, $agent_phone);

        // Build email based on step
        switch ($step) {
            case 1:
                return self::build_email_wrapper(
                    "Thanks for Visiting!",
                    self::build_step1_content($first_name, $property, $property_url, $agent_name, $agent_block)
                );
            case 2:
                return self::build_email_wrapper(
                    "Properties You Might Like",
                    self::build_step2_content($first_name, $city, $search_url, $agent_name, $agent_block)
                );
            case 3:
                return self::build_email_wrapper(
                    "Buyer Resources",
                    self::build_step3_content($first_name, $agent_name, $agent_block)
                );
            case 4:
                return self::build_email_wrapper(
                    "How's Your Search Going?",
                    self::build_step4_content($first_name, $city, $search_url, $agent_name, $agent_block)
                );
            case 5:
                return self::build_email_wrapper(
                    "Market Update",
                    self::build_step5_content($first_name, $city, $search_url, $agent_name, $agent_block)
                );
            default:
                return '';
        }
    }

    // ------------------------------------------------------------------
    //  EMAIL TEMPLATES
    // ------------------------------------------------------------------

    /**
     * Step 1: Thank You (1 day after)
     */
    private static function build_step1_content($first_name, $property, $property_url, $agent_name, $agent_block) {
        $property_link = $property_url
            ? '<a href="' . esc_url($property_url) . '" style="color:#1e3a5f;font-weight:600;">' . esc_html($property) . '</a>'
            : esc_html($property);

        return '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Hi ' . esc_html($first_name) . ',
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Thank you for visiting ' . $property_link . ' yesterday! It was great meeting you.
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            I hope you enjoyed seeing the property. If you have any questions about it&mdash;the
            neighborhood, pricing, or anything else&mdash;I\'d be happy to help.
        </p>
        ' . ($property_url ? '
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;">
            <tr>
                <td style="background:#1e3a5f;border-radius:8px;padding:12px 24px;">
                    <a href="' . esc_url($property_url) . '" style="color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                        View Property Details
                    </a>
                </td>
            </tr>
        </table>' : '') . '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            As a local real estate specialist, I can help you navigate every step of the buying process.
            Don\'t hesitate to reach out anytime.
        </p>
        ' . $agent_block;
    }

    /**
     * Step 2: Similar Properties (3 days after)
     */
    private static function build_step2_content($first_name, $city, $search_url, $agent_name, $agent_block) {
        return '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Hi ' . esc_html($first_name) . ',
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Since you showed interest in ' . esc_html($city ?: 'the area') . ', I thought you might like to see
            other properties nearby. The market moves fast, so staying on top of new listings is key.
        </p>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;">
            <tr>
                <td style="background:#1e3a5f;border-radius:8px;padding:12px 24px;">
                    <a href="' . esc_url($search_url) . '" style="color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                        Browse Properties' . ($city ? ' in ' . esc_html($city) : '') . '
                    </a>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            I can also set up a personalized property search based on your specific criteria&mdash;price range,
            bedrooms, neighborhood preferences, school districts&mdash;so you\'re the first to know when something
            perfect hits the market.
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Just reply to this email or give me a call to get started.
        </p>
        ' . $agent_block;
    }

    /**
     * Step 3: Buyer Resources (7 days after)
     */
    private static function build_step3_content($first_name, $agent_name, $agent_block) {
        return '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Hi ' . esc_html($first_name) . ',
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Whether you\'re buying your first home or your fifth, the process can feel overwhelming.
            Here are a few tips to help you feel confident:
        </p>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:16px 0;">
            <tr>
                <td style="background:#f8f9fa;border-radius:8px;padding:20px;">
                    <p style="margin:0 0 12px;color:#1e3a5f;font-size:15px;font-weight:600;">
                        1. Get Pre-Approved Early
                    </p>
                    <p style="margin:0 0 16px;color:#555;font-size:14px;line-height:1.5;">
                        A pre-approval letter shows sellers you\'re serious and helps you
                        understand exactly what you can afford. I can connect you with trusted local lenders.
                    </p>
                    <p style="margin:0 0 12px;color:#1e3a5f;font-size:15px;font-weight:600;">
                        2. Know What You Need vs. Want
                    </p>
                    <p style="margin:0 0 16px;color:#555;font-size:14px;line-height:1.5;">
                        Make a list of must-haves (bedrooms, location, school district) versus
                        nice-to-haves (pool, fireplace, finished basement). This helps focus your search.
                    </p>
                    <p style="margin:0 0 12px;color:#1e3a5f;font-size:15px;font-weight:600;">
                        3. Work with a Local Expert
                    </p>
                    <p style="margin:0 0 0;color:#555;font-size:14px;line-height:1.5;">
                        A knowledgeable agent knows the neighborhoods, pricing trends, and can
                        help you craft competitive offers. Buyer representation is typically free to you.
                    </p>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            I\'d love to be your guide through this process. Feel free to reach out with any questions.
        </p>
        ' . $agent_block;
    }

    /**
     * Step 4: Check-in (14 days after)
     */
    private static function build_step4_content($first_name, $city, $search_url, $agent_name, $agent_block) {
        return '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Hi ' . esc_html($first_name) . ',
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            I wanted to check in and see how your home search is going. It\'s been a couple
            weeks since we met at the open house, and I\'m curious if you\'ve found anything exciting.
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            New properties are coming on the market regularly' . ($city ? ' in ' . esc_html($city) . ' and surrounding areas' : '') . '.
            If you haven\'t already, I\'d recommend setting up instant alerts so you don\'t miss anything.
        </p>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;">
            <tr>
                <td style="background:#1e3a5f;border-radius:8px;padding:12px 24px;">
                    <a href="' . esc_url($search_url) . '" style="color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                        See Latest Listings
                    </a>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Whether you have questions, want to schedule private showings, or just need advice,
            I\'m here for you. No pressure&mdash;just expertise when you need it.
        </p>
        ' . $agent_block;
    }

    /**
     * Step 5: Final Touch (30 days after)
     */
    private static function build_step5_content($first_name, $city, $search_url, $agent_name, $agent_block) {
        return '
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Hi ' . esc_html($first_name) . ',
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            It\'s been about a month since we met at the open house, and I wanted to share a quick
            market update' . ($city ? ' for ' . esc_html($city) : '') . '.
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            The market is always evolving&mdash;inventory levels, pricing trends, and interest rates
            all play a role in finding the right home at the right time. If you\'re still in the market,
            I\'d be happy to provide a personalized analysis based on your criteria.
        </p>
        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;">
            <tr>
                <td style="background:#1e3a5f;border-radius:8px;padding:12px 24px;">
                    <a href="' . esc_url($search_url) . '" style="color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">
                        Explore the Market
                    </a>
                </td>
            </tr>
        </table>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Even if your timeline has shifted, feel free to reach out anytime. I\'m always happy
            to chat about real estate&mdash;no obligation.
        </p>
        <p style="margin:0 0 16px;color:#333;font-size:16px;line-height:1.6;">
            Wishing you all the best,
        </p>
        ' . $agent_block;
    }

    // ------------------------------------------------------------------
    //  EMAIL BUILDING HELPERS
    // ------------------------------------------------------------------

    /**
     * Build the agent contact block
     */
    private static function build_agent_contact_block($agent_name, $agent_email, $agent_phone) {
        $lines = array();
        if ($agent_phone) {
            $lines[] = '<a href="tel:' . esc_attr($agent_phone) . '" style="color:#1e3a5f;text-decoration:none;">' . esc_html($agent_phone) . '</a>';
        }
        if ($agent_email) {
            $lines[] = '<a href="mailto:' . esc_attr($agent_email) . '" style="color:#1e3a5f;text-decoration:none;">' . esc_html($agent_email) . '</a>';
        }

        $contact = implode(' &nbsp;|&nbsp; ', $lines);

        return '
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0 0;border-top:1px solid #e0e0e0;">
            <tr>
                <td style="padding:20px 0 0;">
                    <p style="margin:0 0 4px;color:#1e3a5f;font-size:16px;font-weight:600;">
                        ' . esc_html($agent_name) . '
                    </p>
                    ' . ($contact ? '<p style="margin:0;color:#666;font-size:14px;">' . $contact . '</p>' : '') . '
                </td>
            </tr>
        </table>';
    }

    /**
     * Wrap email content in the standard template
     */
    private static function build_email_wrapper($heading, $content) {
        // Get app footer if available
        $app_footer = '';
        if (class_exists('MLD_Email_Utilities')) {
            $app_footer = MLD_Email_Utilities::get_app_download_section(false, 'compact');
        }

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f4f4f4;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f4;">
        <tr>
            <td align="center" style="padding:30px 15px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);padding:30px 40px;text-align:center;">
                            <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:600;">
                                ' . esc_html($heading) . '
                            </h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            ' . $content . '
                        </td>
                    </tr>
                    <!-- App Footer -->
                    <tr>
                        <td style="padding:0 40px 20px;">
                            ' . $app_footer . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:20px 40px;background:#f8f9fa;text-align:center;">
                            <p style="margin:0;color:#999;font-size:12px;line-height:1.5;">
                                You\'re receiving this because you visited an open house and opted in for follow-up.
                                <br>
                                &copy; ' . esc_html(wp_date('Y')) . ' BMN Boston Real Estate
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

}
