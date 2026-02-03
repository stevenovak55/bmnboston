<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized activity logging system for comprehensive plugin activity tracking
 * Version: 1.1.0 - Added batch queueing for improved performance (5-10% faster extractions)
 */
class BME_Activity_Logger {

    private $db_manager;
    private $activity_table;

    /**
     * Queue for batch activity logging
     * @since 1.1.0
     */
    private $queue = [];

    /**
     * Number of entries to accumulate before auto-flushing
     * @since 1.1.0
     */
    private $flush_threshold = 50;

    /**
     * Whether batch mode is enabled (default: true during extractions)
     * @since 1.1.0
     */
    private $batch_mode = false;
    
    // Activity types
    const TYPE_LISTING = 'listing';
    const TYPE_AGENT = 'agent'; 
    const TYPE_OFFICE = 'office';
    const TYPE_OPEN_HOUSE = 'open_house';
    const TYPE_VIRTUAL_TOUR = 'virtual_tour';
    const TYPE_MEDIA = 'media';
    const TYPE_SYSTEM = 'system';
    const TYPE_API = 'api';
    const TYPE_CRON = 'cron';
    const TYPE_EXTRACTION = 'extraction';
    const TYPE_BATCH = 'batch';
    
    // Activity actions
    const ACTION_IMPORTED = 'imported';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';
    const ACTION_STATUS_CHANGED = 'status_changed';
    const ACTION_PRICE_CHANGED = 'price_changed';
    const ACTION_TABLE_MOVED = 'table_moved';
    const ACTION_STARTED = 'started';
    const ACTION_COMPLETED = 'completed';
    const ACTION_FAILED = 'failed';
    const ACTION_ERROR = 'error';

    // Open house specific actions
    const ACTION_OPEN_HOUSE_ADDED = 'open_house_added';
    const ACTION_OPEN_HOUSE_REMOVED = 'open_house_removed';
    const ACTION_OPEN_HOUSE_RESCHEDULED = 'open_house_rescheduled';
    const ACTION_OPEN_HOUSE_UPDATED = 'open_house_updated';
    
    // Severity levels
    const SEVERITY_INFO = 'info';
    const SEVERITY_SUCCESS = 'success';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR = 'error';
    const SEVERITY_CRITICAL = 'critical';
    
    public function __construct(BME_Database_Manager $db_manager) {
        $this->db_manager = $db_manager;
        $this->activity_table = $this->db_manager->get_table('activity_logs');
    }

    /**
     * Destructor - ensure any remaining queued items are flushed
     * @since 1.1.0
     */
    public function __destruct() {
        $this->flush_queue();
    }

    /**
     * Enable batch mode for high-volume operations (e.g., extractions)
     * When enabled, activity logs are queued and batch-inserted for better performance
     *
     * @param int $threshold Optional flush threshold (default 50)
     * @since 1.1.0
     */
    public function enable_batch_mode($threshold = 50) {
        $this->batch_mode = true;
        $this->flush_threshold = max(10, min(200, $threshold)); // Clamp between 10-200
    }

    /**
     * Disable batch mode and flush remaining queue
     * @since 1.1.0
     */
    public function disable_batch_mode() {
        $this->flush_queue();
        $this->batch_mode = false;
    }

    /**
     * Flush the activity log queue to the database
     * Uses batch INSERT for better performance
     *
     * @return int Number of records inserted
     * @since 1.1.0
     */
    public function flush_queue() {
        if (empty($this->queue)) {
            return 0;
        }

        global $wpdb;

        // Build batch INSERT query
        $columns = [
            'activity_type', 'action', 'entity_type', 'entity_id', 'mls_id',
            'listing_key', 'extraction_id', 'title', 'description', 'details',
            'old_values', 'new_values', 'severity', 'user_id', 'ip_address',
            'related_ids', 'created_at'
        ];

        $placeholders = [];
        $values = [];

        foreach ($this->queue as $activity) {
            $row_placeholders = [];
            foreach ($columns as $col) {
                $value = $activity[$col] ?? null;
                $values[] = $value;
                $row_placeholders[] = '%s';
            }
            $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
        }

        $column_list = implode(', ', $columns);
        $sql = "INSERT INTO {$this->activity_table} ({$column_list}) VALUES " . implode(', ', $placeholders);

        // Execute batch insert
        $result = $wpdb->query($wpdb->prepare($sql, $values));

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BME Activity Logger: Batch insert failed - ' . $wpdb->last_error);
            }
            // Fall back to individual inserts
            $inserted = 0;
            foreach ($this->queue as $activity) {
                if ($wpdb->insert($this->activity_table, $activity) !== false) {
                    $inserted++;
                }
            }
            $this->queue = [];
            return $inserted;
        }

        $count = count($this->queue);
        $this->queue = [];
        return $count;
    }

    /**
     * Get the current queue size
     * @return int
     * @since 1.1.0
     */
    public function get_queue_size() {
        return count($this->queue);
    }

    /**
     * Log activity with comprehensive details
     */
    public function log_activity($activity_type, $action, $options = []) {
        global $wpdb;
        
        $defaults = [
            'entity_type' => null,
            'entity_id' => null,
            'mls_id' => null,
            'listing_key' => null,
            'extraction_id' => null,
            'title' => '',
            'description' => '',
            'details' => null,
            'old_values' => null,
            'new_values' => null,
            'severity' => self::SEVERITY_INFO,
            'user_id' => get_current_user_id() ?: null,
            'ip_address' => $this->get_user_ip(),
            'related_ids' => null
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // Auto-generate title if not provided
        if (empty($options['title'])) {
            $options['title'] = $this->generate_activity_title($activity_type, $action, $options);
        }
        
        // Auto-generate description if not provided
        if (empty($options['description'])) {
            $options['description'] = $this->generate_activity_description($activity_type, $action, $options);
        }
        
        // Ensure severity is set (safety check)
        if (empty($options['severity'])) {
            $options['severity'] = self::SEVERITY_INFO;
        }
        
        // Prepare data for database insertion
        $activity_data = [
            'activity_type' => $activity_type,
            'action' => $action,
            'entity_type' => $options['entity_type'],
            'entity_id' => $options['entity_id'],
            'mls_id' => $options['mls_id'],
            'listing_key' => $options['listing_key'],
            'extraction_id' => $options['extraction_id'],
            'title' => $options['title'],
            'description' => $options['description'],
            'details' => is_array($options['details']) || is_object($options['details']) 
                        ? json_encode($options['details']) : $options['details'],
            'old_values' => is_array($options['old_values']) || is_object($options['old_values']) 
                           ? json_encode($options['old_values']) : $options['old_values'],
            'new_values' => is_array($options['new_values']) || is_object($options['new_values']) 
                           ? json_encode($options['new_values']) : $options['new_values'],
            'severity' => $options['severity'],
            'user_id' => $options['user_id'],
            'ip_address' => $options['ip_address'],
            'related_ids' => is_array($options['related_ids']) 
                           ? json_encode($options['related_ids']) : $options['related_ids'],
            'created_at' => current_time('mysql')
        ];

        // If batch mode is enabled, queue the activity instead of immediate insert
        if ($this->batch_mode) {
            $this->queue[] = $activity_data;

            // Auto-flush when queue reaches threshold
            if (count($this->queue) >= $this->flush_threshold) {
                $this->flush_queue();
            }

            // Return a placeholder ID (actual IDs assigned on flush)
            return true;
        }

        // Immediate insert (non-batch mode)
        $result = $wpdb->insert($this->activity_table, $activity_data);

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BME Activity Logger: Failed to insert activity - ' . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }
    
    /**
     * Log listing activity (import, update, status change, etc.)
     */
    public function log_listing_activity($action, $listing_data, $options = []) {
        // Extract comprehensive property details
        $property_details = [
            'address' => $this->extract_address($listing_data),
            'property_type' => $listing_data['PropertyType'] ?? $listing_data['property_type'] ?? null,
            'property_sub_type' => $listing_data['PropertySubType'] ?? $listing_data['property_sub_type'] ?? null,
            'status' => $listing_data['StandardStatus'] ?? $listing_data['standard_status'] ?? null,
            'price' => $listing_data['ListPrice'] ?? $listing_data['list_price'] ?? null,
            'original_price' => $listing_data['OriginalListPrice'] ?? $listing_data['original_list_price'] ?? null,
            'close_price' => $listing_data['ClosePrice'] ?? $listing_data['close_price'] ?? null,
            'bedrooms' => $listing_data['BedroomsTotal'] ?? $listing_data['bedrooms_total'] ?? null,
            'bathrooms' => $listing_data['BathroomsTotalInteger'] ?? $listing_data['bathrooms_total'] ?? null,
            'living_area' => $listing_data['LivingArea'] ?? $listing_data['living_area'] ?? null,
            'lot_size' => $listing_data['LotSizeAcres'] ?? $listing_data['lot_size_acres'] ?? null,
            'year_built' => $listing_data['YearBuilt'] ?? $listing_data['year_built'] ?? null,
            'days_on_market' => $listing_data['DaysOnMarket'] ?? $listing_data['days_on_market'] ?? null,
            'agent_id' => $listing_data['ListAgentMlsId'] ?? $listing_data['list_agent_mls_id'] ?? null,
            'agent_name' => $listing_data['ListAgentFullName'] ?? $listing_data['list_agent_full_name'] ?? null,
            'office_id' => $listing_data['ListOfficeMlsId'] ?? $listing_data['list_office_mls_id'] ?? null,
            'office_name' => $listing_data['ListOfficeName'] ?? $listing_data['list_office_name'] ?? null,
            'listing_date' => $listing_data['ListingContractDate'] ?? $listing_data['listing_contract_date'] ?? null,
            'modification_timestamp' => $listing_data['ModificationTimestamp'] ?? $listing_data['modification_timestamp'] ?? null
        ];

        // Clean up null values from details
        $property_details = array_filter($property_details, function($value) {
            return $value !== null && $value !== '';
        });

        $defaults = [
            'entity_type' => 'listing',
            'entity_id' => $listing_data['listing_key'] ?? $listing_data['ListingKey'] ?? $listing_data['listing_id'] ?? null,
            'mls_id' => $listing_data['ListingId'] ?? $listing_data['listing_id'] ?? $listing_data['mls_id'] ?? $listing_data['ListingKey'] ?? null,
            'listing_key' => $listing_data['listing_key'] ?? $listing_data['ListingKey'] ?? null,
            'details' => $property_details
        ];

        $options = wp_parse_args($options, $defaults);

        // Set appropriate severity based on action
        if (empty($options['severity'])) {
            $options['severity'] = ($action === self::ACTION_FAILED || $action === self::ACTION_ERROR)
                                 ? self::SEVERITY_ERROR : self::SEVERITY_INFO;
        }

        return $this->log_activity(self::TYPE_LISTING, $action, $options);
    }
    
    /**
     * Log property status change with detailed tracking
     */
    public function log_status_change($listing_data, $old_status, $new_status, $options = []) {
        $options['old_values'] = ['status' => $old_status];
        $options['new_values'] = ['status' => $new_status];
        $options['severity'] = self::SEVERITY_INFO;
        $options['details'] = array_merge($options['details'] ?? [], [
            'status_change' => [
                'from' => $old_status,
                'to' => $new_status,
                'created_at' => $listing_data['status_change_timestamp'] ?? current_time('mysql')
            ]
        ]);
        
        return $this->log_listing_activity(self::ACTION_STATUS_CHANGED, $listing_data, $options);
    }
    
    /**
     * Log property price change with calculations
     */
    public function log_price_change($listing_data, $old_price, $new_price, $options = []) {
        $price_change = $new_price - $old_price;
        $price_change_percent = $old_price > 0 ? round(($price_change / $old_price) * 100, 2) : 0;
        
        $options['old_values'] = ['price' => $old_price];
        $options['new_values'] = ['price' => $new_price];
        $options['severity'] = self::SEVERITY_INFO;
        $options['details'] = array_merge($options['details'] ?? [], [
            'price_change' => [
                'old_price' => $old_price,
                'new_price' => $new_price,
                'change_amount' => $price_change,
                'change_percent' => $price_change_percent,
                'living_area' => $listing_data['living_area'] ?? null
            ]
        ]);
        
        return $this->log_listing_activity(self::ACTION_PRICE_CHANGED, $listing_data, $options);
    }
    
    /**
     * Compare two data arrays and return only the fields that changed
     * This provides detailed field-level change tracking for activity logs
     */
    public function get_field_level_changes($old_data, $new_data, $important_fields = []) {
        $changes = [
            'changed_fields' => [],
            'old_values' => [],
            'new_values' => []
        ];

        // Define commonly important fields to track
        $default_important_fields = [
            'list_price', 'ListPrice',
            'standard_status', 'StandardStatus',
            'street_number', 'StreetNumber',
            'street_name', 'StreetName',
            'city', 'City',
            'state', 'StateOrProvince',
            'postal_code', 'PostalCode',
            'property_type', 'PropertyType',
            'bedrooms_total', 'BedroomsTotal',
            'bathrooms_total', 'BathroomsTotal',
            'living_area', 'LivingArea',
            'lot_size_acres', 'LotSizeAcres',
            'year_built', 'YearBuilt',
            'modification_timestamp', 'ModificationTimestamp',
            'close_date', 'CloseDate',
            'list_agent_mls_id', 'ListAgentMlsId',
            'list_office_mls_id', 'ListOfficeMlsId'
        ];

        // If no specific fields provided, check all fields from both datasets
        if (empty($important_fields)) {
            // Get all unique fields from both old and new data
            $all_fields = array_unique(array_merge(array_keys($old_data), array_keys($new_data)));
            $fields_to_check = $all_fields;
        } else {
            $fields_to_check = $important_fields;
        }

        // Fields to exclude from comparison (database-only fields)
        $excluded_fields = [
            'id', 'Id', 'ID',  // Database record ID
            'created_at', 'created',  // Database creation timestamp
            'updated_at', 'updated',  // Database update timestamp
            'deleted_at',  // Soft delete timestamp
            'extraction_id',  // Internal extraction ID
            'session_id',  // Internal session ID
            'batch_id',  // Internal batch ID
            'import_source',  // Internal metadata - not from API, preserved by DB default
            'property_timezone',  // Derived field - not from API, preserved by DB default
            'last_imported_at',  // Internal timestamp - not from API
        ];

        // Fields that should not show as changed when going from value to null
        // (these are often just missing from API response but not actually changed)
        $skip_null_transition_fields = [
            'virtual_tour_url_unbranded',
            'virtual_tour_url_branded',
            'days_on_market'  // Often calculated separately
        ];

        foreach ($fields_to_check as $field) {
            // Skip excluded database-only fields
            if (in_array($field, $excluded_fields)) {
                continue;
            }

            $old_value = $old_data[$field] ?? null;
            $new_value = $new_data[$field] ?? null;

            // Skip if both values are null/empty
            if (empty($old_value) && empty($new_value)) {
                continue;
            }

            // Special handling for fields that shouldn't show as changed when going to null
            if (in_array($field, $skip_null_transition_fields) && $new_value === null && $old_value !== null) {
                continue;  // Skip this field - it's just missing from API response
            }

            // Special handling for days_on_market field
            // If new value is null but we have mlspin_market_time_property, skip this field
            if ($field === 'days_on_market' && $new_value === null && isset($new_data['mlspin_market_time_property'])) {
                continue;
            }

            // Compare values (handle different data types and date normalization)
            if ($this->values_are_different($old_value, $new_value)) {
                $changes['changed_fields'][] = $field;
                $changes['old_values'][$field] = $old_value;
                $changes['new_values'][$field] = $new_value;
            }
        }

        return $changes;
    }
    
    /**
     * Helper method to compare values accounting for different data types
     */
    private function values_are_different($old_value, $new_value) {
        // Handle null values
        if ($old_value === null && $new_value === null) {
            return false;
        }
        if ($old_value === null || $new_value === null) {
            return true;
        }

        // Handle numeric values (convert to float for comparison)
        if (is_numeric($old_value) && is_numeric($new_value)) {
            return floatval($old_value) !== floatval($new_value);
        }

        // Handle date/timestamp comparison (normalize formats)
        if (is_string($old_value) && is_string($new_value)) {
            $normalized_old = $this->normalize_date_value($old_value);
            $normalized_new = $this->normalize_date_value($new_value);

            // If both values were successfully normalized as dates, compare them
            if ($normalized_old !== false && $normalized_new !== false) {
                return $normalized_old !== $normalized_new;
            }

            // Otherwise, handle as regular string comparison (trim whitespace)
            return trim($old_value) !== trim($new_value);
        }

        // Default comparison
        return $old_value !== $new_value;
    }

    /**
     * Normalize date values for comparison
     * Returns standardized date string or false if not a date
     */
    private function normalize_date_value($value) {
        if (empty($value) || !is_string($value)) {
            return false;
        }

        // List of common date patterns to try
        $date_patterns = [
            // ISO 8601 with timezone
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?$/',
            // MySQL datetime
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            // Date only
            '/^\d{4}-\d{2}-\d{2}$/',
            // Date with time
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{3})?$/'
        ];

        // Check if value matches any date pattern
        $is_date = false;
        foreach ($date_patterns as $pattern) {
            if (preg_match($pattern, trim($value))) {
                $is_date = true;
                break;
            }
        }

        if (!$is_date) {
            return false;
        }

        try {
            // Try to parse the date
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return false;
            }

            // For date-only values (no time component), normalize to date only
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
                return date('Y-m-d', $timestamp);
            }

            // Check if this is a date with midnight time (00:00:00)
            // These should be treated as date-only values
            if (preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00/', trim($value)) ||
                preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00/', trim($value))) {
                return date('Y-m-d', $timestamp);
            }

            // For datetime values, normalize to MySQL datetime format
            return date('Y-m-d H:i:s', $timestamp);

        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Enhanced listing update logging with field-level changes
     */
    public function log_listing_update_with_changes($listing_data, $old_data, $new_data, $options = []) {
        $changes = $this->get_field_level_changes($old_data, $new_data);

        if (empty($changes['changed_fields'])) {
            // No changes detected, don't log
            return false;
        }

        // Check for price changes and log them separately
        $price_fields = ['list_price', 'ListPrice'];
        $price_changed = false;

        foreach ($price_fields as $price_field) {
            if (in_array($price_field, $changes['changed_fields'])) {
                $old_price = $changes['old_values'][$price_field] ?? 0;
                $new_price = $changes['new_values'][$price_field] ?? 0;

                // Ensure we have valid numeric prices
                if (is_numeric($old_price) && is_numeric($new_price) && $old_price != $new_price) {
                    $this->log_price_change($listing_data, floatval($old_price), floatval($new_price), $options);
                    $price_changed = true;
                    break;
                }
            }
        }

        // Set the old and new values to only the changed fields
        $options['old_values'] = $changes['old_values'];
        $options['new_values'] = $changes['new_values'];

        // Add change summary to details
        $options['details'] = array_merge($options['details'] ?? [], [
            'changed_fields' => $changes['changed_fields'],
            'change_count' => count($changes['changed_fields']),
            'change_summary' => $this->generate_change_summary($changes),
            'price_changed' => $price_changed
        ]);

        return $this->log_listing_activity(self::ACTION_UPDATED, $listing_data, $options);
    }
    
    /**
     * Generate a human-readable change summary
     */
    private function generate_change_summary($changes) {
        $summaries = [];
        
        foreach ($changes['changed_fields'] as $field) {
            $old_val = $changes['old_values'][$field] ?? 'N/A';
            $new_val = $changes['new_values'][$field] ?? 'N/A';
            
            // Create user-friendly field names
            $friendly_field = $this->get_friendly_field_name($field);
            
            // Special formatting for certain fields
            if (in_array($field, ['list_price', 'ListPrice']) && is_numeric($old_val) && is_numeric($new_val)) {
                $summaries[] = "{$friendly_field}: $" . number_format($old_val) . " → $" . number_format($new_val);
            } else {
                $summaries[] = "{$friendly_field}: {$old_val} → {$new_val}";
            }
        }
        
        return $summaries;
    }
    
    /**
     * Convert technical field names to user-friendly labels
     */
    private function get_friendly_field_name($field) {
        $field_names = [
            'list_price' => 'List Price',
            'ListPrice' => 'List Price',
            'standard_status' => 'Status',
            'StandardStatus' => 'Status',
            'street_number' => 'Street Number',
            'StreetNumber' => 'Street Number',
            'street_name' => 'Street Name',
            'StreetName' => 'Street Name',
            'city' => 'City',
            'City' => 'City',
            'state' => 'State',
            'StateOrProvince' => 'State',
            'postal_code' => 'Postal Code',
            'PostalCode' => 'Postal Code',
            'property_type' => 'Property Type',
            'PropertyType' => 'Property Type',
            'bedrooms_total' => 'Bedrooms',
            'BedroomsTotal' => 'Bedrooms',
            'bathrooms_total' => 'Bathrooms',
            'BathroomsTotal' => 'Bathrooms',
            'living_area' => 'Living Area',
            'LivingArea' => 'Living Area',
            'lot_size_acres' => 'Lot Size',
            'LotSizeAcres' => 'Lot Size',
            'year_built' => 'Year Built',
            'YearBuilt' => 'Year Built',
            'modification_timestamp' => 'Modified',
            'ModificationTimestamp' => 'Modified',
            'close_date' => 'Close Date',
            'CloseDate' => 'Close Date',
            'list_agent_mls_id' => 'Listing Agent',
            'ListAgentMlsId' => 'Listing Agent',
            'list_office_mls_id' => 'Listing Office',
            'ListOfficeMlsId' => 'Listing Office'
        ];
        
        return $field_names[$field] ?? ucfirst(str_replace(['_', 'Mls'], [' ', ' MLS'], $field));
    }
    
    /**
     * Log table movement (active to archive or vice versa)
     */
    public function log_table_movement($listing_data, $from_table, $to_table, $reason, $options = []) {
        $options['old_values'] = ['table' => $from_table];
        $options['new_values'] = ['table' => $to_table];
        $options['severity'] = self::SEVERITY_INFO;
        $options['details'] = array_merge($options['details'] ?? [], [
            'table_movement' => [
                'from' => $from_table,
                'to' => $to_table,
                'reason' => $reason
            ]
        ]);
        
        return $this->log_listing_activity(self::ACTION_TABLE_MOVED, $listing_data, $options);
    }
    
    /**
     * Log agent-related activities
     */
    public function log_agent_activity($action, $agent_data, $options = []) {
        $defaults = [
            'entity_type' => 'agent',
            'entity_id' => $agent_data['agent_mls_id'] ?? $agent_data['id'] ?? null,
            'details' => [
                'agent_name' => ($agent_data['first_name'] ?? '') . ' ' . ($agent_data['last_name'] ?? ''),
                'agent_id' => $agent_data['agent_mls_id'] ?? null,
                'office_id' => $agent_data['office_mls_id'] ?? null,
                'email' => $agent_data['email'] ?? null
            ]
        ];
        
        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_AGENT, $action, $options);
    }
    
    /**
     * Log office-related activities  
     */
    public function log_office_activity($action, $office_data, $options = []) {
        $defaults = [
            'entity_type' => 'office',
            'entity_id' => $office_data['office_mls_id'] ?? $office_data['id'] ?? null,
            'details' => [
                'office_name' => $office_data['office_name'] ?? null,
                'office_id' => $office_data['office_mls_id'] ?? null,
                'phone' => $office_data['office_phone'] ?? null
            ]
        ];
        
        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_OFFICE, $action, $options);
    }
    
    /**
     * Log open house-related activities
     */
    public function log_open_house_activity($action, $listing_data, $open_house_data = [], $options = []) {
        $listing_address = $this->extract_address($listing_data);
        $listing_id = $listing_data['listing_id'] ?? $listing_data['ListingId'] ?? null;

        $defaults = [
            'entity_type' => 'open_house',
            'entity_id' => $listing_id,
            'listing_id' => $listing_id,
            'title' => "Open House: {$listing_address}",
            'details' => array_merge([
                'listing_id' => $listing_id,
                'address' => $listing_address,
                'open_house_data' => $open_house_data
            ], $open_house_data)
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_LISTING, $action, $options);
    }

    /**
     * Log when open house is added to a listing
     */
    public function log_open_house_added($listing_data, $open_house_data, $options = []) {
        $open_house_time = '';
        if (!empty($open_house_data['open_house_start_time']) && !empty($open_house_data['open_house_end_time'])) {
            $start = date('g:i A', strtotime($open_house_data['open_house_start_time']));
            $end = date('g:i A', strtotime($open_house_data['open_house_end_time']));
            $open_house_time = " ({$start} - {$end})";
        }

        $date = !empty($open_house_data['open_house_date']) ?
                date('M j, Y', strtotime($open_house_data['open_house_date'])) :
                'Date TBD';

        $defaults = [
            'description' => "New open house scheduled for {$date}{$open_house_time}",
            'details' => [
                'action_type' => 'open_house_added',
                'open_house_date' => $open_house_data['open_house_date'] ?? null,
                'start_time' => $open_house_data['open_house_start_time'] ?? null,
                'end_time' => $open_house_data['open_house_end_time'] ?? null,
                'open_house_type' => $open_house_data['open_house_type'] ?? 'Public',
                'remarks' => $open_house_data['open_house_remarks'] ?? null
            ]
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_open_house_activity(self::ACTION_OPEN_HOUSE_ADDED, $listing_data, $open_house_data, $options);
    }

    /**
     * Log when open house is removed from a listing
     */
    public function log_open_house_removed($listing_data, $open_house_data, $options = []) {
        $open_house_time = '';
        if (!empty($open_house_data['open_house_start_time']) && !empty($open_house_data['open_house_end_time'])) {
            $start = date('g:i A', strtotime($open_house_data['open_house_start_time']));
            $end = date('g:i A', strtotime($open_house_data['open_house_end_time']));
            $open_house_time = " ({$start} - {$end})";
        }

        $date = !empty($open_house_data['open_house_date']) ?
                date('M j, Y', strtotime($open_house_data['open_house_date'])) :
                'Unknown date';

        $defaults = [
            'description' => "Open house cancelled/removed for {$date}{$open_house_time}",
            'details' => [
                'action_type' => 'open_house_removed',
                'open_house_date' => $open_house_data['open_house_date'] ?? null,
                'start_time' => $open_house_data['open_house_start_time'] ?? null,
                'end_time' => $open_house_data['open_house_end_time'] ?? null,
                'removal_reason' => $options['removal_reason'] ?? 'Not specified'
            ]
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_open_house_activity(self::ACTION_OPEN_HOUSE_REMOVED, $listing_data, $open_house_data, $options);
    }

    /**
     * Log when open house time/date is changed
     */
    public function log_open_house_rescheduled($listing_data, $old_data, $new_data, $options = []) {
        $old_date = !empty($old_data['open_house_date']) ?
                    date('M j, Y', strtotime($old_data['open_house_date'])) :
                    'Unknown';
        $new_date = !empty($new_data['open_house_date']) ?
                    date('M j, Y', strtotime($new_data['open_house_date'])) :
                    'Unknown';

        $old_time = '';
        if (!empty($old_data['open_house_start_time']) && !empty($old_data['open_house_end_time'])) {
            $start = date('g:i A', strtotime($old_data['open_house_start_time']));
            $end = date('g:i A', strtotime($old_data['open_house_end_time']));
            $old_time = " ({$start} - {$end})";
        }

        $new_time = '';
        if (!empty($new_data['open_house_start_time']) && !empty($new_data['open_house_end_time'])) {
            $start = date('g:i A', strtotime($new_data['open_house_start_time']));
            $end = date('g:i A', strtotime($new_data['open_house_end_time']));
            $new_time = " ({$start} - {$end})";
        }

        $defaults = [
            'description' => "Open house rescheduled from {$old_date}{$old_time} to {$new_date}{$new_time}",
            'details' => [
                'action_type' => 'open_house_rescheduled',
                'old_date' => $old_data['open_house_date'] ?? null,
                'new_date' => $new_data['open_house_date'] ?? null,
                'old_start_time' => $old_data['open_house_start_time'] ?? null,
                'new_start_time' => $new_data['open_house_start_time'] ?? null,
                'old_end_time' => $old_data['open_house_end_time'] ?? null,
                'new_end_time' => $new_data['open_house_end_time'] ?? null,
                'change_details' => $this->identify_open_house_changes($old_data, $new_data)
            ]
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_open_house_activity(self::ACTION_OPEN_HOUSE_RESCHEDULED, $listing_data, $new_data, $options);
    }

    /**
     * Log other open house updates (type, remarks, etc.)
     */
    public function log_open_house_updated($listing_data, $old_data, $new_data, $options = []) {
        $changes = $this->identify_open_house_changes($old_data, $new_data);
        $change_summary = $this->format_open_house_change_summary($changes);

        $date = !empty($new_data['open_house_date']) ?
                date('M j, Y', strtotime($new_data['open_house_date'])) :
                'Date TBD';

        $defaults = [
            'description' => "Open house updated for {$date}: {$change_summary}",
            'details' => [
                'action_type' => 'open_house_updated',
                'open_house_date' => $new_data['open_house_date'] ?? null,
                'changes' => $changes,
                'changed_fields' => array_keys($changes)
            ]
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_open_house_activity(self::ACTION_OPEN_HOUSE_UPDATED, $listing_data, $new_data, $options);
    }

    /**
     * Identify specific changes between old and new open house data
     */
    private function identify_open_house_changes($old_data, $new_data) {
        $changes = [];

        $fields_to_track = [
            'open_house_date' => 'Date',
            'open_house_start_time' => 'Start Time',
            'open_house_end_time' => 'End Time',
            'open_house_type' => 'Type',
            'open_house_remarks' => 'Remarks',
            'open_house_method' => 'Method',
            'appointment_call' => 'Appointment Phone',
            'appointment_call_comment' => 'Appointment Instructions'
        ];

        foreach ($fields_to_track as $field => $label) {
            $old_value = $old_data[$field] ?? null;
            $new_value = $new_data[$field] ?? null;

            // Normalize for comparison
            $old_normalized = $this->normalize_date_value($old_value);
            $new_normalized = $this->normalize_date_value($new_value);

            if ($old_normalized !== $new_normalized) {
                $changes[$field] = [
                    'field' => $label,
                    'old_value' => $old_value,
                    'new_value' => $new_value
                ];
            }
        }

        return $changes;
    }

    /**
     * Format open house changes into readable summary
     */
    private function format_open_house_change_summary($changes) {
        if (empty($changes)) {
            return 'No significant changes';
        }

        $summaries = [];
        foreach ($changes as $field => $change) {
            $label = $change['field'];
            $old = $change['old_value'] ?: 'None';
            $new = $change['new_value'] ?: 'None';

            // Special formatting for specific fields
            if (strpos($field, 'time') !== false && $new !== 'None') {
                $new = date('g:i A', strtotime($new));
                if ($old !== 'None') {
                    $old = date('g:i A', strtotime($old));
                }
            } elseif ($field === 'open_house_date' && $new !== 'None') {
                $new = date('M j, Y', strtotime($new));
                if ($old !== 'None') {
                    $old = date('M j, Y', strtotime($old));
                }
            }

            $summaries[] = "{$label}: {$old} → {$new}";
        }

        return implode(', ', $summaries);
    }

    /**
     * System-level activities (cron, batch processing, etc.)
     */
    public function log_system_activity($action, $component, $message, $options = []) {
        $defaults = [
            'entity_type' => 'system',
            'entity_id' => $component,
            'title' => "System: {$component}",
            'description' => $message,
            'details' => [
                'component' => $component,
                'message' => $message
            ]
        ];

        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_SYSTEM, $action, $options);
    }
    
    /**
     * Log extraction-related activities
     */
    public function log_extraction_activity($action, $extraction_id, $details, $options = []) {
        $extraction_title = get_the_title($extraction_id) ?: "Extraction #{$extraction_id}";
        
        $defaults = [
            'entity_type' => 'extraction',
            'entity_id' => $extraction_id,
            'extraction_id' => $extraction_id,
            'title' => "Extraction: {$extraction_title}",
            'details' => $details
        ];
        
        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_EXTRACTION, $action, $options);
    }
    
    /**
     * Log API request activities for tracking usage
     */
    public function log_api_activity($action, $endpoint, $details, $options = []) {
        $defaults = [
            'entity_type' => 'api',
            'entity_id' => $endpoint,
            'details' => array_merge([
                'endpoint' => $endpoint,
                'created_at' => current_time('mysql')
            ], $details)
        ];
        
        $options = wp_parse_args($options, $defaults);
        return $this->log_activity(self::TYPE_API, $action, $options);
    }
    
    /**
     * Get activities with search and filtering
     */
    public function get_activities($params = []) {
        global $wpdb;
        
        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'activity_type' => null,
            'action' => null,
            'severity' => null,
            'mls_id' => null,
            'search' => null,
            'date_from' => null,
            'date_to' => null,
            'extraction_id' => null,
            'order_by' => 'timestamp',
            'order' => 'DESC'
        ];
        
        $params = wp_parse_args($params, $defaults);
        
        $where_conditions = ['1=1'];
        $where_values = [];
        
        // Filter by activity type
        if ($params['activity_type']) {
            $where_conditions[] = 'activity_type = %s';
            $where_values[] = $params['activity_type'];
        }
        
        // Filter by action
        if ($params['action']) {
            $where_conditions[] = 'action = %s';
            $where_values[] = $params['action'];
        }
        
        // Filter by severity
        if ($params['severity']) {
            $where_conditions[] = 'severity = %s';
            $where_values[] = $params['severity'];
        }
        
        // Filter by MLS ID
        if ($params['mls_id']) {
            $where_conditions[] = 'mls_id = %s';
            $where_values[] = $params['mls_id'];
        }
        
        // Filter by extraction ID
        if ($params['extraction_id']) {
            $where_conditions[] = 'extraction_id = %d';
            $where_values[] = $params['extraction_id'];
        }
        
        // Search functionality
        if ($params['search']) {
            $search_term = '%' . $wpdb->esc_like($params['search']) . '%';
            $where_conditions[] = '(title LIKE %s OR description LIKE %s OR mls_id LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Date range filtering
        if ($params['date_from']) {
            $where_conditions[] = 'created_at >= %s';
            $where_values[] = $params['date_from'];
        }
        
        if ($params['date_to']) {
            $where_conditions[] = 'created_at <= %s';
            $where_values[] = $params['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        // Fix order_by to use created_at instead of timestamp
        $order_by = ($params['order_by'] === 'timestamp') ? 'created_at' : $params['order_by'];
        $order_clause = sprintf('%s %s', $order_by, $params['order']);
        
        $sql = "SELECT * FROM {$this->activity_table} 
                WHERE {$where_clause} 
                ORDER BY {$order_clause} 
                LIMIT %d OFFSET %d";
        
        $where_values[] = $params['limit'];
        $where_values[] = $params['offset'];
        
        if (empty($where_values)) {
            return $wpdb->get_results($sql, ARRAY_A);
        }
        
        return $wpdb->get_results($wpdb->prepare($sql, $where_values), ARRAY_A);
    }
    
    /**
     * Get activity count for pagination
     */
    public function get_activity_count($params = []) {
        global $wpdb;
        
        $where_conditions = ['1=1'];
        $where_values = [];
        
        // Apply same filters as get_activities (without limit/offset)
        if (!empty($params['activity_type'])) {
            $where_conditions[] = 'activity_type = %s';
            $where_values[] = $params['activity_type'];
        }
        
        if (!empty($params['search'])) {
            $search_term = '%' . $wpdb->esc_like($params['search']) . '%';
            $where_conditions[] = '(title LIKE %s OR description LIKE %s OR mls_id LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Add other filters as needed...
        
        $where_clause = implode(' AND ', $where_conditions);
        $sql = "SELECT COUNT(*) FROM {$this->activity_table} WHERE {$where_clause}";
        
        if (empty($where_values)) {
            return (int) $wpdb->get_var($sql);
        }
        
        return (int) $wpdb->get_var($wpdb->prepare($sql, $where_values));
    }
    
    /**
     * Search activities by MLS ID with autocomplete support
     */
    public function search_by_mls_id($mls_id, $exact_match = false) {
        global $wpdb;
        
        if ($exact_match) {
            $where_condition = 'mls_id = %s';
            $search_value = $mls_id;
        } else {
            $where_condition = 'mls_id LIKE %s';
            $search_value = '%' . $wpdb->esc_like($mls_id) . '%';
        }
        
        $sql = "SELECT * FROM {$this->activity_table} 
                WHERE {$where_condition} 
                ORDER BY created_at DESC 
                LIMIT 100";
        
        return $wpdb->get_results($wpdb->prepare($sql, $search_value), ARRAY_A);
    }
    
    /**
     * Get activity statistics for dashboard
     */
    public function get_activity_stats($days = 7) {
        global $wpdb;
        
        $since_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = [
            'total_activities' => 0,
            'listings_imported' => 0,
            'listings_updated' => 0,
            'price_changes' => 0,
            'status_changes' => 0,
            'table_moves' => 0,
            'errors' => 0,
            'activity_by_type' => [],
            'activity_by_day' => []
        ];
        
        // Total activities
        $stats['total_activities'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->activity_table} WHERE created_at >= %s",
            $since_date
        ));
        
        // Activity breakdown by type and action
        $breakdown = $wpdb->get_results($wpdb->prepare(
            "SELECT activity_type, action, severity, COUNT(*) as count
             FROM {$this->activity_table}
             WHERE created_at >= %s
             GROUP BY activity_type, action, severity
             ORDER BY count DESC",
            $since_date
        ), ARRAY_A);

        foreach ($breakdown as $item) {
            $key = $item['activity_type'] . '_' . $item['action'];
            $stats['activity_by_type'][$key] = (int) $item['count'];

            // Specific counters
            if ($item['activity_type'] === 'listing' && $item['action'] === 'imported') {
                $stats['listings_imported'] = (int) $item['count'];
            }
            if ($item['activity_type'] === 'listing' && $item['action'] === 'updated') {
                $stats['listings_updated'] = (int) $item['count'];
            }
            if ($item['activity_type'] === 'listing' && $item['action'] === 'price_changed') {
                $stats['price_changes'] = (int) $item['count'];
            }
            if ($item['activity_type'] === 'listing' && $item['action'] === 'status_changed') {
                $stats['status_changes'] = (int) $item['count'];
            }
            if ($item['activity_type'] === 'listing' && $item['action'] === 'table_moved') {
                $stats['table_moves'] = (int) $item['count'];
            }
            if (isset($item['severity']) && $item['severity'] === 'error' || $item['action'] === 'failed') {
                $stats['errors'] += (int) $item['count'];
            }
        }
        
        return $stats;
    }
    
    /**
     * Clean up old activity logs
     */
    public function cleanup_old_activities($days_to_keep = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->activity_table} WHERE created_at < %s",
            $cutoff_date
        ));
        
        return $deleted;
    }
    
    /**
     * Helper: Generate activity title automatically
     */
    private function generate_activity_title($activity_type, $action, $options) {
        $title_parts = [];
        
        switch ($activity_type) {
            case self::TYPE_LISTING:
                // Try to get address from details first, fallback to MLS ID
                $address = '';
                if (!empty($options['details']['address'])) {
                    $address = $options['details']['address'];
                    $title_parts[] = $address;
                } else if ($options['mls_id']) {
                    $title_parts[] = "Listing #{$options['mls_id']}";
                } else {
                    $title_parts[] = 'Listing';
                }
                break;
            case self::TYPE_AGENT:
                // Try to get agent name from details first, fallback to agent ID
                if (!empty($options['details']['agent_name']) && trim($options['details']['agent_name'])) {
                    $title_parts[] = 'Agent ' . trim($options['details']['agent_name']);
                } else if ($options['entity_id']) {
                    $title_parts[] = "Agent #{$options['entity_id']}";
                } else {
                    $title_parts[] = 'Agent';
                }
                break;
            case self::TYPE_OFFICE:
                // Try to get office name from details first, fallback to office ID
                if (!empty($options['details']['office_name']) && trim($options['details']['office_name'])) {
                    $title_parts[] = 'Office ' . trim($options['details']['office_name']);
                } else if ($options['entity_id']) {
                    $title_parts[] = "Office #{$options['entity_id']}";
                } else {
                    $title_parts[] = 'Office';
                }
                break;
            case self::TYPE_EXTRACTION:
                $title_parts[] = 'Extraction';
                if ($options['extraction_id']) {
                    $extraction_title = get_the_title($options['extraction_id']);
                    $title_parts[] = $extraction_title ?: "#{$options['extraction_id']}";
                }
                break;
            default:
                $title_parts[] = ucfirst($activity_type);
        }
        
        $title_parts[] = ucfirst(str_replace('_', ' ', $action));
        
        return implode(' - ', $title_parts);
    }
    
    /**
     * Helper: Generate activity description automatically
     */
    private function generate_activity_description($activity_type, $action, $options) {
        // Generate more detailed descriptions based on available data
        if ($activity_type === self::TYPE_LISTING && !empty($options['details'])) {
            $details = $options['details'];

            switch ($action) {
                case self::ACTION_IMPORTED:
                    $desc_parts = ['New listing imported from MLS'];
                    if (!empty($details['bedrooms']) && !empty($details['bathrooms'])) {
                        $desc_parts[] = sprintf('%s bed, %s bath', $details['bedrooms'], $details['bathrooms']);
                    }
                    if (!empty($details['living_area'])) {
                        $desc_parts[] = sprintf('%s sq ft', number_format($details['living_area']));
                    }
                    if (!empty($details['property_type'])) {
                        $desc_parts[] = $details['property_type'];
                    }
                    return implode(' - ', $desc_parts);

                case self::ACTION_UPDATED:
                    $desc = 'Listing data updated from MLS';
                    if (!empty($options['old_values']) && !empty($options['new_values'])) {
                        $changes = is_string($options['new_values']) ? json_decode($options['new_values'], true) : $options['new_values'];
                        if (is_array($changes)) {
                            $desc .= sprintf(' (%d fields changed)', count($changes));
                        }
                    }
                    return $desc;

                case self::ACTION_STATUS_CHANGED:
                    if (!empty($options['old_values']['status']) && !empty($options['new_values']['status'])) {
                        return sprintf('Listing status changed from %s to %s',
                            $options['old_values']['status'],
                            $options['new_values']['status']);
                    }
                    return 'Listing status changed';

                case self::ACTION_PRICE_CHANGED:
                    if (!empty($options['old_values']['price']) && !empty($options['new_values']['price'])) {
                        $old_price = $options['old_values']['price'];
                        $new_price = $options['new_values']['price'];
                        $change = $new_price - $old_price;
                        $direction = $change > 0 ? 'increased' : 'decreased';
                        return sprintf('Price %s from $%s to $%s',
                            $direction,
                            number_format($old_price),
                            number_format($new_price));
                    }
                    return 'Listing price changed';

                case self::ACTION_TABLE_MOVED:
                    return 'Listing moved between active/archive tables';

                case self::ACTION_OPEN_HOUSE_ADDED:
                    $desc = 'Open house scheduled';
                    if (!empty($details['open_house_date'])) {
                        $date = date('M j, Y', strtotime($details['open_house_date']));
                        $desc .= " for {$date}";
                        if (!empty($details['start_time']) && !empty($details['end_time'])) {
                            $start = date('g:i A', strtotime($details['start_time']));
                            $end = date('g:i A', strtotime($details['end_time']));
                            $desc .= " ({$start} - {$end})";
                        }
                    }
                    return $desc;

                case self::ACTION_OPEN_HOUSE_REMOVED:
                    $desc = 'Open house cancelled';
                    if (!empty($details['open_house_date'])) {
                        $date = date('M j, Y', strtotime($details['open_house_date']));
                        $desc .= " for {$date}";
                    }
                    if (!empty($details['removal_reason'])) {
                        $desc .= " ({$details['removal_reason']})";
                    }
                    return $desc;

                case self::ACTION_OPEN_HOUSE_RESCHEDULED:
                    $desc = 'Open house rescheduled';
                    if (!empty($details['old_date']) && !empty($details['new_date'])) {
                        $old_date = date('M j, Y', strtotime($details['old_date']));
                        $new_date = date('M j, Y', strtotime($details['new_date']));
                        $desc .= " from {$old_date} to {$new_date}";
                    }
                    return $desc;

                case self::ACTION_OPEN_HOUSE_UPDATED:
                    $desc = 'Open house details updated';
                    if (!empty($details['changed_fields'])) {
                        $field_count = is_array($details['changed_fields']) ? count($details['changed_fields']) : 1;
                        $desc .= " ({$field_count} field" . ($field_count > 1 ? 's' : '') . " changed)";
                    }
                    return $desc;
            }
        }

        // Default descriptions
        $descriptions = [
            self::TYPE_LISTING => [
                self::ACTION_IMPORTED => 'New listing imported from MLS',
                self::ACTION_UPDATED => 'Listing data updated from MLS',
                self::ACTION_STATUS_CHANGED => 'Listing status changed',
                self::ACTION_PRICE_CHANGED => 'Listing price changed',
                self::ACTION_TABLE_MOVED => 'Listing moved between active/archive tables',
                self::ACTION_OPEN_HOUSE_ADDED => 'Open house scheduled',
                self::ACTION_OPEN_HOUSE_REMOVED => 'Open house cancelled',
                self::ACTION_OPEN_HOUSE_RESCHEDULED => 'Open house rescheduled',
                self::ACTION_OPEN_HOUSE_UPDATED => 'Open house details updated',
            ],
            self::TYPE_AGENT => [
                self::ACTION_IMPORTED => 'Agent information imported from MLS',
                self::ACTION_UPDATED => 'Agent information updated',
            ],
            self::TYPE_OFFICE => [
                self::ACTION_IMPORTED => 'Office information imported from MLS',
                self::ACTION_UPDATED => 'Office information updated',
            ],
            self::TYPE_EXTRACTION => [
                self::ACTION_STARTED => 'Extraction process started',
                self::ACTION_COMPLETED => 'Extraction process completed successfully',
                self::ACTION_FAILED => 'Extraction process failed',
            ]
        ];

        return $descriptions[$activity_type][$action] ?? 'Activity performed';
    }
    
    /**
     * Helper: Extract address from listing data
     */
    private function extract_address($listing_data) {
        $address_parts = [];
        
        // Try RESO standard field names first, then lowercase alternatives
        $street_number = $listing_data['StreetNumber'] ?? $listing_data['street_number'] ?? null;
        if (!empty($street_number)) {
            $address_parts[] = $street_number;
        }
        
        $street_name = $listing_data['StreetName'] ?? $listing_data['street_name'] ?? null;
        if (!empty($street_name)) {
            $address_parts[] = $street_name;
        }
        
        $city = $listing_data['City'] ?? $listing_data['city'] ?? null;
        if (!empty($city)) {
            $address_parts[] = $city;
        }
        
        $state = $listing_data['StateOrProvince'] ?? $listing_data['state'] ?? null;
        if (!empty($state)) {
            $address_parts[] = $state;
        }
        
        // Fallback to unparsed address or property address
        $fallback_address = $listing_data['UnparsedAddress'] ?? 
                          $listing_data['unparsed_address'] ?? 
                          $listing_data['PropertyAddress'] ??
                          $listing_data['property_address'] ?? 
                          'Unknown Address';
        
        return implode(' ', $address_parts) ?: $fallback_address;
    }
    
    /**
     * Helper: Get user IP address
     */
    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
    }
}