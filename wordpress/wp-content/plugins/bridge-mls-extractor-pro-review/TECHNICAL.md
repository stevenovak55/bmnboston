# Technical Documentation - Bridge MLS Extractor Pro

## Architecture Overview

### System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     WordPress Core                          │
├─────────────────────────────────────────────────────────────┤
│                Bridge MLS Extractor Pro                     │
├──────────────┬──────────────┬──────────────┬──────────────┤
│   Service    │   Business   │    Data      │   Admin      │
│  Container   │    Logic     │   Layer      │  Interface   │
├──────────────┼──────────────┼──────────────┼──────────────┤
│ - DB Manager │ - Extractor  │ - Models     │ - Dashboard  │
│ - Cache Mgr  │ - Processor  │ - Repos      │ - Settings   │
│ - API Client │ - Analytics  │ - Migrations │ - Reports    │
│ - Logger     │ - ML Engine  │ - Queries    │ - Forms      │
└──────────────┴──────────────┴──────────────┴──────────────┘
```

### Design Patterns

#### 1. Singleton Pattern
Main plugin instance uses singleton to ensure single initialization:

```php
class Bridge_MLS_Extractor_Pro {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

#### 2. Service Container (Dependency Injection)
All services are registered in a container for easy access and testing:

```php
$this->container = new ArrayObject();
$this->container['db'] = new BME_Database_Manager();
$this->container['cache'] = new BME_Cache_Manager();
$this->container['api'] = new BME_API_Client();
```

#### 3. Repository Pattern
Data access is abstracted through repository classes:

```php
class BME_Listing_Repository {
    public function find($id) { }
    public function findBy($criteria) { }
    public function save($entity) { }
    public function delete($entity) { }
}
```

#### 4. Strategy Pattern
Different extraction strategies for various MLS providers:

```php
interface BME_Extraction_Strategy {
    public function extract($filters);
    public function transform($data);
    public function load($data);
}
```

## Core Components

### 1. Database Manager (`class-bme-database-manager.php`)

**Purpose**: Manages all database operations, schema creation, and migrations.

**Key Methods**:
- `create_tables()` - Creates all plugin tables
- `get_table($name)` - Returns prefixed table name
- `execute_migration($version)` - Runs database migrations
- `optimize_tables()` - Performs table optimization

**Table Structure**:
```sql
-- Active/Archive Separation
CREATE TABLE wp_bme_listings (
    id INT AUTO_INCREMENT,
    listing_id VARCHAR(50) UNIQUE,
    standard_status VARCHAR(50),
    -- ... 50+ columns
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_listing_id (listing_id),
    INDEX idx_status (standard_status),
    INDEX idx_created (created_at)
);
```

### 2. API Client (`class-bme-api-client.php`)

**Purpose**: Handles all communication with Bridge Interactive API.

**Features**:
- Automatic retry with exponential backoff
- Rate limiting compliance
- Response caching
- Error handling and logging

**API Endpoints**:
```php
GET /api/v2/listings?$filter={filters}&$top={limit}&$skip={offset}
GET /api/v2/listings/{id}
GET /api/v2/media?ListingId={id}
GET /api/v2/openhouses?ListingId={id}
```

### 3. Data Processor (`class-bme-data-processor.php`)

**Purpose**: Processes and validates incoming MLS data.

**Processing Pipeline**:
1. **Validation** - Ensure data meets requirements
2. **Sanitization** - Clean and escape data
3. **Normalization** - Standardize formats
4. **Enrichment** - Add calculated fields
5. **Storage** - Save to appropriate tables

**Key Methods**:
```php
public function process_listing($raw_data, $extraction_id) {
    $validated = $this->validate_listing_data($raw_data);
    $sanitized = $this->sanitize_listing_data($validated);
    $normalized = $this->normalize_listing_data($sanitized);
    $enriched = $this->enrich_listing_data($normalized);
    return $this->store_listing_data($enriched);
}
```

### 4. Cache Manager (`class-bme-cache-manager.php`)

**Purpose**: Manages caching for performance optimization.

**Cache Layers**:
- WordPress Object Cache
- Transient API fallback
- Database result caching

**Cache Strategy**:
```php
// Cache key structure
$cache_key = 'bme_' . md5($query . serialize($params));

// TTL Strategy
- API responses: 5 minutes
- Search results: 10 minutes
- Analytics data: 1 hour
- Static data: 24 hours
```

### 5. Extraction Engine (`class-bme-extractor.php`)

**Purpose**: Core extraction logic and orchestration.

**Extraction Process**:
```
1. Load extraction profile
2. Build API filters
3. Initialize session
4. Fetch listings batch
5. Process each listing
6. Handle media/rooms/etc
7. Update statistics
8. Check continuation
9. Schedule next session
```

**Session Management**:
```php
class BME_Extraction_Session {
    private $extraction_id;
    private $offset = 0;
    private $processed = 0;
    private $limit = 1000;
    private $start_time;
    private $memory_start;

    public function should_continue() {
        return $this->processed < $this->limit
            && memory_get_usage() < $this->memory_limit
            && (time() - $this->start_time) < $this->time_limit;
    }
}
```

## Data Flow

### Extraction Data Flow

```
[Bridge API]
    ↓ (HTTPS/JSON)
[API Client]
    ↓ (Raw Data)
[Data Processor]
    ↓ (Validated Data)
[Database Manager]
    ↓ (SQL Insert/Update)
[MySQL Database]
    ↓ (Change Detection)
[Activity Logger]
    ↓ (Events)
[Cache Manager]
    ↓ (Cached Results)
[Frontend Display]
```

### Request Lifecycle

1. **Extraction Trigger**
   - Manual: Admin clicks "Run Now"
   - Scheduled: WP Cron fires event
   - API: REST endpoint called

2. **Pre-Processing**
   - Lock acquisition (prevent duplicates)
   - Memory/time limit checks
   - Previous session recovery

3. **API Communication**
   - Build request with filters
   - Add authentication headers
   - Execute with retry logic

4. **Data Processing**
   - Parse JSON response
   - Validate required fields
   - Transform data structure
   - Apply business rules

5. **Database Operations**
   - Check existing records
   - Determine insert/update
   - Execute transactions
   - Log changes

6. **Post-Processing**
   - Update statistics
   - Clear relevant caches
   - Trigger notifications
   - Schedule continuation

## Performance Optimizations

### Database Optimizations

1. **Indexing Strategy**
```sql
-- Composite indexes for common queries
CREATE INDEX idx_location_search
ON wp_bme_listing_location(city, state_or_province, postal_code);

-- Spatial index for map searches
CREATE SPATIAL INDEX idx_coordinates
ON wp_bme_listing_location(coordinates);

-- Covering indexes for analytics
CREATE INDEX idx_analytics
ON wp_bme_listings(standard_status, property_type, list_price);
```

2. **Query Optimization**
```php
// Use prepared statements
$wpdb->prepare("SELECT * FROM {$table} WHERE listing_id = %s", $id);

// Batch operations
$wpdb->query("INSERT INTO {$table} VALUES " . implode(',', $values));

// Limit result sets
$wpdb->get_results($query . " LIMIT 100");
```

### Memory Management

1. **Batch Processing**
```php
// Process in chunks
$batch_size = 100;
for ($i = 0; $i < count($items); $i += $batch_size) {
    $batch = array_slice($items, $i, $batch_size);
    process_batch($batch);
    unset($batch); // Free memory
}
```

2. **Session Limits**
```php
// Monitor memory usage
if (memory_get_usage(true) > $memory_threshold) {
    $this->pause_and_continue();
}
```

### Caching Strategy

1. **Multi-tier Caching**
```php
// L1: PHP variable cache
private $runtime_cache = [];

// L2: WordPress object cache
wp_cache_set($key, $data, 'bme_cache', 3600);

// L3: Database transients
set_transient('bme_' . $key, $data, DAY_IN_SECONDS);
```

2. **Cache Invalidation**
```php
// Invalidate on data change
public function after_listing_update($listing_id) {
    $this->cache->delete('listing_' . $listing_id);
    $this->cache->delete_pattern('search_*');
    $this->cache->delete_pattern('analytics_*');
}
```

## Security Measures

### Input Validation

```php
// Sanitize all input
$listing_id = sanitize_text_field($_POST['listing_id']);
$price = absint($_POST['price']);
$description = wp_kses_post($_POST['description']);

// Validate data types
if (!is_numeric($price) || $price < 0) {
    throw new InvalidArgumentException('Invalid price');
}
```

### SQL Injection Prevention

```php
// Always use prepared statements
$wpdb->prepare(
    "SELECT * FROM {$table} WHERE id = %d AND status = %s",
    $id,
    $status
);

// Escape table/column names
$table = esc_sql($wpdb->prefix . 'bme_listings');
```

### Authentication & Authorization

```php
// Capability checks
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

// Nonce verification
if (!wp_verify_nonce($_POST['_wpnonce'], 'bme_action')) {
    wp_die('Security check failed');
}
```

### Data Encryption

```php
// Encrypt sensitive data
$encrypted = openssl_encrypt(
    $api_key,
    'AES-256-CBC',
    $encryption_key,
    0,
    $iv
);

// Store encrypted
update_option('bme_api_key', base64_encode($encrypted));
```

## Error Handling

### Exception Hierarchy

```php
BME_Exception (base)
├── BME_API_Exception
│   ├── BME_API_Authentication_Exception
│   ├── BME_API_RateLimit_Exception
│   └── BME_API_Timeout_Exception
├── BME_Database_Exception
│   ├── BME_Database_Connection_Exception
│   └── BME_Database_Query_Exception
└── BME_Validation_Exception
    ├── BME_Missing_Field_Exception
    └── BME_Invalid_Data_Exception
```

### Error Recovery

```php
try {
    $result = $this->api_client->fetch_listings($filters);
} catch (BME_API_RateLimit_Exception $e) {
    // Wait and retry
    sleep($e->getRetryAfter());
    return $this->retry_with_backoff();
} catch (BME_API_Exception $e) {
    // Log and notify
    $this->logger->error($e->getMessage());
    $this->notifier->send_admin_alert($e);
    return false;
}
```

### Logging Strategy

```php
// Severity levels
$logger->debug('Detailed debugging information');
$logger->info('Informational messages');
$logger->warning('Warning conditions');
$logger->error('Error conditions');
$logger->critical('Critical conditions');

// Structured logging
$logger->info('Extraction completed', [
    'extraction_id' => $id,
    'listings_processed' => $count,
    'duration' => $time,
    'memory_peak' => $memory
]);
```

## Testing

### Unit Testing

```php
class BME_Data_Processor_Test extends WP_UnitTestCase {
    public function test_validate_listing_data() {
        $processor = new BME_Data_Processor();
        $valid_data = ['listing_id' => '123', 'price' => 500000];
        $result = $processor->validate_listing_data($valid_data);
        $this->assertTrue($result);
    }
}
```

### Integration Testing

```php
class BME_Extraction_Test extends WP_IntegrationTestCase {
    public function test_full_extraction_cycle() {
        $extraction_id = $this->create_test_extraction();
        $extractor = new BME_Extractor();
        $result = $extractor->run($extraction_id);
        $this->assertGreaterThan(0, $result['processed']);
    }
}
```

### Performance Testing

```php
// Benchmark extraction
$start = microtime(true);
$memory_start = memory_get_usage();

$extractor->run_extraction($id);

$duration = microtime(true) - $start;
$memory_used = memory_get_usage() - $memory_start;

$this->assertLessThan(60, $duration); // Under 60 seconds
$this->assertLessThan(268435456, $memory_used); // Under 256MB
```

## Deployment

### Requirements Check

```php
class BME_Requirements {
    const MIN_PHP = '7.4';
    const MIN_WP = '5.8';
    const MIN_MEMORY = '256M';

    public static function check() {
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            throw new Exception('PHP ' . self::MIN_PHP . ' required');
        }

        if (version_compare($GLOBALS['wp_version'], self::MIN_WP, '<')) {
            throw new Exception('WordPress ' . self::MIN_WP . ' required');
        }

        $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($memory < wp_convert_hr_to_bytes(self::MIN_MEMORY)) {
            throw new Exception('Memory limit ' . self::MIN_MEMORY . ' required');
        }
    }
}
```

### Migration Strategy

```php
class BME_Migrator {
    public function run_migrations() {
        $current = get_option('bme_db_version', '0');
        $target = BME_DB_VERSION;

        while ($current < $target) {
            $next = $this->get_next_version($current);
            $this->run_migration($next);
            $current = $next;
            update_option('bme_db_version', $current);
        }
    }
}
```

## Maintenance

### Database Maintenance

```sql
-- Regular optimization
OPTIMIZE TABLE wp_bme_listings;
ANALYZE TABLE wp_bme_listings;

-- Archive old data
INSERT INTO wp_bme_listings_archive
SELECT * FROM wp_bme_listings
WHERE standard_status = 'Closed'
AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Clean up logs
DELETE FROM wp_bme_activity_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Performance Monitoring

```php
// Track key metrics
$metrics = [
    'extraction_duration' => $duration,
    'listings_per_second' => $count / $duration,
    'memory_peak' => memory_get_peak_usage(true),
    'api_calls' => $api_call_count,
    'cache_hit_rate' => $cache_hits / ($cache_hits + $cache_misses)
];

// Store for analysis
update_option('bme_performance_metrics_' . date('Y-m-d'), $metrics);
```

---

## Appendix

### File Structure
See [README.md](README.md#plugin-structure) for complete file structure.

### Database Schema
See [SCHEMA.md](SCHEMA.md) for detailed database schema.

### API Reference
See [API.md](API.md) for complete API documentation.

### Troubleshooting
See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues and solutions.