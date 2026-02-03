<?php
/**
 * Client Portal AJAX Class
 *
 * Handles AJAX requests for the client portal functionality.
 * All actions require user to be logged in.
 *
 * @package SN_Appointment_Booking
 * @since 1.5.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client Portal AJAX class.
 *
 * @since 1.5.0
 */
class SNAB_Client_Portal_Ajax {

    /**
     * Client portal instance.
     *
     * @var SNAB_Client_Portal
     */
    private $portal;

    /**
     * Availability service instance.
     *
     * @var SNAB_Availability_Service
     */
    private $availability_service;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->portal = snab_client_portal();
        $this->availability_service = new SNAB_Availability_Service();

        // Register AJAX handlers (logged-in users only - no nopriv versions)
        add_action('wp_ajax_snab_client_get_appointments', array($this, 'get_appointments'));
        add_action('wp_ajax_snab_client_get_appointment', array($this, 'get_appointment'));
        add_action('wp_ajax_snab_client_cancel_appointment', array($this, 'cancel_appointment'));
        add_action('wp_ajax_snab_client_reschedule_appointment', array($this, 'reschedule_appointment'));
        add_action('wp_ajax_snab_client_get_reschedule_slots', array($this, 'get_reschedule_slots'));
    }

    /**
     * Get user's appointments.
     *
     * @since 1.5.0
     */
    public function get_appointments() {
        check_ajax_referer('snab_client_portal_nonce', 'nonce');

        if (!$this->portal->is_enabled()) {
            wp_send_json_error(__('Client portal is not enabled.', 'sn-appointment-booking'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in to view your appointments.', 'sn-appointment-booking'));
        }

        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
        $days_past = isset($_POST['days_past']) ? absint($_POST['days_past']) : 90;
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 10;

        // Validate status
        if (!in_array($status, array('upcoming', 'past', 'all'), true)) {
            $status = 'all';
        }

        // Limit per_page to reasonable values
        $per_page = min(max($per_page, 5), 50);

        $result = $this->portal->get_user_appointments($user_id, $status, $days_past, $page, $per_page);

        // Format appointments for JSON response
        $appointments = array();
        foreach ($result['appointments'] as $apt) {
            $appointments[] = $this->format_appointment_for_response($apt);
        }

        wp_send_json_success(array(
            'appointments' => $appointments,
            'total' => $result['total'],
            'pages' => $result['pages'],
            'current_page' => $page,
            'cancellation_policy' => $this->portal->get_cancellation_policy(),
            'reschedule_policy' => $this->portal->get_reschedule_policy(),
        ));
    }

    /**
     * Get single appointment details.
     *
     * @since 1.5.0
     */
    public function get_appointment() {
        check_ajax_referer('snab_client_portal_nonce', 'nonce');

        if (!$this->portal->is_enabled()) {
            wp_send_json_error(__('Client portal is not enabled.', 'sn-appointment-booking'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in to view appointment details.', 'sn-appointment-booking'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        if (!$appointment_id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        $appointment = $this->portal->get_user_appointment($appointment_id, $user_id);
        if (!$appointment) {
            wp_send_json_error(__('Appointment not found.', 'sn-appointment-booking'));
        }

        wp_send_json_success(array(
            'appointment' => $this->format_appointment_for_response($appointment),
        ));
    }

    /**
     * Cancel an appointment.
     *
     * @since 1.5.0
     */
    public function cancel_appointment() {
        check_ajax_referer('snab_client_portal_nonce', 'nonce');

        if (!$this->portal->is_enabled()) {
            wp_send_json_error(__('Client portal is not enabled.', 'sn-appointment-booking'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in to cancel appointments.', 'sn-appointment-booking'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$appointment_id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        $result = $this->portal->cancel_appointment($appointment_id, $user_id, $reason);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Reschedule an appointment.
     *
     * @since 1.5.0
     */
    public function reschedule_appointment() {
        check_ajax_referer('snab_client_portal_nonce', 'nonce');

        if (!$this->portal->is_enabled()) {
            wp_send_json_error(__('Client portal is not enabled.', 'sn-appointment-booking'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in to reschedule appointments.', 'sn-appointment-booking'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $new_date = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';
        $new_time = isset($_POST['new_time']) ? sanitize_text_field($_POST['new_time']) : '';

        if (!$appointment_id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        if (empty($new_date) || empty($new_time)) {
            wp_send_json_error(__('Please select a new date and time.', 'sn-appointment-booking'));
        }

        $result = $this->portal->reschedule_appointment($appointment_id, $user_id, $new_date, $new_time);

        if ($result['success']) {
            // Return updated appointment data
            $appointment = $this->portal->get_user_appointment($appointment_id, $user_id);

            wp_send_json_success(array(
                'message' => $result['message'],
                'appointment' => $appointment ? $this->format_appointment_for_response($appointment) : null,
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Get available slots for rescheduling.
     *
     * @since 1.5.0
     */
    public function get_reschedule_slots() {
        check_ajax_referer('snab_client_portal_nonce', 'nonce');

        if (!$this->portal->is_enabled()) {
            wp_send_json_error(__('Client portal is not enabled.', 'sn-appointment-booking'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in.', 'sn-appointment-booking'));
        }

        $appointment_id = isset($_POST['appointment_id']) ? absint($_POST['appointment_id']) : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (!$appointment_id) {
            wp_send_json_error(__('Invalid appointment ID.', 'sn-appointment-booking'));
        }

        // Get the appointment to know the type
        $appointment = $this->portal->get_user_appointment($appointment_id, $user_id);

        if (!$appointment) {
            wp_send_json_error(__('Appointment not found.', 'sn-appointment-booking'));
        }

        if (!$appointment->can_reschedule) {
            wp_send_json_error(__('This appointment cannot be rescheduled.', 'sn-appointment-booking'));
        }

        // Default to next 2 weeks if no dates provided
        if (empty($start_date)) {
            $start_date = wp_date('Y-m-d');
        }
        if (empty($end_date)) {
            $end_date = wp_date('Y-m-d', current_time('timestamp') + (14 * DAY_IN_SECONDS));
        }

        // Validate dates
        if (!$this->validate_date($start_date) || !$this->validate_date($end_date)) {
            wp_send_json_error(__('Invalid date format.', 'sn-appointment-booking'));
        }

        // Get available slots
        $slots = $this->availability_service->get_available_slots(
            $start_date,
            $end_date,
            $appointment->appointment_type_id,
            $appointment->staff_id
        );

        // Format for frontend
        $formatted_slots = array();
        foreach ($slots as $date => $times) {
            $formatted_slots[$date] = array();
            foreach ($times as $time) {
                $formatted_slots[$date][] = array(
                    'value' => $time,
                    'label' => wp_date(get_option('time_format'), strtotime('2000-01-01 ' . $time)),
                );
            }
        }

        wp_send_json_success(array(
            'slots' => $formatted_slots,
            'dates_with_availability' => array_keys($slots),
        ));
    }

    /**
     * Format appointment object for JSON response.
     *
     * @param object $appointment Appointment object.
     * @return array Formatted appointment data.
     */
    private function format_appointment_for_response($appointment) {
        return array(
            'id' => (int) $appointment->id,
            'status' => $appointment->status,
            'status_label' => $this->get_status_label($appointment->status),
            'type_id' => (int) $appointment->appointment_type_id,
            'type_name' => $appointment->type_name,
            'type_color' => $appointment->type_color,
            'date' => $appointment->appointment_date,
            'formatted_date' => $appointment->formatted_date,
            'start_time' => $appointment->start_time,
            'end_time' => $appointment->end_time,
            'formatted_time' => $appointment->formatted_time,
            'formatted_end_time' => $appointment->formatted_end_time,
            'duration' => (int) $appointment->duration_minutes,
            'property_address' => $appointment->property_address,
            'client_notes' => $appointment->client_notes,
            'can_cancel' => $appointment->can_cancel,
            'can_reschedule' => $appointment->can_reschedule,
            'is_upcoming' => $appointment->is_upcoming,
            'reschedule_count' => (int) $appointment->reschedule_count,
            'google_synced' => (bool) $appointment->google_calendar_synced,
        );
    }

    /**
     * Get human-readable status label.
     *
     * @param string $status Status key.
     * @return string Status label.
     */
    private function get_status_label($status) {
        $labels = array(
            'pending' => __('Pending', 'sn-appointment-booking'),
            'confirmed' => __('Confirmed', 'sn-appointment-booking'),
            'cancelled' => __('Cancelled', 'sn-appointment-booking'),
            'completed' => __('Completed', 'sn-appointment-booking'),
            'no_show' => __('No Show', 'sn-appointment-booking'),
        );

        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
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
}
