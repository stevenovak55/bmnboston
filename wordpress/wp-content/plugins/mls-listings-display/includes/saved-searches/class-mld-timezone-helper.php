<?php
/**
 * MLS Listings Display - Timezone Helper
 *
 * Provides consistent timezone handling across the notification system
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Timezone_Helper {

    /**
     * WordPress timezone object
     *
     * @var DateTimeZone
     */
    private static $wp_timezone = null;

    /**
     * Get WordPress timezone object
     *
     * @return DateTimeZone WordPress timezone
     */
    public static function get_wp_timezone() {
        if (self::$wp_timezone === null) {
            $timezone_string = get_option('timezone_string');

            if (empty($timezone_string)) {
                // Fallback to GMT offset
                $gmt_offset = get_option('gmt_offset', 0);
                $timezone_string = timezone_name_from_abbr('', $gmt_offset * 3600, false);

                if ($timezone_string === false) {
                    // Create a custom timezone string for the offset
                    $offset_hours = abs($gmt_offset);
                    $offset_sign = $gmt_offset >= 0 ? '+' : '-';
                    $timezone_string = sprintf('Etc/GMT%s%d', $offset_sign === '+' ? '-' : '+', $offset_hours);
                }
            }

            try {
                self::$wp_timezone = new DateTimeZone($timezone_string);
            } catch (Exception $e) {
                // Fallback to UTC if timezone creation fails
                self::$wp_timezone = new DateTimeZone('UTC');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Timezone Helper: Failed to create timezone object: ' . $e->getMessage());
                }
            }
        }

        return self::$wp_timezone;
    }

    /**
     * Get current time in WordPress timezone
     *
     * @param string $format DateTime format (default: 'Y-m-d H:i:s')
     * @return string Formatted datetime string
     */
    public static function current_time($format = 'Y-m-d H:i:s') {
        $datetime = new DateTime('now', self::get_wp_timezone());
        return $datetime->format($format);
    }

    /**
     * Convert UTC time to WordPress timezone
     *
     * @param string $utc_time UTC datetime string
     * @param string $format Output format (default: 'Y-m-d H:i:s')
     * @return string Datetime in WordPress timezone
     */
    public static function utc_to_wp($utc_time, $format = 'Y-m-d H:i:s') {
        try {
            $datetime = new DateTime($utc_time, new DateTimeZone('UTC'));
            $datetime->setTimezone(self::get_wp_timezone());
            return $datetime->format($format);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Timezone Helper: Failed to convert UTC to WP time: ' . $e->getMessage());
            }
            return $utc_time;
        }
    }

    /**
     * Convert WordPress timezone to UTC
     *
     * @param string $wp_time WordPress timezone datetime string
     * @param string $format Output format (default: 'Y-m-d H:i:s')
     * @return string Datetime in UTC
     */
    public static function wp_to_utc($wp_time, $format = 'Y-m-d H:i:s') {
        try {
            $datetime = new DateTime($wp_time, self::get_wp_timezone());
            $datetime->setTimezone(new DateTimeZone('UTC'));
            return $datetime->format($format);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Timezone Helper: Failed to convert WP to UTC time: ' . $e->getMessage());
            }
            return $wp_time;
        }
    }

    /**
     * Get timestamp for next occurrence of a specific time
     *
     * @param string $time Time string (e.g., '9:00am', '14:30')
     * @param string $day Day string (e.g., 'today', 'tomorrow', 'monday')
     * @return int Unix timestamp
     */
    public static function get_next_occurrence($time, $day = 'today') {
        try {
            $datetime = new DateTime($day . ' ' . $time, self::get_wp_timezone());
            $now = new DateTime('now', self::get_wp_timezone());

            // If the time has already passed today, get tomorrow's occurrence
            if ($day === 'today' && $datetime <= $now) {
                $datetime->modify('+1 day');
            }

            return $datetime->getTimestamp();
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Timezone Helper: Failed to get next occurrence: ' . $e->getMessage());
            }
            // Fallback to strtotime
            return strtotime($day . ' ' . $time);
        }
    }

    /**
     * Calculate time difference from now
     *
     * @param string $datetime Datetime string
     * @param string $unit Unit to return ('seconds', 'minutes', 'hours', 'days')
     * @return int Time difference in specified unit
     */
    public static function time_diff_from_now($datetime, $unit = 'seconds') {
        try {
            $target = new DateTime($datetime, self::get_wp_timezone());
            $now = new DateTime('now', self::get_wp_timezone());
            $diff = $now->getTimestamp() - $target->getTimestamp();

            switch ($unit) {
                case 'minutes':
                    return round($diff / 60);
                case 'hours':
                    return round($diff / 3600);
                case 'days':
                    return round($diff / 86400);
                default:
                    return $diff;
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Timezone Helper: Failed to calculate time difference: ' . $e->getMessage());
            }
            return 0;
        }
    }

    /**
     * Format datetime for display
     *
     * @param string $datetime Datetime string
     * @param string $format PHP date format or 'human' for human-readable
     * @return string Formatted datetime
     */
    public static function format_datetime($datetime, $format = 'human') {
        if ($format === 'human') {
            // Use WordPress human_time_diff
            $timestamp = strtotime($datetime);
            if ($timestamp > current_time('timestamp')) {
                return sprintf('in %s', human_time_diff(current_time('timestamp'), $timestamp));
            } else {
                return sprintf('%s ago', human_time_diff($timestamp, current_time('timestamp')));
            }
        }

        try {
            $dt = new DateTime($datetime, self::get_wp_timezone());
            return $dt->format($format);
        } catch (Exception $e) {
            return $datetime;
        }
    }

    /**
     * Validate and standardize datetime string
     *
     * @param string $datetime Input datetime string
     * @return string|false Standardized datetime string or false on failure
     */
    public static function validate_datetime($datetime) {
        try {
            $dt = new DateTime($datetime, self::get_wp_timezone());
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get timezone offset in hours
     *
     * @return float Timezone offset in hours
     */
    public static function get_timezone_offset() {
        $datetime = new DateTime('now', self::get_wp_timezone());
        return $datetime->getOffset() / 3600;
    }

    /**
     * Get timezone abbreviation
     *
     * @return string Timezone abbreviation (e.g., 'EST', 'PDT')
     */
    public static function get_timezone_abbr() {
        $datetime = new DateTime('now', self::get_wp_timezone());
        return $datetime->format('T');
    }

    /**
     * Debug timezone information
     *
     * @return array Timezone debug information
     */
    public static function debug_info() {
        return [
            'wp_timezone_string' => get_option('timezone_string'),
            'wp_gmt_offset' => get_option('gmt_offset'),
            'timezone_object' => self::get_wp_timezone()->getName(),
            'current_wp_time' => self::current_time(),
            'current_utc_time' => gmdate('Y-m-d H:i:s'),
            'timezone_offset_hours' => self::get_timezone_offset(),
            'timezone_abbreviation' => self::get_timezone_abbr(),
            'php_default_timezone' => date_default_timezone_get(),
            'server_time' => date('Y-m-d H:i:s')
        ];
    }
}