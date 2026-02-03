<?php
/**
 * Timezone Manager Class
 *
 * Handles timezone conversions and management for the Bridge MLS Extractor Pro plugin.
 *
 * Strategy:
 * - All timestamps stored in database as UTC
 * - Display times converted to appropriate timezone:
 *   1. Property timezone (for listing times, open houses)
 *   2. WordPress timezone (for admin/activity logs)
 *   3. User timezone (for user-specific data)
 *
 * @package Bridge_MLS_Extractor_Pro
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BME_Timezone_Manager {

    /**
     * US State to Timezone mapping
     *
     * @var array
     */
    private static $state_timezones = [
        // Eastern Time
        'CT' => 'America/New_York',
        'DE' => 'America/New_York',
        'FL' => 'America/New_York', // Most of FL, panhandle is Central
        'GA' => 'America/New_York',
        'MA' => 'America/New_York',
        'MD' => 'America/New_York',
        'ME' => 'America/New_York',
        'NH' => 'America/New_York',
        'NJ' => 'America/New_York',
        'NY' => 'America/New_York',
        'NC' => 'America/New_York',
        'OH' => 'America/New_York', // Most of OH, western counties Central
        'PA' => 'America/New_York',
        'RI' => 'America/New_York',
        'SC' => 'America/New_York',
        'VT' => 'America/New_York',
        'VA' => 'America/New_York',
        'WV' => 'America/New_York',

        // Central Time
        'AL' => 'America/Chicago',
        'AR' => 'America/Chicago',
        'IA' => 'America/Chicago',
        'IL' => 'America/Chicago',
        'IN' => 'America/Chicago', // Most of IN, eastern counties Eastern
        'KS' => 'America/Chicago', // Most of KS, western counties Mountain
        'KY' => 'America/Chicago', // Western KY, eastern KY is Eastern
        'LA' => 'America/Chicago',
        'MN' => 'America/Chicago',
        'MO' => 'America/Chicago',
        'MS' => 'America/Chicago',
        'ND' => 'America/Chicago', // Eastern ND, western ND Mountain
        'NE' => 'America/Chicago', // Eastern NE, western NE Mountain
        'OK' => 'America/Chicago',
        'SD' => 'America/Chicago', // Eastern SD, western SD Mountain
        'TN' => 'America/Chicago', // Western TN, eastern TN Eastern
        'TX' => 'America/Chicago', // Most of TX, El Paso area Mountain
        'WI' => 'America/Chicago',

        // Mountain Time
        'AZ' => 'America/Phoenix',  // No DST except Navajo Nation
        'CO' => 'America/Denver',
        'ID' => 'America/Denver',   // Southern ID, northern ID Pacific
        'MT' => 'America/Denver',
        'NM' => 'America/Denver',
        'UT' => 'America/Denver',
        'WY' => 'America/Denver',

        // Pacific Time
        'CA' => 'America/Los_Angeles',
        'NV' => 'America/Los_Angeles',
        'OR' => 'America/Los_Angeles', // Most of OR, eastern OR Mountain
        'WA' => 'America/Los_Angeles',

        // Alaska Time
        'AK' => 'America/Anchorage',

        // Hawaii-Aleutian Time
        'HI' => 'Pacific/Honolulu', // No DST
    ];

    /**
     * City/County timezone overrides for states with multiple timezones
     *
     * @var array
     */
    private static $city_timezone_overrides = [
        // Florida panhandle (Central Time)
        'FL' => [
            'Pensacola' => 'America/Chicago',
            'Panama City' => 'America/Chicago',
        ],
        // Indiana (mixed timezones)
        'IN' => [
            'Evansville' => 'America/New_York',
            'Indianapolis' => 'America/New_York',
        ],
        // Texas (El Paso area - Mountain Time)
        'TX' => [
            'El Paso' => 'America/Denver',
        ],
        // Oregon (eastern OR - Mountain Time)
        'OR' => [
            'Ontario' => 'America/Denver',
        ],
        // Idaho (northern ID - Pacific Time)
        'ID' => [
            'Coeur d\'Alene' => 'America/Los_Angeles',
        ],
    ];

    /**
     * Get WordPress configured timezone
     *
     * @return DateTimeZone
     */
    public static function get_wordpress_timezone() {
        $timezone_string = get_option('timezone_string');

        if (!empty($timezone_string)) {
            return new DateTimeZone($timezone_string);
        }

        // Fallback to GMT offset
        $offset = get_option('gmt_offset', 0);
        $hours = (int) $offset;
        $minutes = abs(($offset - $hours) * 60);
        $offset_string = sprintf('%+03d:%02d', $hours, $minutes);

        return new DateTimeZone($offset_string);
    }

    /**
     * Get timezone for a property based on location
     *
     * @param string $state State abbreviation (e.g., 'MA', 'CA')
     * @param string $city City name (optional, for states with multiple timezones)
     * @return DateTimeZone
     */
    public static function get_property_timezone($state, $city = '') {
        $state = strtoupper(trim($state));

        // Check for city-specific timezone override
        if (!empty($city) && isset(self::$city_timezone_overrides[$state])) {
            foreach (self::$city_timezone_overrides[$state] as $override_city => $timezone) {
                if (stripos($city, $override_city) !== false) {
                    return new DateTimeZone($timezone);
                }
            }
        }

        // Use state default timezone
        if (isset(self::$state_timezones[$state])) {
            return new DateTimeZone(self::$state_timezones[$state]);
        }

        // Fallback to WordPress timezone
        return self::get_wordpress_timezone();
    }

    /**
     * Convert datetime to UTC for database storage
     *
     * @param string|DateTime $datetime DateTime in any timezone
     * @param DateTimeZone|string $from_timezone Source timezone
     * @return string UTC datetime in MySQL format (Y-m-d H:i:s)
     */
    public static function convert_to_utc($datetime, $from_timezone = null) {
        if (empty($datetime)) {
            return null;
        }

        // Handle string input
        if (is_string($datetime)) {
            // Check if already in UTC format (ends with Z)
            if (substr($datetime, -1) === 'Z') {
                $dt = new DateTime($datetime);
                return $dt->format('Y-m-d H:i:s');
            }

            $dt = new DateTime($datetime);
        } else {
            $dt = clone $datetime;
        }

        // Set source timezone if provided
        if ($from_timezone) {
            if (is_string($from_timezone)) {
                $from_timezone = new DateTimeZone($from_timezone);
            }
            $dt->setTimezone($from_timezone);
        }

        // Convert to UTC
        $dt->setTimezone(new DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Convert UTC datetime to local timezone for display
     *
     * @param string|DateTime $utc_datetime UTC datetime from database
     * @param DateTimeZone|string $to_timezone Target timezone
     * @param string $format Output format (default: WordPress date/time format)
     * @return string Formatted datetime in local timezone
     */
    public static function convert_to_local($utc_datetime, $to_timezone, $format = null) {
        if (empty($utc_datetime)) {
            return '';
        }

        // Handle string input
        if (is_string($utc_datetime)) {
            $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
        } else {
            $dt = clone $utc_datetime;
            $dt->setTimezone(new DateTimeZone('UTC'));
        }

        // Convert to target timezone
        if (is_string($to_timezone)) {
            $to_timezone = new DateTimeZone($to_timezone);
        }
        $dt->setTimezone($to_timezone);

        // Use WordPress date format if not specified
        if ($format === null) {
            $format = get_option('date_format') . ' ' . get_option('time_format');
        }

        return $dt->format($format);
    }

    /**
     * Convert UTC datetime to WordPress timezone for admin display
     *
     * @param string|DateTime $utc_datetime UTC datetime from database
     * @param string $format Output format
     * @return string Formatted datetime in WordPress timezone
     */
    public static function convert_to_wordpress($utc_datetime, $format = null) {
        return self::convert_to_local($utc_datetime, self::get_wordpress_timezone(), $format);
    }

    /**
     * Get timezone name for display
     *
     * @param DateTimeZone|string $timezone Timezone object or string
     * @return string Timezone abbreviation (e.g., 'EST', 'PST', 'UTC')
     */
    public static function get_timezone_abbr($timezone) {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        $dt = new DateTime('now', $timezone);
        return $dt->format('T');
    }

    /**
     * Check if a timezone observes Daylight Saving Time
     *
     * @param DateTimeZone|string $timezone Timezone to check
     * @return bool True if DST is observed
     */
    public static function observes_dst($timezone) {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        // Timezones that don't observe DST
        $no_dst = ['America/Phoenix', 'Pacific/Honolulu', 'UTC'];
        return !in_array($timezone->getName(), $no_dst);
    }

    /**
     * Format datetime for JSON API output (ISO 8601 with timezone)
     *
     * @param string|DateTime $datetime Datetime to format
     * @param DateTimeZone|string $timezone Timezone for output
     * @return string ISO 8601 formatted datetime
     */
    public static function format_for_api($datetime, $timezone = 'UTC') {
        if (empty($datetime)) {
            return null;
        }

        if (is_string($datetime)) {
            $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        } else {
            $dt = clone $datetime;
        }

        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        $dt->setTimezone($timezone);
        return $dt->format('c'); // ISO 8601 format
    }

    /**
     * Get current UTC timestamp for database inserts
     *
     * @return string Current UTC datetime in MySQL format
     */
    public static function get_utc_now() {
        $dt = new DateTime('now', new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Calculate time difference between two timezones at a specific date
     *
     * @param DateTimeZone|string $tz1 First timezone
     * @param DateTimeZone|string $tz2 Second timezone
     * @param string $date Date to check (for DST variations)
     * @return int Hour difference
     */
    public static function get_timezone_diff($tz1, $tz2, $date = 'now') {
        if (is_string($tz1)) {
            $tz1 = new DateTimeZone($tz1);
        }
        if (is_string($tz2)) {
            $tz2 = new DateTimeZone($tz2);
        }

        $dt1 = new DateTime($date, $tz1);
        $dt2 = new DateTime($date, $tz2);

        return ($dt1->getOffset() - $dt2->getOffset()) / 3600;
    }

    /**
     * Validate timezone string
     *
     * @param string $timezone Timezone string to validate
     * @return bool True if valid
     */
    public static function is_valid_timezone($timezone) {
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get list of all US timezones
     *
     * @return array Timezone identifiers
     */
    public static function get_us_timezones() {
        return array_unique(array_values(self::$state_timezones));
    }

    /**
     * Get timezone for a state (primary timezone)
     *
     * @param string $state State abbreviation
     * @return string|null Timezone identifier or null if not found
     */
    public static function get_state_timezone($state) {
        $state = strtoupper(trim($state));
        return isset(self::$state_timezones[$state]) ? self::$state_timezones[$state] : null;
    }
}
