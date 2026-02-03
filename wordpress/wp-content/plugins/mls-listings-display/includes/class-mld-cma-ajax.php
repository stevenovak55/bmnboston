<?php
/**
 * MLD CMA AJAX Handler
 *
 * Handles AJAX requests for CMA PDF generation and email delivery
 *
 * @package MLS_Listings_Display
 * @subpackage CMA
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_CMA_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public function __construct() {
        // PDF Generation
        add_action('wp_ajax_mld_generate_cma_pdf', array($this, 'generate_pdf'));
        add_action('wp_ajax_nopriv_mld_generate_cma_pdf', array($this, 'generate_pdf'));

        // Email Delivery
        add_action('wp_ajax_mld_send_cma_email', array($this, 'send_email'));
        add_action('wp_ajax_nopriv_mld_send_cma_email', array($this, 'send_email'));

        // Combined: Generate PDF and Email
        add_action('wp_ajax_mld_generate_and_email_cma', array($this, 'generate_and_email'));
        add_action('wp_ajax_nopriv_mld_generate_and_email_cma', array($this, 'generate_and_email'));

        // CMA Session Management (logged-in users only)
        add_action('wp_ajax_mld_save_cma_session', array($this, 'save_cma_session'));
        add_action('wp_ajax_mld_update_cma_session', array($this, 'update_cma_session'));
        add_action('wp_ajax_mld_load_cma_session', array($this, 'load_cma_session'));
        add_action('wp_ajax_mld_delete_cma_session', array($this, 'delete_cma_session'));
        add_action('wp_ajax_mld_list_cma_sessions', array($this, 'list_cma_sessions'));
        add_action('wp_ajax_mld_toggle_cma_favorite', array($this, 'toggle_cma_favorite'));

        // Standalone CMA (v6.17.0) - works for both logged-in and anonymous users
        add_action('wp_ajax_mld_create_standalone_cma', array($this, 'create_standalone_cma'));
        add_action('wp_ajax_nopriv_mld_create_standalone_cma', array($this, 'create_standalone_cma'));
        add_action('wp_ajax_mld_claim_standalone_cma', array($this, 'claim_standalone_cma'));
        add_action('wp_ajax_mld_update_standalone_cma', array($this, 'update_standalone_cma'));

        // Market Conditions (v6.18.0)
        add_action('wp_ajax_mld_get_market_conditions', array($this, 'get_market_conditions'));
        add_action('wp_ajax_nopriv_mld_get_market_conditions', array($this, 'get_market_conditions'));

        // CMA Value History (v6.20.0)
        add_action('wp_ajax_mld_get_cma_history', array($this, 'get_cma_history'));
        add_action('wp_ajax_nopriv_mld_get_cma_history', array($this, 'get_cma_history'));
        add_action('wp_ajax_mld_get_cma_value_trend', array($this, 'get_cma_value_trend'));
        add_action('wp_ajax_nopriv_mld_get_cma_value_trend', array($this, 'get_cma_value_trend'));
    }

    /**
     * Generate CMA PDF
     */
    public function generate_pdf() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get parameters
        $subject_property = $this->get_subject_property_from_request();
        $cma_filters = $this->get_cma_filters_from_request();
        $pdf_options = $this->get_pdf_options_from_request();

        if (!$subject_property) {
            wp_send_json_error(array(
                'message' => 'Invalid subject property data'
            ));
        }

        // Generate CMA data
        require_once plugin_dir_path(__FILE__) . 'class-mld-comparable-sales.php';
        $comparable_sales = new MLD_Comparable_Sales();
        $cma_data = $comparable_sales->find_comparables($subject_property, $cma_filters);

        // Generate PDF
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-pdf-generator.php';
        $pdf_generator = new MLD_CMA_PDF_Generator();

        $pdf_path = $pdf_generator->generate_report($cma_data, $subject_property, $pdf_options);

        if ($pdf_path && file_exists($pdf_path)) {
            // Generate download URL
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);

            wp_send_json_success(array(
                'message' => 'PDF generated successfully',
                'pdf_url' => $pdf_url,
                'pdf_path' => $pdf_path,
                'filename' => basename($pdf_path)
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Failed to generate PDF. Please check TCPDF library installation.'
            ));
        }
    }

    /**
     * Send CMA email
     */
    public function send_email() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get email parameters
        $email_params = array(
            'recipient_email' => sanitize_email($_POST['recipient_email'] ?? ''),
            'recipient_name' => sanitize_text_field($_POST['recipient_name'] ?? ''),
            'subject' => sanitize_text_field($_POST['subject'] ?? ''),
            'property_address' => sanitize_text_field($_POST['property_address'] ?? ''),
            'agent_name' => sanitize_text_field($_POST['agent_name'] ?? ''),
            'agent_email' => sanitize_email($_POST['agent_email'] ?? ''),
            'agent_phone' => sanitize_text_field($_POST['agent_phone'] ?? ''),
            'brokerage_name' => sanitize_text_field($_POST['brokerage_name'] ?? ''),
            'cc_email' => sanitize_email($_POST['cc_email'] ?? ''),
            'pdf_path' => sanitize_text_field($_POST['pdf_path'] ?? ''),
            'estimated_value_low' => intval($_POST['estimated_value_low'] ?? 0),
            'estimated_value_high' => intval($_POST['estimated_value_high'] ?? 0),
            'template' => sanitize_text_field($_POST['template'] ?? 'default'),
            'custom_message' => wp_kses_post($_POST['custom_message'] ?? ''),
        );

        // Send email
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-email.php';
        $email_service = new MLD_CMA_Email();

        $result = $email_service->send_report($email_params);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Generate PDF and send email (combined operation)
     */
    public function generate_and_email() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Step 1: Generate PDF
        $subject_property = $this->get_subject_property_from_request();
        $cma_filters = $this->get_cma_filters_from_request();
        $pdf_options = $this->get_pdf_options_from_request();

        if (!$subject_property) {
            wp_send_json_error(array(
                'message' => 'Invalid subject property data'
            ));
        }

        // Generate CMA data
        require_once plugin_dir_path(__FILE__) . 'class-mld-comparable-sales.php';
        $comparable_sales = new MLD_Comparable_Sales();
        $cma_data = $comparable_sales->find_comparables($subject_property, $cma_filters);

        // Generate PDF
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-pdf-generator.php';
        $pdf_generator = new MLD_CMA_PDF_Generator();

        $pdf_path = $pdf_generator->generate_report($cma_data, $subject_property, $pdf_options);

        if (!$pdf_path || !file_exists($pdf_path)) {
            wp_send_json_error(array(
                'message' => 'Failed to generate PDF report'
            ));
        }

        // Step 2: Send Email with PDF attachment
        $email_params = array(
            'recipient_email' => sanitize_email($_POST['recipient_email'] ?? ''),
            'recipient_name' => sanitize_text_field($_POST['recipient_name'] ?? ''),
            'subject' => sanitize_text_field($_POST['subject'] ?? ''),
            'property_address' => $subject_property['address'] ?? '',
            'agent_name' => $pdf_options['agent_name'] ?? '',
            'agent_email' => $pdf_options['agent_email'] ?? '',
            'agent_phone' => $pdf_options['agent_phone'] ?? '',
            'brokerage_name' => $pdf_options['brokerage_name'] ?? '',
            'cc_email' => sanitize_email($_POST['cc_email'] ?? ''),
            'pdf_path' => $pdf_path,
            'estimated_value_low' => $cma_data['summary']['estimated_value']['low'] ?? 0,
            'estimated_value_high' => $cma_data['summary']['estimated_value']['high'] ?? 0,
            'template' => sanitize_text_field($_POST['email_template'] ?? 'default'),
            'custom_message' => wp_kses_post($_POST['custom_message'] ?? ''),
        );

        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-email.php';
        $email_service = new MLD_CMA_Email();

        $result = $email_service->send_report($email_params);

        // Generate download URL for PDF
        $upload_dir = wp_upload_dir();
        $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'CMA report generated and emailed successfully',
                'pdf_url' => $pdf_url,
                'email_sent' => true
            ));
        } else {
            // PDF was generated but email failed
            wp_send_json_success(array(
                'message' => 'PDF generated but email failed: ' . $result['message'],
                'pdf_url' => $pdf_url,
                'email_sent' => false,
                'email_error' => $result['message']
            ));
        }
    }

    /**
     * Send test email
     */
    public function test_email() {
        // Verify nonce - use wp_verify_nonce instead of check_ajax_referer
        // check_ajax_referer also checks HTTP Referer which fails with tracking prevention
        // Admin sends 'mld_cma_nonce', not 'mld_ajax_nonce'
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'mld_cma_nonce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD CMA Ajax: Nonce verification failed for test_email');
            }
            wp_send_json_error(array(
                'message' => 'Nonce verification failed'
            ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'Unauthorized access'
            ));
        }

        $test_email = sanitize_email($_POST['test_email'] ?? '');

        if (empty($test_email)) {
            wp_send_json_error(array(
                'message' => 'Test email address is required'
            ));
        }

        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-email.php';
        $email_service = new MLD_CMA_Email();

        $result = $email_service->send_test_email($test_email);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Save a new CMA session
     *
     * @since 6.16.0
     */
    public function save_cma_session() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to save CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();

        // Get session data from request
        $session_data = array(
            'user_id' => $user_id,
            'session_name' => sanitize_text_field($_POST['session_name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'subject_listing_id' => sanitize_text_field($_POST['subject_listing_id'] ?? ''),
            'subject_property_data' => $_POST['subject_property_data'] ?? array(),
            'subject_overrides' => $_POST['subject_overrides'] ?? null,
            'cma_filters' => $_POST['cma_filters'] ?? array(),
            'comparables_data' => $_POST['comparables_data'] ?? array(),
            'summary_statistics' => $_POST['summary_statistics'] ?? array(),
            'comparables_count' => intval($_POST['comparables_count'] ?? 0),
            'estimated_value_mid' => floatval($_POST['estimated_value_mid'] ?? 0),
        );

        // Validate required fields
        if (empty($session_data['session_name'])) {
            wp_send_json_error(array(
                'message' => 'Session name is required'
            ));
            return;
        }

        if (empty($session_data['subject_listing_id'])) {
            wp_send_json_error(array(
                'message' => 'Subject listing ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        // Check if this is a standalone CMA update (v6.20.2)
        $standalone_session_id = isset($_POST['standalone_session_id']) ? absint($_POST['standalone_session_id']) : 0;

        if ($standalone_session_id > 0) {
            // Verify the user owns this session or it's unclaimed
            $existing = MLD_CMA_Sessions::get_session($standalone_session_id);
            if ($existing && ($existing['user_id'] == $user_id || absint($existing['user_id']) === 0)) {
                // Update existing standalone session
                $result = MLD_CMA_Sessions::update_session($standalone_session_id, $session_data);
                if (!is_wp_error($result)) {
                    wp_send_json_success(array(
                        'message' => 'Standalone CMA updated successfully',
                        'session_id' => $standalone_session_id
                    ));
                    return;
                }
            } else {
                // Cannot update - create new session instead
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD CMA AJAX] Cannot update standalone session ' . $standalone_session_id . ' - user does not own it');
                }
            }
        }

        $result = MLD_CMA_Sessions::save_session($session_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA session saved successfully',
            'session_id' => $result
        ));
    }

    /**
     * Update an existing CMA session
     *
     * @since 6.16.0
     */
    public function update_cma_session() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to update CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        // Verify ownership
        if (!MLD_CMA_Sessions::user_owns_session($session_id, $user_id)) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to update this CMA session'
            ));
            return;
        }

        // Prepare update data
        $update_data = array();

        if (isset($_POST['session_name'])) {
            $update_data['session_name'] = sanitize_text_field($_POST['session_name']);
        }
        if (isset($_POST['description'])) {
            $update_data['description'] = sanitize_textarea_field($_POST['description']);
        }
        if (isset($_POST['subject_property_data'])) {
            $update_data['subject_property_data'] = $_POST['subject_property_data'];
        }
        if (isset($_POST['subject_overrides'])) {
            $update_data['subject_overrides'] = $_POST['subject_overrides'];
        }
        if (isset($_POST['cma_filters'])) {
            $update_data['cma_filters'] = $_POST['cma_filters'];
        }
        if (isset($_POST['comparables_data'])) {
            $update_data['comparables_data'] = $_POST['comparables_data'];
        }
        if (isset($_POST['summary_statistics'])) {
            $update_data['summary_statistics'] = $_POST['summary_statistics'];
        }
        if (isset($_POST['comparables_count'])) {
            $update_data['comparables_count'] = intval($_POST['comparables_count']);
        }
        if (isset($_POST['estimated_value_mid'])) {
            $update_data['estimated_value_mid'] = floatval($_POST['estimated_value_mid']);
        }

        $result = MLD_CMA_Sessions::update_session($session_id, $update_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA session updated successfully'
        ));
    }

    /**
     * Load a saved CMA session
     *
     * @since 6.16.0
     */
    public function load_cma_session() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to load CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        // Verify ownership
        if (!MLD_CMA_Sessions::user_owns_session($session_id, $user_id)) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to access this CMA session'
            ));
            return;
        }

        $session = MLD_CMA_Sessions::get_session($session_id);

        if (!$session) {
            wp_send_json_error(array(
                'message' => 'CMA session not found'
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA session loaded successfully',
            'session' => $session
        ));
    }

    /**
     * Delete a CMA session
     *
     * @since 6.16.0
     */
    public function delete_cma_session() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to delete CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        $result = MLD_CMA_Sessions::delete_session($session_id, $user_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA session deleted successfully'
        ));
    }

    /**
     * List user's saved CMA sessions
     *
     * @since 6.16.0
     */
    public function list_cma_sessions() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to view CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();

        // Parse optional arguments
        $args = array(
            'limit' => intval($_POST['limit'] ?? 50),
            'offset' => intval($_POST['offset'] ?? 0),
            'order_by' => sanitize_text_field($_POST['order_by'] ?? 'created_at'),
            'order' => sanitize_text_field($_POST['order'] ?? 'DESC'),
        );

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        $sessions = MLD_CMA_Sessions::get_user_sessions($user_id, $args);
        $total_count = MLD_CMA_Sessions::get_user_session_count($user_id);

        wp_send_json_success(array(
            'sessions' => $sessions,
            'total_count' => $total_count,
            'limit' => $args['limit'],
            'offset' => $args['offset']
        ));
    }

    /**
     * Toggle favorite status for a CMA session
     *
     * @since 6.16.0
     */
    public function toggle_cma_favorite() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to modify CMA sessions'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        $result = MLD_CMA_Sessions::toggle_favorite($session_id, $user_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'Favorite status updated',
            'is_favorite' => $result
        ));
    }

    /**
     * Get subject property data from request
     *
     * @return array|false Subject property data or false
     */
    private function get_subject_property_from_request() {
        if (empty($_POST['subject_property'])) {
            return false;
        }

        $subject = $_POST['subject_property'];

        return array(
            'mlsNumber' => sanitize_text_field($subject['mlsNumber'] ?? ''),
            'address' => sanitize_text_field($subject['address'] ?? ''),
            'city' => sanitize_text_field($subject['city'] ?? ''),
            'state' => sanitize_text_field($subject['state'] ?? ''),
            'postal_code' => sanitize_text_field($subject['postal_code'] ?? ''),
            'lat' => floatval($subject['lat'] ?? 0),
            'lng' => floatval($subject['lng'] ?? 0),
            'price' => floatval($subject['price'] ?? 0),
            'beds' => intval($subject['beds'] ?? 0),
            'baths' => floatval($subject['baths'] ?? 0),
            'sqft' => floatval($subject['sqft'] ?? 0),
            'year_built' => intval($subject['year_built'] ?? 0),
            'lot_size' => floatval($subject['lot_size'] ?? 0),
            'garage_spaces' => intval($subject['garage_spaces'] ?? 0),
            'pool' => intval($subject['pool'] ?? 0),
            'waterfront' => intval($subject['waterfront'] ?? 0),
            'property_type' => sanitize_text_field($subject['property_type'] ?? 'all'),
        );
    }

    /**
     * Get CMA filters from request
     *
     * @return array CMA filters
     */
    private function get_cma_filters_from_request() {
        $filters = $_POST['filters'] ?? array();

        return array(
            'radius' => floatval($filters['radius'] ?? 3),
            'price_range_pct' => intval($filters['price_range_pct'] ?? 15),
            'sqft_range_pct' => intval($filters['sqft_range_pct'] ?? 20),
            'beds_min' => isset($filters['beds_min']) ? intval($filters['beds_min']) : null,
            'beds_max' => isset($filters['beds_max']) ? intval($filters['beds_max']) : null,
            'baths_min' => isset($filters['baths_min']) ? floatval($filters['baths_min']) : null,
            'baths_max' => isset($filters['baths_max']) ? floatval($filters['baths_max']) : null,
            'garage_min' => isset($filters['garage_min']) ? intval($filters['garage_min']) : null,
            'year_built_range' => intval($filters['year_built_range'] ?? 10),
            'statuses' => isset($filters['statuses']) ? array_map('sanitize_text_field', (array)$filters['statuses']) : array('Closed'),
            'time_range_months' => intval($filters['time_range_months'] ?? 12),
            'limit' => intval($filters['limit'] ?? 20),
        );
    }

    /**
     * Get PDF options from request
     *
     * @return array PDF options
     */
    private function get_pdf_options_from_request() {
        $options = $_POST['pdf_options'] ?? array();

        return array(
            'report_title' => sanitize_text_field($options['report_title'] ?? 'Comparative Market Analysis'),
            'agent_name' => sanitize_text_field($options['agent_name'] ?? ''),
            'agent_email' => sanitize_email($options['agent_email'] ?? ''),
            'agent_phone' => sanitize_text_field($options['agent_phone'] ?? ''),
            'agent_license' => sanitize_text_field($options['agent_license'] ?? ''),
            'brokerage_name' => sanitize_text_field($options['brokerage_name'] ?? ''),
            'prepared_for' => sanitize_text_field($options['prepared_for'] ?? ''),
            'report_date' => sanitize_text_field($options['report_date'] ?? date('F j, Y')),
            'include_photos' => isset($options['include_photos']) ? boolval($options['include_photos']) : true,
            'include_forecast' => isset($options['include_forecast']) ? boolval($options['include_forecast']) : true,
            'include_investment' => isset($options['include_investment']) ? boolval($options['include_investment']) : true,
        );
    }

    /**
     * Create a standalone CMA (no MLS listing required)
     * Works for both logged-in and anonymous users
     *
     * @since 6.17.0
     */
    public function create_standalone_cma() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get user ID (0 for anonymous)
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        // Validate required fields
        $address = sanitize_text_field($_POST['address'] ?? '');
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? 'MA');
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $beds = intval($_POST['beds'] ?? 0);
        $baths = floatval($_POST['baths'] ?? 0);
        $sqft = intval($_POST['sqft'] ?? 0);
        $price = floatval($_POST['price'] ?? 0);

        // Validate required fields
        if (empty($address)) {
            wp_send_json_error(array('message' => 'Property address is required'));
            return;
        }

        if (empty($city)) {
            wp_send_json_error(array('message' => 'City is required'));
            return;
        }

        if ($lat == 0 || $lng == 0) {
            wp_send_json_error(array('message' => 'Valid coordinates are required. Please verify the address.'));
            return;
        }

        if ($beds <= 0) {
            wp_send_json_error(array('message' => 'Number of bedrooms is required'));
            return;
        }

        if ($baths <= 0) {
            wp_send_json_error(array('message' => 'Number of bathrooms is required'));
            return;
        }

        if ($sqft <= 0) {
            wp_send_json_error(array('message' => 'Square footage is required'));
            return;
        }

        if ($price <= 0) {
            wp_send_json_error(array('message' => 'Estimated value/price is required'));
            return;
        }

        // Build session data with subject_property_data nested as expected by save_standalone_session
        $session_data = array(
            'user_id' => $user_id,
            'session_name' => sanitize_text_field($_POST['session_name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'subject_property_data' => array(
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
                'lat' => $lat,
                'lng' => $lng,
                'beds' => $beds,
                'baths' => $baths,
                'sqft' => $sqft,
                'price' => $price,
                'property_type' => sanitize_text_field($_POST['property_type'] ?? 'Single Family Residence'),
                'year_built' => intval($_POST['year_built'] ?? 0),
                'garage_spaces' => intval($_POST['garage_spaces'] ?? 0),
                'pool' => !empty($_POST['pool']),
                'waterfront' => !empty($_POST['waterfront']),
                'road_type' => sanitize_text_field($_POST['road_type'] ?? 'unknown'),
                'property_condition' => sanitize_text_field($_POST['property_condition'] ?? 'unknown'),
            ),
        );

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        $result = MLD_CMA_Sessions::save_standalone_session($session_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        // save_standalone_session returns array with session_id, slug, subject_listing_id
        $session_id = $result['session_id'];
        $slug = $result['slug'];

        wp_send_json_success(array(
            'message' => 'Standalone CMA created successfully',
            'session_id' => $session_id,
            'slug' => $slug,
            'redirect_url' => home_url('/cma/' . $slug . '/')
        ));
    }

    /**
     * Claim an anonymous standalone CMA for logged-in user
     *
     * @since 6.17.0
     */
    public function claim_standalone_cma() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to claim a CMA'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        $result = MLD_CMA_Sessions::claim_session($session_id, $user_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA saved to your account successfully'
        ));
    }

    /**
     * Update standalone CMA details (for owners)
     *
     * @since 6.17.0
     */
    public function update_standalone_cma() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Require logged-in user
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'You must be logged in to update CMA details'
            ));
            return;
        }

        $user_id = get_current_user_id();
        $session_id = intval($_POST['session_id'] ?? 0);

        if (!$session_id) {
            wp_send_json_error(array(
                'message' => 'Session ID is required'
            ));
            return;
        }

        // Include the sessions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-sessions.php';

        // Verify ownership
        if (!MLD_CMA_Sessions::user_owns_session($session_id, $user_id)) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to update this CMA'
            ));
            return;
        }

        // Prepare update data (only allow name and description updates)
        $update_data = array();

        if (isset($_POST['session_name'])) {
            $update_data['session_name'] = sanitize_text_field($_POST['session_name']);
        }
        if (isset($_POST['description'])) {
            $update_data['description'] = sanitize_textarea_field($_POST['description']);
        }

        if (empty($update_data)) {
            wp_send_json_error(array(
                'message' => 'No data provided to update'
            ));
            return;
        }

        $result = MLD_CMA_Sessions::update_session($session_id, $update_data);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => 'CMA details updated successfully'
        ));
    }

    /**
     * Get market conditions data
     *
     * Returns comprehensive market analysis including DOM trends,
     * list-to-sale ratios, inventory, and price trends.
     *
     * @since 6.18.0
     */
    public function get_market_conditions() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get parameters
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $property_type = sanitize_text_field($_POST['property_type'] ?? 'all');
        $months = intval($_POST['months'] ?? 12);

        // Validate required parameters
        if (empty($city)) {
            wp_send_json_error(array(
                'message' => 'City is required for market conditions analysis'
            ));
            return;
        }

        // Limit months to reasonable range
        $months = max(3, min(24, $months));

        // Include and instantiate the Market Conditions class
        require_once plugin_dir_path(__FILE__) . 'class-mld-market-conditions.php';
        $market_conditions = new MLD_Market_Conditions();

        // Get market conditions data
        $conditions = $market_conditions->get_market_conditions($city, $state, $property_type, $months);

        if ($conditions['success']) {
            // Add sparkline data for charts
            $conditions['sparklines'] = array(
                'dom' => $market_conditions->get_sparkline_data(
                    $conditions['days_on_market']['monthly'] ?? array(),
                    'avg_dom'
                ),
                'ratio' => $market_conditions->get_sparkline_data(
                    $conditions['list_to_sale_ratio']['monthly'] ?? array(),
                    'percentage'
                ),
                'price' => $market_conditions->get_sparkline_data(
                    $conditions['price_trends']['monthly'] ?? array(),
                    'avg_price'
                ),
                'price_per_sqft' => $market_conditions->get_sparkline_data(
                    $conditions['price_trends']['monthly'] ?? array(),
                    'avg_price_per_sqft'
                ),
            );

            wp_send_json_success($conditions);
        } else {
            wp_send_json_error(array(
                'message' => 'Failed to retrieve market conditions data'
            ));
        }
    }

    /**
     * Get CMA value history for a property
     *
     * Returns historical CMA valuations for trend analysis.
     *
     * @since 6.20.0
     */
    public function get_cma_history() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get parameters
        $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');
        $address = sanitize_text_field($_POST['address'] ?? '');
        $city = sanitize_text_field($_POST['city'] ?? '');
        $limit = intval($_POST['limit'] ?? 20);

        // Validate - need either listing_id or address
        if (empty($listing_id) && empty($address)) {
            wp_send_json_error(array(
                'message' => 'Either listing_id or address is required'
            ));
            return;
        }

        // Limit to reasonable range
        $limit = max(1, min(100, $limit));

        // Include and instantiate the CMA History class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-history.php';
        $history = new MLD_CMA_History();

        // Check if table exists
        if (!$history->table_exists()) {
            wp_send_json_error(array(
                'message' => 'CMA history table not initialized',
                'has_history' => false
            ));
            return;
        }

        // Get history - prefer listing_id
        if (!empty($listing_id)) {
            $records = $history->get_history_by_listing_id($listing_id, $limit);
        } else {
            $records = $history->get_property_history($address, $city, '', $limit);
        }

        wp_send_json_success(array(
            'has_history' => !empty($records),
            'records' => $records,
            'count' => count($records)
        ));
    }

    /**
     * Get CMA value trend data for charting
     *
     * Returns trend data formatted for chart visualization.
     *
     * @since 6.20.0
     */
    public function get_cma_value_trend() {
        // Verify nonce
        check_ajax_referer('mld_ajax_nonce', 'nonce');

        // Get parameters
        $listing_id = sanitize_text_field($_POST['listing_id'] ?? '');
        $months = intval($_POST['months'] ?? 12);

        // Validate
        if (empty($listing_id)) {
            wp_send_json_error(array(
                'message' => 'listing_id is required'
            ));
            return;
        }

        // Limit months to reasonable range
        $months = max(3, min(36, $months));

        // Include and instantiate the CMA History class
        require_once plugin_dir_path(__FILE__) . 'class-mld-cma-history.php';
        $history = new MLD_CMA_History();

        // Check if table exists
        if (!$history->table_exists()) {
            wp_send_json_success(array(
                'has_history' => false,
                'data_points' => array(),
                'summary' => null,
                'statistics' => null
            ));
            return;
        }

        // Get trend data
        $trend = $history->get_value_trend($listing_id, $months);

        // Also get overall statistics
        $statistics = $history->get_value_statistics($listing_id);

        wp_send_json_success(array_merge($trend, array(
            'statistics' => $statistics
        )));
    }
}

// Initialize AJAX handlers
new MLD_CMA_Ajax();
