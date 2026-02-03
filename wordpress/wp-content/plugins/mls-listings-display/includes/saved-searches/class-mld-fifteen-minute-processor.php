<?php
/**
 * MLS Listings Display - Unified Alert Processor
 *
 * Main orchestrator for saved search email alerts for ALL frequencies.
 * Coordinates change detection, filter matching, and email sending.
 * Detects new listings, price changes, and status changes.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.13.0
 * @updated 6.13.2 - Unified to handle all frequencies (instant, fifteen_min, hourly, daily, weekly)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Fifteen_Minute_Processor {

    /**
     * Maximum listings per email
     */
    const MAX_LISTINGS_PER_EMAIL = 25;

    /**
     * Maximum execution time in seconds (leave buffer for cron)
     */
    const MAX_EXECUTION_TIME = 110;

    /**
     * Time windows for each frequency (in minutes)
     */
    const TIME_WINDOWS = [
        'instant' => 5,
        'fifteen_min' => 15,
        'hourly' => 60,
        'daily' => 1440,    // 24 hours
        'weekly' => 10080   // 7 days
    ];

    /**
     * Process notifications for a given frequency
     *
     * Main entry point called by cron job.
     *
     * @param string|array $frequency Frequency or array of frequencies to process
     * @return array Processing results
     */
    public static function process($frequency = null) {
        // Default to instant/fifteen_min for backwards compatibility
        if ($frequency === null) {
            $frequency = ['instant', 'fifteen_min'];
        }

        // Normalize to array
        $frequencies = is_array($frequency) ? $frequency : [$frequency];

        // Determine time window (use largest from frequencies)
        $time_window = 15;
        foreach ($frequencies as $freq) {
            if (isset(self::TIME_WINDOWS[$freq])) {
                $time_window = max($time_window, self::TIME_WINDOWS[$freq]);
            }
        }

        $start_time = microtime(true);

        $results = [
            'sent' => 0,
            'failed' => 0,
            'searches_processed' => 0,
            'changes_detected' => 0,
            'frequency' => implode(',', $frequencies),
            'errors' => []
        ];

        try {
            // Load dependencies
            self::load_dependencies();

            // Step 1: Detect recent changes within the time window
            $change_detector = new MLD_Change_Detector();
            $changes = $change_detector->get_recent_changes($time_window);
            $results['changes_detected'] = count($changes);

            if (empty($changes)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Unified Processor: No changes detected in last {$time_window} minutes for " . implode(',', $frequencies));
                }
                return $results;
            }

            // Step 2: Get active saved searches for the specified frequencies
            $searches = self::get_active_searches($frequencies);
            $results['searches_processed'] = count($searches);

            if (empty($searches)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Unified Processor: No active searches for frequencies: ' . implode(',', $frequencies));
                }
                return $results;
            }

            // Step 3: Process each search
            foreach ($searches as $search) {
                // Check execution time
                if ((microtime(true) - $start_time) > self::MAX_EXECUTION_TIME) {
                    $results['errors'][] = 'Execution time limit reached';
                    break;
                }

                $search_result = self::process_search($search, $changes);
                $results['sent'] += $search_result['sent'];
                $results['failed'] += $search_result['failed'];

                if (!empty($search_result['error'])) {
                    $results['errors'][] = $search_result['error'];
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Unified Processor Exception: ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Process instant notifications (every 5 minutes)
     * @return array
     */
    public static function process_instant() {
        return self::process('instant');
    }

    /**
     * Process 15-minute notifications
     * @return array
     */
    public static function process_fifteen_min() {
        return self::process('fifteen_min');
    }

    /**
     * Process hourly notifications
     * @return array
     */
    public static function process_hourly() {
        return self::process('hourly');
    }

    /**
     * Process daily notifications
     * @return array
     */
    public static function process_daily() {
        return self::process('daily');
    }

    /**
     * Process weekly notifications
     * @return array
     */
    public static function process_weekly() {
        return self::process('weekly');
    }

    /**
     * Load required dependencies
     */
    private static function load_dependencies() {
        $base_path = dirname(__FILE__);

        if (!class_exists('MLD_Change_Detector')) {
            require_once $base_path . '/class-mld-change-detector.php';
        }

        if (!class_exists('MLD_Enhanced_Filter_Matcher')) {
            require_once $base_path . '/class-mld-enhanced-filter-matcher.php';
        }

        if (!class_exists('MLD_Alert_Email_Builder')) {
            require_once $base_path . '/class-mld-alert-email-builder.php';
        }

        if (!class_exists('MLD_Notification_Tracker')) {
            $tracker_path = dirname($base_path) . '/notifications/class-mld-notification-tracker.php';
            if (file_exists($tracker_path)) {
                require_once $tracker_path;
            }
        }

        // v6.67.4: Load BMN Schools Integration for school filter support in saved searches
        // v6.68.7: Added debug logging to diagnose school filter bypass issue
        if (!class_exists('MLD_BMN_Schools_Integration')) {
            $schools_path = dirname($base_path) . '/class-mld-bmn-schools-integration.php';
            error_log('[MLD 15min Processor] Attempting to load BMN Schools from: ' . $schools_path);
            if (file_exists($schools_path)) {
                require_once $schools_path;
                error_log('[MLD 15min Processor] BMN Schools Integration file loaded, class_exists=' . (class_exists('MLD_BMN_Schools_Integration') ? 'TRUE' : 'FALSE'));
            } else {
                error_log('[MLD 15min Processor] BMN Schools file NOT FOUND at: ' . $schools_path);
            }
        } else {
            error_log('[MLD 15min Processor] BMN Schools Integration already loaded');
        }

        // Load push notifications class for sending push alerts alongside emails
        if (!class_exists('MLD_Push_Notifications')) {
            $push_path = dirname($base_path) . '/notifications/class-mld-push-notifications.php';
            if (file_exists($push_path)) {
                require_once $push_path;
            }
        }
    }

    /**
     * Get active saved searches for specified frequencies
     *
     * @param array $frequencies Notification frequencies to include
     * @return array Active saved searches
     */
    private static function get_active_searches($frequencies) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($frequencies), '%s'));

        $sql = $wpdb->prepare(
            "SELECT s.*, u.user_email, u.display_name
             FROM {$wpdb->prefix}mld_saved_searches s
             JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.is_active = 1
               AND s.notification_frequency IN ($placeholders)
             ORDER BY s.id ASC",
            ...$frequencies
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Process a single saved search against detected changes
     *
     * @param array $search Saved search data
     * @param array $changes Detected property changes
     * @return array Processing result
     */
    private static function process_search($search, $changes) {
        $result = ['sent' => 0, 'failed' => 0, 'error' => null];

        try {
            // Parse search filters
            $filters = json_decode($search['filters'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['error'] = "Invalid filters JSON for search #{$search['id']}";
                return $result;
            }

            // Add polygon shapes if present
            if (!empty($search['polygon_shapes'])) {
                $polygons = json_decode($search['polygon_shapes'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $filters['polygon_shapes'] = $polygons;
                }
            }

            // Match changes against filters
            // Note: Pass the full $search array (with 'filters' and 'polygon_shapes' keys)
            // because MLD_Enhanced_Filter_Matcher::matches() expects that structure
            $matching_changes = [];

            // v6.68.15: Get search creation timestamp for filtering
            // Only notify about changes detected AFTER the search was created
            $search_created_at = !empty($search['created_at']) ? strtotime($search['created_at']) : 0;

            foreach ($changes as $listing_id => $change_data) {
                // v6.68.15: Skip changes detected before this search was created
                // This prevents new searches from receiving alerts for old properties
                if ($search_created_at > 0 && !empty($change_data['change_detected_at'])) {
                    $change_time = strtotime($change_data['change_detected_at']);
                    if ($change_time < $search_created_at) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf(
                                'MLD 15min Processor: Skipping listing %s for search #%d - change detected at %s, search created at %s',
                                $listing_id,
                                $search['id'],
                                $change_data['change_detected_at'],
                                $search['created_at']
                            ));
                        }
                        continue;
                    }
                }

                $listing = $change_data['listing_data'];

                if (MLD_Enhanced_Filter_Matcher::matches($listing, $search)) {
                    // Check if user has disliked this property
                    if (!empty($search['exclude_disliked']) && self::is_property_disliked($search['user_id'], $listing_id)) {
                        continue;
                    }

                    $matching_changes[$listing_id] = $change_data;
                }
            }

            if (empty($matching_changes)) {
                return $result;
            }

            // Filter out already-notified listings for this change type
            $matching_changes = self::filter_already_notified($search['id'], $search['user_id'], $matching_changes);

            if (empty($matching_changes)) {
                return $result;
            }

            // Apply 25-listing limit (take newest first)
            $total_matches = count($matching_changes);
            if ($total_matches > self::MAX_LISTINGS_PER_EMAIL) {
                // Sort by change time descending
                uasort($matching_changes, function($a, $b) {
                    return strtotime($b['change_time']) - strtotime($a['change_time']);
                });
                $matching_changes = array_slice($matching_changes, 0, self::MAX_LISTINGS_PER_EMAIL, true);
            }

            // Build and send email
            $email_sent = self::send_alert_email($search, $matching_changes, $total_matches);

            if ($email_sent) {
                $result['sent'] = 1;

                // Record notifications
                self::record_notifications($search['id'], $search['user_id'], $matching_changes);

                // Update last_notified_at
                self::update_search_last_notified($search['id'], count($matching_changes));

                // Send push notification alongside email
                self::send_push_notification(
                    $search['user_id'],
                    count($matching_changes),
                    $search['name'],
                    $search['id'],
                    $matching_changes
                );
            } else {
                $result['failed'] = 1;
                $result['error'] = "Failed to send email for search #{$search['id']}";
            }

        } catch (Exception $e) {
            $result['failed'] = 1;
            $result['error'] = "Search #{$search['id']}: " . $e->getMessage();
        }

        return $result;
    }

    /**
     * Check if property is disliked by user
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return bool
     */
    private static function is_property_disliked($user_id, $listing_id) {
        global $wpdb;

        $disliked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mld_property_preferences
             WHERE user_id = %d AND listing_id = %s AND preference_type = 'disliked'",
            $user_id,
            $listing_id
        ));

        return $disliked > 0;
    }

    /**
     * Filter out listings already notified for this change type
     *
     * @param int $search_id Saved search ID
     * @param int $user_id User ID
     * @param array $changes Changes to filter
     * @return array Filtered changes
     */
    private static function filter_already_notified($search_id, $user_id, $changes) {
        global $wpdb;

        if (empty($changes)) {
            return $changes;
        }

        $filtered = [];

        foreach ($changes as $listing_id => $change_data) {
            $change_type = $change_data['change_type'];

            // Check if already notified for this specific change type
            // Use WordPress timezone-aware time instead of MySQL NOW()
            $wp_now = current_time('mysql');
            $already_notified = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_notification_tracker
                 WHERE user_id = %d
                   AND mls_number = %s
                   AND notification_type = %s
                   AND sent_at >= DATE_SUB(%s, INTERVAL 24 HOUR)",
                $user_id,
                $listing_id,
                $change_type,
                $wp_now
            ));

            if (!$already_notified) {
                $filtered[$listing_id] = $change_data;
            }
        }

        return $filtered;
    }

    /**
     * Send alert email for matching changes
     *
     * @param array $search Saved search data
     * @param array $changes Matching changes
     * @param int $total_matches Total matches before limit
     * @return bool Success
     */
    private static function send_alert_email($search, $changes, $total_matches) {
        // Group changes by type
        $grouped = [
            'new_listing' => [],
            'price_change' => [],
            'status_change' => []
        ];

        foreach ($changes as $listing_id => $change_data) {
            $type = $change_data['change_type'];
            if (isset($grouped[$type])) {
                $grouped[$type][$listing_id] = $change_data;
            } else {
                $grouped['new_listing'][$listing_id] = $change_data;
            }
        }

        // Build email using alert builder if available
        if (class_exists('MLD_Alert_Email_Builder')) {
            return MLD_Alert_Email_Builder::send($search, $grouped, $total_matches);
        }

        // Fallback to simple email
        return self::send_simple_alert_email($search, $changes, $total_matches);
    }

    /**
     * Simple fallback email sender
     *
     * @param array $search Saved search data
     * @param array $changes Matching changes
     * @param int $total_matches Total matches
     * @return bool Success
     */
    private static function send_simple_alert_email($search, $changes, $total_matches) {
        $to = $search['user_email'];
        $search_name = $search['name'];
        $count = count($changes);

        // Build subject
        $subject = sprintf(
            'Property Alert: %d new match%s - "%s"',
            $count,
            $count === 1 ? '' : 'es',
            $search_name
        );

        if ($total_matches > $count) {
            $subject = sprintf(
                'Property Alert: Showing %d of %d matches - "%s"',
                $count,
                $total_matches,
                $search_name
            );
        }

        // Build body
        $body = '<h2>Property Alert for "' . esc_html($search_name) . '"</h2>' . "\n";
        $body .= '<p>We found ' . $count . ' new matching properties for your saved search.</p>' . "\n";

        if ($total_matches > $count) {
            $body .= '<p><strong>Showing ' . $count . ' of ' . $total_matches . ' total matches.</strong></p>' . "\n";
        }

        $body .= "<hr>\n";

        foreach ($changes as $listing_id => $change_data) {
            $listing = $change_data['listing_data'];
            $change_type = $change_data['change_type'];

            // Format change badge
            $badge = '';
            switch ($change_type) {
                case 'new_listing':
                    $badge = '<span style="background:#28a745;color:white;padding:2px 8px;border-radius:3px;font-size:12px;">NEW</span>';
                    break;
                case 'price_change':
                    $old_price = isset($change_data['old_price']) ? $change_data['old_price'] : 0;
                    $new_price = isset($change_data['new_price']) ? $change_data['new_price'] : 0;
                    $direction = $new_price < $old_price ? 'REDUCED' : 'INCREASED';
                    $color = $new_price < $old_price ? '#dc3545' : '#ffc107';
                    $badge = '<span style="background:' . $color . ';color:white;padding:2px 8px;border-radius:3px;font-size:12px;">PRICE ' . $direction . '</span>';
                    break;
                case 'status_change':
                    $badge = '<span style="background:#17a2b8;color:white;padding:2px 8px;border-radius:3px;font-size:12px;">STATUS CHANGE</span>';
                    break;
            }

            // Build listing card
            $address = isset($listing['full_address']) ? $listing['full_address'] : (isset($listing['street_address']) ? $listing['street_address'] : 'Address unavailable');
            $city = isset($listing['city']) ? $listing['city'] : '';
            $price = isset($listing['list_price']) ? '$' . number_format($listing['list_price']) : 'Price unavailable';
            $beds = isset($listing['bedrooms_total']) ? $listing['bedrooms_total'] : '?';
            $baths = isset($listing['bathrooms_total']) ? $listing['bathrooms_total'] : '?';
            $sqft = isset($listing['building_area_total']) ? number_format($listing['building_area_total']) . ' sqft' : '';

            $listing_url = home_url('/property/' . $listing_id . '/');

            $body .= '<div style="margin-bottom:20px;padding:15px;border:1px solid #ddd;border-radius:5px;">' . "\n";
            $body .= '  ' . $badge . "\n";
            $body .= '  <h3 style="margin:10px 0 5px 0;"><a href="' . esc_url($listing_url) . '">' . esc_html($address) . '</a></h3>' . "\n";
            $body .= '  <p style="margin:5px 0;color:#666;">' . esc_html($city) . '</p>' . "\n";
            $body .= '  <p style="margin:5px 0;"><strong>' . $price . '</strong> | ' . $beds . ' beds | ' . $baths . ' baths';
            if ($sqft) {
                $body .= ' | ' . $sqft;
            }
            $body .= "</p>\n";

            // Show price change details
            if ($change_type === 'price_change' && isset($change_data['old_price']) && isset($change_data['new_price'])) {
                $old = '$' . number_format($change_data['old_price']);
                $new = '$' . number_format($change_data['new_price']);
                $diff = $change_data['new_price'] - $change_data['old_price'];
                $diff_formatted = ($diff > 0 ? '+' : '') . '$' . number_format($diff);
                $body .= '  <p style="margin:5px 0;font-size:14px;">Price changed: ' . $old . ' &rarr; ' . $new . ' (' . $diff_formatted . ')</p>' . "\n";
            }

            // Show status change details
            if ($change_type === 'status_change' && isset($change_data['old_status']) && isset($change_data['new_status'])) {
                $body .= '  <p style="margin:5px 0;font-size:14px;">Status changed: ' . esc_html($change_data['old_status']) . ' &rarr; ' . esc_html($change_data['new_status']) . '</p>' . "\n";
            }

            $body .= "</div>\n";
        }

        // Add view all link
        if (!empty($search['search_url'])) {
            $body .= '<p style="margin-top:20px;"><a href="' . esc_url($search['search_url']) . '" style="background:#007bff;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">View All Results</a></p>' . "\n";
        }

        // Add unsubscribe link
        $unsubscribe_url = home_url('/my-account/saved-searches/');
        $body .= "<hr>\n";
        $body .= '<p style="font-size:12px;color:#666;">You\'re receiving this email because you saved a search on our site. <a href="' . esc_url($unsubscribe_url) . '">Manage your saved searches</a></p>' . "\n";

        // Send email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );

        return wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Record notifications in tracker
     *
     * Uses REPLACE to handle unique constraint (user_id, mls_number, search_id).
     * This updates the notification_type and sent_at if the combination exists.
     *
     * @param int $search_id Saved search ID
     * @param int $user_id User ID
     * @param array $changes Changes that were notified
     */
    private static function record_notifications($search_id, $user_id, $changes) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_notification_tracker';
        $now = current_time('mysql');

        foreach ($changes as $listing_id => $change_data) {
            // Use REPLACE to update if exists or insert if new
            $wpdb->query($wpdb->prepare(
                "REPLACE INTO {$table}
                 (user_id, mls_number, notification_type, search_id, sent_at, email_sent)
                 VALUES (%d, %s, %s, %d, %s, %d)",
                $user_id,
                $listing_id,
                $change_data['change_type'],
                $search_id,
                $now,
                1
            ));
        }
    }

    /**
     * Update saved search last_notified_at timestamp
     *
     * @param int $search_id Saved search ID
     * @param int $match_count Number of matches
     */
    private static function update_search_last_notified($search_id, $match_count) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_saved_searches',
            array(
                'last_notified_at' => current_time('mysql'),
                'last_matched_count' => $match_count
            ),
            array('id' => $search_id),
            array('%s', '%d'),
            array('%d')
        );
    }

    /**
     * Send push notifications for matching properties
     *
     * Called after email is successfully sent. Sends one iOS push notification
     * per property, each with a direct deep link to the property detail page.
     *
     * @param int $user_id WordPress user ID
     * @param int $listing_count Number of matching listings
     * @param string $search_name Name of the saved search
     * @param int $search_id Saved search ID for deep linking
     * @param array $matching_changes Array of matching property changes
     * @since 6.48.0
     * @updated 6.48.1 - Now sends individual notification per property with direct deep link
     * @updated 6.49.15 - Always send individual notifications (no summary), cap at 25, respect user preferences
     */
    private static function send_push_notification($user_id, $listing_count, $search_name, $search_id, $matching_changes) {
        // Check if push notification class is available
        if (!class_exists('MLD_Push_Notifications')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Unified Processor: MLD_Push_Notifications class not found");
            }
            return;
        }

        // Check if APNs is configured
        if (!MLD_Push_Notifications::is_configured()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Unified Processor: APNs not configured, skipping push notification");
            }
            return;
        }

        // Check if user has any registered devices
        $device_count = MLD_Push_Notifications::get_user_device_count($user_id);
        if ($device_count === 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Unified Processor: No devices registered for user {$user_id}");
            }
            return;
        }

        // v6.49.15: Always send individual notifications for each property
        // No more summary notifications - users want to tap each notification to see the property
        // Cap at 25 notifications per batch to avoid overwhelming the user
        $max_notifications = 25;
        $sent_count = 0;
        $failed_count = 0;
        $skipped_by_preference = 0;
        $total_matches = count($matching_changes);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                "MLD Unified Processor: Processing %d property notifications for user %d, search '%s' (max: %d)",
                $total_matches,
                $user_id,
                $search_name,
                $max_notifications
            ));
        }

        foreach ($matching_changes as $listing_id => $change_data) {
            // Stop if we've hit the cap
            if ($sent_count >= $max_notifications) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        "MLD Unified Processor: Capped at %d notifications for user %d (total matches: %d)",
                        $max_notifications,
                        $user_id,
                        $total_matches
                    ));
                }
                break;
            }

            // Get notification type from change type
            $change_type = $change_data['change_type'] ?? 'new_listing';
            $notification_type = self::map_change_type_to_notification_type($change_type);

            // Check user preferences before sending (v6.49.15)
            if (class_exists('MLD_Client_Notification_Preferences')) {
                $should_send = MLD_Client_Notification_Preferences::should_send_now($user_id, $notification_type, 'push');
                if (!$should_send['send']) {
                    $skipped_by_preference++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            "MLD Unified Processor: Skipped %s notification for user %d - reason: %s",
                            $notification_type,
                            $user_id,
                            $should_send['reason']
                        ));
                    }
                    continue;
                }
            }

            // Build property data for notification
            $property = $change_data['listing_data'];
            $property['listing_id'] = $listing_id;
            $property['change_type'] = $change_data['change_type'];

            // Add price change details if applicable
            if ($change_data['change_type'] === 'price_change') {
                $property['old_price'] = $change_data['old_price'] ?? 0;
                $property['new_price'] = $change_data['new_price'] ?? 0;
            }

            // Add status change details if applicable
            if ($change_data['change_type'] === 'status_change') {
                $property['old_status'] = $change_data['old_status'] ?? '';
                $property['new_status'] = $change_data['new_status'] ?? '';
            }

            // Send push notification for this property
            $push_result = MLD_Push_Notifications::send_property_notification(
                $user_id,
                $property,
                $search_name,
                $search_id
            );

            if ($push_result['success']) {
                $sent_count++;
            } else {
                $failed_count++;
            }

            // Small delay between notifications to avoid rate limiting
            if ($total_matches > 1) {
                usleep(100000); // 100ms delay
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                "MLD Unified Processor: Push notifications for user %d, search '%s' - Total: %d, Sent: %d, Failed: %d, Skipped (prefs): %d",
                $user_id,
                $search_name,
                $total_matches,
                $sent_count,
                $failed_count,
                $skipped_by_preference
            ));
        }
    }

    /**
     * Map change type to notification preference type
     *
     * @param string $change_type The change type from property history
     * @return string The notification preference type
     * @since 6.49.15
     */
    private static function map_change_type_to_notification_type($change_type) {
        $mapping = array(
            'new_listing' => 'new_listing',
            'price_change' => 'price_change',
            'status_change' => 'status_change',
            'open_house' => 'open_house',
        );

        return isset($mapping[$change_type]) ? $mapping[$change_type] : 'new_listing';
    }
}
