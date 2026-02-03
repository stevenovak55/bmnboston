<?php
/**
 * MLS Saved Search Repository
 * 
 * Repository pattern for saved search data access
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
 * Search Repository Class
 * 
 * Handles database operations for saved searches
 */
class MLD_Search_Repository {
    
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
        $this->table_name = $wpdb->prefix . 'mld_saved_searches';
    }
    
    /**
     * Create a new saved search
     * 
     * @param array $data Search data
     * @return int|false Search ID or false on failure
     */
    public function create($data) {
        global $wpdb;
        
        $defaults = [
            'user_id' => 0,
            'name' => '',
            'filters' => '',
            'polygon_shapes' => '',
            'search_url' => '',
            'notification_frequency' => 'never',
            'is_active' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Serialize complex data
        if (is_array($data['filters'])) {
            $data['filters'] = maybe_serialize($data['filters']);
        }
        
        if (is_array($data['polygon_shapes'])) {
            $data['polygon_shapes'] = maybe_serialize($data['polygon_shapes']);
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            $data,
            [
                '%d', // user_id
                '%s', // name
                '%s', // filters
                '%s', // polygon_shapes
                '%s', // search_url
                '%s', // notification_frequency
                '%d', // is_active
                '%s', // created_at
                '%s'  // updated_at
            ]
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get a saved search by ID
     * 
     * @param int $search_id Search ID
     * @return object|null Search object or null
     */
    public function get($search_id) {
        global $wpdb;
        
        $sql = $wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email 
             FROM {$this->table_name} s
             INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.id = %d",
            $search_id
        );
        
        $search = $wpdb->get_row($sql);
        
        if ($search) {
            $search->filters_decoded = maybe_unserialize($search->filters);
            $search->polygon_shapes_decoded = maybe_unserialize($search->polygon_shapes);
        }
        
        return $search;
    }
    
    /**
     * Update a saved search
     * 
     * @param int $search_id Search ID
     * @param array $data Updated data
     * @return bool Success
     */
    public function update($search_id, $data) {
        global $wpdb;
        
        // Add updated timestamp
        $data['updated_at'] = current_time('mysql');
        
        // Serialize complex data
        if (isset($data['filters']) && is_array($data['filters'])) {
            $data['filters'] = maybe_serialize($data['filters']);
        }
        
        if (isset($data['polygon_shapes']) && is_array($data['polygon_shapes'])) {
            $data['polygon_shapes'] = maybe_serialize($data['polygon_shapes']);
        }
        
        // Build format array based on data keys
        $formats = [];
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'user_id':
                case 'is_active':
                case 'notifications_sent':
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
            ['id' => $search_id],
            $formats,
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete a saved search
     * 
     * @param int $search_id Search ID
     * @return bool Success
     */
    public function delete($search_id) {
        global $wpdb;
        
        // Also delete related data
        $this->delete_search_results($search_id);
        
        $result = $wpdb->delete(
            $this->table_name,
            ['id' => $search_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Find searches based on criteria
     * 
     * @param array $args Query arguments
     * @return array Array of search objects
     */
    public function find($args = []) {
        global $wpdb;
        
        $defaults = [
            'user_id' => null,
            'status' => 'all',
            'frequency' => null,
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build WHERE clause
        $where = ['1=1'];
        $values = [];
        
        if ($args['user_id']) {
            $where[] = 's.user_id = %d';
            $values[] = $args['user_id'];
        }
        
        if ($args['status'] !== 'all') {
            if ($args['status'] === 'active') {
                $where[] = 's.is_active = 1';
            } elseif ($args['status'] === 'inactive') {
                $where[] = 's.is_active = 0';
            }
        }
        
        if ($args['frequency']) {
            $where[] = 's.notification_frequency = %s';
            $values[] = $args['frequency'];
        }
        
        if ($args['search']) {
            $where[] = '(s.name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }
        
        // Build ORDER BY clause
        $valid_orderby = ['created_at', 'updated_at', 'name', 'notifications_sent', 'last_notified_at'];
        $orderby = in_array($args['orderby'], $valid_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build query
        $sql = "SELECT s.*, u.display_name, u.user_email 
                FROM {$this->table_name} s
                INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE " . implode(' AND ', $where) . "
                ORDER BY s.{$orderby} {$order}
                LIMIT %d OFFSET %d";
        
        $values[] = $args['limit'];
        $values[] = $args['offset'];
        
        $searches = $wpdb->get_results($wpdb->prepare($sql, $values));
        
        // Decode serialized data
        foreach ($searches as $search) {
            $search->filters_decoded = maybe_unserialize($search->filters);
            $search->polygon_shapes_decoded = maybe_unserialize($search->polygon_shapes);
        }
        
        return $searches;
    }
    
    /**
     * Count searches based on criteria
     * 
     * @param array $args Query arguments
     * @return int Count
     */
    public function count($args = []) {
        global $wpdb;
        
        $defaults = [
            'user_id' => null,
            'status' => 'all',
            'frequency' => null,
            'search' => ''
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Build WHERE clause (same as find method)
        $where = ['1=1'];
        $values = [];
        
        if ($args['user_id']) {
            $where[] = 's.user_id = %d';
            $values[] = $args['user_id'];
        }
        
        if ($args['status'] !== 'all') {
            if ($args['status'] === 'active') {
                $where[] = 's.is_active = 1';
            } elseif ($args['status'] === 'inactive') {
                $where[] = 's.is_active = 0';
            }
        }
        
        if ($args['frequency']) {
            $where[] = 's.notification_frequency = %s';
            $values[] = $args['frequency'];
        }
        
        if ($args['search']) {
            $where[] = '(s.name LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
            $values[] = $search_term;
        }
        
        $sql = "SELECT COUNT(*) 
                FROM {$this->table_name} s
                INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
                WHERE " . implode(' AND ', $where);
        
        if (!empty($values)) {
            return $wpdb->get_var($wpdb->prepare($sql, $values));
        } else {
            return $wpdb->get_var($sql);
        }
    }
    
    /**
     * Check if a search name exists for a user
     * 
     * @param int $user_id User ID
     * @param string $name Search name
     * @param int $exclude_id Optional search ID to exclude
     * @return bool
     */
    public function search_name_exists($user_id, $name, $exclude_id = null) {
        global $wpdb;
        
        $sql = "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE user_id = %d AND name = %s";
        $values = [$user_id, $name];
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $values[] = $exclude_id;
        }
        
        return (bool) $wpdb->get_var($wpdb->prepare($sql, $values));
    }
    
    /**
     * Update last run time for a search
     * 
     * @param int $search_id Search ID
     * @return bool Success
     */
    public function update_last_run($search_id) {
        return $this->update($search_id, [
            'last_run_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Update last notified time for a search
     * 
     * @param int $search_id Search ID
     * @return bool Success
     */
    public function update_last_notified($search_id) {
        global $wpdb;
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
             SET last_notified_at = %s, 
                 notifications_sent = notifications_sent + 1,
                 updated_at = %s
             WHERE id = %d",
            current_time('mysql'),
            current_time('mysql'),
            $search_id
        ));
        
        return $result !== false;
    }
    
    /**
     * Get searches due for notification
     * 
     * @param string $frequency Notification frequency
     * @return array Array of search objects
     */
    public function get_searches_due_for_notification($frequency, $limit = null, $offset = 0) {
        global $wpdb;
        
        // Check cache first
        $cache_key = 'notifications_due_' . $frequency . '_' . $limit . '_' . $offset;
        $cached = MLD_Cache_Manager::get($cache_key);
        
        if (false !== $cached) {
            return $cached;
        }
        
        // Calculate cutoff time based on frequency
        $cutoff_time = $this->get_notification_cutoff($frequency);
        
        if (!$cutoff_time) {
            return [];
        }
        
        $sql = $wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email 
             FROM {$this->table_name} s
             INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.is_active = 1 
             AND s.notification_frequency = %s
             AND (s.last_notified_at IS NULL OR s.last_notified_at < %s)
             ORDER BY s.last_notified_at ASC",
            $frequency,
            $cutoff_time
        );
        
        if ($limit) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }
        
        $searches = $wpdb->get_results($sql);
        
        // Decode serialized data
        foreach ($searches as $search) {
            $search->filters_decoded = maybe_unserialize($search->filters);
            $search->polygon_shapes_decoded = maybe_unserialize($search->polygon_shapes);
        }
        
        // Cache the results for 60 seconds
        MLD_Cache_Manager::set($cache_key, $searches, 60);
        
        return $searches;
    }
    
    /**
     * Get notification cutoff time
     *
     * @param string $frequency Frequency type
     * @return string|null MySQL datetime or null
     */
    private function get_notification_cutoff($frequency) {
        $now = current_time('timestamp');

        switch ($frequency) {
            case 'instant':
                // 5 minutes ago - use wp_date() for consistent WordPress timezone
                return wp_date('Y-m-d H:i:s', $now - 300);

            case 'hourly':
                // 1 hour ago
                return wp_date('Y-m-d H:i:s', $now - 3600);

            case 'daily':
                // 24 hours ago
                return wp_date('Y-m-d H:i:s', $now - 86400);

            case 'weekly':
                // 7 days ago
                return wp_date('Y-m-d H:i:s', $now - 604800);

            default:
                return null;
        }
    }
    
    /**
     * Delete search results for a search
     * 
     * @param int $search_id Search ID
     * @return bool Success
     */
    private function delete_search_results($search_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'mld_saved_search_results';
        
        $result = $wpdb->delete(
            $table,
            ['search_id' => $search_id],
            ['%d']
        );
        
        return $result !== false;
    }
}