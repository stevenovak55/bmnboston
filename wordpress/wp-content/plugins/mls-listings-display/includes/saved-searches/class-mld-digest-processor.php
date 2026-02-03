<?php
/**
 * MLS Listings Display - Digest Processor
 *
 * Collects changes across multiple saved searches and builds
 * daily/weekly digest emails.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.32.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Digest_Processor {

    /**
     * Collect pending changes for a user
     *
     * @param int $user_id User ID
     * @param string $frequency Digest frequency ('daily' or 'weekly')
     * @return array Grouped changes by search
     */
    public function collect_for_user($user_id, $frequency = 'daily') {
        global $wpdb;

        $searches_table = $wpdb->prefix . 'mld_saved_searches';
        $results_table = $wpdb->prefix . 'mld_saved_search_results';

        // Determine time window based on frequency
        $interval = $frequency === 'weekly' ? '7 DAY' : '1 DAY';

        // Get user's active saved searches with digest enabled
        $searches = $wpdb->get_results($wpdb->prepare(
            "SELECT ss.*, u.user_email, u.display_name
             FROM {$searches_table} ss
             INNER JOIN {$wpdb->users} u ON ss.user_id = u.ID
             WHERE ss.user_id = %d
               AND ss.is_active = 1
               AND ss.digest_enabled = 1
             ORDER BY ss.created_at ASC",
            $user_id
        ), ARRAY_A);

        if (empty($searches)) {
            return array();
        }

        $collected = array();

        foreach ($searches as $search) {
            // Get unnotified results for this search within the time window
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT listing_id, listing_key, first_seen_at
                 FROM {$results_table}
                 WHERE saved_search_id = %d
                   AND notified_at IS NULL
                   AND first_seen_at >= DATE_SUB(NOW(), INTERVAL {$interval})
                 ORDER BY first_seen_at DESC
                 LIMIT 50",
                $search['id']
            ), ARRAY_A);

            if (empty($results)) {
                continue;
            }

            // Fetch listing details
            $listing_keys = wp_list_pluck($results, 'listing_key');
            $changes = $this->get_listing_changes($listing_keys, $search);

            if (!empty($changes)) {
                $collected[] = array(
                    'search_id' => $search['id'],
                    'name' => $search['name'],
                    'search_url' => $search['search_url'] ?? '',
                    'changes' => $changes,
                    'total_count' => count($changes),
                );
            }
        }

        return $collected;
    }

    /**
     * Get listing changes with details
     *
     * @param array $listing_keys Array of listing keys
     * @param array $search Search data for context
     * @return array Changes with listing data
     */
    private function get_listing_changes($listing_keys, $search) {
        global $wpdb;

        if (empty($listing_keys)) {
            return array();
        }

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $placeholders = implode(',', array_fill(0, count($listing_keys), '%s'));

        // Fetch listings from summary table
        $listings = $wpdb->get_results($wpdb->prepare(
            "SELECT listing_key, listing_id, street_number, street_name, city,
                    state_or_province, postal_code, list_price, original_list_price,
                    bedrooms_total, bathrooms_total, building_area_total,
                    main_photo_url, standard_status, listing_contract_date,
                    days_on_market, year_built
             FROM {$summary_table}
             WHERE listing_key IN ({$placeholders})",
            ...$listing_keys
        ), ARRAY_A);

        $changes = array();

        foreach ($listings as $listing) {
            $change_type = 'new_listing';
            $badge_color = '#28a745';
            $badge_text = 'NEW';

            // Determine change type based on price comparison
            if ($listing['original_list_price'] && $listing['list_price'] < $listing['original_list_price']) {
                $change_type = 'price_reduced';
                $badge_color = '#dc3545';
                $badge_text = 'PRICE REDUCED';
            }

            // Format address
            $full_address = trim($listing['street_number'] . ' ' . $listing['street_name']);

            $changes[$listing['listing_key']] = array(
                'listing_data' => array(
                    'id' => $listing['listing_key'],
                    'listing_id' => $listing['listing_id'],
                    'full_address' => $full_address,
                    'street_address' => $full_address,
                    'city' => $listing['city'],
                    'state_or_province' => $listing['state_or_province'],
                    'postal_code' => $listing['postal_code'],
                    'list_price' => $listing['list_price'],
                    'bedrooms_total' => $listing['bedrooms_total'],
                    'bathrooms_total' => $listing['bathrooms_total'],
                    'building_area_total' => $listing['building_area_total'],
                    'primary_photo' => $listing['main_photo_url'],
                    'photo_url' => $listing['main_photo_url'],
                    'standard_status' => $listing['standard_status'],
                    'listing_contract_date' => $listing['listing_contract_date'],
                    'days_on_market' => $listing['days_on_market'],
                    'year_built' => $listing['year_built'],
                ),
                'change_type' => $change_type,
                'badge_color' => $badge_color,
                'badge_text' => $badge_text,
                'old_price' => $listing['original_list_price'],
                'new_price' => $listing['list_price'],
            );
        }

        return $changes;
    }

    /**
     * Deduplicate properties across searches
     *
     * When the same property appears in multiple searches, keep only one instance
     * and note which searches it matched.
     *
     * @param array $collected Collected changes by search
     * @return array Deduplicated data
     */
    public function deduplicate_properties($collected) {
        $seen = array();
        $deduped = array();

        foreach ($collected as $search_data) {
            $search_changes = array();

            foreach ($search_data['changes'] as $listing_key => $change) {
                if (isset($seen[$listing_key])) {
                    // Already seen, skip but add to matched searches list
                    $seen[$listing_key]['matched_searches'][] = $search_data['name'];
                    continue;
                }

                // First time seeing this listing
                $change['matched_searches'] = array($search_data['name']);
                $seen[$listing_key] = $change;
                $search_changes[$listing_key] = $change;
            }

            if (!empty($search_changes)) {
                $deduped[] = array(
                    'search_id' => $search_data['search_id'],
                    'name' => $search_data['name'],
                    'search_url' => $search_data['search_url'],
                    'changes' => $search_changes,
                    'total_count' => count($search_changes),
                );
            }
        }

        return $deduped;
    }

    /**
     * Build digest email data for a user
     *
     * @param int $user_id User ID
     * @param string $type Digest type ('daily' or 'weekly')
     * @return array Email data for template engine
     */
    public function build_digest($user_id, $type = 'daily') {
        $collected = $this->collect_for_user($user_id, $type);

        if (empty($collected)) {
            return array();
        }

        $deduped = $this->deduplicate_properties($collected);

        // Calculate totals
        $total_new = 0;
        $total_price_changes = 0;
        $all_changes = array();

        foreach ($deduped as $search_data) {
            foreach ($search_data['changes'] as $change) {
                $all_changes[] = $change;
                if ($change['change_type'] === 'new_listing') {
                    $total_new++;
                } elseif ($change['change_type'] === 'price_reduced') {
                    $total_price_changes++;
                }
            }
        }

        // Find highlights (best price drops)
        $highlights = $this->find_highlights($all_changes);

        $data = array(
            'searches' => $deduped,
            'total_new' => $total_new,
            'total_price_changes' => $total_price_changes,
            'highlights' => $highlights,
        );

        // For weekly, add additional stats
        if ($type === 'weekly') {
            $data['total_price_drops'] = $total_price_changes;
            $data['total_pending'] = $this->count_pending_changes($user_id);
            $data['top_picks'] = array_slice($highlights, 0, 3);
            $data['best_price_drops'] = $this->get_best_price_drops($all_changes, 3);
            $data['market_trends'] = $this->get_market_trends($user_id);
        }

        return $data;
    }

    /**
     * Find highlight properties (best deals, notable changes)
     *
     * @param array $all_changes All changes across searches
     * @return array Highlight properties
     */
    private function find_highlights($all_changes) {
        $highlights = array();

        // Find biggest price drops
        $price_drops = array_filter($all_changes, function($c) {
            return $c['change_type'] === 'price_reduced';
        });

        usort($price_drops, function($a, $b) {
            $drop_a = ($a['old_price'] ?? 0) - ($a['new_price'] ?? 0);
            $drop_b = ($b['old_price'] ?? 0) - ($b['new_price'] ?? 0);
            return $drop_b - $drop_a;
        });

        // Take top 2 price drops
        foreach (array_slice($price_drops, 0, 2) as $drop) {
            $drop['badge_text'] = 'PRICE DROP';
            $drop['badge_color'] = '#dc3545';
            $highlights[] = $drop;
        }

        // Find newest listings (within 24 hours)
        $new_listings = array_filter($all_changes, function($c) {
            return $c['change_type'] === 'new_listing';
        });

        // Take first new listing
        if (!empty($new_listings)) {
            $new = reset($new_listings);
            $new['badge_text'] = 'JUST LISTED';
            $new['badge_color'] = '#28a745';
            $highlights[] = $new;
        }

        return $highlights;
    }

    /**
     * Get best price drops for weekly digest
     *
     * @param array $all_changes All changes
     * @param int $limit Number to return
     * @return array Best price drops
     */
    private function get_best_price_drops($all_changes, $limit = 3) {
        $price_drops = array_filter($all_changes, function($c) {
            return $c['change_type'] === 'price_reduced';
        });

        usort($price_drops, function($a, $b) {
            $drop_a = ($a['old_price'] ?? 0) - ($a['new_price'] ?? 0);
            $drop_b = ($b['old_price'] ?? 0) - ($b['new_price'] ?? 0);
            return $drop_b - $drop_a;
        });

        $drops = array_slice($price_drops, 0, $limit);

        foreach ($drops as &$drop) {
            $drop['badge_text'] = 'PRICE REDUCED';
            $drop['badge_color'] = '#dc3545';
            $drop['change_type'] = 'price';
        }

        return $drops;
    }

    /**
     * Count pending status changes for weekly stats
     *
     * @param int $user_id User ID
     * @return int Count
     */
    private function count_pending_changes($user_id) {
        global $wpdb;

        // This would require tracking status changes - for now return 0
        return 0;
    }

    /**
     * Get market trends for user's search areas
     *
     * @param int $user_id User ID
     * @return array Market trend data
     */
    private function get_market_trends($user_id) {
        global $wpdb;

        $searches_table = $wpdb->prefix . 'mld_saved_searches';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        // Get cities from user's saved searches
        $searches = $wpdb->get_col($wpdb->prepare(
            "SELECT filters FROM {$searches_table} WHERE user_id = %d AND is_active = 1",
            $user_id
        ));

        $cities = array();
        foreach ($searches as $filters_json) {
            $filters = json_decode($filters_json, true);
            if (!empty($filters['selected_cities'])) {
                $cities = array_merge($cities, (array) $filters['selected_cities']);
            } elseif (!empty($filters['city'])) {
                $cities[] = $filters['city'];
            }
        }

        $cities = array_unique($cities);

        if (empty($cities)) {
            return array();
        }

        // Get market stats for these cities
        $placeholders = implode(',', array_fill(0, count($cities), '%s'));
        $wp_now = current_time('mysql');

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                AVG(list_price) as avg_price,
                AVG(DATEDIFF(%s, listing_contract_date)) as avg_dom,
                COUNT(*) as total_active
             FROM {$summary_table}
             WHERE standard_status = 'Active'
               AND city IN ({$placeholders})",
            $wp_now,
            ...$cities
        ));

        if (!$stats || !$stats->avg_price) {
            return array();
        }

        return array(
            'avg_price' => $stats->avg_price,
            'avg_dom' => $stats->avg_dom,
            'total_active' => $stats->total_active,
            'area_name' => count($cities) === 1 ? $cities[0] : 'Your Search Areas',
        );
    }

    /**
     * Process pending digests for all users
     *
     * Should be called by cron job.
     *
     * @param string $frequency Frequency to process ('daily' or 'weekly')
     * @return array Processing results
     */
    public static function process_pending_digests($frequency = 'daily') {
        global $wpdb;

        $prefs_table = $wpdb->prefix . 'mld_user_email_preferences';

        // Get users who have digest enabled for this frequency
        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT p.user_id, p.timezone, u.user_email, u.display_name
             FROM {$prefs_table} p
             INNER JOIN {$wpdb->users} u ON p.user_id = u.ID
             WHERE p.digest_enabled = 1
               AND p.digest_frequency = %s
               AND p.global_pause = 0
               AND (p.unsubscribed_at IS NULL)",
            $frequency
        ), ARRAY_A);

        if (empty($users)) {
            return array(
                'processed' => 0,
                'sent' => 0,
                'errors' => 0,
            );
        }

        $processor = new self();
        $results = array(
            'processed' => 0,
            'sent' => 0,
            'errors' => 0,
            'details' => array(),
        );

        foreach ($users as $user_data) {
            $user_id = $user_data['user_id'];
            $results['processed']++;

            try {
                // Check if it's the right time for this user
                if (!self::is_digest_time($user_id, $frequency)) {
                    continue;
                }

                // Build digest data
                $digest_data = $processor->build_digest($user_id, $frequency);

                if (empty($digest_data) || empty($digest_data['searches'])) {
                    continue;
                }

                // Send the digest email
                $sent = self::send_digest($user_id, $user_data, $digest_data, $frequency);

                if ($sent) {
                    $results['sent']++;
                    $results['details'][] = array(
                        'user_id' => $user_id,
                        'email' => $user_data['user_email'],
                        'status' => 'sent',
                    );

                    // Mark results as notified
                    self::mark_results_notified($user_id, $digest_data['searches']);
                } else {
                    $results['errors']++;
                    $results['details'][] = array(
                        'user_id' => $user_id,
                        'email' => $user_data['user_email'],
                        'status' => 'failed',
                    );
                }

            } catch (Exception $e) {
                $results['errors']++;
                $results['details'][] = array(
                    'user_id' => $user_id,
                    'email' => $user_data['user_email'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * Check if it's the right time to send digest for a user
     *
     * @param int $user_id User ID
     * @param string $frequency Digest frequency
     * @return bool
     */
    private static function is_digest_time($user_id, $frequency) {
        global $wpdb;

        $prefs_table = $wpdb->prefix . 'mld_user_email_preferences';

        $prefs = $wpdb->get_row($wpdb->prepare(
            "SELECT digest_time, timezone FROM {$prefs_table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        if (!$prefs) {
            return true; // No preferences, send at default time
        }

        $user_timezone = $prefs['timezone'] ?: 'America/New_York';
        $digest_time = $prefs['digest_time'] ?: '08:00:00';

        try {
            $tz = new DateTimeZone($user_timezone);
            $now = new DateTime('now', $tz);
            $digest_hour = (int) substr($digest_time, 0, 2);

            // Allow a 1-hour window around the preferred time
            $current_hour = (int) $now->format('H');

            if ($current_hour === $digest_hour || $current_hour === ($digest_hour + 1) % 24) {
                // For weekly, check if it's the right day (Monday)
                if ($frequency === 'weekly') {
                    return $now->format('N') === '1'; // Monday
                }
                return true;
            }
        } catch (Exception $e) {
            // Invalid timezone, send anyway
            return true;
        }

        return false;
    }

    /**
     * Send digest email to user
     *
     * @param int $user_id User ID
     * @param array $user_data User data
     * @param array $digest_data Digest data
     * @param string $frequency Frequency type
     * @return bool Success
     */
    private static function send_digest($user_id, $user_data, $digest_data, $frequency) {
        // Get agent if user has one assigned
        $agent = null;
        if (class_exists('MLD_Saved_Search_Collaboration')) {
            $collab = new MLD_Saved_Search_Collaboration();
            $client_agent = $collab->get_client_agent($user_id);
            if ($client_agent) {
                $agent = MLD_Agent_Client_Manager::get_agent($client_agent['agent_id']);
            }
        }

        // Create template engine
        $engine = new MLD_Email_Template_Engine();
        $engine->set_client($user_id);

        if ($agent) {
            $engine->set_agent($agent);
        }

        // Determine template and subject
        $template = $frequency === 'weekly' ? 'weekly-roundup' : 'daily-digest';
        $total_updates = $digest_data['total_new'] + $digest_data['total_price_changes'];

        if ($frequency === 'weekly') {
            $subject = sprintf(
                'Weekly Market Roundup: %d new listings, %d price drops',
                $digest_data['total_new'],
                $digest_data['total_price_drops'] ?? $digest_data['total_price_changes']
            );
            $title = 'Weekly Market Roundup';
            $subtitle = 'Your personalized property update';
        } else {
            $subject = sprintf(
                'Daily Digest: %d updates across %d saved searches',
                $total_updates,
                count($digest_data['searches'])
            );
            $title = 'Daily Property Digest';
            $subtitle = date('l, F j, Y');
        }

        // Add header info to data
        $digest_data['title'] = $title;
        $digest_data['subtitle'] = $subtitle;

        // Render email
        $html = $engine->render($template, $digest_data);

        // Send email
        $to = $user_data['user_email'];
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        );

        // CC agent if enabled
        if ($agent && !empty($agent['email'])) {
            // Check if any search has cc_agent_on_notify enabled
            foreach ($digest_data['searches'] as $search) {
                if (!empty($search['cc_agent_on_notify'])) {
                    $headers[] = 'Cc: ' . $agent['email'];
                    break;
                }
            }
        }

        $sent = wp_mail($to, $subject, $html, $headers);

        if ($sent) {
            // Record analytics
            $email_type = $frequency === 'weekly' ? 'weekly_digest' : 'daily_digest';
            $engine->record_send($user_id, $email_type, null, array());
        }

        return $sent;
    }

    /**
     * Mark search results as notified
     *
     * @param int $user_id User ID
     * @param array $searches Searches with changes
     */
    private static function mark_results_notified($user_id, $searches) {
        global $wpdb;

        $results_table = $wpdb->prefix . 'mld_saved_search_results';

        foreach ($searches as $search_data) {
            $search_id = $search_data['search_id'];
            $listing_keys = array_keys($search_data['changes']);

            if (empty($listing_keys)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($listing_keys), '%s'));

            $wpdb->query($wpdb->prepare(
                "UPDATE {$results_table}
                 SET notified_at = %s
                 WHERE saved_search_id = %d
                   AND listing_key IN ({$placeholders})",
                current_time('mysql'),
                $search_id,
                ...$listing_keys
            ));
        }
    }

    /**
     * Get digest stats for a user (for preferences UI)
     *
     * @param int $user_id User ID
     * @return array Stats
     */
    public static function get_user_digest_stats($user_id) {
        global $wpdb;

        $analytics_table = $wpdb->prefix . 'mld_email_analytics';

        // Get last 30 days of digest stats
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_sent,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                SUM(click_count) as total_clicks,
                MAX(sent_at) as last_sent
             FROM {$analytics_table}
             WHERE user_id = %d
               AND email_type IN ('daily_digest', 'weekly_digest')
               AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $user_id
        ), ARRAY_A);

        if (!$stats) {
            return array(
                'total_sent' => 0,
                'total_opened' => 0,
                'total_clicks' => 0,
                'open_rate' => 0,
                'last_sent' => null,
            );
        }

        $total = (int) $stats['total_sent'];
        $opened = (int) $stats['total_opened'];

        return array(
            'total_sent' => $total,
            'total_opened' => $opened,
            'total_clicks' => (int) $stats['total_clicks'],
            'open_rate' => $total > 0 ? round(($opened / $total) * 100) : 0,
            'last_sent' => $stats['last_sent'],
        );
    }
}
