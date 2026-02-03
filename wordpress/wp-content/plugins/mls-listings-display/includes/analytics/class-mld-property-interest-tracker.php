<?php
/**
 * MLS Listings Display - Property Interest Tracker
 *
 * Tracks and calculates property-level interest for users.
 * Aggregates views, interactions, and calculates interest scores.
 *
 * @package MLS_Listings_Display
 * @subpackage Analytics
 * @since 6.40.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Interest_Tracker {

    /**
     * Interest score weights
     */
    const WEIGHT_VIEWS = 30;           // Base view score
    const WEIGHT_DURATION = 25;        // Time spent viewing
    const WEIGHT_PHOTOS = 15;          // Photo engagement
    const WEIGHT_ACTIONS = 30;         // Calculator, contact, favorite

    /**
     * Record a property view
     *
     * @param int $user_id User ID
     * @param string $listing_id MLS listing ID
     * @param string $listing_key Listing key hash
     * @param int $duration Duration in seconds
     * @return bool Success
     */
    public static function record_view($user_id, $listing_id, $listing_key = '', $duration = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';
        $now = current_time('mysql');

        // Check if record exists
        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT id, view_count, total_view_duration, first_viewed_at
            FROM {$table}
            WHERE user_id = %d AND listing_id = %s
        ", $user_id, $listing_id));

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $table,
                array(
                    'view_count' => $existing->view_count + 1,
                    'total_view_duration' => $existing->total_view_duration + $duration,
                    'last_viewed_at' => $now
                ),
                array('id' => $existing->id),
                array('%d', '%d', '%s'),
                array('%d')
            );
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'listing_id' => $listing_id,
                    'listing_key' => $listing_key,
                    'view_count' => 1,
                    'total_view_duration' => $duration,
                    'first_viewed_at' => $now,
                    'last_viewed_at' => $now
                ),
                array('%d', '%s', '%s', '%d', '%d', '%s', '%s')
            );
        }

        // Recalculate interest score
        if ($result !== false) {
            self::calculate_interest_score($user_id, $listing_id);
        }

        return $result !== false;
    }

    /**
     * Record photo view
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param int $count Number of photos viewed (default 1)
     * @return bool Success
     */
    public static function record_photo_view($user_id, $listing_id, $count = 1) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        // Ensure record exists
        self::ensure_record_exists($user_id, $listing_id);

        $result = $wpdb->query($wpdb->prepare("
            UPDATE {$table}
            SET photo_views = photo_views + %d,
                last_viewed_at = %s
            WHERE user_id = %d AND listing_id = %s
        ", $count, current_time('mysql'), $user_id, $listing_id));

        if ($result !== false) {
            self::calculate_interest_score($user_id, $listing_id);
        }

        return $result !== false;
    }

    /**
     * Record an action (calculator, contact, share)
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param string $action Action type: calculator_used, contact_clicked, shared, favorited
     * @return bool Success
     */
    public static function record_action($user_id, $listing_id, $action) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        // Validate action type
        $valid_actions = array('calculator_used', 'contact_clicked', 'shared', 'favorited');
        if (!in_array($action, $valid_actions)) {
            return false;
        }

        // Ensure record exists
        self::ensure_record_exists($user_id, $listing_id);

        $result = $wpdb->query($wpdb->prepare("
            UPDATE {$table}
            SET {$action} = 1,
                last_viewed_at = %s
            WHERE user_id = %d AND listing_id = %s
        ", current_time('mysql'), $user_id, $listing_id));

        if ($result !== false) {
            self::calculate_interest_score($user_id, $listing_id);
        }

        return $result !== false;
    }

    /**
     * Ensure a property interest record exists
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param string $listing_key Optional listing key
     * @return bool Record exists
     */
    private static function ensure_record_exists($user_id, $listing_id, $listing_key = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$table}
            WHERE user_id = %d AND listing_id = %s
        ", $user_id, $listing_id));

        if (!$exists) {
            // Get listing_key if not provided
            if (empty($listing_key)) {
                $summary_table = $wpdb->prefix . 'bme_listing_summary';
                $listing_key = $wpdb->get_var($wpdb->prepare("
                    SELECT listing_key FROM {$summary_table}
                    WHERE listing_id = %s
                ", $listing_id));
            }

            $wpdb->insert(
                $table,
                array(
                    'user_id' => $user_id,
                    'listing_id' => $listing_id,
                    'listing_key' => $listing_key ?: '',
                    'first_viewed_at' => current_time('mysql'),
                    'last_viewed_at' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }

        return true;
    }

    /**
     * Calculate interest score for a property
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return float Interest score (0-100)
     */
    public static function calculate_interest_score($user_id, $listing_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        $record = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE user_id = %d AND listing_id = %s
        ", $user_id, $listing_id));

        if (!$record) {
            return 0;
        }

        // View score: 10 points per view, max 30
        $view_score = min($record->view_count * 10, self::WEIGHT_VIEWS);

        // Duration score: 5 points per minute, max 25
        $duration_minutes = $record->total_view_duration / 60;
        $duration_score = min($duration_minutes * 5, self::WEIGHT_DURATION);

        // Photo score: 3 points per photo view, max 15
        $photo_score = min($record->photo_views * 3, self::WEIGHT_PHOTOS);

        // Action score: 10 points each for high-intent actions
        $action_score = 0;
        if ($record->calculator_used) $action_score += 8;
        if ($record->contact_clicked) $action_score += 12;
        if ($record->shared) $action_score += 5;
        if ($record->favorited) $action_score += 10;
        $action_score = min($action_score, self::WEIGHT_ACTIONS);

        $total_score = $view_score + $duration_score + $photo_score + $action_score;

        // Update the score
        $wpdb->update(
            $table,
            array('interest_score' => $total_score),
            array('user_id' => $user_id, 'listing_id' => $listing_id),
            array('%f'),
            array('%d', '%s')
        );

        return $total_score;
    }

    /**
     * Get top properties for a user by interest score
     *
     * @param int $user_id User ID
     * @param int $limit Number of properties to return
     * @return array Properties with interest data
     */
    public static function get_top_properties($user_id, $limit = 10) {
        global $wpdb;

        $interest_table = $wpdb->prefix . 'mld_client_property_interest';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                pi.*,
                s.street_number,
                s.street_name,
                s.city,
                s.list_price,
                s.bedrooms_total,
                s.bathrooms_total,
                s.building_area_total,
                s.main_photo_url,
                s.standard_status
            FROM {$interest_table} pi
            LEFT JOIN {$summary_table} s ON pi.listing_id = s.listing_id
            WHERE pi.user_id = %d
            AND pi.interest_score > 0
            ORDER BY pi.interest_score DESC
            LIMIT %d
        ", $user_id, $limit), ARRAY_A);
    }

    /**
     * Update interest from activity data
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param int $view_count Views to add
     * @param int $duration Duration to add (seconds)
     * @return bool Success
     */
    public static function update_interest($user_id, $listing_id, $view_count = 0, $duration = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        // Ensure record exists
        self::ensure_record_exists($user_id, $listing_id);

        $updates = array();
        $update_types = array();

        if ($view_count > 0) {
            $updates['view_count'] = "view_count + {$view_count}";
        }
        if ($duration > 0) {
            $updates['total_view_duration'] = "total_view_duration + {$duration}";
        }

        if (empty($updates)) {
            return true;
        }

        $set_clause = array();
        foreach ($updates as $col => $expr) {
            $set_clause[] = "{$col} = {$expr}";
        }
        $set_clause[] = "last_viewed_at = '" . current_time('mysql') . "'";

        $result = $wpdb->query($wpdb->prepare("
            UPDATE {$table}
            SET " . implode(', ', $set_clause) . "
            WHERE user_id = %d AND listing_id = %s
        ", $user_id, $listing_id));

        if ($result !== false) {
            self::calculate_interest_score($user_id, $listing_id);
        }

        return $result !== false;
    }

    /**
     * Get interest data for a specific property
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return array|null Interest data or null
     */
    public static function get_property_interest($user_id, $listing_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        return $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE user_id = %d AND listing_id = %s
        ", $user_id, $listing_id), ARRAY_A);
    }

    /**
     * Get all property interests for a user
     *
     * @param int $user_id User ID
     * @param string $sort_by Sort field (interest_score, view_count, last_viewed_at)
     * @param string $order Sort order (ASC, DESC)
     * @param int $limit Maximum results
     * @return array Property interests
     */
    public static function get_user_interests($user_id, $sort_by = 'interest_score', $order = 'DESC', $limit = 50) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        $valid_sorts = array('interest_score', 'view_count', 'last_viewed_at', 'first_viewed_at');
        $sort_by = in_array($sort_by, $valid_sorts) ? $sort_by : 'interest_score';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        return $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE user_id = %d
            ORDER BY {$sort_by} {$order}
            LIMIT %d
        ", $user_id, $limit), ARRAY_A);
    }

    /**
     * Get interest summary for a user
     *
     * @param int $user_id User ID
     * @return array Summary statistics
     */
    public static function get_user_interest_summary($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';

        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) as total_properties,
                SUM(view_count) as total_views,
                SUM(total_view_duration) as total_duration,
                AVG(interest_score) as avg_interest_score,
                MAX(interest_score) as max_interest_score,
                SUM(calculator_used) as calculator_uses,
                SUM(contact_clicked) as contact_clicks,
                SUM(favorited) as favorites
            FROM {$table}
            WHERE user_id = %d
        ", $user_id), ARRAY_A);

        return $summary;
    }

    /**
     * Get cities of interest for a user
     *
     * @param int $user_id User ID
     * @param int $limit Number of cities
     * @return array Cities with view counts
     */
    public static function get_cities_of_interest($user_id, $limit = 5) {
        global $wpdb;

        $interest_table = $wpdb->prefix . 'mld_client_property_interest';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                s.city,
                COUNT(*) as property_count,
                SUM(pi.view_count) as total_views,
                AVG(pi.interest_score) as avg_interest
            FROM {$interest_table} pi
            INNER JOIN {$summary_table} s ON pi.listing_id = s.listing_id
            WHERE pi.user_id = %d
            AND s.city IS NOT NULL
            AND s.city != ''
            GROUP BY s.city
            ORDER BY total_views DESC
            LIMIT %d
        ", $user_id, $limit), ARRAY_A);
    }

    /**
     * Get price range of interest for a user
     *
     * @param int $user_id User ID
     * @return array Price range (min, max, avg)
     */
    public static function get_price_range_of_interest($user_id) {
        global $wpdb;

        $interest_table = $wpdb->prefix . 'mld_client_property_interest';
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        return $wpdb->get_row($wpdb->prepare("
            SELECT
                MIN(s.list_price) as min_price,
                MAX(s.list_price) as max_price,
                AVG(s.list_price) as avg_price,
                COUNT(*) as property_count
            FROM {$interest_table} pi
            INNER JOIN {$summary_table} s ON pi.listing_id = s.listing_id
            WHERE pi.user_id = %d
            AND s.list_price > 0
            AND pi.interest_score >= 20
        ", $user_id), ARRAY_A);
    }

    /**
     * Cleanup old interest records
     *
     * @param int $days_old Records older than this many days
     * @param float $min_score Only delete records below this score
     * @return int Number of records deleted
     */
    public static function cleanup_old_records($days_old = 90, $min_score = 10) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_client_property_interest';
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        return $wpdb->query($wpdb->prepare("
            DELETE FROM {$table}
            WHERE last_viewed_at < %s
            AND interest_score < %f
        ", $date_threshold, $min_score));
    }
}
