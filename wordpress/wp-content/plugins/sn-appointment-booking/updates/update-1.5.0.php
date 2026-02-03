<?php
/**
 * Update to version 1.5.0
 *
 * Adds client portal support columns.
 * - cancelled_by: Track who cancelled the appointment (client/admin)
 *
 * @package SN_Appointment_Booking
 * @since 1.5.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 1.5.0 update.
 *
 * @return bool Success or failure.
 */
function snab_update_to_1_5_0() {
    global $wpdb;

    $appointments_table = $wpdb->prefix . 'snab_appointments';
    $success = true;

    // Check if cancelled_by column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SHOW COLUMNS FROM {$appointments_table} LIKE %s",
        'cancelled_by'
    ));

    if (empty($column_exists)) {
        // Add cancelled_by column
        $result = $wpdb->query(
            "ALTER TABLE {$appointments_table}
             ADD COLUMN cancelled_by VARCHAR(50) DEFAULT NULL
             COMMENT 'Who cancelled: client or admin'
             AFTER cancellation_reason"
        );

        if ($result === false) {
            SNAB_Logger::error('Failed to add cancelled_by column', array(
                'error' => $wpdb->last_error,
            ));
            $success = false;
        } else {
            SNAB_Logger::info('Added cancelled_by column to appointments table');
        }
    } else {
        SNAB_Logger::info('cancelled_by column already exists');
    }

    // Set default client portal options if not already set
    $portal_options = array(
        'snab_enable_client_portal' => '1',
        'snab_cancellation_hours_before' => '24',
        'snab_reschedule_hours_before' => '24',
        'snab_max_reschedules_per_appointment' => '2',
        'snab_require_cancel_reason' => '1',
        'snab_notify_admin_on_client_changes' => '1',
    );

    foreach ($portal_options as $option_name => $default_value) {
        if (get_option($option_name) === false) {
            add_option($option_name, $default_value);
            SNAB_Logger::info("Set default option: {$option_name} = {$default_value}");
        }
    }

    // Set default notification templates for client portal
    $notification_templates = array(
        'snab_client_cancel_admin_subject' => 'Client Cancelled Appointment: {appointment_type}',
        'snab_client_cancel_admin_body' => "Hello,\n\nA client has cancelled their appointment.\n\n<strong>Details:</strong>\n- Client: {client_name}\n- Email: {client_email}\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\n<strong>Cancellation Reason:</strong>\n{cancellation_reason}\n\nThis appointment has been removed from your calendar.\n\nBest regards,\n{site_name}",
        'snab_client_reschedule_admin_subject' => 'Client Rescheduled Appointment: {appointment_type}',
        'snab_client_reschedule_admin_body' => "Hello,\n\nA client has rescheduled their appointment.\n\n<strong>Client:</strong> {client_name} ({client_email})\n\n<strong>Original:</strong>\n- Date: {old_date}\n- Time: {old_time}\n\n<strong>New:</strong>\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\nYour calendar has been updated automatically.\n\nBest regards,\n{site_name}",
        'snab_client_cancel_client_subject' => 'Appointment Cancelled: {appointment_type}',
        'snab_client_cancel_client_body' => "Hello {client_name},\n\nYour appointment has been successfully cancelled.\n\n<strong>Cancelled Appointment:</strong>\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\nIf you would like to book a new appointment, please visit our website.\n\nBest regards,\n{site_name}",
        'snab_client_reschedule_client_subject' => 'Appointment Rescheduled: {appointment_type}',
        'snab_client_reschedule_client_body' => "Hello {client_name},\n\nYour appointment has been successfully rescheduled.\n\n<strong>New Appointment Details:</strong>\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\n<strong>Previous Time:</strong>\n- Date: {old_date}\n- Time: {old_time}\n\nWe look forward to seeing you!\n\nBest regards,\n{site_name}",
    );

    foreach ($notification_templates as $option_name => $default_value) {
        if (get_option($option_name) === false) {
            add_option($option_name, $default_value);
            SNAB_Logger::info("Set default notification template: {$option_name}");
        }
    }

    return $success;
}
