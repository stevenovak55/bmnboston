<?php
/**
 * Google Calendar Integration Class
 *
 * Handles OAuth2 authentication, token management, and Google Calendar API operations.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Calendar class.
 *
 * @since 1.0.0
 */
class SNAB_Google_Calendar {

    /**
     * Google OAuth2 endpoints.
     */
    const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const OAUTH_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Google Calendar API base URL.
     */
    const API_BASE_URL = 'https://www.googleapis.com/calendar/v3';

    /**
     * Required OAuth scopes.
     */
    const OAUTH_SCOPES = array(
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
    );

    /**
     * Option keys.
     */
    const OPTION_CLIENT_ID = 'snab_google_client_id';
    const OPTION_CLIENT_SECRET = 'snab_google_client_secret';
    const OPTION_ACCESS_TOKEN = 'snab_google_access_token';
    const OPTION_REFRESH_TOKEN = 'snab_google_refresh_token';
    const OPTION_TOKEN_EXPIRES = 'snab_google_token_expires';
    const OPTION_SELECTED_CALENDAR = 'snab_google_calendar_id';

    /**
     * Single instance of the class.
     *
     * @var SNAB_Google_Calendar
     */
    private static $instance = null;

    /**
     * Current access token.
     *
     * @var string|null
     */
    private $access_token = null;

    /**
     * Get single instance.
     *
     * @return SNAB_Google_Calendar
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Register AJAX handlers for OAuth callback
        add_action('wp_ajax_snab_google_oauth_callback', array($this, 'handle_oauth_callback'));
        add_action('wp_ajax_snab_google_disconnect', array($this, 'handle_disconnect'));
        add_action('wp_ajax_snab_get_calendars', array($this, 'ajax_get_calendars'));

        // Register per-staff AJAX handlers
        add_action('wp_ajax_snab_staff_google_disconnect', array($this, 'handle_staff_disconnect'));
        add_action('wp_ajax_snab_staff_get_calendars', array($this, 'ajax_get_staff_calendars'));
        add_action('wp_ajax_snab_staff_set_calendar', array($this, 'ajax_set_staff_calendar'));
    }

    /**
     * Check if Google Calendar is configured.
     *
     * @return bool
     */
    public function is_configured() {
        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = get_option(self::OPTION_CLIENT_SECRET);
        return !empty($client_id) && !empty($client_secret);
    }

    /**
     * Check if Google Calendar is connected (has valid tokens).
     *
     * @return bool
     */
    public function is_connected() {
        $refresh_token = $this->get_refresh_token();
        return !empty($refresh_token);
    }

    /**
     * Get OAuth authorization URL.
     *
     * @return string|false Authorization URL or false if not configured.
     */
    public function get_auth_url() {
        if (!$this->is_configured()) {
            return false;
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $redirect_uri = $this->get_redirect_uri();
        $state = wp_create_nonce('snab_google_oauth');

        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => implode(' ', self::OAUTH_SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        );

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Get OAuth redirect URI.
     *
     * @return string
     */
    public function get_redirect_uri() {
        return admin_url('admin.php?page=snab-settings&snab_oauth_callback=1');
    }

    /**
     * Handle OAuth callback.
     *
     * @param string $code Authorization code from Google.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function exchange_code_for_tokens($code) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Google Calendar is not configured.', 'sn-appointment-booking'));
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = $this->get_client_secret();
        $redirect_uri = $this->get_redirect_uri();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            SNAB_Logger::error('OAuth token exchange failed', array('error' => $response->get_error_message()));
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $error_msg = isset($body['error_description']) ? $body['error_description'] : $body['error'];
            SNAB_Logger::error('OAuth token exchange error', array('error' => $error_msg));
            return new WP_Error('oauth_error', $error_msg);
        }

        if (empty($body['access_token'])) {
            return new WP_Error('no_token', __('No access token received from Google.', 'sn-appointment-booking'));
        }

        // Store tokens
        $this->store_tokens($body);

        SNAB_Logger::info('Google Calendar connected successfully');
        return true;
    }

    /**
     * Store OAuth tokens securely.
     *
     * @param array $token_data Token data from Google.
     */
    private function store_tokens($token_data) {
        // Store access token (short-lived, not encrypted)
        update_option(self::OPTION_ACCESS_TOKEN, $token_data['access_token']);

        // Store token expiration time
        $expires_at = time() + (int) $token_data['expires_in'];
        update_option(self::OPTION_TOKEN_EXPIRES, $expires_at);

        // Store refresh token (encrypted) if provided
        if (!empty($token_data['refresh_token'])) {
            $encrypted = $this->encrypt($token_data['refresh_token']);
            update_option(self::OPTION_REFRESH_TOKEN, $encrypted);
        }
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * @return string|false Access token or false on failure.
     */
    public function get_access_token() {
        if ($this->access_token) {
            return $this->access_token;
        }

        $access_token = get_option(self::OPTION_ACCESS_TOKEN);
        $expires_at = (int) get_option(self::OPTION_TOKEN_EXPIRES);

        // Check if token is still valid (with 5 minute buffer)
        if ($access_token && $expires_at > (time() + 300)) {
            $this->access_token = $access_token;
            return $access_token;
        }

        // Try to refresh the token
        $refreshed = $this->refresh_access_token();
        if ($refreshed) {
            return $this->access_token;
        }

        return false;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @return bool True on success, false on failure.
     */
    private function refresh_access_token() {
        $refresh_token = $this->get_refresh_token();
        if (empty($refresh_token)) {
            return false;
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = $this->get_client_secret();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'refresh_token',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            SNAB_Logger::error('Token refresh failed', array('error' => $response->get_error_message()));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            SNAB_Logger::error('Token refresh error', array('error' => $body['error']));
            // If refresh token is invalid, disconnect
            if ($body['error'] === 'invalid_grant') {
                $this->disconnect();
            }
            return false;
        }

        if (empty($body['access_token'])) {
            return false;
        }

        // Store the new access token
        $this->store_tokens($body);
        $this->access_token = $body['access_token'];

        return true;
    }

    /**
     * Get the decrypted refresh token.
     *
     * @return string|null
     */
    private function get_refresh_token() {
        $encrypted = get_option(self::OPTION_REFRESH_TOKEN);
        if (empty($encrypted)) {
            return null;
        }
        return $this->decrypt($encrypted);
    }

    /**
     * Get the decrypted client secret.
     *
     * @return string
     */
    private function get_client_secret() {
        return get_option(self::OPTION_CLIENT_SECRET);
    }

    /**
     * Disconnect from Google Calendar.
     *
     * @return bool
     */
    public function disconnect() {
        // Revoke token at Google
        $access_token = get_option(self::OPTION_ACCESS_TOKEN);
        if ($access_token) {
            wp_remote_post(self::OAUTH_REVOKE_URL, array(
                'body' => array('token' => $access_token),
                'timeout' => 10,
            ));
        }

        // Delete stored tokens
        delete_option(self::OPTION_ACCESS_TOKEN);
        delete_option(self::OPTION_REFRESH_TOKEN);
        delete_option(self::OPTION_TOKEN_EXPIRES);
        delete_option(self::OPTION_SELECTED_CALENDAR);

        $this->access_token = null;

        SNAB_Logger::info('Google Calendar disconnected');
        return true;
    }

    /**
     * AJAX handler for disconnect.
     */
    public function handle_disconnect() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $this->disconnect();
        wp_send_json_success(array('message' => __('Disconnected from Google Calendar.', 'sn-appointment-booking')));
    }

    // =========================================================================
    // PER-STAFF GOOGLE CALENDAR METHODS
    // =========================================================================

    /**
     * Get OAuth authorization URL for a specific staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return string|false Authorization URL or false if not configured.
     */
    public function get_staff_auth_url($staff_id) {
        if (!$this->is_configured()) {
            return false;
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $redirect_uri = $this->get_staff_redirect_uri();
        $state = wp_create_nonce('snab_staff_google_oauth_' . $staff_id) . '|' . $staff_id;

        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => implode(' ', self::OAUTH_SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        );

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Get OAuth redirect URI for staff connections.
     * Uses the same redirect URI as the main connection to avoid needing
     * to register multiple URIs in Google Cloud Console.
     *
     * @since 1.6.1
     * @return string
     */
    public function get_staff_redirect_uri() {
        // Use same redirect URI as main settings, but state parameter distinguishes staff connections
        return admin_url('admin.php?page=snab-settings&snab_oauth_callback=1');
    }

    /**
     * Exchange authorization code for tokens for a staff member.
     *
     * @since 1.6.1
     * @param string $code Authorization code from Google.
     * @param int $staff_id Staff member ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function exchange_code_for_staff_tokens($code, $staff_id) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Google Calendar is not configured.', 'sn-appointment-booking'));
        }

        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = $this->get_client_secret();
        $redirect_uri = $this->get_staff_redirect_uri();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            SNAB_Logger::error('Staff OAuth token exchange failed', array(
                'staff_id' => $staff_id,
                'error' => $response->get_error_message(),
            ));
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $error_msg = isset($body['error_description']) ? $body['error_description'] : $body['error'];
            SNAB_Logger::error('Staff OAuth token exchange error', array(
                'staff_id' => $staff_id,
                'error' => $error_msg,
            ));
            return new WP_Error('oauth_error', $error_msg);
        }

        if (empty($body['access_token'])) {
            return new WP_Error('no_token', __('No access token received from Google.', 'sn-appointment-booking'));
        }

        // Store tokens for staff
        $this->store_staff_tokens($staff_id, $body);

        SNAB_Logger::info('Staff Google Calendar connected successfully', array('staff_id' => $staff_id));
        return true;
    }

    /**
     * Store OAuth tokens for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @param array $token_data Token data from Google.
     */
    private function store_staff_tokens($staff_id, $token_data) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        $update_data = array(
            'google_access_token' => $this->encrypt($token_data['access_token']),
            'google_token_expires' => time() + (int) $token_data['expires_in'],
            'updated_at' => current_time('mysql'),
        );

        // Store refresh token if provided
        if (!empty($token_data['refresh_token'])) {
            $update_data['google_refresh_token'] = $this->encrypt($token_data['refresh_token']);
        }

        $wpdb->update(
            $staff_table,
            $update_data,
            array('id' => $staff_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Get access token for a staff member, refreshing if necessary.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return string|false Access token or false on failure.
     */
    public function get_staff_access_token($staff_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT google_access_token, google_refresh_token, google_token_expires FROM {$staff_table} WHERE id = %d",
            $staff_id
        ));

        if (!$staff || empty($staff->google_refresh_token)) {
            return false;
        }

        // Decrypt access token
        $access_token = $this->decrypt($staff->google_access_token);
        $expires_at = (int) $staff->google_token_expires;

        // Check if token is still valid (with 5 minute buffer)
        if ($access_token && $expires_at > (time() + 300)) {
            return $access_token;
        }

        // Try to refresh the token
        $refresh_token = $this->decrypt($staff->google_refresh_token);
        if (empty($refresh_token)) {
            return false;
        }

        return $this->refresh_staff_access_token($staff_id, $refresh_token);
    }

    /**
     * Refresh access token for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @param string $refresh_token Decrypted refresh token.
     * @return string|false New access token or false on failure.
     */
    private function refresh_staff_access_token($staff_id, $refresh_token) {
        $client_id = get_option(self::OPTION_CLIENT_ID);
        $client_secret = $this->get_client_secret();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, array(
            'body' => array(
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'refresh_token',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            SNAB_Logger::error('Staff token refresh failed', array(
                'staff_id' => $staff_id,
                'error' => $response->get_error_message(),
            ));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            SNAB_Logger::error('Staff token refresh error', array(
                'staff_id' => $staff_id,
                'error' => $body['error'],
            ));
            // If refresh token is invalid, disconnect staff
            if ($body['error'] === 'invalid_grant') {
                $this->disconnect_staff($staff_id);
            }
            return false;
        }

        if (empty($body['access_token'])) {
            return false;
        }

        // Store the new access token
        $this->store_staff_tokens($staff_id, $body);

        return $body['access_token'];
    }

    /**
     * Check if a staff member is connected to Google Calendar.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return bool
     */
    public function is_staff_connected($staff_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        $refresh_token = $wpdb->get_var($wpdb->prepare(
            "SELECT google_refresh_token FROM {$staff_table} WHERE id = %d",
            $staff_id
        ));

        return !empty($refresh_token);
    }

    /**
     * Get staff member's selected calendar ID.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return string Calendar ID or 'primary'.
     */
    public function get_staff_calendar($staff_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        $calendar_id = $wpdb->get_var($wpdb->prepare(
            "SELECT google_calendar_id FROM {$staff_table} WHERE id = %d",
            $staff_id
        ));

        return $calendar_id ?: 'primary';
    }

    /**
     * Set staff member's selected calendar ID.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @param string $calendar_id Calendar ID.
     * @return bool
     */
    public function set_staff_calendar($staff_id, $calendar_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        return $wpdb->update(
            $staff_table,
            array(
                'google_calendar_id' => sanitize_text_field($calendar_id),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $staff_id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Disconnect a staff member from Google Calendar.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return bool
     */
    public function disconnect_staff($staff_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        // Revoke token at Google
        $access_token = $this->get_staff_access_token($staff_id);
        if ($access_token) {
            wp_remote_post(self::OAUTH_REVOKE_URL, array(
                'body' => array('token' => $access_token),
                'timeout' => 10,
            ));
        }

        // Clear stored tokens
        $result = $wpdb->update(
            $staff_table,
            array(
                'google_access_token' => null,
                'google_refresh_token' => null,
                'google_token_expires' => null,
                'google_calendar_id' => null,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $staff_id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        SNAB_Logger::info('Staff Google Calendar disconnected', array('staff_id' => $staff_id));
        return $result !== false;
    }

    /**
     * AJAX handler for staff disconnect.
     *
     * @since 1.6.1
     */
    public function handle_staff_disconnect() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        $this->disconnect_staff($staff_id);
        wp_send_json_success(array('message' => __('Staff disconnected from Google Calendar.', 'sn-appointment-booking')));
    }

    /**
     * AJAX handler to get calendars for a staff member.
     *
     * @since 1.6.1
     */
    public function ajax_get_staff_calendars() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        $calendars = $this->get_staff_calendars($staff_id);

        if (is_wp_error($calendars)) {
            wp_send_json_error($calendars->get_error_message());
        }

        wp_send_json_success($calendars);
    }

    /**
     * Get list of calendars for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return array|WP_Error Array of calendars or WP_Error.
     */
    public function get_staff_calendars($staff_id) {
        $access_token = $this->get_staff_access_token($staff_id);
        if (!$access_token) {
            return new WP_Error('no_token', __('Staff not connected to Google Calendar.', 'sn-appointment-booking'));
        }

        $url = self::API_BASE_URL . '/users/me/calendarList?minAccessRole=writer';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) >= 400) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'API request failed';
            return new WP_Error('api_error', $error_msg);
        }

        $calendars = array();
        if (!empty($body['items'])) {
            foreach ($body['items'] as $calendar) {
                $calendars[] = array(
                    'id' => $calendar['id'],
                    'summary' => $calendar['summary'],
                    'primary' => isset($calendar['primary']) && $calendar['primary'],
                    'backgroundColor' => isset($calendar['backgroundColor']) ? $calendar['backgroundColor'] : '#3788d8',
                );
            }
        }

        return $calendars;
    }

    /**
     * AJAX handler to set calendar for a staff member.
     *
     * @since 1.6.1
     */
    public function ajax_set_staff_calendar() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
        $calendar_id = isset($_POST['calendar_id']) ? sanitize_text_field($_POST['calendar_id']) : '';

        if (!$staff_id) {
            wp_send_json_error(__('Invalid staff ID.', 'sn-appointment-booking'));
        }

        if (empty($calendar_id)) {
            wp_send_json_error(__('Please select a calendar.', 'sn-appointment-booking'));
        }

        $result = $this->set_staff_calendar($staff_id, $calendar_id);

        if ($result) {
            wp_send_json_success(array('message' => __('Calendar saved.', 'sn-appointment-booking')));
        } else {
            wp_send_json_error(__('Failed to save calendar.', 'sn-appointment-booking'));
        }
    }

    /**
     * Make an authenticated API request for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @param string $endpoint API endpoint (relative to base URL).
     * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH).
     * @param array $data Request data.
     * @return array|WP_Error Response data or WP_Error.
     */
    public function staff_api_request($staff_id, $endpoint, $method = 'GET', $data = array()) {
        $access_token = $this->get_staff_access_token($staff_id);
        if (!$access_token) {
            return new WP_Error('no_token', __('Staff not connected to Google Calendar.', 'sn-appointment-booking'));
        }

        $url = self::API_BASE_URL . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );

        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($data);
        }

        // Add query params for GET requests
        if (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            SNAB_Logger::error('Staff Google API request failed', array(
                'staff_id' => $staff_id,
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'API request failed';
            SNAB_Logger::error('Staff Google API error', array(
                'staff_id' => $staff_id,
                'endpoint' => $endpoint,
                'code' => $code,
                'error' => $error_msg,
            ));
            return new WP_Error('api_error', $error_msg);
        }

        return $body;
    }

    /**
     * Create event for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @param array $event_data Event data.
     * @return array|WP_Error Created event data or WP_Error.
     */
    public function create_staff_event($staff_id, $event_data) {
        $calendar_id = $this->get_staff_calendar($staff_id);
        $endpoint = '/calendars/' . urlencode($calendar_id) . '/events';

        $response = $this->staff_api_request($staff_id, $endpoint, 'POST', $event_data);

        if (!is_wp_error($response)) {
            SNAB_Logger::info('Staff Google Calendar event created', array(
                'staff_id' => $staff_id,
                'event_id' => $response['id'],
            ));
        }

        return $response;
    }

    /**
     * Get connection status for a staff member.
     *
     * @since 1.6.1
     * @param int $staff_id Staff member ID.
     * @return array Status information.
     */
    public function get_staff_connection_status($staff_id) {
        global $wpdb;
        $staff_table = $wpdb->prefix . 'snab_staff';

        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT google_calendar_id, google_token_expires FROM {$staff_table} WHERE id = %d",
            $staff_id
        ));

        $connected = $this->is_staff_connected($staff_id);

        $status = array(
            'configured' => $this->is_configured(),
            'connected' => $connected,
            'calendar_id' => $staff ? $staff->google_calendar_id : null,
            'calendar_name' => null,
            'token_expires' => $staff ? (int) $staff->google_token_expires : null,
        );

        if ($connected && $status['calendar_id']) {
            // Try to get calendar name
            $calendars = $this->get_staff_calendars($staff_id);
            if (!is_wp_error($calendars)) {
                foreach ($calendars as $cal) {
                    if ($cal['id'] === $status['calendar_id']) {
                        $status['calendar_name'] = $cal['summary'];
                        break;
                    }
                }
            }
        }

        return $status;
    }

    // =========================================================================
    // END PER-STAFF METHODS
    // =========================================================================

    /**
     * Make an authenticated API request to Google Calendar.
     *
     * @param string $endpoint API endpoint (relative to base URL).
     * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH).
     * @param array $data Request data.
     * @return array|WP_Error Response data or WP_Error.
     */
    public function api_request($endpoint, $method = 'GET', $data = array()) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return new WP_Error('no_token', __('Not connected to Google Calendar.', 'sn-appointment-booking'));
        }

        $url = self::API_BASE_URL . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );

        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = wp_json_encode($data);
        }

        // Add query params for GET requests
        if (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            SNAB_Logger::error('Google API request failed', array(
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'API request failed';
            SNAB_Logger::error('Google API error', array(
                'endpoint' => $endpoint,
                'code' => $code,
                'error' => $error_msg,
            ));
            return new WP_Error('api_error', $error_msg);
        }

        return $body;
    }

    /**
     * Get list of user's calendars.
     *
     * @return array|WP_Error Array of calendars or WP_Error.
     */
    public function get_calendars() {
        $response = $this->api_request('/users/me/calendarList', 'GET', array(
            'minAccessRole' => 'writer',
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $calendars = array();
        if (!empty($response['items'])) {
            foreach ($response['items'] as $calendar) {
                $calendars[] = array(
                    'id' => $calendar['id'],
                    'summary' => $calendar['summary'],
                    'primary' => isset($calendar['primary']) && $calendar['primary'],
                    'backgroundColor' => isset($calendar['backgroundColor']) ? $calendar['backgroundColor'] : '#3788d8',
                );
            }
        }

        return $calendars;
    }

    /**
     * AJAX handler to get calendars.
     */
    public function ajax_get_calendars() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $calendars = $this->get_calendars();

        if (is_wp_error($calendars)) {
            wp_send_json_error($calendars->get_error_message());
        }

        wp_send_json_success($calendars);
    }

    /**
     * Get the selected calendar ID.
     *
     * @return string Calendar ID or 'primary'.
     */
    public function get_selected_calendar() {
        return get_option(self::OPTION_SELECTED_CALENDAR, 'primary');
    }

    /**
     * Set the selected calendar ID.
     *
     * @param string $calendar_id Calendar ID.
     * @return bool
     */
    public function set_selected_calendar($calendar_id) {
        return update_option(self::OPTION_SELECTED_CALENDAR, sanitize_text_field($calendar_id));
    }

    /**
     * Query FreeBusy information for a time range.
     *
     * @param string $start_time ISO 8601 datetime string.
     * @param string $end_time ISO 8601 datetime string.
     * @param string|null $calendar_id Calendar ID (defaults to selected calendar).
     * @return array|WP_Error Array of busy periods or WP_Error.
     */
    public function get_free_busy($start_time, $end_time, $calendar_id = null) {
        if (null === $calendar_id) {
            $calendar_id = $this->get_selected_calendar();
        }

        $response = $this->api_request('/freeBusy', 'POST', array(
            'timeMin' => $start_time,
            'timeMax' => $end_time,
            'items' => array(
                array('id' => $calendar_id),
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $busy_periods = array();
        if (!empty($response['calendars'][$calendar_id]['busy'])) {
            $busy_periods = $response['calendars'][$calendar_id]['busy'];
        }

        return $busy_periods;
    }

    /**
     * Create a calendar event.
     *
     * @param array $event_data Event data.
     * @param string|null $calendar_id Calendar ID (defaults to selected calendar).
     * @return array|WP_Error Created event data or WP_Error.
     */
    public function create_event($event_data, $calendar_id = null) {
        if (null === $calendar_id) {
            $calendar_id = $this->get_selected_calendar();
        }

        $endpoint = '/calendars/' . urlencode($calendar_id) . '/events';
        $response = $this->api_request($endpoint, 'POST', $event_data);

        if (is_wp_error($response)) {
            return $response;
        }

        SNAB_Logger::info('Google Calendar event created', array('event_id' => $response['id']));
        return $response;
    }

    /**
     * Update a calendar event.
     *
     * @param string $event_id Event ID.
     * @param array $event_data Event data to update.
     * @param string|null $calendar_id Calendar ID (defaults to selected calendar).
     * @return array|WP_Error Updated event data or WP_Error.
     */
    public function update_event($event_id, $event_data, $calendar_id = null) {
        if (null === $calendar_id) {
            $calendar_id = $this->get_selected_calendar();
        }

        $endpoint = '/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
        $response = $this->api_request($endpoint, 'PATCH', $event_data);

        if (is_wp_error($response)) {
            return $response;
        }

        SNAB_Logger::info('Google Calendar event updated', array('event_id' => $event_id));
        return $response;
    }

    /**
     * Delete a calendar event.
     *
     * @param string $event_id Event ID.
     * @param string|null $calendar_id Calendar ID (defaults to selected calendar).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_event($event_id, $calendar_id = null) {
        if (null === $calendar_id) {
            $calendar_id = $this->get_selected_calendar();
        }

        $endpoint = '/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
        $response = $this->api_request($endpoint, 'DELETE');

        if (is_wp_error($response)) {
            return $response;
        }

        SNAB_Logger::info('Google Calendar event deleted', array('event_id' => $event_id));
        return true;
    }

    /**
     * Get a single calendar event.
     *
     * @param string $event_id Event ID.
     * @param string|null $calendar_id Calendar ID (defaults to selected calendar).
     * @return array|WP_Error Event data or WP_Error.
     */
    public function get_event($event_id, $calendar_id = null) {
        if (null === $calendar_id) {
            $calendar_id = $this->get_selected_calendar();
        }

        $endpoint = '/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id);
        return $this->api_request($endpoint, 'GET');
    }

    /**
     * Build event data for an appointment.
     *
     * @param object $appointment Appointment database row.
     * @param object $appointment_type Appointment type database row.
     * @return array Event data for Google Calendar API.
     */
    public function build_event_data($appointment, $appointment_type) {
        $timezone = wp_timezone_string();

        // Build datetime strings
        $start_datetime = $appointment->appointment_date . 'T' . $appointment->start_time;
        $end_datetime = $appointment->appointment_date . 'T' . $appointment->end_time;

        // Build event summary and description
        $summary = sprintf(
            '%s - %s',
            $appointment_type->name,
            $appointment->client_name
        );

        $description_parts = array();
        $description_parts[] = sprintf(__('Type: %s', 'sn-appointment-booking'), $appointment_type->name);
        $description_parts[] = sprintf(__('Client: %s', 'sn-appointment-booking'), $appointment->client_name);
        $description_parts[] = sprintf(__('Email: %s', 'sn-appointment-booking'), $appointment->client_email);

        if (!empty($appointment->client_phone)) {
            $description_parts[] = sprintf(__('Phone: %s', 'sn-appointment-booking'), $appointment->client_phone);
        }

        if (!empty($appointment->property_address)) {
            $description_parts[] = sprintf(__('Property: %s', 'sn-appointment-booking'), $appointment->property_address);
        }

        if (!empty($appointment->client_notes)) {
            $description_parts[] = '';
            $description_parts[] = __('Client Notes:', 'sn-appointment-booking');
            $description_parts[] = $appointment->client_notes;
        }

        // Build event data
        $event_data = array(
            'summary' => $summary,
            'description' => implode("\n", $description_parts),
            'start' => array(
                'dateTime' => $start_datetime,
                'timeZone' => $timezone,
            ),
            'end' => array(
                'dateTime' => $end_datetime,
                'timeZone' => $timezone,
            ),
            'colorId' => $this->hex_to_google_color_id($appointment_type->color),
            'reminders' => array(
                'useDefault' => false,
                'overrides' => array(
                    array('method' => 'popup', 'minutes' => 60),
                    array('method' => 'popup', 'minutes' => 15),
                ),
            ),
        );

        // Add location if property address exists
        if (!empty($appointment->property_address)) {
            $event_data['location'] = $appointment->property_address;
        }

        // Add attendee (client email)
        $event_data['attendees'] = array(
            array(
                'email' => $appointment->client_email,
                'displayName' => $appointment->client_name,
            ),
        );

        return $event_data;
    }

    /**
     * Convert hex color to Google Calendar color ID.
     *
     * Google Calendar only supports specific color IDs (1-11).
     * This maps common colors to the closest Google color.
     *
     * @param string $hex_color Hex color code (e.g., #3788d8).
     * @return string Google Calendar color ID.
     */
    private function hex_to_google_color_id($hex_color) {
        // Google Calendar color IDs and their approximate hex values
        $google_colors = array(
            '1' => '#7986cb',  // Lavender
            '2' => '#33b679',  // Sage
            '3' => '#8e24aa',  // Grape
            '4' => '#e67c73',  // Flamingo
            '5' => '#f6bf26',  // Banana
            '6' => '#f4511e',  // Tangerine
            '7' => '#039be5',  // Peacock
            '8' => '#616161',  // Graphite
            '9' => '#3f51b5',  // Blueberry
            '10' => '#0b8043', // Basil
            '11' => '#d50000', // Tomato
        );

        // Convert hex to RGB
        $hex = ltrim($hex_color, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Find closest color
        $closest_id = '7'; // Default to Peacock (blue)
        $closest_distance = PHP_INT_MAX;

        foreach ($google_colors as $id => $google_hex) {
            $gh = ltrim($google_hex, '#');
            $gr = hexdec(substr($gh, 0, 2));
            $gg = hexdec(substr($gh, 2, 2));
            $gb = hexdec(substr($gh, 4, 2));

            // Euclidean distance in RGB space
            $distance = sqrt(pow($r - $gr, 2) + pow($g - $gg, 2) + pow($b - $gb, 2));

            if ($distance < $closest_distance) {
                $closest_distance = $distance;
                $closest_id = $id;
            }
        }

        return $closest_id;
    }

    /**
     * Encrypt a string using WordPress auth key.
     *
     * @param string $data Data to encrypt.
     * @return string Encrypted and base64 encoded data.
     */
    private function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);

        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string.
     *
     * @param string $data Encrypted and base64 encoded data.
     * @return string|false Decrypted data or false on failure.
     */
    private function decrypt($data) {
        if (empty($data)) {
            return '';
        }

        $key = $this->get_encryption_key();
        $data = base64_decode($data);

        if ($data === false) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Get encryption key derived from WordPress auth key.
     *
     * @return string 32-byte encryption key.
     */
    private function get_encryption_key() {
        // Use AUTH_KEY constant if available, otherwise use a site-specific fallback
        $base_key = defined('AUTH_KEY') ? AUTH_KEY : md5(site_url());
        return hash('sha256', $base_key . 'snab_encryption', true);
    }

    /**
     * Get connection status information.
     *
     * @return array Status information.
     */
    public function get_connection_status() {
        $status = array(
            'configured' => $this->is_configured(),
            'connected' => $this->is_connected(),
            'calendar_id' => null,
            'calendar_name' => null,
            'token_expires' => null,
        );

        if ($status['connected']) {
            $status['calendar_id'] = $this->get_selected_calendar();
            $status['token_expires'] = get_option(self::OPTION_TOKEN_EXPIRES);

            // Try to get calendar name
            $calendars = $this->get_calendars();
            if (!is_wp_error($calendars)) {
                foreach ($calendars as $cal) {
                    if ($cal['id'] === $status['calendar_id']) {
                        $status['calendar_name'] = $cal['summary'];
                        break;
                    }
                }
            }
        }

        return $status;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserializing.
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Get the Google Calendar instance.
 *
 * @return SNAB_Google_Calendar
 */
function snab_google_calendar() {
    return SNAB_Google_Calendar::instance();
}
