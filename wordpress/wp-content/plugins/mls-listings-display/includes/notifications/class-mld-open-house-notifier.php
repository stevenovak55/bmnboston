<?php
/**
 * MLD Open House Notifier
 *
 * Sends push and email notifications to users when their favorited properties
 * have upcoming open houses.
 *
 * Notification triggers:
 * 1. When an open house is first detected on a favorited property
 * 2. Reminder the morning of the open house day
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Open_House_Notifier {

    /**
     * Cron hook for detecting new open houses
     */
    const CRON_HOOK_DETECT = 'mld_open_house_detection';

    /**
     * Cron hook for day-of reminders
     */
    const CRON_HOOK_REMIND = 'mld_open_house_reminders';

    /**
     * Option key for tracking last detection run
     */
    const LAST_DETECT_OPTION = 'mld_open_house_last_detect';

    /**
     * Initialize the notifier
     */
    public static function init() {
        // Register cron actions
        add_action(self::CRON_HOOK_DETECT, [__CLASS__, 'detect_and_notify_new_open_houses']);
        add_action(self::CRON_HOOK_REMIND, [__CLASS__, 'send_day_of_reminders']);

        // Self-healing: ensure crons are scheduled
        add_action('admin_init', [__CLASS__, 'maybe_schedule_events'], 20);
    }

    /**
     * Schedule the cron events if not already scheduled
     */
    public static function maybe_schedule_events() {
        // Detection runs every hour
        if (!wp_next_scheduled(self::CRON_HOOK_DETECT)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK_DETECT);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Open House Notifier: Scheduled detection cron');
            }
        }

        // Reminders run daily at 8 AM
        if (!wp_next_scheduled(self::CRON_HOOK_REMIND)) {
            // Schedule for 8 AM tomorrow
            $tomorrow_8am = strtotime('tomorrow 08:00:00');
            wp_schedule_event($tomorrow_8am, 'daily', self::CRON_HOOK_REMIND);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Open House Notifier: Scheduled reminder cron for ' . date('Y-m-d H:i:s', $tomorrow_8am));
            }
        }
    }

    /**
     * Unschedule the cron events
     */
    public static function unschedule_events() {
        wp_clear_scheduled_hook(self::CRON_HOOK_DETECT);
        wp_clear_scheduled_hook(self::CRON_HOOK_REMIND);
    }

    /**
     * Detect new open houses on favorited properties and notify users
     *
     * @return array Results of the detection and notification process
     */
    public static function detect_and_notify_new_open_houses() {
        global $wpdb;

        $start_time = microtime(true);
        $results = [
            'new_open_houses' => 0,
            'users_notified' => 0,
            'push_sent' => 0,
            'email_sent' => 0,
            'errors' => []
        ];

        try {
            $open_houses_table = $wpdb->prefix . 'bme_open_houses';
            $preferences_table = $wpdb->prefix . 'mld_property_preferences';
            $notifications_table = $wpdb->prefix . 'mld_open_house_notifications';
            $summary_table = $wpdb->prefix . 'bme_listing_summary';

            // Check if open houses table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$open_houses_table}'") !== $open_houses_table) {
                $results['errors'][] = 'Open houses table does not exist';
                return $results;
            }

            // Create notifications tracking table if needed
            self::maybe_create_notifications_table();

            // Get last detection timestamp
            $last_detect = get_option(self::LAST_DETECT_OPTION, date('Y-m-d H:i:s', strtotime('-1 day')));

            // Find open houses that:
            // 1. Are on favorited properties
            // 2. Haven't been notified yet
            // 3. Are in the future
            $sql = $wpdb->prepare("
                SELECT DISTINCT
                    oh.id as open_house_id,
                    oh.listing_id,
                    oh.open_house_data,
                    oh.expires_at,
                    s.listing_key,
                    s.street_number,
                    s.street_name,
                    s.city,
                    s.state_or_province,
                    s.list_price,
                    s.main_photo_url,
                    p.user_id
                FROM {$open_houses_table} oh
                INNER JOIN {$preferences_table} p ON oh.listing_id = p.listing_id
                INNER JOIN {$summary_table} s ON oh.listing_id = s.listing_id
                WHERE p.preference_type = 'liked'
                AND oh.expires_at > NOW()
                AND NOT EXISTS (
                    SELECT 1 FROM {$notifications_table} n
                    WHERE n.open_house_id = oh.id
                    AND n.user_id = p.user_id
                    AND n.notification_type = 'new'
                )
                ORDER BY oh.expires_at ASC
                LIMIT 200
            ", []);

            $open_houses = $wpdb->get_results($sql);

            if (empty($open_houses)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Open House Notifier: No new open houses to notify');
                }
                update_option(self::LAST_DETECT_OPTION, current_time('mysql', true));
                $results['execution_time'] = round(microtime(true) - $start_time, 2);
                return $results;
            }

            $results['new_open_houses'] = count($open_houses);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Open House Notifier: Found {$results['new_open_houses']} new open houses to notify");
            }

            // Group by user to send consolidated notifications
            $by_user = self::group_by_user($open_houses);

            // Send notifications to each user
            foreach ($by_user as $user_id => $user_open_houses) {
                $user_result = self::notify_user_new_open_houses($user_id, $user_open_houses);

                if ($user_result['push_sent']) {
                    $results['push_sent']++;
                }
                if ($user_result['email_sent']) {
                    $results['email_sent']++;
                }
                if ($user_result['push_sent'] || $user_result['email_sent']) {
                    $results['users_notified']++;
                }

                // Record notifications sent
                foreach ($user_open_houses as $oh) {
                    self::record_notification($oh->open_house_id, $user_id, 'new');
                }
            }

            // Update last detection timestamp
            update_option(self::LAST_DETECT_OPTION, current_time('mysql', true));

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Open House Notifier Error: ' . $e->getMessage());
            }
        }

        $results['execution_time'] = round(microtime(true) - $start_time, 2);
        $results['last_run'] = current_time('mysql');
        update_option('mld_open_house_notifier_detect_results', $results);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Open House Notifier Detection: New=%d, Users=%d, Push=%d, Email=%d',
                $results['new_open_houses'],
                $results['users_notified'],
                $results['push_sent'],
                $results['email_sent']
            ));
        }

        return $results;
    }

    /**
     * Send day-of reminders for open houses happening today
     *
     * @return array Results of the reminder process
     */
    public static function send_day_of_reminders() {
        global $wpdb;

        $start_time = microtime(true);
        $results = [
            'open_houses_today' => 0,
            'users_reminded' => 0,
            'push_sent' => 0,
            'errors' => []
        ];

        try {
            $open_houses_table = $wpdb->prefix . 'bme_open_houses';
            $preferences_table = $wpdb->prefix . 'mld_property_preferences';
            $notifications_table = $wpdb->prefix . 'mld_open_house_notifications';
            $summary_table = $wpdb->prefix . 'bme_listing_summary';

            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$open_houses_table}'") !== $open_houses_table) {
                $results['errors'][] = 'Open houses table does not exist';
                return $results;
            }

            // Find open houses happening today that user hasn't been reminded about
            $today = current_time('Y-m-d');
            $sql = $wpdb->prepare("
                SELECT DISTINCT
                    oh.id as open_house_id,
                    oh.listing_id,
                    oh.open_house_data,
                    oh.expires_at,
                    s.listing_key,
                    s.street_number,
                    s.street_name,
                    s.city,
                    s.state_or_province,
                    s.list_price,
                    s.main_photo_url,
                    p.user_id
                FROM {$open_houses_table} oh
                INNER JOIN {$preferences_table} p ON oh.listing_id = p.listing_id
                INNER JOIN {$summary_table} s ON oh.listing_id = s.listing_id
                WHERE p.preference_type = 'liked'
                AND DATE(oh.expires_at) = %s
                AND oh.expires_at > NOW()
                AND NOT EXISTS (
                    SELECT 1 FROM {$notifications_table} n
                    WHERE n.open_house_id = oh.id
                    AND n.user_id = p.user_id
                    AND n.notification_type = 'reminder'
                )
                ORDER BY oh.expires_at ASC
                LIMIT 200
            ", $today);

            $open_houses = $wpdb->get_results($sql);

            if (empty($open_houses)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Open House Notifier: No open house reminders to send today');
                }
                $results['execution_time'] = round(microtime(true) - $start_time, 2);
                return $results;
            }

            $results['open_houses_today'] = count($open_houses);

            // Group by user
            $by_user = self::group_by_user($open_houses);

            // Send reminders to each user
            foreach ($by_user as $user_id => $user_open_houses) {
                $push_result = self::send_reminder_push($user_id, $user_open_houses);

                if ($push_result['success']) {
                    $results['push_sent']++;
                    $results['users_reminded']++;
                }

                // Record reminders sent
                foreach ($user_open_houses as $oh) {
                    self::record_notification($oh->open_house_id, $user_id, 'reminder');
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Open House Notifier Reminder Error: ' . $e->getMessage());
            }
        }

        $results['execution_time'] = round(microtime(true) - $start_time, 2);
        $results['last_run'] = current_time('mysql');
        update_option('mld_open_house_notifier_remind_results', $results);

        return $results;
    }

    /**
     * Group open houses by user
     *
     * @param array $open_houses Array of open house records
     * @return array Open houses grouped by user_id
     */
    private static function group_by_user($open_houses) {
        $grouped = [];
        foreach ($open_houses as $oh) {
            $user_id = $oh->user_id;
            if (!isset($grouped[$user_id])) {
                $grouped[$user_id] = [];
            }
            $grouped[$user_id][] = $oh;
        }
        return $grouped;
    }

    /**
     * Notify user about new open houses on their favorited properties
     *
     * @param int $user_id WordPress user ID
     * @param array $open_houses User's open houses
     * @return array Result with 'push_sent', 'email_sent'
     */
    private static function notify_user_new_open_houses($user_id, $open_houses) {
        $result = ['push_sent' => false, 'email_sent' => false, 'errors' => []];

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $result;
        }

        // Send push notification for first open house (most relevant)
        $first_oh = $open_houses[0];
        $push_result = self::send_new_open_house_push($user_id, $first_oh, count($open_houses));
        $result['push_sent'] = $push_result['success'];

        // Send email with all open houses
        $email_result = self::send_open_house_email($user, $open_houses, 'new');
        $result['email_sent'] = $email_result['success'];

        return $result;
    }

    /**
     * Send push notification for new open house
     *
     * @param int $user_id User ID
     * @param object $open_house First open house
     * @param int $total_count Total number of new open houses
     * @return array Result with 'success', 'errors'
     */
    private static function send_new_open_house_push($user_id, $open_house, $total_count) {
        $result = ['success' => false, 'errors' => []];

        if (!class_exists('MLD_Push_Notifications')) {
            $result['errors'][] = 'Push notifications not available';
            return $result;
        }

        $address = self::format_address($open_house);
        $time_info = self::parse_open_house_time($open_house);

        if ($total_count === 1) {
            $title = 'Open House Alert';
            $body = "{$address} - {$time_info['display']}";
        } else {
            $title = 'Open House Alerts';
            $body = "{$total_count} of your saved properties have open houses";
        }

        $context = [
            'notification_type' => 'open_house',
            'listing_id' => $open_house->listing_id,
            'listing_key' => $open_house->listing_key,
            'open_house_date' => $time_info['date'],
            'open_house_time' => $time_info['time_range']
        ];

        $push_result = MLD_Push_Notifications::send_activity_notification(
            $user_id,
            $title,
            $body,
            'open_house',
            $context
        );

        // Log analytics (v6.48.0)
        if (class_exists('MLD_Notification_Analytics')) {
            $analytics_id = MLD_Notification_Analytics::log_send(
                $user_id,
                'open_house',
                MLD_Notification_Analytics::CHANNEL_PUSH,
                $open_house->listing_id
            );
            if ($push_result['success'] && $analytics_id) {
                MLD_Notification_Analytics::mark_delivered($analytics_id);
            } elseif ($analytics_id) {
                MLD_Notification_Analytics::mark_failed($analytics_id, implode(', ', $push_result['errors'] ?? []));
            }
        }

        $result['success'] = $push_result['success'];
        $result['errors'] = $push_result['errors'] ?? [];

        return $result;
    }

    /**
     * Send day-of reminder push notification
     *
     * @param int $user_id User ID
     * @param array $open_houses Today's open houses
     * @return array Result with 'success'
     */
    private static function send_reminder_push($user_id, $open_houses) {
        $result = ['success' => false, 'errors' => []];

        if (!class_exists('MLD_Push_Notifications')) {
            return $result;
        }

        $first_oh = $open_houses[0];
        $address = self::format_address($first_oh);
        $time_info = self::parse_open_house_time($first_oh);

        if (count($open_houses) === 1) {
            $title = 'Open House Today';
            $body = "{$address} - {$time_info['time_range']}";
        } else {
            $title = 'Open Houses Today';
            $body = count($open_houses) . " of your saved properties have open houses today";
        }

        $context = [
            'notification_type' => 'open_house_reminder',
            'listing_id' => $first_oh->listing_id,
            'listing_key' => $first_oh->listing_key,
            'open_house_date' => $time_info['date'],
            'open_house_time' => $time_info['time_range']
        ];

        $push_result = MLD_Push_Notifications::send_activity_notification(
            $user_id,
            $title,
            $body,
            'open_house',
            $context
        );

        // Log analytics (v6.48.0)
        if (class_exists('MLD_Notification_Analytics')) {
            foreach ($open_houses as $oh) {
                $analytics_id = MLD_Notification_Analytics::log_send(
                    $user_id,
                    'open_house',
                    MLD_Notification_Analytics::CHANNEL_PUSH,
                    $oh->listing_id,
                    ['reminder' => true]
                );
                if ($push_result['success'] && $analytics_id) {
                    MLD_Notification_Analytics::mark_delivered($analytics_id);
                } elseif ($analytics_id) {
                    MLD_Notification_Analytics::mark_failed($analytics_id);
                }
            }
        }

        $result['success'] = $push_result['success'];
        return $result;
    }

    /**
     * Send email notification about open houses
     *
     * @param WP_User $user User object
     * @param array $open_houses Open houses
     * @param string $type 'new' or 'reminder'
     * @return array Result with 'success'
     */
    private static function send_open_house_email($user, $open_houses, $type = 'new') {
        $result = ['success' => false, 'error' => null];

        $count = count($open_houses);
        if ($type === 'reminder') {
            $subject = $count === 1
                ? 'Reminder: Open House Today at ' . self::format_address($open_houses[0])
                : "Reminder: {$count} Open Houses Today";
        } else {
            $subject = $count === 1
                ? 'Open House Alert: ' . self::format_address($open_houses[0])
                : "Open House Alert: {$count} of Your Saved Properties";
        }

        $html = self::build_open_house_email_html($user, $open_houses, $type);

        // Use dynamic from address based on user's assigned agent
        $headers = [];
        if (class_exists('MLD_Email_Utilities')) {
            $headers = MLD_Email_Utilities::get_email_headers($user->ID);
        } else {
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: BMN Boston <notifications@bmnboston.com>'
            ];
        }

        $sent = wp_mail($user->user_email, $subject, $html, $headers);

        // Log email analytics (v6.48.0)
        if (class_exists('MLD_Notification_Analytics')) {
            foreach ($open_houses as $oh) {
                $analytics_id = MLD_Notification_Analytics::log_send(
                    $user->ID,
                    'open_house',
                    MLD_Notification_Analytics::CHANNEL_EMAIL,
                    $oh->listing_id,
                    ['type' => $type]
                );
                if ($sent && $analytics_id) {
                    MLD_Notification_Analytics::mark_delivered($analytics_id);
                } elseif ($analytics_id) {
                    MLD_Notification_Analytics::mark_failed($analytics_id, 'Email delivery failed');
                }
            }
        }

        $result['success'] = $sent;
        if (!$sent) {
            $result['error'] = 'Failed to send email to ' . $user->user_email;
        }

        return $result;
    }

    /**
     * Build HTML email for open house notification
     *
     * @param WP_User $user User object
     * @param array $open_houses Open houses
     * @param string $type 'new' or 'reminder'
     * @return string HTML content
     */
    private static function build_open_house_email_html($user, $open_houses, $type) {
        $first_name = $user->first_name ?: $user->display_name;
        $site_url = home_url();
        $is_reminder = ($type === 'reminder');

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f5f5f5;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: <?php echo $is_reminder ? '#f59e0b' : '#059669'; ?>; padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">
                                <?php echo $is_reminder ? '🏠 Open House Today!' : '🏠 Open House Alert'; ?>
                            </h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            <p style="font-size: 16px; color: #333333; margin: 0 0 20px;">
                                Hi <?php echo esc_html($first_name); ?>,
                            </p>
                            <p style="font-size: 16px; color: #333333; margin: 0 0 25px;">
                                <?php if ($is_reminder): ?>
                                Don't miss <?php echo count($open_houses) === 1 ? 'this' : 'these'; ?> open house<?php echo count($open_houses) > 1 ? 's' : ''; ?> today on your saved properties:
                                <?php else: ?>
                                Great news! <?php echo count($open_houses) === 1 ? 'One of your saved properties has' : count($open_houses) . ' of your saved properties have'; ?> upcoming open house<?php echo count($open_houses) > 1 ? 's' : ''; ?>:
                                <?php endif; ?>
                            </p>

                            <!-- Open Houses List -->
                            <?php foreach ($open_houses as $oh):
                                $time_info = self::parse_open_house_time($oh);
                            ?>
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 15px; border-left: 4px solid <?php echo $is_reminder ? '#f59e0b' : '#059669'; ?>;">
                                <?php if (!empty($oh->main_photo_url)): ?>
                                <img src="<?php echo esc_url($oh->main_photo_url); ?>"
                                     alt="Property photo"
                                     style="width: 100%; height: 150px; object-fit: cover; border-radius: 4px; margin-bottom: 15px;">
                                <?php endif; ?>

                                <a href="<?php echo esc_url($site_url . '/property/' . $oh->listing_id . '/'); ?>"
                                   style="color: #0891b2; text-decoration: none; font-weight: 600; font-size: 17px;">
                                    <?php echo esc_html(self::format_address($oh)); ?>
                                </a>

                                <div style="margin-top: 10px;">
                                    <p style="margin: 0 0 5px; color: #374151; font-size: 15px;">
                                        <strong style="color: <?php echo $is_reminder ? '#f59e0b' : '#059669'; ?>;">
                                            📅 <?php echo esc_html($time_info['display']); ?>
                                        </strong>
                                    </p>
                                    <p style="margin: 0; color: #6b7280; font-size: 14px;">
                                        💰 $<?php echo number_format((int)$oh->list_price); ?>
                                    </p>
                                </div>

                                <a href="<?php echo esc_url($site_url . '/property/' . $oh->listing_id . '/'); ?>"
                                   style="display: inline-block; margin-top: 12px; padding: 10px 20px; background-color: #0891b2; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">
                                    View Property Details
                                </a>
                            </div>
                            <?php endforeach; ?>

                            <!-- CTA -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="<?php echo esc_url($site_url . '/my-dashboard/'); ?>"
                                   style="display: inline-block; background-color: #374151; color: #ffffff; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 16px;">
                                    View All Saved Properties
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer with unified content -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px 30px; border-top: 1px solid #e5e7eb;">
                            <?php if (class_exists('MLD_Email_Utilities')): ?>
                                <?php echo MLD_Email_Utilities::get_unified_footer([
                                    'context' => 'property_alert',
                                    'show_social' => true,
                                    'show_app_download' => true,
                                    'compact' => true,
                                ]); ?>
                            <?php else: ?>
                            <p style="margin: 0; font-size: 13px; color: #6b7280; text-align: center;">
                                You're receiving this because you saved these properties on BMN Boston.
                                <br>
                                <a href="<?php echo esc_url($site_url . '/my-dashboard/'); ?>" style="color: #0891b2;">
                                    Manage your preferences
                                </a>
                            </p>
                            <?php endif; ?>
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

    /**
     * Parse open house time from JSON data
     *
     * @param object $open_house Open house record
     * @return array With 'date', 'time_range', 'display'
     */
    private static function parse_open_house_time($open_house) {
        $data = json_decode($open_house->open_house_data, true);

        $start = $data['OpenHouseStartTime'] ?? $data['open_house_start_time'] ?? null;
        $end = $data['OpenHouseEndTime'] ?? $data['open_house_end_time'] ?? null;

        // Parse the datetime strings
        $start_dt = $start ? strtotime($start) : strtotime($open_house->expires_at);
        $end_dt = $end ? strtotime($end) : null;

        $date = date('l, F j', $start_dt); // "Sunday, January 12"
        $time_start = date('g:i A', $start_dt); // "1:00 PM"
        $time_end = $end_dt ? date('g:i A', $end_dt) : null;

        $time_range = $time_end ? "{$time_start} - {$time_end}" : $time_start;
        $display = "{$date} at {$time_range}";

        return [
            'date' => date('Y-m-d', $start_dt),
            'time_range' => $time_range,
            'display' => $display
        ];
    }

    /**
     * Format property address
     *
     * @param object $open_house Open house record with address fields
     * @return string Formatted address
     */
    private static function format_address($open_house) {
        $parts = [];
        if (!empty($open_house->street_number)) {
            $parts[] = $open_house->street_number;
        }
        if (!empty($open_house->street_name)) {
            $parts[] = $open_house->street_name;
        }
        $address = implode(' ', $parts);

        if (!empty($open_house->city)) {
            $address .= ', ' . $open_house->city;
        }

        return $address ?: 'Property';
    }

    /**
     * Record that a notification was sent
     *
     * @param int $open_house_id Open house ID
     * @param int $user_id User ID
     * @param string $type 'new' or 'reminder'
     */
    private static function record_notification($open_house_id, $user_id, $type) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_house_notifications';

        $wpdb->insert($table, [
            'open_house_id' => $open_house_id,
            'user_id' => $user_id,
            'notification_type' => $type,
            'sent_at' => current_time('mysql')
        ], ['%d', '%d', '%s', '%s']);
    }

    /**
     * Create notifications tracking table if it doesn't exist
     */
    public static function maybe_create_notifications_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_house_notifications';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            open_house_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            notification_type ENUM('new', 'reminder') NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_oh_user_type (open_house_id, user_id, notification_type),
            KEY idx_user (user_id),
            KEY idx_sent (sent_at)
        ) {$charset_collate}";

        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Open House Notifier: Created notifications tracking table');
        }
    }

    /**
     * Get notifier status for admin dashboard
     *
     * @return array Status information
     */
    public static function get_status() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_house_notifications';
        $detect_results = get_option('mld_open_house_notifier_detect_results', []);
        $remind_results = get_option('mld_open_house_notifier_remind_results', []);

        $status = [
            'detection_scheduled' => wp_next_scheduled(self::CRON_HOOK_DETECT) !== false,
            'detection_next_run' => wp_next_scheduled(self::CRON_HOOK_DETECT),
            'detection_last_run' => $detect_results['last_run'] ?? null,
            'reminder_scheduled' => wp_next_scheduled(self::CRON_HOOK_REMIND) !== false,
            'reminder_next_run' => wp_next_scheduled(self::CRON_HOOK_REMIND),
            'reminder_last_run' => $remind_results['last_run'] ?? null
        ];

        // Count notifications sent in last 24 hours
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $status['notifications_24h'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        }

        return $status;
    }

    /**
     * Cleanup old notification records (older than 30 days)
     */
    public static function cleanup_old_records() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_open_house_notifications';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        $deleted = $wpdb->query(
            "DELETE FROM {$table} WHERE sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("MLD Open House Notifier: Cleaned up {$deleted} old notification records");
        }

        return $deleted;
    }
}
