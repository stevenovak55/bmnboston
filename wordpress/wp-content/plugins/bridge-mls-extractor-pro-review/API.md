# API Documentation - Bridge MLS Extractor Pro

## Table of Contents

- [Global Functions](#global-functions)
- [Service Container API](#service-container-api)
- [Database Manager API](#database-manager-api)
- [Cache Manager API](#cache-manager-api)
- [API Client Methods](#api-client-methods)
- [Data Processor API](#data-processor-api)
- [Extraction Engine API](#extraction-engine-api)
- [Analytics API](#analytics-api)
- [REST API Endpoints](#rest-api-endpoints)
- [JavaScript API](#javascript-api)
- [Hooks Reference](#hooks-reference)

## Global Functions

### `bme_pro()`
Get the main plugin instance.

```php
/**
 * Get Bridge MLS Extractor Pro instance
 *
 * @return Bridge_MLS_Extractor_Pro Plugin instance
 */
$plugin = bme_pro();
```

### Service Access

```php
// Get service from container
$db = bme_pro()->get('db');           // Database Manager
$cache = bme_pro()->get('cache');     // Cache Manager
$api = bme_pro()->get('api');         // API Client
$processor = bme_pro()->get('processor'); // Data Processor
$extractor = bme_pro()->get('extractor'); // Extraction Engine
```

## Service Container API

### Bridge_MLS_Extractor_Pro

```php
class Bridge_MLS_Extractor_Pro {
    /**
     * Get service from container
     *
     * @param string $service Service name
     * @return mixed Service instance
     * @throws Exception If service not found
     */
    public function get($service)

    /**
     * Register service in container
     *
     * @param string $name Service name
     * @param mixed $service Service instance or closure
     * @return void
     */
    public function set($name, $service)

    /**
     * Check if service exists
     *
     * @param string $name Service name
     * @return bool
     */
    public function has($name)
}
```

## Database Manager API

### BME_Database_Manager

```php
class BME_Database_Manager {
    /**
     * Get prefixed table name
     *
     * @param string $table Table key
     * @return string Full table name with prefix
     */
    public function get_table($table)

    /**
     * Get all table names
     *
     * @return array Array of table names
     */
    public function get_tables()

    /**
     * Create all plugin tables
     *
     * @return bool True on success
     */
    public function create_tables()

    /**
     * Drop all plugin tables
     *
     * @return bool True on success
     */
    public function drop_tables()

    /**
     * Execute database query
     *
     * @param string $query SQL query
     * @param array $params Parameters for prepared statement
     * @return mixed Query result
     */
    public function query($query, $params = [])

    /**
     * Get results from database
     *
     * @param string $query SQL query
     * @param array $params Parameters
     * @param string $output Output type (OBJECT|ARRAY_A|ARRAY_N)
     * @return array Query results
     */
    public function get_results($query, $params = [], $output = OBJECT)

    /**
     * Get single row from database
     *
     * @param string $query SQL query
     * @param array $params Parameters
     * @param string $output Output type
     * @return mixed Single row or null
     */
    public function get_row($query, $params = [], $output = OBJECT)

    /**
     * Get single variable from database
     *
     * @param string $query SQL query
     * @param array $params Parameters
     * @return mixed Single value or null
     */
    public function get_var($query, $params = [])

    /**
     * Insert data into table
     *
     * @param string $table Table name
     * @param array $data Data to insert
     * @param array $format Format specifiers
     * @return int|false Insert ID or false on failure
     */
    public function insert($table, $data, $format = null)

    /**
     * Update table data
     *
     * @param string $table Table name
     * @param array $data Data to update
     * @param array $where WHERE conditions
     * @param array $format Format specifiers
     * @param array $where_format WHERE format specifiers
     * @return int|false Number of rows updated or false
     */
    public function update($table, $data, $where, $format = null, $where_format = null)

    /**
     * Delete from table
     *
     * @param string $table Table name
     * @param array $where WHERE conditions
     * @param array $where_format Format specifiers
     * @return int|false Number of rows deleted or false
     */
    public function delete($table, $where, $where_format = null)

    /**
     * Optimize database tables
     *
     * @return bool True on success
     */
    public function optimize_tables()
}
```

## Cache Manager API

### BME_Cache_Manager

```php
class BME_Cache_Manager {
    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @param callable $callback Optional callback to generate data
     * @param int $ttl Time to live in seconds
     * @return mixed Cached data or false
     */
    public function get($key, $callback = null, $ttl = null)

    /**
     * Set cache data
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool True on success
     */
    public function set($key, $data, $ttl = null)

    /**
     * Delete cached data
     *
     * @param string $key Cache key
     * @return bool True on success
     */
    public function delete($key)

    /**
     * Clear all cache
     *
     * @return bool True on success
     */
    public function flush()

    /**
     * Get multiple cache entries
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of key => data
     */
    public function get_multiple($keys)

    /**
     * Set multiple cache entries
     *
     * @param array $data_array Associative array of key => data
     * @param int $ttl Time to live
     * @return array Results for each key
     */
    public function set_multiple($data_array, $ttl = null)

    /**
     * Cache search results
     *
     * @param array $filters Search filters
     * @param array $results Search results
     * @param int $count Result count
     * @return bool True on success
     */
    public function cache_search_results($filters, $results, $count)

    /**
     * Get cached search results
     *
     * @param array $filters Search filters
     * @return array|null Cached results or null
     */
    public function get_cached_search($filters)

    /**
     * Invalidate listing caches
     *
     * @param int|null $listing_id Specific listing or null for all
     * @param array $related_keys Additional keys to invalidate
     * @return int Number of keys invalidated
     */
    public function invalidate_listing_caches($listing_id = null, $related_keys = [])

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function get_cache_stats()
}
```

## API Client Methods

### BME_API_Client

```php
class BME_API_Client {
    /**
     * Fetch listings from Bridge API
     *
     * @param array $filters Query filters
     * @param int $limit Number of results
     * @param int $offset Starting offset
     * @return array API response data
     * @throws BME_API_Exception On API error
     */
    public function fetch_listings($filters = [], $limit = 200, $offset = 0)

    /**
     * Get single listing by ID
     *
     * @param string $listing_id Listing ID
     * @return array Listing data
     * @throws BME_API_Exception On API error
     */
    public function get_listing($listing_id)

    /**
     * Fetch media for listing
     *
     * @param string $listing_id Listing ID
     * @return array Media items
     */
    public function fetch_media($listing_id)

    /**
     * Fetch open houses for listing
     *
     * @param string $listing_id Listing ID
     * @return array Open house data
     */
    public function fetch_open_houses($listing_id)

    /**
     * Fetch rooms for listing
     *
     * @param string $listing_id Listing ID
     * @return array Room data
     */
    public function fetch_rooms($listing_id)

    /**
     * Build API filter string
     *
     * @param array $filters Filter criteria
     * @return string OData filter string
     */
    public function build_filter_string($filters)

    /**
     * Execute API request with retry
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @param int $max_retries Maximum retry attempts
     * @return array Response data
     * @throws BME_API_Exception On failure
     */
    public function request_with_retry($endpoint, $params = [], $max_retries = 3)

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     */
    public function test_connection()
}
```

## Data Processor API

### BME_Data_Processor

```php
class BME_Data_Processor {
    /**
     * Process single listing
     *
     * @param array $raw_data Raw listing data from API
     * @param int $extraction_id Extraction ID
     * @return int|false Listing ID or false on failure
     */
    public function process_listing($raw_data, $extraction_id)

    /**
     * Process batch of listings
     *
     * @param array $listings Array of listing data
     * @param int $extraction_id Extraction ID
     * @return array Processing results
     */
    public function process_listings_batch($listings, $extraction_id)

    /**
     * Validate listing data
     *
     * @param array $data Listing data
     * @return bool True if valid
     * @throws BME_Validation_Exception On validation failure
     */
    public function validate_listing_data($data)

    /**
     * Sanitize listing data
     *
     * @param array $data Raw data
     * @return array Sanitized data
     */
    public function sanitize_listing_data($data)

    /**
     * Normalize listing data
     *
     * @param array $data Listing data
     * @return array Normalized data
     */
    public function normalize_listing_data($data)

    /**
     * Store listing in database
     *
     * @param array $data Processed listing data
     * @param int $extraction_id Extraction ID
     * @return int|false Database ID or false
     */
    public function store_listing($data, $extraction_id)

    /**
     * Process media items
     *
     * @param array $media Media items
     * @param string $listing_id Listing ID
     * @return int Number of media items processed
     */
    public function process_media($media, $listing_id)

    /**
     * Process agent data
     *
     * @param array $agent_data Agent information
     * @return int|false Agent ID or false
     */
    public function process_agent($agent_data)

    /**
     * Process office data
     *
     * @param array $office_data Office information
     * @return int|false Office ID or false
     */
    public function process_office($office_data)

    /**
     * Get all listing columns
     *
     * @return array Column definitions
     */
    public function get_all_listing_columns()

    /**
     * Export listings to CSV
     *
     * @param array $listing_ids Listing IDs to export
     * @param array $columns Columns to include
     * @return string CSV data
     */
    public function export_listings_csv($listing_ids, $columns = [])
}
```

## Extraction Engine API

### BME_Extractor

```php
class BME_Extractor {
    /**
     * Run extraction
     *
     * @param int $extraction_id Extraction profile ID
     * @param array $options Override options
     * @return array Extraction results
     */
    public function run_extraction($extraction_id, $options = [])

    /**
     * Resume extraction session
     *
     * @param int $extraction_id Extraction ID
     * @return array Session results
     */
    public function resume_extraction($extraction_id)

    /**
     * Stop extraction
     *
     * @param int $extraction_id Extraction ID
     * @return bool True on success
     */
    public function stop_extraction($extraction_id)

    /**
     * Get extraction status
     *
     * @param int $extraction_id Extraction ID
     * @return array Status information
     */
    public function get_extraction_status($extraction_id)

    /**
     * Schedule extraction
     *
     * @param int $extraction_id Extraction ID
     * @param string $schedule Cron schedule
     * @return bool True on success
     */
    public function schedule_extraction($extraction_id, $schedule)

    /**
     * Get extraction statistics
     *
     * @param int $extraction_id Extraction ID
     * @return array Statistics
     */
    public function get_extraction_stats($extraction_id)

    /**
     * Clear extraction data
     *
     * @param int $extraction_id Extraction ID
     * @return bool True on success
     */
    public function clear_extraction_data($extraction_id)

    /**
     * Run test extraction
     *
     * @param array $filters Test filters
     * @param int $limit Number of listings
     * @return array Test results
     */
    public function test_extraction($filters, $limit = 10)
}
```

## Analytics API

### BME_Market_Analytics_V3

```php
class BME_Market_Analytics_V3 {
    /**
     * Get market overview data
     *
     * @param array $filters Optional filters
     * @return array Overview statistics
     */
    public function get_overview_data($filters = [])

    /**
     * Get property type analysis
     *
     * @param array $filters Optional filters
     * @return array Property type breakdown
     */
    public function get_property_types_data($filters = [])

    /**
     * Get price analysis
     *
     * @param array $filters Optional filters
     * @return array Price statistics
     */
    public function get_price_analysis($filters = [])

    /**
     * Get market trends
     *
     * @param array $filters Optional filters
     * @param int $months Number of months
     * @return array Trend data
     */
    public function get_trends_data($filters = [], $months = 12)

    /**
     * Get geographic analysis
     *
     * @param array $filters Optional filters
     * @return array Geographic breakdown
     */
    public function get_geographic_data($filters = [])

    /**
     * Get agent performance
     *
     * @param array $filters Optional filters
     * @param int $limit Number of agents
     * @return array Agent rankings
     */
    public function get_agent_performance($filters = [], $limit = 20)

    /**
     * Get market segments
     *
     * @param array $filters Optional filters
     * @return array Segment analysis
     */
    public function get_segments_data($filters = [])

    /**
     * Calculate market metrics
     *
     * @param array $data Raw data
     * @return array Calculated metrics
     */
    public function calculate_market_metrics($data)
}
```

## REST API Endpoints

### Authentication

All REST API endpoints require authentication via nonce or application password.

```javascript
// JavaScript authentication
fetch('/wp-json/bme/v1/extraction/123/status', {
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce
    }
});
```

### Extraction Endpoints

#### Get Extraction Status
```
GET /wp-json/bme/v1/extraction/{id}/status

Response:
{
    "status": "running",
    "progress": {
        "current": 500,
        "total": 2000,
        "percentage": 25
    },
    "started_at": "2024-01-15 10:30:00",
    "last_activity": "2024-01-15 10:35:00"
}
```

#### Run Extraction
```
POST /wp-json/bme/v1/extraction/{id}/run

Request Body:
{
    "mode": "full|update",
    "limit": 1000
}

Response:
{
    "success": true,
    "message": "Extraction started",
    "extraction_id": 123
}
```

#### Stop Extraction
```
POST /wp-json/bme/v1/extraction/{id}/stop

Response:
{
    "success": true,
    "message": "Extraction stopped"
}
```

### Analytics Endpoints

#### Get Market Overview
```
GET /wp-json/bme/v1/analytics/overview

Query Parameters:
- cities: Comma-separated city list
- property_types: Comma-separated types
- price_min: Minimum price
- price_max: Maximum price

Response:
{
    "active_listings": 1500,
    "median_price": 450000,
    "days_on_market": 35,
    "inventory_months": 2.5,
    "price_change_30d": 3.5
}
```

#### Get Property Type Analysis
```
GET /wp-json/bme/v1/analytics/property-types

Response:
{
    "data": [
        {
            "type": "Single Family",
            "count": 800,
            "avg_price": 550000,
            "median_price": 500000
        }
    ]
}
```

#### Get Price Distribution
```
GET /wp-json/bme/v1/analytics/price-distribution

Response:
{
    "ranges": [
        {"min": 0, "max": 200000, "count": 150},
        {"min": 200000, "max": 400000, "count": 450}
    ]
}
```

### Search Endpoints

#### Search Listings
```
POST /wp-json/bme/v1/listings/search

Request Body:
{
    "filters": {
        "city": "Boston",
        "property_type": "Single Family",
        "price_min": 300000,
        "price_max": 800000
    },
    "sort": "price_desc",
    "page": 1,
    "per_page": 20
}

Response:
{
    "listings": [...],
    "total": 245,
    "pages": 13
}
```

## JavaScript API

### BME Global Object

```javascript
// Global BME object
window.BME = {
    // API endpoints
    api: {
        baseUrl: '/wp-json/bme/v1',
        nonce: 'wp_rest_nonce'
    },

    // Utility methods
    utils: {
        formatPrice: function(price) {},
        formatDate: function(date) {},
        calculateMetrics: function(data) {}
    },

    // Chart management
    charts: {
        render: function(element, data, options) {},
        update: function(chartId, data) {},
        destroy: function(chartId) {}
    }
};
```

### AJAX Methods

```javascript
// Get analytics data
BME.getAnalytics = function(type, filters, callback) {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'bme_get_analytics',
            type: type,
            filters: filters,
            nonce: BME.nonce
        },
        success: callback
    });
};

// Run extraction
BME.runExtraction = function(extractionId, options, callback) {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'bme_run_extraction',
            extraction_id: extractionId,
            options: options,
            nonce: BME.nonce
        },
        success: callback
    });
};
```

## Hooks Reference

### Actions

#### Extraction Hooks

```php
// Before extraction starts
do_action('bme_before_extraction', $extraction_id, $filters);

// After extraction completes
do_action('bme_after_extraction', $extraction_id, $stats);

// Before processing listing
do_action('bme_before_process_listing', $listing_data, $extraction_id);

// After processing listing
do_action('bme_after_process_listing', $listing_id, $extraction_id);

// On listing import
do_action('bme_listing_imported', $listing_id, $data);

// On listing update
do_action('bme_listing_updated', $listing_id, $old_data, $new_data);

// On listing status change
do_action('bme_listing_status_changed', $listing_id, $old_status, $new_status);
```

#### Cache Hooks

```php
// Before cache clear
do_action('bme_before_cache_clear', $cache_keys);

// After cache clear
do_action('bme_after_cache_clear', $cache_keys);

// On cache hit
do_action('bme_cache_hit', $key, $data);

// On cache miss
do_action('bme_cache_miss', $key);
```

### Filters

#### Data Filters

```php
// Modify listing data before save
$data = apply_filters('bme_listing_data', $data, $listing_id);

// Modify extraction filters
$filters = apply_filters('bme_extraction_filters', $filters, $extraction_id);

// Modify API request arguments
$args = apply_filters('bme_api_request_args', $args, $endpoint);

// Modify processed data
$processed = apply_filters('bme_processed_data', $processed, $raw_data);
```

#### Configuration Filters

```php
// Modify batch size
$batch_size = apply_filters('bme_batch_size', 200);

// Modify session limit
$session_limit = apply_filters('bme_session_limit', 1000);

// Modify cache duration
$ttl = apply_filters('bme_cache_duration', 3600);

// Modify retry attempts
$max_retries = apply_filters('bme_max_retries', 3);
```

#### Display Filters

```php
// Modify admin columns
$columns = apply_filters('bme_admin_columns', $columns);

// Modify export fields
$fields = apply_filters('bme_export_fields', $fields);

// Modify analytics data
$analytics = apply_filters('bme_analytics_data', $analytics, $type);
```

## Error Codes

### API Error Codes

| Code | Description | Resolution |
|------|-------------|------------|
| BME_API_001 | Authentication failed | Check API credentials |
| BME_API_002 | Rate limit exceeded | Wait and retry |
| BME_API_003 | Invalid request | Check request parameters |
| BME_API_004 | Server error | Contact support |
| BME_API_005 | Timeout | Retry with backoff |

### Database Error Codes

| Code | Description | Resolution |
|------|-------------|------------|
| BME_DB_001 | Connection failed | Check database settings |
| BME_DB_002 | Query failed | Check SQL syntax |
| BME_DB_003 | Table not found | Run database setup |
| BME_DB_004 | Duplicate entry | Check for conflicts |
| BME_DB_005 | Lock timeout | Retry operation |

### Validation Error Codes

| Code | Description | Resolution |
|------|-------------|------------|
| BME_VAL_001 | Missing required field | Provide required data |
| BME_VAL_002 | Invalid data type | Check data format |
| BME_VAL_003 | Value out of range | Adjust value |
| BME_VAL_004 | Invalid format | Fix formatting |
| BME_VAL_005 | Validation failed | Review validation rules |

---

## Examples

### Basic Extraction

```php
// Get extractor instance
$extractor = bme_pro()->get('extractor');

// Run extraction
$result = $extractor->run_extraction(123, [
    'mode' => 'update',
    'limit' => 500
]);

// Check results
if ($result['success']) {
    echo "Processed: " . $result['processed'];
    echo "Errors: " . $result['errors'];
}
```

### Cache Usage

```php
// Get cache manager
$cache = bme_pro()->get('cache');

// Get with callback
$data = $cache->get('expensive_query', function() {
    // Expensive operation
    return calculate_complex_data();
}, 3600);

// Invalidate related caches
$cache->invalidate_listing_caches($listing_id);
```

### Custom Analytics

```php
// Get analytics engine
$analytics = bme_pro()->get('analytics');

// Get filtered data
$data = $analytics->get_overview_data([
    'cities' => ['Boston', 'Cambridge'],
    'property_types' => ['Single Family'],
    'price_min' => 500000
]);

// Process results
foreach ($data['metrics'] as $metric => $value) {
    echo "$metric: $value\n";
}
```

---

For more examples and detailed usage, see the [plugin documentation](README.md).