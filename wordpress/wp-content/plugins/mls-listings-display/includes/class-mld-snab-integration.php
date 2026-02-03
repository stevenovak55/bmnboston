<?php
/**
 * SNAB Integration Class
 *
 * Handles integration with the SN Appointment Booking plugin.
 * Provides methods to query appointments and manage them from MLD.
 *
 * @package MLS_Listings_Display
 * @since 6.26.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SNAB Integration class.
 *
 * @since 6.26.8
 */
class MLD_SNAB_Integration {

    /**
     * Single instance of the class.
     *
     * @var MLD_SNAB_Integration
     */
    private static $instance = null;

    /**
     * Get single instance of the class.
     *
     * @return MLD_SNAB_Integration
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_mld_cancel_appointment', array($this, 'ajax_cancel_appointment'));
        add_action('wp_ajax_mld_get_reschedule_slots', array($this, 'ajax_get_reschedule_slots'));
        add_action('wp_ajax_mld_reschedule_appointment', array($this, 'ajax_reschedule_appointment'));
    }

    /**
     * Check if SNAB plugin is active.
     *
     * @return bool True if SNAB is active.
     */
    public static function is_snab_active() {
        // Check if main class exists (plugin loaded)
        if (class_exists('SN_Appointment_Booking')) {
            return true;
        }

        // Fallback: check if plugin file exists and is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        return is_plugin_active('sn-appointment-booking/sn-appointment-booking.php');
    }

    /**
     * Get appointments for a specific user.
     *
     * @param int    $user_id    WordPress user ID.
     * @param string $user_email User email address.
     * @param string $filter     Filter: 'all', 'upcoming', 'past'.
     * @return array Array of appointments.
     */
    public static function get_user_appointments($user_id, $user_email = '', $filter = 'all') {
        if (!self::is_snab_active()) {
            return array();
        }

        global $wpdb;
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';

        // Check if tables exist
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$appointments_table}'");
        if (!$table_exists) {
            return array();
        }

        // Build WHERE clause
        $where_parts = array();
        $where_values = array();

        // Match by user_id OR email
        if ($user_id > 0 && !empty($user_email)) {
            $where_parts[] = '(a.user_id = %d OR a.client_email = %s)';
            $where_values[] = $user_id;
            $where_values[] = $user_email;
        } elseif ($user_id > 0) {
            $where_parts[] = 'a.user_id = %d';
            $where_values[] = $user_id;
        } elseif (!empty($user_email)) {
            $where_parts[] = 'a.client_email = %s';
            $where_values[] = $user_email;
        } else {
            return array();
        }

        // Filter by date
        $today = current_time('Y-m-d');
        if ($filter === 'upcoming') {
            $where_parts[] = 'a.appointment_date >= %s';
            $where_values[] = $today;
            $where_parts[] = "a.status IN ('pending', 'confirmed')";
        } elseif ($filter === 'past') {
            $where_parts[] = '(a.appointment_date < %s OR a.status IN (\'completed\', \'cancelled\', \'no_show\'))';
            $where_values[] = $today;
        }

        $where_sql = implode(' AND ', $where_parts);

        // Determine sort order
        $order = ($filter === 'upcoming') ? 'ASC' : 'DESC';

        $sql = "SELECT a.*, t.name as type_name, t.color as type_color, t.duration_minutes
                FROM {$appointments_table} a
                LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
                WHERE {$where_sql}
                ORDER BY a.appointment_date {$order}, a.start_time {$order}";

        $appointments = $wpdb->get_results($wpdb->prepare($sql, ...$where_values), ARRAY_A);

        return $appointments ?: array();
    }

    /**
     * Get a single appointment by ID.
     *
     * @param int $appointment_id Appointment ID.
     * @return array|null Appointment array or null.
     */
    public static function get_appointment($appointment_id) {
        if (!self::is_snab_active()) {
            return null;
        }

        global $wpdb;
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color as type_color, t.duration_minutes
             FROM {$appointments_table} a
             LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE a.id = %d",
            $appointment_id
        ), ARRAY_A);
    }

    /**
     * Cancel an appointment.
     *
     * @param int    $appointment_id Appointment ID.
     * @param int    $user_id        User ID (for verification).
     * @param string $reason         Cancellation reason.
     * @return array Result array with success/error.
     */
    public static function cancel_appointment($appointment_id, $user_id, $reason = '') {
        if (!self::is_snab_active()) {
            return array('success' => false, 'message' => 'Appointment booking plugin is not active.');
        }

        // Get appointment and verify ownership
        $appointment = self::get_appointment($appointment_id);
        if (!$appointment) {
            return array('success' => false, 'message' => 'Appointment not found.');
        }

        // Verify user owns this appointment
        $current_user = wp_get_current_user();
        if ($appointment['user_id'] != $user_id && $appointment['client_email'] != $current_user->user_email) {
            return array('success' => false, 'message' => 'You do not have permission to cancel this appointment.');
        }

        // Check if already cancelled
        if ($appointment['status'] === 'cancelled') {
            return array('success' => false, 'message' => 'This appointment has already been cancelled.');
        }

        // Check if appointment is in the past
        $appointment_datetime = $appointment['appointment_date'] . ' ' . $appointment['start_time'];
        if (strtotime($appointment_datetime) < current_time('timestamp')) {
            return array('success' => false, 'message' => 'Cannot cancel past appointments.');
        }

        global $wpdb;
        $appointments_table = $wpdb->prefix . 'snab_appointments';

        // Update appointment status
        $updated = $wpdb->update(
            $appointments_table,
            array(
                'status' => 'cancelled',
                'cancellation_reason' => sanitize_textarea_field($reason),
                'cancelled_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $appointment_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return array('success' => false, 'message' => 'Failed to cancel appointment.');
        }

        // Try to cancel Google Calendar event if SNAB class exists
        if (function_exists('snab_google_calendar')) {
            $gcal = snab_google_calendar();
            if ($gcal && !empty($appointment['google_event_id'])) {
                $gcal->delete_event($appointment['google_event_id']);
            }
        }

        // Send cancellation notification if SNAB notification class exists
        if (function_exists('snab_notifications')) {
            $notifications = snab_notifications();
            if ($notifications && method_exists($notifications, 'send_cancellation')) {
                // send_cancellation expects: appointment_id, reason
                $notifications->send_cancellation($appointment_id, $reason);
            }
        }

        return array('success' => true, 'message' => 'Appointment cancelled successfully.');
    }

    /**
     * Reschedule an appointment.
     *
     * @param int    $appointment_id Appointment ID.
     * @param int    $user_id        User ID (for verification).
     * @param string $new_date       New date (Y-m-d).
     * @param string $new_time       New time (H:i:s).
     * @param string $reason         Reschedule reason.
     * @return array Result array with success/error.
     */
    public static function reschedule_appointment($appointment_id, $user_id, $new_date, $new_time, $reason = '') {
        if (!self::is_snab_active()) {
            return array('success' => false, 'message' => 'Appointment booking plugin is not active.');
        }

        // Get appointment and verify ownership
        $appointment = self::get_appointment($appointment_id);
        if (!$appointment) {
            return array('success' => false, 'message' => 'Appointment not found.');
        }

        // Verify user owns this appointment
        $current_user = wp_get_current_user();
        if ($appointment['user_id'] != $user_id && $appointment['client_email'] != $current_user->user_email) {
            return array('success' => false, 'message' => 'You do not have permission to reschedule this appointment.');
        }

        // Check if appointment can be rescheduled
        if (!in_array($appointment['status'], array('pending', 'confirmed'))) {
            return array('success' => false, 'message' => 'This appointment cannot be rescheduled.');
        }

        // Store original date/time BEFORE updating (for notification)
        $old_date = $appointment['appointment_date'];
        $old_time = $appointment['start_time'];

        // Calculate new end time based on duration
        $duration_minutes = $appointment['duration_minutes'] ?: 60;
        $new_start = strtotime($new_date . ' ' . $new_time);
        $new_end = $new_start + ($duration_minutes * 60);
        $new_end_time = date('H:i:s', $new_end);

        // Store original datetime for tracking (first reschedule only)
        $original_datetime = $appointment['appointment_date'] . ' ' . $appointment['start_time'];

        global $wpdb;
        $appointments_table = $wpdb->prefix . 'snab_appointments';

        // Update appointment
        $reschedule_count = isset($appointment['reschedule_count']) ? (int)$appointment['reschedule_count'] : 0;
        $updated = $wpdb->update(
            $appointments_table,
            array(
                'appointment_date' => $new_date,
                'start_time' => $new_time,
                'end_time' => $new_end_time,
                'reschedule_count' => $reschedule_count + 1,
                'original_datetime' => !empty($appointment['original_datetime']) ? $appointment['original_datetime'] : $original_datetime,
                'rescheduled_by' => 'client',
                'reschedule_reason' => sanitize_textarea_field($reason),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $appointment_id),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return array('success' => false, 'message' => 'Failed to reschedule appointment.');
        }

        // Update Google Calendar event if exists
        if (function_exists('snab_google_calendar') && !empty($appointment['google_event_id'])) {
            $gcal = snab_google_calendar();
            if ($gcal && $gcal->is_connected()) {
                $timezone = wp_timezone_string();

                // Build event data in Google Calendar API format
                $event_data = array(
                    'start' => array(
                        'dateTime' => $new_date . 'T' . $new_time,
                        'timeZone' => $timezone,
                    ),
                    'end' => array(
                        'dateTime' => $new_date . 'T' . $new_end_time,
                        'timeZone' => $timezone,
                    ),
                );

                $result = $gcal->update_event($appointment['google_event_id'], $event_data);

                if (is_wp_error($result)) {
                    // Log error but don't fail the reschedule
                    error_log('MLD SNAB Integration: Google Calendar update failed - ' . $result->get_error_message());
                }
            }
        }

        // Send reschedule notification to client and admin
        if (function_exists('snab_notifications')) {
            $notifications = snab_notifications();
            if ($notifications && method_exists($notifications, 'send_reschedule')) {
                // send_reschedule expects: appointment_id, old_date, old_time, reason
                $notifications->send_reschedule($appointment_id, $old_date, $old_time, $reason);
            }
        }

        return array('success' => true, 'message' => 'Appointment rescheduled successfully.');
    }

    /**
     * Get available time slots for a specific date.
     *
     * @param int    $appointment_type_id Appointment type ID.
     * @param string $date                Date (Y-m-d).
     * @return array Available time slots formatted for display.
     */
    public static function get_available_slots($appointment_type_id, $date) {
        if (!self::is_snab_active()) {
            return array();
        }

        // Use SNAB availability service if available
        if (class_exists('SNAB_Availability_Service')) {
            $service = new SNAB_Availability_Service();
            // SNAB service expects start_date, end_date, type_id
            $raw_slots = $service->get_available_slots($date, $date, $appointment_type_id);

            // Transform to format expected by JavaScript: [{time: 'HH:mm:ss', display: 'h:mm A'}]
            $formatted_slots = array();

            // Raw slots are returned as ['2025-01-01' => ['09:00', '09:30', ...]]
            // Times are already in WordPress local timezone (from SNAB service)
            if (!empty($raw_slots[$date])) {
                $time_format = get_option('time_format', 'g:i A');
                $timezone = wp_timezone();

                foreach ($raw_slots[$date] as $time) {
                    // Create DateTime with WordPress timezone to avoid UTC conversion issues
                    $dt = new DateTime($date . ' ' . $time, $timezone);

                    $formatted_slots[] = array(
                        'time' => $time . ':00', // Add seconds for consistency (H:i:s format)
                        'display' => $dt->format('g:i A'), // Format directly without timezone conversion
                    );
                }
            }

            return $formatted_slots;
        }

        return array();
    }

    /**
     * AJAX handler: Cancel appointment.
     */
    public function ajax_cancel_appointment() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mld_dashboard_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$appointment_id) {
            wp_send_json_error(array('message' => 'Invalid appointment ID.'));
        }

        $result = self::cancel_appointment($appointment_id, get_current_user_id(), $reason);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler: Get available slots for reschedule.
     */
    public function ajax_get_reschedule_slots() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mld_dashboard_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }

        // Accept either type_id directly or appointment_id to look it up
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

        // If no type_id, try to get it from appointment_id
        if (!$type_id && isset($_POST['appointment_id'])) {
            $appointment_id = absint($_POST['appointment_id']);
            $appointment = self::get_appointment($appointment_id);
            if ($appointment) {
                $type_id = $appointment['appointment_type_id'];
            }
        }

        if (!$type_id || !$date) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        $slots = self::get_available_slots($type_id, $date);

        wp_send_json_success(array('slots' => $slots));
    }

    /**
     * AJAX handler: Reschedule appointment.
     */
    public function ajax_reschedule_appointment() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mld_dashboard_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $new_date = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';
        $new_time = isset($_POST['new_time']) ? sanitize_text_field($_POST['new_time']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$appointment_id || !$new_date || !$new_time) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
            wp_send_json_error(array('message' => 'Invalid date format.'));
        }

        // Ensure time has seconds
        if (strlen($new_time) === 5) {
            $new_time .= ':00';
        }

        $result = self::reschedule_appointment($appointment_id, get_current_user_id(), $new_date, $new_time, $reason);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Format appointment for display.
     *
     * @param array $appointment Appointment array.
     * @return array Formatted appointment data.
     */
    public static function format_appointment($appointment) {
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $timezone = wp_timezone();

        // IMPORTANT: Create DateTime with WordPress timezone to avoid UTC conversion issues
        // strtotime() without timezone context causes wrong dates/times
        $datetime_str = $appointment['appointment_date'] . ' ' . $appointment['start_time'];
        $dt = new DateTime($datetime_str, $timezone);
        $appointment_timestamp = $dt->getTimestamp();

        $is_upcoming = $appointment_timestamp > current_time('timestamp');
        $is_today = $appointment['appointment_date'] === current_time('Y-m-d');

        // Format end time if available
        $end_time_display = '';
        if (!empty($appointment['end_time'])) {
            $end_dt = new DateTime($appointment['appointment_date'] . ' ' . $appointment['end_time'], $timezone);
            $end_time_display = wp_date($time_format, $end_dt->getTimestamp());
        }

        return array(
            'id' => $appointment['id'],
            'type_name' => $appointment['type_name'] ?? 'Appointment',
            'type_color' => !empty($appointment['type_color']) ? $appointment['type_color'] : '#3788d8',
            'status' => $appointment['status'],
            'status_label' => ucfirst($appointment['status']),
            'date' => wp_date($date_format, $appointment_timestamp),
            'time' => wp_date($time_format, $appointment_timestamp),
            'end_time' => $end_time_display,
            'datetime_raw' => $appointment['appointment_date'] . ' ' . $appointment['start_time'],
            'property_address' => $appointment['property_address'] ?? '',
            'listing_id' => $appointment['listing_id'] ?? '',
            'client_notes' => $appointment['client_notes'] ?? '',
            'is_upcoming' => $is_upcoming,
            'is_today' => $is_today,
            'can_cancel' => $is_upcoming && in_array($appointment['status'], array('pending', 'confirmed')),
            'can_reschedule' => $is_upcoming && in_array($appointment['status'], array('pending', 'confirmed')),
            'created_at' => $appointment['created_at'] ?? '',
            'booked_ago' => !empty($appointment['created_at']) ? human_time_diff(strtotime($appointment['created_at']), current_time('timestamp')) . ' ago' : '',
        );
    }
}

/**
 * Get the SNAB integration instance.
 *
 * @return MLD_SNAB_Integration
 */
function mld_snab_integration() {
    return MLD_SNAB_Integration::instance();
}
