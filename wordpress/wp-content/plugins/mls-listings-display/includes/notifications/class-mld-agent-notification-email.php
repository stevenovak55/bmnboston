<?php
/**
 * Agent Notification Email Builder
 *
 * Builds branded HTML emails for agent activity notifications.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.43.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Agent_Notification_Email {

    /**
     * Build email content for a notification type
     *
     * @param string $notification_type Type constant from MLD_Agent_Notification_Preferences
     * @param array $context Context data
     * @return array|null Array with 'subject' and 'body', or null on failure
     */
    public static function build($notification_type, $context) {
        $client_name = $context['client_name'] ?? 'Your client';

        switch ($notification_type) {
            case MLD_Agent_Notification_Preferences::TYPE_CLIENT_LOGIN:
                return self::build_login_email($context);

            case MLD_Agent_Notification_Preferences::TYPE_APP_OPEN:
                return self::build_app_open_email($context);

            case MLD_Agent_Notification_Preferences::TYPE_FAVORITE_ADDED:
                return self::build_favorite_email($context);

            case MLD_Agent_Notification_Preferences::TYPE_SEARCH_CREATED:
                return self::build_search_created_email($context);

            case MLD_Agent_Notification_Preferences::TYPE_TOUR_REQUESTED:
                return self::build_tour_requested_email($context);

            default:
                return null;
        }
    }

    /**
     * Build login notification email
     */
    private static function build_login_email($context) {
        $client_name = $context['client_name'] ?? 'Your client';
        $platform = $context['platform'] ?? 'the app';

        $subject = "{$client_name} just logged in";

        $headline = "{$client_name} just logged in";
        $message = "Your client logged in via {$platform}. This might be a good time to check in and see if they need any help with their search.";

        $cta_text = 'View Client Activity';
        $cta_url = admin_url('admin.php?page=mld-agent-dashboard');

        $body = self::build_html_email($headline, $message, $cta_text, $cta_url, $context);

        return array(
            'subject' => $subject,
            'body' => $body
        );
    }

    /**
     * Build app open notification email
     */
    private static function build_app_open_email($context) {
        $client_name = $context['client_name'] ?? 'Your client';

        $subject = "{$client_name} is browsing properties";

        $headline = "{$client_name} is browsing properties";
        $message = "Your client just opened the app and is actively browsing. This is a great opportunity to reach out and offer assistance.";

        $cta_text = 'View Client Activity';
        $cta_url = admin_url('admin.php?page=mld-agent-dashboard');

        $body = self::build_html_email($headline, $message, $cta_text, $cta_url, $context);

        return array(
            'subject' => $subject,
            'body' => $body
        );
    }

    /**
     * Build favorite added notification email
     */
    private static function build_favorite_email($context) {
        $client_name = $context['client_name'] ?? 'Your client';
        $address = $context['property_address'] ?? 'a property';
        $listing_id = $context['listing_id'] ?? '';

        $subject = "{$client_name} favorited {$address}";

        $headline = "{$client_name} favorited a property";

        $property_html = '';
        if (!empty($address)) {
            $property_url = $listing_id ? home_url('/property/' . $listing_id . '/') : '#';
            $property_html = sprintf(
                '<div style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 20px 0;">
                    <p style="margin: 0; font-size: 16px; color: #333;"><strong>%s</strong></p>
                    <p style="margin: 8px 0 0; font-size: 14px;">
                        <a href="%s" style="color: #1a73e8; text-decoration: none;">View Property</a>
                    </p>
                </div>',
                esc_html($address),
                esc_url($property_url)
            );
        }

        $message = "Your client just saved this property to their favorites. They may be interested in scheduling a showing.";

        $cta_text = 'View Client Activity';
        $cta_url = admin_url('admin.php?page=mld-agent-dashboard');

        $body = self::build_html_email($headline, $message . $property_html, $cta_text, $cta_url, $context);

        return array(
            'subject' => $subject,
            'body' => $body
        );
    }

    /**
     * Build saved search created notification email
     */
    private static function build_search_created_email($context) {
        $client_name = $context['client_name'] ?? 'Your client';
        $search_name = $context['search_name'] ?? 'a new search';

        $subject = "{$client_name} created a saved search";

        $headline = "{$client_name} created a saved search";

        $search_html = '';
        if (!empty($search_name)) {
            $search_html = sprintf(
                '<div style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 20px 0;">
                    <p style="margin: 0; font-size: 14px; color: #666;">Search Name:</p>
                    <p style="margin: 4px 0 0; font-size: 16px; color: #333;"><strong>%s</strong></p>
                </div>',
                esc_html($search_name)
            );
        }

        $message = "Your client set up a new saved search and will receive alerts for matching properties.";

        $cta_text = 'View Client Searches';
        $cta_url = admin_url('admin.php?page=mld-agent-dashboard');

        $body = self::build_html_email($headline, $message . $search_html, $cta_text, $cta_url, $context);

        return array(
            'subject' => $subject,
            'body' => $body
        );
    }

    /**
     * Build tour requested notification email
     */
    private static function build_tour_requested_email($context) {
        $client_name = $context['client_name'] ?? 'Your client';
        $address = $context['property_address'] ?? 'a property';
        $date = $context['date'] ?? '';
        $time = $context['time'] ?? '';

        $subject = "Tour Request from {$client_name}";

        $headline = "{$client_name} requested a tour";

        $tour_html = '<div style="background: #e8f5e9; border-radius: 8px; padding: 16px; margin: 20px 0; border-left: 4px solid #4caf50;">';
        $tour_html .= sprintf('<p style="margin: 0; font-size: 16px; color: #333;"><strong>%s</strong></p>', esc_html($address));

        if ($date || $time) {
            $tour_html .= sprintf(
                '<p style="margin: 8px 0 0; font-size: 14px; color: #666;">Requested: %s %s</p>',
                esc_html($date),
                esc_html($time)
            );
        }
        $tour_html .= '</div>';

        $message = "Please confirm or reschedule this showing at your earliest convenience.";

        $cta_text = 'View Tour Request';
        $cta_url = admin_url('admin.php?page=snab-appointments');

        $body = self::build_html_email($headline, $tour_html . $message, $cta_text, $cta_url, $context);

        return array(
            'subject' => $subject,
            'body' => $body
        );
    }

    /**
     * Build the HTML email wrapper
     *
     * @param string $headline Main headline
     * @param string $message Message content (can include HTML)
     * @param string $cta_text CTA button text
     * @param string $cta_url CTA button URL
     * @param array $context Context data
     * @return string HTML email body
     */
    private static function build_html_email($headline, $message, $cta_text, $cta_url, $context) {
        $client_name = $context['client_name'] ?? '';
        $client_email = $context['client_email'] ?? '';
        $timestamp = current_time('F j, Y \a\t g:i A');

        // Get site branding
        $site_name = get_bloginfo('name');
        $logo_url = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_attr($headline); ?></title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 32px 40px 24px; border-bottom: 1px solid #eee;">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>" style="max-height: 40px; width: auto;">
                            <?php else: ?>
                                <p style="margin: 0; font-size: 20px; font-weight: 600; color: #333;"><?php echo esc_html($site_name); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <!-- Headline -->
                            <h1 style="margin: 0 0 16px; font-size: 24px; font-weight: 600; color: #333; line-height: 1.3;">
                                <?php echo esc_html($headline); ?>
                            </h1>

                            <!-- Timestamp -->
                            <p style="margin: 0 0 24px; font-size: 14px; color: #666;">
                                <?php echo esc_html($timestamp); ?>
                            </p>

                            <!-- Message -->
                            <div style="font-size: 16px; line-height: 1.6; color: #444;">
                                <?php echo $message; ?>
                            </div>

                            <!-- Client Info Card -->
                            <?php if ($client_name || $client_email): ?>
                            <div style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin: 24px 0 0;">
                                <p style="margin: 0; font-size: 12px; text-transform: uppercase; color: #666; letter-spacing: 0.5px;">Client</p>
                                <?php if ($client_name): ?>
                                    <p style="margin: 4px 0 0; font-size: 16px; font-weight: 500; color: #333;"><?php echo esc_html($client_name); ?></p>
                                <?php endif; ?>
                                <?php if ($client_email): ?>
                                    <p style="margin: 2px 0 0; font-size: 14px; color: #666;">
                                        <a href="mailto:<?php echo esc_attr($client_email); ?>" style="color: #1a73e8; text-decoration: none;"><?php echo esc_html($client_email); ?></a>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- CTA Button -->
                            <div style="margin-top: 32px;">
                                <a href="<?php echo esc_url($cta_url); ?>" style="display: inline-block; padding: 14px 28px; background: #1a73e8; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 500;">
                                    <?php echo esc_html($cta_text); ?>
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 40px; background: #f8f9fa; border-radius: 0 0 12px 12px;">
                            <p style="margin: 0; font-size: 13px; color: #666; line-height: 1.5;">
                                You're receiving this because you have client activity notifications enabled.
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mld-notification-settings')); ?>" style="color: #1a73e8; text-decoration: none;">Manage preferences</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
