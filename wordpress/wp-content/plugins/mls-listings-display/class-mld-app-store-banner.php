<?php
/**
 * App Store Banner for iOS Users
 *
 * Displays a smart app banner for iOS Safari users, encouraging
 * them to download the BMN Boston app.
 *
 * @package MLS_Listings_Display
 * @since 6.61.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MLD_App_Store_Banner Class
 */
class MLD_App_Store_Banner {

    /**
     * App Store URL
     */
    const APP_STORE_URL = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';

    /**
     * Cookie name for dismissal tracking
     */
    const COOKIE_NAME = 'mld_app_banner_dismissed';

    /**
     * Days to remember dismissal
     */
    const COOKIE_DAYS = 30;

    /**
     * Instance of this class
     *
     * @var MLD_App_Store_Banner
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MLD_App_Store_Banner
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
        // Only run on frontend
        if (is_admin()) {
            return;
        }

        add_action('wp_footer', array($this, 'render_banner'), 100);
        add_action('wp_footer', array($this, 'render_desktop_footer'), 99);
    }

    /**
     * Check if banner should be displayed
     *
     * @return bool
     */
    public function should_show_banner() {
        // Check if dismissed via cookie
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        // Get user agent
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        // Only show on iOS devices (iPhone/iPad in Safari)
        $is_ios = (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false);
        if (!$is_ios) {
            return false;
        }

        // Skip if in iOS app WebView (app includes custom identifier)
        if (stripos($ua, 'BMNBoston') !== false) {
            return false;
        }

        // Skip if in any WebView (common WebView indicators)
        if (stripos($ua, 'FBAN') !== false || stripos($ua, 'FBAV') !== false) {
            return false; // Facebook in-app browser
        }

        return true;
    }

    /**
     * Render the banner HTML and assets
     */
    public function render_banner() {
        if (!$this->should_show_banner()) {
            return;
        }

        $app_store_url = self::APP_STORE_URL;
        ?>
        <style>
        .mld-app-banner {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            padding: 10px 12px;
            background: #f8f8f8;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif;
            transform: translateY(-100%);
            transition: transform 0.3s ease;
        }
        .mld-app-banner.mld-banner-visible {
            transform: translateY(0);
        }
        .mld-app-banner-close {
            background: none;
            border: none;
            padding: 8px;
            color: #8e8e93;
            cursor: pointer;
            margin-right: 8px;
            line-height: 1;
        }
        .mld-app-banner-close svg {
            display: block;
        }
        .mld-app-banner-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            margin-right: 12px;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .mld-app-banner-icon svg {
            width: 32px;
            height: 32px;
            fill: white;
        }
        .mld-app-banner-text {
            flex: 1;
            min-width: 0;
        }
        .mld-app-banner-title {
            font-weight: 600;
            font-size: 15px;
            color: #1c1c1e;
            margin: 0 0 2px 0;
        }
        .mld-app-banner-subtitle {
            font-size: 13px;
            color: #8e8e93;
            margin: 0;
        }
        .mld-app-banner-button {
            background: #007AFF;
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 600;
            flex-shrink: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        body.mld-has-app-banner {
            padding-top: 72px !important;
        }
        @media (max-width: 380px) {
            .mld-app-banner-subtitle {
                display: none;
            }
        }
        </style>

        <div id="mld-app-banner" class="mld-app-banner">
            <button class="mld-app-banner-close" id="mld-app-banner-close" aria-label="Close banner">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <div class="mld-app-banner-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l4.59-4.58L18 11l-6 6z"/>
                </svg>
            </div>
            <div class="mld-app-banner-text">
                <p class="mld-app-banner-title">BMN Boston</p>
                <p class="mld-app-banner-subtitle">Get instant property alerts</p>
            </div>
            <a href="<?php echo esc_url($app_store_url); ?>" class="mld-app-banner-button" id="mld-app-banner-view">
                View
            </a>
        </div>

        <script>
        (function() {
            var banner = document.getElementById('mld-app-banner');
            var closeBtn = document.getElementById('mld-app-banner-close');

            if (!banner || !closeBtn) return;

            // Show banner with animation
            document.body.classList.add('mld-has-app-banner');
            setTimeout(function() {
                banner.classList.add('mld-banner-visible');
            }, 100);

            // Handle close
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                banner.classList.remove('mld-banner-visible');
                document.body.classList.remove('mld-has-app-banner');

                // Set cookie to dismiss for 30 days
                var d = new Date();
                d.setTime(d.getTime() + (<?php echo self::COOKIE_DAYS; ?> * 24 * 60 * 60 * 1000));
                document.cookie = '<?php echo self::COOKIE_NAME; ?>=1;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';

                // Remove banner from DOM after animation
                setTimeout(function() {
                    banner.remove();
                }, 300);
            });
        })();
        </script>
        <?php
    }

    /**
     * Check if desktop footer should be displayed
     *
     * @return bool
     */
    public function should_show_desktop_footer() {
        // Get user agent
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

        // Skip on iOS devices (they get the smart banner instead)
        $is_ios = (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false);
        if ($is_ios) {
            return false;
        }

        // Skip on Android (no Android app yet)
        if (stripos($ua, 'Android') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Render desktop footer promotion
     */
    public function render_desktop_footer() {
        if (!$this->should_show_desktop_footer()) {
            return;
        }

        $app_store_url = self::APP_STORE_URL;
        ?>
        <style>
        .mld-app-footer-promo {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 1px solid #dee2e6;
        }
        .mld-app-footer-promo-inner {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .mld-app-footer-promo-text {
            color: #495057;
            font-size: 15px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .mld-app-footer-promo img {
            height: 40px;
            width: auto;
            vertical-align: middle;
        }
        </style>
        <div class="mld-app-footer-promo">
            <div class="mld-app-footer-promo-inner">
                <span class="mld-app-footer-promo-text">Get instant property alerts on your iPhone</span>
                <a href="<?php echo esc_url($app_store_url); ?>">
                    <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83"
                         alt="Download on the App Store">
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Get App Store URL (for use in other templates)
     *
     * @return string
     */
    public static function get_app_store_url() {
        return self::APP_STORE_URL;
    }
}
