<?php
/**
 * School Geocoder
 *
 * Geocodes school addresses using Nominatim (OpenStreetMap)
 *
 * @package BMN_Schools
 * @since 0.5.1
 */

if (!defined('WPINC')) {
    die;
}

class BMN_Schools_Geocoder {

    /**
     * Nominatim API endpoint
     */
    const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * Rate limit delay in microseconds (1 second per Nominatim policy)
     */
    const RATE_LIMIT_DELAY = 1000000;

    /**
     * Geocode a single address
     *
     * @param string $address Street address
     * @param string $city City name
     * @param string $state State (default MA)
     * @return array|false Array with 'lat' and 'lng' or false on failure
     */
    public static function geocode($address, $city, $state = 'MA') {
        if (empty($address) || empty($city)) {
            return false;
        }

        $full_address = trim("{$address}, {$city}, {$state}, USA");

        $response = wp_remote_get(self::NOMINATIM_URL, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'BMNBoston-Schools/1.0 (https://bmnboston.com)',
            ],
            'body' => [
                'q' => $full_address,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'us',
            ],
        ]);

        if (is_wp_error($response)) {
            BMN_Schools_Logger::log('error', 'geocode', "Geocoding failed for: {$full_address}", [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body[0]['lat'], $body[0]['lon'])) {
            BMN_Schools_Logger::log('warning', 'geocode', "No results for: {$full_address}");
            return false;
        }

        return [
            'lat' => (float) $body[0]['lat'],
            'lng' => (float) $body[0]['lon'],
        ];
    }

    /**
     * Batch geocode schools without coordinates
     *
     * @param int $limit Maximum number of schools to geocode (0 = all)
     * @param callable|null $progress_callback Called with (current, total, school_name)
     * @return array Stats: ['success' => int, 'failed' => int, 'skipped' => int]
     */
    public static function geocode_schools($limit = 100, $progress_callback = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'bmn_schools';

        // Get schools without coordinates
        $sql = "SELECT id, name, address, city, state
                FROM {$table}
                WHERE (latitude IS NULL OR longitude IS NULL)
                AND address IS NOT NULL AND address != ''
                AND city IS NOT NULL AND city != ''";

        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }

        $schools = $wpdb->get_results($sql);

        $stats = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total' => count($schools),
        ];

        foreach ($schools as $i => $school) {
            // Progress callback
            if ($progress_callback) {
                $progress_callback($i + 1, $stats['total'], $school->name);
            }

            // Skip if no address
            if (empty($school->address)) {
                $stats['skipped']++;
                continue;
            }

            // Geocode
            $state = $school->state ?: 'MA';
            $coords = self::geocode($school->address, $school->city, $state);

            if ($coords) {
                // Update database
                $updated = $wpdb->update(
                    $table,
                    [
                        'latitude' => $coords['lat'],
                        'longitude' => $coords['lng'],
                    ],
                    ['id' => $school->id],
                    ['%f', '%f'],
                    ['%d']
                );

                if ($updated !== false) {
                    $stats['success']++;
                    BMN_Schools_Logger::log('info', 'geocode', "Geocoded: {$school->name}", [
                        'lat' => $coords['lat'],
                        'lng' => $coords['lng'],
                    ]);
                } else {
                    $stats['failed']++;
                }
            } else {
                $stats['failed']++;
            }

            // Rate limit (Nominatim requires 1 request per second)
            usleep(self::RATE_LIMIT_DELAY);
        }

        BMN_Schools_Logger::log('info', 'geocode', "Batch geocoding complete", $stats);

        return $stats;
    }

    /**
     * Get count of schools needing geocoding
     *
     * @return int
     */
    public static function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'bmn_schools';

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (latitude IS NULL OR longitude IS NULL)
             AND address IS NOT NULL AND address != ''
             AND city IS NOT NULL AND city != ''"
        );
    }
}
