<?php
/**
 * MLS Listings Display - Shared Properties Manager
 *
 * Manages property sharing between agents and their clients.
 * Supports bulk sharing with notification triggers.
 *
 * @package MLS_Listings_Display
 * @subpackage Shared_Properties
 * @since 6.35.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Shared_Properties_Manager {

    /**
     * Table name
     */
    private static $table_name = 'mld_shared_properties';

    /**
     * Get the full table name with prefix
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Share properties with client(s)
     *
     * Supports bulk sharing: multiple properties to one client, or one property to multiple clients.
     *
     * @param int $agent_id Agent user ID
     * @param array $client_ids Array of client user IDs
     * @param array $listing_keys Array of listing keys (MD5 hashes)
     * @param string $note Optional agent note
     * @return array Result with shared_count, shares, and errors
     */
    public static function share_properties($agent_id, $client_ids, $listing_keys, $note = '') {
        global $wpdb;

        $table = self::get_table_name();
        $shares = [];
        $errors = [];
        $shared_count = 0;

        // Validate agent is actually an agent for these clients
        foreach ($client_ids as $client_id) {
            if (!self::is_agent_for_client($agent_id, $client_id)) {
                $errors[] = [
                    'client_id' => $client_id,
                    'error' => 'Not authorized to share with this client'
                ];
                continue;
            }

            // Get listing_id for each listing_key
            foreach ($listing_keys as $listing_key) {
                $listing_id = self::get_listing_id_from_key($listing_key);

                if (!$listing_id) {
                    $errors[] = [
                        'listing_key' => $listing_key,
                        'error' => 'Invalid listing key'
                    ];
                    continue;
                }

                // Insert with ON DUPLICATE KEY to handle re-sharing
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table}
                    (agent_id, client_id, listing_id, listing_key, agent_note, shared_at)
                    VALUES (%d, %d, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE
                        agent_note = VALUES(agent_note),
                        shared_at = NOW(),
                        is_dismissed = 0,
                        client_response = 'none',
                        view_count = view_count",
                    $agent_id,
                    $client_id,
                    $listing_id,
                    $listing_key,
                    sanitize_textarea_field($note)
                ));

                if ($result !== false) {
                    $share_id = $wpdb->insert_id ?: $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table} WHERE agent_id = %d AND client_id = %d AND listing_key = %s",
                        $agent_id, $client_id, $listing_key
                    ));

                    $shares[] = [
                        'id' => (int) $share_id,
                        'client_id' => (int) $client_id,
                        'listing_key' => $listing_key,
                        'is_new' => $wpdb->insert_id > 0
                    ];
                    $shared_count++;
                }
            }
        }

        // Trigger notification action for new shares only
        $new_shares = array_filter($shares, function($s) { return $s['is_new']; });
        if (!empty($new_shares)) {
            do_action('mld_properties_shared', $agent_id, $client_ids, $listing_keys, $new_shares);
        }

        return [
            'success' => $shared_count > 0,
            'shared_count' => $shared_count,
            'shares' => $shares,
            'errors' => $errors
        ];
    }

    /**
     * Get properties shared with a client
     *
     * @param int $client_id Client user ID
     * @param array $args Optional arguments (include_dismissed, limit, offset)
     * @return array Array of shared properties with full property data
     */
    public static function get_shared_properties_for_client($client_id, $args = []) {
        global $wpdb;

        $defaults = [
            'include_dismissed' => false,
            'limit' => 50,
            'offset' => 0,
            'order' => 'DESC'
        ];
        $args = wp_parse_args($args, $defaults);

        $table = self::get_table_name();
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $agent_profiles = $wpdb->prefix . 'mld_agent_profiles';

        $where = ['sp.client_id = %d'];
        $params = [$client_id];

        if (!$args['include_dismissed']) {
            $where[] = 'sp.is_dismissed = 0';
        }

        $where_sql = implode(' AND ', $where);
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        $sql = $wpdb->prepare(
            "SELECT sp.*,
                    ap.display_name as agent_name,
                    ap.photo_url as agent_photo,
                    ap.phone as agent_phone,
                    ap.email as agent_email,
                    ls.listing_id,
                    ls.list_price,
                    ls.street_number,
                    ls.street_name,
                    ls.city,
                    ls.state_or_province,
                    ls.postal_code,
                    ls.bedrooms_total,
                    ls.bathrooms_total,
                    ls.building_area_total,
                    ls.main_photo_url,
                    ls.standard_status,
                    ls.property_type,
                    ls.latitude,
                    ls.longitude
             FROM {$table} sp
             LEFT JOIN {$agent_profiles} ap ON sp.agent_id = ap.user_id
             LEFT JOIN {$summary_table} ls ON sp.listing_key = ls.listing_key
             WHERE {$where_sql}
             ORDER BY sp.shared_at {$order}
             LIMIT %d OFFSET %d",
            array_merge($params, [$args['limit'], $args['offset']])
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Format the results
        return array_map(function($row) {
            return self::format_shared_property($row);
        }, $results ?: []);
    }

    /**
     * Get properties shared by an agent
     *
     * @param int $agent_id Agent user ID
     * @param array $args Optional arguments (client_id, limit, offset)
     * @return array Array of shared properties grouped by client
     */
    public static function get_shared_properties_by_agent($agent_id, $args = []) {
        global $wpdb;

        $defaults = [
            'client_id' => null,
            'limit' => 100,
            'offset' => 0
        ];
        $args = wp_parse_args($args, $defaults);

        $table = self::get_table_name();
        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $where = ['sp.agent_id = %d'];
        $params = [$agent_id];

        if ($args['client_id']) {
            $where[] = 'sp.client_id = %d';
            $params[] = $args['client_id'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = $wpdb->prepare(
            "SELECT sp.*,
                    u.display_name as client_name,
                    u.user_email as client_email,
                    ls.listing_id,
                    ls.list_price,
                    ls.street_number,
                    ls.street_name,
                    ls.city,
                    ls.main_photo_url,
                    ls.standard_status
             FROM {$table} sp
             LEFT JOIN {$wpdb->users} u ON sp.client_id = u.ID
             LEFT JOIN {$summary_table} ls ON sp.listing_key = ls.listing_key
             WHERE {$where_sql}
             ORDER BY sp.shared_at DESC
             LIMIT %d OFFSET %d",
            array_merge($params, [$args['limit'], $args['offset']])
        );

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Update client response to a shared property
     *
     * @param int $share_id Share ID
     * @param int $client_id Client user ID (for verification)
     * @param string $response 'interested' or 'not_interested'
     * @param string $note Optional client note
     * @return bool Success
     */
    public static function update_client_response($share_id, $client_id, $response, $note = '') {
        global $wpdb;

        $valid_responses = ['none', 'interested', 'not_interested'];
        if (!in_array($response, $valid_responses)) {
            return false;
        }

        $table = self::get_table_name();

        $result = $wpdb->update(
            $table,
            [
                'client_response' => $response,
                'client_note' => sanitize_textarea_field($note),
                'updated_at' => current_time('mysql')
            ],
            [
                'id' => $share_id,
                'client_id' => $client_id
            ],
            ['%s', '%s', '%s'],
            ['%d', '%d']
        );

        if ($result !== false) {
            do_action('mld_shared_property_response', $share_id, $client_id, $response);
        }

        return $result !== false;
    }

    /**
     * Dismiss a shared property (client action)
     *
     * @param int $share_id Share ID
     * @param int $client_id Client user ID
     * @return bool Success
     */
    public static function dismiss_shared_property($share_id, $client_id) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->update(
            $table,
            ['is_dismissed' => 1, 'updated_at' => current_time('mysql')],
            ['id' => $share_id, 'client_id' => $client_id],
            ['%d', '%s'],
            ['%d', '%d']
        ) !== false;
    }

    /**
     * Revoke a shared property (agent action)
     *
     * @param int $share_id Share ID
     * @param int $agent_id Agent user ID
     * @return bool Success
     */
    public static function revoke_shared_property($share_id, $agent_id) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->delete(
            $table,
            ['id' => $share_id, 'agent_id' => $agent_id],
            ['%d', '%d']
        ) !== false;
    }

    /**
     * Record that client viewed a shared property
     *
     * @param int $share_id Share ID
     * @param int $client_id Client user ID
     * @return bool Success
     */
    public static function record_view($share_id, $client_id) {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET view_count = view_count + 1,
                 viewed_at = COALESCE(viewed_at, NOW()),
                 updated_at = NOW()
             WHERE id = %d AND client_id = %d",
            $share_id, $client_id
        )) !== false;
    }

    /**
     * Get share counts for agent dashboard
     *
     * @param int $agent_id Agent user ID
     * @return array Counts by status
     */
    public static function get_agent_share_stats($agent_id) {
        global $wpdb;

        $table = self::get_table_name();

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_shares,
                COUNT(DISTINCT client_id) as clients_shared_with,
                COUNT(DISTINCT listing_key) as properties_shared,
                SUM(CASE WHEN client_response = 'interested' THEN 1 ELSE 0 END) as interested_count,
                SUM(CASE WHEN client_response = 'not_interested' THEN 1 ELSE 0 END) as not_interested_count,
                SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) as viewed_count,
                SUM(view_count) as total_views
             FROM {$table}
             WHERE agent_id = %d AND is_dismissed = 0",
            $agent_id
        ), ARRAY_A);

        return $stats ?: [
            'total_shares' => 0,
            'clients_shared_with' => 0,
            'properties_shared' => 0,
            'interested_count' => 0,
            'not_interested_count' => 0,
            'viewed_count' => 0,
            'total_views' => 0
        ];
    }

    /**
     * Get count of unviewed shared properties for client
     *
     * @param int $client_id Client user ID
     * @return int Count of unviewed shares
     */
    public static function get_unviewed_count($client_id) {
        global $wpdb;

        $table = self::get_table_name();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE client_id = %d AND viewed_at IS NULL AND is_dismissed = 0",
            $client_id
        ));
    }

    /**
     * Check if agent is assigned to client
     *
     * @param int $agent_id Agent user ID
     * @param int $client_id Client user ID
     * @return bool
     */
    private static function is_agent_for_client($agent_id, $client_id) {
        global $wpdb;

        $relationships_table = $wpdb->prefix . 'mld_agent_client_relationships';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relationships_table}
             WHERE agent_id = %d AND client_id = %d AND is_active = 1",
            $agent_id, $client_id
        ));

        return $result > 0;
    }

    /**
     * Get listing_id from listing_key
     *
     * @param string $listing_key MD5 hash
     * @return string|null MLS listing ID
     */
    private static function get_listing_id_from_key($listing_key) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';

        $listing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT listing_id FROM {$summary_table} WHERE listing_key = %s",
            $listing_key
        ));

        // If not in active listings, check archive
        if (!$listing_id) {
            $archive_table = $wpdb->prefix . 'bme_listing_summary_archive';
            $listing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT listing_id FROM {$archive_table} WHERE listing_key = %s",
                $listing_key
            ));
        }

        return $listing_id;
    }

    /**
     * Format a shared property for API response
     *
     * @param array $row Database row
     * @return array Formatted response
     */
    private static function format_shared_property($row) {
        $address = trim(sprintf('%s %s', $row['street_number'] ?? '', $row['street_name'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'listing_key' => $row['listing_key'],
            'agent_note' => $row['agent_note'],
            'shared_at' => $row['shared_at'],
            'viewed_at' => $row['viewed_at'],
            'view_count' => (int) $row['view_count'],
            'client_response' => $row['client_response'],
            'client_note' => $row['client_note'],
            'is_dismissed' => (bool) $row['is_dismissed'],
            'agent' => [
                'id' => (int) $row['agent_id'],
                'name' => $row['agent_name'],
                'photo_url' => $row['agent_photo'],
                'phone' => $row['agent_phone'],
                'email' => $row['agent_email']
            ],
            'property' => [
                'id' => $row['listing_key'],
                'listing_id' => $row['listing_id'] ?? null,  // MLS number for property URLs
                'listing_key' => $row['listing_key'],        // MD5 hash for API lookups
                'address' => $address,
                'city' => $row['city'],
                'state' => $row['state_or_province'],
                'zip' => $row['postal_code'],
                'price' => (int) ($row['list_price'] ?? 0),
                'beds' => (int) ($row['bedrooms_total'] ?? 0),
                'baths' => (float) ($row['bathrooms_total'] ?? 0),
                'sqft' => (int) ($row['building_area_total'] ?? 0),
                'photo_url' => $row['main_photo_url'],
                'status' => $row['standard_status'],
                'property_type' => $row['property_type'],
                'latitude' => (float) ($row['latitude'] ?? 0),
                'longitude' => (float) ($row['longitude'] ?? 0)
            ]
        ];
    }

    /**
     * Get a single shared property by ID
     *
     * @param int $share_id Share ID
     * @return array|null Shared property data or null
     */
    public static function get_shared_property($share_id) {
        global $wpdb;

        $table = self::get_table_name();
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $agent_profiles = $wpdb->prefix . 'mld_agent_profiles';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT sp.*,
                    ap.display_name as agent_name,
                    ap.photo_url as agent_photo,
                    ap.phone as agent_phone,
                    ap.email as agent_email,
                    ls.listing_id,
                    ls.list_price,
                    ls.street_number,
                    ls.street_name,
                    ls.city,
                    ls.state_or_province,
                    ls.postal_code,
                    ls.bedrooms_total,
                    ls.bathrooms_total,
                    ls.building_area_total,
                    ls.main_photo_url,
                    ls.standard_status,
                    ls.property_type,
                    ls.latitude,
                    ls.longitude
             FROM {$table} sp
             LEFT JOIN {$agent_profiles} ap ON sp.agent_id = ap.user_id
             LEFT JOIN {$summary_table} ls ON sp.listing_key = ls.listing_key
             WHERE sp.id = %d",
            $share_id
        ), ARRAY_A);

        return $row ? self::format_shared_property($row) : null;
    }
}
