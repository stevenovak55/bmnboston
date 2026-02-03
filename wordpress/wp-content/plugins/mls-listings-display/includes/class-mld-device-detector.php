<?php
/**
 * Device Detection Class
 *
 * Handles device detection, user preferences, and device capabilities
 *
 * @package MLS_Listings_Display
 * @since 2.0.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * MLD_Device_Detector Class
 *
 * Singleton class for detecting device types and managing user preferences
 */
class MLD_Device_Detector {
    
    /**
     * Instance of this class
     *
     * @var MLD_Device_Detector
     */
    private static $instance = null;
    
    /**
     * User agent string
     *
     * @var string
     */
    private $user_agent;
    
    /**
     * Parsed device info cache
     *
     * @var array
     */
    private $device_info_cache = null;
    
    /**
     * Cookie name for version preference
     *
     * @var string
     */
    const VERSION_COOKIE = 'mld_version_preference';
    
    /**
     * Cookie expiration time (30 days)
     *
     * @var int
     */
    const COOKIE_EXPIRATION = 2592000; // 30 days in seconds
    
    /**
     * Debug mode flag
     *
     * @var bool
     */
    private $debug_mode = false;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $this->debug_mode = defined('MLD_DEVICE_DEBUG') && MLD_DEVICE_DEBUG;
        
        // Initialize hooks
        add_action('init', array($this, 'handle_version_switch'));
    }
    
    /**
     * Get singleton instance
     *
     * @return MLD_Device_Detector
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Detect if device is mobile (phone or tablet)
     *
     * @return bool
     */
    public function is_mobile() {
        // Check user preference first
        $preference = $this->get_version_preference();
        if ($preference === 'desktop') {
            return false;
        }
        if ($preference === 'mobile') {
            return true;
        }
        
        // Debug mode override
        if ($this->debug_mode && isset($_GET['device'])) {
            $device = sanitize_text_field($_GET['device']);
            return in_array($device, array('mobile', 'phone', 'tablet'));
        }
        
        // Use device detection
        return $this->is_phone() || $this->is_tablet();
    }
    
    /**
     * Detect if device is a tablet
     *
     * @return bool
     */
    public function is_tablet() {
        // Debug mode override
        if ($this->debug_mode && isset($_GET['device'])) {
            return sanitize_text_field($_GET['device']) === 'tablet';
        }
        
        $info = $this->get_device_info();
        return $info['is_tablet'];
    }
    
    /**
     * Detect if device is a phone
     *
     * @return bool
     */
    public function is_phone() {
        // Debug mode override
        if ($this->debug_mode && isset($_GET['device'])) {
            return sanitize_text_field($_GET['device']) === 'phone';
        }
        
        $info = $this->get_device_info();
        return $info['is_phone'];
    }
    
    /**
     * Get device type string
     *
     * @return string 'desktop', 'tablet', or 'phone'
     */
    public function get_device_type() {
        if ($this->is_phone()) {
            return 'phone';
        }
        if ($this->is_tablet()) {
            return 'tablet';
        }
        return 'desktop';
    }
    
    /**
     * Determine if mobile version should be used
     *
     * @return bool
     */
    public function should_use_mobile_version() {
        // First, handle any version switch requests
        $this->handle_version_switch();
        
        // Check for user preference
        $preference = $this->get_version_preference();
        if ($preference === 'mobile') {
            return true;
        }
        if ($preference === 'desktop') {
            return false;
        }
        
        // Check if forced via URL parameter (for testing)
        if (isset($_GET['mld_version'])) {
            return sanitize_text_field($_GET['mld_version']) === 'mobile';
        }
        
        // Default to device detection
        return $this->is_mobile();
    }
    
    /**
     * Get user's version preference
     *
     * @return string|null 'mobile', 'desktop', or null
     */
    public function get_version_preference() {
        if (isset($_COOKIE[self::VERSION_COOKIE])) {
            $preference = sanitize_text_field($_COOKIE[self::VERSION_COOKIE]);
            if (in_array($preference, array('mobile', 'desktop'))) {
                return $preference;
            }
        }
        return null;
    }
    
    /**
     * Set user's version preference
     *
     * @param string $version 'mobile' or 'desktop'
     * @return bool
     */
    public function set_version_preference($version) {
        if (!in_array($version, array('mobile', 'desktop', 'auto'))) {
            return false;
        }
        
        if ($version === 'auto') {
            // Remove cookie to use auto-detection
            setcookie(self::VERSION_COOKIE, '', time() - 3600, '/', '', is_ssl(), true);
        } else {
            // Set cookie
            setcookie(self::VERSION_COOKIE, $version, time() + self::COOKIE_EXPIRATION, '/', '', is_ssl(), true);
        }
        
        // Update current request
        $_COOKIE[self::VERSION_COOKIE] = ($version === 'auto') ? null : $version;
        
        return true;
    }
    
    /**
     * Handle version switch requests
     */
    public function handle_version_switch() {
        if (isset($_GET['mld_version'])) {
            $version = sanitize_text_field($_GET['mld_version']);
            
            // Don't set cookie preference if just testing with URL parameter
            // Only set preference and redirect if it's a deliberate switch (not testing)
            $is_design_mode = isset($_GET['design']) && sanitize_text_field($_GET['design']) === '1';
            $is_test_mode = isset($_GET['test']) && sanitize_text_field($_GET['test']) === '1';
            if (!$is_design_mode && !$is_test_mode) {
                $this->set_version_preference($version);
                
                // Redirect to remove parameter
                $redirect_url = remove_query_arg('mld_version');
                wp_safe_redirect($redirect_url);
                exit;
            }
        }
    }
    
    /**
     * Get detailed user agent information
     *
     * @return array
     */
    public function get_user_agent_info() {
        $info = $this->get_device_info();
        
        return array(
            'user_agent' => $this->user_agent,
            'device_type' => $this->get_device_type(),
            'is_mobile' => $this->is_mobile(),
            'is_tablet' => $this->is_tablet(),
            'is_phone' => $this->is_phone(),
            'is_touch' => $info['is_touch'],
            'is_bot' => $info['is_bot'],
            'browser' => $info['browser'],
            'browser_version' => $info['browser_version'],
            'os' => $info['os'],
            'os_version' => $info['os_version'],
            'device_name' => $info['device_name'],
            'preference' => $this->get_version_preference(),
            'using_mobile_version' => $this->should_use_mobile_version()
        );
    }
    
    /**
     * Detect if device has touch capability
     *
     * @return bool
     */
    public function is_touch_device() {
        $info = $this->get_device_info();
        return $info['is_touch'];
    }
    
    /**
     * Detect if user agent is a bot/crawler
     *
     * @return bool
     */
    public function is_bot() {
        $info = $this->get_device_info();
        return $info['is_bot'];
    }
    
    /**
     * Get device info with caching
     *
     * @return array
     */
    private function get_device_info() {
        if ($this->device_info_cache !== null) {
            return $this->device_info_cache;
        }
        
        $info = array(
            'is_phone' => false,
            'is_tablet' => false,
            'is_touch' => false,
            'is_bot' => false,
            'browser' => 'Unknown',
            'browser_version' => '',
            'os' => 'Unknown',
            'os_version' => '',
            'device_name' => 'Unknown'
        );
        
        if (empty($this->user_agent)) {
            $this->device_info_cache = $info;
            return $info;
        }
        
        // Detect bots
        $bot_patterns = array(
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'facebookexternalhit', 'WhatsApp', 'Slack', 'Twitter',
            'LinkedIn', 'Google', 'Bing', 'Yahoo', 'DuckDuck'
        );
        foreach ($bot_patterns as $pattern) {
            if (stripos($this->user_agent, $pattern) !== false) {
                $info['is_bot'] = true;
                break;
            }
        }
        
        // Detect mobile devices
        $phone_patterns = array(
            'iPhone' => 'iPhone',
            'iPod' => 'iPod',
            'Android.*Mobile' => 'Android Phone',
            'Windows Phone' => 'Windows Phone',
            'BlackBerry' => 'BlackBerry',
            'BB10' => 'BlackBerry 10',
            'Opera Mini' => 'Opera Mini',
            'IEMobile' => 'IE Mobile',
            'Mobile.*Firefox' => 'Firefox Mobile',
            'Nokia' => 'Nokia'
        );
        
        foreach ($phone_patterns as $pattern => $device) {
            if (preg_match('/' . $pattern . '/i', $this->user_agent)) {
                $info['is_phone'] = true;
                $info['device_name'] = $device;
                break;
            }
        }
        
        // Detect tablets
        $tablet_patterns = array(
            'iPad' => 'iPad',
            'Android(?!.*Mobile)' => 'Android Tablet',
            'Tablet' => 'Generic Tablet',
            'Kindle' => 'Kindle',
            'Silk' => 'Kindle Fire',
            'PlayBook' => 'BlackBerry PlayBook'
        );
        
        if (!$info['is_phone']) {
            foreach ($tablet_patterns as $pattern => $device) {
                if (preg_match('/' . $pattern . '/i', $this->user_agent)) {
                    $info['is_tablet'] = true;
                    $info['device_name'] = $device;
                    break;
                }
            }
        }
        
        // Detect touch capability (most mobile devices and some laptops)
        if ($info['is_phone'] || $info['is_tablet']) {
            $info['is_touch'] = true;
        } elseif (preg_match('/Touch/i', $this->user_agent)) {
            $info['is_touch'] = true;
        }
        
        // Detect browser
        if (preg_match('/Firefox\/([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['browser'] = 'Firefox';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Chrome\/([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['browser'] = 'Chrome';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/i', $this->user_agent, $matches) && !preg_match('/Chrome/i', $this->user_agent)) {
            $info['browser'] = 'Safari';
            if (preg_match('/Version\/([0-9.]+)/i', $this->user_agent, $version_matches)) {
                $info['browser_version'] = $version_matches[1];
            }
        } elseif (preg_match('/Edge\/([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['browser'] = 'Edge';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE ([0-9.]+)/i', $this->user_agent, $matches) || preg_match('/Trident.*rv:([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['browser'] = 'Internet Explorer';
            $info['browser_version'] = $matches[1];
        }
        
        // Detect OS
        if (preg_match('/Windows NT ([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['os'] = 'Windows';
            $windows_versions = array(
                '10.0' => '10',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
                '6.0' => 'Vista',
                '5.1' => 'XP'
            );
            $info['os_version'] = isset($windows_versions[$matches[1]]) ? $windows_versions[$matches[1]] : $matches[1];
        } elseif (preg_match('/Mac OS X ([0-9_]+)/i', $this->user_agent, $matches)) {
            $info['os'] = 'macOS';
            $info['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPhone OS ([0-9_]+)/i', $this->user_agent, $matches)) {
            $info['os'] = 'iOS';
            $info['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android ([0-9.]+)/i', $this->user_agent, $matches)) {
            $info['os'] = 'Android';
            $info['os_version'] = $matches[1];
        } elseif (preg_match('/Linux/i', $this->user_agent)) {
            $info['os'] = 'Linux';
        }
        
        // Fallback to WordPress detection if our detection fails
        if (!$info['is_phone'] && !$info['is_tablet'] && !$info['is_bot']) {
            if (function_exists('wp_is_mobile') && wp_is_mobile()) {
                $info['is_phone'] = true;
                $info['is_touch'] = true;
                $info['device_name'] = 'Mobile Device';
            }
        }
        
        $this->device_info_cache = $info;
        return $info;
    }
    
    /**
     * Get debug information
     *
     * @return array
     */
    public function get_debug_info() {
        if (!$this->debug_mode) {
            return array('error' => 'Debug mode not enabled');
        }
        
        return array(
            'debug_mode' => true,
            'debug_device' => isset($_GET['device']) ? $_GET['device'] : null,
            'user_agent' => $this->user_agent,
            'device_info' => $this->get_user_agent_info(),
            'wordpress_mobile' => function_exists('wp_is_mobile') ? wp_is_mobile() : null,
            'cookie_preference' => isset($_COOKIE[self::VERSION_COOKIE]) ? $_COOKIE[self::VERSION_COOKIE] : null,
            'final_decision' => $this->should_use_mobile_version() ? 'mobile' : 'desktop'
        );
    }
    
    /**
     * Add device class to body classes
     *
     * @param array $classes
     * @return array
     */
    public function add_body_classes($classes) {
        $classes[] = 'mld-device-' . $this->get_device_type();
        
        if ($this->is_touch_device()) {
            $classes[] = 'mld-touch';
        }
        
        if ($this->should_use_mobile_version()) {
            $classes[] = 'mld-using-mobile';
        }
        
        $preference = $this->get_version_preference();
        if ($preference) {
            $classes[] = 'mld-preference-' . $preference;
        }
        
        return $classes;
    }
    
    /**
     * Get version switcher HTML
     *
     * @return string
     */
    public function get_version_switcher_html() {
        $current_version = $this->should_use_mobile_version() ? 'mobile' : 'desktop';
        $switch_to = $current_version === 'mobile' ? 'desktop' : 'mobile';
        $switch_text = $current_version === 'mobile' ? 'Desktop Version' : 'Mobile Version';
        
        $switch_url = add_query_arg('mld_version', $switch_to);
        
        $html = '<div class="mld-version-switcher">';
        $html .= '<a href="' . esc_url($switch_url) . '" class="mld-version-switch-link">';
        $html .= esc_html($switch_text);
        $html .= '</a>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get asset suffix based on device type
     *
     * @return string
     */
    public function get_asset_suffix() {
        if ($this->should_use_mobile_version()) {
            return '-mobile';
        }
        return '';
    }
    
    /**
     * Get template suffix based on device type
     *
     * @return string
     */
    public function get_template_suffix() {
        if ($this->should_use_mobile_version()) {
            return '-mobile';
        }
        return '';
    }
    
    /**
     * Check if current view is desktop
     *
     * @return bool
     */
    public function is_desktop_view() {
        return !$this->should_use_mobile_version();
    }
    
    /**
     * Check if current view is mobile
     *
     * @return bool
     */
    public function is_mobile_view() {
        return $this->should_use_mobile_version();
    }
    
    /**
     * Get view mode (mobile or desktop)
     *
     * @return string
     */
    public function get_view_mode() {
        return $this->should_use_mobile_version() ? 'mobile' : 'desktop';
    }
    
    /**
     * Get view switcher links
     *
     * @return array
     */
    public function get_view_switcher_links() {
        $current_url = remove_query_arg('mld_version');
        
        return array(
            'mobile' => add_query_arg('mld_version', 'mobile', $current_url),
            'desktop' => add_query_arg('mld_version', 'desktop', $current_url),
            'auto' => add_query_arg('mld_version', 'auto', $current_url)
        );
    }
    
    /**
     * Get user preference
     *
     * @return string|null
     */
    public function get_user_preference() {
        return $this->get_version_preference();
    }
    
    /**
     * Get JavaScript data for frontend
     *
     * @return array
     */
    public function get_js_data() {
        return array(
            'device_type' => $this->get_device_type(),
            'is_mobile' => $this->is_mobile(),
            'is_tablet' => $this->is_tablet(),
            'is_phone' => $this->is_phone(),
            'is_touch' => $this->is_touch_device(),
            'view_mode' => $this->get_view_mode(),
            'user_preference' => $this->get_user_preference()
        );
    }
}