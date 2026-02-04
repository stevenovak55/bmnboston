<?php
/**
 * Notifications Class
 *
 * Handles all email notifications for appointments.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notifications class.
 *
 * @since 1.0.0
 */
class SNAB_Notifications {

    /**
     * Notification types.
     */
    const TYPE_CONFIRMATION = 'confirmation';
    const TYPE_REMINDER_24H = 'reminder_24h';
    const TYPE_REMINDER_1H = 'reminder_1h';
    const TYPE_CANCELLED = 'cancelled';
    const TYPE_RESCHEDULED = 'rescheduled';
    const TYPE_CLIENT_CANCEL = 'client_cancel';
    const TYPE_CLIENT_RESCHEDULE = 'client_reschedule';

    /**
     * Single instance.
     *
     * @var SNAB_Notifications
     */
    private static $instance = null;

    /**
     * Get single instance.
     *
     * @return SNAB_Notifications
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
        // Register cron hook
        add_action('snab_send_reminders', array($this, 'process_reminders'));

        // Schedule cron if not already scheduled
        // v1.10.2: Use current_time() for WordPress timezone consistency
        if (!wp_next_scheduled('snab_send_reminders')) {
            wp_schedule_event(current_time('timestamp'), 'hourly', 'snab_send_reminders');
        }
    }

    /**
     * Send confirmation email to client (and all attendees).
     *
     * @param int $appointment_id Appointment ID.
     * @return bool
     */
    public function send_client_confirmation($appointment_id) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        // Get all attendees (v1.10.0 multi-attendee support)
        $attendees = $this->get_attendees($appointment_id);

        // Generate ICS attachment (reuse for all attendees)
        $attachments = array();
        $ics_file = $this->generate_ics_attachment($appointment, 'appointment');
        if ($ics_file) {
            $attachments[] = $ics_file;
        }

        $all_sent = true;

        // If we have attendees in the new table, send to all of them
        if (!empty($attendees)) {
            foreach ($attendees as $attendee) {
                // Personalize appointment object for this attendee
                $personalized_appt = clone $appointment;
                $personalized_appt->client_name = $attendee->name;

                // Add other attendees info for multi-attendee notification
                $other_attendees = $this->get_other_attendees_list($attendees, $attendee->email);
                if (!empty($other_attendees)) {
                    $personalized_appt->other_attendees = $other_attendees;
                }

                $subject = $this->parse_template(
                    $this->get_template('confirmation_client_subject'),
                    $personalized_appt
                );

                $message = $this->parse_template(
                    $this->get_template('confirmation_client_body'),
                    $personalized_appt
                );

                // Add other attendees section if applicable
                if (!empty($other_attendees)) {
                    $message .= "\n\n" . sprintf(__('Other attendees: %s', 'sn-appointment-booking'), $other_attendees);
                }

                $sent = $this->send_email(
                    $attendee->email,
                    $subject,
                    $message,
                    $attachments
                );

                $this->log_notification(
                    $appointment_id,
                    self::TYPE_CONFIRMATION,
                    'client',
                    $attendee->email,
                    $subject,
                    $sent
                );

                if (!$sent) {
                    $all_sent = false;
                }
            }
        } else {
            // Fallback: No attendees in new table, send to primary client (backward compatibility)
            $subject = $this->parse_template(
                $this->get_template('confirmation_client_subject'),
                $appointment
            );

            $message = $this->parse_template(
                $this->get_template('confirmation_client_body'),
                $appointment
            );

            $all_sent = $this->send_email(
                $appointment->client_email,
                $subject,
                $message,
                $attachments
            );

            $this->log_notification(
                $appointment_id,
                self::TYPE_CONFIRMATION,
                'client',
                $appointment->client_email,
                $subject,
                $all_sent
            );
        }

        return $all_sent;
    }

    /**
     * Send confirmation email to admin.
     *
     * @param int $appointment_id Appointment ID.
     * @return bool
     */
    public function send_admin_confirmation($appointment_id) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $admin_email = $this->get_admin_email();

        $subject = $this->parse_template(
            $this->get_template('confirmation_admin_subject'),
            $appointment
        );

        $message = $this->parse_template(
            $this->get_template('confirmation_admin_body'),
            $appointment
        );

        $sent = $this->send_email(
            $admin_email,
            $subject,
            $message
        );

        $this->log_notification(
            $appointment_id,
            self::TYPE_CONFIRMATION,
            'admin',
            $admin_email,
            $subject,
            $sent
        );

        return $sent;
    }

    /**
     * Send reminder email to client (and all attendees).
     *
     * @param int $appointment_id Appointment ID.
     * @param string $type Reminder type (reminder_24h or reminder_1h).
     * @return bool
     */
    public function send_reminder($appointment_id, $type = self::TYPE_REMINDER_24H) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $template_key = $type === self::TYPE_REMINDER_1H ? 'reminder_1h' : 'reminder_24h';
        $reminder_column = $type === self::TYPE_REMINDER_1H ? 'reminder_1h_sent' : 'reminder_24h_sent';

        // Generate ICS attachment (reuse for all attendees)
        $attachments = array();
        $ics_file = $this->generate_ics_attachment($appointment, 'appointment');
        if ($ics_file) {
            $attachments[] = $ics_file;
        }

        // Get all attendees (v1.10.0 multi-attendee support)
        $attendees = $this->get_attendees($appointment_id);

        $all_sent = true;

        if (!empty($attendees)) {
            foreach ($attendees as $attendee) {
                // Check if reminder already sent to this attendee
                if ($attendee->$reminder_column) {
                    continue;
                }

                // Personalize for this attendee
                $personalized_appt = clone $appointment;
                $personalized_appt->client_name = $attendee->name;

                $subject = $this->parse_template(
                    $this->get_template($template_key . '_subject'),
                    $personalized_appt
                );

                $message = $this->parse_template(
                    $this->get_template($template_key . '_body'),
                    $personalized_appt
                );

                // Add other attendees info if applicable
                $other_attendees = $this->get_other_attendees_list($attendees, $attendee->email);
                if (!empty($other_attendees)) {
                    $message .= "\n\n" . sprintf(__('Other attendees: %s', 'sn-appointment-booking'), $other_attendees);
                }

                $sent = $this->send_email(
                    $attendee->email,
                    $subject,
                    $message,
                    $attachments
                );

                $this->log_notification(
                    $appointment_id,
                    $type,
                    'client',
                    $attendee->email,
                    $subject,
                    $sent
                );

                // Mark reminder as sent for this attendee
                if ($sent) {
                    $this->mark_attendee_reminder_sent($attendee->id, $type);
                } else {
                    $all_sent = false;
                }
            }
        } else {
            // Fallback: No attendees in new table, send to primary client (backward compatibility)
            $subject = $this->parse_template(
                $this->get_template($template_key . '_subject'),
                $appointment
            );

            $message = $this->parse_template(
                $this->get_template($template_key . '_body'),
                $appointment
            );

            $all_sent = $this->send_email(
                $appointment->client_email,
                $subject,
                $message,
                $attachments
            );

            $this->log_notification(
                $appointment_id,
                $type,
                'client',
                $appointment->client_email,
                $subject,
                $all_sent
            );
        }

        // Update appointment reminder flags (for backward compatibility)
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';
        $column = $type === self::TYPE_REMINDER_1H ? 'reminder_1h_sent' : 'reminder_24h_sent';

        $wpdb->update(
            $table,
            array($column => 1),
            array('id' => $appointment_id),
            array('%d'),
            array('%d')
        );

        return $all_sent;
    }

    /**
     * Send cancellation email to client (and all attendees).
     *
     * @param int $appointment_id Appointment ID.
     * @param string $reason Cancellation reason.
     * @return bool
     */
    public function send_cancellation($appointment_id, $reason = '') {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        // Add reason to appointment object for template
        $appointment->cancellation_reason = $reason;

        // Get all attendees (v1.10.0 multi-attendee support)
        $attendees = $this->get_attendees($appointment_id);

        $all_sent = true;

        if (!empty($attendees)) {
            foreach ($attendees as $attendee) {
                // Personalize for this attendee
                $personalized_appt = clone $appointment;
                $personalized_appt->client_name = $attendee->name;

                $subject = $this->parse_template(
                    $this->get_template('cancellation_subject'),
                    $personalized_appt
                );

                $message = $this->parse_template(
                    $this->get_template('cancellation_body'),
                    $personalized_appt
                );

                $sent = $this->send_email(
                    $attendee->email,
                    $subject,
                    $message
                );

                $this->log_notification(
                    $appointment_id,
                    self::TYPE_CANCELLED,
                    'client',
                    $attendee->email,
                    $subject,
                    $sent
                );

                if (!$sent) {
                    $all_sent = false;
                }
            }
        } else {
            // Fallback: No attendees in new table (backward compatibility)
            $subject = $this->parse_template(
                $this->get_template('cancellation_subject'),
                $appointment
            );

            $message = $this->parse_template(
                $this->get_template('cancellation_body'),
                $appointment
            );

            $all_sent = $this->send_email(
                $appointment->client_email,
                $subject,
                $message
            );

            $this->log_notification(
                $appointment_id,
                self::TYPE_CANCELLED,
                'client',
                $appointment->client_email,
                $subject,
                $all_sent
            );
        }

        return $all_sent;
    }

    /**
     * Send reschedule email to client (and all attendees).
     *
     * @since 1.1.0
     * @param int $appointment_id Appointment ID.
     * @param string $old_date Original appointment date.
     * @param string $old_time Original appointment time.
     * @param string $reason Reschedule reason.
     * @return bool
     */
    public function send_reschedule($appointment_id, $old_date, $old_time, $reason = '') {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        // Add reschedule info to appointment object for template
        $appointment->old_date = $old_date;
        $appointment->old_time = $old_time;
        $appointment->reschedule_reason = $reason;

        // Generate ICS with new appointment time (reuse for all attendees)
        $attachments = array();
        $ics_file = $this->generate_ics_attachment($appointment, 'appointment');
        if ($ics_file) {
            $attachments[] = $ics_file;
        }

        // Get all attendees (v1.10.0 multi-attendee support)
        $attendees = $this->get_attendees($appointment_id);

        $all_sent = true;

        if (!empty($attendees)) {
            foreach ($attendees as $attendee) {
                // Personalize for this attendee
                $personalized_appt = clone $appointment;
                $personalized_appt->client_name = $attendee->name;

                $subject = $this->parse_template(
                    $this->get_template('reschedule_subject'),
                    $personalized_appt
                );

                $message = $this->parse_template(
                    $this->get_template('reschedule_body'),
                    $personalized_appt
                );

                // Add other attendees info if applicable
                $other_attendees = $this->get_other_attendees_list($attendees, $attendee->email);
                if (!empty($other_attendees)) {
                    $message .= "\n\n" . sprintf(__('Other attendees: %s', 'sn-appointment-booking'), $other_attendees);
                }

                $sent = $this->send_email(
                    $attendee->email,
                    $subject,
                    $message,
                    $attachments
                );

                $this->log_notification(
                    $appointment_id,
                    self::TYPE_RESCHEDULED,
                    'client',
                    $attendee->email,
                    $subject,
                    $sent
                );

                if (!$sent) {
                    $all_sent = false;
                }
            }
        } else {
            // Fallback: No attendees in new table (backward compatibility)
            $subject = $this->parse_template(
                $this->get_template('reschedule_subject'),
                $appointment
            );

            $message = $this->parse_template(
                $this->get_template('reschedule_body'),
                $appointment
            );

            $all_sent = $this->send_email(
                $appointment->client_email,
                $subject,
                $message,
                $attachments
            );

            $this->log_notification(
                $appointment_id,
                self::TYPE_RESCHEDULED,
                'client',
                $appointment->client_email,
                $subject,
                $all_sent
            );
        }

        return $all_sent;
    }

    /**
     * Send notification to admin when client cancels their appointment.
     *
     * @since 1.5.0
     * @param int    $appointment_id Appointment ID.
     * @param string $reason         Cancellation reason.
     * @return bool
     */
    public function send_client_cancel_admin_notification($appointment_id, $reason = '') {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $admin_email = $this->get_admin_email();
        $appointment->cancellation_reason = $reason;

        $subject = $this->get_client_portal_template('client_cancel_admin_subject', $appointment);
        $message = $this->get_client_portal_template('client_cancel_admin_body', $appointment);

        $sent = $this->send_email($admin_email, $subject, $message);

        $this->log_notification(
            $appointment_id,
            self::TYPE_CLIENT_CANCEL,
            'admin',
            $admin_email,
            $subject,
            $sent
        );

        return $sent;
    }

    /**
     * Send confirmation to client when they cancel their appointment.
     *
     * @since 1.5.0
     * @param int $appointment_id Appointment ID.
     * @return bool
     */
    public function send_client_cancel_client_notification($appointment_id) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $subject = $this->get_client_portal_template('client_cancel_client_subject', $appointment);
        $message = $this->get_client_portal_template('client_cancel_client_body', $appointment);

        $sent = $this->send_email($appointment->client_email, $subject, $message);

        $this->log_notification(
            $appointment_id,
            self::TYPE_CLIENT_CANCEL,
            'client',
            $appointment->client_email,
            $subject,
            $sent
        );

        return $sent;
    }

    /**
     * Send notification to admin when client reschedules their appointment.
     *
     * @since 1.5.0
     * @param int    $appointment_id Appointment ID.
     * @param string $old_date       Original date.
     * @param string $old_time       Original time.
     * @return bool
     */
    public function send_client_reschedule_admin_notification($appointment_id, $old_date, $old_time) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $admin_email = $this->get_admin_email();
        $appointment->old_date = $old_date;
        $appointment->old_time = $old_time;

        $subject = $this->get_client_portal_template('client_reschedule_admin_subject', $appointment);
        $message = $this->get_client_portal_template('client_reschedule_admin_body', $appointment);

        $sent = $this->send_email($admin_email, $subject, $message);

        $this->log_notification(
            $appointment_id,
            self::TYPE_CLIENT_RESCHEDULE,
            'admin',
            $admin_email,
            $subject,
            $sent
        );

        return $sent;
    }

    /**
     * Send confirmation to client when they reschedule their appointment.
     *
     * @since 1.5.0
     * @param int    $appointment_id Appointment ID.
     * @param string $old_date       Original date.
     * @param string $old_time       Original time.
     * @return bool
     */
    public function send_client_reschedule_client_notification($appointment_id, $old_date, $old_time) {
        $appointment = $this->get_appointment($appointment_id);
        if (!$appointment) {
            return false;
        }

        $appointment->old_date = $old_date;
        $appointment->old_time = $old_time;

        $subject = $this->get_client_portal_template('client_reschedule_client_subject', $appointment);
        $message = $this->get_client_portal_template('client_reschedule_client_body', $appointment);

        // Generate ICS with updated appointment time
        $attachments = array();
        $ics_file = $this->generate_ics_attachment($appointment, 'appointment');
        if ($ics_file) {
            $attachments[] = $ics_file;
        }

        $sent = $this->send_email($appointment->client_email, $subject, $message, $attachments);

        $this->log_notification(
            $appointment_id,
            self::TYPE_CLIENT_RESCHEDULE,
            'client',
            $appointment->client_email,
            $subject,
            $sent
        );

        return $sent;
    }

    /**
     * Get client portal notification template.
     *
     * Templates are stored in wp_options by the update-1.5.0.php migration.
     *
     * @since 1.5.0
     * @param string $template_key Template key (without snab_ prefix).
     * @param object $appointment  Appointment object.
     * @return string Parsed template.
     */
    private function get_client_portal_template($template_key, $appointment) {
        $option_key = 'snab_' . $template_key;
        $template = get_option($option_key, '');

        if (empty($template)) {
            // Fallback defaults
            $defaults = $this->get_client_portal_default_templates();
            $template = isset($defaults[$template_key]) ? $defaults[$template_key] : '';
        }

        return $this->parse_template($template, $appointment);
    }

    /**
     * Get default client portal notification templates.
     *
     * @since 1.5.0
     * @return array
     */
    private function get_client_portal_default_templates() {
        $site_name = get_bloginfo('name');

        return array(
            'client_cancel_admin_subject' => __('Client Cancelled Appointment: {type_name}', 'sn-appointment-booking'),
            'client_cancel_admin_body' => sprintf(
                __('Hello,

A client has cancelled their appointment.

Details:
--------
Client: {client_name}
Email: {client_email}
Type: {type_name}
Date: {date}
Time: {time}

{cancellation_reason_section}

This appointment has been removed from your calendar.

Best regards,
%s', 'sn-appointment-booking'),
                $site_name
            ),

            'client_reschedule_admin_subject' => __('Client Rescheduled Appointment: {type_name}', 'sn-appointment-booking'),
            'client_reschedule_admin_body' => sprintf(
                __('Hello,

A client has rescheduled their appointment.

Client: {client_name} ({client_email})

Original:
---------
Date: {old_date}
Time: {old_time}

New:
----
Date: {date}
Time: {time}

Your calendar has been updated automatically.

Best regards,
%s', 'sn-appointment-booking'),
                $site_name
            ),

            'client_cancel_client_subject' => __('Appointment Cancelled: {type_name}', 'sn-appointment-booking'),
            'client_cancel_client_body' => sprintf(
                __('Hello {client_name},

Your appointment has been successfully cancelled.

Cancelled Appointment:
---------------------
Type: {type_name}
Date: {date}
Time: {time}

If you would like to book a new appointment, please visit our website.

Best regards,
%s', 'sn-appointment-booking'),
                $site_name
            ),

            'client_reschedule_client_subject' => __('Appointment Rescheduled: {type_name}', 'sn-appointment-booking'),
            'client_reschedule_client_body' => sprintf(
                __('Hello {client_name},

Your appointment has been successfully rescheduled.

New Appointment Details:
-----------------------
Type: {type_name}
Date: {date}
Time: {time}

Previous Time:
-------------
Date: {old_date}
Time: {old_time}

We look forward to seeing you!

Best regards,
%s', 'sn-appointment-booking'),
                $site_name
            ),
        );
    }

    /**
     * Process reminder emails (called by cron).
     */
    public function process_reminders() {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        $now = current_time('timestamp');
        $in_24h = $now + (24 * HOUR_IN_SECONDS);
        $in_1h = $now + HOUR_IN_SECONDS;
        $in_2h = $now + (2 * HOUR_IN_SECONDS);

        // Get appointments needing 24-hour reminder
        // (between 23-25 hours from now, not already sent)
        $appointments_24h = $wpdb->get_results($wpdb->prepare(
            "SELECT id, appointment_date, start_time
             FROM {$table}
             WHERE status = 'confirmed'
               AND reminder_24h_sent = 0
               AND CONCAT(appointment_date, ' ', start_time) BETWEEN %s AND %s",
            wp_date('Y-m-d H:i:s', $in_24h - HOUR_IN_SECONDS),
            wp_date('Y-m-d H:i:s', $in_24h + HOUR_IN_SECONDS)
        ));

        foreach ($appointments_24h as $apt) {
            $this->send_reminder($apt->id, self::TYPE_REMINDER_24H);
            SNAB_Logger::info('24-hour reminder sent', array('appointment_id' => $apt->id));
        }

        // Get appointments needing 1-hour reminder
        // (between 1-2 hours from now, not already sent)
        $appointments_1h = $wpdb->get_results($wpdb->prepare(
            "SELECT id, appointment_date, start_time
             FROM {$table}
             WHERE status = 'confirmed'
               AND reminder_1h_sent = 0
               AND CONCAT(appointment_date, ' ', start_time) BETWEEN %s AND %s",
            wp_date('Y-m-d H:i:s', $in_1h),
            wp_date('Y-m-d H:i:s', $in_2h)
        ));

        foreach ($appointments_1h as $apt) {
            $this->send_reminder($apt->id, self::TYPE_REMINDER_1H);
            SNAB_Logger::info('1-hour reminder sent', array('appointment_id' => $apt->id));
        }
    }

    /**
     * Get appointment with full details.
     *
     * @param int $appointment_id Appointment ID.
     * @return object|null
     */
    private function get_appointment($appointment_id) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT a.*,
                    t.name as type_name,
                    t.color as type_color,
                    t.duration_minutes,
                    s.name as staff_name,
                    s.email as staff_email,
                    s.phone as staff_phone
             FROM {$wpdb->prefix}snab_appointments a
             JOIN {$wpdb->prefix}snab_appointment_types t ON a.appointment_type_id = t.id
             JOIN {$wpdb->prefix}snab_staff s ON a.staff_id = s.id
             WHERE a.id = %d",
            $appointment_id
        );

        return $wpdb->get_row($sql);
    }

    /**
     * Get all attendees for an appointment.
     *
     * @since 1.10.0
     * @param int $appointment_id Appointment ID.
     * @return array Array of attendee objects.
     */
    private function get_attendees($appointment_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_attendees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, attendee_type, name, email, phone, reminder_24h_sent, reminder_1h_sent
             FROM {$table}
             WHERE appointment_id = %d
             ORDER BY FIELD(attendee_type, 'primary', 'additional', 'cc'), id ASC",
            $appointment_id
        ));
    }

    /**
     * Update reminder sent status for an attendee.
     *
     * @since 1.10.0
     * @param int $attendee_id Attendee ID.
     * @param string $reminder_type 'reminder_24h' or 'reminder_1h'.
     */
    private function mark_attendee_reminder_sent($attendee_id, $reminder_type) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_attendees';
        $column = $reminder_type === 'reminder_1h' ? 'reminder_1h_sent' : 'reminder_24h_sent';

        $wpdb->update(
            $table,
            array($column => 1),
            array('id' => $attendee_id),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Get other attendees list for email personalization.
     *
     * @since 1.10.0
     * @param array $attendees All attendees.
     * @param string $exclude_email Email to exclude from the list.
     * @return string Formatted list of other attendees.
     */
    private function get_other_attendees_list($attendees, $exclude_email) {
        $others = array();
        foreach ($attendees as $att) {
            if ($att->email !== $exclude_email && $att->attendee_type !== 'cc') {
                $others[] = $att->name;
            }
        }

        if (empty($others)) {
            return '';
        }

        return implode(', ', $others);
    }

    /**
     * Get admin notification email.
     *
     * @return string
     */
    private function get_admin_email() {
        // First try staff email, fall back to site admin
        global $wpdb;
        $staff_email = $wpdb->get_var(
            "SELECT email FROM {$wpdb->prefix}snab_staff WHERE is_primary = 1 LIMIT 1"
        );

        return $staff_email ? $staff_email : get_option('admin_email');
    }

    /**
     * Get email template.
     *
     * @param string $template_key Template key.
     * @return string
     */
    private function get_template($template_key) {
        $templates = $this->get_default_templates();

        // Check for custom templates in options
        $custom_templates = get_option('snab_email_templates', array());
        if (isset($custom_templates[$template_key])) {
            return $custom_templates[$template_key];
        }

        return isset($templates[$template_key]) ? $templates[$template_key] : '';
    }

    /**
     * Get default email templates.
     *
     * @return array
     */
    private function get_default_templates() {
        $site_name = get_bloginfo('name');

        return array(
            // Client Confirmation
            'confirmation_client_subject' => __('Appointment Confirmed: {type_name} on {date}', 'sn-appointment-booking'),
            'confirmation_client_body' => sprintf(
                __('Hello {client_name},

Your appointment has been confirmed!

Appointment Details:
-------------------
Type: {type_name}
Date: {date}
Time: {time}
Duration: {duration} minutes

{property_address_section}

If you need to cancel or reschedule, please contact us.

Thank you,
%s', 'sn-appointment-booking'),
                $site_name
            ),

            // Admin Confirmation
            'confirmation_admin_subject' => __('New Appointment: {type_name} - {client_name}', 'sn-appointment-booking'),
            'confirmation_admin_body' => __('A new appointment has been booked.

Appointment Details:
-------------------
Type: {type_name}
Date: {date}
Time: {time}
Duration: {duration} minutes

Client Information:
------------------
Name: {client_name}
Email: {client_email}
Phone: {client_phone}

{property_address_section}

{client_notes_section}

Google Calendar: {google_status}', 'sn-appointment-booking'),

            // 24-Hour Reminder
            'reminder_24h_subject' => __('Reminder: Your appointment tomorrow - {type_name}', 'sn-appointment-booking'),
            'reminder_24h_body' => sprintf(
                __('Hello {client_name},

This is a reminder about your upcoming appointment tomorrow.

Appointment Details:
-------------------
Type: {type_name}
Date: {date}
Time: {time}

{property_address_section}

We look forward to seeing you!

%s', 'sn-appointment-booking'),
                $site_name
            ),

            // 1-Hour Reminder
            'reminder_1h_subject' => __('Reminder: Your appointment in 1 hour - {type_name}', 'sn-appointment-booking'),
            'reminder_1h_body' => sprintf(
                __('Hello {client_name},

Your appointment is coming up in about 1 hour!

Appointment Details:
-------------------
Type: {type_name}
Date: {date}
Time: {time}

{property_address_section}

See you soon!

%s', 'sn-appointment-booking'),
                $site_name
            ),

            // Cancellation
            'cancellation_subject' => __('Appointment Cancelled: {type_name} on {date}', 'sn-appointment-booking'),
            'cancellation_body' => sprintf(
                __('Hello {client_name},

Your appointment has been cancelled.

Cancelled Appointment:
--------------------
Type: {type_name}
Date: {date}
Time: {time}

{cancellation_reason_section}

If you would like to reschedule, please visit our website.

%s', 'sn-appointment-booking'),
                $site_name
            ),

            // Reschedule
            'reschedule_subject' => __('Appointment Rescheduled: {type_name}', 'sn-appointment-booking'),
            'reschedule_body' => sprintf(
                __('Hello {client_name},

Your appointment has been rescheduled.

Previous Appointment:
-------------------
Date: {old_date}
Time: {old_time}

New Appointment:
--------------
Type: {type_name}
Date: {date}
Time: {time}

{reschedule_reason_section}

If you have any questions or need to make further changes, please contact us.

%s', 'sn-appointment-booking'),
                $site_name
            ),
        );
    }

    /**
     * Parse template with appointment data.
     *
     * @param string $template Template string.
     * @param object $appointment Appointment object.
     * @return string
     */
    private function parse_template($template, $appointment) {
        // IMPORTANT: Create DateTime with WordPress timezone to avoid UTC conversion issues
        // strtotime() without timezone context causes wrong dates/times (e.g., 9am becomes 4am)
        $timezone = wp_timezone();
        $datetime_str = $appointment->appointment_date . ' ' . $appointment->start_time;
        $dt = new DateTime($datetime_str, $timezone);
        $appointment_timestamp = $dt->getTimestamp();

        $date = wp_date(get_option('date_format'), $appointment_timestamp);
        $time = wp_date(get_option('time_format'), $appointment_timestamp);

        $replacements = array(
            '{client_name}' => $appointment->client_name,
            '{client_email}' => $appointment->client_email,
            '{client_phone}' => $appointment->client_phone ?: __('Not provided', 'sn-appointment-booking'),
            '{type_name}' => $appointment->type_name,
            '{date}' => $date,
            '{time}' => $time,
            '{duration}' => $appointment->duration_minutes,
            '{staff_name}' => $appointment->staff_name,
            '{staff_email}' => $appointment->staff_email,
            '{staff_phone}' => $appointment->staff_phone ?: '',
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
        );

        // Property address section
        if (!empty($appointment->property_address)) {
            $replacements['{property_address_section}'] = sprintf(
                __("Property Address:\n%s", 'sn-appointment-booking'),
                $appointment->property_address
            );
        } else {
            $replacements['{property_address_section}'] = '';
        }

        // Client notes section
        if (!empty($appointment->client_notes)) {
            $replacements['{client_notes_section}'] = sprintf(
                __("Client Notes:\n%s", 'sn-appointment-booking'),
                $appointment->client_notes
            );
        } else {
            $replacements['{client_notes_section}'] = '';
        }

        // Cancellation reason section
        if (!empty($appointment->cancellation_reason)) {
            $replacements['{cancellation_reason_section}'] = sprintf(
                __("Reason: %s", 'sn-appointment-booking'),
                $appointment->cancellation_reason
            );
        } else {
            $replacements['{cancellation_reason_section}'] = '';
        }

        // Reschedule variables (for reschedule emails)
        // Format old_date and old_time with proper timezone handling
        if (!empty($appointment->old_date)) {
            $replacements['{old_date}'] = snab_format_date($appointment->old_date);
        } else {
            $replacements['{old_date}'] = '';
        }
        if (!empty($appointment->old_time) && !empty($appointment->old_date)) {
            $replacements['{old_time}'] = snab_format_time($appointment->old_date, $appointment->old_time);
        } else {
            $replacements['{old_time}'] = '';
        }

        // Reschedule reason section
        if (!empty($appointment->reschedule_reason)) {
            $replacements['{reschedule_reason_section}'] = sprintf(
                __("Reason for reschedule: %s", 'sn-appointment-booking'),
                $appointment->reschedule_reason
            );
        } else {
            $replacements['{reschedule_reason_section}'] = '';
        }

        // Google Calendar status
        $replacements['{google_status}'] = $appointment->google_calendar_synced
            ? __('Synced', 'sn-appointment-booking')
            : __('Not synced', 'sn-appointment-booking');

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Send an email.
     *
     * @param string       $to          Recipient email.
     * @param string       $subject     Email subject.
     * @param string       $message     Email body (plain text or HTML content).
     * @param array        $attachments Optional attachments.
     * @param bool         $is_html     Whether message is HTML content (not full HTML doc).
     * @return bool
     */
    private function send_email($to, $subject, $message, $attachments = array(), $is_html = false) {
        // Wrap content in HTML template if it's HTML content
        if ($is_html) {
            $message = $this->wrap_html_email($subject, $message);
            $content_type = 'text/html';
        } else {
            // Convert plain text to HTML for better rendering
            $html_message = $this->plain_text_to_html($message);
            $message = $this->wrap_html_email($subject, $html_message);
            $content_type = 'text/html';
        }

        // Dynamic from address based on recipient (v6.63.0 / SNAB 1.9.5)
        // Clients with assigned agent get email from agent; others from MLD settings
        $from_header = get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
        if (class_exists('MLD_Email_Utilities')) {
            $recipient_user_id = MLD_Email_Utilities::get_user_id_from_email($to);
            $from_header = MLD_Email_Utilities::get_from_header($recipient_user_id);
        }

        $headers = array(
            'Content-Type: ' . $content_type . '; charset=UTF-8',
            'From: ' . $from_header,
        );

        $sent = wp_mail($to, $subject, $message, $headers, $attachments);

        if ($sent) {
            SNAB_Logger::info('Email sent successfully', array(
                'to' => $to,
                'subject' => $subject,
                'has_attachments' => !empty($attachments),
            ));
        } else {
            SNAB_Logger::error('Failed to send email', array(
                'to' => $to,
                'subject' => $subject,
            ));
        }

        // Clean up attachment files
        foreach ($attachments as $file) {
            if (file_exists($file) && strpos($file, 'snab-temp') !== false) {
                @unlink($file);
            }
        }

        return $sent;
    }

    /**
     * Wrap email content in HTML template.
     *
     * @param string $subject Email subject.
     * @param string $content Email content.
     * @param string $footer_text Optional footer text.
     * @return string Full HTML email.
     */
    private function wrap_html_email($subject, $content, $footer_text = '') {
        ob_start();
        include SNAB_PLUGIN_DIR . 'templates/emails/base.php';
        return ob_get_clean();
    }

    /**
     * Convert plain text email to HTML.
     *
     * @param string $text Plain text content.
     * @return string HTML content.
     */
    private function plain_text_to_html($text) {
        $brand_color = '#0891B2';

        // Escape HTML entities
        $html = esc_html($text);

        // Convert section headers (lines ending with :)
        $html = preg_replace(
            '/^([A-Za-z][A-Za-z\s]+):$/m',
            '<h3 style="margin: 25px 0 10px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 600; color: #1a1a1a;">$1</h3>',
            $html
        );

        // Convert dashed separator lines
        $html = preg_replace('/^-+$/m', '<hr style="border: none; border-top: 1px solid #e9ecef; margin: 15px 0;">', $html);

        // Convert URLs to links
        $html = preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" style="color: ' . $brand_color . '; text-decoration: none;">$1</a>',
            $html
        );

        // Convert email addresses to mailto links
        $html = preg_replace(
            '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i',
            '<a href="mailto:$1" style="color: ' . $brand_color . '; text-decoration: none;">$1</a>',
            $html
        );

        // Convert double newlines to paragraph breaks
        $paragraphs = preg_split('/\n{2,}/', $html);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            // Check if it's already a block element
            if (preg_match('/^<(h[1-6]|hr|div)/', $para)) {
                $html .= $para;
            } else {
                // Convert single newlines to <br>
                $para = nl2br($para);
                $html .= '<p style="margin: 0 0 15px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333333;">' . $para . '</p>';
            }
        }

        return $html;
    }

    /**
     * Generate ICS attachment for an appointment.
     *
     * @param object $appointment Appointment object.
     * @param string $type Type of ICS ('appointment' or 'cancellation').
     * @return string|false Path to attachment file or false.
     */
    private function generate_ics_attachment($appointment, $type = 'appointment') {
        if (!class_exists('SNAB_ICS_Generator')) {
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-ics-generator.php';
        }

        if ($type === 'cancellation') {
            $content = SNAB_ICS_Generator::generate_cancellation($appointment);
        } else {
            $content = SNAB_ICS_Generator::generate($appointment);
        }

        $filename = SNAB_ICS_Generator::get_filename($appointment, $type);
        return SNAB_ICS_Generator::save_temp_file($content, $filename);
    }

    /**
     * Log notification to database.
     *
     * @param int $appointment_id Appointment ID.
     * @param string $type Notification type.
     * @param string $recipient_type 'client' or 'admin'.
     * @param string $email Recipient email.
     * @param string $subject Email subject.
     * @param bool $sent Whether email was sent successfully.
     * @param string $error Error message if failed.
     */
    private function log_notification($appointment_id, $type, $recipient_type, $email, $subject, $sent, $error = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_notifications_log';

        $wpdb->insert(
            $table,
            array(
                'appointment_id' => $appointment_id,
                'notification_type' => $type,
                'recipient_type' => $recipient_type,
                'recipient_email' => $email,
                'subject' => $subject,
                'sent_at' => current_time('mysql'),
                'status' => $sent ? 'sent' : 'failed',
                'error_message' => $sent ? null : ($error ?: __('Unknown error', 'sn-appointment-booking')),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get notification log for an appointment.
     *
     * @param int $appointment_id Appointment ID.
     * @return array
     */
    public function get_notification_log($appointment_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_notifications_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE appointment_id = %d ORDER BY sent_at DESC",
            $appointment_id
        ));
    }

    /**
     * Clear scheduled cron on deactivation.
     */
    public static function clear_cron() {
        wp_clear_scheduled_hook('snab_send_reminders');
    }
}

/**
 * Get notifications instance.
 *
 * @return SNAB_Notifications
 */
function snab_notifications() {
    return SNAB_Notifications::instance();
}
