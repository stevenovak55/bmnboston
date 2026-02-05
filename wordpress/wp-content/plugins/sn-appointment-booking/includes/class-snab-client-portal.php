<?php
/**
 * Client Portal Class
 *
 * Handles client self-service functionality: viewing, cancelling,
 * and rescheduling their own appointments.
 *
 * @package SN_Appointment_Booking
 * @since 1.5.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client Portal class.
 *
 * @since 1.5.0
 */
class SNAB_Client_Portal {

    /**
     * Singleton instance.
     *
     * @var SNAB_Client_Portal
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return SNAB_Client_Portal
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Check if client portal is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return get_option('snab_enable_client_portal', '1') === '1';
    }

    /**
     * Get appointments for a user.
     *
     * @param int    $user_id  User ID.
     * @param string $status   Filter by status: 'upcoming', 'past', 'all'.
     * @param int    $days_past Number of days in the past to include (for 'all' and 'past').
     * @param int    $page     Page number for pagination.
     * @param int    $per_page Items per page.
     * @return array {
     *     @type array  $appointments Array of appointment objects.
     *     @type int    $total        Total count for pagination.
     *     @type int    $pages        Total pages.
     * }
     */
    public function get_user_appointments($user_id, $status = 'all', $days_past = 90, $page = 1, $per_page = 10) {
        global $wpdb;

        $user_id = absint($user_id);
        if (!$user_id) {
            return array(
                'appointments' => array(),
                'total' => 0,
                'pages' => 0,
            );
        }

        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $staff_table = $wpdb->prefix . 'snab_staff';

        $today = wp_date('Y-m-d');
        $past_date = wp_date('Y-m-d', current_time('timestamp') - ($days_past * DAY_IN_SECONDS));

        // Check if user is a staff member (staff can see appointments booked WITH them)
        $staff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$staff_table} WHERE user_id = %d",
            $user_id
        ));

        // Build WHERE clause based on status and user type
        if ($staff_id) {
            // Staff can see appointments they booked OR appointments booked with them
            $where_clauses = array("(a.user_id = %d OR a.staff_id = %d)");
            $params = array($user_id, $staff_id);
        } else {
            // Regular clients can only see their own appointments
            $where_clauses = array("a.user_id = %d");
            $params = array($user_id);
        }

        if ($status === 'upcoming') {
            // Upcoming: confirmed or pending appointments in the future
            $where_clauses[] = "a.appointment_date >= %s";
            $where_clauses[] = "a.status IN ('pending', 'confirmed')";
            $params[] = $today;
        } elseif ($status === 'past') {
            // Past: completed, cancelled, no-show, or dates in the past
            $where_clauses[] = "(a.appointment_date < %s OR a.status IN ('completed', 'cancelled', 'no_show'))";
            $where_clauses[] = "a.appointment_date >= %s";
            $params[] = $today;
            $params[] = $past_date;
        } else {
            // All: Include everything within the date range
            $where_clauses[] = "a.appointment_date >= %s";
            $params[] = $past_date;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$appointments_table} a WHERE {$where_sql}",
            ...$params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Calculate pagination
        $pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;

        // Get appointments with type information
        $order = ($status === 'past') ? 'DESC' : 'ASC';
        $query_sql = $wpdb->prepare(
            "SELECT a.*, t.name AS type_name, t.color AS type_color, t.duration_minutes
             FROM {$appointments_table} a
             LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE {$where_sql}
             ORDER BY a.appointment_date {$order}, a.start_time {$order}
             LIMIT %d OFFSET %d",
            ...array_merge($params, array($per_page, $offset))
        );
        $appointments = $wpdb->get_results($query_sql);

        // Add computed fields for each appointment
        $now = current_time('timestamp');
        foreach ($appointments as &$apt) {
            $apt_timestamp = snab_datetime_to_timestamp($apt->appointment_date, $apt->start_time);

            // Determine if current user is the staff member for this appointment
            $is_staff_viewing = $staff_id && ($apt->staff_id == $staff_id);

            $apt->can_cancel = $this->can_user_cancel_appointment($apt, $now, $apt_timestamp, $is_staff_viewing);
            $apt->can_reschedule = $this->can_user_reschedule_appointment($apt, $now, $apt_timestamp, $is_staff_viewing);
            $apt->formatted_date = snab_format_date($apt->appointment_date);
            $apt->formatted_time = snab_format_time($apt->appointment_date, $apt->start_time);
            $apt->formatted_end_time = snab_format_time($apt->appointment_date, $apt->end_time);
            $apt->is_upcoming = ($apt_timestamp > $now) && in_array($apt->status, array('pending', 'confirmed'), true);
        }

        return array(
            'appointments' => $appointments,
            'total' => $total,
            'pages' => $pages,
        );
    }

    /**
     * Get a single appointment by ID for a user.
     *
     * @param int $appointment_id Appointment ID.
     * @param int $user_id        User ID.
     * @return object|null Appointment object or null if not found/not owned.
     */
    public function get_user_appointment($appointment_id, $user_id) {
        global $wpdb;

        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $staff_table = $wpdb->prefix . 'snab_staff';

        // Check if user is a staff member (staff can access appointments booked WITH them)
        $staff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$staff_table} WHERE user_id = %d",
            $user_id
        ));

        // Build query based on whether user is staff or regular client
        if ($staff_id) {
            // Staff can access appointments they booked OR appointments booked with them
            $appointment = $wpdb->get_row($wpdb->prepare(
                "SELECT a.*, t.name AS type_name, t.color AS type_color, t.duration_minutes
                 FROM {$appointments_table} a
                 LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
                 WHERE a.id = %d AND (a.user_id = %d OR a.staff_id = %d)",
                $appointment_id,
                $user_id,
                $staff_id
            ));
        } else {
            // Regular clients can only access their own appointments
            $appointment = $wpdb->get_row($wpdb->prepare(
                "SELECT a.*, t.name AS type_name, t.color AS type_color, t.duration_minutes
                 FROM {$appointments_table} a
                 LEFT JOIN {$types_table} t ON a.appointment_type_id = t.id
                 WHERE a.id = %d AND a.user_id = %d",
                $appointment_id,
                $user_id
            ));
        }

        if ($appointment) {
            $now = current_time('timestamp');
            $apt_timestamp = snab_datetime_to_timestamp($appointment->appointment_date, $appointment->start_time);

            // Determine if current user is the staff member (staff bypass time restrictions)
            $is_staff_viewing = $staff_id && ($appointment->staff_id == $staff_id);

            $appointment->can_cancel = $this->can_user_cancel_appointment($appointment, $now, $apt_timestamp, $is_staff_viewing);
            $appointment->can_reschedule = $this->can_user_reschedule_appointment($appointment, $now, $apt_timestamp, $is_staff_viewing);
            $appointment->formatted_date = snab_format_date($appointment->appointment_date);
            $appointment->formatted_time = snab_format_time($appointment->appointment_date, $appointment->start_time);
            $appointment->formatted_end_time = snab_format_time($appointment->appointment_date, $appointment->end_time);
        }

        return $appointment;
    }

    /**
     * Check if user can cancel an appointment.
     *
     * @param object $appointment   Appointment object.
     * @param int    $now           Current timestamp.
     * @param int    $apt_timestamp Appointment timestamp.
     * @param bool   $is_staff      Whether current user is the assigned staff (bypasses time restrictions).
     * @return bool
     */
    private function can_user_cancel_appointment($appointment, $now, $apt_timestamp, $is_staff = false) {
        // Can only cancel pending or confirmed appointments
        if (!in_array($appointment->status, array('pending', 'confirmed'), true)) {
            return false;
        }

        // Check if appointment is in the future
        if ($apt_timestamp <= $now) {
            return false;
        }

        // Staff can cancel anytime (bypass time restrictions)
        if ($is_staff) {
            return true;
        }

        // Check minimum hours before (clients only)
        $min_hours = (int) get_option('snab_cancellation_hours_before', 24);
        $min_timestamp = $now + ($min_hours * HOUR_IN_SECONDS);

        return $apt_timestamp >= $min_timestamp;
    }

    /**
     * Check if user can reschedule an appointment.
     *
     * @param object $appointment   Appointment object.
     * @param int    $now           Current timestamp.
     * @param int    $apt_timestamp Appointment timestamp.
     * @param bool   $is_staff      Whether current user is the assigned staff (bypasses time restrictions).
     * @return bool
     */
    private function can_user_reschedule_appointment($appointment, $now, $apt_timestamp, $is_staff = false) {
        // Can only reschedule pending or confirmed appointments
        if (!in_array($appointment->status, array('pending', 'confirmed'), true)) {
            return false;
        }

        // Check if appointment is in the future
        if ($apt_timestamp <= $now) {
            return false;
        }

        // Staff can reschedule anytime (bypass time restrictions and reschedule limits)
        if ($is_staff) {
            return true;
        }

        // Check minimum hours before (clients only)
        $min_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $min_timestamp = $now + ($min_hours * HOUR_IN_SECONDS);

        if ($apt_timestamp < $min_timestamp) {
            return false;
        }

        // Check max reschedules (clients only)
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);
        if ($max_reschedules > 0 && (int) $appointment->reschedule_count >= $max_reschedules) {
            return false;
        }

        return true;
    }

    /**
     * Cancel an appointment by client.
     *
     * @param int    $appointment_id Appointment ID.
     * @param int    $user_id        User ID.
     * @param string $reason         Cancellation reason.
     * @return array {
     *     @type bool   $success Whether cancellation succeeded.
     *     @type string $message Success/error message.
     * }
     */
    public function cancel_appointment($appointment_id, $user_id, $reason = '') {
        global $wpdb;

        // Get the appointment and verify ownership
        $appointment = $this->get_user_appointment($appointment_id, $user_id);

        if (!$appointment) {
            return array(
                'success' => false,
                'message' => __('Appointment not found or you do not have permission to cancel it.', 'sn-appointment-booking'),
            );
        }

        if (!$appointment->can_cancel) {
            $min_hours = (int) get_option('snab_cancellation_hours_before', 24);
            return array(
                'success' => false,
                'message' => sprintf(
                    __('This appointment cannot be cancelled. Cancellations must be made at least %d hours in advance.', 'sn-appointment-booking'),
                    $min_hours
                ),
            );
        }

        // Check if reason is required
        $require_reason = get_option('snab_require_cancel_reason', '1') === '1';
        if ($require_reason && empty(trim($reason))) {
            return array(
                'success' => false,
                'message' => __('Please provide a reason for cancellation.', 'sn-appointment-booking'),
            );
        }

        $appointments_table = $wpdb->prefix . 'snab_appointments';

        // Update the appointment
        $result = $wpdb->update(
            $appointments_table,
            array(
                'status' => 'cancelled',
                'cancellation_reason' => sanitize_textarea_field($reason),
                'cancelled_by' => 'client',
                'cancelled_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $appointment_id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            SNAB_Logger::error('Failed to cancel appointment', array(
                'appointment_id' => $appointment_id,
                'user_id' => $user_id,
                'error' => $wpdb->last_error,
            ));
            return array(
                'success' => false,
                'message' => __('Failed to cancel appointment. Please try again.', 'sn-appointment-booking'),
            );
        }

        // Delete Google Calendar event if synced
        if (!empty($appointment->google_event_id)) {
            $google_calendar = snab_google_calendar();
            if ($google_calendar->is_connected()) {
                $delete_result = $google_calendar->delete_event($appointment->google_event_id);
                if (is_wp_error($delete_result)) {
                    SNAB_Logger::warning('Failed to delete Google Calendar event', array(
                        'appointment_id' => $appointment_id,
                        'event_id' => $appointment->google_event_id,
                        'error' => $delete_result->get_error_message(),
                    ));
                }
            }
        }

        // Send notifications
        if (get_option('snab_notify_admin_on_client_changes', '1') === '1') {
            $notifications = snab_notifications();
            $notifications->send_client_cancel_admin_notification($appointment_id, $reason);
        }

        // Send confirmation to client
        $notifications = snab_notifications();
        $notifications->send_client_cancel_client_notification($appointment_id);

        SNAB_Logger::info('Client cancelled appointment', array(
            'appointment_id' => $appointment_id,
            'user_id' => $user_id,
            'reason' => $reason,
        ));

        return array(
            'success' => true,
            'message' => __('Your appointment has been cancelled successfully.', 'sn-appointment-booking'),
        );
    }

    /**
     * Reschedule an appointment by client.
     *
     * @param int    $appointment_id Appointment ID.
     * @param int    $user_id        User ID.
     * @param string $new_date       New date (Y-m-d).
     * @param string $new_time       New time (H:i).
     * @return array {
     *     @type bool   $success Whether reschedule succeeded.
     *     @type string $message Success/error message.
     * }
     */
    public function reschedule_appointment($appointment_id, $user_id, $new_date, $new_time) {
        global $wpdb;

        // Get the appointment and verify ownership
        $appointment = $this->get_user_appointment($appointment_id, $user_id);

        if (!$appointment) {
            return array(
                'success' => false,
                'message' => __('Appointment not found or you do not have permission to reschedule it.', 'sn-appointment-booking'),
            );
        }

        if (!$appointment->can_reschedule) {
            $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);
            if ($max_reschedules > 0 && (int) $appointment->reschedule_count >= $max_reschedules) {
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __('This appointment has reached the maximum number of reschedules (%d).', 'sn-appointment-booking'),
                        $max_reschedules
                    ),
                );
            }

            $min_hours = (int) get_option('snab_reschedule_hours_before', 24);
            return array(
                'success' => false,
                'message' => sprintf(
                    __('This appointment cannot be rescheduled. Changes must be made at least %d hours in advance.', 'sn-appointment-booking'),
                    $min_hours
                ),
            );
        }

        // Validate new date and time
        if (!$this->validate_date($new_date)) {
            return array(
                'success' => false,
                'message' => __('Invalid date format.', 'sn-appointment-booking'),
            );
        }

        if (!$this->validate_time($new_time)) {
            return array(
                'success' => false,
                'message' => __('Invalid time format.', 'sn-appointment-booking'),
            );
        }

        // Check if the new slot is available
        $availability_service = new SNAB_Availability_Service();
        if (!$availability_service->is_slot_available($new_date, $new_time, $appointment->appointment_type_id, $appointment->staff_id)) {
            return array(
                'success' => false,
                'message' => __('The selected time slot is no longer available. Please choose another time.', 'sn-appointment-booking'),
            );
        }

        // Store original date/time for notification
        $old_date = $appointment->appointment_date;
        $old_time = $appointment->start_time;

        // Calculate new end time
        $duration = (int) $appointment->duration_minutes;
        $new_start = new DateTime($new_date . ' ' . $new_time, wp_timezone());
        $new_end = clone $new_start;
        $new_end->modify('+' . $duration . ' minutes');

        $appointments_table = $wpdb->prefix . 'snab_appointments';

        // Update the appointment
        $update_data = array(
            'appointment_date' => $new_date,
            'start_time' => $new_start->format('H:i:s'),
            'end_time' => $new_end->format('H:i:s'),
            'reschedule_count' => (int) $appointment->reschedule_count + 1,
            'rescheduled_by' => 'client',
            'updated_at' => current_time('mysql'),
        );

        // Store original datetime on first reschedule
        if (empty($appointment->original_datetime)) {
            $update_data['original_datetime'] = $old_date . ' ' . $old_time;
        }

        $result = $wpdb->update(
            $appointments_table,
            $update_data,
            array('id' => $appointment_id),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            SNAB_Logger::error('Failed to reschedule appointment', array(
                'appointment_id' => $appointment_id,
                'user_id' => $user_id,
                'error' => $wpdb->last_error,
            ));
            return array(
                'success' => false,
                'message' => __('Failed to reschedule appointment. Please try again.', 'sn-appointment-booking'),
            );
        }

        // Update Google Calendar event if synced (v1.10.4: use per-staff method with attendees)
        if (!empty($appointment->google_event_id) && !empty($appointment->staff_id)) {
            try {
                $google_calendar = snab_google_calendar();
                if ($google_calendar->is_staff_connected($appointment->staff_id)) {
                    $timezone = wp_timezone_string();
                    $time_with_seconds = (strlen($new_time) === 5) ? $new_time . ':00' : $new_time;
                    $start_datetime = $new_date . 'T' . $time_with_seconds;

                    // Calculate end time
                    $duration_minutes = !empty($appointment->duration_minutes) ? (int) $appointment->duration_minutes : 30;
                    $end_dt = new DateTime($new_date . ' ' . $new_time, wp_timezone());
                    $end_dt->add(new DateInterval('PT' . $duration_minutes . 'M'));
                    $end_datetime_str = $new_date . 'T' . $end_dt->format('H:i:s');

                    $gcal_update_data = array(
                        'start' => array(
                            'dateTime' => $start_datetime,
                            'timeZone' => $timezone,
                        ),
                        'end' => array(
                            'dateTime' => $end_datetime_str,
                            'timeZone' => $timezone,
                        ),
                    );

                    // Include all attendees in the update
                    $attendees_array = $google_calendar->build_attendees_array($appointment_id);
                    if (!empty($attendees_array)) {
                        $gcal_update_data['attendees'] = $attendees_array;
                    }

                    $update_result = $google_calendar->update_staff_event(
                        $appointment->staff_id,
                        $appointment->google_event_id,
                        $gcal_update_data
                    );

                    if (is_wp_error($update_result)) {
                        SNAB_Logger::warning('Failed to update Google Calendar event', array(
                            'appointment_id' => $appointment_id,
                            'event_id' => $appointment->google_event_id,
                            'error' => $update_result->get_error_message(),
                        ));
                    }
                }
            } catch (Exception $e) {
                SNAB_Logger::error('Failed to update Google Calendar event', array(
                    'appointment_id' => $appointment_id,
                    'error' => $e->getMessage(),
                ));
            }
        }

        // Send notifications
        $notifications = snab_notifications();

        if (get_option('snab_notify_admin_on_client_changes', '1') === '1') {
            $notifications->send_client_reschedule_admin_notification($appointment_id, $old_date, $old_time);
        }

        // Send confirmation to client
        $notifications->send_client_reschedule_client_notification($appointment_id, $old_date, $old_time);

        SNAB_Logger::info('Client rescheduled appointment', array(
            'appointment_id' => $appointment_id,
            'user_id' => $user_id,
            'old_date' => $old_date,
            'old_time' => $old_time,
            'new_date' => $new_date,
            'new_time' => $new_time,
        ));

        return array(
            'success' => true,
            'message' => __('Your appointment has been rescheduled successfully.', 'sn-appointment-booking'),
        );
    }

    /**
     * Get cancellation policy text.
     *
     * @return string
     */
    public function get_cancellation_policy() {
        $min_hours = (int) get_option('snab_cancellation_hours_before', 24);
        return sprintf(
            __('Cancellations must be made at least %d hours before the scheduled appointment time.', 'sn-appointment-booking'),
            $min_hours
        );
    }

    /**
     * Get reschedule policy text.
     *
     * @return string
     */
    public function get_reschedule_policy() {
        $min_hours = (int) get_option('snab_reschedule_hours_before', 24);
        $max_reschedules = (int) get_option('snab_max_reschedules_per_appointment', 2);

        $policy = sprintf(
            __('Reschedules must be made at least %d hours before the scheduled appointment time.', 'sn-appointment-booking'),
            $min_hours
        );

        if ($max_reschedules > 0) {
            $policy .= ' ' . sprintf(
                __('Each appointment can be rescheduled a maximum of %d times.', 'sn-appointment-booking'),
                $max_reschedules
            );
        }

        return $policy;
    }

    /**
     * Validate date format (Y-m-d).
     *
     * @param string $date Date string.
     * @return bool
     */
    private function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Validate time format (H:i or H:i:s).
     *
     * @param string $time Time string.
     * @return bool
     */
    private function validate_time($time) {
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            $parts = explode(':', $time);
            $hour = (int) $parts[0];
            $minute = (int) $parts[1];
            return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
        }
        return false;
    }
}

/**
 * Get client portal instance.
 *
 * @return SNAB_Client_Portal
 */
function snab_client_portal() {
    return SNAB_Client_Portal::get_instance();
}
