<?php
/**
 * MLS Listings Display - Email Template Engine
 *
 * Modular email builder with component system, agent co-branding,
 * and theme configuration support.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.32.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Email_Template_Engine {

    /**
     * Default theme configuration
     */
    private static $default_theme = array(
        'primary_color' => '#1e3a5f',
        'secondary_color' => '#2d5a87',
        'accent_color' => '#16a34a',
        'text_color' => '#1a1a1a',
        'muted_color' => '#4a4a4a',
        'border_color' => '#e5e7eb',
        'bg_color' => '#f4f4f4',
        'card_bg_color' => '#ffffff',
        'font_family' => 'Arial, Helvetica, sans-serif',
        'logo_url' => '',
        'logo_width' => '180',
    );

    /**
     * Current theme configuration
     */
    private $theme;

    /**
     * Agent data for co-branding
     */
    private $agent_data;

    /**
     * Client data
     */
    private $client_data;

    /**
     * Email tracking ID
     */
    private $email_id;

    /**
     * Constructor
     */
    public function __construct() {
        $this->theme = self::$default_theme;
        $this->agent_data = null;
        $this->client_data = null;
        $this->email_id = $this->generate_email_id();
    }

    /**
     * Generate unique email ID for tracking
     *
     * @return string Email ID
     */
    private function generate_email_id() {
        return 'mld_' . bin2hex(random_bytes(16));
    }

    /**
     * Get email ID for tracking
     *
     * @return string
     */
    public function get_email_id() {
        return $this->email_id;
    }

    /**
     * Set theme configuration
     *
     * @param array $config Theme configuration
     * @return self
     */
    public function set_theme($config) {
        $this->theme = wp_parse_args($config, self::$default_theme);
        return $this;
    }

    /**
     * Set agent for co-branding
     *
     * @param int|array $agent Agent user ID or agent data array
     * @return self
     */
    public function set_agent($agent) {
        if (is_numeric($agent)) {
            // Load agent profile by user ID
            $this->agent_data = MLD_Agent_Client_Manager::get_agent($agent);
        } else {
            $this->agent_data = $agent;
        }
        return $this;
    }

    /**
     * Set client data
     *
     * @param int|WP_User $client Client user ID or user object
     * @return self
     */
    public function set_client($client) {
        if (is_numeric($client)) {
            $user = get_user_by('id', $client);
            if ($user) {
                $this->client_data = array(
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'first_name' => $user->first_name ?: $user->display_name,
                );
            }
        } elseif ($client instanceof WP_User) {
            $this->client_data = array(
                'id' => $client->ID,
                'name' => $client->display_name,
                'email' => $client->user_email,
                'first_name' => $client->first_name ?: $client->display_name,
            );
        } else {
            $this->client_data = $client;
        }
        return $this;
    }

    /**
     * Render a complete email template
     *
     * @param string $template_name Template name (single-alert, daily-digest, weekly-roundup, etc.)
     * @param array $data Template data
     * @return string Rendered HTML
     */
    public function render($template_name, $data = array()) {
        // Get base layout
        $html = $this->get_base_layout_start();

        // Add header
        $html .= $this->render_component('header', $data);

        // Add agent card if agent is set and email type supports it
        if ($this->agent_data && $this->should_show_agent_card($template_name)) {
            $html .= $this->render_component('agent-card', array(
                'agent' => $this->agent_data,
                'greeting' => $this->get_agent_greeting(),
            ));
        }

        // Render the main content based on template
        switch ($template_name) {
            case 'single-alert':
                $html .= $this->render_single_alert($data);
                break;
            case 'daily-digest':
                $html .= $this->render_daily_digest($data);
                break;
            case 'weekly-roundup':
                $html .= $this->render_weekly_roundup($data);
                break;
            case 'welcome':
                $html .= $this->render_welcome($data);
                break;
            case 'agent-intro':
                $html .= $this->render_agent_intro($data);
                break;
            default:
                $html .= $this->render_single_alert($data);
        }

        // Add footer
        $html .= $this->render_component('footer', array(
            'agent' => $this->agent_data,
            'unsubscribe_url' => $data['unsubscribe_url'] ?? home_url('/saved-search/'),
        ));

        $html .= $this->get_base_layout_end();

        return $html;
    }

    /**
     * Check if template should show agent card
     *
     * @param string $template_name Template name
     * @return bool
     */
    private function should_show_agent_card($template_name) {
        $agent_templates = array('single-alert', 'daily-digest', 'weekly-roundup', 'agent-intro');
        return in_array($template_name, $agent_templates);
    }

    /**
     * Get agent's custom greeting or default
     *
     * @return string Greeting text
     */
    private function get_agent_greeting() {
        if ($this->agent_data && !empty($this->agent_data['custom_greeting'])) {
            return $this->agent_data['custom_greeting'];
        }

        $client_name = $this->client_data['first_name'] ?? 'there';
        return sprintf('Hi %s! Here are some listings I think you\'ll love.', $client_name);
    }

    /**
     * Get base layout start HTML
     *
     * @return string HTML
     */
    private function get_base_layout_start() {
        $theme = $this->theme;

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Alert</title>
    <!--[if mso]>
    <style type="text/css">
        table { border-collapse: collapse; }
        .button-wrapper { padding: 0 !important; }
        .button-link { padding: 14px 40px !important; }
    </style>
    <![endif]-->
</head>
<body style="margin:0;padding:0;font-family:' . esc_attr($theme['font_family']) . ';background-color:' . esc_attr($theme['bg_color']) . ';-webkit-font-smoothing:antialiased;">
    <!-- Tracking pixel -->
    <img src="' . esc_url($this->get_tracking_pixel_url()) . '" width="1" height="1" style="display:none;" alt="" />
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:' . esc_attr($theme['bg_color']) . ';">
        <tr>
            <td align="center" style="padding:20px 10px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:' . esc_attr($theme['card_bg_color']) . ';border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);max-width:600px;width:100%;">
';
    }

    /**
     * Get base layout end HTML
     *
     * @return string HTML
     */
    private function get_base_layout_end() {
        return '                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    /**
     * Get tracking pixel URL
     *
     * @return string URL
     */
    private function get_tracking_pixel_url() {
        return add_query_arg(array(
            'action' => 'mld_email_open',
            'eid' => $this->email_id,
        ), admin_url('admin-ajax.php'));
    }

    /**
     * Get click tracking URL
     *
     * @param string $original_url Original destination URL
     * @param string $link_type Type of link (property, cta, unsubscribe, etc.)
     * @return string Tracking URL
     */
    public function get_tracked_url($original_url, $link_type = 'general') {
        return add_query_arg(array(
            'action' => 'mld_email_click',
            'eid' => $this->email_id,
            'lt' => $link_type,
            'url' => urlencode($original_url),
        ), admin_url('admin-ajax.php'));
    }

    /**
     * Render a component
     *
     * @param string $name Component name
     * @param array $vars Component variables
     * @return string HTML
     */
    public function render_component($name, $vars = array()) {
        $method = 'component_' . str_replace('-', '_', $name);

        if (method_exists($this, $method)) {
            return $this->$method($vars);
        }

        // Try loading from template file
        $template_file = MLD_PLUGIN_DIR . 'templates/emails/modern/components/' . $name . '.php';
        if (file_exists($template_file)) {
            ob_start();
            extract($vars);
            include $template_file;
            return ob_get_clean();
        }

        return '';
    }

    /**
     * Header component
     *
     * @param array $vars Variables
     * @return string HTML
     */
    private function component_header($vars) {
        $theme = $this->theme;
        $site_name = get_bloginfo('name');
        $title = $vars['title'] ?? 'Property Alert';
        $subtitle = $vars['subtitle'] ?? '';

        // Get logo
        $logo_url = $theme['logo_url'];
        if (empty($logo_url)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }

        $html = '                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,' . esc_attr($theme['primary_color']) . ' 0%,' . esc_attr($theme['secondary_color']) . ' 100%);padding:30px 40px;text-align:center;">
';

        if ($logo_url) {
            $html .= '                            <img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" style="max-width:' . esc_attr($theme['logo_width']) . 'px;height:auto;margin-bottom:15px;">' . "\n";
        }

        $html .= '                            <h1 style="color:#ffffff;margin:0;font-size:28px;font-weight:600;">' . esc_html($title) . '</h1>
';

        if ($subtitle) {
            $html .= '                            <p style="color:#b8d4e8;margin:12px 0 0 0;font-size:18px;">' . esc_html($subtitle) . '</p>
';
        }

        $html .= '                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Agent card component
     *
     * @param array $vars Variables (agent, greeting)
     * @return string HTML
     */
    private function component_agent_card($vars) {
        $agent = $vars['agent'] ?? null;
        if (!$agent) {
            return '';
        }

        $theme = $this->theme;
        $greeting = $vars['greeting'] ?? '';

        $photo_url = $agent['photo_url'] ?? '';
        $name = $agent['display_name'] ?? $agent['wp_display_name'] ?? '';
        $title = $agent['title'] ?? 'Real Estate Agent';
        $phone = $agent['phone'] ?? '';
        $email = $agent['email'] ?? $agent['user_email'] ?? '';
        $office = $agent['office_name'] ?? '';

        $html = '                    <!-- Agent Card -->
                    <tr>
                        <td style="padding:25px 40px;background:#f0f7ff;border-bottom:1px solid ' . esc_attr($theme['border_color']) . ';">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
';

        // Agent photo
        if ($photo_url) {
            $html .= '                                    <td width="80" valign="top" style="padding-right:20px;">
                                        <img src="' . esc_url($photo_url) . '" alt="' . esc_attr($name) . '" width="80" height="80" style="border-radius:50%;display:block;object-fit:cover;">
                                    </td>
';
        }

        $html .= '                                    <td valign="top">
                                        <p style="margin:0 0 5px 0;font-size:18px;font-weight:600;color:' . esc_attr($theme['text_color']) . ';">' . esc_html($name) . '</p>
                                        <p style="margin:0 0 10px 0;font-size:14px;color:' . esc_attr($theme['muted_color']) . ';">' . esc_html($title);

        if ($office) {
            $html .= ' • ' . esc_html($office);
        }

        $html .= '</p>
';

        // Greeting message
        if ($greeting) {
            $html .= '                                        <p style="margin:10px 0 0 0;font-size:15px;color:' . esc_attr($theme['muted_color']) . ';font-style:italic;">"' . esc_html($greeting) . '"</p>
';
        }

        $html .= '                                    </td>
                                </tr>
';

        // Contact buttons row
        if ($phone || $email) {
            $html .= '                                <tr>
                                    <td colspan="2" style="padding-top:15px;">
';
            if ($phone) {
                $html .= '                                        <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $phone)) . '" style="display:inline-block;background:' . esc_attr($theme['primary_color']) . ';color:#fff;padding:10px 20px;border-radius:5px;text-decoration:none;font-size:14px;margin-right:10px;">' . esc_html($phone) . '</a>
';
            }
            if ($email) {
                $html .= '                                        <a href="mailto:' . esc_attr($email) . '" style="display:inline-block;background:#f8f9fa;color:' . esc_attr($theme['primary_color']) . ';padding:10px 20px;border-radius:5px;text-decoration:none;font-size:14px;border:1px solid ' . esc_attr($theme['border_color']) . ';">Email Me</a>
';
            }
            $html .= '                                    </td>
                                </tr>
';
        }

        $html .= '                            </table>
                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Property card component
     *
     * @param array $vars Variables (listing, badge_color, badge_text, change_type, etc.)
     * @return string HTML
     */
    public function component_property_card($vars) {
        $listing = $vars['listing'] ?? array();
        $badge_color = $vars['badge_color'] ?? '#28a745';
        $badge_text = $vars['badge_text'] ?? 'NEW';
        $change_type = $vars['change_type'] ?? null;
        $change_data = $vars['change_data'] ?? array();
        $theme = $this->theme;

        // Extract listing data
        $address = $listing['full_address'] ?? $listing['street_address'] ?? 'Address unavailable';
        $city = $listing['city'] ?? '';
        $state = $listing['state_or_province'] ?? '';
        $zip = $listing['postal_code'] ?? '';
        $location = trim(implode(', ', array_filter(array($city, $state))) . ' ' . $zip);

        $price = isset($listing['list_price']) ? '$' . number_format($listing['list_price']) : '';
        $beds = $listing['bedrooms_total'] ?? '?';
        $baths = $listing['bathrooms_total'] ?? '?';
        $sqft = isset($listing['building_area_total']) ? number_format($listing['building_area_total']) : '';

        $photo_url = $listing['primary_photo'] ?? $listing['photo_url'] ?? $listing['main_photo_url'] ?? '';
        $listing_id = $listing['listing_key'] ?? $listing['id'] ?? '';
        $listing_url = $this->get_tracked_url(home_url('/property/' . $listing_id . '/'), 'property');

        $html = '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:20px;border:1px solid ' . esc_attr($theme['border_color']) . ';border-radius:10px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.08);">
';

        // Photo
        if ($photo_url) {
            $html .= '                                <tr>
                                    <td style="padding:0;">
                                        <a href="' . esc_url($listing_url) . '" style="display:block;">
                                            <img src="' . esc_url($photo_url) . '" alt="' . esc_attr($address) . '" width="100%" style="display:block;width:100%;height:auto;min-height:200px;max-height:280px;object-fit:cover;">
                                        </a>
                                    </td>
                                </tr>
';
        }

        // Content
        $html .= '                                <tr>
                                    <td style="padding:20px;">
                                        <span style="background:' . esc_attr($badge_color) . ';color:white;padding:5px 12px;border-radius:4px;font-size:13px;font-weight:bold;display:inline-block;margin-bottom:12px;letter-spacing:0.5px;">' . esc_html($badge_text) . '</span>
                                        <h3 style="margin:0 0 8px 0;font-size:20px;line-height:1.3;">
                                            <a href="' . esc_url($listing_url) . '" style="color:' . esc_attr($theme['text_color']) . ';text-decoration:none;">' . esc_html($address) . '</a>
                                        </h3>
                                        <p style="margin:0 0 12px 0;color:' . esc_attr($theme['muted_color']) . ';font-size:16px;">' . esc_html($location) . '</p>
                                        <p style="margin:0 0 10px 0;font-size:18px;line-height:1.5;">
                                            <strong style="color:' . esc_attr($theme['text_color']) . ';font-size:22px;">' . $price . '</strong>';

        if ($beds !== '?' || $baths !== '?') {
            $html .= ' <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:' . esc_attr($theme['text_color']) . ';">' . $beds . ' bed' . ($beds != 1 ? 's' : '') . '</span> <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:' . esc_attr($theme['text_color']) . ';">' . $baths . ' bath' . ($baths != 1 ? 's' : '') . '</span>';
        }

        if ($sqft) {
            $html .= ' <span style="color:#a3a3a3;margin:0 6px;">|</span> <span style="color:' . esc_attr($theme['text_color']) . ';">' . $sqft . ' sqft</span>';
        }

        $html .= '</p>
';

        // Price change details
        if ($change_type === 'price' && isset($change_data['old_price']) && isset($change_data['new_price'])) {
            $old = '$' . number_format($change_data['old_price']);
            $new = '$' . number_format($change_data['new_price']);
            $diff = $change_data['new_price'] - $change_data['old_price'];
            $diff_formatted = '$' . number_format(abs($diff));
            $arrow = $diff < 0 ? '&darr;' : '&uarr;';
            $color = $diff < 0 ? $theme['accent_color'] : '#dc2626';

            $html .= '                                        <p style="margin:12px 0 0 0;font-size:16px;color:' . esc_attr($theme['muted_color']) . ';background:#f9fafb;padding:10px 12px;border-radius:6px;">
                                            <span style="text-decoration:line-through;color:#6b7280;">' . $old . '</span>
                                            <span style="margin:0 8px;">&rarr;</span> <strong style="color:' . esc_attr($theme['text_color']) . ';">' . $new . '</strong>
                                            <span style="color:' . esc_attr($color) . ';font-weight:bold;margin-left:8px;">(' . $arrow . ' ' . $diff_formatted . ')</span>
                                        </p>
';
        }

        // Status change details
        if ($change_type === 'status' && isset($change_data['old_status']) && isset($change_data['new_status'])) {
            $html .= '                                        <p style="margin:12px 0 0 0;font-size:16px;color:' . esc_attr($theme['muted_color']) . ';background:#f9fafb;padding:10px 12px;border-radius:6px;">
                                            Status: <span style="text-decoration:line-through;color:#6b7280;">' . esc_html($change_data['old_status']) . '</span>
                                            <span style="margin:0 8px;">&rarr;</span> <strong style="color:#0891b2;">' . esc_html($change_data['new_status']) . '</strong>
                                        </p>
';
        }

        $html .= '                                    </td>
                                </tr>
                            </table>
';

        return $html;
    }

    /**
     * Footer component
     *
     * @param array $vars Variables
     * @return string HTML
     */
    private function component_footer($vars) {
        $theme = $this->theme;
        $agent = $vars['agent'] ?? null;
        $unsubscribe_url = $this->get_tracked_url($vars['unsubscribe_url'] ?? home_url('/saved-search/'), 'unsubscribe');
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        // Get social media URLs
        $facebook_url = get_option('mld_social_facebook', '');
        $twitter_url = get_option('mld_social_twitter', '');
        $instagram_url = get_option('mld_social_instagram', '');
        $linkedin_url = get_option('mld_social_linkedin', '');

        // Use agent social links if available
        if ($agent && !empty($agent['social_links'])) {
            $social = is_string($agent['social_links']) ? json_decode($agent['social_links'], true) : $agent['social_links'];
            if (is_array($social)) {
                $facebook_url = $social['facebook'] ?? $facebook_url;
                $twitter_url = $social['twitter'] ?? $twitter_url;
                $instagram_url = $social['instagram'] ?? $instagram_url;
                $linkedin_url = $social['linkedin'] ?? $linkedin_url;
            }
        }

        $html = '                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:25px 40px;text-align:center;border-top:1px solid ' . esc_attr($theme['border_color']) . ';">
';

        // Agent contact reminder
        if ($agent) {
            $agent_name = $agent['display_name'] ?? $agent['wp_display_name'] ?? '';
            $agent_phone = $agent['phone'] ?? '';

            if ($agent_name) {
                $html .= '                            <p style="margin:0 0 15px 0;color:' . esc_attr($theme['muted_color']) . ';font-size:15px;">
                                Questions? Contact ' . esc_html($agent_name);
                if ($agent_phone) {
                    $html .= ' at <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $agent_phone)) . '" style="color:' . esc_attr($theme['primary_color']) . ';font-weight:500;">' . esc_html($agent_phone) . '</a>';
                }
                $html .= '
                            </p>
';
            }
        }

        // Social links
        $social_links = array();
        if ($facebook_url) {
            $social_links[] = '<a href="' . esc_url($facebook_url) . '" style="display:inline-block;width:36px;height:36px;background:#1877F2;border-radius:50%;line-height:36px;color:#fff;text-decoration:none;margin:0 5px;font-weight:bold;">f</a>';
        }
        if ($twitter_url) {
            $social_links[] = '<a href="' . esc_url($twitter_url) . '" style="display:inline-block;width:36px;height:36px;background:#000;border-radius:50%;line-height:36px;color:#fff;text-decoration:none;margin:0 5px;font-size:18px;">&#120143;</a>';
        }
        if ($instagram_url) {
            $social_links[] = '<a href="' . esc_url($instagram_url) . '" style="display:inline-block;width:36px;height:36px;background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);border-radius:50%;line-height:36px;color:#fff;text-decoration:none;margin:0 5px;font-size:18px;">&#9737;</a>';
        }
        if ($linkedin_url) {
            $social_links[] = '<a href="' . esc_url($linkedin_url) . '" style="display:inline-block;width:36px;height:36px;background:#0A66C2;border-radius:50%;line-height:36px;color:#fff;text-decoration:none;margin:0 5px;font-weight:bold;">in</a>';
        }

        if (!empty($social_links)) {
            $html .= '                            <p style="margin:0 0 15px 0;">' . implode('', $social_links) . '</p>
';
        }

        // iOS App Store badge
        $app_store_url = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';
        $app_store_badge = 'https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83';
        $html .= '                            <div style="margin:20px 0;text-align:center;">
                                <p style="margin:0 0 10px 0;color:' . esc_attr($theme['muted_color']) . ';font-size:14px;">Get instant alerts on your iPhone</p>
                                <a href="' . esc_url($this->get_tracked_url($app_store_url, 'app_store')) . '" style="display:inline-block;">
                                    <img src="' . esc_url($app_store_badge) . '" alt="Download on the App Store" style="height:40px;width:auto;" />
                                </a>
                            </div>
';

        $html .= '                            <p style="margin:0 0 12px 0;color:' . esc_attr($theme['muted_color']) . ';font-size:15px;line-height:1.5;">
                                You\'re receiving this email because you created a saved search on <a href="' . esc_url($site_url) . '" style="color:' . esc_attr($theme['primary_color']) . ';font-weight:500;">' . esc_html($site_name) . '</a>
                            </p>
                            <p style="margin:0;font-size:14px;">
                                <a href="' . esc_url($unsubscribe_url) . '" style="color:' . esc_attr($theme['muted_color']) . ';text-decoration:underline;">Manage your saved searches</a>
                                <span style="color:#a3a3a3;margin:0 8px;">|</span>
                                <a href="' . esc_url($site_url) . '" style="color:' . esc_attr($theme['muted_color']) . ';text-decoration:underline;">Visit our website</a>
                            </p>
                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Market stats component
     *
     * @param array $vars Variables (avg_price, avg_dom, total_active, area_name)
     * @return string HTML
     */
    public function component_market_stats($vars) {
        $theme = $this->theme;
        $avg_price = $vars['avg_price'] ?? 0;
        $avg_dom = $vars['avg_dom'] ?? 0;
        $total_active = $vars['total_active'] ?? 0;
        $area_name = $vars['area_name'] ?? 'Your Search Area';

        if (!$avg_price) {
            return '';
        }

        return '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:30px 0;background:linear-gradient(135deg,' . esc_attr($theme['primary_color']) . ' 0%,' . esc_attr($theme['secondary_color']) . ' 100%);border-radius:10px;overflow:hidden;">
                                <tr>
                                    <td style="padding:25px;">
                                        <h3 style="color:#fff;margin:0 0 20px 0;font-size:20px;font-weight:600;">Market Insights for ' . esc_html($area_name) . '</h3>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">$' . number_format($avg_price) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Avg. List Price</div>
                                                    </div>
                                                </td>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">' . round($avg_dom) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Avg. Days on Market</div>
                                                    </div>
                                                </td>
                                                <td align="center" style="padding:10px;">
                                                    <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:18px;">
                                                        <div style="font-size:28px;font-weight:bold;color:#fff;">' . number_format($total_active) . '</div>
                                                        <div style="font-size:13px;color:#b8d4e8;text-transform:uppercase;letter-spacing:0.5px;">Active Listings</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
';
    }

    /**
     * CTA button component
     *
     * @param array $vars Variables (url, text)
     * @return string HTML
     */
    public function component_cta_button($vars) {
        $theme = $this->theme;
        $url = $this->get_tracked_url($vars['url'] ?? '#', 'cta');
        $text = $vars['text'] ?? 'View All Results';

        return '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:35px;">
                                <tr>
                                    <td align="center" class="button-wrapper">
                                        <a href="' . esc_url($url) . '" class="button-link" style="display:inline-block;background:linear-gradient(135deg,' . esc_attr($theme['primary_color']) . ' 0%,' . esc_attr($theme['secondary_color']) . ' 100%);color:#ffffff;padding:18px 45px;text-decoration:none;border-radius:8px;font-weight:600;font-size:18px;box-shadow:0 2px 4px rgba(0,0,0,0.15);">' . esc_html($text) . '</a>
                                    </td>
                                </tr>
                            </table>
';
    }

    /**
     * Render single alert template
     *
     * @param array $data Template data
     * @return string HTML
     */
    private function render_single_alert($data) {
        $html = '                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
';

        // Summary section
        $grouped = $data['grouped_changes'] ?? array();
        $total_matches = $data['total_matches'] ?? 0;
        $html .= $this->render_summary_stats($grouped, $total_matches);

        // New listings
        if (!empty($grouped['new_listing'])) {
            $html .= $this->render_section('New Listings', $grouped['new_listing'], '#28a745', 'NEW');
        }

        // Price changes
        if (!empty($grouped['price_change'])) {
            $html .= $this->render_section('Price Changes', $grouped['price_change'], null, null, 'price');
        }

        // Status changes
        if (!empty($grouped['status_change'])) {
            $html .= $this->render_section('Status Updates', $grouped['status_change'], '#17a2b8', 'STATUS CHANGE', 'status');
        }

        // Market insights
        if (!empty($data['market_stats'])) {
            $html .= $this->component_market_stats($data['market_stats']);
        }

        // View all button
        if (!empty($data['search_url'])) {
            $button_text = $total_matches > 25 ? 'View All ' . $total_matches . ' Results' : 'View All Results';
            $html .= $this->component_cta_button(array(
                'url' => $data['search_url'],
                'text' => $button_text,
            ));
        }

        $html .= '                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Render summary stats section
     *
     * @param array $grouped Grouped changes
     * @param int $total_matches Total matches
     * @return string HTML
     */
    private function render_summary_stats($grouped, $total_matches) {
        $new_count = count($grouped['new_listing'] ?? array());
        $price_count = count($grouped['price_change'] ?? array());
        $status_count = count($grouped['status_change'] ?? array());
        $shown_count = $new_count + $price_count + $status_count;

        $html = '                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:30px;">
                                <tr>
';

        if ($new_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#e8f5e9;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#16a34a;">' . $new_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">New</div>
                                        </div>
                                    </td>
';
        }

        if ($price_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#fff3cd;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#b45309;">' . $price_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">Price Changes</div>
                                        </div>
                                    </td>
';
        }

        if ($status_count > 0) {
            $html .= '                                    <td align="center" style="padding:10px;">
                                        <div style="background:#e0f2fe;border-radius:10px;padding:18px 24px;display:inline-block;">
                                            <div style="font-size:36px;font-weight:bold;color:#0284c7;">' . $status_count . '</div>
                                            <div style="font-size:14px;color:#4a4a4a;text-transform:uppercase;letter-spacing:0.5px;">Status Updates</div>
                                        </div>
                                    </td>
';
        }

        $html .= '                                </tr>
                            </table>
';

        if ($total_matches > $shown_count) {
            $html .= '                            <p style="text-align:center;color:#4a4a4a;font-size:16px;margin-bottom:25px;background:#f8f9fa;padding:12px;border-radius:6px;">
                                Showing <strong>' . $shown_count . '</strong> of <strong>' . $total_matches . '</strong> total matches
                            </p>
';
        }

        return $html;
    }

    /**
     * Render a section with listings
     *
     * @param string $title Section title
     * @param array $listings Listings data
     * @param string|null $badge_color Badge color (null for price changes to auto-determine)
     * @param string|null $badge_text Badge text (null for price changes to auto-determine)
     * @param string|null $change_type Change type (price, status)
     * @return string HTML
     */
    private function render_section($title, $listings, $badge_color = null, $badge_text = null, $change_type = null) {
        $theme = $this->theme;

        $html = '                            <h2 style="color:' . esc_attr($theme['text_color']) . ';font-size:22px;border-bottom:2px solid ' . esc_attr($theme['border_color']) . ';padding-bottom:12px;margin:30px 0 20px 0;font-weight:600;">' . esc_html($title) . ' (' . count($listings) . ')</h2>
';

        foreach ($listings as $listing_id => $change_data) {
            $listing_data = $change_data['listing_data'] ?? $change_data;

            // Determine badge for price changes
            $bc = $badge_color;
            $bt = $badge_text;
            if ($change_type === 'price') {
                $old_price = $change_data['old_price'] ?? 0;
                $new_price = $change_data['new_price'] ?? 0;
                $is_reduction = $new_price < $old_price;
                $bc = $is_reduction ? '#dc3545' : '#ffc107';
                $bt = $is_reduction ? 'PRICE REDUCED' : 'PRICE INCREASED';
            }

            $html .= $this->component_property_card(array(
                'listing' => $listing_data,
                'badge_color' => $bc ?? '#28a745',
                'badge_text' => $bt ?? 'NEW',
                'change_type' => $change_type,
                'change_data' => $change_data,
            ));
        }

        return $html;
    }

    /**
     * Render daily digest template
     *
     * @param array $data Template data
     * @return string HTML
     */
    private function render_daily_digest($data) {
        $theme = $this->theme;
        $searches = $data['searches'] ?? array();
        $total_new = $data['total_new'] ?? 0;
        $total_price_changes = $data['total_price_changes'] ?? 0;
        $total_searches = count($searches);

        $html = '                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
';

        // Summary header
        $html .= '                            <p style="text-align:center;font-size:18px;color:' . esc_attr($theme['muted_color']) . ';margin:0 0 25px 0;">
                                <strong style="color:' . esc_attr($theme['text_color']) . ';">' . $total_new . ' new listings</strong> and
                                <strong style="color:' . esc_attr($theme['text_color']) . ';">' . $total_price_changes . ' price changes</strong>
                                across <strong style="color:' . esc_attr($theme['text_color']) . ';">' . $total_searches . ' saved searches</strong>
                            </p>
';

        // Highlights section (best price drops, back on market)
        if (!empty($data['highlights'])) {
            $html .= '                            <div style="background:#f0f7ff;border-radius:10px;padding:20px;margin-bottom:30px;">
                                <h3 style="margin:0 0 15px 0;color:' . esc_attr($theme['primary_color']) . ';font-size:18px;">Today\'s Highlights</h3>
';
            foreach ($data['highlights'] as $highlight) {
                $html .= $this->component_property_card($highlight);
            }
            $html .= '                            </div>
';
        }

        // Per-search sections
        foreach ($searches as $search) {
            $search_name = $search['name'] ?? 'Unnamed Search';
            $changes = $search['changes'] ?? array();
            $total = count($changes);

            if ($total === 0) {
                continue;
            }

            $html .= '                            <div style="margin-bottom:30px;">
                                <h3 style="color:' . esc_attr($theme['text_color']) . ';font-size:18px;margin:0 0 15px 0;padding-bottom:10px;border-bottom:1px solid ' . esc_attr($theme['border_color']) . ';">' . esc_html($search_name) . ' <span style="color:' . esc_attr($theme['muted_color']) . ';font-weight:normal;">(' . $total . ' updates)</span></h3>
';

            // Show first 3 properties
            $shown = 0;
            foreach ($changes as $listing_id => $change_data) {
                if ($shown >= 3) {
                    break;
                }
                $html .= $this->component_property_card(array(
                    'listing' => $change_data['listing_data'] ?? $change_data,
                    'badge_color' => $change_data['badge_color'] ?? '#28a745',
                    'badge_text' => $change_data['badge_text'] ?? 'NEW',
                    'change_type' => $change_data['change_type'] ?? null,
                    'change_data' => $change_data,
                ));
                $shown++;
            }

            // "See X more" link if there are more
            if ($total > 3 && !empty($search['search_url'])) {
                $html .= '                                <p style="text-align:center;margin:15px 0 0 0;">
                                    <a href="' . esc_url($this->get_tracked_url($search['search_url'], 'see_more')) . '" style="color:' . esc_attr($theme['primary_color']) . ';font-weight:600;text-decoration:none;">See ' . ($total - 3) . ' more &rarr;</a>
                                </p>
';
            }

            $html .= '                            </div>
';
        }

        $html .= '                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Render weekly roundup template
     *
     * @param array $data Template data
     * @return string HTML
     */
    private function render_weekly_roundup($data) {
        $theme = $this->theme;

        $html = '                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
';

        // Week stats
        $html .= '                            <div style="background:linear-gradient(135deg,' . esc_attr($theme['primary_color']) . ' 0%,' . esc_attr($theme['secondary_color']) . ' 100%);border-radius:10px;padding:25px;margin-bottom:30px;color:#fff;">
                                <h3 style="margin:0 0 20px 0;font-size:20px;">This Week\'s Market Activity</h3>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td align="center" style="padding:10px;">
                                            <div style="font-size:32px;font-weight:bold;">' . ($data['total_new'] ?? 0) . '</div>
                                            <div style="font-size:13px;opacity:0.8;">New Listings</div>
                                        </td>
                                        <td align="center" style="padding:10px;">
                                            <div style="font-size:32px;font-weight:bold;">' . ($data['total_price_drops'] ?? 0) . '</div>
                                            <div style="font-size:13px;opacity:0.8;">Price Drops</div>
                                        </td>
                                        <td align="center" style="padding:10px;">
                                            <div style="font-size:32px;font-weight:bold;">' . ($data['total_pending'] ?? 0) . '</div>
                                            <div style="font-size:13px;opacity:0.8;">Went Pending</div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
';

        // Top picks section
        if (!empty($data['top_picks'])) {
            $html .= '                            <h3 style="color:' . esc_attr($theme['text_color']) . ';font-size:20px;margin:0 0 20px 0;">Top Picks This Week</h3>
';
            foreach ($data['top_picks'] as $pick) {
                $html .= $this->component_property_card($pick);
            }
        }

        // Best price drops
        if (!empty($data['best_price_drops'])) {
            $html .= '                            <h3 style="color:' . esc_attr($theme['text_color']) . ';font-size:20px;margin:30px 0 20px 0;">Best Price Drops</h3>
';
            foreach ($data['best_price_drops'] as $drop) {
                $html .= $this->component_property_card($drop);
            }
        }

        // Market trends
        if (!empty($data['market_trends'])) {
            $html .= $this->component_market_stats($data['market_trends']);
        }

        $html .= '                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Render welcome email template
     *
     * @param array $data Template data
     * @return string HTML
     */
    private function render_welcome($data) {
        $theme = $this->theme;
        $search_name = $data['search_name'] ?? 'your new saved search';
        $filters_summary = $data['filters_summary'] ?? '';
        $match_count = $data['match_count'] ?? 0;

        $html = '                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;text-align:center;">
                            <div style="font-size:60px;margin-bottom:20px;">&#127881;</div>
                            <h2 style="color:' . esc_attr($theme['text_color']) . ';font-size:24px;margin:0 0 15px 0;">Your Saved Search is Live!</h2>
                            <p style="color:' . esc_attr($theme['muted_color']) . ';font-size:16px;margin:0 0 25px 0;line-height:1.6;">
                                We\'ll notify you whenever new properties match <strong>"' . esc_html($search_name) . '"</strong>
                            </p>
';

        if ($filters_summary) {
            $html .= '                            <div style="background:#f8f9fa;border-radius:8px;padding:20px;text-align:left;margin-bottom:25px;">
                                <h4 style="margin:0 0 10px 0;color:' . esc_attr($theme['text_color']) . ';">Your Search Criteria:</h4>
                                <p style="margin:0;color:' . esc_attr($theme['muted_color']) . ';font-size:15px;">' . esc_html($filters_summary) . '</p>
                            </div>
';
        }

        if ($match_count > 0) {
            $html .= '                            <p style="color:' . esc_attr($theme['accent_color']) . ';font-size:18px;font-weight:600;margin:0 0 25px 0;">
                                ' . number_format($match_count) . ' properties currently match your criteria
                            </p>
';
        }

        if (!empty($data['search_url'])) {
            $html .= $this->component_cta_button(array(
                'url' => $data['search_url'],
                'text' => 'View Matching Properties',
            ));
        }

        $html .= '                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Render agent intro email template
     *
     * @param array $data Template data
     * @return string HTML
     */
    private function render_agent_intro($data) {
        $theme = $this->theme;
        $agent = $this->agent_data;
        $client_name = $this->client_data['first_name'] ?? 'there';

        if (!$agent) {
            return '';
        }

        $html = '                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <h2 style="color:' . esc_attr($theme['text_color']) . ';font-size:24px;margin:0 0 20px 0;text-align:center;">Hi ' . esc_html($client_name) . '!</h2>
                            <p style="color:' . esc_attr($theme['muted_color']) . ';font-size:16px;margin:0 0 25px 0;line-height:1.6;text-align:center;">
                                I\'m excited to help you find your perfect home. As your dedicated real estate agent, I\'ll be here to guide you through every step of your home search.
                            </p>
';

        // Agent bio section
        if (!empty($agent['bio'])) {
            $html .= '                            <div style="background:#f8f9fa;border-radius:10px;padding:25px;margin-bottom:25px;">
                                <h3 style="margin:0 0 15px 0;color:' . esc_attr($theme['text_color']) . ';">About Me</h3>
                                <p style="margin:0;color:' . esc_attr($theme['muted_color']) . ';font-size:15px;line-height:1.6;">' . esc_html($agent['bio']) . '</p>
                            </div>
';
        }

        // What to expect section
        $html .= '                            <div style="margin-bottom:25px;">
                                <h3 style="margin:0 0 15px 0;color:' . esc_attr($theme['text_color']) . ';">What to Expect</h3>
                                <ul style="margin:0;padding-left:20px;color:' . esc_attr($theme['muted_color']) . ';font-size:15px;line-height:1.8;">
                                    <li>Personalized property recommendations based on your criteria</li>
                                    <li>Priority access to new listings before they hit the market</li>
                                    <li>Expert guidance on neighborhoods, schools, and market trends</li>
                                    <li>Support throughout the buying process from offer to closing</li>
                                </ul>
                            </div>
';

        // Contact CTA
        $phone = $agent['phone'] ?? '';
        $email = $agent['email'] ?? $agent['user_email'] ?? '';

        $html .= '                            <div style="text-align:center;margin-top:30px;">
                                <p style="margin:0 0 20px 0;color:' . esc_attr($theme['muted_color']) . ';font-size:16px;">Ready to get started? Let\'s connect!</p>
';

        if ($phone) {
            $html .= '                                <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $phone)) . '" style="display:inline-block;background:' . esc_attr($theme['primary_color']) . ';color:#fff;padding:14px 30px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:600;margin:5px;">Call Me</a>
';
        }
        if ($email) {
            $html .= '                                <a href="mailto:' . esc_attr($email) . '" style="display:inline-block;background:#f8f9fa;color:' . esc_attr($theme['primary_color']) . ';padding:14px 30px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:600;border:2px solid ' . esc_attr($theme['primary_color']) . ';margin:5px;">Email Me</a>
';
        }

        $html .= '                            </div>
                        </td>
                    </tr>
';

        return $html;
    }

    /**
     * Record email send in analytics
     *
     * @param int $user_id User ID
     * @param string $email_type Email type
     * @param int|null $search_id Search ID (optional)
     * @param array $listings_included Listing IDs included
     * @return bool Success
     */
    public function record_send($user_id, $email_type, $search_id = null, $listings_included = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_email_analytics';

        return $wpdb->insert(
            $table,
            array(
                'email_id' => $this->email_id,
                'user_id' => $user_id,
                'search_id' => $search_id,
                'email_type' => $email_type,
                'sent_at' => current_time('mysql'),
                'listings_included' => !empty($listings_included) ? json_encode($listings_included) : null,
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s')
        ) !== false;
    }

    /**
     * Record email open
     *
     * @param string $email_id Email ID
     * @return bool Success
     */
    public static function record_open($email_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_email_analytics';

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET opened_at = COALESCE(opened_at, %s),
                 open_count = open_count + 1
             WHERE email_id = %s",
            current_time('mysql'),
            $email_id
        )) !== false;
    }

    /**
     * Record email click
     *
     * @param string $email_id Email ID
     * @return bool Success
     */
    public static function record_click($email_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_email_analytics';

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET click_count = click_count + 1
             WHERE email_id = %s",
            $email_id
        )) !== false;
    }
}
