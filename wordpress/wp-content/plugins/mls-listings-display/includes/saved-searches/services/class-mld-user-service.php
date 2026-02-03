<?php
/**
 * MLS User Service
 * 
 * Service layer for user-related business logic
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
 * User Service Class
 * 
 * Handles user preferences and property interactions
 */
class MLD_User_Service {
    
    /**
     * Property preferences repository
     * 
     * @var MLD_Property_Preferences_Repository
     */
    private $preferences_repository;
    
    /**
     * Constructor
     * 
     * @param MLD_Property_Preferences_Repository $repository Preferences repository
     */
    public function __construct($repository = null) {
        $this->preferences_repository = $repository ?: new MLD_Property_Preferences_Repository();
    }
    
    /**
     * Save a property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool|WP_Error Success or error
     */
    public function save_property($user_id, $listing_id) {
        // Check if already saved
        if ($this->is_property_saved($user_id, $listing_id)) {
            return new WP_Error('already_saved', 'Property is already saved');
        }
        
        // Get property data to store with preference
        $property_data = $this->get_property_basic_data($listing_id);
        
        if (!$property_data) {
            return new WP_Error('property_not_found', 'Property not found');
        }
        
        // Save the property
        $result = $this->preferences_repository->save_property($user_id, $listing_id, $property_data);
        
        if ($result) {
            do_action('mld_property_saved', $user_id, $listing_id);
        }
        
        return $result;
    }
    
    /**
     * Remove a saved property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool|WP_Error Success or error
     */
    public function remove_saved_property($user_id, $listing_id) {
        // Check if property is saved
        if (!$this->is_property_saved($user_id, $listing_id)) {
            return new WP_Error('not_saved', 'Property is not saved');
        }
        
        // Remove the property
        $result = $this->preferences_repository->remove_saved_property($user_id, $listing_id);
        
        if ($result) {
            do_action('mld_property_removed', $user_id, $listing_id);
        }
        
        return $result;
    }
    
    /**
     * Like a property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool|WP_Error Success or error
     */
    public function like_property($user_id, $listing_id) {
        // Set preference to liked
        $result = $this->preferences_repository->set_property_preference($user_id, $listing_id, 'liked');
        
        if ($result) {
            do_action('mld_property_liked', $user_id, $listing_id);
        }
        
        return $result;
    }
    
    /**
     * Dislike a property
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool|WP_Error Success or error
     */
    public function dislike_property($user_id, $listing_id) {
        // Set preference to disliked
        $result = $this->preferences_repository->set_property_preference($user_id, $listing_id, 'disliked');
        
        if ($result) {
            do_action('mld_property_disliked', $user_id, $listing_id);
        }
        
        return $result;
    }
    
    /**
     * Get user's saved properties
     * 
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array Properties array
     */
    public function get_saved_properties($user_id, $args = []) {
        $saved_listings = $this->preferences_repository->get_saved_properties($user_id);
        
        if (empty($saved_listings)) {
            return [];
        }
        
        // Get full property data
        $listing_ids = array_column($saved_listings, 'listing_id');
        $properties = $this->get_properties_by_ids($listing_ids);
        
        // Merge with saved data
        $result = [];
        foreach ($properties as $property) {
            $saved_data = $this->find_saved_data($saved_listings, $property['listing_id']);
            if ($saved_data) {
                $property['saved_at'] = $saved_data->saved_at;
                $property['notes'] = $saved_data->notes;
                $result[] = $property;
            }
        }
        
        return $result;
    }
    
    /**
     * Get user's property preferences
     * 
     * @param int $user_id User ID
     * @param string $preference_type Optional type filter (liked/disliked)
     * @return array Preferences array
     */
    public function get_property_preferences($user_id, $preference_type = null) {
        return $this->preferences_repository->get_property_preferences($user_id, $preference_type);
    }
    
    /**
     * Check if property is saved
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return bool
     */
    public function is_property_saved($user_id, $listing_id) {
        return $this->preferences_repository->is_property_saved($user_id, $listing_id);
    }
    
    /**
     * Get property preference
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @return string|null Preference type or null
     */
    public function get_property_preference($user_id, $listing_id) {
        $preference = $this->preferences_repository->get_property_preference($user_id, $listing_id);
        return $preference ? $preference->preference_type : null;
    }
    
    /**
     * Update saved property notes
     * 
     * @param int $user_id User ID
     * @param string $listing_id Property listing ID
     * @param string $notes Notes text
     * @return bool Success
     */
    public function update_property_notes($user_id, $listing_id, $notes) {
        return $this->preferences_repository->update_property_notes($user_id, $listing_id, $notes);
    }
    
    /**
     * Get user statistics
     * 
     * @param int $user_id User ID
     * @return array Statistics array
     */
    public function get_user_statistics($user_id) {
        $stats = [
            'saved_properties' => $this->preferences_repository->count_saved_properties($user_id),
            'liked_properties' => $this->preferences_repository->count_property_preferences($user_id, 'liked'),
            'disliked_properties' => $this->preferences_repository->count_property_preferences($user_id, 'disliked'),
            'saved_searches' => $this->count_user_searches($user_id),
            'active_searches' => $this->count_active_searches($user_id)
        ];
        
        return $stats;
    }
    
    /**
     * Get property basic data
     * 
     * @param string $listing_id Property listing ID
     * @return array|null Property data or null
     */
    private function get_property_basic_data($listing_id) {
        $properties = MLD_Query::get_map_properties(['listing_id' => $listing_id]);
        
        if (empty($properties)) {
            return null;
        }
        
        $property = $properties[0];
        
        // Extract basic data to store
        return [
            'ListPrice' => $property['ListPrice'] ?? null,
            'full_address' => $property['full_address'] ?? null,
            'BedroomsTotal' => $property['BedroomsTotal'] ?? null,
            'BathroomsTotalInteger' => $property['BathroomsTotalInteger'] ?? null,
            'LivingArea' => $property['LivingArea'] ?? null,
            'featured_image_url' => $property['featured_image_url'] ?? null
        ];
    }
    
    /**
     * Get properties by IDs
     * 
     * @param array $listing_ids Array of listing IDs
     * @return array Properties array
     */
    private function get_properties_by_ids($listing_ids) {
        if (empty($listing_ids)) {
            return [];
        }
        
        // Query properties
        return MLD_Query::get_map_properties(['listing_ids' => $listing_ids]);
    }
    
    /**
     * Find saved data for a listing
     * 
     * @param array $saved_listings Saved listings array
     * @param string $listing_id Listing ID
     * @return object|null Saved data or null
     */
    private function find_saved_data($saved_listings, $listing_id) {
        foreach ($saved_listings as $saved) {
            if ($saved->listing_id === $listing_id) {
                return $saved;
            }
        }
        return null;
    }
    
    /**
     * Count user searches
     * 
     * @param int $user_id User ID
     * @return int Count
     */
    private function count_user_searches($user_id) {
        $search_repo = new MLD_Search_Repository();
        return $search_repo->count(['user_id' => $user_id]);
    }
    
    /**
     * Count active searches
     * 
     * @param int $user_id User ID
     * @return int Count
     */
    private function count_active_searches($user_id) {
        $search_repo = new MLD_Search_Repository();
        return $search_repo->count(['user_id' => $user_id, 'status' => 'active']);
    }
}