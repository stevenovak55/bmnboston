<?php
/**
 * MLS Listings Display - Property Preferences Class
 * 
 * Handles liked and disliked properties for users
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Property_Preferences {
    
    /**
     * Toggle property preference (like/dislike)
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @param string $type Preference type ('liked' or 'disliked')
     * @param array $property_data Optional property data for caching
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function toggle_property($user_id, $listing_id, $type, $property_data = []) {
        global $wpdb;
        
        $user_id = absint($user_id);
        $listing_id = sanitize_text_field($listing_id);
        
        if (!in_array($type, ['liked', 'disliked'])) {
            return new WP_Error('invalid_type', 'Invalid preference type');
        }
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');

        // Check if preference already exists
        // Note: listing_id is VARCHAR(50), not INT, so use %s
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d AND listing_id = %s",
            $user_id,
            $listing_id
        ));
        
        if ($existing) {
            if ($existing->preference_type === $type) {
                // Remove the preference (toggle off)
                $result = $wpdb->delete(
                    $table_name,
                    [
                        'user_id' => $user_id,
                        'listing_id' => $listing_id
                    ]
                );
                
                if ($result === false) {
                    return new WP_Error('db_error', 'Failed to remove preference');
                }
                
                // Clear cache
                self::clear_user_cache($user_id);
                
                do_action('mld_property_preference_removed', $user_id, $listing_id, $type);
                
                return true;
            } else {
                // Update to different preference type
                $result = $wpdb->update(
                    $table_name,
                    ['preference_type' => $type],
                    [
                        'user_id' => $user_id,
                        'listing_id' => $listing_id
                    ]
                );
                
                if ($result === false) {
                    return new WP_Error('db_error', 'Failed to update preference');
                }
                
                // Clear cache
                self::clear_user_cache($user_id);
                
                do_action('mld_property_preference_updated', $user_id, $listing_id, $type);
                
                return true;
            }
        } else {
            // Add new preference
            $listing_key = isset($property_data['listing_key']) ? $property_data['listing_key'] : '';
            
            $result = $wpdb->insert(
                $table_name,
                [
                    'user_id' => $user_id,
                    'listing_id' => $listing_id,
                    'listing_key' => sanitize_text_field($listing_key),
                    'preference_type' => $type
                ]
            );
            
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to add preference');
            }
            
            // Clear cache
            self::clear_user_cache($user_id);
            
            do_action('mld_property_preference_added', $user_id, $listing_id, $type);
            
            return true;
        }
    }
    
    /**
     * Get user preferences
     *
     * @param int $user_id User ID
     * @param string $type Optional preference type filter
     * @return array Array of preferences
     */
    public static function get_user_preferences($user_id, $type = null) {
        global $wpdb;
        
        $user_id = absint($user_id);
        
        // Try to get from cache first
        $cache_key = 'mld_user_preferences_' . $user_id;
        $cached = get_transient($cache_key);
        
        if ($cached !== false && $type === null) {
            return $cached;
        }
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');
        
        $query = "SELECT * FROM $table_name WHERE user_id = %d";
        $query_args = [$user_id];
        
        if ($type && in_array($type, ['liked', 'disliked'])) {
            $query .= " AND preference_type = %s";
            $query_args[] = $type;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $preferences = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Cache the results if getting all preferences
        if ($type === null) {
            set_transient($cache_key, $preferences, HOUR_IN_SECONDS);
        }
        
        return $preferences;
    }
    
    /**
     * Get user's liked properties
     *
     * @param int $user_id User ID
     * @return array Array of liked property IDs
     */
    public static function get_liked_properties($user_id) {
        $preferences = self::get_user_preferences($user_id, 'liked');
        return wp_list_pluck($preferences, 'listing_id');
    }
    
    /**
     * Get user's disliked properties
     *
     * @param int $user_id User ID
     * @return array Array of disliked property IDs
     */
    public static function get_disliked_properties($user_id) {
        $preferences = self::get_user_preferences($user_id, 'disliked');
        return wp_list_pluck($preferences, 'listing_id');
    }
    
    /**
     * Remove preference
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function remove_preference($user_id, $listing_id) {
        global $wpdb;
        
        $user_id = absint($user_id);
        $listing_id = sanitize_text_field($listing_id);
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');
        
        $result = $wpdb->delete(
            $table_name,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id
            ]
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to remove preference');
        }
        
        // Clear cache
        self::clear_user_cache($user_id);
        
        do_action('mld_property_preference_removed', $user_id, $listing_id, null);
        
        return true;
    }
    
    /**
     * Get preference statistics for a user
     *
     * @param int $user_id User ID
     * @return array Statistics array
     */
    public static function get_preference_stats($user_id) {
        global $wpdb;
        
        $user_id = absint($user_id);
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT preference_type, COUNT(*) as count 
             FROM $table_name 
             WHERE user_id = %d 
             GROUP BY preference_type",
            $user_id
        ), OBJECT_K);
        
        return [
            'liked' => isset($stats['liked']) ? (int)$stats['liked']->count : 0,
            'disliked' => isset($stats['disliked']) ? (int)$stats['disliked']->count : 0,
            'total' => (isset($stats['liked']) ? (int)$stats['liked']->count : 0) + 
                      (isset($stats['disliked']) ? (int)$stats['disliked']->count : 0)
        ];
    }
    
    /**
     * Bulk update preferences
     *
     * @param int $user_id User ID
     * @param array $listing_ids Array of listing IDs
     * @param string $type Preference type
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function bulk_update_preferences($user_id, $listing_ids, $type) {
        global $wpdb;
        
        $user_id = absint($user_id);
        
        if (!in_array($type, ['liked', 'disliked', 'remove'])) {
            return new WP_Error('invalid_type', 'Invalid preference type');
        }
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($listing_ids as $listing_id) {
                $listing_id = sanitize_text_field($listing_id);
                
                if ($type === 'remove') {
                    $wpdb->delete(
                        $table_name,
                        [
                            'user_id' => $user_id,
                            'listing_id' => $listing_id
                        ]
                    );
                } else {
                    // Use INSERT ... ON DUPLICATE KEY UPDATE
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO $table_name (user_id, listing_id, preference_type) 
                         VALUES (%d, %s, %s) 
                         ON DUPLICATE KEY UPDATE preference_type = %s",
                        $user_id,
                        $listing_id,
                        $type,
                        $type
                    ));
                }
            }
            
            $wpdb->query('COMMIT');
            
            // Clear cache
            self::clear_user_cache($user_id);
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Failed to update preferences: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if property is liked by user
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return bool
     */
    public static function is_property_liked($user_id, $listing_id) {
        $liked_properties = self::get_liked_properties($user_id);
        return in_array($listing_id, $liked_properties);
    }
    
    /**
     * Check if property is disliked by user
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return bool
     */
    public static function is_property_disliked($user_id, $listing_id) {
        $disliked_properties = self::get_disliked_properties($user_id);
        return in_array($listing_id, $disliked_properties);
    }
    
    /**
     * Get preference type for a property
     *
     * @param int $user_id User ID
     * @param string $listing_id Listing ID
     * @return string|null 'liked', 'disliked', or null
     */
    public static function get_property_preference($user_id, $listing_id) {
        global $wpdb;
        
        $user_id = absint($user_id);
        $listing_id = sanitize_text_field($listing_id);
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');

        // Note: listing_id is VARCHAR(50), not INT, so use %s
        $preference = $wpdb->get_var($wpdb->prepare(
            "SELECT preference_type FROM $table_name WHERE user_id = %d AND listing_id = %s",
            $user_id,
            $listing_id
        ));

        return $preference;
    }
    
    /**
     * Clear user preference cache
     *
     * @param int $user_id User ID
     */
    private static function clear_user_cache($user_id) {
        delete_transient('mld_user_preferences_' . $user_id);
    }
    
    /**
     * Get preferences for multiple users (admin use)
     *
     * @param array $user_ids Array of user IDs
     * @param string $type Optional preference type filter
     * @return array Grouped by user ID
     */
    public static function get_preferences_for_users($user_ids, $type = null) {
        global $wpdb;
        
        if (empty($user_ids)) {
            return [];
        }
        
        $user_ids = array_map('absint', $user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        
        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');
        
        $query = "SELECT * FROM $table_name WHERE user_id IN ($placeholders)";
        $query_args = $user_ids;
        
        if ($type && in_array($type, ['liked', 'disliked'])) {
            $query .= " AND preference_type = %s";
            $query_args[] = $type;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $preferences = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Group by user ID
        $grouped = [];
        foreach ($preferences as $pref) {
            if (!isset($grouped[$pref->user_id])) {
                $grouped[$pref->user_id] = [];
            }
            $grouped[$pref->user_id][] = $pref;
        }
        
        return $grouped;
    }

    /**
     * Get preference statistics for multiple users in a single query
     *
     * PERFORMANCE: Replaces N+1 query pattern with single batch query
     * Used by agent/clients and agent/metrics endpoints
     *
     * @since 6.54.3
     * @param array $user_ids Array of user IDs
     * @return array Associative array keyed by user_id with 'liked' and 'disliked' counts
     */
    public static function get_preference_stats_batch($user_ids) {
        global $wpdb;

        if (empty($user_ids)) {
            return [];
        }

        $user_ids = array_map('absint', $user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

        $table_name = MLD_Saved_Search_Database::get_table_name('property_preferences');

        // Single query to get counts for all users grouped by user and type
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, preference_type, COUNT(*) as count
             FROM $table_name
             WHERE user_id IN ($placeholders)
             GROUP BY user_id, preference_type",
            $user_ids
        ));

        // Initialize all users with zero counts
        $stats = [];
        foreach ($user_ids as $user_id) {
            $stats[$user_id] = [
                'liked' => 0,
                'disliked' => 0
            ];
        }

        // Populate with actual counts
        foreach ($results as $row) {
            $user_id = (int) $row->user_id;
            $type = $row->preference_type;
            if (isset($stats[$user_id]) && in_array($type, ['liked', 'disliked'])) {
                $stats[$user_id][$type] = (int) $row->count;
            }
        }

        return $stats;
    }
}