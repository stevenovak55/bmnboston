<?php
/**
 * App Store Banner for iOS Users
 *
 * Displays a smart app banner for iOS Safari users, encouraging
 * them to download the BMN Boston app.
 *
 * Note: Uses client-side detection to work with full-page caching.
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
     * Cookie name for property card dismissal
     */
    const PROPERTY_COOKIE_NAME = 'mld_app_property_card_dismissed';

    /**
     * Days to remember property card dismissal (less aggressive than banner)
     */
    const PROPERTY_COOKIE_DAYS = 14;

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

        // Always output banner HTML - JS will control visibility based on device
        add_action('wp_body_open', array($this, 'render_banner'), 5);
        // Always output footer HTML - JS will control visibility based on device
        add_action('wp_footer', array($this, 'render_desktop_footer'), 99);
        // Property page inline card - placed after "About This Home" section
        add_action('mld_after_property_description', array($this, 'render_property_inline_card'), 10, 2);
    }

    /**
     * Render the banner HTML and assets
     * Banner is hidden by default, JS shows it on iOS devices
     */
    public function render_banner() {
        $app_store_url = self::APP_STORE_URL;
        $app_store_badge = 'https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83';
        ?>
        <style>
        /* Hidden by default - JS will show on iOS */
        #mld-app-banner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999999;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif;
            box-sizing: border-box;
        }
        #mld-app-banner.mld-banner-visible {
            display: flex;
        }
        #mld-app-banner .mld-banner-close {
            background: none;
            border: none;
            padding: 6px;
            color: #6c757d;
            cursor: pointer;
            line-height: 1;
            flex-shrink: 0;
        }
        #mld-app-banner .mld-banner-close svg {
            display: block;
        }
        #mld-app-banner .mld-banner-text {
            flex: 1;
            margin: 0 12px;
        }
        #mld-app-banner .mld-banner-title {
            font-weight: 600;
            font-size: 15px;
            color: #1c1c1e;
            margin: 0;
        }
        #mld-app-banner .mld-banner-subtitle {
            font-size: 12px;
            color: #6c757d;
            margin: 2px 0 0 0;
        }
        #mld-app-banner .mld-banner-badge {
            flex-shrink: 0;
        }
        #mld-app-banner .mld-banner-badge img {
            height: 32px;
            width: auto;
            display: block;
        }
        html.mld-has-app-banner {
            margin-top: 54px !important;
        }
        html.mld-has-app-banner body {
            margin-top: 0 !important;
        }
        @media (max-width: 380px) {
            #mld-app-banner .mld-banner-subtitle {
                display: none;
            }
            html.mld-has-app-banner {
                margin-top: 48px !important;
            }
        }
        </style>

        <div id="mld-app-banner">
            <button class="mld-banner-close" id="mld-app-banner-close" aria-label="Close banner">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <div class="mld-banner-text">
                <p class="mld-banner-title">BMN Boston</p>
                <p class="mld-banner-subtitle">Get our mobile app!</p>
            </div>
            <a href="<?php echo esc_url($app_store_url); ?>" class="mld-banner-badge">
                <img src="<?php echo esc_url($app_store_badge); ?>" alt="Download on the App Store">
            </a>
        </div>

        <script>
        (function() {
            var banner = document.getElementById('mld-app-banner');
            var closeBtn = document.getElementById('mld-app-banner-close');

            if (!banner || !closeBtn) return;

            // Check if iOS device (client-side detection for cache compatibility)
            var ua = navigator.userAgent;
            var isIOS = /iPhone|iPad|iPod/.test(ua);
            var isInApp = /BMNBoston|FBAN|FBAV/.test(ua); // Skip if in our app or Facebook browser

            // Check dismissal cookie
            var dismissed = document.cookie.indexOf('<?php echo self::COOKIE_NAME; ?>=') !== -1;

            // Only show on iOS Safari, not dismissed, not in app
            if (isIOS && !isInApp && !dismissed) {
                banner.classList.add('mld-banner-visible');
                document.documentElement.classList.add('mld-has-app-banner');
            }

            // Handle close button
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                banner.classList.remove('mld-banner-visible');
                document.documentElement.classList.remove('mld-has-app-banner');

                // Set cookie to dismiss for 30 days
                var d = new Date();
                d.setTime(d.getTime() + (<?php echo self::COOKIE_DAYS; ?> * 24 * 60 * 60 * 1000));
                document.cookie = '<?php echo self::COOKIE_NAME; ?>=1;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
            });
        })();
        </script>
        <?php
    }

    /**
     * Render desktop footer promotion with QR code
     * Footer is hidden by default, JS shows it on desktop (non-mobile)
     */
    public function render_desktop_footer() {
        $app_store_url = self::APP_STORE_URL;
        $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($app_store_url);
        ?>
        <style>
        /* Hidden by default - JS will show on desktop */
        .mld-app-footer-promo {
            display: none;
            text-align: center;
            padding: 30px 20px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-top: 1px solid #2a2a4a;
        }
        .mld-app-footer-promo.mld-footer-visible {
            display: block;
        }
        .mld-app-footer-promo-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            max-width: 800px;
            margin: 0 auto;
            flex-wrap: wrap;
        }
        .mld-app-footer-qr {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .mld-app-footer-qr img {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            background: #fff;
            padding: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }
        .mld-app-footer-qr-label {
            color: #a0a0b0;
            font-size: 12px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .mld-app-footer-content {
            text-align: left;
        }
        .mld-app-footer-headline {
            color: #ffffff;
            font-size: 22px;
            font-weight: 600;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0 0 8px 0;
        }
        .mld-app-footer-subtext {
            color: #a0a0b0;
            font-size: 14px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0 0 15px 0;
            max-width: 350px;
        }
        .mld-app-footer-badge {
            display: inline-block;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        .mld-app-footer-badge:hover {
            transform: scale(1.05);
            opacity: 0.9;
        }
        .mld-app-footer-badge img {
            height: 44px;
            width: auto;
        }
        @media (max-width: 600px) {
            .mld-app-footer-promo-inner {
                flex-direction: column;
                gap: 20px;
            }
            .mld-app-footer-content {
                text-align: center;
            }
            .mld-app-footer-subtext {
                margin-left: auto;
                margin-right: auto;
            }
        }
        </style>
        <div class="mld-app-footer-promo" id="mld-app-footer-promo">
            <div class="mld-app-footer-promo-inner">
                <div class="mld-app-footer-qr">
                    <img src="<?php echo esc_url($qr_code_url); ?>" alt="Scan to download BMN Boston app">
                    <span class="mld-app-footer-qr-label">Scan with your phone</span>
                </div>
                <div class="mld-app-footer-content">
                    <h3 class="mld-app-footer-headline">Get the BMN Boston App</h3>
                    <p class="mld-app-footer-subtext">Search homes, save favorites, and get instant alerts for new listings - all from your iPhone.</p>
                    <a href="<?php echo esc_url($app_store_url); ?>" class="mld-app-footer-badge" target="_blank" rel="noopener">
                        <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/white/en-us?size=250x83"
                             alt="Download on the App Store">
                    </a>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var footer = document.getElementById('mld-app-footer-promo');
            if (!footer) return;

            // Check device type (client-side for cache compatibility)
            var ua = navigator.userAgent;
            var isIOS = /iPhone|iPad|iPod/.test(ua);
            var isAndroid = /Android/.test(ua);

            // Show footer on desktop only (not iOS - they get banner, not Android - no app)
            if (!isIOS && !isAndroid) {
                footer.classList.add('mld-footer-visible');
            }
        })();
        </script>
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

    /**
     * Render property-specific inline app download card with sticky header
     * Shows after "About This Home" section on property detail pages
     * Includes smart deep linking that opens app if installed
     *
     * @param string $listing_id The MLS number
     * @param array $listing The listing data
     */
    public function render_property_inline_card($listing_id, $listing) {
        $app_store_url = self::APP_STORE_URL;
        $deep_link_url = 'bmnboston://property/' . esc_attr($listing_id);
        $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($app_store_url);
        ?>
        <style>
        /* ========================================
           MAIN INLINE CARD - More prominent design
           ======================================== */
        .mld-app-property-card {
            display: none;
            margin: 24px 0;
            padding: 24px;
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
            border-radius: 16px;
            position: relative;
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif;
            box-shadow: 0 10px 25px -5px rgba(12, 74, 110, 0.4);
            overflow: hidden;
        }
        .mld-app-property-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 60%;
            height: 200%;
            background: radial-gradient(ellipse, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        .mld-app-property-card.mld-card-visible {
            display: block;
        }
        .mld-app-property-card-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(255,255,255,0.15);
            border: none;
            padding: 6px;
            cursor: pointer;
            color: rgba(255,255,255,0.8);
            line-height: 1;
            border-radius: 6px;
            transition: background-color 0.2s ease;
            z-index: 2;
        }
        .mld-app-property-card-close:hover {
            background-color: rgba(255,255,255,0.25);
            color: white;
        }
        .mld-app-property-card-content {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        .mld-app-property-card-icon {
            flex-shrink: 0;
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }
        .mld-app-property-card-icon svg {
            color: white;
            width: 28px;
            height: 28px;
        }
        .mld-app-property-card-text {
            flex: 1;
            min-width: 0;
        }
        .mld-app-property-card-title {
            font-size: 20px;
            font-weight: 700;
            color: white;
            margin: 0 0 6px 0;
        }
        .mld-app-property-card-subtitle {
            font-size: 14px;
            color: rgba(255,255,255,0.85);
            margin: 0;
            line-height: 1.5;
        }
        .mld-app-property-card-cta {
            flex-shrink: 0;
        }
        .mld-app-property-card-btn {
            display: inline-block;
            padding: 14px 28px;
            background: white;
            color: #0c4a6e;
            text-decoration: none;
            font-weight: 700;
            font-size: 15px;
            border-radius: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            cursor: pointer;
            border: none;
        }
        .mld-app-property-card-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            color: #0c4a6e;
        }
        /* Desktop: Show QR code */
        .mld-app-property-card-qr {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .mld-app-property-card-qr img {
            width: 90px;
            height: 90px;
            border-radius: 10px;
            background: white;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .mld-app-property-card-qr-label {
            font-size: 11px;
            color: rgba(255,255,255,0.7);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mld-app-property-card.mld-card-desktop .mld-app-property-card-btn {
            display: none;
        }
        .mld-app-property-card.mld-card-desktop .mld-app-property-card-qr {
            display: flex;
        }
        @media (max-width: 480px) {
            .mld-app-property-card {
                padding: 20px;
                margin: 20px 0;
            }
            .mld-app-property-card-content {
                flex-wrap: wrap;
            }
            .mld-app-property-card-text {
                flex: 1 1 calc(100% - 76px);
            }
            .mld-app-property-card-title {
                font-size: 18px;
            }
            .mld-app-property-card-cta {
                flex: 1 1 100%;
                margin-top: 16px;
            }
            .mld-app-property-card-btn {
                display: block;
                text-align: center;
                width: 100%;
            }
        }

        /* ========================================
           STICKY HEADER BAR - Compact version
           ======================================== */
        .mld-app-sticky-bar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999998;
            background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 100%);
            padding: 10px 16px;
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transform: translateY(-100%);
            transition: transform 0.3s ease;
        }
        .mld-app-sticky-bar.mld-sticky-visible {
            display: flex;
            transform: translateY(0);
        }
        /* Adjust for existing app banner if present */
        html.mld-has-app-banner .mld-app-sticky-bar.mld-sticky-visible {
            top: 54px;
        }
        @media (max-width: 380px) {
            html.mld-has-app-banner .mld-app-sticky-bar.mld-sticky-visible {
                top: 48px;
            }
        }
        .mld-app-sticky-bar-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            gap: 12px;
        }
        .mld-app-sticky-bar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }
        .mld-app-sticky-bar-icon {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .mld-app-sticky-bar-icon svg {
            color: white;
            width: 18px;
            height: 18px;
        }
        .mld-app-sticky-bar-text {
            color: white;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mld-app-sticky-bar-btn {
            flex-shrink: 0;
            display: inline-block;
            padding: 8px 16px;
            background: white;
            color: #0c4a6e;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            transition: transform 0.15s ease;
        }
        .mld-app-sticky-bar-btn:hover {
            transform: scale(1.02);
            color: #0c4a6e;
        }
        .mld-app-sticky-bar-close {
            flex-shrink: 0;
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            color: rgba(255,255,255,0.7);
            line-height: 1;
        }
        .mld-app-sticky-bar-close:hover {
            color: white;
        }
        @media (max-width: 400px) {
            .mld-app-sticky-bar-text {
                font-size: 13px;
            }
            .mld-app-sticky-bar-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
        }
        </style>

        <!-- Main inline card -->
        <div class="mld-app-property-card" id="mld-app-property-card">
            <button class="mld-app-property-card-close" id="mld-app-property-card-close" aria-label="Dismiss app download prompt">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <div class="mld-app-property-card-content">
                <div class="mld-app-property-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                        <line x1="12" y1="18" x2="12.01" y2="18"></line>
                    </svg>
                </div>
                <div class="mld-app-property-card-text">
                    <p class="mld-app-property-card-title">View in the App</p>
                    <p class="mld-app-property-card-subtitle">Save this property, schedule tours instantly, and get alerts when the price drops</p>
                </div>
                <div class="mld-app-property-card-cta">
                    <button class="mld-app-property-card-btn" id="mld-app-open-btn">Open App</button>
                    <div class="mld-app-property-card-qr">
                        <img src="<?php echo esc_url($qr_code_url); ?>" alt="Scan to download BMN Boston app">
                        <span class="mld-app-property-card-qr-label">Scan with phone</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sticky header bar (appears when scrolling past main card) -->
        <div class="mld-app-sticky-bar" id="mld-app-sticky-bar">
            <div class="mld-app-sticky-bar-content">
                <div class="mld-app-sticky-bar-left">
                    <div class="mld-app-sticky-bar-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                            <line x1="12" y1="18" x2="12.01" y2="18"></line>
                        </svg>
                    </div>
                    <span class="mld-app-sticky-bar-text">Get the full experience in the BMN Boston app</span>
                </div>
                <button class="mld-app-sticky-bar-btn" id="mld-app-sticky-btn">Open App</button>
                <button class="mld-app-sticky-bar-close" id="mld-app-sticky-close" aria-label="Dismiss">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>

        <script>
        (function() {
            var card = document.getElementById('mld-app-property-card');
            var cardCloseBtn = document.getElementById('mld-app-property-card-close');
            var cardOpenBtn = document.getElementById('mld-app-open-btn');
            var stickyBar = document.getElementById('mld-app-sticky-bar');
            var stickyOpenBtn = document.getElementById('mld-app-sticky-btn');
            var stickyCloseBtn = document.getElementById('mld-app-sticky-close');

            if (!card || !stickyBar) return;

            // Config
            var deepLinkUrl = '<?php echo esc_js($deep_link_url); ?>';
            var appStoreUrl = '<?php echo esc_js($app_store_url); ?>';
            var cookieName = '<?php echo self::PROPERTY_COOKIE_NAME; ?>';
            var cookieDays = <?php echo self::PROPERTY_COOKIE_DAYS; ?>;

            // Device detection
            var ua = navigator.userAgent;
            var isIOS = /iPhone|iPad|iPod/.test(ua);
            var isAndroid = /Android/.test(ua);
            var isInApp = /BMNBoston|FBAN|FBAV/.test(ua);
            var isDesktop = !isIOS && !isAndroid;

            // Check dismissal cookie
            var dismissed = document.cookie.indexOf(cookieName + '=') !== -1;

            // State
            var cardIsVisible = false;
            var stickyIsVisible = false;

            // Show card based on device
            if (!dismissed && !isInApp) {
                if (isIOS) {
                    card.classList.add('mld-card-visible');
                    cardIsVisible = true;
                } else if (isDesktop) {
                    card.classList.add('mld-card-visible', 'mld-card-desktop');
                    cardIsVisible = true;
                }
            }

            // Smart app open function (tries deep link first, then App Store)
            function openApp(e) {
                if (e) e.preventDefault();

                if (isIOS) {
                    // Try to open the app via deep link
                    var start = Date.now();
                    var timeout;

                    // Set up fallback to App Store
                    timeout = setTimeout(function() {
                        // If we're still here after 1.5s, app probably isn't installed
                        // Only redirect if the page is still visible (user didn't leave)
                        if (!document.hidden) {
                            window.location.href = appStoreUrl;
                        }
                    }, 1500);

                    // Try to open the app
                    window.location.href = deepLinkUrl;

                    // If app opens, this page will be backgrounded
                    // Clear the timeout when page becomes hidden
                    var visibilityHandler = function() {
                        if (document.hidden) {
                            clearTimeout(timeout);
                            document.removeEventListener('visibilitychange', visibilityHandler);
                        }
                    };
                    document.addEventListener('visibilitychange', visibilityHandler);
                } else {
                    // Desktop or other - just go to App Store
                    window.open(appStoreUrl, '_blank');
                }
            }

            // Set dismissal cookie
            function setCookie() {
                var d = new Date();
                d.setTime(d.getTime() + (cookieDays * 24 * 60 * 60 * 1000));
                document.cookie = cookieName + '=1;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
            }

            // Dismiss everything
            function dismissAll() {
                card.classList.remove('mld-card-visible', 'mld-card-desktop');
                stickyBar.classList.remove('mld-sticky-visible');
                cardIsVisible = false;
                stickyIsVisible = false;
                dismissed = true;
                setCookie();
            }

            // Event listeners for buttons
            if (cardOpenBtn) cardOpenBtn.addEventListener('click', openApp);
            if (stickyOpenBtn) stickyOpenBtn.addEventListener('click', openApp);
            if (cardCloseBtn) cardCloseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                dismissAll();
            });
            if (stickyCloseBtn) stickyCloseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                dismissAll();
            });

            // Scroll handler for sticky bar (iOS only - desktop doesn't need it)
            if (cardIsVisible && isIOS) {
                var lastScrollY = window.scrollY;
                var ticking = false;

                function updateStickyBar() {
                    if (dismissed) return;

                    var cardRect = card.getBoundingClientRect();
                    var cardBottom = cardRect.bottom;

                    // Show sticky bar when the main card is scrolled out of view
                    if (cardBottom < -50) {
                        if (!stickyIsVisible) {
                            stickyBar.classList.add('mld-sticky-visible');
                            stickyIsVisible = true;
                        }
                    } else {
                        if (stickyIsVisible) {
                            stickyBar.classList.remove('mld-sticky-visible');
                            stickyIsVisible = false;
                        }
                    }
                    ticking = false;
                }

                window.addEventListener('scroll', function() {
                    lastScrollY = window.scrollY;
                    if (!ticking) {
                        window.requestAnimationFrame(updateStickyBar);
                        ticking = true;
                    }
                }, { passive: true });
            }
        })();
        </script>
        <?php
    }
}
