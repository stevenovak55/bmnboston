# Bridge MLS Extractor Pro - Hooks & Filters Reference

## Table of Contents

1. [Action Hooks](#action-hooks)
2. [Filter Hooks](#filter-hooks)
3. [Usage Examples](#usage-examples)
4. [Custom Integration](#custom-integration)

---

## Action Hooks

### Extraction Lifecycle Hooks

#### `bme_before_extraction`
Fired before an extraction begins.

**Parameters:**
- `$extraction_id` (int) - The extraction profile post ID
- `$is_resync` (bool) - Whether this is a full resync

**Example:**
```php
add_action('bme_before_extraction', function($extraction_id, $is_resync) {
    // Log extraction start
    error_log("Starting extraction {$extraction_id}, resync: " . ($is_resync ? 'yes' : 'no'));

    // Send notification
    wp_mail('admin@example.com', 'Extraction Started', 'Extraction ' . $extraction_id . ' has started.');
}, 10, 2);
```

#### `bme_after_extraction`
Fired after an extraction completes.

**Parameters:**
- `$extraction_id` (int) - The extraction profile post ID
- `$results` (array) - Extraction results including counts and status

**Example:**
```php
add_action('bme_after_extraction', function($extraction_id, $results) {
    // Log results
    error_log(sprintf(
        "Extraction %d completed: %d processed, %d new, %d updated",
        $extraction_id,
        $results['total_processed'],
        $results['new_listings'],
        $results['updated_listings']
    ));
}, 10, 2);
```

#### `bme_extraction_paused`
Fired when an extraction is paused.

**Parameters:**
- `$extraction_id` (int) - The extraction profile post ID
- `$reason` (string) - Pause reason (memory_limit, time_limit, error)
- `$state` (array) - Current extraction state

**Example:**
```php
add_action('bme_extraction_paused', function($extraction_id, $reason, $state) {
    // Handle pause based on reason
    switch ($reason) {
        case 'memory_limit':
            // Free up memory
            wp_cache_flush();
            break;
        case 'time_limit':
            // Schedule immediate continuation
            wp_schedule_single_event(time() + 60, 'bme_continue_extraction', [$extraction_id]);
            break;
    }
}, 10, 3);
```

### Listing Data Hooks

#### `bme_listing_saved`
Fired when a listing is saved to the database.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$data` (array) - Listing data
- `$is_new` (bool) - Whether this is a new listing

**Example:**
```php
add_action('bme_listing_saved', function($listing_id, $data, $is_new) {
    if ($is_new) {
        // Process new listing
        do_action('my_custom_new_listing', $listing_id, $data);

        // Maybe send alerts
        if ($data['list_price'] < 500000) {
            send_price_alert($listing_id, $data);
        }
    }
}, 10, 3);
```

#### `bme_listing_updated`
Fired when an existing listing is updated.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$old_data` (array) - Previous listing data
- `$new_data` (array) - Updated listing data
- `$changes` (array) - Array of changed fields

**Example:**
```php
add_action('bme_listing_updated', function($listing_id, $old_data, $new_data, $changes) {
    // Check for price reduction
    if (in_array('list_price', $changes)) {
        $price_change = $new_data['list_price'] - $old_data['list_price'];
        if ($price_change < 0) {
            notify_price_reduction($listing_id, $old_data['list_price'], $new_data['list_price']);
        }
    }
}, 10, 4);
```

#### `bme_listing_deleted`
Fired when a listing is deleted or archived.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$reason` (string) - Deletion reason

**Example:**
```php
add_action('bme_listing_deleted', function($listing_id, $reason) {
    // Clean up related data
    delete_post_meta_by_key('_related_listing_' . $listing_id);

    // Log deletion
    error_log("Listing {$listing_id} deleted: {$reason}");
}, 10, 2);
```

#### `bme_listing_status_changed`
Fired when a listing's status changes.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$old_status` (string) - Previous status
- `$new_status` (string) - New status
- `$listing_data` (array) - Current listing data

**Example:**
```php
add_action('bme_listing_status_changed', function($listing_id, $old_status, $new_status, $listing_data) {
    // Handle status transitions
    if ($old_status === 'Active' && $new_status === 'Pending') {
        notify_listing_pending($listing_id, $listing_data);
    } elseif ($new_status === 'Closed') {
        archive_closed_listing($listing_id);
    }
}, 10, 4);
```

### Media & Attachments Hooks

#### `bme_media_imported`
Fired when media is imported for a listing.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$media_items` (array) - Array of media data
- `$media_type` (string) - Type of media (photos, videos, tours)

**Example:**
```php
add_action('bme_media_imported', function($listing_id, $media_items, $media_type) {
    if ($media_type === 'photos') {
        // Generate thumbnails
        foreach ($media_items as $media) {
            generate_custom_thumbnail($media['url'], $listing_id);
        }
    }
}, 10, 3);
```

#### `bme_virtual_tour_imported`
Fired when a virtual tour is imported.

**Parameters:**
- `$listing_id` (string) - MLS listing ID
- `$tour_url` (string) - Virtual tour URL
- `$tour_type` (string) - Tour type (matterport, video, etc.)

**Example:**
```php
add_action('bme_virtual_tour_imported', function($listing_id, $tour_url, $tour_type) {
    // Process virtual tour
    if ($tour_type === 'matterport') {
        extract_matterport_thumbnail($tour_url, $listing_id);
    }
}, 10, 3);
```

### Database & Cache Hooks

#### `bme_cache_cleared`
Fired when cache is cleared.

**Parameters:**
- `$cache_type` (string) - Type of cache cleared
- `$cache_keys` (array) - Specific keys cleared (if applicable)

**Example:**
```php
add_action('bme_cache_cleared', function($cache_type, $cache_keys) {
    // Clear CDN cache
    if (function_exists('cloudflare_purge_cache')) {
        cloudflare_purge_cache();
    }
}, 10, 2);
```

#### `bme_database_optimized`
Fired after database optimization.

**Parameters:**
- `$tables` (array) - Tables that were optimized
- `$results` (array) - Optimization results

**Example:**
```php
add_action('bme_database_optimized', function($tables, $results) {
    // Log optimization
    error_log('Database optimization completed for tables: ' . implode(', ', $tables));
}, 10, 2);
```

### Admin & UI Hooks

#### `bme_admin_notices`
Add custom admin notices.

**Parameters:** None

**Example:**
```php
add_action('bme_admin_notices', function() {
    if (get_option('bme_needs_attention')) {
        echo '<div class="notice notice-warning"><p>Extraction needs attention!</p></div>';
    }
});
```

#### `bme_settings_saved`
Fired when plugin settings are saved.

**Parameters:**
- `$settings` (array) - New settings values
- `$old_settings` (array) - Previous settings values

**Example:**
```php
add_action('bme_settings_saved', function($settings, $old_settings) {
    // Clear cache when settings change
    if ($settings['api_token'] !== $old_settings['api_token']) {
        wp_cache_flush();
    }
}, 10, 2);
```

---

## Filter Hooks

### Extraction Filters

#### `bme_api_filters`
Modify API filters before extraction.

**Parameters:**
- `$filters` (array) - Current API filters
- `$extraction_id` (int) - Extraction profile ID

**Returns:** (array) Modified filters

**Example:**
```php
add_filter('bme_api_filters', function($filters, $extraction_id) {
    // Add custom filter
    $filters['CustomField'] = 'CustomValue';

    // Modify existing filter
    if (isset($filters['PropertyType'])) {
        $filters['PropertyType'] .= ',Condo';
    }

    return $filters;
}, 10, 2);
```

#### `bme_extraction_batch_size`
Modify extraction batch size.

**Parameters:**
- `$batch_size` (int) - Current batch size
- `$extraction_id` (int) - Extraction profile ID

**Returns:** (int) Modified batch size

**Example:**
```php
add_filter('bme_extraction_batch_size', function($batch_size, $extraction_id) {
    // Reduce batch size for specific extraction
    $extraction = get_post($extraction_id);
    if ($extraction->post_title === 'Large Dataset') {
        return 50; // Smaller batches
    }
    return $batch_size;
}, 10, 2);
```

#### `bme_extraction_time_limit`
Modify extraction time limit.

**Parameters:**
- `$time_limit` (int) - Time limit in seconds
- `$extraction_id` (int) - Extraction profile ID

**Returns:** (int) Modified time limit

**Example:**
```php
add_filter('bme_extraction_time_limit', function($time_limit, $extraction_id) {
    // Increase time limit for full resync
    if (get_post_meta($extraction_id, '_bme_is_resync', true)) {
        return 600; // 10 minutes
    }
    return $time_limit;
}, 10, 2);
```

### Data Processing Filters

#### `bme_listing_data`
Modify listing data before saving.

**Parameters:**
- `$data` (array) - Processed listing data
- `$raw_data` (array) - Raw API data

**Returns:** (array) Modified listing data

**Example:**
```php
add_filter('bme_listing_data', function($data, $raw_data) {
    // Add custom field
    $data['custom_score'] = calculate_listing_score($data);

    // Modify existing field
    $data['description'] = wp_trim_words($data['description'], 100);

    // Add SEO-friendly slug
    $data['slug'] = sanitize_title($data['street_address'] . ' ' . $data['city']);

    return $data;
}, 10, 2);
```

#### `bme_skip_listing`
Determine whether to skip a listing.

**Parameters:**
- `$skip` (bool) - Current skip status
- `$listing_data` (array) - Listing data
- `$extraction_id` (int) - Extraction profile ID

**Returns:** (bool) Whether to skip the listing

**Example:**
```php
add_filter('bme_skip_listing', function($skip, $listing_data, $extraction_id) {
    // Skip listings without photos
    if (empty($listing_data['media']) || count($listing_data['media']) < 3) {
        return true;
    }

    // Skip specific property types
    if ($listing_data['property_type'] === 'Land') {
        return true;
    }

    return $skip;
}, 10, 3);
```

#### `bme_listing_validation_rules`
Modify listing validation rules.

**Parameters:**
- `$rules` (array) - Current validation rules

**Returns:** (array) Modified validation rules

**Example:**
```php
add_filter('bme_listing_validation_rules', function($rules) {
    // Add custom validation
    $rules['min_price'] = 100000;
    $rules['required_fields'][] = 'year_built';

    return $rules;
});
```

### Database & Query Filters

#### `bme_database_tables`
Modify database table creation.

**Parameters:**
- `$tables` (array) - Table definitions

**Returns:** (array) Modified table definitions

**Example:**
```php
add_filter('bme_database_tables', function($tables) {
    // Add custom table
    $tables['custom_data'] = "
        CREATE TABLE {prefix}bme_custom_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id VARCHAR(50),
            custom_field VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";

    return $tables;
});
```

#### `bme_listing_query`
Modify listing query parameters.

**Parameters:**
- `$query` (string) - SQL query
- `$args` (array) - Query arguments

**Returns:** (string) Modified query

**Example:**
```php
add_filter('bme_listing_query', function($query, $args) {
    // Add custom JOIN
    if (!empty($args['include_custom'])) {
        $query = str_replace(
            'FROM wp_bme_listings',
            'FROM wp_bme_listings LEFT JOIN wp_bme_custom_data ON ...',
            $query
        );
    }

    return $query;
}, 10, 2);
```

### Cache Filters

#### `bme_cache_ttl`
Modify cache TTL for specific keys.

**Parameters:**
- `$ttl` (int) - Time to live in seconds
- `$cache_key` (string) - Cache key

**Returns:** (int) Modified TTL

**Example:**
```php
add_filter('bme_cache_ttl', function($ttl, $cache_key) {
    // Longer cache for analytics
    if (strpos($cache_key, 'analytics_') === 0) {
        return 7200; // 2 hours
    }

    // Shorter cache for active listings
    if (strpos($cache_key, 'active_listings_') === 0) {
        return 300; // 5 minutes
    }

    return $ttl;
}, 10, 2);
```

#### `bme_cacheable_data`
Determine if data should be cached.

**Parameters:**
- `$cacheable` (bool) - Current cacheable status
- `$data_type` (string) - Type of data
- `$data` (mixed) - The data itself

**Returns:** (bool) Whether to cache

**Example:**
```php
add_filter('bme_cacheable_data', function($cacheable, $data_type, $data) {
    // Don't cache empty results
    if (empty($data)) {
        return false;
    }

    // Don't cache user-specific data
    if ($data_type === 'user_searches') {
        return false;
    }

    return $cacheable;
}, 10, 3);
```

### Display & Output Filters

#### `bme_listing_output`
Modify listing output for display.

**Parameters:**
- `$output` (array) - Listing data for display
- `$listing` (object) - Original listing object

**Returns:** (array) Modified output

**Example:**
```php
add_filter('bme_listing_output', function($output, $listing) {
    // Format price
    $output['formatted_price'] = '$' . number_format($output['list_price']);

    // Add custom badges
    if ($output['days_on_market'] < 7) {
        $output['badges'][] = 'New';
    }

    return $output;
}, 10, 2);
```

#### `bme_admin_menu_items`
Modify admin menu items.

**Parameters:**
- `$menu_items` (array) - Current menu items

**Returns:** (array) Modified menu items

**Example:**
```php
add_filter('bme_admin_menu_items', function($menu_items) {
    // Add custom menu item
    $menu_items[] = [
        'title' => 'Custom Reports',
        'capability' => 'manage_options',
        'slug' => 'bme-custom-reports',
        'callback' => 'render_custom_reports_page'
    ];

    return $menu_items;
});
```

---

## Usage Examples

### Complete Integration Example

```php
/**
 * Custom integration for price reduction alerts
 */
class My_BME_Integration {

    public function __construct() {
        // Hook into extraction lifecycle
        add_action('bme_before_extraction', [$this, 'prepare_extraction'], 10, 2);
        add_action('bme_after_extraction', [$this, 'process_results'], 10, 2);

        // Monitor listing changes
        add_action('bme_listing_updated', [$this, 'check_price_changes'], 10, 4);

        // Modify data processing
        add_filter('bme_listing_data', [$this, 'enhance_listing_data'], 10, 2);

        // Custom validation
        add_filter('bme_skip_listing', [$this, 'validate_listing'], 10, 3);
    }

    public function prepare_extraction($extraction_id, $is_resync) {
        // Store current prices for comparison
        $this->store_current_prices();
    }

    public function process_results($extraction_id, $results) {
        // Send summary email
        $this->send_extraction_summary($results);
    }

    public function check_price_changes($listing_id, $old_data, $new_data, $changes) {
        if (in_array('list_price', $changes)) {
            $reduction = $old_data['list_price'] - $new_data['list_price'];
            if ($reduction > 0) {
                $this->send_price_alert($listing_id, $old_data['list_price'], $new_data['list_price']);
            }
        }
    }

    public function enhance_listing_data($data, $raw_data) {
        // Add market analysis
        $data['market_score'] = $this->calculate_market_score($data);
        $data['value_rating'] = $this->assess_value($data);

        return $data;
    }

    public function validate_listing($skip, $listing_data, $extraction_id) {
        // Custom validation logic
        if ($listing_data['list_price'] < 50000) {
            return true; // Skip very low price listings
        }

        return $skip;
    }
}

// Initialize integration
new My_BME_Integration();
```

### Performance Monitoring Example

```php
/**
 * Monitor extraction performance
 */
class BME_Performance_Monitor {

    private $start_time;
    private $start_memory;

    public function __construct() {
        add_action('bme_before_extraction', [$this, 'start_monitoring'], 10, 2);
        add_action('bme_after_extraction', [$this, 'end_monitoring'], 10, 2);
        add_action('bme_listing_saved', [$this, 'track_listing'], 10, 3);
    }

    public function start_monitoring($extraction_id, $is_resync) {
        $this->start_time = microtime(true);
        $this->start_memory = memory_get_usage();

        error_log("Starting extraction {$extraction_id} monitoring");
    }

    public function end_monitoring($extraction_id, $results) {
        $duration = microtime(true) - $this->start_time;
        $memory_used = memory_get_usage() - $this->start_memory;

        // Store metrics
        update_post_meta($extraction_id, '_bme_last_duration', $duration);
        update_post_meta($extraction_id, '_bme_last_memory', $memory_used);

        // Log performance
        error_log(sprintf(
            "Extraction %d completed in %.2f seconds using %.2f MB",
            $extraction_id,
            $duration,
            $memory_used / 1024 / 1024
        ));
    }

    public function track_listing($listing_id, $data, $is_new) {
        // Track individual listing processing time
        static $count = 0;
        $count++;

        if ($count % 100 === 0) {
            error_log("Processed {$count} listings");
        }
    }
}

new BME_Performance_Monitor();
```

---

## Custom Integration

### Creating Custom Hooks

You can add your own hooks within custom code:

```php
// In your custom processor
function process_custom_data($listing_id, $data) {
    // Allow others to modify data
    $data = apply_filters('my_plugin_custom_data', $data, $listing_id);

    // Process data
    $result = do_custom_processing($data);

    // Trigger action after processing
    do_action('my_plugin_data_processed', $listing_id, $result);

    return $result;
}
```

### Hook Priority Guidelines

- **1-10**: Core plugin functionality
- **10**: Default priority
- **11-99**: General modifications
- **100+**: Final modifications that should run last

### Performance Considerations

1. **Use specific hooks** rather than general ones when possible
2. **Check conditions early** to avoid unnecessary processing
3. **Cache expensive operations** when appropriate
4. **Remove hooks** when no longer needed with `remove_action()` or `remove_filter()`

### Debugging Hooks

```php
// Log all hooks being fired
add_action('all', function($hook) {
    if (strpos($hook, 'bme_') === 0) {
        error_log("BME Hook fired: {$hook}");
    }
});

// Check if hook has callbacks
if (has_action('bme_listing_saved')) {
    $callbacks = $wp_filter['bme_listing_saved'];
    error_log('Callbacks: ' . print_r($callbacks, true));
}
```

---

## Support

For questions about hooks and filters:
- Check the inline documentation in the source code
- Review examples in this document
- Contact support for custom integration assistance