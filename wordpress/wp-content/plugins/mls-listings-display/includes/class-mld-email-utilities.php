<?php
/**
 * MLS Listings Display - Email Utilities
 *
 * Centralized email helper functions for dynamic "from" addresses and unified footers.
 * Used by both MLD and SNAB plugins for consistent email formatting.
 *
 * @package MLS_Listings_Display
 * @since 6.63.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Email_Utilities {

    /**
     * App Store URL constant
     */
    const APP_STORE_URL = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';

    /**
     * App Store badge URL constant
     */
    const APP_STORE_BADGE_URL = 'https://bmnboston.com/wp-content/uploads/email-assets/app-store-badge.png';

    /**
     * QR code API base URL
     */
    const QR_CODE_API = 'https://api.qrserver.com/v1/create-qr-code/';

    /**
     * Cache for agent lookups to avoid repeated database queries
     *
     * @var array
     */
    private static $agent_cache = [];

    /**
     * Get the "From" header for an email based on recipient
     *
     * Logic:
     * - Admin/Agent/Client without assigned agent: Use MLD Email Notification Settings
     * - Client with assigned agent: Use agent's email address
     *
     * @param int|null $recipient_user_id User ID of email recipient (null for default)
     * @return string Formatted "Name <email>" header value (without "From: " prefix)
     */
    public static function get_from_header($recipient_user_id = null) {
        // Get default from MLD settings
        $settings = get_option('mld_simple_notification_settings', []);
        $default_email = !empty($settings['from_email']) ? $settings['from_email'] : get_option('admin_email');
        $default_name = !empty($settings['from_name']) ? $settings['from_name'] : get_bloginfo('name');

        // If no recipient specified, return default
        if (!$recipient_user_id) {
            return sprintf('%s <%s>', $default_name, $default_email);
        }

        // Check if recipient has an assigned agent
        if (class_exists('MLD_Agent_Client_Manager')) {
            // Use cache if available
            if (isset(self::$agent_cache[$recipient_user_id])) {
                $agent = self::$agent_cache[$recipient_user_id];
            } else {
                $agent = MLD_Agent_Client_Manager::get_client_agent($recipient_user_id);
                self::$agent_cache[$recipient_user_id] = $agent;
            }

            if ($agent) {
                // Client has an agent - use agent's email
                $agent_email = !empty($agent['email']) ? $agent['email'] : (!empty($agent['user_email']) ? $agent['user_email'] : '');
                $agent_name = !empty($agent['display_name']) ? $agent['display_name'] : (!empty($agent['wp_display_name']) ? $agent['wp_display_name'] : '');

                if ($agent_email && $agent_name) {
                    return sprintf('%s <%s>', $agent_name, $agent_email);
                }
                // Fallback if agent name is missing but email exists
                if ($agent_email) {
                    return sprintf('%s <%s>', $default_name, $agent_email);
                }
            }
        }

        // No agent or agent data missing - use default
        return sprintf('%s <%s>', $default_name, $default_email);
    }

    /**
     * Get email headers array with proper from address
     *
     * @param int|null $recipient_user_id User ID of email recipient
     * @param bool $include_content_type Whether to include Content-Type header
     * @return array Email headers
     */
    public static function get_email_headers($recipient_user_id = null, $include_content_type = true) {
        $headers = [];

        if ($include_content_type) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        $headers[] = 'From: ' . self::get_from_header($recipient_user_id);

        return $headers;
    }

    /**
     * Get social media links from theme settings
     *
     * @return array Associative array of social links (platform => url)
     */
    public static function get_social_links() {
        return array_filter([
            'instagram' => get_theme_mod('bne_social_instagram', ''),
            'facebook' => get_theme_mod('bne_social_facebook', ''),
            'youtube' => get_theme_mod('bne_social_youtube', ''),
            'linkedin' => get_theme_mod('bne_social_linkedin', ''),
        ]);
    }

    /**
     * Get App Store download section HTML
     *
     * @param bool $include_qr Whether to include QR code
     * @param string $size Size variant: 'compact' or 'full'
     * @return string HTML content
     */
    public static function get_app_download_section($include_qr = true, $size = 'full') {
        $app_store_url = self::APP_STORE_URL;
        $badge_url = self::APP_STORE_BADGE_URL;
        $qr_url = self::QR_CODE_API . '?size=120x120&data=' . urlencode($app_store_url);

        if ($size === 'compact') {
            // Compact version - just badge with text
            return '
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 15px 0;">
                <tr>
                    <td align="center">
                        <p style="margin: 0 0 10px 0; color: #4a4a4a; font-size: 14px;">Get instant alerts on your iPhone</p>
                        <a href="' . esc_url($app_store_url) . '" style="display: inline-block;">
                            <img src="' . esc_url($badge_url) . '" alt="Download on the App Store" style="height: 40px; width: auto;" />
                        </a>
                    </td>
                </tr>
            </table>';
        }

        // Full version - prominent section with QR code
        $html = '
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%); border-radius: 12px; margin: 25px 0;">
            <tr>
                <td style="padding: 25px; text-align: center;">
                    <h3 style="color: #ffffff; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        Get the BMN Boston App
                    </h3>
                    <p style="color: #b8d4e8; margin: 0 0 20px 0; font-size: 15px;">
                        Instant property alerts on your iPhone
                    </p>
                    <table role="presentation" cellspacing="0" cellpadding="0" align="center">
                        <tr>';

        if ($include_qr) {
            $html .= '
                            <td style="padding-right: 20px; vertical-align: middle;">
                                <a href="' . esc_url($app_store_url) . '">
                                    <img src="' . esc_url($qr_url) . '" alt="Scan to download"
                                         width="100" height="100"
                                         style="border-radius: 8px; background: #fff; padding: 5px; display: block;">
                                </a>
                            </td>';
        }

        $html .= '
                            <td style="vertical-align: middle;">
                                <a href="' . esc_url($app_store_url) . '">
                                    <img src="' . esc_url($badge_url) . '" alt="Download on the App Store"
                                         style="height: 50px; width: auto;">
                                </a>
                                <p style="color: #b8d4e8; margin: 10px 0 0 0; font-size: 12px;">
                                    Scan QR code or tap to download
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        return $html;
    }

    /**
     * Get social media icons HTML
     *
     * @return string HTML for social media icons (empty if no links configured)
     */
    public static function get_social_icons_html() {
        $social_links = self::get_social_links();

        if (empty($social_links)) {
            return '';
        }

        $icons = [];

        // Social media icon styles and content
        $social_config = [
            'instagram' => [
                'bg' => 'linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888)',
                'icon' => 'https://cdn-icons-png.flaticon.com/32/2111/2111463.png',
                'alt' => 'Instagram',
            ],
            'facebook' => [
                'bg' => '#1877F2',
                'icon' => 'https://cdn-icons-png.flaticon.com/32/733/733547.png',
                'alt' => 'Facebook',
            ],
            'youtube' => [
                'bg' => '#FF0000',
                'icon' => 'https://cdn-icons-png.flaticon.com/32/733/733646.png',
                'alt' => 'YouTube',
            ],
            'linkedin' => [
                'bg' => '#0A66C2',
                'icon' => 'https://cdn-icons-png.flaticon.com/32/733/733561.png',
                'alt' => 'LinkedIn',
            ],
        ];

        foreach ($social_links as $platform => $url) {
            if (empty($url) || !isset($social_config[$platform])) {
                continue;
            }

            $config = $social_config[$platform];
            $icons[] = '<a href="' . esc_url($url) . '" style="display: inline-block; margin: 0 6px; text-decoration: none;" target="_blank">
                <img src="' . esc_url($config['icon']) . '" alt="' . esc_attr($config['alt']) . '"
                     width="32" height="32" style="display: block; border: 0; border-radius: 6px;">
            </a>';
        }

        if (empty($icons)) {
            return '';
        }

        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
                <td align="center" style="padding: 0 0 20px 0;">
                    ' . implode('', $icons) . '
                </td>
            </tr>
        </table>';
    }

    /**
     * Get unified email footer HTML
     *
     * @param array $options Options array:
     *   - show_social: bool (default true)
     *   - show_app_download: bool (default true)
     *   - show_qr_code: bool (default true)
     *   - unsubscribe_url: string (default /my-account/saved-searches/)
     *   - context: string ('property_alert', 'appointment', 'general')
     *   - compact: bool (default false) - use compact app download section
     * @return string HTML footer content
     */
    public static function get_unified_footer($options = []) {
        $defaults = [
            'show_social' => true,
            'show_app_download' => true,
            'show_qr_code' => true,
            'unsubscribe_url' => home_url('/my-account/saved-searches/'),
            'context' => 'general',
            'compact' => false,
        ];
        $options = wp_parse_args($options, $defaults);

        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $year = date('Y');

        $html = '';

        // Social media section
        if ($options['show_social']) {
            $html .= self::get_social_icons_html();
        }

        // App download section (prominent)
        if ($options['show_app_download']) {
            $size = $options['compact'] ? 'compact' : 'full';
            $html .= self::get_app_download_section($options['show_qr_code'], $size);
        }

        // Context-specific message
        $context_message = '';
        switch ($options['context']) {
            case 'property_alert':
                $context_message = 'You\'re receiving this email because you created a saved search on <a href="' . esc_url($site_url) . '" style="color: #1e3a5f; font-weight: 500;">' . esc_html($site_name) . '</a>';
                break;
            case 'appointment':
                $context_message = 'This email was sent from <a href="' . esc_url($site_url) . '" style="color: #1e3a5f; font-weight: 500;">' . esc_html($site_name) . '</a>';
                break;
            default:
                $context_message = '<a href="' . esc_url($site_url) . '" style="color: #1e3a5f; font-weight: 500;">' . esc_html($site_name) . '</a>';
        }

        // Legal footer section
        $html .= '
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
                <td align="center" style="padding: 20px 0 0 0;">
                    <p style="margin: 0 0 12px 0; color: #4a4a4a; font-size: 14px; line-height: 1.5;">
                        ' . $context_message . '
                    </p>';

        // Unsubscribe/preferences link (context-dependent)
        if ($options['context'] === 'property_alert') {
            $html .= '
                    <p style="margin: 0 0 12px 0; font-size: 13px;">
                        <a href="' . esc_url($options['unsubscribe_url']) . '" style="color: #6c757d; text-decoration: underline;">Manage your saved searches</a>
                        <span style="color: #a3a3a3; margin: 0 8px;">|</span>
                        <a href="' . esc_url($site_url) . '" style="color: #6c757d; text-decoration: underline;">Visit our website</a>
                    </p>';
        } elseif ($options['context'] === 'appointment') {
            $html .= '
                    <p style="margin: 0 0 12px 0; font-size: 13px;">
                        <a href="' . esc_url(home_url('/my-account/appointments/')) . '" style="color: #6c757d; text-decoration: underline;">Manage your appointments</a>
                        <span style="color: #a3a3a3; margin: 0 8px;">|</span>
                        <a href="' . esc_url($site_url) . '" style="color: #6c757d; text-decoration: underline;">Visit our website</a>
                    </p>';
        }

        $html .= '
                    <p style="margin: 0; font-size: 12px; color: #adb5bd;">
                        &copy; ' . esc_html($year) . ' ' . esc_html($site_name) . '. All rights reserved.
                    </p>
                </td>
            </tr>
        </table>';

        return $html;
    }

    /**
     * Clear the agent cache (useful for testing)
     */
    public static function clear_cache() {
        self::$agent_cache = [];
    }

    /**
     * Get user ID from email address
     *
     * @param string $email Email address
     * @return int|null User ID or null if not found
     */
    public static function get_user_id_from_email($email) {
        if (empty($email)) {
            return null;
        }

        $user = get_user_by('email', $email);
        return $user ? $user->ID : null;
    }
}
