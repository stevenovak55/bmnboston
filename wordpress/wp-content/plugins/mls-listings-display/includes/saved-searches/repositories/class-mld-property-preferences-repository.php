<?php
/**
 * MLS Property Preferences Repository
 * 
 * Repository pattern for property preferences data access
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Property Preferences Repository Class
 * 
 * Handles database operations for property preferences (saved, liked, disliked)
 */
class MLD_Property_Preferences_Repository {
    
    /**
     * Table name
     * 
     * @var string
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mld_property_preferences';
    }
    
    /**
     * Save a property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @param array $property_data Optional property data to store
     * @return bool Success
     */
    public function save_property($user_id, $listing_id, $property_data = []) {
        global $wpdb;
        
        // Check if already exists
        $exists = $this->get_preference($user_id, $listing_id);
        
        if ($exists) {
            // Update existing record
            return $this->update_preference($user_id, $listing_id, [
                'is_saved' => 1,
                'saved_at' => current_time('mysql'),
                'property_data' => maybe_serialize($property_data)
            ]);
        }
        
        // Insert new record
        $result = $wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id,
                'is_saved' => 1,
                'saved_at' => current_time('mysql'),
                'property_data' => maybe_serialize($property_data),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Remove a saved property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool Success
     */
    public function remove_saved_property($user_id, $listing_id) {
        global $wpdb;
        
        // Check if has other preferences
        $preference = $this->get_preference($user_id, $listing_id);
        
        if (!$preference) {
            return false;
        }
        
        if ($preference->preference_type || $preference->notes) {
            // Keep record but remove saved status
            return $this->update_preference($user_id, $listing_id, [
                'is_saved' => 0,
                'saved_at' => null
            ]);
        } else {
            // Delete record entirely
            return $this->delete_preference($user_id, $listing_id);
        }
    }
    
    /**
     * Set property preference (liked/disliked)
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @param string $preference_type Preference type (liked/disliked)
     * @return bool Success
     */
    public function set_property_preference($user_id, $listing_id, $preference_type) {
        global $wpdb;
        
        // Validate preference type
        if (!in_array($preference_type, ['liked', 'disliked'])) {
            return false;
        }
        
        // Check if already exists
        $exists = $this->get_preference($user_id, $listing_id);
        
        if ($exists) {
            // Update existing record
            return $this->update_preference($user_id, $listing_id, [
                'preference_type' => $preference_type,
                'preference_date' => current_time('mysql')
            ]);
        }
        
        // Insert new record
        $result = $wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id,
                'preference_type' => $preference_type,
                'preference_date' => current_time('mysql'),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Get saved properties for a user
     * 
     * @param int $user_id User ID
     * @param int $limit Optional limit
     * @param int $offset Optional offset
     * @return array Array of saved property records
     */
    public function get_saved_properties($user_id, $limit = null, $offset = 0) {
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE user_id = %d AND is_saved = 1 
             ORDER BY saved_at DESC",
            $user_id
        );
        
        if ($limit) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }
        
        $results = $wpdb->get_results($sql);
        
        // Unserialize property data
        foreach ($results as $result) {
            if ($result->property_data) {
                $result->property_data = maybe_unserialize($result->property_data);
            }
        }
        
        return $results;
    }
    
    /**
     * Get property preferences for a user
     * 
     * @param int $user_id User ID
     * @param string $preference_type Optional type filter
     * @return array Array of preference records
     */
    public function get_property_preferences($user_id, $preference_type = null) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name} 
                WHERE user_id = %d AND preference_type IS NOT NULL";
        $values = [$user_id];
        
        if ($preference_type) {
            $sql .= " AND preference_type = %s";
            $values[] = $preference_type;
        }
        
        $sql .= " ORDER BY preference_date DESC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
    
    /**
     * Check if property is saved
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool
     */
    public function is_property_saved($user_id, $listing_id) {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT is_saved FROM {$this->table_name} 
             WHERE user_id = %d AND listing_id = %d",
            $user_id,
            $listing_id
        ));
        
        return (bool) $result;
    }
    
    /**
     * Get property preference
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return object|null Preference record or null
     */
    public function get_property_preference($user_id, $listing_id) {
        return $this->get_preference($user_id, $listing_id);
    }
    
    /**
     * Update property notes
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @param string $notes Notes text
     * @return bool Success
     */
    public function update_property_notes($user_id, $listing_id, $notes) {
        // Check if exists
        $exists = $this->get_preference($user_id, $listing_id);
        
        if ($exists) {
            // Update existing record
            return $this->update_preference($user_id, $listing_id, [
                'notes' => $notes,
                'notes_updated_at' => current_time('mysql')
            ]);
        }
        
        // Insert new record
        global $wpdb;
        $result = $wpdb->insert(
            $this->table_name,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id,
                'notes' => $notes,
                'notes_updated_at' => current_time('mysql'),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Count saved properties
     * 
     * @param int $user_id User ID
     * @return int Count
     */
    public function count_saved_properties($user_id) {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE user_id = %d AND is_saved = 1",
            $user_id
        ));
    }
    
    /**
     * Count property preferences
     * 
     * @param int $user_id User ID
     * @param string $preference_type Optional type filter
     * @return int Count
     */
    public function count_property_preferences($user_id, $preference_type = null) {
        global $wpdb;
        
        $sql = "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE user_id = %d AND preference_type IS NOT NULL";
        $values = [$user_id];
        
        if ($preference_type) {
            $sql .= " AND preference_type = %s";
            $values[] = $preference_type;
        }
        
        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }
    
    /**
     * Get preference record
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return object|null Record or null
     */
    private function get_preference($user_id, $listing_id) {
        global $wpdb;
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE user_id = %d AND listing_id = %d",
            $user_id,
            $listing_id
        ));
        
        if ($result && $result->property_data) {
            $result->property_data = maybe_unserialize($result->property_data);
        }
        
        return $result;
    }
    
    /**
     * Update preference record
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @param array $data Data to update
     * @return bool Success
     */
    private function update_preference($user_id, $listing_id, $data) {
        global $wpdb;
        
        // Build format array
        $formats = [];
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'user_id':
                case 'is_saved':
                    $formats[] = '%d';
                    break;
                default:
                    $formats[] = '%s';
                    break;
            }
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $data,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id
            ],
            $formats,
            ['%d', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete preference record
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool Success
     */
    private function delete_preference($user_id, $listing_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            [
                'user_id' => $user_id,
                'listing_id' => $listing_id
            ],
            ['%d', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Cleanup old unsaved preferences
     * 
     * @param int $days Days to keep
     * @return int Number of deleted records
     */
    public function cleanup_old_preferences($days = 90) {
        global $wpdb;
        
        // Use wp_date() for consistent WordPress timezone formatting
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        
        // Delete records that are not saved and have no preferences or notes
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE is_saved = 0 
             AND preference_type IS NULL 
             AND (notes IS NULL OR notes = '')
             AND created_at < %s",
            $cutoff
        ));
        
        return $deleted;
    }
}