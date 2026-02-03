<?php
/**
 * MLS Listings Display - Referral Signup Page
 *
 * Handles the dedicated signup page for agent referral links.
 * Displays agent info and registration form when accessed via /signup?ref=CODE
 *
 * @package MLS_Listings_Display
 * @since 6.52.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Referral_Signup {

    /**
     * Initialize the referral signup page handler
     */
    public static function init() {
        // Original referral signup shortcode
        add_shortcode('mld_referral_signup', array(__CLASS__, 'render_shortcode'));
        // New general signup shortcode (same handler, works without referral code)
        add_shortcode('mld_signup', array(__CLASS__, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_assets'));
        add_action('wp_head', array(__CLASS__, 'output_meta_tags'), 1);
        add_action('wp_ajax_nopriv_mld_referral_register', array(__CLASS__, 'handle_registration'));
        add_action('wp_ajax_mld_referral_register', array(__CLASS__, 'handle_registration'));
    }

    /**
     * Output Open Graph and Twitter Card meta tags for social sharing
     */
    public static function output_meta_tags() {
        global $post;

        // Only output on pages with our shortcode (either referral or general signup)
        if (!is_a($post, 'WP_Post') || (!has_shortcode($post->post_content, 'mld_referral_signup') && !has_shortcode($post->post_content, 'mld_signup'))) {
            return;
        }

        // Get referral code from URL
        $referral_code = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';

        // Get agent data if referral code is valid
        $agent_data = null;
        if (!empty($referral_code) && class_exists('MLD_Referral_Manager')) {
            $agent_data = MLD_Referral_Manager::get_agent_by_code($referral_code);
        }

        $site_name = get_bloginfo('name');

        // Build dynamic title and description based on agent
        if ($agent_data && !empty($agent_data['name'])) {
            $og_title = sprintf('%s has invited you to %s', esc_attr($agent_data['name']), $site_name);
            $og_description = sprintf(
                'Create your free account to start your home search with personalized guidance from %s. Save searches, get instant alerts, and find your perfect home.',
                esc_attr($agent_data['name'])
            );
            $og_image = !empty($agent_data['photo_url']) ? esc_url($agent_data['photo_url']) : '';
        } else {
            $og_title = sprintf('Join %s - Find Your Perfect Home', $site_name);
            $og_description = 'Create your free account to save searches, get instant alerts for new listings, and find your perfect home with personalized guidance.';
            $og_image = '';
        }

        // Use site logo as fallback image
        if (empty($og_image)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $og_image = wp_get_attachment_image_url($custom_logo_id, 'full');
            }
        }

        $current_url = home_url($_SERVER['REQUEST_URI']);

        // Output Open Graph tags
        echo "\n<!-- MLD Referral Signup Meta Tags -->\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($og_title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($og_description) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($current_url) . '" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '" />' . "\n";
        if (!empty($og_image)) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '" />' . "\n";
        }

        // Output Twitter Card tags
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($og_title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($og_description) . '" />' . "\n";
        if (!empty($og_image)) {
            echo '<meta name="twitter:image" content="' . esc_url($og_image) . '" />' . "\n";
        }
        echo "<!-- End MLD Referral Signup Meta Tags -->\n\n";
    }

    /**
     * Conditionally enqueue signup page assets
     */
    public static function maybe_enqueue_assets() {
        global $post;

        // Only enqueue on pages with our shortcode (either referral or general signup)
        if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'mld_referral_signup') || has_shortcode($post->post_content, 'mld_signup'))) {
            self::enqueue_assets();
        }
    }

    /**
     * Enqueue signup page CSS and JS
     */
    public static function enqueue_assets() {
        // Signup page CSS
        wp_enqueue_style(
            'mld-referral-signup',
            MLD_PLUGIN_URL . 'assets/css/mld-referral-signup.css',
            array(),
            MLD_VERSION
        );

        // Signup page JS
        wp_enqueue_script(
            'mld-referral-signup',
            MLD_PLUGIN_URL . 'assets/js/mld-referral-signup.js',
            array('jquery'),
            MLD_VERSION,
            true
        );

        // Localize script
        wp_localize_script('mld-referral-signup', 'mldReferralConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mld_referral_signup'),
            'loginUrl' => wp_login_url(home_url('/my-dashboard/')),
            'dashboardUrl' => home_url('/my-dashboard/'),
            'strings' => array(
                'registering' => __('Creating your account...', 'mls-listings-display'),
                'success' => __('Account created! Redirecting...', 'mls-listings-display'),
                'error' => __('Registration failed. Please try again.', 'mls-listings-display'),
                'emailExists' => __('This email is already registered. Please sign in.', 'mls-listings-display'),
                'passwordMismatch' => __('Passwords do not match.', 'mls-listings-display'),
                'passwordShort' => __('Password must be at least 6 characters.', 'mls-listings-display'),
            ),
        ));
    }

    /**
     * Render the signup page shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_shortcode($atts = array()) {
        // Already logged in? Redirect to dashboard
        if (is_user_logged_in()) {
            wp_redirect(home_url('/my-dashboard/'));
            exit;
        }

        // Get referral code from URL
        $referral_code = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';

        // Get agent data if referral code is valid
        $agent_data = null;
        if (!empty($referral_code) && class_exists('MLD_Referral_Manager')) {
            $agent_data = MLD_Referral_Manager::get_agent_by_code($referral_code);
        }

        // Enqueue assets
        self::enqueue_assets();

        ob_start();
        include MLD_PLUGIN_PATH . 'templates/referral-signup-page.php';
        return ob_get_clean();
    }

    /**
     * Handle AJAX registration form submission
     */
    public static function handle_registration() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mld_referral_signup')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'mls-listings-display')));
        }

        // Get client IP for logging
        $ip = self::get_client_ip();

        // ============ BOT PROTECTION LAYER 1: Honeypot ============
        // If honeypot field has a value, a bot filled it. Return fake success.
        $honeypot = isset($_POST['mld_signup_website']) ? sanitize_text_field($_POST['mld_signup_website']) : '';
        if (!empty($honeypot)) {
            // Log the blocked attempt
            if (class_exists('MLD_Email_Validator')) {
                MLD_Email_Validator::log_blocked_attempt(
                    isset($_POST['email']) ? sanitize_email($_POST['email']) : 'unknown',
                    'Honeypot field filled',
                    'honeypot_triggered',
                    array('ip' => $ip, 'source' => 'web')
                );
            }
            // Return fake success to avoid tipping off the bot
            wp_send_json_success(array(
                'message' => __('Account created successfully!', 'mls-listings-display'),
                'redirect' => home_url('/my-dashboard/'),
            ));
        }

        // ============ BOT PROTECTION LAYER 2: Time-Based Validation ============
        // Reject submissions faster than 3 seconds (bots submit instantly)
        $form_timestamp = isset($_POST['mld_form_ts']) ? intval($_POST['mld_form_ts']) : 0;
        $current_timestamp = current_time('timestamp');
        $time_diff = $current_timestamp - $form_timestamp;

        if ($form_timestamp > 0 && $time_diff < 3) {
            // Log the blocked attempt
            if (class_exists('MLD_Email_Validator')) {
                MLD_Email_Validator::log_blocked_attempt(
                    isset($_POST['email']) ? sanitize_email($_POST['email']) : 'unknown',
                    'Form submitted too quickly (' . $time_diff . ' seconds)',
                    'too_fast',
                    array('ip' => $ip, 'source' => 'web')
                );
            }
            // Return fake success
            wp_send_json_success(array(
                'message' => __('Account created successfully!', 'mls-listings-display'),
                'redirect' => home_url('/my-dashboard/'),
            ));
        }

        // Get form data
        $email = sanitize_email($_POST['email']);
        $password = $_POST['password'];
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $referral_code = sanitize_text_field($_POST['referral_code']);
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';

        // Validate required fields
        if (empty($email) || empty($password) || empty($first_name)) {
            wp_send_json_error(array('message' => __('Please fill in all required fields.', 'mls-listings-display')));
        }

        // ============ BOT PROTECTION LAYER 3 & 4: Email Validation ============
        // Check for disposable emails and gibberish patterns
        if (class_exists('MLD_Email_Validator')) {
            $email_validation = MLD_Email_Validator::validate($email);
            if (!$email_validation['valid']) {
                MLD_Email_Validator::log_blocked_attempt(
                    $email,
                    $email_validation['reason'],
                    $email_validation['code'],
                    array('ip' => $ip, 'source' => 'web')
                );
                // Return real error for disposable/gibberish emails
                wp_send_json_error(array(
                    'message' => $email_validation['reason'],
                    'code' => $email_validation['code']
                ));
            }
        }

        // Check if email already exists
        if (email_exists($email)) {
            wp_send_json_error(array(
                'message' => __('This email is already registered.', 'mls-listings-display'),
                'code' => 'email_exists'
            ));
        }

        // Create WordPress user
        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }

        // Update user meta
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
        ));

        if (!empty($phone)) {
            update_user_meta($user_id, 'phone', $phone);
        }

        // Set user role
        $user = new WP_User($user_id);
        $user->set_role('subscriber');

        // Assign to agent using referral manager
        $assigned_agent = null;
        if (class_exists('MLD_Referral_Manager')) {
            $source = !empty($referral_code) ? 'referral_link' : 'organic';
            $assignment_result = MLD_Referral_Manager::assign_client_on_register(
                $user_id,
                $referral_code,
                $source,
                'web'
            );

            if ($assignment_result && isset($assignment_result['agent_user_id'])) {
                if (class_exists('MLD_Agent_Client_Manager')) {
                    $assigned_agent = MLD_Agent_Client_Manager::get_agent_for_api($assignment_result['agent_user_id']);
                }
            }
        }

        // Set user type to client
        if (class_exists('MLD_User_Type_Manager')) {
            MLD_User_Type_Manager::set_user_type($user_id, 'client');
        }

        // Log the user in
        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);

        // Send welcome email if agent-client manager is available
        if (class_exists('MLD_Agent_Client_Manager') && $assigned_agent) {
            MLD_Agent_Client_Manager::send_client_welcome_email($user_id, $assigned_agent['id']);
        }

        wp_send_json_success(array(
            'message' => __('Account created successfully!', 'mls-listings-display'),
            'redirect' => home_url('/my-dashboard/'),
            'assigned_agent' => $assigned_agent,
        ));
    }

    /**
     * Get the client IP address, accounting for CDN/proxy headers
     *
     * @return string IP address
     */
    private static function get_client_ip() {
        // Check for CDN/proxy headers (Kinsta, Cloudflare, etc.)
        $headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx proxy
            'REMOTE_ADDR',               // Direct connection
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For may contain multiple IPs, get the first one
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return sanitize_text_field($ip);
                }
            }
        }

        return 'unknown';
    }
}

// Initialize
add_action('init', array('MLD_Referral_Signup', 'init'));
