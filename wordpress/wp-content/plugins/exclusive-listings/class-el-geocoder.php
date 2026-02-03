<?php
/**
 * Geocoding service for exclusive listings
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Geocoder
 *
 * Converts addresses to latitude/longitude coordinates.
 * Supports Nominatim (OpenStreetMap, free) and Google Geocoding API.
 */
class EL_Geocoder {

    /**
     * Nominatim API endpoint (free, rate-limited)
     * @var string
     */
    const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * Google Geocoding API endpoint
     * @var string
     */
    const GOOGLE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Cache expiration (1 week in seconds)
     * @var int
     */
    const CACHE_EXPIRATION = 604800;

    /**
     * Default coordinates (Boston, MA) used when geocoding fails
     * @var array
     */
    const DEFAULT_COORDINATES = array(
        'latitude' => 42.3601,
        'longitude' => -71.0589,
    );

    /**
     * Geocode an address to coordinates
     *
     * @since 1.0.0
     * @since 1.5.3 Added city/zip fallback when full address fails
     * @param array $address Address components (street_number, street_name, city, state_or_province, postal_code)
     * @return array|WP_Error Array with 'latitude' and 'longitude' or WP_Error on failure
     */
    public function geocode($address) {
        // Build full address string
        $address_string = $this->build_address_string($address);

        if (empty($address_string)) {
            return new WP_Error('invalid_address', 'Address is empty or incomplete');
        }

        // Check cache first
        $cache_key = 'el_geocode_' . md5($address_string);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Exclusive Listings Geocoder: Cache hit for '{$address_string}'");
            }
            return $cached;
        }

        $settings = get_option('el_settings', array());
        $provider = $settings['geocoding_provider'] ?? 'nominatim';

        // Try geocoding full address
        if ($provider === 'google' && $this->has_google_api_key()) {
            $result = $this->geocode_with_google($address_string);
        } else {
            $result = $this->geocode_with_nominatim($address_string);
        }

        if (is_wp_error($result)) {
            // Log the error
            error_log('Exclusive Listings Geocoder: ' . $result->get_error_message());

            // Try alternate provider if primary fails
            if ($provider === 'google') {
                $result = $this->geocode_with_nominatim($address_string);
            }
        }

        // If full address failed, try city/zip fallback (v1.5.3)
        if (is_wp_error($result)) {
            $fallback_result = $this->geocode_city_fallback($address, $provider);
            if (!is_wp_error($fallback_result)) {
                $result = $fallback_result;
                error_log("Exclusive Listings Geocoder: Used city/zip fallback for '{$address_string}'");
            }
        }

        // Cache successful result
        if (!is_wp_error($result)) {
            set_transient($cache_key, $result, self::CACHE_EXPIRATION);
        }

        return $result;
    }

    /**
     * Fallback geocoding using just city, state, and postal code
     *
     * When full address geocoding fails (e.g., misspelled street name),
     * this method attempts to geocode using just the city/zip to at least
     * place the pin in the correct town rather than defaulting to Boston.
     *
     * @since 1.5.3
     * @param array $address Address components
     * @param string $provider Preferred geocoding provider
     * @return array|WP_Error Coordinates (with 'approximate' => true) or WP_Error
     */
    private function geocode_city_fallback($address, $provider = 'nominatim') {
        // Need at least city or postal code for fallback
        $city = $address['city'] ?? '';
        $state = $address['state_or_province'] ?? 'MA';
        $postal_code = $address['postal_code'] ?? '';

        if (empty($city) && empty($postal_code)) {
            return new WP_Error('insufficient_fallback_data', 'No city or postal code for fallback geocoding');
        }

        // Build fallback address string (city, state, zip only)
        $fallback_parts = array();
        if (!empty($city)) {
            $fallback_parts[] = $city;
        }
        if (!empty($state)) {
            $fallback_parts[] = $state;
        }
        if (!empty($postal_code)) {
            $fallback_parts[] = $postal_code;
        }
        $fallback_parts[] = 'USA';

        $fallback_string = implode(', ', $fallback_parts);

        // Check fallback cache
        $fallback_cache_key = 'el_geocode_fallback_' . md5($fallback_string);
        $cached = get_transient($fallback_cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Try geocoding the fallback address
        if ($provider === 'google' && $this->has_google_api_key()) {
            $result = $this->geocode_with_google($fallback_string);
        } else {
            $result = $this->geocode_with_nominatim($fallback_string);
        }

        if (!is_wp_error($result)) {
            // Mark as approximate since we only matched city/zip
            $result['approximate'] = true;
            $result['fallback_address'] = $fallback_string;
            set_transient($fallback_cache_key, $result, self::CACHE_EXPIRATION);
        }

        return $result;
    }

    /**
     * Geocode using Nominatim (OpenStreetMap)
     *
     * @since 1.0.0
     * @param string $address Full address string
     * @return array|WP_Error Coordinates or error
     */
    private function geocode_with_nominatim($address) {
        $args = array(
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 0,
        );

        $url = add_query_arg($args, self::NOMINATIM_URL);

        // Nominatim requires a User-Agent header
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'BMNBoston Exclusive Listings Plugin/1.0 (https://bmnboston.com)',
            ),
        ));

        if (is_wp_error($response)) {
            return new WP_Error(
                'nominatim_request_failed',
                'Nominatim API request failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'nominatim_error',
                sprintf('Nominatim API returned status %d', $status_code)
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data) || !isset($data[0])) {
            return new WP_Error(
                'nominatim_no_results',
                sprintf('No geocoding results found for address: %s', $address)
            );
        }

        $result = $data[0];

        if (!isset($result['lat']) || !isset($result['lon'])) {
            return new WP_Error(
                'nominatim_invalid_response',
                'Nominatim response missing coordinates'
            );
        }

        return array(
            'latitude' => floatval($result['lat']),
            'longitude' => floatval($result['lon']),
            'display_name' => isset($result['display_name']) ? $result['display_name'] : null,
            'provider' => 'nominatim',
        );
    }

    /**
     * Geocode using Google Geocoding API
     *
     * @since 1.0.0
     * @param string $address Full address string
     * @return array|WP_Error Coordinates or error
     */
    private function geocode_with_google($address) {
        $api_key = $this->get_google_api_key();

        if (empty($api_key)) {
            return new WP_Error('google_no_api_key', 'Google API key not configured');
        }

        $args = array(
            'address' => $address,
            'key' => $api_key,
        );

        $url = add_query_arg($args, self::GOOGLE_URL);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error(
                'google_request_failed',
                'Google Geocoding API request failed: ' . $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['status'])) {
            return new WP_Error('google_invalid_response', 'Invalid response from Google API');
        }

        if ($data['status'] !== 'OK') {
            $error_messages = array(
                'ZERO_RESULTS' => 'No results found for address',
                'OVER_QUERY_LIMIT' => 'Google API query limit exceeded',
                'REQUEST_DENIED' => 'Google API request denied - check API key',
                'INVALID_REQUEST' => 'Invalid request to Google API',
            );

            $message = isset($error_messages[$data['status']])
                ? $error_messages[$data['status']]
                : 'Google API error: ' . $data['status'];

            return new WP_Error('google_error', $message);
        }

        if (empty($data['results']) || !isset($data['results'][0]['geometry']['location'])) {
            return new WP_Error('google_no_results', 'No location data in Google response');
        }

        $location = $data['results'][0]['geometry']['location'];

        return array(
            'latitude' => floatval($location['lat']),
            'longitude' => floatval($location['lng']),
            'formatted_address' => isset($data['results'][0]['formatted_address'])
                ? $data['results'][0]['formatted_address']
                : null,
            'provider' => 'google',
        );
    }

    /**
     * Build full address string from components
     *
     * @since 1.0.0
     * @param array $address Address components
     * @return string Full address string
     */
    public function build_address_string($address) {
        $parts = array();

        // Street address
        if (!empty($address['street_number']) && !empty($address['street_name'])) {
            $street = $address['street_number'] . ' ' . $address['street_name'];
            if (!empty($address['unit_number'])) {
                $street .= ' ' . $address['unit_number'];
            }
            $parts[] = $street;
        } elseif (!empty($address['street_name'])) {
            $parts[] = $address['street_name'];
        }

        // City
        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }

        // State
        if (!empty($address['state_or_province'])) {
            $parts[] = $address['state_or_province'];
        }

        // Postal code
        if (!empty($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }

        // Country (default to USA)
        $parts[] = 'USA';

        return implode(', ', $parts);
    }

    /**
     * Check if Google API key is configured
     *
     * @since 1.0.0
     * @return bool True if API key exists
     */
    private function has_google_api_key() {
        return !empty($this->get_google_api_key());
    }

    /**
     * Get Google API key from settings or constant
     *
     * @since 1.0.0
     * @return string|null API key or null
     */
    private function get_google_api_key() {
        // Check constant first (for wp-config.php definition)
        if (defined('EL_GOOGLE_API_KEY')) {
            return EL_GOOGLE_API_KEY;
        }

        // Check plugin settings
        $settings = get_option('el_settings', array());
        if (!empty($settings['google_api_key'])) {
            return $settings['google_api_key'];
        }

        // Check MLD plugin settings (reuse existing key)
        $mld_google_key = get_option('mld_google_maps_api_key');
        if (!empty($mld_google_key)) {
            return $mld_google_key;
        }

        return null;
    }

    /**
     * Get default coordinates (Boston, MA)
     *
     * @since 1.0.0
     * @return array Array with 'latitude' and 'longitude'
     */
    public static function get_default_coordinates() {
        return self::DEFAULT_COORDINATES;
    }

    /**
     * Validate that coordinates are within Massachusetts/New England area
     *
     * @since 1.0.0
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @return bool True if coordinates are in valid service area
     */
    public function is_in_service_area($latitude, $longitude) {
        // Bounding box for Massachusetts and surrounding New England area
        // Extended slightly to include nearby states
        $bounds = array(
            'min_lat' => 41.0,   // Southern MA border
            'max_lat' => 43.5,   // Northern border (includes NH, southern ME)
            'min_lng' => -73.5,  // Western border (includes eastern NY)
            'max_lng' => -69.5,  // Eastern border (Atlantic coast)
        );

        return $latitude >= $bounds['min_lat']
            && $latitude <= $bounds['max_lat']
            && $longitude >= $bounds['min_lng']
            && $longitude <= $bounds['max_lng'];
    }

    /**
     * Clear geocoding cache for an address
     *
     * @since 1.0.0
     * @param array $address Address components
     * @return bool True on success
     */
    public function clear_cache($address) {
        $address_string = $this->build_address_string($address);
        $cache_key = 'el_geocode_' . md5($address_string);
        return delete_transient($cache_key);
    }

    /**
     * Format coordinates as MySQL POINT string
     *
     * @since 1.0.0
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @return string POINT string for ST_GeomFromText()
     */
    public static function format_point($latitude, $longitude) {
        // IMPORTANT: MySQL POINT uses (longitude, latitude) order!
        return sprintf('POINT(%f %f)', $longitude, $latitude);
    }

    /**
     * Get diagnostic information about the geocoder
     *
     * @since 1.0.0
     * @return array Diagnostic data
     */
    public function get_diagnostics() {
        $settings = get_option('el_settings', array());

        return array(
            'provider' => $settings['geocoding_provider'] ?? 'nominatim',
            'google_key_configured' => $this->has_google_api_key(),
            'nominatim_url' => self::NOMINATIM_URL,
            'cache_expiration_hours' => self::CACHE_EXPIRATION / 3600,
            'default_coordinates' => self::DEFAULT_COORDINATES,
        );
    }
}
