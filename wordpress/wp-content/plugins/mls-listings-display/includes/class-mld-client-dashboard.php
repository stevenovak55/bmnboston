<?php
/**
 * MLS Listings Display - Client Dashboard
 *
 * Vue.js-powered client dashboard with saved searches, agent info,
 * favorites, and email preferences.
 *
 * @package MLS_Listings_Display
 * @since 6.32.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Client_Dashboard {

    /**
     * Initialize the dashboard
     */
    public static function init() {
        add_shortcode('mld_client_dashboard', array(__CLASS__, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_assets'));
    }

    /**
     * Conditionally enqueue dashboard assets
     */
    public static function maybe_enqueue_assets() {
        global $post;

        // Only enqueue on pages with our shortcode
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'mld_client_dashboard')) {
            self::enqueue_assets();
        }
    }

    /**
     * Enqueue dashboard CSS and JS
     */
    public static function enqueue_assets() {
        // Vue.js 3 (local copy to avoid CSP issues)
        wp_enqueue_script(
            'vue3',
            MLD_PLUGIN_URL . 'assets/js/dashboard/vue.global.prod.js',
            array(),
            '3.5.13',
            true
        );

        // Dashboard CSS
        wp_enqueue_style(
            'mld-client-dashboard',
            MLD_PLUGIN_URL . 'assets/css/dashboard/mld-client-dashboard.css',
            array(),
            MLD_VERSION
        );

        // Dashboard Vue App
        wp_enqueue_script(
            'mld-client-dashboard',
            MLD_PLUGIN_URL . 'assets/js/dashboard/mld-client-dashboard.js',
            array('vue3'),
            MLD_VERSION,
            true
        );

        // Pass data to JavaScript
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        // Get agent if assigned
        $agent_data = null;
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_client_agent($user_id);
            if ($agent) {
                $agent_data = array(
                    'id' => $agent['user_id'],
                    'name' => $agent['display_name'] ?: $agent['wp_display_name'],
                    'title' => $agent['title'] ?? 'Real Estate Agent',
                    'phone' => $agent['phone'] ?? '',
                    'email' => $agent['email'] ?: $agent['user_email'],
                    'photo_url' => $agent['photo_url'] ?? '',
                    'office_name' => $agent['office_name'] ?? '',
                    'bio' => $agent['bio'] ?? '',
                    'snab_staff_id' => $agent['snab_staff_id'] ?? null,
                );
            }
        }

        // Get user type
        $user_type = 'client';
        if (class_exists('MLD_User_Type_Manager')) {
            $user_type = MLD_User_Type_Manager::get_user_type($user_id);
        }

        // Check if user is an agent
        $is_agent = ($user_type === 'agent');

        wp_localize_script('mld-client-dashboard', 'mldDashboardConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('mld-mobile/v1/'),
            'snabRestUrl' => rest_url('snab/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'homeUrl' => home_url(),
            'searchUrl' => home_url('/search/'),
            'bookingUrl' => home_url('/book/'),
            'userType' => $user_type,
            'isAgent' => $is_agent,
            'user' => array(
                'id' => $user_id,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'firstName' => $user->first_name ?: $user->display_name,
                'type' => $user_type,
            ),
            'agent' => $agent_data,
            'strings' => array(
                'loading' => __('Loading...', 'mls-listings-display'),
                'error' => __('An error occurred', 'mls-listings-display'),
                'noSearches' => __('No saved searches yet', 'mls-listings-display'),
                'noFavorites' => __('No favorite properties yet', 'mls-listings-display'),
                'confirmDelete' => __('Are you sure you want to delete this?', 'mls-listings-display'),
                'noClients' => __('No clients yet', 'mls-listings-display'),
            ),
        ));
    }

    /**
     * Render the dashboard shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_shortcode($atts = array()) {
        // Prevent CDN caching of this page (user-specific content)
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate, private');
            header('Pragma: no-cache');
            header('Expires: 0');
            // Kinsta-specific header to bypass cache
            header('X-Kinsta-Cache: BYPASS');
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            return self::render_login_prompt();
        }

        // Enqueue assets (in case they weren't already)
        self::enqueue_assets();

        ob_start();
        include MLD_PLUGIN_PATH . 'templates/client-dashboard.php';
        return ob_get_clean();
    }

    /**
     * Render login prompt for non-logged-in users
     *
     * @return string HTML
     */
    private static function render_login_prompt() {
        $login_url = wp_login_url(get_permalink());

        return '<div class="mld-login-prompt">
            <div class="mld-login-prompt__icon">🔐</div>
            <h2>' . esc_html__('Sign In Required', 'mls-listings-display') . '</h2>
            <p>' . esc_html__('Please sign in to access your dashboard.', 'mls-listings-display') . '</p>
            <a href="' . esc_url($login_url) . '" class="mld-btn mld-btn--primary">' .
                esc_html__('Sign In', 'mls-listings-display') .
            '</a>
        </div>';
    }
}

// Initialize
add_action('init', array('MLD_Client_Dashboard', 'init'));
