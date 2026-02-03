<?php
/**
 * MLD Push Notifications
 *
 * Handles Apple Push Notifications (APNs) for iOS app users.
 * Uses APNs HTTP/2 API with JWT authentication.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.31.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Push_Notifications {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * APNs production endpoint
     */
    const APNS_PRODUCTION_URL = 'https://api.push.apple.com/3/device/';

    /**
     * APNs sandbox endpoint
     */
    const APNS_SANDBOX_URL = 'https://api.sandbox.push.apple.com/3/device/';

    /**
     * Option keys for APNs credentials
     */
    const OPTION_KEY_ID = 'mld_apns_key_id';
    const OPTION_TEAM_ID = 'mld_apns_team_id';
    const OPTION_PRIVATE_KEY = 'mld_apns_private_key';
    const OPTION_BUNDLE_ID = 'mld_apns_bundle_id';
    const OPTION_ENVIRONMENT = 'mld_apns_environment';

    /**
     * Cached JWT token
     */
    private $jwt_token = null;
    private $jwt_expiry = 0;

    // ============================================
    // RATE LIMITING (v6.49.1)
    // ============================================

    /**
     * Rate limit: max requests per second (APNs soft limit is ~1000)
     * We target 500/sec to stay safely under the limit
     */
    const RATE_LIMIT_PER_SECOND = 500;

    /**
     * Rate limit: delay threshold - start adding delays at this % of limit
     * At 60% of 500 = 300 requests/second, we start slowing down
     */
    const RATE_LIMIT_THRESHOLD_PERCENT = 60;

    /**
     * Transient key for rate tracking
     */
    const RATE_LIMIT_TRANSIENT = 'mld_apns_rate_tracker';

    /**
     * Track requests for rate limiting (stored in transient)
     * Format: ['window_start' => timestamp, 'count' => int]
     */
    private static $rate_data = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        // Initialize
    }

    /**
     * Check if APNs is configured
     *
     * @return bool True if all credentials are set
     */
    public static function is_configured() {
        return !empty(get_option(self::OPTION_KEY_ID)) &&
               !empty(get_option(self::OPTION_TEAM_ID)) &&
               !empty(get_option(self::OPTION_PRIVATE_KEY)) &&
               !empty(get_option(self::OPTION_BUNDLE_ID));
    }

    /**
     * Send push notification to a user
     *
     * @param int $user_id WordPress user ID
     * @param int $listing_count Number of new listings
     * @param string $search_name Name of the saved search
     * @param int|null $search_id Optional saved search ID for deep linking
     * @return array Result with 'success', 'sent_count', 'failed_count', 'errors'
     * @updated 6.49.2 Added notification preference enforcement
     */
    public static function send_to_user($user_id, $listing_count, $search_name, $search_id = null) {
        $instance = self::get_instance();

        $result = [
            'success' => false,
            'sent_count' => 0,
            'failed_count' => 0,
            'errors' => [],
            'skipped_reason' => null
        ];

        // Check notification preferences before sending (v6.49.2)
        // v6.50.7: Queue for later delivery if blocked by quiet hours instead of skipping
        if (class_exists('MLD_Client_Notification_Preferences')) {
            $should_send = MLD_Client_Notification_Preferences::should_send_now($user_id, 'saved_search', 'push');
            if (!$should_send['send']) {
                $result['skipped_reason'] = $should_send['reason'];

                // If blocked by quiet hours, queue for later delivery
                if ($should_send['reason'] === 'quiet_hours') {
                    $payload_data = [
                        'user_id' => $user_id,
                        'listing_count' => $listing_count,
                        'search_name' => $search_name,
                        'search_id' => $search_id,
                        'notification_method' => 'send_to_user'
                    ];
                    $queued = MLD_Client_Notification_Preferences::queue_for_quiet_hours($user_id, 'saved_search', $payload_data);
                    if ($queued) {
                        $result['skipped_reason'] = 'queued_quiet_hours';
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD_Push_Notifications: Queued saved search notification for user {$user_id} - will deliver after quiet hours");
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Skipped saved search notification for user {$user_id} - reason: {$should_send['reason']}");
                    }
                }
                return $result;
            }
        }

        // Check configuration
        if (!self::is_configured()) {
            $result['errors'][] = 'APNs not configured';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Push_Notifications: APNs not configured');
            }
            return $result;
        }

        // Get user's device tokens
        $tokens = $instance->get_user_device_tokens($user_id);

        if (empty($tokens)) {
            $result['errors'][] = 'No device tokens for user';
            return $result;
        }

        // Increment server-side badge count and get new total
        $badge_count = self::increment_badge_count($user_id, 1);
        if ($badge_count === false) {
            $badge_count = $listing_count; // Fallback if badge table not available
        }

        // Build notification payload with server-side badge count
        $payload = $instance->build_payload($listing_count, $search_name, $search_id, $badge_count);

        // Send to each device
        foreach ($tokens as $token_data) {
            // Use per-token sandbox detection
            $is_sandbox = isset($token_data->is_sandbox) ? (bool) $token_data->is_sandbox : false;
            $send_result = $instance->send_notification($token_data->device_token, $payload, $is_sandbox, $user_id, 'saved_search');

            if ($send_result['success']) {
                $result['sent_count']++;
                $instance->update_token_last_used($token_data->id);
            } else {
                $result['failed_count']++;
                $result['errors'][] = $send_result['error'];

                // Handle invalid token (410 response)
                if ($send_result['status'] === 410 || $send_result['reason'] === 'Unregistered') {
                    $instance->deactivate_token($token_data->id);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Deactivated invalid token for user {$user_id}");
                    }
                }
            }
        }

        $result['success'] = $result['sent_count'] > 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                "MLD_Push_Notifications: User %d - Sent: %d, Failed: %d",
                $user_id,
                $result['sent_count'],
                $result['failed_count']
            ));
        }

        return $result;
    }

    /**
     * Get device tokens for a user
     *
     * @param int $user_id WordPress user ID
     * @return array Array of token objects (includes is_sandbox flag)
     */
    private function get_user_device_tokens($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, device_token, platform, is_sandbox FROM {$table_name}
             WHERE user_id = %d AND is_active = 1 AND platform = 'ios'
             ORDER BY last_used_at DESC",
            $user_id
        ));
    }

    /**
     * Build APNs payload
     *
     * @param int $listing_count Number of new listings
     * @param string $search_name Name of the saved search
     * @param int|null $search_id Optional saved search ID
     * @param int|null $badge_count Server-side badge count (uses listing_count if null)
     * @return array Payload array
     */
    private function build_payload($listing_count, $search_name, $search_id = null, $badge_count = null) {
        // Build alert text
        $title = 'New Property Alert';
        if ($listing_count === 1) {
            $body = "1 new listing matches \"{$search_name}\"";
        } else {
            $body = "{$listing_count} new listings match \"{$search_name}\"";
        }

        // Use server-side badge count if available, fallback to listing count
        $badge = $badge_count !== null ? $badge_count : $listing_count;

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body
                ],
                'badge' => $badge,
                'sound' => 'default',
                'category' => 'NEW_LISTINGS',
                'thread-id' => 'saved-search-' . ($search_id ?? 'general'),
                'mutable-content' => 1
            ],
            'listing_count' => $listing_count,
            'search_name' => $search_name
        ];

        // Add search ID for deep linking
        if ($search_id) {
            $payload['saved_search_id'] = $search_id;
        }

        return $payload;
    }

    /**
     * Send notification to a single device
     *
     * @param string $device_token APNs device token
     * @param array $payload Notification payload
     * @param bool $is_sandbox Whether to use sandbox APNs (auto-detected per token)
     * @param int|null $user_id User ID for logging (optional)
     * @param string $notification_type Notification type for logging (optional)
     * @return array Result with 'success', 'status', 'reason', 'error'
     */
    private function send_notification($device_token, $payload, $is_sandbox = null, $user_id = null, $notification_type = 'unknown') {
        $result = [
            'success' => false,
            'status' => null,
            'reason' => null,
            'error' => null
        ];

        // Get or generate JWT
        $jwt = $this->get_jwt();
        if (!$jwt) {
            $result['error'] = 'Failed to generate JWT';
            return $result;
        }

        // Determine APNs environment - use per-token flag if provided, otherwise fall back to global setting
        if ($is_sandbox === null) {
            $environment = get_option(self::OPTION_ENVIRONMENT, 'production');
            $is_sandbox = ($environment === 'sandbox');
        }
        $url = ($is_sandbox ? self::APNS_SANDBOX_URL : self::APNS_PRODUCTION_URL) . $device_token;
        $bundle_id = get_option(self::OPTION_BUNDLE_ID, 'com.bmnboston.app');

        $headers = [
            'Authorization: bearer ' . $jwt,
            'apns-topic: ' . $bundle_id,
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-expiration: ' . (time() + 86400), // 24 hours
            'Content-Type: application/json'
        ];

        $body = json_encode($payload);

        // Apply rate limiting to prevent 429 errors
        self::apply_rate_limit();

        // Use curl for HTTP/2 support
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($curl_error) {
            $result['error'] = 'Curl error: ' . $curl_error;
            return $result;
        }

        $result['status'] = $http_code;

        // Parse response body
        $response_body = substr($response, $header_size);
        if (!empty($response_body)) {
            $response_data = json_decode($response_body, true);
            $result['reason'] = $response_data['reason'] ?? null;
        }

        if ($http_code === 200) {
            $result['success'] = true;
        } else {
            $result['error'] = "APNs error: HTTP {$http_code}" . ($result['reason'] ? " - {$result['reason']}" : '');
        }

        // Log the notification delivery attempt
        $title = isset($payload['aps']['alert']['title']) ? $payload['aps']['alert']['title'] : '';
        $body_text = isset($payload['aps']['alert']['body']) ? $payload['aps']['alert']['body'] : '';

        if ($user_id) {
            self::log_notification(
                $user_id,
                $device_token,
                $notification_type,
                $title,
                $body_text,
                $payload,
                $result['success'] ? 'sent' : 'failed',
                $http_code,
                $result['reason'],
                $result['error'],
                $is_sandbox,
                'mld'
            );

            // Queue for retry if this is a retriable error
            if (!$result['success'] && self::is_retriable_error($http_code, $result['reason'])) {
                self::queue_for_retry(
                    $user_id,
                    $device_token,
                    $notification_type,
                    $title,
                    $body_text,
                    $payload,
                    $is_sandbox,
                    $result['error'],
                    'mld'
                );
            }
        }

        return $result;
    }

    /**
     * Get or generate JWT for APNs authentication
     *
     * @return string|null JWT token or null on failure
     */
    private function get_jwt() {
        // Return cached token if still valid (tokens expire after 1 hour, we refresh at 50 minutes)
        if ($this->jwt_token && $this->jwt_expiry > time()) {
            return $this->jwt_token;
        }

        $key_id = get_option(self::OPTION_KEY_ID);
        $team_id = get_option(self::OPTION_TEAM_ID);
        $private_key = get_option(self::OPTION_PRIVATE_KEY);

        if (empty($key_id) || empty($team_id) || empty($private_key)) {
            return null;
        }

        // Build JWT header
        $header = [
            'alg' => 'ES256',
            'kid' => $key_id
        ];

        // Build JWT claims
        $claims = [
            'iss' => $team_id,
            'iat' => time()
        ];

        // Encode header and claims
        $header_encoded = $this->base64url_encode(json_encode($header));
        $claims_encoded = $this->base64url_encode(json_encode($claims));

        $data = $header_encoded . '.' . $claims_encoded;

        // Sign with private key
        $private_key_resource = openssl_pkey_get_private($private_key);
        if (!$private_key_resource) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Push_Notifications: Invalid private key');
            }
            return null;
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $private_key_resource, 'sha256');

        if (!$success) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Push_Notifications: Failed to sign JWT');
            }
            return null;
        }

        // Convert DER signature to raw format (APNs requires raw)
        $signature = $this->der_to_raw_signature($signature);
        $signature_encoded = $this->base64url_encode($signature);

        $this->jwt_token = $data . '.' . $signature_encoded;
        $this->jwt_expiry = time() + 3000; // 50 minutes

        return $this->jwt_token;
    }

    /**
     * Base64 URL encode (no padding, URL-safe)
     *
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Convert DER-encoded signature to raw format
     * APNs requires raw 64-byte signature, OpenSSL produces DER format
     *
     * @param string $der_signature DER-encoded signature
     * @return string Raw 64-byte signature
     */
    private function der_to_raw_signature($der_signature) {
        // Parse DER structure
        $offset = 0;
        if (ord($der_signature[$offset++]) !== 0x30) { // SEQUENCE
            return $der_signature;
        }

        // Skip length byte(s)
        $length = ord($der_signature[$offset++]);
        if ($length & 0x80) {
            $offset += ($length & 0x7f);
        }

        // First INTEGER (r)
        if (ord($der_signature[$offset++]) !== 0x02) {
            return $der_signature;
        }
        $r_length = ord($der_signature[$offset++]);
        $r = substr($der_signature, $offset, $r_length);
        $offset += $r_length;

        // Second INTEGER (s)
        if (ord($der_signature[$offset++]) !== 0x02) {
            return $der_signature;
        }
        $s_length = ord($der_signature[$offset++]);
        $s = substr($der_signature, $offset, $s_length);

        // Pad r and s to 32 bytes each
        $r = ltrim($r, "\x00"); // Remove leading zeros
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Update token's last_used_at timestamp
     *
     * @param int $token_id Token record ID
     */
    private function update_token_last_used($token_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_device_tokens',
            ['last_used_at' => current_time('mysql')],
            ['id' => $token_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Deactivate an invalid token
     *
     * @param int $token_id Token record ID
     */
    private function deactivate_token($token_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_device_tokens',
            ['is_active' => 0],
            ['id' => $token_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Send activity notification to a user (agent)
     *
     * Used for client activity notifications (login, favorites, etc.)
     *
     * @param int $user_id WordPress user ID (agent)
     * @param string $title Notification title
     * @param string $body Notification body
     * @param string $notification_type Type of notification for categorization
     * @param array $context Additional context data
     * @return array Result with 'success', 'sent_count', 'failed_count', 'errors'
     */
    public static function send_activity_notification($user_id, $title, $body, $notification_type = 'client_activity', $context = array()) {
        $instance = self::get_instance();

        $result = [
            'success' => false,
            'sent_count' => 0,
            'failed_count' => 0,
            'errors' => []
        ];

        // Check configuration
        if (!self::is_configured()) {
            $result['errors'][] = 'APNs not configured';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Push_Notifications: APNs not configured');
            }
            return $result;
        }

        // Get user's device tokens
        $tokens = $instance->get_user_device_tokens($user_id);

        if (empty($tokens)) {
            $result['errors'][] = 'No device tokens for user';
            return $result;
        }

        // v6.72.0: Filter out excluded device token (e.g., kiosk device shouldn't notify itself)
        $exclude_token = isset($context['exclude_device_token']) ? $context['exclude_device_token'] : null;
        if (!empty($exclude_token)) {
            $tokens = array_filter($tokens, function($token_data) use ($exclude_token) {
                return $token_data->device_token !== $exclude_token;
            });
            // Re-index array
            $tokens = array_values($tokens);
            if (empty($tokens)) {
                $result['errors'][] = 'All tokens excluded';
                return $result;
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Push_Notifications: Excluded device token for user {$user_id}");
            }
        }

        // Increment server-side badge count and get new total
        $badge_count = self::increment_badge_count($user_id, 1);

        // Build notification payload with badge count
        $payload = $instance->build_activity_payload($title, $body, $notification_type, $context, $badge_count);

        // Send to each device
        foreach ($tokens as $token_data) {
            // Use per-token sandbox detection
            $is_sandbox = isset($token_data->is_sandbox) ? (bool) $token_data->is_sandbox : false;
            $send_result = $instance->send_notification($token_data->device_token, $payload, $is_sandbox, $user_id, $notification_type);

            if ($send_result['success']) {
                $result['sent_count']++;
                $instance->update_token_last_used($token_data->id);
            } else {
                $result['failed_count']++;
                $result['errors'][] = $send_result['error'];

                // Handle invalid token (410 response)
                if ($send_result['status'] === 410 || $send_result['reason'] === 'Unregistered') {
                    $instance->deactivate_token($token_data->id);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Deactivated invalid token for user {$user_id}");
                    }
                }
            }
        }

        $result['success'] = $result['sent_count'] > 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                "MLD_Push_Notifications: Activity notification to user %d - Sent: %d, Failed: %d",
                $user_id,
                $result['sent_count'],
                $result['failed_count']
            ));
        }

        return $result;
    }

    /**
     * Build APNs payload for activity notifications
     *
     * @param string $title Notification title
     * @param string $body Notification body
     * @param string $notification_type Type of notification
     * @param array $context Additional context data
     * @param int|null $badge_count Server-side badge count
     * @return array Payload array
     */
    private function build_activity_payload($title, $body, $notification_type, $context = array(), $badge_count = null) {
        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body
                ],
                'sound' => 'default',
                'category' => 'CLIENT_ACTIVITY',
                'thread-id' => 'agent-notifications',
                'mutable-content' => 1
            ],
            'notification_type' => $notification_type
        ];

        // Add badge count if available
        if ($badge_count !== null && $badge_count !== false) {
            $payload['aps']['badge'] = (int) $badge_count;
        }

        // Add context data
        if (!empty($context['client_id'])) {
            $payload['client_id'] = (int) $context['client_id'];
        }

        if (!empty($context['listing_id'])) {
            $payload['listing_id'] = $context['listing_id'];
        }

        if (!empty($context['listing_key'])) {
            $payload['listing_key'] = $context['listing_key'];
        }

        if (!empty($context['search_id'])) {
            $payload['search_id'] = (int) $context['search_id'];
        }

        if (!empty($context['appointment_id'])) {
            $payload['appointment_id'] = (int) $context['appointment_id'];
        }

        // Add image URL for Notification Service Extension (rich notifications)
        if (!empty($context['image_url'])) {
            $payload['image_url'] = $context['image_url'];
        } elseif (!empty($context['photo_url'])) {
            $payload['image_url'] = $context['photo_url'];
        }

        return $payload;
    }

    /**
     * Send push notification for a single property
     *
     * Used by saved search alerts to send individual notifications per property,
     * each with a direct deep link to the property detail page.
     *
     * @param int $user_id WordPress user ID
     * @param array $property Property data (listing_id, listing_key, address, city, price, change_type, etc.)
     * @param string $search_name Name of the saved search that matched
     * @param int|null $search_id Optional saved search ID
     * @return array Result with 'success', 'sent_count', 'failed_count', 'errors'
     * @since 6.48.1
     * @updated 6.49.2 Added notification preference enforcement
     */
    public static function send_property_notification($user_id, $property, $search_name, $search_id = null) {
        $instance = self::get_instance();

        $result = [
            'success' => false,
            'sent_count' => 0,
            'failed_count' => 0,
            'errors' => [],
            'skipped_reason' => null
        ];

        // Check notification preferences before sending (v6.49.2)
        // v6.50.7: Queue for later delivery if blocked by quiet hours instead of skipping
        $change_type = $property['change_type'] ?? 'new_listing';
        $notification_type = self::map_change_type_to_preference($change_type);

        if (class_exists('MLD_Client_Notification_Preferences')) {
            $should_send = MLD_Client_Notification_Preferences::should_send_now($user_id, $notification_type, 'push');
            if (!$should_send['send']) {
                $result['skipped_reason'] = $should_send['reason'];

                // If blocked by quiet hours, queue for later delivery
                if ($should_send['reason'] === 'quiet_hours') {
                    $payload_data = [
                        'user_id' => $user_id,
                        'property' => $property,
                        'search_name' => $search_name,
                        'search_id' => $search_id,
                        'listing_id' => $property['listing_id'] ?? null,
                        'notification_method' => 'send_property_notification'
                    ];
                    $queued = MLD_Client_Notification_Preferences::queue_for_quiet_hours($user_id, $notification_type, $payload_data);
                    if ($queued) {
                        $result['skipped_reason'] = 'queued_quiet_hours';
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD_Push_Notifications: Queued property notification for user {$user_id} - will deliver after quiet hours");
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Skipped notification for user {$user_id} - reason: {$should_send['reason']}");
                    }
                }
                return $result;
            }
        }

        // v6.53.0: Check if this notification was recently sent to prevent duplicates
        // This catches duplicate triggers from overlapping cron jobs or event handlers
        $listing_id = isset($property['listing_id']) ? (string) $property['listing_id'] : null;
        if (self::was_recently_sent($user_id, $change_type, $listing_id, 60)) {
            $result['skipped_reason'] = 'duplicate_within_hour';
            return $result;
        }

        // Check configuration
        if (!self::is_configured()) {
            $result['errors'][] = 'APNs not configured';
            return $result;
        }

        // Get user's device tokens
        $tokens = $instance->get_user_device_tokens($user_id);

        if (empty($tokens)) {
            $result['errors'][] = 'No device tokens for user';
            return $result;
        }

        // Increment server-side badge count and get new total
        $badge_count = self::increment_badge_count($user_id, 1);

        // Build notification payload for this specific property
        $payload = $instance->build_property_payload($property, $search_name, $search_id, $badge_count);

        // Determine notification type from change_type
        // FIXED v6.50.5: Use actual change_type for logging so history API returns correct type
        // Previously this converted 'new_listing' to 'saved_search' causing wrong icon in Notification Center
        $change_type = $property['change_type'] ?? 'new_listing';
        $notification_type = $change_type;

        // Send to each device
        foreach ($tokens as $token_data) {
            // Use per-token sandbox detection
            $is_sandbox = isset($token_data->is_sandbox) ? (bool) $token_data->is_sandbox : false;
            $send_result = $instance->send_notification($token_data->device_token, $payload, $is_sandbox, $user_id, $notification_type);

            if ($send_result['success']) {
                $result['sent_count']++;
                $instance->update_token_last_used($token_data->id);
            } else {
                $result['failed_count']++;
                $result['errors'][] = $send_result['error'];

                // Handle invalid token (410 response)
                if ($send_result['status'] === 410 || $send_result['reason'] === 'Unregistered') {
                    $instance->deactivate_token($token_data->id);
                }
            }
        }

        $result['success'] = $result['sent_count'] > 0;

        return $result;
    }

    /**
     * Build APNs payload for a single property notification
     *
     * @param array $property Property data
     * @param string $search_name Name of the saved search
     * @param int|null $search_id Saved search ID
     * @param int|null $badge_count Server-side badge count
     * @return array Payload array
     * @since 6.48.1
     */
    private function build_property_payload($property, $search_name, $search_id = null, $badge_count = null) {
        // Extract property details
        $listing_id = $property['listing_id'] ?? $property['ListingId'] ?? '';
        $listing_key = $property['listing_key'] ?? $property['ListingKey'] ?? '';
        $address = $property['full_address'] ?? $property['street_address'] ?? $property['UnparsedAddress'] ?? 'New Property';
        $city = $property['city'] ?? $property['City'] ?? '';
        $price = $property['list_price'] ?? $property['ListPrice'] ?? 0;
        $change_type = $property['change_type'] ?? 'new_listing';
        $photo_url = $property['main_photo_url'] ?? $property['MainPhotoUrl'] ?? $property['photo_url'] ?? '';

        // Format price
        $price_formatted = '$' . number_format($price);

        // Build title and body based on change type
        switch ($change_type) {
            case 'price_change':
                $old_price = $property['old_price'] ?? 0;
                $new_price = $property['new_price'] ?? $price;
                $diff = $old_price - $new_price;
                $diff_formatted = '$' . number_format($diff);
                $title = 'Price Reduced!';
                $body = "{$address}" . ($city ? ", {$city}" : "") . " - Now {$price_formatted} (-{$diff_formatted})";
                break;

            case 'status_change':
                $new_status = $property['new_status'] ?? 'Updated';
                $title = "Status: {$new_status}";
                $body = "{$address}" . ($city ? ", {$city}" : "") . " - {$price_formatted}";
                break;

            default: // new_listing
                $title = 'New Listing';
                $body = "{$address}" . ($city ? ", {$city}" : "") . " - {$price_formatted}";
                break;
        }

        // Add search name context
        $body .= "\nMatches \"{$search_name}\"";

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body
                ],
                'sound' => 'default',
                'category' => 'PROPERTY_ALERT',
                'thread-id' => 'property-alerts',
                'mutable-content' => 1
            ],
            'notification_type' => $change_type, // Send actual change type (new_listing, price_change, status_change)
            'listing_id' => $listing_id,
            'listing_key' => $listing_key
        ];

        // Add badge count if available
        if ($badge_count !== null && $badge_count !== false) {
            $payload['aps']['badge'] = (int) $badge_count;
        }

        // Add search ID for context
        if ($search_id) {
            $payload['saved_search_id'] = $search_id;
        }

        // Add price change details
        if ($change_type === 'price_change') {
            $payload['price_previous'] = (int) ($property['old_price'] ?? 0);
            $payload['price_current'] = (int) ($property['new_price'] ?? $price);
        }

        // Add image URL for Notification Service Extension (rich notifications)
        if (!empty($photo_url)) {
            $payload['image_url'] = $photo_url;
        }

        return $payload;
    }

    /**
     * Send test notification to a user
     *
     * @param int $user_id WordPress user ID
     * @return array Result with details
     */
    public static function send_test($user_id) {
        return self::send_to_user($user_id, 3, 'Test Search', null);
    }

    /**
     * Get configuration status
     *
     * @return array Configuration status details
     */
    public static function get_config_status() {
        return [
            'configured' => self::is_configured(),
            'has_key_id' => !empty(get_option(self::OPTION_KEY_ID)),
            'has_team_id' => !empty(get_option(self::OPTION_TEAM_ID)),
            'has_private_key' => !empty(get_option(self::OPTION_PRIVATE_KEY)),
            'has_bundle_id' => !empty(get_option(self::OPTION_BUNDLE_ID)),
            'environment' => get_option(self::OPTION_ENVIRONMENT, 'production'),
            'bundle_id' => get_option(self::OPTION_BUNDLE_ID, 'com.bmnboston.app')
        ];
    }

    /**
     * Get device count for a user
     *
     * @param int $user_id WordPress user ID
     * @return int Number of active devices
     */
    public static function get_user_device_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE user_id = %d AND is_active = 1",
            $user_id
        ));
    }

    /**
     * Get total active device count
     *
     * @return int Total number of active devices
     */
    public static function get_total_device_count() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE is_active = 1"
        );
    }

    /**
     * Check if a similar notification was recently sent to this user
     * Used to prevent duplicate notifications within a short time window
     *
     * This is a user-level check (not per-device) to prevent the same notification
     * content from being sent multiple times if cron jobs overlap or events fire twice.
     *
     * @param int $user_id User ID
     * @param string $notification_type Type of notification (new_listing, price_change, etc.)
     * @param string|null $listing_id Optional listing ID for property notifications
     * @param int $window_minutes Time window in minutes (default 60)
     * @return bool True if a similar notification was recently sent
     * @since 6.53.0
     */
    public static function was_recently_sent($user_id, $notification_type, $listing_id = null, $window_minutes = 60) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        // Use WordPress timezone for threshold calculation
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($window_minutes * 60));

        if ($listing_id) {
            // For property notifications, check by listing_id in payload
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name}
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')) = %s
                 AND created_at > %s
                 AND status IN ('sent', 'failed')",
                $user_id, $notification_type, $listing_id, $threshold
            ));
        } else {
            // For non-property notifications, just check by type
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name}
                 WHERE user_id = %d
                 AND notification_type = %s
                 AND created_at > %s
                 AND status IN ('sent', 'failed')",
                $user_id, $notification_type, $threshold
            ));
        }

        $recently_sent = (int) $count > 0;

        if ($recently_sent && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD_Push_Notifications::was_recently_sent() - Skipping duplicate: user=%d, type=%s, listing=%s, count=%d',
                $user_id, $notification_type, $listing_id ?? 'null', $count
            ));
        }

        return $recently_sent;
    }

    /**
     * Log push notification delivery attempt
     *
     * @param int $user_id WordPress user ID
     * @param string $device_token APNs device token
     * @param string $notification_type Type of notification (saved_search, price_change, etc.)
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $payload Full notification payload
     * @param string $status Status (sent, failed, skipped)
     * @param int|null $apns_status_code HTTP status code from APNs
     * @param string|null $apns_reason APNs reason string
     * @param string|null $error_message Error message if any
     * @param bool $is_sandbox Whether sandbox APNs was used
     * @param string $source_plugin Source plugin (mld, snab)
     * @return int|false Insert ID or false on failure
     * @since 6.48.5
     */
    public static function log_notification($user_id, $device_token, $notification_type, $title, $body, $payload, $status, $apns_status_code = null, $apns_reason = null, $error_message = null, $is_sandbox = false, $source_plugin = 'mld') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'device_token' => substr($device_token, 0, 16) . '...' . substr($device_token, -8), // Truncate for privacy
                'notification_type' => $notification_type,
                'title' => $title,
                'body' => $body,
                'payload' => json_encode($payload),
                'status' => $status,
                'apns_status_code' => $apns_status_code,
                'apns_reason' => $apns_reason,
                'error_message' => $error_message,
                'is_sandbox' => $is_sandbox ? 1 : 0,
                'source_plugin' => $source_plugin,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get notification delivery statistics
     *
     * @param string $period Time period: 'day', 'week', 'month'
     * @param string|null $source_plugin Filter by source plugin
     * @return array Statistics array
     * @since 6.48.5
     */
    public static function get_delivery_stats($period = 'day', $source_plugin = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array(
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'by_type' => array(),
            );
        }

        // Determine date filter
        switch ($period) {
            case 'week':
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 7 DAY)';
                break;
            case 'month':
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 30 DAY)';
                break;
            default:
                $date_filter = 'DATE_SUB(NOW(), INTERVAL 1 DAY)';
        }

        $source_condition = $source_plugin ? $wpdb->prepare(' AND source_plugin = %s', $source_plugin) : '';

        // Get overall counts
        $counts = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped
            FROM {$table_name}
            WHERE created_at >= {$date_filter} {$source_condition}
        ");

        // Get counts by type
        $by_type = $wpdb->get_results("
            SELECT
                notification_type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table_name}
            WHERE created_at >= {$date_filter} {$source_condition}
            GROUP BY notification_type
            ORDER BY total DESC
        ", ARRAY_A);

        return array(
            'total' => (int) $counts->total,
            'sent' => (int) $counts->sent,
            'failed' => (int) $counts->failed,
            'skipped' => (int) $counts->skipped,
            'success_rate' => $counts->total > 0 ? round(($counts->sent / $counts->total) * 100, 1) : 0,
            'by_type' => $by_type,
        );
    }

    /**
     * Clean up old notification logs (retention: 30 days)
     *
     * @return int Number of rows deleted
     * @since 6.48.5
     */
    public static function cleanup_old_logs() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_notification_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        return $wpdb->query("
            DELETE FROM {$table_name}
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
    }

    /**
     * Check if an APNs error is retriable
     *
     * @param int $status_code HTTP status code
     * @param string|null $reason APNs reason string
     * @return bool True if the error is transient and should be retried
     * @since 6.48.6
     */
    public static function is_retriable_error($status_code, $reason = null) {
        // Network errors (no status code)
        if ($status_code === null || $status_code === 0) {
            return true;
        }

        // Rate limited - definitely retry
        if ($status_code === 429) {
            return true;
        }

        // Server errors - retry
        if ($status_code >= 500 && $status_code < 600) {
            return true;
        }

        // Specific APNs reasons that are transient
        $retriable_reasons = array(
            'ServiceUnavailable',
            'InternalServerError',
            'Shutdown',
            'TooManyRequests',
        );

        if ($reason && in_array($reason, $retriable_reasons, true)) {
            return true;
        }

        // 410 Unregistered - NOT retriable (token invalid)
        // 400 Bad Request - NOT retriable (payload issue)
        // 403 Forbidden - NOT retriable (cert/key issue)

        return false;
    }

    /**
     * Add a failed notification to the retry queue
     *
     * @param int $user_id WordPress user ID
     * @param string $device_token APNs device token
     * @param string $notification_type Type of notification
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $payload Full notification payload
     * @param bool $is_sandbox Whether sandbox APNs was used
     * @param string $error_message Error message from failed attempt
     * @param string $source_plugin Source plugin (mld, snab)
     * @return int|false Queue entry ID or false on failure
     * @since 6.48.6
     */
    public static function queue_for_retry($user_id, $device_token, $notification_type, $title, $body, $payload, $is_sandbox = false, $error_message = null, $source_plugin = 'mld') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        // First retry in 1 minute
        $next_retry = date('Y-m-d H:i:s', current_time('timestamp') + 60);

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'device_token' => $device_token,
                'notification_type' => $notification_type,
                'title' => $title,
                'body' => $body,
                'payload' => json_encode($payload),
                'is_sandbox' => $is_sandbox ? 1 : 0,
                'source_plugin' => $source_plugin,
                'retry_count' => 0,
                'max_retries' => 5,
                'last_error' => $error_message,
                'last_attempt_at' => current_time('mysql'),
                'next_retry_at' => $next_retry,
                'created_at' => current_time('mysql'),
                'status' => 'pending',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Push_Notifications: Queued notification for retry (user {$user_id}, type: {$notification_type})");
            }
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Process the retry queue
     *
     * Uses exponential backoff: 1min, 2min, 4min, 8min, 16min (max 5 retries)
     *
     * @param int $batch_size Maximum number of items to process
     * @return array Processing results
     * @since 6.48.6
     */
    public static function process_retry_queue($batch_size = 50) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';
        $instance = self::get_instance();

        $result = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'requeued' => 0,
            'expired' => 0,
        );

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return $result;
        }

        // Get pending items ready for retry
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE status = 'pending'
               AND next_retry_at <= %s
             ORDER BY next_retry_at ASC
             LIMIT %d",
            current_time('mysql'),
            $batch_size
        ));

        if (empty($items)) {
            return $result;
        }

        foreach ($items as $item) {
            $result['processed']++;

            // Mark as processing
            $wpdb->update(
                $table_name,
                array('status' => 'processing'),
                array('id' => $item->id),
                array('%s'),
                array('%d')
            );

            // Attempt to send
            $payload = json_decode($item->payload, true);
            $send_result = $instance->send_notification(
                $item->device_token,
                $payload,
                (bool) $item->is_sandbox,
                $item->user_id,
                $item->notification_type
            );

            if ($send_result['success']) {
                // Success - mark completed
                $wpdb->update(
                    $table_name,
                    array(
                        'status' => 'completed',
                        'last_attempt_at' => current_time('mysql'),
                    ),
                    array('id' => $item->id),
                    array('%s', '%s'),
                    array('%d')
                );
                $result['succeeded']++;

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD_Push_Notifications: Retry succeeded for queue item {$item->id}");
                }
            } else {
                // Failed - check if we should retry again
                $new_retry_count = $item->retry_count + 1;

                if ($new_retry_count >= $item->max_retries) {
                    // Max retries reached - mark as failed
                    $wpdb->update(
                        $table_name,
                        array(
                            'status' => 'failed',
                            'retry_count' => $new_retry_count,
                            'last_error' => $send_result['error'],
                            'last_attempt_at' => current_time('mysql'),
                        ),
                        array('id' => $item->id),
                        array('%s', '%d', '%s', '%s'),
                        array('%d')
                    );
                    $result['failed']++;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Retry failed permanently for queue item {$item->id} after {$new_retry_count} attempts");
                    }
                } elseif (!self::is_retriable_error($send_result['status'], $send_result['reason'])) {
                    // Non-retriable error (e.g., 410 Unregistered) - mark as failed
                    $wpdb->update(
                        $table_name,
                        array(
                            'status' => 'failed',
                            'retry_count' => $new_retry_count,
                            'last_error' => $send_result['error'],
                            'last_attempt_at' => current_time('mysql'),
                        ),
                        array('id' => $item->id),
                        array('%s', '%d', '%s', '%s'),
                        array('%d')
                    );
                    $result['failed']++;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Non-retriable error for queue item {$item->id}: {$send_result['reason']}");
                    }
                } else {
                    // Schedule next retry with exponential backoff
                    // 1min, 2min, 4min, 8min, 16min
                    $delay_seconds = 60 * pow(2, $new_retry_count);
                    $next_retry = date('Y-m-d H:i:s', current_time('timestamp') + $delay_seconds);

                    $wpdb->update(
                        $table_name,
                        array(
                            'status' => 'pending',
                            'retry_count' => $new_retry_count,
                            'last_error' => $send_result['error'],
                            'last_attempt_at' => current_time('mysql'),
                            'next_retry_at' => $next_retry,
                        ),
                        array('id' => $item->id),
                        array('%s', '%d', '%s', '%s', '%s'),
                        array('%d')
                    );
                    $result['requeued']++;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD_Push_Notifications: Requeued item {$item->id} for retry {$new_retry_count} at {$next_retry}");
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get retry queue statistics
     *
     * @return array Queue statistics
     * @since 6.48.6
     */
    public static function get_retry_queue_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array(
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
            );
        }

        $stats = $wpdb->get_results("
            SELECT status, COUNT(*) as count
            FROM {$table_name}
            GROUP BY status
        ", ARRAY_A);

        $result = array(
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'expired' => 0,
            'total' => 0,
        );

        foreach ($stats as $row) {
            $result[$row['status']] = (int) $row['count'];
            $result['total'] += (int) $row['count'];
        }

        return $result;
    }

    /**
     * Clean up old retry queue entries (retention: 7 days for completed, 30 days for failed)
     *
     * @return int Number of rows deleted
     * @since 6.48.6
     */
    public static function cleanup_retry_queue() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        // Delete completed items older than 7 days
        $deleted_completed = $wpdb->query("
            DELETE FROM {$table_name}
            WHERE status = 'completed'
              AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        // Delete failed/expired items older than 30 days
        $deleted_failed = $wpdb->query("
            DELETE FROM {$table_name}
            WHERE status IN ('failed', 'expired')
              AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        return $deleted_completed + $deleted_failed;
    }

    /**
     * Expire stale pending items (stuck for more than 24 hours)
     *
     * @return int Number of rows expired
     * @since 6.48.6
     */
    public static function expire_stale_retries() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_push_retry_queue';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        return $wpdb->query("
            UPDATE {$table_name}
            SET status = 'expired'
            WHERE status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
    }

    // ============================================
    // DEVICE TOKEN CLEANUP (v6.50.7)
    // ============================================

    /**
     * Cleanup stale device tokens not used in the specified number of days
     *
     * Tokens that haven't been used (no push notifications sent to them) in 90+ days
     * are likely from uninstalled apps. Marking them inactive prevents wasted API calls.
     *
     * @param int $days Number of days of inactivity before marking token as stale (default 90)
     * @return array Result with 'deactivated' count and 'deleted' count
     * @since 6.50.7
     */
    public static function cleanup_stale_tokens($days = 90) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        $result = array(
            'deactivated' => 0,
            'deleted' => 0,
        );

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return $result;
        }

        // Mark active tokens as inactive if not used in X days
        $deactivated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name}
             SET is_active = 0
             WHERE is_active = 1
               AND last_used_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        $result['deactivated'] = $deactivated !== false ? $deactivated : 0;

        // Delete inactive tokens older than 180 days (6 months) to keep table clean
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name}
             WHERE is_active = 0
               AND last_used_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days * 2  // 180 days if days=90
        ));

        $result['deleted'] = $deleted !== false ? $deleted : 0;

        if (defined('WP_DEBUG') && WP_DEBUG && ($result['deactivated'] > 0 || $result['deleted'] > 0)) {
            error_log(sprintf(
                "MLD_Push_Notifications: Stale token cleanup - Deactivated: %d, Deleted: %d",
                $result['deactivated'],
                $result['deleted']
            ));
        }

        return $result;
    }

    /**
     * Get device token statistics
     *
     * @return array Token statistics
     * @since 6.50.7
     */
    public static function get_device_token_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_device_tokens';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array(
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'sandbox' => 0,
                'production' => 0,
                'stale_30d' => 0,
                'stale_90d' => 0,
            );
        }

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN is_sandbox = 1 THEN 1 ELSE 0 END) as sandbox,
                SUM(CASE WHEN is_sandbox = 0 THEN 1 ELSE 0 END) as production,
                SUM(CASE WHEN is_active = 1 AND last_used_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as stale_30d,
                SUM(CASE WHEN is_active = 1 AND last_used_at < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as stale_90d
            FROM {$table_name}
        ");

        return array(
            'total' => (int) $stats->total,
            'active' => (int) $stats->active,
            'inactive' => (int) $stats->inactive,
            'sandbox' => (int) $stats->sandbox,
            'production' => (int) $stats->production,
            'stale_30d' => (int) $stats->stale_30d,
            'stale_90d' => (int) $stats->stale_90d,
        );
    }

    // ============================================
    // DEFERRED NOTIFICATION PROCESSING (v6.50.7)
    // ============================================

    /**
     * Process deferred notifications that are ready for delivery
     *
     * Notifications queued during quiet hours are processed once quiet hours end.
     * Called by cron job every 15 minutes.
     *
     * @param int $limit Maximum number of notifications to process
     * @return array Processing results with sent/failed/skipped counts
     * @since 6.50.7
     */
    public static function process_deferred_notifications($limit = 50) {
        $result = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        if (!class_exists('MLD_Client_Notification_Preferences')) {
            return $result;
        }

        // Get pending deferred notifications that are ready
        $pending = MLD_Client_Notification_Preferences::get_pending_deferred_notifications($limit);

        if (empty($pending)) {
            return $result;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('MLD_Push_Notifications: Processing %d deferred notifications', count($pending)));
        }

        foreach ($pending as $deferred) {
            $result['processed']++;

            $payload = json_decode($deferred->payload, true);
            if (!$payload) {
                MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'failed', 'Invalid payload JSON');
                $result['failed']++;
                $result['errors'][] = "ID {$deferred->id}: Invalid payload";
                continue;
            }

            $user_id = (int) $deferred->user_id;
            $notification_method = $payload['notification_method'] ?? null;

            // Check if user still wants notifications (preferences may have changed)
            $notification_type = $deferred->notification_type;
            if (!MLD_Client_Notification_Preferences::is_push_enabled($user_id, $notification_type)) {
                MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'skipped', 'Push disabled by user');
                $result['skipped']++;
                continue;
            }

            // Check if still in quiet hours (shouldn't be, but safety check)
            if (MLD_Client_Notification_Preferences::is_quiet_hours($user_id)) {
                // Still in quiet hours - skip for now, will be processed later
                continue;
            }

            $send_result = null;

            try {
                if ($notification_method === 'send_to_user') {
                    // Saved search summary notification
                    $send_result = self::send_to_user_direct(
                        $user_id,
                        $payload['listing_count'] ?? 1,
                        $payload['search_name'] ?? '',
                        $payload['search_id'] ?? null
                    );
                } elseif ($notification_method === 'send_property_notification') {
                    // Property-specific notification
                    $property = $payload['property'] ?? null;
                    if (!$property) {
                        MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'failed', 'Missing property data');
                        $result['failed']++;
                        continue;
                    }
                    $send_result = self::send_property_notification_direct(
                        $user_id,
                        $property,
                        $payload['search_name'] ?? '',
                        $payload['search_id'] ?? null
                    );
                } else {
                    MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'failed', 'Unknown notification method');
                    $result['failed']++;
                    continue;
                }

                if ($send_result && $send_result['success']) {
                    MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'sent');
                    $result['sent']++;
                } else {
                    $error_msg = isset($send_result['errors']) ? implode(', ', $send_result['errors']) : 'Unknown send error';
                    MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'failed', $error_msg);
                    $result['failed']++;
                    $result['errors'][] = "ID {$deferred->id}: {$error_msg}";
                }

            } catch (Exception $e) {
                MLD_Client_Notification_Preferences::mark_deferred_processed($deferred->id, 'failed', $e->getMessage());
                $result['failed']++;
                $result['errors'][] = "ID {$deferred->id}: " . $e->getMessage();
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD_Push_Notifications: Deferred processing complete - Processed: %d, Sent: %d, Failed: %d, Skipped: %d',
                $result['processed'],
                $result['sent'],
                $result['failed'],
                $result['skipped']
            ));
        }

        return $result;
    }

    /**
     * Send notification directly without preference checks (for deferred processing)
     *
     * @param int $user_id WordPress user ID
     * @param int $listing_count Number of listings
     * @param string $search_name Name of the saved search
     * @param int|null $search_id Saved search ID
     * @return array Result with success status
     * @since 6.50.7
     */
    private static function send_to_user_direct($user_id, $listing_count, $search_name, $search_id = null) {
        $instance = self::get_instance();

        $result = [
            'success' => false,
            'sent_count' => 0,
            'failed_count' => 0,
            'errors' => []
        ];

        if (!self::is_configured()) {
            $result['errors'][] = 'APNs not configured';
            return $result;
        }

        $tokens = $instance->get_user_device_tokens($user_id);
        if (empty($tokens)) {
            $result['errors'][] = 'No device tokens for user';
            return $result;
        }

        $badge_count = self::increment_badge_count($user_id, 1);
        if ($badge_count === false) {
            $badge_count = $listing_count;
        }

        $payload = $instance->build_payload($listing_count, $search_name, $search_id, $badge_count);

        foreach ($tokens as $token_data) {
            $is_sandbox = isset($token_data->is_sandbox) ? (bool) $token_data->is_sandbox : false;
            $send_result = $instance->send_notification($token_data->device_token, $payload, $is_sandbox, $user_id, 'saved_search');

            if ($send_result['success']) {
                $result['sent_count']++;
                $instance->update_token_last_used($token_data->id);
            } else {
                $result['failed_count']++;
                $result['errors'][] = $send_result['error'];

                if ($send_result['status'] === 410 || $send_result['reason'] === 'Unregistered') {
                    $instance->deactivate_token($token_data->id);
                }
            }
        }

        $result['success'] = $result['sent_count'] > 0;
        return $result;
    }

    /**
     * Send property notification directly without preference checks (for deferred processing)
     *
     * @param int $user_id WordPress user ID
     * @param array $property Property data
     * @param string $search_name Name of the saved search
     * @param int|null $search_id Saved search ID
     * @return array Result with success status
     * @since 6.50.7
     */
    private static function send_property_notification_direct($user_id, $property, $search_name, $search_id = null) {
        $instance = self::get_instance();

        $result = [
            'success' => false,
            'sent_count' => 0,
            'failed_count' => 0,
            'errors' => []
        ];

        if (!self::is_configured()) {
            $result['errors'][] = 'APNs not configured';
            return $result;
        }

        $tokens = $instance->get_user_device_tokens($user_id);
        if (empty($tokens)) {
            $result['errors'][] = 'No device tokens for user';
            return $result;
        }

        $badge_count = self::increment_badge_count($user_id, 1);
        $payload = $instance->build_property_payload($property, $search_name, $search_id, $badge_count);

        $change_type = $property['change_type'] ?? 'new_listing';
        $notification_type = $change_type;

        foreach ($tokens as $token_data) {
            $is_sandbox = isset($token_data->is_sandbox) ? (bool) $token_data->is_sandbox : false;
            $send_result = $instance->send_notification($token_data->device_token, $payload, $is_sandbox, $user_id, $notification_type);

            if ($send_result['success']) {
                $result['sent_count']++;
                $instance->update_token_last_used($token_data->id);
            } else {
                $result['failed_count']++;
                $result['errors'][] = $send_result['error'];

                if ($send_result['status'] === 410 || $send_result['reason'] === 'Unregistered') {
                    $instance->deactivate_token($token_data->id);
                }
            }
        }

        $result['success'] = $result['sent_count'] > 0;
        return $result;
    }

    // ============================================
    // BADGE COUNT MANAGEMENT (v6.49.0)
    // ============================================

    /**
     * Increment unread badge count for a user
     *
     * @param int $user_id WordPress user ID
     * @param int $increment Amount to increment (default 1)
     * @return int|false New badge count or false on failure
     * @since 6.49.0
     */
    public static function increment_badge_count($user_id, $increment = 1) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        // Upsert: insert if not exists, or increment if exists
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table_name} (user_id, unread_count, last_notification_at)
             VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE
                unread_count = unread_count + %d,
                last_notification_at = %s",
            $user_id,
            $increment,
            current_time('mysql'),
            $increment,
            current_time('mysql')
        ));

        if ($result === false) {
            return false;
        }

        return self::get_badge_count($user_id);
    }

    /**
     * Get current unread badge count for a user
     *
     * @param int $user_id WordPress user ID
     * @return int Badge count (0 if not found)
     * @since 6.49.0
     */
    public static function get_badge_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT unread_count FROM {$table_name} WHERE user_id = %d",
            $user_id
        ));

        return $count ? (int) $count : 0;
    }

    /**
     * Reset (clear) badge count for a user
     * Called when user opens app or views notifications
     *
     * @param int $user_id WordPress user ID
     * @return bool Success
     * @since 6.49.0
     */
    public static function reset_badge_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table_name} (user_id, unread_count, last_read_at)
             VALUES (%d, 0, %s)
             ON DUPLICATE KEY UPDATE
                unread_count = 0,
                last_read_at = %s",
            $user_id,
            current_time('mysql'),
            current_time('mysql')
        ));

        return $result !== false;
    }

    /**
     * Set badge count to a specific value
     *
     * @param int $user_id WordPress user ID
     * @param int $count Badge count to set
     * @return bool Success
     * @since 6.49.0
     */
    public static function set_badge_count($user_id, $count) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table_name} (user_id, unread_count)
             VALUES (%d, %d)
             ON DUPLICATE KEY UPDATE unread_count = %d",
            $user_id,
            $count,
            $count
        ));

        return $result !== false;
    }

    /**
     * Get badge count data including timestamps
     *
     * @param int $user_id WordPress user ID
     * @return array|null Badge data or null if not found
     * @since 6.49.0
     */
    public static function get_badge_data($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_user_badge_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT unread_count, last_notification_at, last_read_at, updated_at
             FROM {$table_name} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        if (!$row) {
            return array(
                'unread_count' => 0,
                'last_notification_at' => null,
                'last_read_at' => null,
                'updated_at' => null,
            );
        }

        return array(
            'unread_count' => (int) $row['unread_count'],
            'last_notification_at' => $row['last_notification_at'],
            'last_read_at' => $row['last_read_at'],
            'updated_at' => $row['updated_at'],
        );
    }

    // ============================================
    // PREFERENCE ENFORCEMENT (v6.49.2)
    // ============================================

    /**
     * Map change type to notification preference type
     *
     * Converts property change types to the preference type keys used
     * in MLD_Client_Notification_Preferences.
     *
     * @param string $change_type Property change type (new_listing, price_change, status_change)
     * @return string Preference type key
     * @since 6.49.2
     */
    private static function map_change_type_to_preference($change_type) {
        // v6.49.15: Map each change type to its specific preference type
        // This allows users to toggle each notification type independently
        $mapping = array(
            'new_listing' => 'new_listing',      // New listings from saved searches
            'price_change' => 'price_change',    // Price drops
            'status_change' => 'status_change',  // Status updates (pending, sold)
            'open_house' => 'open_house',        // Open house notifications
            'saved_search' => 'new_listing',     // Generic saved search matches → treat as new listing
        );

        return isset($mapping[$change_type]) ? $mapping[$change_type] : 'new_listing';
    }

    /**
     * Map activity notification type to preference type
     *
     * For agent activity notifications, maps the notification_type to
     * the appropriate preference key (though most agent notifications
     * don't have user-configurable preferences - they're for agents).
     *
     * @param string $notification_type Activity notification type
     * @return string|null Preference type key or null if no preference applies
     * @since 6.49.2
     */
    private static function map_activity_type_to_preference($notification_type) {
        // Agent notifications use their own preference system
        // Client-facing notifications map to client preferences
        $client_types = array(
            'appointment_reminder' => 'saved_search', // Use saved_search as fallback
            'appointment_confirmed' => 'saved_search',
            'tour_scheduled' => 'saved_search',
        );

        return isset($client_types[$notification_type]) ? $client_types[$notification_type] : null;
    }

    // ============================================
    // RATE LIMITING METHODS (v6.49.1)
    // ============================================

    /**
     * Apply rate limiting before sending a notification
     *
     * Tracks requests per second and adds delays when approaching
     * APNs rate limits to prevent 429 errors proactively.
     *
     * @return int Delay applied in microseconds (0 if no delay needed)
     * @since 6.49.1
     */
    private static function apply_rate_limit() {
        $rate_data = self::get_rate_data();
        $now = microtime(true);
        $window_start = $rate_data['window_start'];
        $count = $rate_data['count'];

        // Check for high utilization and send alert (v6.49.4)
        self::check_rate_limit_alert($count);

        // If window has expired (>1 second old), start a new window
        if (($now - $window_start) >= 1.0) {
            self::reset_rate_window($now);
            return 0;
        }

        // Calculate threshold (e.g., 60% of 500 = 300)
        $threshold = (int) (self::RATE_LIMIT_PER_SECOND * self::RATE_LIMIT_THRESHOLD_PERCENT / 100);

        // If under threshold, no delay needed
        if ($count < $threshold) {
            self::increment_rate_count();
            return 0;
        }

        // If approaching limit, calculate delay to spread requests across remaining time
        // Time remaining in current window
        $time_remaining = 1.0 - ($now - $window_start);
        if ($time_remaining <= 0) {
            // Window expired during calculation, reset
            self::reset_rate_window(microtime(true));
            return 0;
        }

        // Requests remaining before hitting limit
        $requests_remaining = self::RATE_LIMIT_PER_SECOND - $count;
        if ($requests_remaining <= 0) {
            // At limit - wait for window to expire
            $delay_us = (int) ($time_remaining * 1000000);
            usleep($delay_us);
            self::reset_rate_window(microtime(true));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Push_Notifications: Rate limit reached, waited " . round($delay_us / 1000) . "ms for new window");
            }

            return $delay_us;
        }

        // Calculate delay to spread remaining requests across remaining time
        // This creates gradual slowdown as we approach the limit
        $delay_per_request = $time_remaining / $requests_remaining;
        $delay_us = (int) ($delay_per_request * 1000000);

        // Cap delay at 100ms max per request
        $delay_us = min($delay_us, 100000);

        if ($delay_us > 1000) { // Only delay if >1ms
            usleep($delay_us);

            if (defined('WP_DEBUG') && WP_DEBUG && $delay_us > 10000) {
                error_log("MLD_Push_Notifications: Rate limiting - added " . round($delay_us / 1000) . "ms delay (count: {$count})");
            }
        }

        self::increment_rate_count();
        return $delay_us;
    }

    /**
     * Get current rate tracking data
     *
     * @return array Rate data with 'window_start' and 'count'
     * @since 6.49.1
     */
    private static function get_rate_data() {
        // Use static variable for in-process tracking (faster than transient)
        if (self::$rate_data === null) {
            // Try to load from transient for cross-process tracking
            $transient = get_transient(self::RATE_LIMIT_TRANSIENT);
            if ($transient !== false && is_array($transient)) {
                self::$rate_data = $transient;
            } else {
                self::$rate_data = array(
                    'window_start' => microtime(true),
                    'count' => 0,
                );
            }
        }
        return self::$rate_data;
    }

    /**
     * Reset rate tracking window
     *
     * @param float $start New window start time
     * @since 6.49.1
     */
    private static function reset_rate_window($start) {
        self::$rate_data = array(
            'window_start' => $start,
            'count' => 1,
        );
        // Save to transient for cross-process tracking (2 second TTL)
        set_transient(self::RATE_LIMIT_TRANSIENT, self::$rate_data, 2);
    }

    /**
     * Increment rate counter
     *
     * @since 6.49.1
     */
    private static function increment_rate_count() {
        if (self::$rate_data !== null) {
            self::$rate_data['count']++;
            // Update transient periodically (every 50 requests) for cross-process visibility
            if (self::$rate_data['count'] % 50 === 0) {
                set_transient(self::RATE_LIMIT_TRANSIENT, self::$rate_data, 2);
            }
        }
    }

    /**
     * Get rate limiting statistics
     *
     * Useful for monitoring and debugging rate limiting behavior.
     *
     * @return array Rate limit stats
     * @since 6.49.1
     */
    public static function get_rate_limit_stats() {
        $rate_data = self::get_rate_data();
        $now = microtime(true);
        $window_age = $now - $rate_data['window_start'];
        $threshold = (int) (self::RATE_LIMIT_PER_SECOND * self::RATE_LIMIT_THRESHOLD_PERCENT / 100);

        return array(
            'limit_per_second' => self::RATE_LIMIT_PER_SECOND,
            'threshold_percent' => self::RATE_LIMIT_THRESHOLD_PERCENT,
            'threshold_count' => $threshold,
            'current_window_count' => $rate_data['count'],
            'window_age_ms' => round($window_age * 1000, 2),
            'is_throttling' => $rate_data['count'] >= $threshold,
            'utilization_percent' => round(($rate_data['count'] / self::RATE_LIMIT_PER_SECOND) * 100, 1),
        );
    }

    /**
     * Check rate limit utilization and send alert if above threshold (v6.49.4)
     *
     * Sends an email alert to the admin when push notification rate utilization
     * exceeds 80%. Uses a 1-hour transient to prevent alert spam.
     *
     * @param int $count Current request count in the window
     * @since 6.49.4
     */
    private static function check_rate_limit_alert($count) {
        // Calculate utilization percentage
        $utilization = ($count / self::RATE_LIMIT_PER_SECOND) * 100;

        // Only alert if utilization exceeds 80%
        if ($utilization < 80) {
            return;
        }

        // Check if we've already sent an alert recently (1-hour cooldown)
        $alert_transient = 'mld_rate_limit_alert_sent';
        if (get_transient($alert_transient)) {
            return;
        }

        // Set transient to prevent duplicate alerts
        set_transient($alert_transient, true, HOUR_IN_SECONDS);

        // Get admin email
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        // Build alert email
        $site_name = get_bloginfo('name');
        $subject = "[{$site_name}] Push Notification Rate Limit Warning";

        $body = sprintf(
            "Push notification rate utilization has reached %.1f%%.\n\n" .
            "Current Rate: %d requests/second\n" .
            "Limit: %d requests/second\n" .
            "Throttle Threshold: %d%% (%d req/s)\n\n" .
            "This alert means you're approaching the APNs rate limit. Consider:\n" .
            "- Reviewing notification triggers for excessive sends\n" .
            "- Enabling batch coalescing for bulk notifications\n" .
            "- Adjusting saved search notification schedules\n\n" .
            "This is an automated alert from %s.\n" .
            "You will receive at most one alert per hour.",
            $utilization,
            $count,
            self::RATE_LIMIT_PER_SECOND,
            self::RATE_LIMIT_THRESHOLD_PERCENT,
            (int) (self::RATE_LIMIT_PER_SECOND * self::RATE_LIMIT_THRESHOLD_PERCENT / 100),
            $site_name
        );

        // Send the alert email
        wp_mail($admin_email, $subject, $body);

        // Log the alert
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Push_Notifications: Rate limit alert sent - utilization at {$utilization}%");
        }

        // Also log to notifications log table if available
        if (class_exists('MLD_Notification_Log')) {
            MLD_Notification_Log::log(
                0,
                'rate_limit_alert',
                'admin',
                array(
                    'utilization_percent' => $utilization,
                    'current_count' => $count,
                    'limit' => self::RATE_LIMIT_PER_SECOND,
                ),
                'sent'
            );
        }
    }
}
