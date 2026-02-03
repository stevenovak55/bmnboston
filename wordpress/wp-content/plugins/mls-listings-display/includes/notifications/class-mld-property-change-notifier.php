<?php
/**
 * MLD Property Change Notifier
 *
 * Sends push and email notifications to users when their favorited properties
 * have price reductions or status changes.
 *
 * @package MLS_Listings_Display
 * @subpackage Notifications
 * @since 6.48.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Change_Notifier {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'mld_property_change_notifications';

    /**
     * Option key for tracking last notification run
     */
    const LAST_RUN_OPTION = 'mld_property_change_notifier_last_run';

    /**
     * Initialize the notifier
     */
    public static function init() {
        // Register cron action
        add_action(self::CRON_HOOK, [__CLASS__, 'send_notifications']);

        // Self-healing: ensure cron is scheduled
        add_action('admin_init', [__CLASS__, 'maybe_schedule_event'], 20);
    }

    /**
     * Schedule the cron event if not already scheduled
     */
    public static function maybe_schedule_event() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Run 5 minutes after the detector cron
            wp_schedule_event(time() + 300, 'mld_fifteen_minutes', self::CRON_HOOK);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Property Change Notifier: Scheduled cron event');
            }
        }
    }

    /**
     * Unschedule the cron event
     */
    public static function unschedule_event() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Main notification method - runs every 15 minutes (after detector)
     *
     * @return array Results of the notification process
     */
    public static function send_notifications() {
        $start_time = microtime(true);
        $results = [
            'push_sent' => 0,
            'email_sent' => 0,
            'users_notified' => 0,
            'errors' => []
        ];

        try {
            // Get pending notifications from detector
            if (!class_exists('MLD_Property_Change_Detector')) {
                require_once MLD_PLUGIN_DIR . 'includes/notifications/class-mld-property-change-detector.php';
            }

            $pending = MLD_Property_Change_Detector::get_pending_notifications(200);

            if (empty($pending)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Property Change Notifier: No pending notifications');
                }
                $results['execution_time'] = round(microtime(true) - $start_time, 2);
                update_option('mld_property_change_notifier_results', $results);
                return $results;
            }

            // Group notifications by user
            $notifications_by_user = self::group_notifications_by_user($pending);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'MLD Property Change Notifier: Found %d notifications for %d users',
                    count($pending),
                    count($notifications_by_user)
                ));
            }

            // Process each user's notifications
            $processed_change_ids = [];

            foreach ($notifications_by_user as $user_id => $user_notifications) {
                $user_result = self::notify_user($user_id, $user_notifications);

                if ($user_result['push_sent']) {
                    $results['push_sent']++;
                }
                if ($user_result['email_sent']) {
                    $results['email_sent']++;
                }
                if ($user_result['push_sent'] || $user_result['email_sent']) {
                    $results['users_notified']++;
                }

                if (!empty($user_result['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $user_result['errors']);
                }

                // Collect processed change IDs
                foreach ($user_notifications as $notification) {
                    $processed_change_ids[] = $notification->change_id;
                }
            }

            // Mark all processed changes as notified
            if (!empty($processed_change_ids)) {
                $unique_ids = array_unique($processed_change_ids);
                MLD_Property_Change_Detector::mark_as_notified($unique_ids);
            }

            // Update last run timestamp
            update_option(self::LAST_RUN_OPTION, current_time('mysql'));

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Property Change Notifier Error: ' . $e->getMessage());
            }
        }

        $results['execution_time'] = round(microtime(true) - $start_time, 2);
        $results['last_run'] = current_time('mysql');
        update_option('mld_property_change_notifier_results', $results);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'MLD Property Change Notifier: Completed - Users: %d, Push: %d, Email: %d',
                $results['users_notified'],
                $results['push_sent'],
                $results['email_sent']
            ));
        }

        return $results;
    }

    /**
     * Group notifications by user ID
     *
     * @param array $notifications Array of notification records
     * @return array Notifications grouped by user_id
     */
    private static function group_notifications_by_user($notifications) {
        $grouped = [];

        foreach ($notifications as $notification) {
            $user_id = $notification->user_id;
            if (!isset($grouped[$user_id])) {
                $grouped[$user_id] = [];
            }
            $grouped[$user_id][] = $notification;
        }

        return $grouped;
    }

    /**
     * Send notifications to a single user
     *
     * @param int $user_id WordPress user ID
     * @param array $notifications User's property change notifications
     * @return array Result with 'push_sent', 'email_sent', 'errors'
     */
    private static function notify_user($user_id, $notifications) {
        $result = [
            'push_sent' => false,
            'email_sent' => false,
            'errors' => []
        ];

        $user = get_user_by('id', $user_id);
        if (!$user) {
            $result['errors'][] = "User {$user_id} not found";
            return $result;
        }

        // Separate price reductions and status changes
        $price_reductions = [];
        $status_changes = [];

        foreach ($notifications as $notification) {
            if ($notification->change_type === 'price_reduction') {
                $price_reductions[] = $notification;
            } else {
                $status_changes[] = $notification;
            }
        }

        // Send push notifications
        $push_result = self::send_push_notifications($user_id, $price_reductions, $status_changes);
        $result['push_sent'] = $push_result['success'];
        if (!empty($push_result['errors'])) {
            $result['errors'] = array_merge($result['errors'], $push_result['errors']);
        }

        // Send email notification (summary)
        $email_result = self::send_email_notification($user, $price_reductions, $status_changes);
        $result['email_sent'] = $email_result['success'];
        if (!empty($email_result['error'])) {
            $result['errors'][] = $email_result['error'];
        }

        return $result;
    }

    /**
     * Send push notifications for property changes
     *
     * @param int $user_id WordPress user ID
     * @param array $price_reductions Price reduction notifications
     * @param array $status_changes Status change notifications
     * @return array Result with 'success', 'errors'
     */
    private static function send_push_notifications($user_id, $price_reductions, $status_changes) {
        $result = ['success' => false, 'errors' => []];

        if (!class_exists('MLD_Push_Notifications')) {
            $result['errors'][] = 'Push notifications not available';
            return $result;
        }

        // Check user push preferences (v6.50.7)
        // Filter out notifications based on per-type push preferences
        if (class_exists('MLD_Client_Notification_Preferences')) {
            // Filter price reductions if push disabled for price_change
            if (!MLD_Client_Notification_Preferences::is_push_enabled($user_id, 'price_change')) {
                $price_reductions = [];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Property Change Notifier: Skipping price change push for user {$user_id} - disabled in preferences");
                }
            }

            // Filter status changes if push disabled for status_change
            if (!MLD_Client_Notification_Preferences::is_push_enabled($user_id, 'status_change')) {
                $status_changes = [];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Property Change Notifier: Skipping status change push for user {$user_id} - disabled in preferences");
                }
            }

            // Check quiet hours for push notifications
            if (MLD_Client_Notification_Preferences::is_quiet_hours($user_id)) {
                // Queue for later delivery instead of sending now
                foreach ($price_reductions as $change) {
                    $payload = self::build_price_change_payload($user_id, $change);
                    MLD_Client_Notification_Preferences::queue_for_quiet_hours($user_id, 'price_change', $payload);
                }
                foreach ($status_changes as $change) {
                    $payload = self::build_status_change_payload($user_id, $change);
                    MLD_Client_Notification_Preferences::queue_for_quiet_hours($user_id, 'status_change', $payload);
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $total = count($price_reductions) + count($status_changes);
                    error_log("MLD Property Change Notifier: Queued {$total} notifications for user {$user_id} - quiet hours active");
                }
                $result['success'] = true; // Considered success since we queued them
                return $result;
            }
        }

        $sent_any = false;

        // Send individual push for each price reduction (more impactful)
        foreach ($price_reductions as $change) {
            $title = 'Price Drop Alert';
            $address = self::format_address($change);
            $reduction = self::format_price_reduction($change);
            $body = "{$address} - {$reduction}";

            $context = [
                'notification_type' => 'price_change',
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'price_previous' => (int) $change->value_previous,
                'price_current' => (int) $change->value_current,
                'percentage_change' => (float) $change->percentage_change
            ];

            $push_result = MLD_Push_Notifications::send_activity_notification(
                $user_id,
                $title,
                $body,
                'price_change',
                $context
            );

            // Log analytics (v6.48.0)
            if (class_exists('MLD_Notification_Analytics')) {
                $analytics_id = MLD_Notification_Analytics::log_send(
                    $user_id,
                    'price_change',
                    MLD_Notification_Analytics::CHANNEL_PUSH,
                    $change->listing_id
                );
                if ($push_result['success'] && $analytics_id) {
                    MLD_Notification_Analytics::mark_delivered($analytics_id);
                } elseif ($analytics_id) {
                    MLD_Notification_Analytics::mark_failed($analytics_id, implode(', ', $push_result['errors'] ?? []));
                }
            }

            if ($push_result['success']) {
                $sent_any = true;
            } else {
                $result['errors'] = array_merge($result['errors'], $push_result['errors']);
            }
        }

        // Send individual push for each status change
        foreach ($status_changes as $change) {
            $status = $change->value_current;
            $title = $status === 'Pending' ? 'Property Now Pending' : 'Property Sold';
            $address = self::format_address($change);
            $body = "{$address} is now {$status}";

            $context = [
                'notification_type' => 'status_change',
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'status_previous' => $change->value_previous,
                'status_current' => $change->value_current
            ];

            $push_result = MLD_Push_Notifications::send_activity_notification(
                $user_id,
                $title,
                $body,
                'status_change',
                $context
            );

            // Log analytics (v6.48.0)
            if (class_exists('MLD_Notification_Analytics')) {
                $analytics_id = MLD_Notification_Analytics::log_send(
                    $user_id,
                    'status_change',
                    MLD_Notification_Analytics::CHANNEL_PUSH,
                    $change->listing_id
                );
                if ($push_result['success'] && $analytics_id) {
                    MLD_Notification_Analytics::mark_delivered($analytics_id);
                } elseif ($analytics_id) {
                    MLD_Notification_Analytics::mark_failed($analytics_id, implode(', ', $push_result['errors'] ?? []));
                }
            }

            if ($push_result['success']) {
                $sent_any = true;
            } else {
                $result['errors'] = array_merge($result['errors'], $push_result['errors']);
            }
        }

        $result['success'] = $sent_any;
        return $result;
    }

    /**
     * Send email notification summarizing property changes
     *
     * @param WP_User $user WordPress user object
     * @param array $price_reductions Price reduction notifications
     * @param array $status_changes Status change notifications
     * @return array Result with 'success', 'error'
     */
    private static function send_email_notification($user, $price_reductions, $status_changes) {
        $result = ['success' => false, 'error' => null];

        // Check user email preferences (v6.50.7)
        // Filter out notifications based on per-type email preferences
        if (class_exists('MLD_Client_Notification_Preferences')) {
            // Filter price reductions if email disabled for price_change
            if (!MLD_Client_Notification_Preferences::is_email_enabled($user->ID, 'price_change')) {
                $price_reductions = [];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Property Change Notifier: Skipping price change email for user {$user->ID} - disabled in preferences");
                }
            }

            // Filter status changes if email disabled for status_change
            if (!MLD_Client_Notification_Preferences::is_email_enabled($user->ID, 'status_change')) {
                $status_changes = [];
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Property Change Notifier: Skipping status change email for user {$user->ID} - disabled in preferences");
                }
            }
        }

        $total_changes = count($price_reductions) + count($status_changes);
        if ($total_changes === 0) {
            return $result;
        }

        // Build subject
        if (count($price_reductions) > 0 && count($status_changes) > 0) {
            $subject = sprintf('Property Alert: %d Price Drop%s & %d Status Change%s',
                count($price_reductions),
                count($price_reductions) === 1 ? '' : 's',
                count($status_changes),
                count($status_changes) === 1 ? '' : 's'
            );
        } elseif (count($price_reductions) > 0) {
            $subject = count($price_reductions) === 1
                ? 'Price Drop Alert: ' . self::format_address($price_reductions[0])
                : sprintf('Price Drop Alert: %d of Your Saved Properties', count($price_reductions));
        } else {
            $subject = count($status_changes) === 1
                ? 'Status Update: ' . self::format_address($status_changes[0]) . ' is now ' . $status_changes[0]->value_current
                : sprintf('Status Update: %d of Your Saved Properties', count($status_changes));
        }

        // Build HTML email
        $html = self::build_email_html($user, $price_reductions, $status_changes);

        // Send email
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: BMN Boston <notifications@bmnboston.com>'
        ];

        $sent = wp_mail($user->user_email, $subject, $html, $headers);

        // Log email analytics (v6.48.0)
        if (class_exists('MLD_Notification_Analytics')) {
            // Log for each listing included in the email
            $all_changes = array_merge($price_reductions, $status_changes);
            foreach ($all_changes as $change) {
                $type = isset($change->percentage_change) ? 'price_change' : 'status_change';
                $analytics_id = MLD_Notification_Analytics::log_send(
                    $user->ID,
                    $type,
                    MLD_Notification_Analytics::CHANNEL_EMAIL,
                    $change->listing_id
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
     * Build HTML email content
     *
     * @param WP_User $user WordPress user object
     * @param array $price_reductions Price reduction notifications
     * @param array $status_changes Status change notifications
     * @return string HTML email content
     */
    private static function build_email_html($user, $price_reductions, $status_changes) {
        $first_name = $user->first_name ?: $user->display_name;
        $site_url = home_url();

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
                        <td style="background-color: #0891b2; padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Property Updates</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            <p style="font-size: 16px; color: #333333; margin: 0 0 20px;">
                                Hi <?php echo esc_html($first_name); ?>,
                            </p>
                            <p style="font-size: 16px; color: #333333; margin: 0 0 25px;">
                                There are updates on properties you've saved:
                            </p>

                            <?php if (!empty($price_reductions)): ?>
                            <!-- Price Reductions -->
                            <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin-bottom: 20px; border-radius: 0 4px 4px 0;">
                                <h2 style="color: #dc2626; margin: 0 0 15px; font-size: 18px;">
                                    💰 Price Reductions (<?php echo count($price_reductions); ?>)
                                </h2>
                                <?php foreach ($price_reductions as $change): ?>
                                <div style="background-color: #ffffff; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                    <a href="<?php echo esc_url($site_url . '/property/' . $change->listing_id . '/'); ?>"
                                       style="color: #0891b2; text-decoration: none; font-weight: 600; font-size: 15px;">
                                        <?php echo esc_html(self::format_address($change)); ?>
                                    </a>
                                    <p style="margin: 8px 0 0; color: #666666; font-size: 14px;">
                                        <span style="text-decoration: line-through; color: #999999;">
                                            $<?php echo number_format((int)$change->value_previous); ?>
                                        </span>
                                        &nbsp;→&nbsp;
                                        <span style="color: #dc2626; font-weight: 600;">
                                            $<?php echo number_format((int)$change->value_current); ?>
                                        </span>
                                        <span style="color: #059669; font-size: 13px; margin-left: 8px;">
                                            (-<?php echo number_format($change->percentage_change, 1); ?>%)
                                        </span>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($status_changes)): ?>
                            <!-- Status Changes -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; margin-bottom: 20px; border-radius: 0 4px 4px 0;">
                                <h2 style="color: #1d4ed8; margin: 0 0 15px; font-size: 18px;">
                                    🏠 Status Changes (<?php echo count($status_changes); ?>)
                                </h2>
                                <?php foreach ($status_changes as $change): ?>
                                <div style="background-color: #ffffff; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                    <a href="<?php echo esc_url($site_url . '/property/' . $change->listing_id . '/'); ?>"
                                       style="color: #0891b2; text-decoration: none; font-weight: 600; font-size: 15px;">
                                        <?php echo esc_html(self::format_address($change)); ?>
                                    </a>
                                    <p style="margin: 8px 0 0; color: #666666; font-size: 14px;">
                                        Status:
                                        <span style="color: <?php echo $change->value_current === 'Pending' ? '#d97706' : '#059669'; ?>; font-weight: 600;">
                                            <?php echo esc_html($change->value_current); ?>
                                        </span>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <!-- CTA Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="<?php echo esc_url($site_url . '/my-dashboard/'); ?>"
                                   style="display: inline-block; background-color: #0891b2; color: #ffffff; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 16px;">
                                    View All Saved Properties
                                </a>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px 30px; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; font-size: 13px; color: #6b7280; text-align: center;">
                                You're receiving this because you saved these properties on BMN Boston.
                                <br>
                                <a href="<?php echo esc_url($site_url . '/my-dashboard/'); ?>" style="color: #0891b2;">
                                    Manage your preferences
                                </a>
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

    /**
     * Format property address from change record
     *
     * @param object $change Change record with address fields
     * @return string Formatted address
     */
    private static function format_address($change) {
        $parts = [];

        if (!empty($change->street_number)) {
            $parts[] = $change->street_number;
        }
        if (!empty($change->street_name)) {
            $parts[] = $change->street_name;
        }

        $address = implode(' ', $parts);

        if (!empty($change->city)) {
            $address .= ', ' . $change->city;
        }

        return $address ?: 'Property #' . $change->listing_id;
    }

    /**
     * Format price reduction for display
     *
     * @param object $change Price reduction record
     * @return string Formatted price reduction text
     */
    private static function format_price_reduction($change) {
        $previous = (int) $change->value_previous;
        $current = (int) $change->value_current;
        $reduction = $previous - $current;

        // Format reduction amount
        if ($reduction >= 1000000) {
            $reduction_text = '$' . number_format($reduction / 1000000, 1) . 'M off';
        } elseif ($reduction >= 1000) {
            $reduction_text = '$' . number_format($reduction / 1000) . 'K off';
        } else {
            $reduction_text = '$' . number_format($reduction) . ' off';
        }

        // Add percentage
        $percentage = round($change->percentage_change, 1);
        $reduction_text .= " (-{$percentage}%)";

        return $reduction_text;
    }

    /**
     * Get notifier status for admin dashboard
     *
     * @return array Status information
     */
    public static function get_status() {
        $results = get_option('mld_property_change_notifier_results', []);

        return [
            'scheduled' => wp_next_scheduled(self::CRON_HOOK) !== false,
            'next_run' => wp_next_scheduled(self::CRON_HOOK),
            'last_run' => $results['last_run'] ?? null,
            'last_execution_time' => $results['execution_time'] ?? null,
            'last_push_sent' => $results['push_sent'] ?? 0,
            'last_email_sent' => $results['email_sent'] ?? 0,
            'last_users_notified' => $results['users_notified'] ?? 0
        ];
    }

    /**
     * Manually trigger notifications (for testing/admin)
     *
     * @return array Results from send_notifications()
     */
    public static function trigger_manual() {
        return self::send_notifications();
    }

    /**
     * Build payload for price change notification (for deferred delivery)
     *
     * @param int $user_id WordPress user ID
     * @param object $change Price change record
     * @return array Payload for push notification
     * @since 6.50.7
     */
    private static function build_price_change_payload($user_id, $change) {
        return array(
            'user_id' => $user_id,
            'title' => 'Price Drop Alert',
            'body' => self::format_address($change) . ' - ' . self::format_price_reduction($change),
            'notification_type' => 'price_change',
            'listing_id' => $change->listing_id,
            'listing_key' => $change->listing_key,
            'context' => array(
                'notification_type' => 'price_change',
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'price_previous' => (int) $change->value_previous,
                'price_current' => (int) $change->value_current,
                'percentage_change' => (float) $change->percentage_change
            )
        );
    }

    /**
     * Build payload for status change notification (for deferred delivery)
     *
     * @param int $user_id WordPress user ID
     * @param object $change Status change record
     * @return array Payload for push notification
     * @since 6.50.7
     */
    private static function build_status_change_payload($user_id, $change) {
        $status = $change->value_current;
        $title = $status === 'Pending' ? 'Property Now Pending' : 'Property Sold';
        $address = self::format_address($change);

        return array(
            'user_id' => $user_id,
            'title' => $title,
            'body' => "{$address} is now {$status}",
            'notification_type' => 'status_change',
            'listing_id' => $change->listing_id,
            'listing_key' => $change->listing_key,
            'context' => array(
                'notification_type' => 'status_change',
                'listing_id' => $change->listing_id,
                'listing_key' => $change->listing_key,
                'status_previous' => $change->value_previous,
                'status_current' => $change->value_current
            )
        );
    }
}
