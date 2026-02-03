<?php
/**
 * MLD JWT Handler
 *
 * Shared JWT authentication handler for MLD and other plugins (SNAB).
 * Centralizes JWT verification, encoding/decoding, and auth checking.
 *
 * Usage:
 *   // In permission_callback:
 *   'permission_callback' => array('MLD_JWT_Handler', 'check_auth')
 *
 *   // Or for optional auth (guests allowed):
 *   'permission_callback' => array('MLD_JWT_Handler', 'check_optional_auth')
 *
 * @package MLS_Listings_Display
 * @since 6.58.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_JWT_Handler {

    /**
     * JWT secret key option name (legacy - prefer wp-config.php constant)
     */
    const JWT_SECRET_OPTION = 'mld_mobile_jwt_secret';

    /**
     * Get JWT secret key
     * SECURITY: Prefers wp-config.php constant, falls back to database option
     *
     * @return string|null JWT secret or null if not configured
     */
    public static function get_jwt_secret() {
        // Prefer wp-config.php constant (more secure)
        if (defined('MLD_JWT_SECRET') && !empty(MLD_JWT_SECRET)) {
            return MLD_JWT_SECRET;
        }

        // Fall back to database option (legacy)
        $secret = get_option(self::JWT_SECRET_OPTION);
        if (!empty($secret)) {
            return $secret;
        }

        return null;
    }

    /**
     * Base64 URL-safe encode
     *
     * @param string $data Data to encode
     * @return string URL-safe base64 encoded string
     */
    public static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode
     *
     * @param string $data URL-safe base64 string to decode
     * @return string Decoded data
     */
    public static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Verify JWT token
     *
     * @param string $token JWT token string
     * @return array|WP_Error Decoded payload array or WP_Error on failure
     */
    public static function verify_jwt($token) {
        $secret = self::get_jwt_secret();
        if (empty($secret)) {
            return new WP_Error('jwt_not_configured', 'JWT authentication not configured', array('status' => 500));
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new WP_Error('invalid_token', 'Invalid token format', array('status' => 401));
        }

        list($header_b64, $payload_b64, $signature_b64) = $parts;

        // Verify signature
        $signature_check = self::base64url_encode(
            hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true)
        );

        if (!hash_equals($signature_check, $signature_b64)) {
            return new WP_Error('invalid_signature', 'Invalid token signature', array('status' => 401));
        }

        // Decode payload
        $payload = json_decode(self::base64url_decode($payload_b64), true);
        if (!$payload) {
            return new WP_Error('invalid_payload', 'Invalid token payload', array('status' => 401));
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return new WP_Error('token_expired', 'Token has expired', array('status' => 401));
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== get_bloginfo('url')) {
            return new WP_Error('invalid_issuer', 'Invalid token issuer', array('status' => 401));
        }

        // Check token type (only accept 'access' tokens, not 'refresh')
        if (isset($payload['type']) && $payload['type'] !== 'access') {
            return new WP_Error('invalid_token_type', 'Invalid token type', array('status' => 401));
        }

        return $payload;
    }

    /**
     * Check authentication - supports both JWT and WordPress session auth
     * Use this as permission_callback for authenticated endpoints
     *
     * @param WP_REST_Request $request REST request object
     * @return true|WP_REST_Response True if authenticated, error response otherwise
     */
    public static function check_auth($request) {
        // First, try JWT authentication (for iOS/mobile)
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);

            if (is_wp_error($payload)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => $payload->get_error_code(),
                    'message' => $payload->get_error_message()
                ), 401);
            }

            // Set current user from token
            if (isset($payload['sub'])) {
                wp_set_current_user($payload['sub']);
            }

            return true;
        }

        // Fall back to WordPress session authentication (for web)
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_REST_Response(array(
            'success' => false,
            'code' => 'unauthorized',
            'message' => 'Authentication required'
        ), 401);
    }

    /**
     * Optional authentication - allows guests but enriches with user data if authenticated
     * Use this as permission_callback for endpoints that work with or without auth
     *
     * @param WP_REST_Request $request REST request object
     * @return true Always returns true (guests allowed)
     */
    public static function check_optional_auth($request) {
        // Try JWT authentication
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $payload = self::verify_jwt($token);

            if (!is_wp_error($payload) && isset($payload['sub'])) {
                wp_set_current_user($payload['sub']);
            }
        }

        // WordPress session auth is already handled by WordPress
        // if user has valid session cookies

        return true; // Always allow, guests can access
    }

    /**
     * Send no-cache headers for authenticated endpoints
     * Prevents CDN from caching user-specific data
     */
    public static function send_no_cache_headers() {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('Vary: Authorization');
    }

    /**
     * Get user ID from current request (JWT or session)
     * Call after check_auth() or check_optional_auth()
     *
     * @return int User ID or 0 if not authenticated
     */
    public static function get_current_user_id() {
        return get_current_user_id();
    }
}
