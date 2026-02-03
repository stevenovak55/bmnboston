<?php
/**
 * MLD Geolocation Service
 *
 * Provides IP-to-location lookup using MaxMind GeoLite2 database.
 * Falls back to ip-api.com for development/testing if database not available.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Geolocation_Service
 *
 * Handles geolocation lookups with caching.
 */
class MLD_Geolocation_Service {

    /**
     * Singleton instance
     *
     * @var MLD_Geolocation_Service
     */
    private static $instance = null;

    /**
     * Path to GeoLite2 database file
     *
     * @var string
     */
    private $database_path;

    /**
     * MaxMind Reader instance
     *
     * @var object|null
     */
    private $reader = null;

    /**
     * Whether database is available
     *
     * @var bool
     */
    private $database_available = false;

    /**
     * In-memory cache for lookups
     *
     * @var array
     */
    private $cache = array();

    /**
     * Cache transient prefix
     */
    const CACHE_PREFIX = 'mld_geo_';

    /**
     * Cache duration in seconds (1 hour)
     */
    const CACHE_DURATION = 3600;

    /**
     * Private IPs that should not be looked up
     *
     * @var array
     */
    private $private_ranges = array(
        '10.',
        '172.16.',
        '172.17.',
        '172.18.',
        '172.19.',
        '172.20.',
        '172.21.',
        '172.22.',
        '172.23.',
        '172.24.',
        '172.25.',
        '172.26.',
        '172.27.',
        '172.28.',
        '172.29.',
        '172.30.',
        '172.31.',
        '192.168.',
        '127.',
        '::1',
        'fe80:',
    );

    /**
     * Get singleton instance
     *
     * @return MLD_Geolocation_Service
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->database_path = MLD_PLUGIN_DIR . 'data/GeoLite2-City.mmdb';
        $this->init_database();
    }

    /**
     * Initialize the GeoLite2 database
     */
    private function init_database() {
        if (!file_exists($this->database_path)) {
            $this->database_available = false;
            return;
        }

        // Try to load the MaxMind reader
        // Using native PHP reader (no Composer dependency)
        try {
            require_once MLD_PLUGIN_DIR . 'includes/analytics/public/lib/maxmind-db/Reader.php';
            $this->reader = new MaxMind\Db\Reader($this->database_path);
            $this->database_available = true;
        } catch (Exception $e) {
            error_log('MLD Geolocation: Failed to load MaxMind database: ' . $e->getMessage());
            $this->database_available = false;
        }
    }

    /**
     * Check if the geolocation database is available
     *
     * @return bool
     */
    public function is_available() {
        return $this->database_available;
    }

    /**
     * Get location data for an IP address
     *
     * @param string|null $ip IP address (default: current visitor)
     * @return array Location data
     */
    public function get_location($ip = null) {
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }

        // Return empty for private/local IPs
        if ($this->is_private_ip($ip)) {
            return $this->get_empty_location();
        }

        // Check in-memory cache
        if (isset($this->cache[$ip])) {
            return $this->cache[$ip];
        }

        // Check transient cache
        $cached = get_transient(self::CACHE_PREFIX . md5($ip));
        if ($cached !== false) {
            $this->cache[$ip] = $cached;
            return $cached;
        }

        // Perform lookup
        $location = $this->lookup_ip($ip);

        // Cache result
        $this->cache[$ip] = $location;
        set_transient(self::CACHE_PREFIX . md5($ip), $location, self::CACHE_DURATION);

        return $location;
    }

    /**
     * Perform IP lookup
     *
     * @param string $ip IP address
     * @return array Location data
     */
    private function lookup_ip($ip) {
        // Try MaxMind database first
        if ($this->database_available && $this->reader) {
            $location = $this->lookup_maxmind($ip);
            if (!empty($location['country_code'])) {
                return $location;
            }
        }

        // Fall back to ip-api.com (free tier: 45 requests/minute)
        return $this->lookup_ipapi($ip);
    }

    /**
     * Lookup IP using MaxMind GeoLite2 database
     *
     * @param string $ip IP address
     * @return array Location data
     */
    private function lookup_maxmind($ip) {
        try {
            $record = $this->reader->get($ip);

            if (!$record) {
                return $this->get_empty_location();
            }

            return array(
                'ip_address'   => $ip,
                'country_code' => $record['country']['iso_code'] ?? null,
                'country_name' => $record['country']['names']['en'] ?? null,
                'region'       => $record['subdivisions'][0]['names']['en'] ?? null,
                'city'         => $record['city']['names']['en'] ?? null,
                'latitude'     => $record['location']['latitude'] ?? null,
                'longitude'    => $record['location']['longitude'] ?? null,
                'timezone'     => $record['location']['time_zone'] ?? null,
                'source'       => 'maxmind',
            );
        } catch (Exception $e) {
            error_log('MLD Geolocation: MaxMind lookup failed: ' . $e->getMessage());
            return $this->get_empty_location();
        }
    }

    /**
     * Lookup IP using ip-api.com (fallback)
     *
     * @param string $ip IP address
     * @return array Location data
     */
    private function lookup_ipapi($ip) {
        $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,lat,lon,timezone";

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            error_log('MLD Geolocation: ip-api.com request failed: ' . $response->get_error_message());
            return $this->get_empty_location();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || ($data['status'] ?? '') !== 'success') {
            return $this->get_empty_location();
        }

        return array(
            'ip_address'   => $ip,
            'country_code' => $data['countryCode'] ?? null,
            'country_name' => $data['country'] ?? null,
            'region'       => $data['regionName'] ?? null,
            'city'         => $data['city'] ?? null,
            'latitude'     => $data['lat'] ?? null,
            'longitude'    => $data['lon'] ?? null,
            'timezone'     => $data['timezone'] ?? null,
            'source'       => 'ip-api',
        );
    }

    /**
     * Get empty location array
     *
     * @return array Empty location data
     */
    private function get_empty_location() {
        return array(
            'ip_address'   => null,
            'country_code' => null,
            'country_name' => null,
            'region'       => null,
            'city'         => null,
            'latitude'     => null,
            'longitude'    => null,
            'timezone'     => null,
            'source'       => null,
        );
    }

    /**
     * Get client IP address
     *
     * Checks multiple headers in priority order to handle various CDN/proxy setups.
     * v6.46.0: Added support for Kinsta CDN and additional proxy headers.
     *
     * @return string IP address
     */
    public function get_client_ip() {
        $ip = '';
        $source = '';

        // Priority order of headers to check
        // Each CDN/proxy may use different headers
        $headers_to_check = array(
            'HTTP_CF_CONNECTING_IP'        => 'Cloudflare',
            'HTTP_TRUE_CLIENT_IP'          => 'Akamai/CDN',
            'HTTP_X_REAL_IP'               => 'Nginx/Kinsta',
            'HTTP_X_CLIENT_IP'             => 'Proxy',
            'HTTP_X_FORWARDED_FOR'         => 'X-Forwarded-For',
            'HTTP_X_ORIGINAL_FORWARDED_FOR' => 'Original-Forwarded',
            'HTTP_FORWARDED'               => 'RFC7239',
            'REMOTE_ADDR'                  => 'Direct',
        );

        foreach ($headers_to_check as $header => $header_source) {
            if (!empty($_SERVER[$header])) {
                $header_value = $_SERVER[$header];

                // Handle comma-separated list (take first IP)
                if (strpos($header_value, ',') !== false) {
                    $ips = explode(',', $header_value);
                    $header_value = trim($ips[0]);
                }

                // Handle RFC7239 Forwarded header format: for=192.0.2.60;proto=http
                if ($header === 'HTTP_FORWARDED' && preg_match('/for=([^;,\s]+)/i', $header_value, $matches)) {
                    $header_value = trim($matches[1], '"[]');
                }

                // Validate IP
                $validated_ip = filter_var($header_value, FILTER_VALIDATE_IP);
                if ($validated_ip) {
                    // Skip if it's a private/internal IP and we have more headers to check
                    if ($this->is_private_ip($validated_ip) && $header !== 'REMOTE_ADDR') {
                        continue; // Try next header
                    }
                    $ip = $validated_ip;
                    $source = $header_source;
                    break;
                }
            }
        }

        // Debug logging for failed IP detection (only in debug mode)
        if (empty($ip) && defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_headers = array();
            foreach (array_keys($headers_to_check) as $h) {
                if (isset($_SERVER[$h])) {
                    $debug_headers[$h] = $_SERVER[$h];
                }
            }
            error_log('MLD Analytics: IP detection failed. Checked headers: ' . wp_json_encode($debug_headers));
        }

        return $ip ? $ip : '';
    }

    /**
     * Check if IP is private/local
     *
     * @param string $ip IP address
     * @return bool
     */
    private function is_private_ip($ip) {
        if (empty($ip)) {
            return true;
        }

        // Check common private ranges
        foreach ($this->private_ranges as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }

        // Use PHP's filter for comprehensive check
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Get database status information
     *
     * @return array Status information
     */
    public function get_status() {
        $status = array(
            'database_available' => $this->database_available,
            'database_path'      => $this->database_path,
            'database_exists'    => file_exists($this->database_path),
            'fallback_available' => true, // ip-api.com always available
        );

        if ($status['database_exists']) {
            $status['database_size'] = size_format(filesize($this->database_path));
            $status['database_modified'] = date('Y-m-d H:i:s', filemtime($this->database_path));
        }

        return $status;
    }

    /**
     * Clear location cache
     *
     * @param string|null $ip Specific IP to clear, or null for all
     */
    public function clear_cache($ip = null) {
        if ($ip !== null) {
            unset($this->cache[$ip]);
            delete_transient(self::CACHE_PREFIX . md5($ip));
        } else {
            $this->cache = array();
            // Note: Clearing all transients would require a database query
            // Individual transients will expire naturally
        }
    }

    /**
     * Download GeoLite2 database
     *
     * Requires MaxMind license key (free registration at maxmind.com)
     *
     * @param string $license_key MaxMind license key
     * @return bool|WP_Error Success or error
     */
    public function download_database($license_key) {
        if (empty($license_key)) {
            return new WP_Error('missing_key', 'MaxMind license key is required');
        }

        $url = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
            urlencode($license_key)
        );

        // Download the file
        $temp_file = download_url($url, 300);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Create data directory if needed
        $data_dir = dirname($this->database_path);
        if (!file_exists($data_dir)) {
            wp_mkdir_p($data_dir);
        }

        // Extract the database file
        $result = $this->extract_mmdb($temp_file);

        // Cleanup temp file
        @unlink($temp_file);

        if (is_wp_error($result)) {
            return $result;
        }

        // Reinitialize the database
        $this->init_database();

        return true;
    }

    /**
     * Extract MMDB file from tar.gz archive
     *
     * @param string $archive_path Path to tar.gz archive
     * @return bool|WP_Error Success or error
     */
    private function extract_mmdb($archive_path) {
        // Use PharData for extraction
        try {
            $phar = new PharData($archive_path);
            $phar->decompress();

            $tar_path = str_replace('.gz', '', $archive_path);
            $tar = new PharData($tar_path);

            // Find the .mmdb file in the archive
            $mmdb_found = false;
            foreach ($tar as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'mmdb') {
                    // Copy to destination
                    copy($file, $this->database_path);
                    $mmdb_found = true;
                    break;
                }
            }

            // Cleanup tar file
            @unlink($tar_path);

            if (!$mmdb_found) {
                return new WP_Error('extract_failed', 'Could not find MMDB file in archive');
            }

            return true;
        } catch (Exception $e) {
            return new WP_Error('extract_error', $e->getMessage());
        }
    }

    /**
     * Get last database update time
     *
     * @return string|null DateTime string or null
     */
    public function get_database_date() {
        if (!file_exists($this->database_path)) {
            return null;
        }
        return date('Y-m-d H:i:s', filemtime($this->database_path));
    }

    /**
     * Close the database reader
     */
    public function __destruct() {
        if ($this->reader) {
            try {
                $this->reader->close();
            } catch (Exception $e) {
                // Ignore errors on close
            }
        }
    }
}
