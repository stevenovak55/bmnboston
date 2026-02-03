<?php
/**
 * Availability Service Class
 *
 * Calculates available time slots by merging manual availability rules
 * with Google Calendar busy times.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Availability Service class.
 *
 * @since 1.0.0
 */
class SNAB_Availability_Service {

    /**
     * Default slot interval in minutes.
     */
    const DEFAULT_SLOT_INTERVAL = 30;

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
        $this->google_calendar = snab_google_calendar();
    }

    /**
     * Get available time slots for a date range.
     *
     * @param string $start_date Start date (Y-m-d format).
     * @param string $end_date End date (Y-m-d format).
     * @param int|null $appointment_type_id Optional appointment type ID.
     * @param int|null $staff_id Optional staff ID (defaults to primary staff).
     * @param array $filters Optional filters: allowed_days (array of 0-6), start_hour (0-23), end_hour (0-23).
     * @return array Array of dates with available slots.
     *
     * @since 1.0.0
     * @since 1.2.0 Added $filters parameter for day/hour filtering.
     */
    public function get_available_slots($start_date, $end_date, $appointment_type_id = null, $staff_id = null, $filters = array()) {
        global $wpdb;

        // Validate dates
        if (empty($start_date) || empty($end_date)) {
            return array();
        }

        // Validate date format
        if (!$this->validate_date_format($start_date) || !$this->validate_date_format($end_date)) {
            return array();
        }

        // Get staff ID (default to primary)
        if (null === $staff_id) {
            $staff_id = $this->get_primary_staff_id();
        }

        if (!$staff_id) {
            return array();
        }

        // Get appointment type details if specified
        $appointment_type = null;
        $duration = 30; // Default duration
        $buffer_before = 0;
        $buffer_after = 15;

        if ($appointment_type_id) {
            $appointment_type = $this->get_appointment_type($appointment_type_id);
            if ($appointment_type) {
                $duration = (int) $appointment_type->duration_minutes;
                $buffer_before = (int) $appointment_type->buffer_before_minutes;
                $buffer_after = (int) $appointment_type->buffer_after_minutes;
            }
        }

        // Get manual availability rules
        $rules = $this->get_availability_rules($staff_id);

        // Get Google Calendar busy times
        $google_busy = $this->get_google_busy_times($start_date, $end_date);

        // Get existing appointments
        $booked_times = $this->get_booked_appointments($staff_id, $start_date, $end_date);

        // Parse filters
        $allowed_days = isset($filters['allowed_days']) && is_array($filters['allowed_days']) ? $filters['allowed_days'] : array();
        $filter_start_hour = isset($filters['start_hour']) && $filters['start_hour'] !== '' ? (int) $filters['start_hour'] : null;
        $filter_end_hour = isset($filters['end_hour']) && $filters['end_hour'] !== '' ? (int) $filters['end_hour'] : null;

        // Build available slots for each date
        $available_slots = array();
        $current = new DateTime($start_date, wp_timezone());
        $end = new DateTime($end_date, wp_timezone());
        $end->modify('+1 day'); // Include end date

        while ($current < $end) {
            $date_str = $current->format('Y-m-d');
            $day_of_week = (int) $current->format('w'); // 0 = Sunday

            // Check if this day is allowed (if filter is set)
            if (!empty($allowed_days) && !in_array($day_of_week, $allowed_days, true)) {
                $current->modify('+1 day');
                continue;
            }

            // Get availability windows for this date
            $windows = $this->get_availability_windows($rules, $date_str, $day_of_week);

            if (!empty($windows)) {
                // Generate slots from windows
                $slots = $this->generate_slots_from_windows(
                    $windows,
                    $date_str,
                    $duration,
                    $buffer_before,
                    $buffer_after
                );

                // Remove slots that conflict with Google Calendar
                $slots = $this->remove_conflicting_slots($slots, $google_busy, $date_str, $duration);

                // Remove slots that conflict with existing appointments
                $slots = $this->remove_booked_slots($slots, $booked_times, $date_str, $duration, $buffer_before, $buffer_after);

                // Remove past slots for today
                if ($date_str === wp_date('Y-m-d')) {
                    $slots = $this->remove_past_slots($slots);
                }

                // Apply hour filters if set
                if ($filter_start_hour !== null || $filter_end_hour !== null) {
                    $slots = $this->filter_slots_by_hour($slots, $filter_start_hour, $filter_end_hour);
                }

                if (!empty($slots)) {
                    $available_slots[$date_str] = $slots;
                }
            }

            $current->modify('+1 day');
        }

        return $available_slots;
    }

    /**
     * Get the primary staff ID.
     *
     * @return int|null
     */
    private function get_primary_staff_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_staff';

        return $wpdb->get_var(
            "SELECT id FROM {$table} WHERE is_primary = 1 AND is_active = 1 LIMIT 1"
        );
    }

    /**
     * Get appointment type by ID.
     *
     * @param int $type_id Appointment type ID.
     * @return object|null
     */
    private function get_appointment_type($type_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_types';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND is_active = 1",
            $type_id
        ));
    }

    /**
     * Get all active appointment types.
     *
     * @return array
     */
    public function get_active_appointment_types() {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointment_types';

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order, name"
        );
    }

    /**
     * Get availability rules for a staff member.
     *
     * @param int $staff_id Staff ID.
     * @return array
     */
    private function get_availability_rules($staff_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_availability_rules';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE staff_id = %d AND is_active = 1",
            $staff_id
        ));
    }

    /**
     * Get availability windows for a specific date.
     *
     * @param array $rules All availability rules.
     * @param string $date Date string (Y-m-d).
     * @param int $day_of_week Day of week (0-6).
     * @return array Array of windows with start_time and end_time.
     */
    private function get_availability_windows($rules, $date, $day_of_week) {
        $windows = array();
        $is_blocked = false;
        $has_specific_date = false;

        foreach ($rules as $rule) {
            // Check for blocked dates first
            if ($rule->rule_type === 'blocked' && $rule->specific_date === $date) {
                // Check if this blocks the entire day or just specific times
                if ($rule->start_time === '00:00:00' && $rule->end_time === '23:59:59') {
                    $is_blocked = true;
                    break;
                }
                // Partial block - we'll handle this later
            }

            // Check for specific date overrides
            if ($rule->rule_type === 'specific_date' && $rule->specific_date === $date) {
                $has_specific_date = true;
                $windows[] = array(
                    'start' => $rule->start_time,
                    'end' => $rule->end_time,
                );
            }
        }

        // If blocked, return empty
        if ($is_blocked) {
            return array();
        }

        // If we have specific date rules, use those instead of recurring
        if ($has_specific_date) {
            return $windows;
        }

        // Otherwise, use recurring rules for this day of week
        foreach ($rules as $rule) {
            if ($rule->rule_type === 'recurring' && (int) $rule->day_of_week === $day_of_week) {
                $windows[] = array(
                    'start' => $rule->start_time,
                    'end' => $rule->end_time,
                );
            }
        }

        // Remove blocked time ranges
        foreach ($rules as $rule) {
            if ($rule->rule_type === 'blocked' && $rule->specific_date === $date) {
                $windows = $this->subtract_time_range($windows, $rule->start_time, $rule->end_time);
            }
        }

        return $windows;
    }

    /**
     * Subtract a time range from availability windows.
     *
     * @param array $windows Current windows.
     * @param string $block_start Block start time.
     * @param string $block_end Block end time.
     * @return array Updated windows.
     */
    private function subtract_time_range($windows, $block_start, $block_end) {
        $result = array();

        foreach ($windows as $window) {
            $w_start = $window['start'];
            $w_end = $window['end'];

            // No overlap - keep window as is
            if ($block_end <= $w_start || $block_start >= $w_end) {
                $result[] = $window;
                continue;
            }

            // Block covers entire window - remove it
            if ($block_start <= $w_start && $block_end >= $w_end) {
                continue;
            }

            // Block is in the middle - split window
            if ($block_start > $w_start && $block_end < $w_end) {
                $result[] = array('start' => $w_start, 'end' => $block_start);
                $result[] = array('start' => $block_end, 'end' => $w_end);
                continue;
            }

            // Block overlaps start
            if ($block_start <= $w_start && $block_end < $w_end) {
                $result[] = array('start' => $block_end, 'end' => $w_end);
                continue;
            }

            // Block overlaps end
            if ($block_start > $w_start && $block_end >= $w_end) {
                $result[] = array('start' => $w_start, 'end' => $block_start);
                continue;
            }
        }

        return $result;
    }

    /**
     * Generate time slots from availability windows.
     *
     * @param array $windows Availability windows.
     * @param string $date Date string.
     * @param int $duration Appointment duration in minutes.
     * @param int $buffer_before Buffer before in minutes.
     * @param int $buffer_after Buffer after in minutes.
     * @return array Array of slot times (H:i format).
     */
    private function generate_slots_from_windows($windows, $date, $duration, $buffer_before, $buffer_after) {
        $slots = array();
        $interval = self::DEFAULT_SLOT_INTERVAL;
        $total_time = $buffer_before + $duration + $buffer_after;

        foreach ($windows as $window) {
            $start = new DateTime($date . ' ' . $window['start'], wp_timezone());
            $end = new DateTime($date . ' ' . $window['end'], wp_timezone());

            // Subtract total appointment time to ensure it fits in window
            $end->modify("-{$total_time} minutes");
            $end->modify("+{$interval} minutes"); // Add back one interval

            while ($start <= $end) {
                $slots[] = $start->format('H:i');
                $start->modify("+{$interval} minutes");
            }
        }

        // Remove duplicates and sort
        $slots = array_unique($slots);
        sort($slots);

        return $slots;
    }

    /**
     * Get Google Calendar busy times for a date range.
     *
     * @param string $start_date Start date.
     * @param string $end_date End date.
     * @return array Array of busy periods.
     */
    private function get_google_busy_times($start_date, $end_date) {
        if (!$this->google_calendar->is_connected()) {
            return array();
        }

        $timezone = wp_timezone_string();
        $start = new DateTime($start_date . ' 00:00:00', wp_timezone());
        $end = new DateTime($end_date . ' 23:59:59', wp_timezone());

        $busy = $this->google_calendar->get_free_busy(
            $start->format('c'),
            $end->format('c')
        );

        if (is_wp_error($busy)) {
            SNAB_Logger::error('Failed to get Google Calendar busy times', array(
                'error' => $busy->get_error_message(),
            ));
            return array();
        }

        return $busy;
    }

    /**
     * Get booked appointments for a date range.
     *
     * @param int $staff_id Staff ID.
     * @param string $start_date Start date.
     * @param string $end_date End date.
     * @return array
     */
    private function get_booked_appointments($staff_id, $start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_appointments';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT appointment_date, start_time, end_time
             FROM {$table}
             WHERE staff_id = %d
               AND appointment_date BETWEEN %s AND %s
               AND status IN ('pending', 'confirmed')
             ORDER BY appointment_date, start_time",
            $staff_id,
            $start_date,
            $end_date
        ));
    }

    /**
     * Remove slots that conflict with Google Calendar busy times.
     *
     * @param array $slots Available slots.
     * @param array $busy_times Google Calendar busy times.
     * @param string $date Date string.
     * @param int $duration Appointment duration.
     * @return array Filtered slots.
     */
    private function remove_conflicting_slots($slots, $busy_times, $date, $duration) {
        if (empty($busy_times)) {
            return $slots;
        }

        $filtered = array();
        $timezone = wp_timezone();

        foreach ($slots as $slot) {
            $slot_start = new DateTime($date . ' ' . $slot, $timezone);
            $slot_end = clone $slot_start;
            $slot_end->modify("+{$duration} minutes");

            $is_available = true;

            foreach ($busy_times as $busy) {
                $busy_start = new DateTime($busy['start']);
                $busy_end = new DateTime($busy['end']);

                // Check for overlap
                if ($slot_start < $busy_end && $slot_end > $busy_start) {
                    $is_available = false;
                    break;
                }
            }

            if ($is_available) {
                $filtered[] = $slot;
            }
        }

        return $filtered;
    }

    /**
     * Remove slots that conflict with existing booked appointments.
     *
     * @param array $slots Available slots.
     * @param array $booked Booked appointments.
     * @param string $date Date string.
     * @param int $duration Appointment duration.
     * @param int $buffer_before Buffer before.
     * @param int $buffer_after Buffer after.
     * @return array Filtered slots.
     */
    private function remove_booked_slots($slots, $booked, $date, $duration, $buffer_before, $buffer_after) {
        if (empty($booked)) {
            return $slots;
        }

        $filtered = array();
        $timezone = wp_timezone();

        foreach ($slots as $slot) {
            $slot_start = new DateTime($date . ' ' . $slot, $timezone);
            $slot_start->modify("-{$buffer_before} minutes");
            $slot_end = clone $slot_start;
            $slot_end->modify("+{$buffer_before} minutes");
            $slot_end->modify("+{$duration} minutes");
            $slot_end->modify("+{$buffer_after} minutes");

            $is_available = true;

            foreach ($booked as $appointment) {
                if ($appointment->appointment_date !== $date) {
                    continue;
                }

                $apt_start = new DateTime($date . ' ' . $appointment->start_time, $timezone);
                $apt_end = new DateTime($date . ' ' . $appointment->end_time, $timezone);

                // Check for overlap (including buffers)
                if ($slot_start < $apt_end && $slot_end > $apt_start) {
                    $is_available = false;
                    break;
                }
            }

            if ($is_available) {
                $filtered[] = $slot;
            }
        }

        return $filtered;
    }

    /**
     * Remove slots that are in the past (for today).
     *
     * @param array $slots Available slots.
     * @return array Filtered slots.
     */
    private function remove_past_slots($slots) {
        $now = current_time('timestamp');
        $min_booking_time = $now + (2 * HOUR_IN_SECONDS); // Require 2 hours advance booking

        $filtered = array();
        $today = wp_date('Y-m-d');

        foreach ($slots as $slot) {
            $slot_time = strtotime($today . ' ' . $slot);
            if ($slot_time >= $min_booking_time) {
                $filtered[] = $slot;
            }
        }

        return $filtered;
    }

    /**
     * Check if a specific slot is available.
     *
     * @param string $date Date (Y-m-d).
     * @param string $time Time (H:i).
     * @param int $appointment_type_id Appointment type ID.
     * @param int|null $staff_id Staff ID.
     * @return bool
     */
    public function is_slot_available($date, $time, $appointment_type_id, $staff_id = null) {
        $slots = $this->get_available_slots($date, $date, $appointment_type_id, $staff_id);

        if (empty($slots[$date])) {
            return false;
        }

        return in_array($time, $slots[$date], true);
    }

    /**
     * Get slot details for display.
     *
     * @param string $time Time in H:i format.
     * @return array Slot details with formatted time.
     */
    public function format_slot_for_display($time) {
        $timestamp = strtotime('2000-01-01 ' . $time);

        return array(
            'value' => $time,
            'label' => wp_date(get_option('time_format'), $timestamp),
        );
    }

    /**
     * Get dates with availability for calendar display.
     *
     * @param string $start_date Start date.
     * @param string $end_date End date.
     * @param int|null $appointment_type_id Appointment type ID.
     * @return array Array of dates that have at least one available slot.
     */
    public function get_dates_with_availability($start_date, $end_date, $appointment_type_id = null) {
        $slots = $this->get_available_slots($start_date, $end_date, $appointment_type_id);
        return array_keys($slots);
    }

    /**
     * Validate date format (Y-m-d).
     *
     * @param string $date Date string.
     * @return bool True if valid, false otherwise.
     */
    private function validate_date_format($date) {
        if (empty($date)) {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Filter slots by hour range.
     *
     * @since 1.2.0
     * @param array $slots Array of slot times (H:i format).
     * @param int|null $start_hour Minimum hour (0-23), or null for no minimum.
     * @param int|null $end_hour Maximum hour (0-23), or null for no maximum.
     * @return array Filtered slots.
     */
    private function filter_slots_by_hour($slots, $start_hour, $end_hour) {
        if ($start_hour === null && $end_hour === null) {
            return $slots;
        }

        $filtered = array();

        foreach ($slots as $slot) {
            // Extract hour from H:i format
            $parts = explode(':', $slot);
            $hour = (int) $parts[0];

            // Check start hour
            if ($start_hour !== null && $hour < $start_hour) {
                continue;
            }

            // Check end hour (slot must START before end_hour)
            if ($end_hour !== null && $hour >= $end_hour) {
                continue;
            }

            $filtered[] = $slot;
        }

        return $filtered;
    }
}
