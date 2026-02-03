<?php
/**
 * Lead Generation Tools Class
 *
 * Handles CMA requests, property alerts, tour scheduling, and mortgage calculations
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Lead_Tools {

    /**
     * Initialize lead tools
     */
    public static function init() {
        // Register AJAX handlers
        add_action('wp_ajax_bne_cma_request', array(__CLASS__, 'handle_cma_request'));
        add_action('wp_ajax_nopriv_bne_cma_request', array(__CLASS__, 'handle_cma_request'));

        add_action('wp_ajax_bne_property_alerts', array(__CLASS__, 'handle_property_alerts'));
        add_action('wp_ajax_nopriv_bne_property_alerts', array(__CLASS__, 'handle_property_alerts'));

        add_action('wp_ajax_bne_schedule_tour', array(__CLASS__, 'handle_schedule_tour'));
        add_action('wp_ajax_nopriv_bne_schedule_tour', array(__CLASS__, 'handle_schedule_tour'));
    }

    /**
     * Handle CMA request form submission
     */
    public static function handle_cma_request() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bne_lead_tools_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }

        // Honeypot check
        if (!empty($_POST['website'])) {
            wp_send_json_error(array('message' => 'Invalid submission.'));
            return;
        }

        // Sanitize input
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $property_address = isset($_POST['property_address']) ? sanitize_textarea_field(wp_unslash($_POST['property_address'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $property_type = isset($_POST['property_type']) ? sanitize_text_field(wp_unslash($_POST['property_type'])) : 'single_family';
        $timeline = isset($_POST['timeline']) ? sanitize_text_field(wp_unslash($_POST['timeline'])) : '';

        // Validate required fields
        if (empty($first_name) || empty($email) || empty($property_address)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
            return;
        }

        // Store in database if MLD_Form_Submissions exists
        $submission_id = null;
        if (class_exists('MLD_Form_Submissions')) {
            $submission_id = MLD_Form_Submissions::insert_submission(array(
                'form_type' => 'cma_request',
                'property_address' => $property_address,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'message' => wp_json_encode(array(
                    'property_type' => $property_type,
                    'timeline' => $timeline,
                    'additional_notes' => $message,
                )),
                'status' => 'new',
            ));
        }

        // Send notification email to agent
        $agent_email = get_theme_mod('bne_agent_email', get_option('admin_email'));
        $agent_name = get_theme_mod('bne_agent_name', 'Agent');

        $subject = sprintf('[CMA Request] New request from %s %s', $first_name, $last_name);

        $body = sprintf(
            "New CMA Request Received\n\n" .
            "Name: %s %s\n" .
            "Email: %s\n" .
            "Phone: %s\n\n" .
            "Property Address:\n%s\n\n" .
            "Property Type: %s\n" .
            "Timeline: %s\n\n" .
            "Additional Notes:\n%s\n\n" .
            "---\n" .
            "Submitted via: %s\n" .
            "Date: %s",
            $first_name,
            $last_name,
            $email,
            $phone ?: 'Not provided',
            $property_address,
            ucwords(str_replace('_', ' ', $property_type)),
            $timeline ?: 'Not specified',
            $message ?: 'None',
            home_url(),
            current_time('F j, Y g:i a')
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $first_name . ' ' . $last_name, $email),
        );

        $email_sent = wp_mail($agent_email, $subject, $body, $headers);

        // Send confirmation email to user
        $confirmation_subject = 'Your CMA Request Has Been Received';
        $confirmation_body = sprintf(
            "Dear %s,\n\n" .
            "Thank you for requesting a Comparative Market Analysis (CMA) for your property.\n\n" .
            "Property Address: %s\n\n" .
            "I'll review the current market conditions and comparable sales in your area and get back to you with a detailed analysis within 24-48 hours.\n\n" .
            "If you have any questions in the meantime, please don't hesitate to reach out.\n\n" .
            "Best regards,\n" .
            "%s\n" .
            "%s\n" .
            "%s",
            $first_name,
            $property_address,
            $agent_name,
            get_theme_mod('bne_phone_number', ''),
            $agent_email
        );

        $confirmation_headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $agent_name, $agent_email),
        );

        wp_mail($email, $confirmation_subject, $confirmation_body, $confirmation_headers);

        wp_send_json_success(array(
            'message' => 'Thank you! Your CMA request has been submitted. You will receive a detailed market analysis within 24-48 hours.',
            'submission_id' => $submission_id,
        ));
    }

    /**
     * Handle property alerts signup
     */
    public static function handle_property_alerts() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bne_lead_tools_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }

        // Honeypot check
        if (!empty($_POST['website'])) {
            wp_send_json_error(array('message' => 'Invalid submission.'));
            return;
        }

        // Sanitize input
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $cities = isset($_POST['cities']) ? array_map('sanitize_text_field', (array) $_POST['cities']) : array();
        $min_price = isset($_POST['min_price']) ? absint($_POST['min_price']) : 0;
        $max_price = isset($_POST['max_price']) ? absint($_POST['max_price']) : 0;
        $bedrooms = isset($_POST['bedrooms']) ? absint($_POST['bedrooms']) : 0;
        $property_types = isset($_POST['property_types']) ? array_map('sanitize_text_field', (array) $_POST['property_types']) : array();
        $frequency = isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'instant';

        // Validate required fields
        if (empty($email)) {
            wp_send_json_error(array('message' => 'Please enter your email address.'));
            return;
        }

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
            return;
        }

        // Build search filters
        $filters = array();

        if (!empty($cities)) {
            $filters['city'] = $cities;
        }

        if ($min_price > 0) {
            $filters['min_price'] = $min_price;
        }

        if ($max_price > 0) {
            $filters['max_price'] = $max_price;
        }

        if ($bedrooms > 0) {
            $filters['bedrooms_min'] = $bedrooms;
        }

        if (!empty($property_types)) {
            $filters['property_type'] = $property_types;
        }

        // Check if user exists or create guest user
        $user = get_user_by('email', $email);
        $user_id = $user ? $user->ID : 0;

        // If no user, create guest entry in saved searches
        if (!$user_id) {
            // Create a guest user entry using email as identifier
            $user_id = self::get_or_create_guest_user($email, $first_name);
        }

        // Create saved search if MLD_Saved_Searches exists
        $search_id = null;
        if (class_exists('MLD_Saved_Searches') && $user_id) {
            $search_name = !empty($cities) ? 'Alerts for ' . implode(', ', array_slice($cities, 0, 2)) : 'Property Alerts';

            $result = MLD_Saved_Searches::create_search(array(
                'user_id' => $user_id,
                'name' => $search_name,
                'description' => 'Created via homepage property alerts signup',
                'filters' => $filters,
                'notification_frequency' => $frequency,
                'is_active' => true,
            ));

            if (!is_wp_error($result)) {
                $search_id = $result;
            }
        }

        // Send confirmation email
        $agent_name = get_theme_mod('bne_agent_name', 'Agent');
        $agent_email = get_theme_mod('bne_agent_email', get_option('admin_email'));

        $filter_summary = array();
        if (!empty($cities)) {
            $filter_summary[] = 'Cities: ' . implode(', ', $cities);
        }
        if ($min_price > 0 || $max_price > 0) {
            $price_range = '';
            if ($min_price > 0) {
                $price_range .= '$' . number_format($min_price);
            }
            if ($max_price > 0) {
                $price_range .= ' - $' . number_format($max_price);
            }
            $filter_summary[] = 'Price Range: ' . trim($price_range, ' - ');
        }
        if ($bedrooms > 0) {
            $filter_summary[] = 'Bedrooms: ' . $bedrooms . '+';
        }

        $confirmation_subject = 'Your Property Alerts Are Now Active';
        $confirmation_body = sprintf(
            "Hi %s,\n\n" .
            "Great news! Your property alerts have been set up successfully.\n\n" .
            "Search Criteria:\n%s\n\n" .
            "Notification Frequency: %s\n\n" .
            "You'll receive email notifications when new properties matching your criteria are listed.\n\n" .
            "To manage your alerts or update your preferences, visit:\n%s\n\n" .
            "Best regards,\n" .
            "%s",
            $first_name ?: 'there',
            !empty($filter_summary) ? implode("\n", $filter_summary) : 'All properties',
            ucfirst($frequency),
            home_url('/property-search/'),
            $agent_name
        );

        $confirmation_headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $agent_name, $agent_email),
        );

        wp_mail($email, $confirmation_subject, $confirmation_body, $confirmation_headers);

        // Notify agent of new signup
        $agent_subject = sprintf('[Property Alerts] New signup from %s', $email);
        $agent_body = sprintf(
            "New Property Alerts Signup\n\n" .
            "Name: %s\n" .
            "Email: %s\n\n" .
            "Search Criteria:\n%s\n\n" .
            "Frequency: %s\n\n" .
            "---\n" .
            "Submitted via: %s\n" .
            "Date: %s",
            $first_name ?: 'Not provided',
            $email,
            !empty($filter_summary) ? implode("\n", $filter_summary) : 'All properties',
            ucfirst($frequency),
            home_url(),
            current_time('F j, Y g:i a')
        );

        wp_mail($agent_email, $agent_subject, $agent_body);

        wp_send_json_success(array(
            'message' => 'Your property alerts have been set up! Check your email for confirmation.',
            'search_id' => $search_id,
        ));
    }

    /**
     * Handle tour scheduling request
     */
    public static function handle_schedule_tour() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bne_lead_tools_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }

        // Honeypot check
        if (!empty($_POST['website'])) {
            wp_send_json_error(array('message' => 'Invalid submission.'));
            return;
        }

        // Sanitize input
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $property_address = isset($_POST['property_address']) ? sanitize_textarea_field(wp_unslash($_POST['property_address'])) : '';
        $property_mls = isset($_POST['property_mls']) ? sanitize_text_field(wp_unslash($_POST['property_mls'])) : '';
        $tour_type = isset($_POST['tour_type']) ? sanitize_text_field(wp_unslash($_POST['tour_type'])) : 'in_person';
        $preferred_date = isset($_POST['preferred_date']) ? sanitize_text_field(wp_unslash($_POST['preferred_date'])) : '';
        $preferred_time = isset($_POST['preferred_time']) ? sanitize_text_field(wp_unslash($_POST['preferred_time'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        // Validate required fields
        if (empty($first_name) || empty($email) || empty($preferred_date)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
            return;
        }

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
            return;
        }

        // Store in database if MLD_Form_Submissions exists
        $submission_id = null;
        if (class_exists('MLD_Form_Submissions')) {
            $submission_id = MLD_Form_Submissions::insert_submission(array(
                'form_type' => 'tour_request',
                'property_mls' => $property_mls,
                'property_address' => $property_address,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'tour_type' => $tour_type,
                'preferred_date' => $preferred_date,
                'preferred_time' => $preferred_time,
                'message' => $message,
                'status' => 'new',
            ));
        }

        // Format tour type for display
        $tour_type_labels = array(
            'in_person' => 'In-Person Tour',
            'video' => 'Video Tour',
            'self_guided' => 'Self-Guided Tour',
        );
        $tour_type_display = isset($tour_type_labels[$tour_type]) ? $tour_type_labels[$tour_type] : ucwords(str_replace('_', ' ', $tour_type));

        // Send notification email to agent
        $agent_email = get_theme_mod('bne_agent_email', get_option('admin_email'));
        $agent_name = get_theme_mod('bne_agent_name', 'Agent');

        $subject = sprintf('[Tour Request] %s on %s', $tour_type_display, $preferred_date);

        $body = sprintf(
            "New Tour Request Received\n\n" .
            "Name: %s %s\n" .
            "Email: %s\n" .
            "Phone: %s\n\n" .
            "Tour Type: %s\n" .
            "Preferred Date: %s\n" .
            "Preferred Time: %s\n\n" .
            "Property: %s\n" .
            "%s\n\n" .
            "Message:\n%s\n\n" .
            "---\n" .
            "Submitted via: %s\n" .
            "Date: %s",
            $first_name,
            $last_name,
            $email,
            $phone ?: 'Not provided',
            $tour_type_display,
            $preferred_date,
            $preferred_time ?: 'Flexible',
            $property_address ?: 'Not specified',
            $property_mls ? 'MLS#: ' . $property_mls : '',
            $message ?: 'None',
            home_url(),
            current_time('F j, Y g:i a')
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', $first_name . ' ' . $last_name, $email),
        );

        wp_mail($agent_email, $subject, $body, $headers);

        // Send confirmation email to user
        $confirmation_subject = 'Your Tour Request Has Been Received';
        $confirmation_body = sprintf(
            "Dear %s,\n\n" .
            "Thank you for scheduling a property tour!\n\n" .
            "Tour Details:\n" .
            "- Type: %s\n" .
            "- Date: %s\n" .
            "- Time: %s\n" .
            "%s\n\n" .
            "I'll confirm your tour within a few hours. If you have any questions, please don't hesitate to reach out.\n\n" .
            "Best regards,\n" .
            "%s\n" .
            "%s\n" .
            "%s",
            $first_name,
            $tour_type_display,
            $preferred_date,
            $preferred_time ?: 'Flexible',
            $property_address ? 'Property: ' . $property_address : '',
            $agent_name,
            get_theme_mod('bne_phone_number', ''),
            $agent_email
        );

        $confirmation_headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $agent_name, $agent_email),
        );

        wp_mail($email, $confirmation_subject, $confirmation_body, $confirmation_headers);

        wp_send_json_success(array(
            'message' => 'Your tour request has been submitted! You will receive a confirmation email shortly.',
            'submission_id' => $submission_id,
        ));
    }

    /**
     * Get or create guest user for saved searches
     *
     * @param string $email User email
     * @param string $name User name
     * @return int User ID
     */
    private static function get_or_create_guest_user($email, $name = '') {
        // Check if guest user exists in WordPress
        $user = get_user_by('email', $email);
        if ($user) {
            return $user->ID;
        }

        // Check if email is registered as subscriber
        $existing_id = email_exists($email);
        if ($existing_id) {
            return $existing_id;
        }

        // Create new subscriber user
        $username = sanitize_user(current(explode('@', $email)), true);
        $username = self::generate_unique_username($username);

        $random_password = wp_generate_password(12, false);

        $user_id = wp_create_user($username, $random_password, $email);

        if (is_wp_error($user_id)) {
            // If we can't create user, return 0 to handle gracefully
            return 0;
        }

        // Update user meta
        if (!empty($name)) {
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $name,
                'display_name' => $name,
            ));
        }

        // Set role to subscriber
        $user = new WP_User($user_id);
        $user->set_role('subscriber');

        // Mark as created by lead tools
        update_user_meta($user_id, '_bne_lead_source', 'property_alerts');
        update_user_meta($user_id, '_bne_signup_date', current_time('mysql'));

        // Send welcome email with password
        $agent_name = get_theme_mod('bne_agent_name', 'Agent');
        $agent_email = get_theme_mod('bne_agent_email', get_option('admin_email'));

        $subject = 'Welcome! Your Account Has Been Created';
        $body = sprintf(
            "Hi %s,\n\n" .
            "Welcome! An account has been created for you to manage your property alerts.\n\n" .
            "Login Details:\n" .
            "Username: %s\n" .
            "Password: %s\n\n" .
            "You can log in at: %s\n\n" .
            "After logging in, you can:\n" .
            "- Manage your property alerts\n" .
            "- Save favorite properties\n" .
            "- Track your search history\n\n" .
            "Best regards,\n" .
            "%s",
            $name ?: 'there',
            $username,
            $random_password,
            wp_login_url(),
            $agent_name
        );

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $agent_name, $agent_email),
        );

        wp_mail($email, $subject, $body, $headers);

        return $user_id;
    }

    /**
     * Generate unique username
     *
     * @param string $base_username Base username
     * @return string Unique username
     */
    private static function generate_unique_username($base_username) {
        $username = $base_username;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Calculate mortgage payment
     *
     * @param float $principal Loan amount
     * @param float $annual_rate Annual interest rate (as percentage)
     * @param int $term_years Loan term in years
     * @return float Monthly payment
     */
    public static function calculate_mortgage_payment($principal, $annual_rate, $term_years) {
        $monthly_rate = ($annual_rate / 100) / 12;
        $num_payments = $term_years * 12;

        if ($monthly_rate == 0) {
            return $principal / $num_payments;
        }

        $payment = $principal * ($monthly_rate * pow(1 + $monthly_rate, $num_payments)) / (pow(1 + $monthly_rate, $num_payments) - 1);

        return round($payment, 2);
    }

    /**
     * Get default mortgage rate from settings or API
     *
     * @return float Default interest rate
     */
    public static function get_default_rate() {
        $default_rate = get_theme_mod('bne_default_mortgage_rate', 6.5);
        return floatval($default_rate);
    }
}
