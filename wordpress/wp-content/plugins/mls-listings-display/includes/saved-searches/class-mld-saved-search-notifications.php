<?php
/**
 * MLD Saved Search Notifications
 *
 * Handles frequency-based notifications for saved searches (instant, hourly, daily, weekly).
 * This class bridges the cron job system with the actual notification sending logic.
 *
 * @package MLS_Listings_Display
 * @subpackage SavedSearches
 * @since 6.11.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Saved_Search_Notifications {

    /**
     * Notification tracker instance
     */
    private static $tracker = null;

    /**
     * BuddyBoss notifier instance
     */
    private static $buddyboss_notifier = null;

    /**
     * Results tracking
     */
    private static $results = [
        'sent' => 0,
        'failed' => 0,
        'searches_processed' => 0,
        'listings_matched' => 0,
        'errors' => []
    ];

    /**
     * Initialize dependencies
     */
    private static function init_dependencies() {
        // Load Enhanced Filter Matcher (centralized filter matching logic)
        $filter_matcher_file = plugin_dir_path(__FILE__) . 'class-mld-enhanced-filter-matcher.php';
        if (file_exists($filter_matcher_file) && !class_exists('MLD_Enhanced_Filter_Matcher')) {
            require_once $filter_matcher_file;
        }

        if (self::$tracker === null) {
            $tracker_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-notification-tracker.php';
            if (file_exists($tracker_file)) {
                require_once $tracker_file;
                self::$tracker = MLD_Notification_Tracker::get_instance();
            }
        }

        if (self::$buddyboss_notifier === null && function_exists('bp_is_active')) {
            $enhanced_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-buddyboss-notifier-enhanced.php';
            $standard_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-buddyboss-notifier.php';

            if (file_exists($enhanced_file)) {
                require_once $enhanced_file;
                self::$buddyboss_notifier = MLD_BuddyBoss_Notifier_Enhanced::get_instance();
            } elseif (file_exists($standard_file)) {
                require_once $standard_file;
                self::$buddyboss_notifier = MLD_BuddyBoss_Notifier::get_instance();
            }
        }

        // Load Push Notifications class
        $push_file = plugin_dir_path(__FILE__) . '../notifications/class-mld-push-notifications.php';
        if (file_exists($push_file) && !class_exists('MLD_Push_Notifications')) {
            require_once $push_file;
        }
    }

    /**
     * Send notifications for saved searches based on frequency
     *
     * @param string $frequency The notification frequency (instant, hourly, daily, weekly)
     * @return array Results of the notification process
     */
    public static function send_notifications($frequency) {
        self::init_dependencies();

        // Reset results
        self::$results = [
            'sent' => 0,
            'failed' => 0,
            'searches_processed' => 0,
            'listings_matched' => 0,
            'errors' => []
        ];

        try {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Saved_Search_Notifications: Processing {$frequency} notifications");
            }

            // Get saved searches for this frequency
            $searches = self::get_searches_by_frequency($frequency);

            if (empty($searches)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD_Saved_Search_Notifications: No active searches found for frequency: {$frequency}");
                }
                return self::$results;
            }

            // Get time window based on frequency
            $time_window = self::get_time_window($frequency);

            // Get recent listings within the time window
            $recent_listings = self::get_recent_listings($time_window);

            if (empty($recent_listings)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD_Saved_Search_Notifications: No recent listings in the last {$time_window} minutes");
                }
                return self::$results;
            }

            // Process each search
            foreach ($searches as $search) {
                self::$results['searches_processed']++;
                self::process_search($search, $recent_listings, $frequency);
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    "MLD_Saved_Search_Notifications: Completed - Sent: %d, Failed: %d, Searches: %d",
                    self::$results['sent'],
                    self::$results['failed'],
                    self::$results['searches_processed']
                ));
            }

        } catch (Exception $e) {
            self::$results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD_Saved_Search_Notifications Error: " . $e->getMessage());
            }
        }

        return self::$results;
    }

    /**
     * Get saved searches by notification frequency
     *
     * @param string $frequency The notification frequency
     * @return array Array of saved search objects
     */
    private static function get_searches_by_frequency($frequency) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_saved_searches';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return [];
        }

        // Get searches with matching frequency that are due for notification
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');
        $sql = $wpdb->prepare("
            SELECT * FROM {$table_name}
            WHERE is_active = 1
            AND notification_frequency = %s
            AND (last_notified_at IS NULL OR last_notified_at < DATE_SUB(%s, INTERVAL %d MINUTE))
            ORDER BY last_notified_at ASC, id ASC
            LIMIT 100
        ", $frequency, $wp_now, self::get_time_window($frequency));

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Get time window in minutes based on frequency
     *
     * @param string $frequency The notification frequency
     * @return int Time window in minutes
     */
    private static function get_time_window($frequency) {
        switch ($frequency) {
            case 'instant':
                return 5;      // Check last 5 minutes
            case 'hourly':
                return 60;     // Check last hour
            case 'daily':
                return 10;     // Check last 10 minutes (runs every 10 minutes)
            case 'weekly':
                return 10080;  // Check last 7 days
            default:
                return 10;     // Default to 10 minutes
        }
    }

    /**
     * Get listings updated within the time window
     *
     * @param int $minutes Time window in minutes
     * @return array Array of listing objects
     */
    private static function get_recent_listings($minutes) {
        global $wpdb;

        // Use BME listing_summary table (denormalized, has all fields we need)
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listings_table = $wpdb->prefix . 'bme_listings';

        // Check if summary table exists (preferred)
        $use_summary = $wpdb->get_var("SHOW TABLES LIKE '{$summary_table}'") === $summary_table;
        $use_listings = $wpdb->get_var("SHOW TABLES LIKE '{$listings_table}'") === $listings_table;

        if (!$use_summary && !$use_listings) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD_Saved_Search_Notifications: No BME tables found');
            }
            return [];
        }

        // Use wp_date() for WordPress timezone consistency
        $cutoff_time = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));

        if ($use_summary) {
            // Use summary table - has all denormalized data
            $sql = $wpdb->prepare("
                SELECT
                    listing_id,
                    listing_key,
                    standard_status,
                    list_price,
                    street_number,
                    street_name,
                    unit_number,
                    city,
                    state_or_province,
                    postal_code,
                    bedrooms_total,
                    bathrooms_total,
                    building_area_total as living_area,
                    property_type,
                    latitude,
                    longitude,
                    modification_timestamp,
                    main_photo_url
                FROM {$summary_table}
                WHERE modification_timestamp > %s
                AND standard_status = 'Active'
                ORDER BY modification_timestamp DESC
                LIMIT 500
            ", $cutoff_time);
        } else {
            // Fallback: Join listings with location and details tables
            $location_table = $wpdb->prefix . 'bme_listing_location';
            $details_table = $wpdb->prefix . 'bme_listing_details';

            $sql = $wpdb->prepare("
                SELECT
                    l.listing_id,
                    l.listing_key,
                    l.standard_status,
                    l.list_price,
                    l.property_type,
                    l.modification_timestamp,
                    loc.street_number,
                    loc.street_name,
                    loc.city,
                    loc.state_or_province,
                    loc.postal_code,
                    loc.latitude,
                    loc.longitude,
                    d.bedrooms_total,
                    d.bathrooms_total_integer as bathrooms_total,
                    d.living_area
                FROM {$listings_table} l
                LEFT JOIN {$location_table} loc ON l.listing_id = loc.listing_id
                LEFT JOIN {$details_table} d ON l.listing_id = d.listing_id
                WHERE l.modification_timestamp > %s
                AND l.standard_status = 'Active'
                ORDER BY l.modification_timestamp DESC
                LIMIT 500
            ", $cutoff_time);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Process a single saved search against recent listings
     *
     * @param object $search The saved search object
     * @param array $listings Array of recent listings
     * @param string $frequency The notification frequency
     */
    private static function process_search($search, $listings, $frequency) {
        try {
            // Get user info
            $user = get_user_by('id', $search->user_id);
            if (!$user || !$user->user_email) {
                self::$results['errors'][] = "No valid user for search ID: {$search->id}";
                return;
            }

            // Parse search filters
            $filters = [];
            if (!empty($search->filters)) {
                $filters = json_decode($search->filters, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $filters = maybe_unserialize($search->filters);
                }
            }

            if (!is_array($filters)) {
                $filters = [];
            }

            // Match listings against search criteria
            $matching_listings = self::match_listings($listings, $filters, $search);

            if (empty($matching_listings)) {
                return;
            }

            self::$results['listings_matched'] += count($matching_listings);

            // Check if we've already notified about these listings
            $new_listings = self::filter_already_notified($matching_listings, $search, $user->ID);

            if (empty($new_listings)) {
                return;
            }

            // Send notification
            $sent = self::send_notification_email($user, $search, $new_listings, $frequency);

            if ($sent) {
                self::$results['sent']++;

                // Update last_notified_at
                self::update_last_notified($search->id);

                // Track sent notifications
                self::track_sent_notifications($user->ID, $search->id, $new_listings);

                // Send BuddyBoss notification if available
                if (self::$buddyboss_notifier && method_exists(self::$buddyboss_notifier, 'send_notification')) {
                    self::$buddyboss_notifier->send_notification($user->ID, $new_listings, $search);
                }

                // Send push notification to iOS devices
                $push_class_exists = class_exists('MLD_Push_Notifications');
                $push_configured = $push_class_exists ? MLD_Push_Notifications::is_configured() : false;

                error_log("MLD_Saved_Search_Notifications: Push check - class_exists: " . ($push_class_exists ? 'yes' : 'no') . ", configured: " . ($push_configured ? 'yes' : 'no'));

                if ($push_class_exists && $push_configured) {
                    $push_result = MLD_Push_Notifications::send_to_user(
                        $user->ID,
                        count($new_listings),
                        $search->name ?? $search->search_name ?? 'Your Search',
                        $search->id
                    );

                    error_log("MLD_Saved_Search_Notifications: Push result for user {$user->ID}: " . json_encode($push_result));

                    if (!$push_result['success']) {
                        error_log("MLD_Saved_Search_Notifications: Push notification failed for user {$user->ID}: " .
                            implode(', ', $push_result['errors']));
                    }
                } else {
                    error_log("MLD_Saved_Search_Notifications: Push skipped - class or config missing");
                }
            } else {
                self::$results['failed']++;
            }

        } catch (Exception $e) {
            self::$results['failed']++;
            self::$results['errors'][] = "Error processing search {$search->id}: " . $e->getMessage();
        }
    }

    /**
     * Match listings against search criteria
     *
     * Uses MLD_Enhanced_Filter_Matcher for comprehensive filter support (50+ filter types).
     *
     * @param array $listings Array of listings to check
     * @param array $filters Search filter criteria (unused - filters extracted from $search)
     * @param object $search The saved search object
     * @return array Matching listings
     */
    private static function match_listings($listings, $filters, $search) {
        $matching = [];

        foreach ($listings as $listing) {
            // Convert listing object to array for the enhanced filter matcher
            $listing_array = (array) $listing;

            // Use Enhanced Filter Matcher if available (supports 50+ filter types)
            if (class_exists('MLD_Enhanced_Filter_Matcher')) {
                if (MLD_Enhanced_Filter_Matcher::matches($listing_array, $search)) {
                    $matching[] = $listing;
                }
            } else {
                // Fallback to legacy matching (limited filter support)
                if (self::listing_matches_criteria_legacy($listing, $filters, $search)) {
                    $matching[] = $listing;
                }
            }
        }

        return $matching;
    }

    /**
     * Legacy filter matching (fallback if Enhanced Filter Matcher unavailable)
     *
     * Supports basic filters: price, beds, baths, sqft, city, property type, bounds, polygon.
     * For comprehensive filter support, use MLD_Enhanced_Filter_Matcher.
     *
     * @param object $listing The listing to check
     * @param array $filters The search filters
     * @param object $search The saved search object
     * @return bool True if listing matches
     * @deprecated Use MLD_Enhanced_Filter_Matcher::matches() instead
     */
    private static function listing_matches_criteria_legacy($listing, $filters, $search) {
        // Price range filter - check both naming conventions
        $min_price = $filters['min_price'] ?? $filters['price_min'] ?? null;
        $max_price = $filters['max_price'] ?? $filters['price_max'] ?? null;

        if (!empty($min_price) && ($listing->list_price ?? 0) < (float)$min_price) {
            return false;
        }
        if (!empty($max_price) && ($listing->list_price ?? 0) > (float)$max_price) {
            return false;
        }

        // Bedrooms filter
        $beds = intval($listing->bedrooms_total ?? 0);
        $min_beds = $filters['min_bedrooms'] ?? $filters['beds_min'] ?? null;

        if (!empty($min_beds) && $beds < (int)$min_beds) {
            return false;
        }

        // City filter - check all possible city filter keys
        $city_filters = [];
        if (!empty($filters['city'])) {
            $city_filters = array_merge($city_filters, (array)$filters['city']);
        }
        if (!empty($filters['cities'])) {
            $city_filters = array_merge($city_filters, (array)$filters['cities']);
        }
        if (!empty($filters['City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['City']);
        }
        if (!empty($filters['selected_cities'])) {
            $city_filters = array_merge($city_filters, (array)$filters['selected_cities']);
        }
        if (!empty($filters['keyword_City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['keyword_City']);
        }

        if (!empty($city_filters)) {
            $city_filters = array_unique(array_map('strtolower', array_map('trim', $city_filters)));
            if (!in_array(strtolower(trim($listing->city ?? '')), $city_filters)) {
                return false;
            }
        }

        // Property type filter
        $type_filters = [];
        if (!empty($filters['property_type'])) {
            $type_filters = array_merge($type_filters, (array)$filters['property_type']);
        }
        if (!empty($filters['property_types'])) {
            $type_filters = array_merge($type_filters, (array)$filters['property_types']);
        }
        if (!empty($filters['PropertyType'])) {
            $type_filters = array_merge($type_filters, (array)$filters['PropertyType']);
        }
        if (!empty($filters['home_type'])) {
            $type_filters = array_merge($type_filters, (array)$filters['home_type']);
        }

        if (!empty($type_filters)) {
            $type_filters = array_unique(array_map('strtolower', array_map('trim', $type_filters)));
            if (!in_array(strtolower(trim($listing->property_type ?? '')), $type_filters)) {
                return false;
            }
        }

        // Check polygon shapes if present (basic bounds check)
        if (!empty($search->polygon_shapes)) {
            $shapes = json_decode($search->polygon_shapes, true);
            if (is_array($shapes) && !empty($shapes)) {
                $lat = (float)$listing->latitude;
                $lng = (float)$listing->longitude;

                if ($lat && $lng && !self::point_in_any_polygon($lat, $lng, $shapes)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a point is within any of the polygons
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param array $shapes Array of polygon shapes
     * @return bool True if point is in any polygon
     */
    private static function point_in_any_polygon($lat, $lng, $shapes) {
        foreach ($shapes as $shape) {
            if (!empty($shape['coordinates']) && self::point_in_polygon($lat, $lng, $shape['coordinates'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ray casting algorithm to check if point is in polygon
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param array $polygon Array of [lat, lng] coordinates
     * @return bool True if point is inside polygon
     */
    private static function point_in_polygon($lat, $lng, $polygon) {
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = isset($polygon[$i]['lat']) ? $polygon[$i]['lat'] : $polygon[$i][0];
            $xi = isset($polygon[$i]['lng']) ? $polygon[$i]['lng'] : $polygon[$i][1];
            $yj = isset($polygon[$j]['lat']) ? $polygon[$j]['lat'] : $polygon[$j][0];
            $xj = isset($polygon[$j]['lng']) ? $polygon[$j]['lng'] : $polygon[$j][1];

            if ((($yi > $lat) != ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Filter out listings that user has already been notified about
     *
     * @param array $listings Matching listings
     * @param object $search The saved search
     * @param int $user_id User ID
     * @return array Listings not yet notified
     */
    private static function filter_already_notified($listings, $search, $user_id) {
        if (empty($listings)) {
            return [];
        }

        // Use tracker if available
        if (self::$tracker && method_exists(self::$tracker, 'was_notification_sent')) {
            $new_listings = [];
            foreach ($listings as $listing) {
                $mls_number = $listing->listing_id ?? $listing->listing_key;
                if (!self::$tracker->was_notification_sent($user_id, $mls_number, $search->id)) {
                    $new_listings[] = $listing;
                }
            }
            return $new_listings;
        }

        // Fallback: check saved_search_results table
        global $wpdb;
        $results_table = $wpdb->prefix . 'mld_saved_search_results';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$results_table}'") !== $results_table) {
            return $listings; // Can't check, return all
        }

        $listing_ids = array_map(function($l) {
            return $l->listing_id ?? $l->listing_key;
        }, $listings);

        $placeholders = implode(',', array_fill(0, count($listing_ids), '%s'));

        $notified = $wpdb->get_col($wpdb->prepare(
            "SELECT listing_id FROM {$results_table}
             WHERE saved_search_id = %d AND listing_id IN ({$placeholders})",
            array_merge([$search->id], $listing_ids)
        ));

        return array_filter($listings, function($l) use ($notified) {
            $id = $l->listing_id ?? $l->listing_key;
            return !in_array($id, $notified);
        });
    }

    /**
     * Send notification email to user
     *
     * @param WP_User $user The user to notify
     * @param object $search The saved search
     * @param array $listings Matching listings
     * @param string $frequency The notification frequency
     * @return bool True if email sent successfully
     */
    private static function send_notification_email($user, $search, $listings, $frequency) {
        // Build email content
        $subject = self::build_email_subject($search, $listings, $frequency);
        $body = self::build_email_body($user, $search, $listings, $frequency);

        // Set HTML content type
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        $sent = wp_mail($user->user_email, $subject, $body, $headers);

        if ($sent && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD_Saved_Search_Notifications: Email sent to {$user->user_email} for search {$search->id}");
        }

        return $sent;
    }

    /**
     * Build email subject line
     *
     * @param object $search The saved search
     * @param array $listings Matching listings
     * @param string $frequency The notification frequency
     * @return string Email subject
     */
    private static function build_email_subject($search, $listings, $frequency) {
        $count = count($listings);
        $search_name = !empty($search->name) ? $search->name : (!empty($search->search_name) ? $search->search_name : 'Your Search');

        if ($count === 1) {
            return "New Property Alert: 1 new listing matches \"{$search_name}\"";
        }

        return "New Property Alert: {$count} new listings match \"{$search_name}\"";
    }

    /**
     * Build email body HTML
     *
     * @param WP_User $user The user
     * @param object $search The saved search
     * @param array $listings Matching listings
     * @param string $frequency The notification frequency
     * @return string HTML email body
     */
    private static function build_email_body($user, $search, $listings, $frequency) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $search_name = !empty($search->name) ? $search->name : (!empty($search->search_name) ? $search->search_name : 'Your Search');
        $unsubscribe_url = add_query_arg([
            'action' => 'mld_unsubscribe',
            'search_id' => $search->id,
            'user_id' => $user->ID,
            'token' => wp_hash($user->ID . $search->id . 'unsubscribe')
        ], home_url());

        // Try to load template
        $template_paths = [
            MLD_PLUGIN_PATH . 'templates/emails/listing-updates-premium.php',
            MLD_PLUGIN_PATH . 'templates/emails/listing-updates-enhanced.php',
            MLD_PLUGIN_PATH . 'templates/emails/listing-updates.php'
        ];

        foreach ($template_paths as $template_path) {
            if (file_exists($template_path)) {
                ob_start();
                include $template_path;
                return ob_get_clean();
            }
        }

        // Fallback to inline HTML
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h1 style="color: #2563eb; margin: 0 0 10px 0; font-size: 24px;">🏠 New Property Alert</h1>
                <p style="margin: 0; color: #666;">
                    <?php echo count($listings); ?> new listing<?php echo count($listings) > 1 ? 's match' : ' matches'; ?>
                    your saved search "<?php echo esc_html($search_name); ?>"
                </p>
            </div>

            <?php foreach (array_slice($listings, 0, 10) as $listing): ?>
                <?php
                $address = trim(($listing->street_number ?? '') . ' ' . ($listing->street_name ?? ''));
                $city_state = trim(($listing->city ?? '') . ', ' . ($listing->state_or_province ?? ''));
                $price = '$' . number_format((float)$listing->list_price);
                $beds = $listing->bedrooms_total ?? 'N/A';
                $baths = $listing->bathrooms_total ?? 'N/A';
                $sqft = $listing->living_area ? number_format((int)$listing->living_area) : 'N/A';
                $listing_url = home_url("/property/{$listing->listing_id}/");
                ?>
                <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                    <h3 style="margin: 0 0 5px 0; color: #111827;">
                        <a href="<?php echo esc_url($listing_url); ?>" style="color: #2563eb; text-decoration: none;">
                            <?php echo esc_html($address); ?>
                        </a>
                    </h3>
                    <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php echo esc_html($city_state); ?></p>
                    <p style="margin: 0 0 10px 0; font-size: 20px; font-weight: bold; color: #059669;">
                        <?php echo esc_html($price); ?>
                    </p>
                    <p style="margin: 0; color: #666; font-size: 14px;">
                        <?php echo esc_html($beds); ?> beds • <?php echo esc_html($baths); ?> baths • <?php echo esc_html($sqft); ?> sqft
                    </p>
                </div>
            <?php endforeach; ?>

            <?php if (count($listings) > 10): ?>
                <p style="text-align: center; color: #666;">
                    <em>And <?php echo count($listings) - 10; ?> more listings...</em>
                </p>
            <?php endif; ?>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center;">
                <a href="<?php echo esc_url($search->search_url ?? $site_url); ?>"
                   style="display: inline-block; background: #2563eb; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold;">
                    View All Results
                </a>
            </div>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;">
                <p>
                    You received this email because you have a saved search on <?php echo esc_html($site_name); ?>.<br>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>" style="color: #9ca3af;">Unsubscribe</a> |
                    <a href="<?php echo esc_url($site_url); ?>/my-account/saved-searches/" style="color: #9ca3af;">Manage Saved Searches</a>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Update last_notified_at timestamp for a search
     *
     * @param int $search_id The saved search ID
     */
    private static function update_last_notified($search_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'mld_saved_searches',
            ['last_notified_at' => current_time('mysql')],
            ['id' => $search_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Track sent notifications to prevent duplicates
     *
     * @param int $user_id User ID
     * @param int $search_id Search ID
     * @param array $listings Listings that were notified
     */
    private static function track_sent_notifications($user_id, $search_id, $listings) {
        global $wpdb;

        // Use tracker if available
        if (self::$tracker && method_exists(self::$tracker, 'mark_listing_sent')) {
            foreach ($listings as $listing) {
                $mls_number = $listing->listing_id ?? $listing->listing_key;
                self::$tracker->mark_listing_sent($user_id, $mls_number, $search_id, true, false);
            }
            return;
        }

        // Fallback: insert into saved_search_results
        $results_table = $wpdb->prefix . 'mld_saved_search_results';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$results_table}'") !== $results_table) {
            return;
        }

        foreach ($listings as $listing) {
            $wpdb->insert(
                $results_table,
                [
                    'saved_search_id' => $search_id,
                    'listing_id' => $listing->listing_id ?? '',
                    'listing_key' => $listing->listing_key ?? '',
                    'first_seen_at' => current_time('mysql'),
                    'notified_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
        }
    }

    /**
     * Get new properties that match a saved search
     * Used by digest notification system
     *
     * @param object $search The saved search object
     * @return array Array of matching properties
     */
    public static function get_new_properties_for_search($search) {
        self::init_dependencies();

        // Get time window based on frequency
        $frequency = $search->notification_frequency ?? 'daily';
        $time_window = self::get_time_window($frequency);

        // Get recent listings
        $recent_listings = self::get_recent_listings($time_window);

        if (empty($recent_listings)) {
            return [];
        }

        // Parse filters
        $filters = [];
        if (!empty($search->filters)) {
            $filters = json_decode($search->filters, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $filters = maybe_unserialize($search->filters);
            }
        }

        // Match listings
        $matching = self::match_listings($recent_listings, $filters, $search);

        // Filter already notified
        if (!empty($matching)) {
            $matching = self::filter_already_notified($matching, $search, $search->user_id);
        }

        return $matching;
    }

    /**
     * Test notification for a specific saved search (admin/debug use)
     *
     * Actually SENDS a test email to the user with sample data.
     *
     * @param int $search_id The saved search ID
     * @return bool True if email was sent successfully
     */
    public static function test_notification($search_id) {
        global $wpdb;

        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_saved_searches WHERE id = %d",
            $search_id
        ));

        if (!$search) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Test Notification: Search not found - ID: {$search_id}");
            }
            return false;
        }

        // Get user info
        $user = get_user_by('id', $search->user_id);
        if (!$user || !$user->user_email) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Test Notification: No valid user for search ID: {$search_id}");
            }
            return false;
        }

        // Get some sample listings to include in the test email
        $sample_listings = self::get_sample_listings_for_test();

        if (empty($sample_listings)) {
            // Create a fake sample listing for the test
            $sample_listings = [
                (object) [
                    'listing_id' => 'TEST123',
                    'street_number' => '123',
                    'street_name' => 'Test Street',
                    'city' => 'Boston',
                    'state_or_province' => 'MA',
                    'postal_code' => '02101',
                    'list_price' => 500000,
                    'bedrooms_total' => 3,
                    'bathrooms_total' => 2,
                    'living_area' => 1500
                ]
            ];
        }

        // Build subject with TEST prefix
        $search_name = !empty($search->name) ? $search->name : 'Your Search';
        $subject = "[TEST] Property Alert: " . count($sample_listings) . " new listings match \"{$search_name}\"";

        // Build body
        $body = self::build_test_email_body($user, $search, $sample_listings);

        // Set HTML content type
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];

        // Send email
        $sent = wp_mail($user->user_email, $subject, $body, $headers);

        if ($sent && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Test Notification: Email sent successfully to {$user->user_email} for search {$search_id}");
        }

        return $sent;
    }

    /**
     * Get sample listings for test email
     *
     * @return array Sample listings
     */
    private static function get_sample_listings_for_test() {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get 3 random active listings
        $listings = $wpdb->get_results("
            SELECT
                listing_id,
                street_number,
                street_name,
                city,
                state_or_province,
                postal_code,
                list_price,
                bedrooms_total,
                bathrooms_total,
                building_area_total as living_area,
                main_photo_url
            FROM {$summary_table}
            WHERE standard_status = 'Active'
            ORDER BY RAND()
            LIMIT 3
        ");

        return $listings ?: [];
    }

    /**
     * Build test email body HTML
     *
     * @param WP_User $user The user
     * @param object $search The saved search
     * @param array $listings Sample listings
     * @return string HTML email body
     */
    private static function build_test_email_body($user, $search, $listings) {
        $site_name = get_bloginfo('name');
        $search_name = !empty($search->name) ? $search->name : 'Your Search';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffc107;">
                <strong style="color: #856404;">🧪 This is a TEST notification</strong>
                <p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
                    This email was sent to verify that saved search notifications are working correctly.
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h1 style="color: #2563eb; margin: 0 0 10px 0; font-size: 24px;">🏠 New Property Alert</h1>
                <p style="margin: 0; color: #666;">
                    <?php echo count($listings); ?> listing<?php echo count($listings) > 1 ? 's match' : ' matches'; ?>
                    your saved search "<?php echo esc_html($search_name); ?>"
                </p>
            </div>

            <?php foreach ($listings as $listing): ?>
                <?php
                $address = trim(($listing->street_number ?? '') . ' ' . ($listing->street_name ?? ''));
                $city_state = trim(($listing->city ?? 'Unknown City') . ', ' . ($listing->state_or_province ?? 'MA'));
                $price = '$' . number_format((float)$listing->list_price);
                $beds = $listing->bedrooms_total ?? 'N/A';
                $baths = $listing->bathrooms_total ?? 'N/A';
                $sqft = $listing->living_area ? number_format((int)$listing->living_area) : 'N/A';
                $listing_url = home_url("/property/{$listing->listing_id}/");
                ?>
                <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                    <span style="background:#28a745;color:white;padding:3px 8px;border-radius:3px;font-size:12px;font-weight:bold;">NEW</span>
                    <h3 style="margin: 10px 0 5px 0; color: #111827;">
                        <a href="<?php echo esc_url($listing_url); ?>" style="color: #2563eb; text-decoration: none;">
                            <?php echo esc_html($address ?: 'Sample Property'); ?>
                        </a>
                    </h3>
                    <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;"><?php echo esc_html($city_state); ?></p>
                    <p style="margin: 0 0 10px 0; font-size: 20px; font-weight: bold; color: #059669;">
                        <?php echo esc_html($price); ?>
                    </p>
                    <p style="margin: 0; color: #666; font-size: 14px;">
                        <?php echo esc_html($beds); ?> beds • <?php echo esc_html($baths); ?> baths • <?php echo esc_html($sqft); ?> sqft
                    </p>
                </div>
            <?php endforeach; ?>

            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; text-align: center; color: #9ca3af; font-size: 12px;">
                <p>
                    You received this test email because an administrator triggered a test notification.<br>
                    Real notifications will be sent based on your saved search frequency: <strong><?php echo esc_html($search->notification_frequency ?? 'Not set'); ?></strong>
                </p>
                <p style="margin-top: 15px;">
                    <a href="<?php echo esc_url(home_url('/my-account/saved-searches/')); ?>" style="color: #2563eb;">Manage your saved searches</a>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Get test notification preview data (without sending email)
     *
     * @param int $search_id The saved search ID
     * @return array Test preview data
     */
    public static function get_test_preview($search_id) {
        global $wpdb;

        $search = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_saved_searches WHERE id = %d",
            $search_id
        ));

        if (!$search) {
            return ['success' => false, 'error' => 'Search not found'];
        }

        $properties = self::get_new_properties_for_search($search);

        return [
            'success' => true,
            'search_id' => $search_id,
            'search_name' => $search->name ?? $search->search_name ?? 'Unknown',
            'user_id' => $search->user_id,
            'frequency' => $search->notification_frequency ?? 'unknown',
            'matching_properties' => count($properties),
            'properties' => array_slice($properties, 0, 5) // First 5 for preview
        ];
    }

    /**
     * Test the live alert flow for a saved search (admin/debug use)
     *
     * @param int $search_id The saved search ID
     * @return array Test results including email preview
     */
    public static function test_live_alert_flow($search_id) {
        $test_result = self::test_notification($search_id);

        if (!$test_result['success']) {
            return $test_result;
        }

        // Add email preview if there are matching properties
        if ($test_result['matching_properties'] > 0) {
            global $wpdb;
            $search = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mld_saved_searches WHERE id = %d",
                $search_id
            ));
            $user = get_user_by('id', $search->user_id);

            if ($user) {
                $test_result['email_preview'] = [
                    'to' => $user->user_email,
                    'subject' => self::build_email_subject($search, $test_result['properties'], $search->notification_frequency ?? 'daily'),
                    'body_preview' => '(HTML email - ' . strlen(self::build_email_body($user, $search, $test_result['properties'], $search->notification_frequency ?? 'daily')) . ' characters)'
                ];
            }
        }

        return $test_result;
    }
}
