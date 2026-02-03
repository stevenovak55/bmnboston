<?php
/**
 * MLS Saved Search Service
 * 
 * Service layer for saved search business logic
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
 * Search Service Class
 * 
 * Handles business logic for saved searches, separating concerns from data access
 */
class MLD_Search_Service {
    
    /**
     * Repository instance
     * 
     * @var MLD_Search_Repository
     */
    private $repository;
    
    /**
     * Notification service instance
     * 
     * @var MLD_Notification_Service
     */
    private $notification_service;
    
    /**
     * Constructor
     * 
     * @param MLD_Search_Repository $repository Search repository
     * @param MLD_Notification_Service $notification_service Notification service
     */
    public function __construct($repository = null, $notification_service = null) {
        $this->repository = $repository ?: new MLD_Search_Repository();
        $this->notification_service = $notification_service ?: new MLD_Notification_Service();
    }
    
    /**
     * Create a new saved search
     * 
     * @param array $data Search data
     * @return int|WP_Error Search ID or error
     */
    public function create_search($data) {
        // Validate required fields
        if (empty($data['user_id']) || empty($data['name'])) {
            return new WP_Error('missing_fields', 'User ID and name are required');
        }
        
        // Sanitize data
        $clean_data = $this->sanitize_search_data($data);
        
        // Check for duplicate names for this user
        if ($this->repository->search_name_exists($clean_data['user_id'], $clean_data['name'])) {
            return new WP_Error('duplicate_name', 'A search with this name already exists');
        }
        
        // Create the search
        $search_id = $this->repository->create($clean_data);
        
        if (!$search_id) {
            return new WP_Error('creation_failed', 'Failed to create search');
        }
        
        // Clear user cache
        MLD_Cache_Manager::clear_user_cache($clean_data['user_id']);
        
        // Log the creation
        do_action('mld_saved_search_created', $search_id, $clean_data);
        
        return $search_id;
    }
    
    /**
     * Update a saved search
     * 
     * @param int $search_id Search ID
     * @param array $data Updated data
     * @return bool|WP_Error Success or error
     */
    public function update_search($search_id, $data) {
        // Get existing search
        $search = $this->repository->get($search_id);
        
        if (!$search) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Check permissions
        if (!$this->can_user_edit_search($search->user_id, get_current_user_id())) {
            return new WP_Error('permission_denied', 'You cannot edit this search');
        }
        
        // Sanitize data
        $clean_data = $this->sanitize_search_data($data);
        
        // Update the search
        $result = $this->repository->update($search_id, $clean_data);
        
        if (!$result) {
            return new WP_Error('update_failed', 'Failed to update search');
        }
        
        // Clear caches
        MLD_Cache_Manager::clear_search_cache($search_id);
        MLD_Cache_Manager::clear_user_cache($search->user_id);
        
        // Log the update
        do_action('mld_saved_search_updated', $search_id, $clean_data);
        
        return true;
    }
    
    /**
     * Delete a saved search
     * 
     * @param int $search_id Search ID
     * @return bool|WP_Error Success or error
     */
    public function delete_search($search_id) {
        // Get existing search
        $search = $this->repository->get($search_id);
        
        if (!$search) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Check permissions
        if (!$this->can_user_edit_search($search->user_id, get_current_user_id())) {
            return new WP_Error('permission_denied', 'You cannot delete this search');
        }
        
        // Delete the search
        $result = $this->repository->delete($search_id);
        
        if (!$result) {
            return new WP_Error('deletion_failed', 'Failed to delete search');
        }
        
        // Clear caches
        MLD_Cache_Manager::clear_search_cache($search_id);
        MLD_Cache_Manager::clear_user_cache($search->user_id);
        
        // Log the deletion
        do_action('mld_saved_search_deleted', $search_id, $search);
        
        return true;
    }
    
    /**
     * Get user's saved searches
     * 
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array Searches array
     */
    public function get_user_searches($user_id, $args = []) {
        $defaults = [
            'status' => 'all',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        $args['user_id'] = $user_id;
        
        // Try to get from cache first
        $cache_key = MLD_Cache_Manager::get_user_key($user_id, 'searches_' . md5(serialize($args)));
        $cached = MLD_Cache_Manager::get($cache_key);
        
        if (false !== $cached) {
            return $cached;
        }
        
        // Get from database
        $searches = $this->repository->find($args);
        
        // Cache the results
        MLD_Cache_Manager::set($cache_key, $searches, 300); // 5 minutes
        
        return $searches;
    }
    
    /**
     * Run a saved search
     * 
     * @param int $search_id Search ID
     * @return array|WP_Error Properties array or error
     */
    public function run_search($search_id) {
        // Try to get from cache first
        $cached_results = MLD_Cache_Manager::get_cached_search_results($search_id);
        if (false !== $cached_results) {
            return $cached_results;
        }
        
        $search = $this->repository->get($search_id);
        
        if (!$search) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Check if user can run this search
        if (!$this->can_user_run_search($search->user_id, get_current_user_id())) {
            return new WP_Error('permission_denied', 'You cannot run this search');
        }
        
        // Decode filters
        $filters = maybe_unserialize($search->filters);
        $polygon_shapes = maybe_unserialize($search->polygon_shapes);
        
        // Build query arguments
        $query_args = $this->build_query_args($filters, $polygon_shapes);
        
        // Get properties
        $properties = MLD_Query::get_map_properties($query_args);
        
        // Update last run time
        $this->repository->update_last_run($search_id);
        
        // Cache the results
        MLD_Cache_Manager::cache_search_results($search_id, $properties, 300); // 5 minutes
        
        return $properties;
    }
    
    /**
     * Send test notification for a search
     * 
     * @param int $search_id Search ID
     * @return bool|WP_Error Success or error
     */
    public function send_test_notification($search_id) {
        $search = $this->repository->get($search_id);
        
        if (!$search) {
            return new WP_Error('not_found', 'Search not found');
        }
        
        // Get some sample properties
        $properties = $this->run_search($search_id);
        
        if (is_wp_error($properties)) {
            return $properties;
        }
        
        if (empty($properties)) {
            return new WP_Error('no_properties', 'No properties found for this search');
        }
        
        // Send test notification with first 3 properties
        $test_properties = array_slice($properties, 0, 3);
        
        return $this->notification_service->send_test_notification($search, $test_properties);
    }
    
    /**
     * Check if user can edit a search
     * 
     * @param int $search_user_id Search owner ID
     * @param int $current_user_id Current user ID
     * @return bool
     */
    private function can_user_edit_search($search_user_id, $current_user_id) {
        // User can edit their own searches
        if ($search_user_id == $current_user_id) {
            return true;
        }
        
        // Admins can edit any search
        if (current_user_can('manage_options')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if user can run a search
     * 
     * @param int $search_user_id Search owner ID
     * @param int $current_user_id Current user ID
     * @return bool
     */
    private function can_user_run_search($search_user_id, $current_user_id) {
        // Same permissions as edit for now
        return $this->can_user_edit_search($search_user_id, $current_user_id);
    }
    
    /**
     * Sanitize search data
     * 
     * @param array $data Raw data
     * @return array Sanitized data
     */
    private function sanitize_search_data($data) {
        $clean = [];
        
        if (isset($data['user_id'])) {
            $clean['user_id'] = absint($data['user_id']);
        }
        
        if (isset($data['name'])) {
            $clean['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['filters'])) {
            $clean['filters'] = $this->sanitize_filters($data['filters']);
        }
        
        if (isset($data['polygon_shapes'])) {
            $clean['polygon_shapes'] = $this->sanitize_polygon_shapes($data['polygon_shapes']);
        }
        
        if (isset($data['notification_frequency'])) {
            $valid_frequencies = ['never', 'instant', 'daily', 'weekly'];
            $clean['notification_frequency'] = in_array($data['notification_frequency'], $valid_frequencies) 
                ? $data['notification_frequency'] 
                : 'never';
        }
        
        if (isset($data['is_active'])) {
            $clean['is_active'] = (int) $data['is_active'];
        }
        
        return $clean;
    }
    
    /**
     * Sanitize search filters
     * 
     * @param array $filters Raw filters
     * @return array Sanitized filters
     */
    private function sanitize_filters($filters) {
        if (!is_array($filters)) {
            return [];
        }
        
        $clean = [];
        
        // Sanitize each filter type
        if (isset($filters['city'])) {
            $clean['city'] = sanitize_text_field($filters['city']);
        }
        
        if (isset($filters['min_price'])) {
            $clean['min_price'] = absint($filters['min_price']);
        }
        
        if (isset($filters['max_price'])) {
            $clean['max_price'] = absint($filters['max_price']);
        }
        
        if (isset($filters['beds'])) {
            $clean['beds'] = absint($filters['beds']);
        }
        
        if (isset($filters['baths'])) {
            $clean['baths'] = absint($filters['baths']);
        }
        
        if (isset($filters['property_type'])) {
            $clean['property_type'] = sanitize_text_field($filters['property_type']);
        }
        
        if (isset($filters['square_feet'])) {
            $clean['square_feet'] = absint($filters['square_feet']);
        }
        
        return $clean;
    }
    
    /**
     * Sanitize polygon shapes
     * 
     * @param array $shapes Raw shapes
     * @return array Sanitized shapes
     */
    private function sanitize_polygon_shapes($shapes) {
        if (!is_array($shapes)) {
            return [];
        }
        
        $clean = [];
        
        foreach ($shapes as $shape) {
            if (is_array($shape) && !empty($shape)) {
                $clean_shape = [];
                foreach ($shape as $point) {
                    if (isset($point['lat']) && isset($point['lng'])) {
                        $clean_shape[] = [
                            'lat' => (float) $point['lat'],
                            'lng' => (float) $point['lng']
                        ];
                    }
                }
                if (!empty($clean_shape)) {
                    $clean[] = $clean_shape;
                }
            }
        }
        
        return $clean;
    }
    
    /**
     * Build query arguments from filters and shapes
     * 
     * @param array $filters Search filters
     * @param array $polygon_shapes Polygon shapes
     * @return array Query arguments
     */
    private function build_query_args($filters, $polygon_shapes) {
        $args = [];
        
        // Add filters
        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                if (!empty($value)) {
                    $args[$key] = $value;
                }
            }
        }
        
        // Add polygon shapes
        if (!empty($polygon_shapes)) {
            $args['polygon_shapes'] = $polygon_shapes;
        }
        
        // Always get active properties for saved searches
        if (!isset($args['status'])) {
            $args['status'] = ['Active'];
        }
        
        return $args;
    }
}