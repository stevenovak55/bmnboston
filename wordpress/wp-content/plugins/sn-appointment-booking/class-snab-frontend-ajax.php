<?php
/**
 * Frontend AJAX Class
 *
 * Handles AJAX requests from the frontend booking widget.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend AJAX class.
 *
 * @since 1.0.0
 */
class SNAB_Frontend_Ajax {

    /**
     * Availability service instance.
     *
     * @var SNAB_Availability_Service
     */
    private $availability_service;

    /**
     * Google Calendar instance.
     *
     * @var SNAB_Google_Calendar
     */
    private $google_calendar;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->availability_service = new SNAB_Availability_Service();
        $this->google_calendar = snab_google_calendar();

        // Register AJAX handlers (both logged-in and logged-out users)
        add_action('wp_ajax_snab_get_availability', array($this, 'get_availability'));
        add_action('wp_ajax_nopriv_snab_get_availability', array($this, 'get_availability'));

        add_action('wp_ajax_snab_get_time_slots', array($this, 'get_time_slots'));
        add_action('wp_ajax_nopriv_snab_get_time_slots', array($this, 'get_time_slots'));

        add_action('wp_ajax_snab_book_appointment', array($this, 'book_appointment'));
        add_action('wp_ajax_nopriv_snab_book_appointment', array($this, 'book_appointment'));
    }

    /**
     * Get availability for a date range.
     *
     * @since 1.0.0
     * @since 1.2.0 Added support for allowed_days, start_hour, end_hour filters.
     */
    public function get_availability() {
        check_ajax_referer('snab_frontend_nonce', 'nonce');

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : null;

        // Parse filter parameters (from preset or shortcode attributes)
        $filters = $this->parse_availability_filters($_POST);

        if (empty($start_date) || empty($end_date)) {
            wp_send_json_error(__('Invalid date range.', 'sn-appointment-booking'));
        }

        // Validate dates
        if (!$this->validate_date($start_date) || !$this->validate_date($end_date)) {
            wp_send_json_error(__('Invalid date format.', 'sn-appointment-booking'));
        }

        // Get available slots with filters
        $slots = $this->availability_service->get_available_slots($start_date, $end_date, $type_id, null, $filters);

        // Format for frontend
        $dates_with_availability = array();
        foreach ($slots as $date => $time_slots) {
            if (!empty($time_slots)) {
                $dates_with_availability[] = $date;
            }
        }

        wp_send_json_success(array(
            'dates' => $dates_with_availability,
            'slots' => $slots,
        ));
    }

    /**
     * Get time slots for a specific date.
     *
     * @since 1.0.0
     * @since 1.2.0 Added support for allowed_days, start_hour, end_hour filters.
     */
    public function get_time_slots() {
        check_ajax_referer('snab_frontend_nonce', 'nonce');

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : null;

        // Parse filter parameters
        $filters = $this->parse_availability_filters($_POST);

        if (empty($date)) {
            wp_send_json_error(__('Date is required.', 'sn-appointment-booking'));
        }

        // Validate date
        if (!$this->validate_date($date)) {
            wp_send_json_error(__('Invalid date format.', 'sn-appointment-booking'));
        }

        // Get slots for this date with filters
        $all_slots = $this->availability_service->get_available_slots($date, $date, $type_id, null, $filters);
        $slots = isset($all_slots[$date]) ? $all_slots[$date] : array();

        // Format slots for display
        $formatted_slots = array();
        foreach ($slots as $time) {
            $formatted = $this->availability_service->format_slot_for_display($time);
            $formatted_slots[] = $formatted;
        }

        wp_send_json_success(array(
            'date' => $date,
            'slots' => $formatted_slots,
        ));
    }

    /**
     * Book an appointment.
     */
    public function book_appointment() {
        // Verify the booking form nonce
        if (!isset($_POST['snab_booking_nonce']) || !wp_verify_nonce($_POST['snab_booking_nonce'], 'snab_book_appointment')) {
            wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'sn-appointment-booking'));
        }

        // Get and sanitize input
        $type_id = isset($_POST['appointment_type_id']) ? absint($_POST['appointment_type_id']) : 0;
        $selected_staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $date = isset($_POST['appointment_date']) ? sanitize_text_field($_POST['appointment_date']) : '';
        $time = isset($_POST['appointment_time']) ? sanitize_text_field($_POST['appointment_time']) : '';
        $client_name = isset($_POST['client_name']) ? sanitize_text_field($_POST['client_name']) : '';
        $client_email = isset($_POST['client_email']) ? sanitize_email($_POST['client_email']) : '';
        $client_phone = isset($_POST['client_phone']) ? sanitize_text_field($_POST['client_phone']) : '';
        $property_address = isset($_POST['property_address']) ? sanitize_text_field($_POST['property_address']) : '';
        $client_notes = isset($_POST['client_notes']) ? sanitize_textarea_field($_POST['client_notes']) : '';

        // Validate required fields
        $errors = array();

        if (empty($type_id)) {
            $errors[] = __('Please select an appointment type.', 'sn-appointment-booking');
        }

        if (empty($date) || !$this->validate_date($date)) {
            $errors[] = __('Please select a valid date.', 'sn-appointment-booking');
        }

        if (empty($time) || !$this->validate_time($time)) {
            $errors[] = __('Please select a valid time.', 'sn-appointment-booking');
        }

        if (empty($client_name)) {
            $errors[] = __('Please enter your name.', 'sn-appointment-booking');
        }

        if (empty($client_email) || !is_email($client_email)) {
            $errors[] = __('Please enter a valid email address.', 'sn-appointment-booking');
        }

        if (!empty($errors)) {
            wp_send_json_error(implode(' ', $errors));
        }

        // Verify the slot is still available
        if (!$this->availability_service->is_slot_available($date, $time, $type_id)) {
            wp_send_json_error(__('Sorry, this time slot is no longer available. Please select another time.', 'sn-appointment-booking'));
        }

        // Get appointment type details
        global $wpdb;
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $appointment_type = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$types_table} WHERE id = %d",
            $type_id
        ));

        if (!$appointment_type) {
            wp_send_json_error(__('Invalid appointment type.', 'sn-appointment-booking'));
        }

        // Get staff ID - use selected staff or fall back to primary
        $staff_table = $wpdb->prefix . 'snab_staff';

        if ($selected_staff_id > 0) {
            // Verify selected staff exists and is active
            $staff_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$staff_table} WHERE id = %d AND is_active = 1",
                $selected_staff_id
            ));

            if (!$staff_id) {
                wp_send_json_error(__('Selected staff member is not available. Please try again.', 'sn-appointment-booking'));
            }
        } else {
            // No staff selected - use primary staff
            $staff_id = $wpdb->get_var(
                "SELECT id FROM {$staff_table} WHERE is_primary = 1 AND is_active = 1 LIMIT 1"
            );
        }

        if (!$staff_id) {
            wp_send_json_error(__('No staff available. Please contact us directly.', 'sn-appointment-booking'));
        }

        // Calculate end time
        $start_datetime = new DateTime($date . ' ' . $time, wp_timezone());
        $end_datetime = clone $start_datetime;
        $end_datetime->modify('+' . $appointment_type->duration_minutes . ' minutes');

        // Create the appointment with transaction handling
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $user_id = is_user_logged_in() ? get_current_user_id() : null;

        // Start transaction to prevent race conditions
        $wpdb->query('START TRANSACTION');

        $result = $wpdb->insert(
            $appointments_table,
            array(
                'staff_id' => $staff_id,
                'appointment_type_id' => $type_id,
                'status' => $appointment_type->requires_approval ? 'pending' : 'confirmed',
                'appointment_date' => $date,
                'start_time' => $start_datetime->format('H:i:s'),
                'end_time' => $end_datetime->format('H:i:s'),
                'user_id' => $user_id,
                'client_name' => $client_name,
                'client_email' => $client_email,
                'client_phone' => $client_phone,
                'property_address' => $property_address,
                'client_notes' => $client_notes,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            $wpdb->query('ROLLBACK');

            // Check if it's a duplicate key error (race condition - slot was taken)
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate entry') !== false || strpos($error, 'unique_slot') !== false) {
                SNAB_Logger::warning('Slot booking race condition detected', array(
                    'type_id' => $type_id,
                    'date' => $date,
                    'time' => $time,
                    'client' => $client_name,
                ));
                wp_send_json_error(__('Sorry, this time slot was just booked by someone else. Please select another time.', 'sn-appointment-booking'));
            }

            SNAB_Logger::error('Failed to create appointment', array(
                'error' => $error,
                'data' => array(
                    'type_id' => $type_id,
                    'date' => $date,
                    'time' => $time,
                    'client' => $client_name,
                ),
            ));
            wp_send_json_error(__('Failed to book appointment. Please try again.', 'sn-appointment-booking'));
        }

        $appointment_id = $wpdb->insert_id;

        // Commit the transaction - appointment is now permanently saved
        $wpdb->query('COMMIT');

        // Get the full appointment record
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$appointments_table} WHERE id = %d",
            $appointment_id
        ));

        // Create Google Calendar event for staff member
        $google_event_id = null;
        if ($this->google_calendar->is_staff_connected($staff_id)) {
            // Build event data in Google Calendar API format
            $timezone = wp_timezone_string();
            $start_datetime = $date . 'T' . $start_datetime->format('H:i:s');
            $end_datetime_str = $date . 'T' . $end_datetime->format('H:i:s');

            $summary = sprintf('%s - %s', $appointment_type->name, $client_name);
            $description_parts = array(
                sprintf('Type: %s', $appointment_type->name),
                sprintf('Client: %s', $client_name),
                sprintf('Email: %s', $client_email),
            );
            if (!empty($client_phone)) {
                $description_parts[] = sprintf('Phone: %s', $client_phone);
            }
            if (!empty($property_address)) {
                $description_parts[] = sprintf('Property: %s', $property_address);
            }

            $google_event_data = array(
                'summary' => $summary,
                'description' => implode("\n", $description_parts),
                'start' => array('dateTime' => $start_datetime, 'timeZone' => $timezone),
                'end' => array('dateTime' => $end_datetime_str, 'timeZone' => $timezone),
            );

            if (!empty($property_address)) {
                $google_event_data['location'] = $property_address;
            }

            $event_result = $this->google_calendar->create_staff_event($staff_id, $google_event_data);

            if (!is_wp_error($event_result) && isset($event_result['id'])) {
                $google_event_id = $event_result['id'];

                // Update appointment with Google event ID
                $wpdb->update(
                    $appointments_table,
                    array(
                        'google_event_id' => $google_event_id,
                        'google_calendar_synced' => 1,
                    ),
                    array('id' => $appointment_id),
                    array('%s', '%d'),
                    array('%d')
                );
            } else {
                SNAB_Logger::warning('Failed to create Google Calendar event', array(
                    'appointment_id' => $appointment_id,
                    'error' => is_wp_error($event_result) ? $event_result->get_error_message() : 'Unknown error',
                ));
            }
        }

        // Send confirmation emails
        $notifications = snab_notifications();
        $notifications->send_client_confirmation($appointment_id);
        $notifications->send_admin_confirmation($appointment_id);

        SNAB_Logger::info('Appointment booked successfully', array(
            'appointment_id' => $appointment_id,
            'type' => $appointment_type->name,
            'date' => $date,
            'time' => $time,
            'client' => $client_name,
            'google_synced' => !empty($google_event_id),
        ));

        // Format response - use helper functions for proper timezone handling
        $response = array(
            'appointment_id' => $appointment_id,
            'status' => $appointment->status,
            'type_name' => $appointment_type->name,
            'type_color' => $appointment_type->color,
            'date' => snab_format_date($date),
            'time' => snab_format_time($date, $time),
            'duration' => $appointment_type->duration_minutes,
            'client_name' => $client_name,
            'client_email' => $client_email,
            'google_synced' => !empty($google_event_id),
        );

        wp_send_json_success($response);
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
        // Accept H:i or H:i:s format
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            $parts = explode(':', $time);
            $hour = (int) $parts[0];
            $minute = (int) $parts[1];
            return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
        }
        return false;
    }

    /**
     * Parse availability filters from request data.
     *
     * @since 1.2.0
     * @param array $data Request data.
     * @return array Filters array for availability service.
     */
    private function parse_availability_filters($data) {
        $filters = array();

        // Parse allowed days (comma-separated string like "0,1,2,3,4,5,6")
        if (!empty($data['allowed_days'])) {
            $days_str = sanitize_text_field($data['allowed_days']);
            if (!empty($days_str)) {
                $filters['allowed_days'] = array_map('intval', array_filter(explode(',', $days_str), 'is_numeric'));
            }
        }

        // Parse start hour
        if (isset($data['start_hour']) && $data['start_hour'] !== '') {
            $start_hour = absint($data['start_hour']);
            if ($start_hour >= 0 && $start_hour <= 23) {
                $filters['start_hour'] = $start_hour;
            }
        }

        // Parse end hour
        if (isset($data['end_hour']) && $data['end_hour'] !== '') {
            $end_hour = absint($data['end_hour']);
            if ($end_hour >= 0 && $end_hour <= 23) {
                $filters['end_hour'] = $end_hour;
            }
        }

        return $filters;
    }
}
