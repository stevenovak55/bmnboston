<?php
/**
 * MLS Listings Display - Shared Properties Notifier
 *
 * Handles push and email notifications when agents share properties with clients.
 *
 * @package MLS_Listings_Display
 * @subpackage Shared_Properties
 * @since 6.35.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Shared_Properties_Notifier {

    /**
     * Single instance.
     *
     * @var MLD_Shared_Properties_Notifier
     */
    private static $instance = null;

    /**
     * Get single instance.
     *
     * @return MLD_Shared_Properties_Notifier
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
        // Nothing to initialize yet
    }

    /**
     * Send notifications when properties are shared.
     *
     * @param int   $agent_id   Agent's user ID.
     * @param int   $client_id  Client's user ID.
     * @param array $shares     Array of share records with property data.
     * @param string $note      Agent's note to client.
     * @return array Results with push and email counts.
     */
    public function notify_client($agent_id, $client_id, $shares, $note = '') {
        $results = array(
            'push' => 0,
            'email' => 0,
        );

        if (empty($shares)) {
            return $results;
        }

        // Get agent and client data
        $agent = $this->get_agent_data($agent_id);
        $client = get_user_by('id', $client_id);

        if (!$agent || !$client) {
            return $results;
        }

        $property_count = count($shares);

        // Send push notification
        $push_sent = $this->send_push_notification($client_id, $agent, $shares, $note);
        if ($push_sent) {
            $results['push'] = 1;
        }

        // Send email notification
        $email_sent = $this->send_email_notification($client, $agent, $shares, $note);
        if ($email_sent) {
            $results['email'] = 1;
        }

        return $results;
    }

    /**
     * Send push notification to client.
     *
     * @param int    $client_id Client's user ID.
     * @param array  $agent     Agent data.
     * @param array  $shares    Shared properties.
     * @param string $note      Agent's note.
     * @return bool Success.
     */
    private function send_push_notification($client_id, $agent, $shares, $note) {
        // Check if SNAB push notifications are available
        if (!function_exists('snab_push_notifications')) {
            error_log('[MLD Shared Properties] SNAB push notifications not available');
            return false;
        }

        $push = snab_push_notifications();
        $devices = $push->get_user_devices($client_id);

        if (empty($devices)) {
            return false;
        }

        $property_count = count($shares);
        $agent_name = $agent['display_name'] ?? $agent['name'] ?? 'Your agent';

        // Build notification content
        if ($property_count === 1) {
            $first_share = reset($shares);
            $address = $first_share['address'] ?? 'a property';
            $title = "New Property from {$agent_name}";
            $body = $note ?: "Check out {$address}";
        } else {
            $title = "New Properties from {$agent_name}";
            $body = $note ?: "{$agent_name} shared {$property_count} properties with you";
        }

        // Truncate body if too long
        if (strlen($body) > 100) {
            $body = substr($body, 0, 97) . '...';
        }

        // Custom data for deep linking
        $first_share = reset($shares);
        $data = array(
            'type' => 'shared_property',
            'agent_id' => $agent['id'] ?? 0,
            'property_count' => $property_count,
            'listing_key' => $first_share['listing_key'] ?? '',
            'share_id' => $first_share['id'] ?? 0,
        );

        // Send to all client's devices
        $sent = false;
        foreach ($devices as $device) {
            $result = $push->send_notification(
                $device->device_token,
                $title,
                $body,
                $data,
                (bool) $device->is_sandbox
            );

            if ($result === true) {
                $sent = true;
            }
        }

        return $sent;
    }

    /**
     * Send email notification to client.
     *
     * @param WP_User $client Client user object.
     * @param array   $agent  Agent data.
     * @param array   $shares Shared properties.
     * @param string  $note   Agent's note.
     * @return bool Success.
     */
    private function send_email_notification($client, $agent, $shares, $note) {
        // Check if email template engine is available
        if (!class_exists('MLD_Email_Template_Engine')) {
            error_log('[MLD Shared Properties] Email template engine not available');
            return false;
        }

        $property_count = count($shares);
        $agent_name = $agent['display_name'] ?? $agent['name'] ?? 'Your agent';

        // Build email subject
        if ($property_count === 1) {
            $subject = "{$agent_name} shared a property with you";
        } else {
            $subject = "{$agent_name} shared {$property_count} properties with you";
        }

        // Build email content
        $email_engine = new MLD_Email_Template_Engine();
        $email_engine->set_agent($agent);
        $email_engine->set_client($client);

        // Render the shared properties email
        $html_content = $email_engine->render('shared-properties', array(
            'title' => 'Properties For You',
            'subtitle' => $note ?: 'Hand-picked by your agent',
            'shares' => $shares,
            'agent' => $agent,
            'agent_note' => $note,
            'dashboard_url' => home_url('/my-dashboard/'),
        ));

        // If shared-properties template doesn't exist, use a simple fallback
        if (empty($html_content) || strpos($html_content, 'Properties For You') === false) {
            $html_content = $this->build_fallback_email($client, $agent, $shares, $note);
        }

        // Send email - from agent directly since this is agent-to-client (v6.63.0)
        $agent_email = $agent['email'] ?? $agent['user_email'] ?? '';
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        // Use agent's email as From address if available, otherwise fallback to settings
        if ($agent_email && $agent_name) {
            $headers[] = 'From: ' . $agent_name . ' <' . $agent_email . '>';
        } elseif (class_exists('MLD_Email_Utilities')) {
            $headers[] = 'From: ' . MLD_Email_Utilities::get_from_header($client->ID);
        } else {
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
        }

        $sent = wp_mail($client->user_email, $subject, $html_content, $headers);

        if ($sent) {
            // Record email send for analytics
            $email_engine->record_send(
                $client->ID,
                'shared-properties',
                null,
                array_column($shares, 'listing_key')
            );
        }

        return $sent;
    }

    /**
     * Build fallback email HTML if template not available.
     *
     * @param WP_User $client Client user object.
     * @param array   $agent  Agent data.
     * @param array   $shares Shared properties.
     * @param string  $note   Agent's note.
     * @return string HTML content.
     */
    private function build_fallback_email($client, $agent, $shares, $note) {
        $property_count = count($shares);
        $agent_name = $agent['display_name'] ?? $agent['name'] ?? 'Your agent';
        $agent_phone = $agent['phone'] ?? '';
        $agent_email = $agent['email'] ?? $agent['user_email'] ?? '';
        $agent_photo = $agent['photo_url'] ?? '';
        $site_name = get_bloginfo('name');
        $dashboard_url = home_url('/my-dashboard/');

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background-color:#f4f4f4;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f4;">
        <tr>
            <td align="center" style="padding:20px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);padding:30px 40px;text-align:center;">
                            <h1 style="color:#ffffff;margin:0;font-size:24px;">Properties For You</h1>
                            <p style="color:#b8d4e8;margin:12px 0 0 0;font-size:16px;">Hand-picked by your agent</p>
                        </td>
                    </tr>

                    <!-- Agent Card -->
                    <tr>
                        <td style="padding:25px 40px;background:#f0f7ff;border-bottom:1px solid #e5e7eb;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>';

        if ($agent_photo) {
            $html .= '
                                    <td width="80" valign="top" style="padding-right:20px;">
                                        <img src="' . esc_url($agent_photo) . '" alt="' . esc_attr($agent_name) . '" width="80" height="80" style="border-radius:50%;display:block;object-fit:cover;">
                                    </td>';
        }

        $html .= '
                                    <td valign="top">
                                        <p style="margin:0 0 5px 0;font-size:18px;font-weight:600;color:#1a1a1a;">' . esc_html($agent_name) . '</p>
                                        <p style="margin:0;font-size:14px;color:#4a4a4a;">Your Real Estate Agent</p>';

        if ($note) {
            $html .= '
                                        <p style="margin:15px 0 0 0;font-size:15px;color:#4a4a4a;font-style:italic;">"' . esc_html($note) . '"</p>';
        }

        $html .= '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 40px;">
                            <p style="margin:0 0 25px 0;font-size:16px;color:#4a4a4a;text-align:center;">
                                ' . esc_html($agent_name) . ' shared <strong>' . $property_count . '</strong> ' . ($property_count === 1 ? 'property' : 'properties') . ' with you
                            </p>';

        // Property cards (show up to 5)
        $shown = 0;
        foreach ($shares as $share) {
            if ($shown >= 5) break;

            $address = $share['address'] ?? 'Address unavailable';
            $city = $share['city'] ?? '';
            $price = isset($share['price']) ? '$' . number_format($share['price']) : '';
            $beds = $share['beds'] ?? '?';
            $baths = $share['baths'] ?? '?';
            $photo_url = $share['photo_url'] ?? '';
            $listing_key = $share['listing_key'] ?? '';
            $property_url = home_url('/property/' . $listing_key . '/');

            $html .= '
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:20px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                                <tr>
                                    <td style="padding:0;">';

            if ($photo_url) {
                $html .= '
                                        <a href="' . esc_url($property_url) . '" style="display:block;">
                                            <img src="' . esc_url($photo_url) . '" alt="' . esc_attr($address) . '" width="100%" style="display:block;width:100%;height:auto;max-height:200px;object-fit:cover;">
                                        </a>';
            }

            $html .= '
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:15px;">
                                        <span style="background:#1e3a5f;color:white;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:bold;">FROM YOUR AGENT</span>
                                        <h3 style="margin:10px 0 5px 0;font-size:16px;">
                                            <a href="' . esc_url($property_url) . '" style="color:#1a1a1a;text-decoration:none;">' . esc_html($address) . '</a>
                                        </h3>
                                        <p style="margin:0 0 8px 0;color:#4a4a4a;font-size:14px;">' . esc_html($city) . '</p>
                                        <p style="margin:0;font-size:16px;">
                                            <strong style="color:#1a1a1a;">' . $price . '</strong>
                                            <span style="color:#a3a3a3;margin:0 6px;">|</span>
                                            <span style="color:#1a1a1a;">' . $beds . ' bed' . ($beds != 1 ? 's' : '') . '</span>
                                            <span style="color:#a3a3a3;margin:0 6px;">|</span>
                                            <span style="color:#1a1a1a;">' . $baths . ' bath' . ($baths != 1 ? 's' : '') . '</span>
                                        </p>
                                    </td>
                                </tr>
                            </table>';

            $shown++;
        }

        // "View more" note if there are more properties
        if ($property_count > 5) {
            $html .= '
                            <p style="text-align:center;color:#4a4a4a;font-size:14px;margin:20px 0;">
                                + ' . ($property_count - 5) . ' more ' . (($property_count - 5) === 1 ? 'property' : 'properties') . '
                            </p>';
        }

        // CTA Button
        $html .= '
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:25px;">
                                <tr>
                                    <td align="center">
                                        <a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:linear-gradient(135deg,#1e3a5f 0%,#2d5a87 100%);color:#ffffff;padding:14px 40px;text-decoration:none;border-radius:8px;font-weight:600;font-size:16px;">View All Properties</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8f9fa;padding:25px 40px;text-align:center;border-top:1px solid #e5e7eb;">
                            <p style="margin:0 0 10px 0;color:#4a4a4a;font-size:14px;">
                                Questions? Contact ' . esc_html($agent_name);

        if ($agent_phone) {
            $html .= ' at <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $agent_phone)) . '" style="color:#1e3a5f;font-weight:500;">' . esc_html($agent_phone) . '</a>';
        }

        $html .= '
                            </p>
                            <p style="margin:0;color:#a3a3a3;font-size:13px;">
                                ' . esc_html($site_name) . '
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

        return $html;
    }

    /**
     * Get agent data by user ID.
     *
     * @param int $agent_id Agent's user ID.
     * @return array|null Agent data or null.
     */
    private function get_agent_data($agent_id) {
        // Try to get from agent profiles table first
        if (class_exists('MLD_Agent_Client_Manager')) {
            $agent = MLD_Agent_Client_Manager::get_agent($agent_id);
            if ($agent) {
                return $agent;
            }
        }

        // Fallback to user data
        $user = get_user_by('id', $agent_id);
        if (!$user) {
            return null;
        }

        return array(
            'id' => $user->ID,
            'name' => $user->display_name,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'user_email' => $user->user_email,
            'phone' => get_user_meta($user->ID, 'phone', true),
            'photo_url' => get_avatar_url($user->ID, array('size' => 200)),
        );
    }
}

/**
 * Get shared properties notifier instance.
 *
 * @return MLD_Shared_Properties_Notifier
 */
function mld_shared_properties_notifier() {
    return MLD_Shared_Properties_Notifier::instance();
}
