<?php
/**
 * ICS Calendar File Generator
 *
 * Generates iCalendar (.ics) files for appointments that can be
 * imported into Apple Calendar, Google Calendar, Outlook, etc.
 *
 * @package SN_Appointment_Booking
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ICS Generator class.
 *
 * @since 1.8.0
 */
class SNAB_ICS_Generator {

    /**
     * Generate an ICS file content for an appointment.
     *
     * @param object $appointment Appointment object with all details.
     * @param array  $attendees   Optional array of attendee objects (from snab_appointment_attendees).
     * @return string ICS file content.
     */
    public static function generate($appointment, $attendees = array()) {
        $timezone = wp_timezone();
        $tz_string = $timezone->getName();

        // Parse appointment date/time
        $datetime_str = $appointment->appointment_date . ' ' . $appointment->start_time;
        $start_dt = new DateTime($datetime_str, $timezone);

        // Calculate end time
        $duration_minutes = isset($appointment->duration_minutes) ? (int) $appointment->duration_minutes : 30;
        $end_dt = clone $start_dt;
        $end_dt->add(new DateInterval('PT' . $duration_minutes . 'M'));

        // Format dates for ICS (YYYYMMDDTHHMMSS)
        $dtstart = $start_dt->format('Ymd\THis');
        $dtend = $end_dt->format('Ymd\THis');
        $dtstamp = gmdate('Ymd\THis\Z'); // Current time in UTC

        // Generate unique ID
        $uid = sprintf(
            'appointment-%d-%s@%s',
            $appointment->id,
            $start_dt->format('Ymd'),
            parse_url(home_url(), PHP_URL_HOST)
        );

        // Build summary
        $summary = self::escape_ics_text($appointment->type_name);

        // Build description
        $description_parts = array();
        $description_parts[] = sprintf(__('Appointment Type: %s', 'sn-appointment-booking'), $appointment->type_name);

        if (!empty($appointment->staff_name)) {
            $description_parts[] = sprintf(__('With: %s', 'sn-appointment-booking'), $appointment->staff_name);
        }

        if (!empty($appointment->property_address)) {
            $description_parts[] = sprintf(__('Property: %s', 'sn-appointment-booking'), $appointment->property_address);
        }

        $description_parts[] = '';
        $description_parts[] = sprintf(__('Confirmation #%d', 'sn-appointment-booking'), $appointment->id);
        $description_parts[] = '';
        $description_parts[] = sprintf(__('Booked via %s', 'sn-appointment-booking'), get_bloginfo('name'));

        $description = self::escape_ics_text(implode('\n', $description_parts));

        // Build location
        $location = '';
        if (!empty($appointment->property_address)) {
            $location = self::escape_ics_text($appointment->property_address);
        }

        // Build the ICS content
        $ics = array();
        $ics[] = 'BEGIN:VCALENDAR';
        $ics[] = 'VERSION:2.0';
        $ics[] = 'PRODID:-//BMN Boston//Appointment Booking//EN';
        $ics[] = 'CALSCALE:GREGORIAN';
        $ics[] = 'METHOD:PUBLISH';
        $ics[] = 'X-WR-CALNAME:' . self::escape_ics_text(get_bloginfo('name'));
        $ics[] = 'X-WR-TIMEZONE:' . $tz_string;

        // Timezone definition (important for proper local time display)
        $ics[] = 'BEGIN:VTIMEZONE';
        $ics[] = 'TZID:' . $tz_string;
        $ics[] = 'X-LIC-LOCATION:' . $tz_string;

        // Get timezone transitions for the year
        $year = (int) $start_dt->format('Y');
        $transitions = $timezone->getTransitions(
            mktime(0, 0, 0, 1, 1, $year),
            mktime(23, 59, 59, 12, 31, $year)
        );

        // Add standard/daylight components
        if ($transitions) {
            foreach ($transitions as $i => $trans) {
                if ($i === 0) continue; // Skip the first entry

                $is_dst = $trans['isdst'];
                $component = $is_dst ? 'DAYLIGHT' : 'STANDARD';
                $trans_dt = new DateTime('@' . $trans['ts']);
                $trans_dt->setTimezone($timezone);

                $offset_hours = (int) floor(abs($trans['offset']) / 3600);
                $offset_mins = (int) ((abs($trans['offset']) % 3600) / 60);
                $offset_sign = $trans['offset'] >= 0 ? '+' : '-';
                $offset_str = sprintf('%s%02d%02d', $offset_sign, $offset_hours, $offset_mins);

                // Get previous offset
                $prev_offset = isset($transitions[$i - 1]) ? $transitions[$i - 1]['offset'] : $trans['offset'];
                $prev_hours = (int) floor(abs($prev_offset) / 3600);
                $prev_mins = (int) ((abs($prev_offset) % 3600) / 60);
                $prev_sign = $prev_offset >= 0 ? '+' : '-';
                $prev_str = sprintf('%s%02d%02d', $prev_sign, $prev_hours, $prev_mins);

                $ics[] = 'BEGIN:' . $component;
                $ics[] = 'TZOFFSETFROM:' . $prev_str;
                $ics[] = 'TZOFFSETTO:' . $offset_str;
                $ics[] = 'TZNAME:' . $trans['abbr'];
                $ics[] = 'DTSTART:' . $trans_dt->format('Ymd\THis');
                $ics[] = 'END:' . $component;
            }
        }

        $ics[] = 'END:VTIMEZONE';

        // Event
        $ics[] = 'BEGIN:VEVENT';
        $ics[] = 'UID:' . $uid;
        $ics[] = 'DTSTAMP:' . $dtstamp;
        $ics[] = 'DTSTART;TZID=' . $tz_string . ':' . $dtstart;
        $ics[] = 'DTEND;TZID=' . $tz_string . ':' . $dtend;
        $ics[] = 'SUMMARY:' . $summary;
        $ics[] = 'DESCRIPTION:' . $description;

        if (!empty($location)) {
            $ics[] = 'LOCATION:' . $location;
        }

        // Organizer
        $organizer_name = get_bloginfo('name');
        $organizer_email = get_option('admin_email');
        $ics[] = 'ORGANIZER;CN=' . self::escape_ics_text($organizer_name) . ':mailto:' . $organizer_email;

        // Attendees (v1.10.4: include all attendees - primary, additional, CC)
        if (!empty($attendees)) {
            foreach ($attendees as $att) {
                $att_name = !empty($att->name) ? $att->name : '';
                $ics[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN=' . self::escape_ics_text($att_name) . ':mailto:' . $att->email;
            }
        } elseif (!empty($appointment->client_email)) {
            // Backward compatibility: single primary client
            $attendee_name = !empty($appointment->client_name) ? $appointment->client_name : '';
            $ics[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN=' . self::escape_ics_text($attendee_name) . ':mailto:' . $appointment->client_email;
        }

        // Reminder 1 hour before
        $ics[] = 'BEGIN:VALARM';
        $ics[] = 'TRIGGER:-PT1H';
        $ics[] = 'ACTION:DISPLAY';
        $ics[] = 'DESCRIPTION:' . sprintf(__('Reminder: %s in 1 hour', 'sn-appointment-booking'), $appointment->type_name);
        $ics[] = 'END:VALARM';

        // Reminder 24 hours before
        $ics[] = 'BEGIN:VALARM';
        $ics[] = 'TRIGGER:-P1D';
        $ics[] = 'ACTION:DISPLAY';
        $ics[] = 'DESCRIPTION:' . sprintf(__('Reminder: %s tomorrow', 'sn-appointment-booking'), $appointment->type_name);
        $ics[] = 'END:VALARM';

        $ics[] = 'STATUS:CONFIRMED';
        $ics[] = 'SEQUENCE:0';
        $ics[] = 'END:VEVENT';
        $ics[] = 'END:VCALENDAR';

        return implode("\r\n", $ics);
    }

    /**
     * Generate a cancellation ICS file (METHOD:CANCEL).
     *
     * @param object $appointment Appointment object.
     * @return string ICS file content for cancellation.
     */
    public static function generate_cancellation($appointment) {
        $timezone = wp_timezone();
        $tz_string = $timezone->getName();

        // Parse appointment date/time
        $datetime_str = $appointment->appointment_date . ' ' . $appointment->start_time;
        $start_dt = new DateTime($datetime_str, $timezone);

        // Calculate end time
        $duration_minutes = isset($appointment->duration_minutes) ? (int) $appointment->duration_minutes : 30;
        $end_dt = clone $start_dt;
        $end_dt->add(new DateInterval('PT' . $duration_minutes . 'M'));

        // Format dates
        $dtstart = $start_dt->format('Ymd\THis');
        $dtend = $end_dt->format('Ymd\THis');
        $dtstamp = gmdate('Ymd\THis\Z');

        // Same UID as original
        $uid = sprintf(
            'appointment-%d-%s@%s',
            $appointment->id,
            $start_dt->format('Ymd'),
            parse_url(home_url(), PHP_URL_HOST)
        );

        $summary = self::escape_ics_text($appointment->type_name . ' (Cancelled)');

        $ics = array();
        $ics[] = 'BEGIN:VCALENDAR';
        $ics[] = 'VERSION:2.0';
        $ics[] = 'PRODID:-//BMN Boston//Appointment Booking//EN';
        $ics[] = 'CALSCALE:GREGORIAN';
        $ics[] = 'METHOD:CANCEL';

        $ics[] = 'BEGIN:VEVENT';
        $ics[] = 'UID:' . $uid;
        $ics[] = 'DTSTAMP:' . $dtstamp;
        $ics[] = 'DTSTART;TZID=' . $tz_string . ':' . $dtstart;
        $ics[] = 'DTEND;TZID=' . $tz_string . ':' . $dtend;
        $ics[] = 'SUMMARY:' . $summary;
        $ics[] = 'STATUS:CANCELLED';
        $ics[] = 'SEQUENCE:1';
        $ics[] = 'END:VEVENT';
        $ics[] = 'END:VCALENDAR';

        return implode("\r\n", $ics);
    }

    /**
     * Get the filename for an appointment ICS file.
     *
     * @param object $appointment Appointment object.
     * @param string $type Type of file ('appointment' or 'cancellation').
     * @return string Filename.
     */
    public static function get_filename($appointment, $type = 'appointment') {
        $date = date('Y-m-d', strtotime($appointment->appointment_date));
        $slug = sanitize_title($appointment->type_name);

        if ($type === 'cancellation') {
            return sprintf('%s-%s-cancelled.ics', $slug, $date);
        }

        return sprintf('%s-%s.ics', $slug, $date);
    }

    /**
     * Save ICS content to a temporary file.
     *
     * @param string $content ICS content.
     * @param string $filename Filename.
     * @return string|false Path to temp file or false on failure.
     */
    public static function save_temp_file($content, $filename) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/snab-temp';

        // Create temp directory if it doesn't exist
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);

            // Add .htaccess to prevent direct access
            file_put_contents($temp_dir . '/.htaccess', 'deny from all');
        }

        // Clean up old temp files (older than 1 hour)
        self::cleanup_temp_files($temp_dir);

        $filepath = $temp_dir . '/' . $filename;
        $result = file_put_contents($filepath, $content);

        return $result !== false ? $filepath : false;
    }

    /**
     * Clean up old temporary files.
     *
     * @param string $dir Directory path.
     */
    private static function cleanup_temp_files($dir) {
        $files = glob($dir . '/*.ics');
        $now = time();
        $max_age = HOUR_IN_SECONDS;

        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $max_age) {
                unlink($file);
            }
        }
    }

    /**
     * Escape text for ICS format.
     *
     * @param string $text Text to escape.
     * @return string Escaped text.
     */
    private static function escape_ics_text($text) {
        // Replace actual newlines with ICS newline
        $text = str_replace(array("\r\n", "\r", "\n"), '\n', $text);

        // Escape special characters
        $text = str_replace(array('\\', ';', ','), array('\\\\', '\;', '\,'), $text);

        return $text;
    }
}
