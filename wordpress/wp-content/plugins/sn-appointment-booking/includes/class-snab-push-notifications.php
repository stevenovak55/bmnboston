<?php
/**
 * Push Notifications Service
 *
 * Handles APNs (Apple Push Notification service) integration for
 * sending appointment reminders to iOS devices.
 *
 * @package SN_Appointment_Booking
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Push Notifications class.
 *
 * @since 1.8.0
 */
class SNAB_Push_Notifications {

    /**
     * APNs production endpoint
     */
    const APNS_PRODUCTION = 'https://api.push.apple.com';

    /**
     * APNs sandbox endpoint
     */
    const APNS_SANDBOX = 'https://api.sandbox.push.apple.com';

    /**
     * Bundle ID for the iOS app
     */
    const BUNDLE_ID = 'com.bmnboston.app';

    /**
     * Single instance.
     *
     * @var SNAB_Push_Notifications
     */
    private static $instance = null;

    /**
     * Cached JWT token for APNs authentication.
     *
     * @var string|null
     */
    private $jwt_token = null;

    /**
     * JWT token expiry timestamp.
     *
     * @var int
     */
    private $jwt_expiry = 0;

    /**
     * Get single instance.
     *
     * @return SNAB_Push_Notifications
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
        // Register cron hook for push notifications
        add_action('snab_send_push_reminders', array($this, 'process_push_reminders'));

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('snab_send_push_reminders')) {
            wp_schedule_event(time(), 'hourly', 'snab_send_push_reminders');
        }
    }

    /**
     * Register a device token for a user.
     *
     * @param int    $user_id      WordPress user ID.
     * @param string $device_token APNs device token.
     * @param string $device_type  Device type (ios, android).
     * @param bool   $is_sandbox   Whether this is a sandbox/development token.
     * @return bool|WP_Error
     */
    public function register_device($user_id, $device_token, $device_type = 'ios', $is_sandbox = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_device_tokens';

        // Validate token format (APNs tokens are 64 hex characters)
        if ($device_type === 'ios' && !preg_match('/^[a-f0-9]{64}$/i', $device_token)) {
            return new WP_Error('invalid_token', 'Invalid APNs device token format');
        }

        // Check if token already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE device_token = %s",
            $device_token
        ));

        if ($existing) {
            // Update existing token (may have new user_id)
            $result = $wpdb->update(
                $table,
                array(
                    'user_id' => $user_id,
                    'is_sandbox' => $is_sandbox ? 1 : 0,
                    'updated_at' => current_time('mysql'),
                ),
                array('device_token' => $device_token),
                array('%d', '%d', '%s'),
                array('%s')
            );
        } else {
            // Insert new token
            $result = $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'device_token' => $device_token,
                    'device_type' => $device_type,
                    'is_sandbox' => $is_sandbox ? 1 : 0,
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%d', '%d', '%s', '%s')
            );
        }

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to save device token');
        }

        SNAB_Logger::info('Device token registered', array(
            'user_id' => $user_id,
            'device_type' => $device_type,
            'is_sandbox' => $is_sandbox,
        ));

        return true;
    }

    /**
     * Unregister a device token.
     *
     * @param string $device_token APNs device token.
     * @return bool
     */
    public function unregister_device($device_token) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_device_tokens';

        // Soft delete - mark as inactive
        $result = $wpdb->update(
            $table,
            array(
                'is_active' => 0,
                'updated_at' => current_time('mysql'),
            ),
            array('device_token' => $device_token),
            array('%d', '%s'),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Get active device tokens for a user.
     *
     * @param int $user_id WordPress user ID.
     * @return array Array of device token records.
     */
    public function get_user_devices($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snab_device_tokens';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND is_active = 1",
            $user_id
        ));
    }

    /**
     * Send a push notification to a device.
     *
     * @param string $device_token APNs device token.
     * @param string $title        Notification title.
     * @param string $body         Notification body.
     * @param array  $data         Custom data payload.
     * @param bool   $is_sandbox   Whether to use sandbox APNs.
     * @param int    $user_id      User ID for logging (optional).
     * @return bool|WP_Error
     */
    public function send_notification($device_token, $title, $body, $data = array(), $is_sandbox = false, $user_id = 0) {
        // Get APNs auth key from settings - try SNAB first, then fall back to MLD
        $auth_key = get_option('snab_apns_auth_key');
        $key_id = get_option('snab_apns_key_id');
        $team_id = get_option('snab_apns_team_id');

        // Fall back to MLD credentials if SNAB not configured
        if (empty($auth_key) || empty($key_id) || empty($team_id)) {
            $auth_key = get_option('mld_apns_private_key');
            $key_id = get_option('mld_apns_key_id');
            $team_id = get_option('mld_apns_team_id');

            if (!empty($auth_key) && !empty($key_id) && !empty($team_id)) {
                SNAB_Logger::info('Using MLD APNs credentials as fallback');
            }
        }

        if (empty($auth_key) || empty($key_id) || empty($team_id)) {
            SNAB_Logger::warning('APNs not configured - skipping push notification');
            return new WP_Error('not_configured', 'APNs credentials not configured');
        }

        // Build the APNs payload
        $payload = array(
            'aps' => array(
                'alert' => array(
                    'title' => $title,
                    'body' => $body,
                ),
                'sound' => 'default',
                'badge' => 1,
                'thread-id' => 'appointments',
                'mutable-content' => 1,
            ),
        );

        // Add custom data
        if (!empty($data)) {
            $payload = array_merge($payload, $data);
        }

        // Generate JWT token for APNs
        $jwt = $this->generate_apns_jwt($key_id, $team_id, $auth_key);
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        // Select APNs endpoint
        $endpoint = $is_sandbox ? self::APNS_SANDBOX : self::APNS_PRODUCTION;
        $url = $endpoint . '/3/device/' . $device_token;

        // Send the notification using HTTP/2
        $response = $this->send_apns_request($url, $jwt, $payload);

        // Log to shared MLD notification log if available
        $this->log_to_mld($user_id, $device_token, 'appointment_reminder', $title, $body, $payload, $response, $is_sandbox);

        if (is_wp_error($response)) {
            // Mark token as inactive if it's invalid
            if ($response->get_error_code() === 'invalid_token') {
                $this->unregister_device($device_token);
            }

            // Queue for retry if this is a retriable error (use MLD's retry queue)
            $retriable_errors = array('apns_error', 'curl_error', 'expired_jwt');
            if ($user_id && in_array($response->get_error_code(), $retriable_errors, true)) {
                $this->queue_for_retry_via_mld($user_id, $device_token, $title, $body, $payload, $is_sandbox, $response->get_error_message());
            }

            return $response;
        }

        SNAB_Logger::info('Push notification sent', array(
            'title' => $title,
            'is_sandbox' => $is_sandbox,
        ));

        return true;
    }

    /**
     * Log notification to shared MLD log table.
     *
     * @param int    $user_id         WordPress user ID.
     * @param string $device_token    Device token.
     * @param string $notification_type Type of notification.
     * @param string $title           Notification title.
     * @param string $body            Notification body.
     * @param array  $payload         Full payload.
     * @param mixed  $response        Response from APNs (true, WP_Error, or array with status).
     * @param bool   $is_sandbox      Whether sandbox APNs was used.
     */
    private function log_to_mld($user_id, $device_token, $notification_type, $title, $body, $payload, $response, $is_sandbox) {
        // Check if MLD_Push_Notifications class exists
        if (!class_exists('MLD_Push_Notifications') || !method_exists('MLD_Push_Notifications', 'log_notification')) {
            return;
        }

        // Determine status and error info from response
        if ($response === true) {
            $status = 'sent';
            $apns_status_code = 200;
            $apns_reason = null;
            $error_message = null;
        } elseif (is_wp_error($response)) {
            $status = 'failed';
            $apns_status_code = null;
            $apns_reason = $response->get_error_code();
            $error_message = $response->get_error_message();
        } else {
            $status = 'failed';
            $apns_status_code = null;
            $apns_reason = 'unknown';
            $error_message = 'Unknown error';
        }

        MLD_Push_Notifications::log_notification(
            $user_id,
            $device_token,
            $notification_type,
            $title,
            $body,
            $payload,
            $status,
            $apns_status_code,
            $apns_reason,
            $error_message,
            $is_sandbox,
            'snab'
        );
    }

    /**
     * Queue failed notification for retry via MLD's retry queue.
     *
     * @param int    $user_id       WordPress user ID.
     * @param string $device_token  APNs device token.
     * @param string $title         Notification title.
     * @param string $body          Notification body.
     * @param array  $payload       Full payload.
     * @param bool   $is_sandbox    Whether sandbox APNs was used.
     * @param string $error_message Error message from failed attempt.
     * @since 1.8.5
     */
    private function queue_for_retry_via_mld($user_id, $device_token, $title, $body, $payload, $is_sandbox, $error_message) {
        // Check if MLD_Push_Notifications class exists with retry queue support
        if (!class_exists('MLD_Push_Notifications') || !method_exists('MLD_Push_Notifications', 'queue_for_retry')) {
            return;
        }

        MLD_Push_Notifications::queue_for_retry(
            $user_id,
            $device_token,
            'appointment_reminder',
            $title,
            $body,
            $payload,
            $is_sandbox,
            $error_message,
            'snab'
        );

        SNAB_Logger::info('Queued notification for retry via MLD', array(
            'user_id' => $user_id,
            'error' => $error_message,
        ));
    }

    /**
     * Generate a JWT token for APNs authentication.
     * Caches the token for 50 minutes (tokens expire after 60 minutes).
     *
     * @param string $key_id   APNs Key ID.
     * @param string $team_id  Apple Team ID.
     * @param string $auth_key APNs Auth Key (P8 format).
     * @return string|WP_Error JWT token or error.
     */
    private function generate_apns_jwt($key_id, $team_id, $auth_key) {
        // Return cached token if still valid (tokens expire after 1 hour, we refresh at 50 minutes)
        if ($this->jwt_token && $this->jwt_expiry > time()) {
            return $this->jwt_token;
        }

        // Header
        $header = array(
            'alg' => 'ES256',
            'kid' => $key_id,
        );

        // Payload
        $payload = array(
            'iss' => $team_id,
            'iat' => time(),
        );

        // Encode header and payload
        $header_encoded = $this->base64url_encode(json_encode($header));
        $payload_encoded = $this->base64url_encode(json_encode($payload));

        // Create signature
        $data = $header_encoded . '.' . $payload_encoded;

        // Parse the P8 key
        $key = openssl_pkey_get_private($auth_key);
        if (!$key) {
            return new WP_Error('invalid_key', 'Invalid APNs auth key');
        }

        // Sign with ES256 (ECDSA with SHA-256)
        $signature = '';
        $success = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$success) {
            return new WP_Error('sign_failed', 'Failed to sign JWT');
        }

        // Convert signature from DER to raw format for ES256
        $signature = $this->der_to_raw_signature($signature);

        $signature_encoded = $this->base64url_encode($signature);

        // Cache the token for 50 minutes
        $this->jwt_token = $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
        $this->jwt_expiry = time() + 3000; // 50 minutes

        return $this->jwt_token;
    }

    /**
     * Convert DER signature to raw signature for ES256.
     *
     * @param string $der_signature DER-encoded signature.
     * @return string Raw signature (64 bytes).
     */
    private function der_to_raw_signature($der_signature) {
        // Parse the DER structure
        $offset = 0;
        if (ord($der_signature[$offset++]) !== 0x30) {
            return $der_signature;
        }

        // Skip length byte(s)
        $length = ord($der_signature[$offset++]);
        if ($length & 0x80) {
            $offset += ($length & 0x7f);
        }

        // Parse R
        if (ord($der_signature[$offset++]) !== 0x02) {
            return $der_signature;
        }
        $r_length = ord($der_signature[$offset++]);
        $r = substr($der_signature, $offset, $r_length);
        $offset += $r_length;

        // Parse S
        if (ord($der_signature[$offset++]) !== 0x02) {
            return $der_signature;
        }
        $s_length = ord($der_signature[$offset++]);
        $s = substr($der_signature, $offset, $s_length);

        // Pad/trim to 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return substr($r, -32) . substr($s, -32);
    }

    /**
     * Send HTTP/2 request to APNs.
     *
     * @param string $url     APNs URL.
     * @param string $jwt     JWT authentication token.
     * @param array  $payload Notification payload.
     * @return bool|WP_Error
     */
    private function send_apns_request($url, $jwt, $payload) {
        $headers = array(
            'Authorization: Bearer ' . $jwt,
            'apns-topic: ' . self::BUNDLE_ID,
            'apns-push-type: alert',
            'apns-priority: 10',
            'apns-expiration: ' . (time() + 86400), // Retry for 24 hours if device is offline
            'Content-Type: application/json',
        );

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT => 30,
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            SNAB_Logger::error('APNs cURL error', array('error' => $error));
            return new WP_Error('curl_error', $error);
        }

        if ($http_code === 200) {
            return true;
        }

        // Parse error response
        $response_data = json_decode($response, true);
        $reason = isset($response_data['reason']) ? $response_data['reason'] : 'Unknown error';

        SNAB_Logger::error('APNs error', array(
            'http_code' => $http_code,
            'reason' => $reason,
        ));

        // Map APNs error codes
        switch ($reason) {
            case 'BadDeviceToken':
            case 'Unregistered':
                return new WP_Error('invalid_token', 'Device token is invalid or unregistered');
            case 'ExpiredProviderToken':
                return new WP_Error('expired_jwt', 'Provider token has expired');
            default:
                return new WP_Error('apns_error', $reason);
        }
    }

    /**
     * Process push notification reminders (called by cron).
     */
    public function process_push_reminders() {
        global $wpdb;

        // Check if push notifications are enabled
        $push_enabled = get_option('snab_enable_push_notifications', false);
        if (!$push_enabled) {
            return;
        }

        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $tokens_table = $wpdb->prefix . 'snab_device_tokens';
        $types_table = $wpdb->prefix . 'snab_appointment_types';

        $now = current_time('timestamp');
        $in_24h = $now + (24 * HOUR_IN_SECONDS);
        $in_1h = $now + HOUR_IN_SECONDS;
        $in_2h = $now + (2 * HOUR_IN_SECONDS);

        // Get appointments needing 24-hour push reminder
        // Include listing_id for deep linking (v1.8.7)
        $appointments_24h = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.user_id, a.appointment_date, a.start_time, a.property_address,
                    a.listing_id, t.name as type_name
             FROM {$appointments_table} a
             JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE a.status = 'confirmed'
               AND a.push_reminder_24h_sent = 0
               AND a.user_id IS NOT NULL
               AND CONCAT(a.appointment_date, ' ', a.start_time) BETWEEN %s AND %s",
            wp_date('Y-m-d H:i:s', $in_24h - HOUR_IN_SECONDS),
            wp_date('Y-m-d H:i:s', $in_24h + HOUR_IN_SECONDS)
        ));

        foreach ($appointments_24h as $apt) {
            $this->send_appointment_reminder($apt, '24h');
        }

        // Get appointments needing 1-hour push reminder
        // Include listing_id for deep linking (v1.8.7)
        $appointments_1h = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.user_id, a.appointment_date, a.start_time, a.property_address,
                    a.listing_id, t.name as type_name
             FROM {$appointments_table} a
             JOIN {$types_table} t ON a.appointment_type_id = t.id
             WHERE a.status = 'confirmed'
               AND a.push_reminder_1h_sent = 0
               AND a.user_id IS NOT NULL
               AND CONCAT(a.appointment_date, ' ', a.start_time) BETWEEN %s AND %s",
            wp_date('Y-m-d H:i:s', $in_1h),
            wp_date('Y-m-d H:i:s', $in_2h)
        ));

        foreach ($appointments_1h as $apt) {
            $this->send_appointment_reminder($apt, '1h');
        }
    }

    /**
     * Send appointment reminder push notification.
     *
     * Enhanced in v1.8.7 to include rich content:
     * - Property image URL for rich notifications
     * - listing_id and listing_key for deep linking
     * - notification_type for iOS parsing consistency
     *
     * Enhanced in v1.9.1 to respect MLD notification preferences.
     *
     * @param object $appointment Appointment object.
     * @param string $type        Reminder type ('24h' or '1h').
     */
    private function send_appointment_reminder($appointment, $type) {
        global $wpdb;

        // Check user's notification preferences (v1.9.1)
        // appointment maps to 'open_house' preference type in MLD
        if (class_exists('MLD_Client_Notification_Preferences')) {
            $prefs = MLD_Client_Notification_Preferences::get_preferences($appointment->user_id);
            $push_enabled = $prefs['open_house_push'] ?? true;

            if (!$push_enabled) {
                SNAB_Logger::info('Skipping push reminder - user disabled appointment notifications', array(
                    'appointment_id' => $appointment->id,
                    'user_id' => $appointment->user_id,
                    'type' => $type,
                ));

                // Still mark reminder as "sent" so we don't keep trying
                $column = $type === '24h' ? 'push_reminder_24h_sent' : 'push_reminder_1h_sent';
                $wpdb->update(
                    $wpdb->prefix . 'snab_appointments',
                    array($column => 1),
                    array('id' => $appointment->id),
                    array('%d'),
                    array('%d')
                );
                return;
            }
        }

        // Get user's device tokens
        $devices = $this->get_user_devices($appointment->user_id);
        if (empty($devices)) {
            return;
        }

        // Format time
        $time = snab_format_time($appointment->appointment_date, $appointment->start_time);

        // Build notification
        if ($type === '24h') {
            $title = 'Appointment Tomorrow';
            $body = sprintf('%s at %s', $appointment->type_name, $time);
        } else {
            $title = 'Appointment in 1 Hour';
            $body = sprintf('%s at %s', $appointment->type_name, $time);
        }

        if (!empty($appointment->property_address)) {
            $body .= ' - ' . $appointment->property_address;
        }

        // Custom data for deep linking (enhanced v1.8.7)
        $data = array(
            'appointment_id' => (int) $appointment->id,
            'notification_type' => 'appointment_reminder',
        );

        // Add property address for display
        if (!empty($appointment->property_address)) {
            $data['property_address'] = $appointment->property_address;
        }

        // Fetch property data from MLD if listing_id exists (v1.8.7)
        if (!empty($appointment->listing_id)) {
            $data['listing_id'] = $appointment->listing_id;

            // Get property image and listing_key from MLD summary table
            $property_data = $this->get_property_data_for_notification($appointment->listing_id);
            if ($property_data) {
                if (!empty($property_data->listing_key)) {
                    $data['listing_key'] = $property_data->listing_key;
                }
                if (!empty($property_data->main_photo_url)) {
                    $data['image_url'] = $property_data->main_photo_url;
                }
            }
        }

        // Send to all user's devices
        foreach ($devices as $device) {
            $this->send_notification(
                $device->device_token,
                $title,
                $body,
                $data,
                (bool) $device->is_sandbox,
                (int) $appointment->user_id
            );
        }

        // Mark reminder as sent
        $column = $type === '24h' ? 'push_reminder_24h_sent' : 'push_reminder_1h_sent';
        $wpdb->update(
            $wpdb->prefix . 'snab_appointments',
            array($column => 1),
            array('id' => $appointment->id),
            array('%d'),
            array('%d')
        );

        SNAB_Logger::info('Push reminder sent', array(
            'appointment_id' => $appointment->id,
            'type' => $type,
            'devices_count' => count($devices),
        ));
    }

    /**
     * Get property data from MLD listing summary for notification enrichment.
     *
     * Fetches listing_key and main_photo_url from MLD's summary tables.
     * Checks active listings first, then archive.
     *
     * @since 1.8.7
     * @param string $listing_id MLS listing ID.
     * @return object|null Property data with listing_key and main_photo_url, or null.
     */
    private function get_property_data_for_notification($listing_id) {
        global $wpdb;

        if (empty($listing_id)) {
            return null;
        }

        // Try active listings first
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") === $summary_table) {
            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_key, main_photo_url FROM {$summary_table} WHERE listing_id = %s LIMIT 1",
                $listing_id
            ));

            if ($property) {
                return $property;
            }
        }

        // Try archive table
        $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$archive_table}'") === $archive_table) {
            $property = $wpdb->get_row($wpdb->prepare(
                "SELECT listing_key, main_photo_url FROM {$archive_table} WHERE listing_id = %s LIMIT 1",
                $listing_id
            ));

            if ($property) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Base64 URL-safe encode.
     *
     * @param string $data Data to encode.
     * @return string Encoded data.
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Clear scheduled cron on deactivation.
     */
    public static function clear_cron() {
        wp_clear_scheduled_hook('snab_send_push_reminders');
    }
}

/**
 * Get push notifications instance.
 *
 * @return SNAB_Push_Notifications
 */
function snab_push_notifications() {
    return SNAB_Push_Notifications::instance();
}
