<?php
/**
 * MLD Device Detector
 *
 * Parses user agent strings to detect device type, browser, and OS information.
 * Lightweight implementation without external dependencies.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.39.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Public_Device_Detector
 *
 * Detects device type, browser, and operating system from user agent strings.
 * Note: Named differently from MLD_Device_Detector to avoid class name conflicts.
 */
class MLD_Public_Device_Detector {

    /**
     * Singleton instance
     *
     * @var MLD_Public_Device_Detector
     */
    private static $instance = null;

    /**
     * Cached detection results
     *
     * @var array
     */
    private $cache = array();

    /**
     * Known bot patterns
     *
     * @var array
     */
    private $bot_patterns = array(
        'googlebot',
        'bingbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'whatsapp',
        'telegrambot',
        'applebot',
        'semrush',
        'ahrefs',
        'mj12bot',
        'dotbot',
        'petalbot',
        'bytespider',
        'gptbot',
        'claudebot',
        'anthropic',
        'crawler',
        'spider',
        'bot/',
        'bot-',
        'headless',
        'phantomjs',
        'selenium',
        'puppeteer',
        'playwright',
        'scraper',
        'wget',
        'curl/',
        'python-requests',
        'axios/',
        'go-http-client',
        'java/',
        'apache-httpclient',
        'http_client',
        'monitoring',
        'pingdom',
        'uptime',
        'newrelic',
        'datadog',
        'gtmetrix',
        'lighthouse',
        'pagespeed',
    );

    /**
     * Get singleton instance
     *
     * @return MLD_Public_Device_Detector
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {}

    /**
     * Detect device information from user agent
     *
     * @param string|null $user_agent User agent string (default: $_SERVER)
     * @return array Device information
     */
    public function detect($user_agent = null) {
        if ($user_agent === null) {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        }

        // Check cache
        $cache_key = md5($user_agent);
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }

        $ua_lower = strtolower($user_agent);

        $result = array(
            'user_agent'      => $user_agent,
            'is_bot'          => $this->is_bot($ua_lower),
            'device_type'     => $this->detect_device_type($ua_lower),
            'browser'         => $this->detect_browser($user_agent),
            'browser_version' => $this->detect_browser_version($user_agent),
            'os'              => $this->detect_os($user_agent),
            'os_version'      => $this->detect_os_version($user_agent),
            'is_mobile'       => false,
            'is_tablet'       => false,
            'is_desktop'      => false,
        );

        // Set convenience flags
        $result['is_mobile'] = $result['device_type'] === 'mobile';
        $result['is_tablet'] = $result['device_type'] === 'tablet';
        $result['is_desktop'] = $result['device_type'] === 'desktop';

        // Cache result
        $this->cache[$cache_key] = $result;

        // Limit cache size
        if (count($this->cache) > 100) {
            array_shift($this->cache);
        }

        return $result;
    }

    /**
     * Check if user agent is a bot
     *
     * @param string $ua_lower Lowercase user agent
     * @return bool
     */
    public function is_bot($ua_lower) {
        foreach ($this->bot_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                return true;
            }
        }

        // Check for empty or suspicious UAs
        if (empty($ua_lower) || strlen($ua_lower) < 20) {
            return true;
        }

        // Check for missing typical browser indicators
        if (
            strpos($ua_lower, 'mozilla') === false &&
            strpos($ua_lower, 'opera') === false &&
            strpos($ua_lower, 'bmnboston') === false // Our iOS app
        ) {
            return true;
        }

        return false;
    }

    /**
     * Detect device type
     *
     * @param string $ua_lower Lowercase user agent
     * @return string 'desktop', 'mobile', or 'tablet'
     */
    public function detect_device_type($ua_lower) {
        // Check for tablets first (before mobile, as tablets may contain "mobile")
        $tablets = array(
            'ipad',
            'tablet',
            'playbook',
            'silk',
            'kindle',
            'sm-t',      // Samsung tablets
            'gt-p',      // Samsung tablets
            'surface',
            'tab ',      // Generic tablet indicator
        );

        foreach ($tablets as $tablet) {
            if (strpos($ua_lower, $tablet) !== false) {
                return 'tablet';
            }
        }

        // Check for mobile devices
        $mobiles = array(
            'iphone',
            'ipod',
            'android',
            'mobile',
            'blackberry',
            'opera mini',
            'opera mobi',
            'iemobile',
            'windows phone',
            'phone',
            'symbian',
            'palm',
            'webos',
        );

        foreach ($mobiles as $mobile) {
            if (strpos($ua_lower, $mobile) !== false) {
                // Android tablets often contain "android" but not "mobile"
                if ($mobile === 'android' && strpos($ua_lower, 'mobile') === false) {
                    return 'tablet';
                }
                return 'mobile';
            }
        }

        return 'desktop';
    }

    /**
     * Detect browser name
     *
     * @param string $user_agent User agent string
     * @return string Browser name
     */
    public function detect_browser($user_agent) {
        // Order matters - check more specific patterns first

        // Our iOS app
        if (stripos($user_agent, 'BMNBoston') !== false) {
            return 'BMNBoston App';
        }

        // Edge (Chromium-based)
        if (stripos($user_agent, 'Edg/') !== false || stripos($user_agent, 'Edge/') !== false) {
            return 'Edge';
        }

        // Opera
        if (stripos($user_agent, 'OPR/') !== false || stripos($user_agent, 'Opera') !== false) {
            return 'Opera';
        }

        // Samsung Browser
        if (stripos($user_agent, 'SamsungBrowser') !== false) {
            return 'Samsung Browser';
        }

        // Chrome (must check before Safari due to UA overlap)
        if (stripos($user_agent, 'Chrome/') !== false && stripos($user_agent, 'Chromium') === false) {
            return 'Chrome';
        }

        // Firefox
        if (stripos($user_agent, 'Firefox/') !== false) {
            return 'Firefox';
        }

        // Safari (must check after Chrome)
        if (stripos($user_agent, 'Safari/') !== false && stripos($user_agent, 'Chrome') === false) {
            return 'Safari';
        }

        // Internet Explorer
        if (stripos($user_agent, 'MSIE') !== false || stripos($user_agent, 'Trident/') !== false) {
            return 'Internet Explorer';
        }

        return 'Unknown';
    }

    /**
     * Detect browser version
     *
     * @param string $user_agent User agent string
     * @return string|null Browser version
     */
    public function detect_browser_version($user_agent) {
        $browser = $this->detect_browser($user_agent);
        $version = null;

        switch ($browser) {
            case 'BMNBoston App':
                if (preg_match('/BMNBoston\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Edge':
                if (preg_match('/Edg\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                } elseif (preg_match('/Edge\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Chrome':
                if (preg_match('/Chrome\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Firefox':
                if (preg_match('/Firefox\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Safari':
                if (preg_match('/Version\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Opera':
                if (preg_match('/OPR\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                } elseif (preg_match('/Opera\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Samsung Browser':
                if (preg_match('/SamsungBrowser\/(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Internet Explorer':
                if (preg_match('/MSIE\s(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                } elseif (preg_match('/rv:(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;
        }

        return $version;
    }

    /**
     * Detect operating system
     *
     * @param string $user_agent User agent string
     * @return string OS name
     */
    public function detect_os($user_agent) {
        $ua_lower = strtolower($user_agent);

        // iOS
        if (strpos($ua_lower, 'iphone') !== false || strpos($ua_lower, 'ipad') !== false || strpos($ua_lower, 'ipod') !== false) {
            return 'iOS';
        }

        // macOS
        if (strpos($ua_lower, 'macintosh') !== false || strpos($ua_lower, 'mac os') !== false) {
            return 'macOS';
        }

        // Windows
        if (strpos($ua_lower, 'windows') !== false) {
            if (strpos($ua_lower, 'windows phone') !== false) {
                return 'Windows Phone';
            }
            return 'Windows';
        }

        // Android
        if (strpos($ua_lower, 'android') !== false) {
            return 'Android';
        }

        // Linux
        if (strpos($ua_lower, 'linux') !== false) {
            if (strpos($ua_lower, 'ubuntu') !== false) {
                return 'Ubuntu';
            }
            if (strpos($ua_lower, 'fedora') !== false) {
                return 'Fedora';
            }
            return 'Linux';
        }

        // Chrome OS
        if (strpos($ua_lower, 'cros') !== false) {
            return 'Chrome OS';
        }

        return 'Unknown';
    }

    /**
     * Detect OS version
     *
     * @param string $user_agent User agent string
     * @return string|null OS version
     */
    public function detect_os_version($user_agent) {
        $os = $this->detect_os($user_agent);
        $version = null;

        switch ($os) {
            case 'iOS':
                // iOS version is like: CPU iPhone OS 15_0 like Mac OS X
                if (preg_match('/OS\s(\d+[_\d]*)/i', $user_agent, $matches)) {
                    $version = str_replace('_', '.', $matches[1]);
                }
                break;

            case 'macOS':
                // macOS version is like: Mac OS X 10_15_7
                if (preg_match('/Mac OS X\s(\d+[_\d\.]*)/i', $user_agent, $matches)) {
                    $version = str_replace('_', '.', $matches[1]);
                }
                break;

            case 'Windows':
                // Windows version
                if (preg_match('/Windows NT\s(\d+\.\d+)/i', $user_agent, $matches)) {
                    $nt_versions = array(
                        '10.0' => '10/11',
                        '6.3'  => '8.1',
                        '6.2'  => '8',
                        '6.1'  => '7',
                        '6.0'  => 'Vista',
                        '5.1'  => 'XP',
                    );
                    $version = isset($nt_versions[$matches[1]]) ? $nt_versions[$matches[1]] : $matches[1];
                }
                break;

            case 'Android':
                if (preg_match('/Android\s(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;

            case 'Chrome OS':
                if (preg_match('/CrOS\s\w+\s(\d+(?:\.\d+)?)/i', $user_agent, $matches)) {
                    $version = $matches[1];
                }
                break;
        }

        return $version;
    }

    /**
     * Get platform string for analytics
     *
     * @param string|null $user_agent User agent string
     * @return string Platform identifier
     */
    public function get_platform($user_agent = null) {
        $detection = $this->detect($user_agent);

        // Check for our iOS app
        if ($detection['browser'] === 'BMNBoston App') {
            return 'ios_app';
        }

        // Return device-specific platform
        switch ($detection['device_type']) {
            case 'mobile':
                return 'web_mobile';
            case 'tablet':
                return 'web_tablet';
            default:
                return 'web_desktop';
        }
    }

    /**
     * Get full device info as associative array
     *
     * @param string|null $user_agent User agent string
     * @return array Device info for database storage
     */
    public function get_device_info($user_agent = null) {
        $detection = $this->detect($user_agent);

        return array(
            'platform'        => $this->get_platform($user_agent),
            'device_type'     => $detection['device_type'],
            'browser'         => $detection['browser'],
            'browser_version' => $detection['browser_version'],
            'os'              => $detection['os'],
            'os_version'      => $detection['os_version'],
            'is_bot'          => $detection['is_bot'] ? 1 : 0,
        );
    }

    /**
     * Clear cache
     */
    public function clear_cache() {
        $this->cache = array();
    }
}
