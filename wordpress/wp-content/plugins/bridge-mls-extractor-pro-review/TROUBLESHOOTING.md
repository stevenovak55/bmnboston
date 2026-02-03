# Bridge MLS Extractor Pro - Troubleshooting Guide

## Table of Contents

1. [Common Issues](#common-issues)
2. [Installation Problems](#installation-problems)
3. [Extraction Issues](#extraction-issues)
4. [Database Issues](#database-issues)
5. [Performance Problems](#performance-problems)
6. [API Connection Issues](#api-connection-issues)
7. [Display Issues](#display-issues)
8. [Diagnostic Tools](#diagnostic-tools)
9. [Error Codes](#error-codes)
10. [Getting Help](#getting-help)

---

## Common Issues

### Plugin Won't Activate

**Symptoms:**
- Error message when activating plugin
- White screen after activation
- Plugin deactivates immediately

**Solutions:**

1. **Check PHP Version**
```bash
php -v
# Should be 7.4 or higher
```

2. **Verify WordPress Version**
- Go to Dashboard → Updates
- Should be WordPress 5.8 or higher

3. **Check Memory Limit**
```php
// Add to wp-config.php
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

4. **Enable Debug Mode**
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

5. **Check Error Log**
```bash
tail -f wp-content/debug.log
```

### Database Tables Not Created

**Symptoms:**
- Missing tables error
- SQL syntax errors
- Activation succeeds but no tables

**Solutions:**

1. **Manual Table Creation**
```sql
-- Check if tables exist
SHOW TABLES LIKE 'wp_bme_%';

-- If missing, check database permissions
SHOW GRANTS FOR CURRENT_USER;
```

2. **Force Reinstallation**
```php
// Run in WordPress admin
delete_option('bme_db_version');
// Then deactivate and reactivate plugin
```

3. **Check Charset Issues**
```sql
-- Verify database charset
SHOW VARIABLES LIKE 'character_set_database';
-- Should be utf8mb4
```

### No Data After Extraction

**Symptoms:**
- Extraction runs but no listings appear
- Success message but 0 listings processed
- Tables remain empty

**Solutions:**

1. **Verify API Credentials**
```php
// Test API connection
$api = bme_pro()->get('api');
$result = $api->validate_credentials();
var_dump($result);
```

2. **Check Extraction Filters**
- Go to Extraction profile
- Verify filters aren't too restrictive
- Try removing all filters for test

3. **Review Activity Logs**
```sql
SELECT * FROM wp_bme_activity_logs
WHERE extraction_id = YOUR_ID
ORDER BY created_at DESC
LIMIT 50;
```

---

## Installation Problems

### Missing Dependencies

**Error:** "Class not found" or "Fatal error: require_once"

**Solution:**
```bash
# Verify all files are present
cd wp-content/plugins/bridge-mls-extractor-pro
ls -la includes/

# Check file permissions
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

### Activation Hook Failed

**Error:** "The plugin does not have a valid header"

**Solution:**
1. Re-download plugin
2. Check file encoding (should be UTF-8 without BOM)
3. Verify first line of main plugin file:
```php
<?php
/**
 * Plugin Name: Bridge MLS Extractor Pro
 */
```

### Database Creation Failed

**Error:** "Table creation failed"

**Solution:**
```php
// Test database creation manually
global $wpdb;
$charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE test_table (
    id INT AUTO_INCREMENT PRIMARY KEY
) $charset_collate;";

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
dbDelta($sql);
```

---

## Extraction Issues

### Extraction Stuck or Hanging

**Symptoms:**
- Progress bar frozen
- Status shows "Running" indefinitely
- No new activity in logs

**Solutions:**

1. **Clear Extraction Lock**
```php
// Force clear all locks
$processor = bme_pro()->get('background_processor');
$processor->clear_all_extraction_locks();
```

2. **Check Background Processes**
```bash
# Check if WP Cron is running
wp cron event list | grep bme

# Trigger cron manually
wp cron event run --all
```

3. **Reset Extraction State**
```php
delete_post_meta($extraction_id, '_bme_extraction_state');
delete_transient('bme_extraction_lock_' . $extraction_id);
delete_transient('bme_live_progress_' . $extraction_id);
```

### Memory Limit Exceeded

**Error:** "Allowed memory size exhausted"

**Solutions:**

1. **Increase Memory Limit**
```php
// In wp-config.php
define('WP_MEMORY_LIMIT', '512M');

// Or in .htaccess
php_value memory_limit 512M

// Or in php.ini
memory_limit = 512M
```

2. **Reduce Batch Size**
```php
// In extraction settings
update_post_meta($extraction_id, '_bme_batch_size', 50);
```

3. **Enable Batch Processing**
```php
update_post_meta($extraction_id, '_bme_use_batch_processing', true);
update_post_meta($extraction_id, '_bme_batch_delay', 60);
```

### Timeout Errors

**Error:** "Maximum execution time exceeded"

**Solutions:**

1. **Increase Time Limit**
```php
// In .htaccess
php_value max_execution_time 300

// Or in php.ini
max_execution_time = 300
```

2. **Use Background Processing**
- Enable "Run in Background" in extraction settings
- Set smaller session limits

3. **Configure WP Cron**
```php
// In wp-config.php
define('ALTERNATE_WP_CRON', true);
define('WP_CRON_LOCK_TIMEOUT', 120);
```

---

## Database Issues

### Slow Queries

**Symptoms:**
- Admin pages load slowly
- Timeouts when viewing listings
- High server CPU usage

**Solutions:**

1. **Check Missing Indexes**
```sql
-- Show existing indexes
SHOW INDEX FROM wp_bme_listings;

-- Add missing indexes
ALTER TABLE wp_bme_listings
ADD INDEX idx_status_date (standard_status, list_date);

ALTER TABLE wp_bme_listing_location
ADD SPATIAL INDEX idx_coordinates (coordinates);
```

2. **Optimize Tables**
```sql
OPTIMIZE TABLE wp_bme_listings;
ANALYZE TABLE wp_bme_listings;
```

3. **Enable Query Cache**
```php
// Check if enabled
SHOW VARIABLES LIKE 'query_cache_%';

// Enable in my.cnf
query_cache_type = 1
query_cache_size = 64M
```

### Duplicate Entry Errors

**Error:** "Duplicate entry for key 'listing_id'"

**Solutions:**

1. **Clear Duplicates**
```sql
-- Find duplicates
SELECT listing_id, COUNT(*)
FROM wp_bme_listings
GROUP BY listing_id
HAVING COUNT(*) > 1;

-- Remove duplicates (keep newest)
DELETE t1 FROM wp_bme_listings t1
INNER JOIN wp_bme_listings t2
WHERE t1.id < t2.id
AND t1.listing_id = t2.listing_id;
```

2. **Fix Unique Constraint**
```sql
ALTER TABLE wp_bme_listings
ADD UNIQUE KEY uk_listing_id (listing_id);
```

### Location Data Not Saving

**Symptoms:**
- Coordinates are NULL
- Map shows no markers
- Spatial queries fail

**Solutions:**

1. **Verify Spatial Support**
```sql
-- Check if spatial functions are available
SELECT ST_GeomFromText('POINT(0 0)');
```

2. **Fix Coordinate Format**
```php
// Correct format for saving
$point = sprintf('POINT(%f %f)', $longitude, $latitude);
$sql = "UPDATE wp_bme_listing_location
        SET coordinates = ST_GeomFromText(%s)
        WHERE listing_id = %s";
```

---

## Performance Problems

### Slow Page Load

**Symptoms:**
- Pages take >5 seconds to load
- Browser timeout errors
- High server load

**Solutions:**

1. **Enable Caching**
```php
// Install object cache
// wp-content/object-cache.php

// Configure in wp-config.php
define('WP_CACHE', true);
```

2. **Optimize Queries**
```php
// Use pagination
$listings = $wpdb->get_results(
    "SELECT * FROM wp_bme_listings
     LIMIT 100 OFFSET 0"
);

// Add specific columns
$listings = $wpdb->get_results(
    "SELECT listing_id, list_price, city
     FROM wp_bme_listings"
);
```

3. **Check Slow Query Log**
```sql
-- Enable slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;

-- View slow queries
SHOW VARIABLES LIKE 'slow_query_log_file';
```

### High Memory Usage

**Symptoms:**
- Out of memory errors
- Server crashes
- Slow response times

**Solutions:**

1. **Monitor Memory Usage**
```php
// Add to extraction
error_log('Memory: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
error_log('Peak: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB');
```

2. **Free Memory During Processing**
```php
// In extraction loop
if ($count % 100 === 0) {
    $wpdb->flush();
    wp_cache_flush();
}
```

3. **Use Batch Processing**
```php
// Process in smaller chunks
$batch_size = 50;
$offset = 0;

while ($items = get_next_batch($batch_size, $offset)) {
    process_batch($items);
    $offset += $batch_size;

    // Free memory
    unset($items);
}
```

---

## API Connection Issues

### Authentication Failed

**Error:** "API authentication failed"

**Solutions:**

1. **Verify Credentials**
```php
// Check stored credentials
$creds = get_option('bme_pro_api_credentials');
var_dump($creds);
```

2. **Test API Endpoint**
```bash
curl -X GET "https://api.bridgeinteractive.com/api/v2/listings" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/json"
```

3. **Check SSL Certificate**
```php
// Temporarily disable SSL verification (not for production!)
add_filter('https_ssl_verify', '__return_false');
```

### Rate Limiting

**Error:** "Too many requests"

**Solutions:**

1. **Increase Delay Between Requests**
```php
// In API client
private $rate_limit_delay = 3; // Increase from 2 to 3 seconds
```

2. **Implement Exponential Backoff**
```php
$retry = 0;
$max_retries = 3;

while ($retry < $max_retries) {
    $response = make_api_request();

    if ($response !== false) {
        break;
    }

    $wait = pow(2, $retry) * 1000000; // Exponential backoff
    usleep($wait);
    $retry++;
}
```

### Timeout Errors

**Error:** "Connection timeout"

**Solutions:**

1. **Increase Timeout**
```php
// In API client
$this->timeout = 30; // Increase from default

// For wp_remote_get
$args = [
    'timeout' => 30,
    'redirection' => 5,
    'blocking' => true
];
```

2. **Check Network**
```bash
# Test connectivity
ping api.bridgeinteractive.com

# Check DNS
nslookup api.bridgeinteractive.com

# Test with curl
curl -I https://api.bridgeinteractive.com
```

---

## Display Issues

### Map Not Showing

**Symptoms:**
- Blank map area
- JavaScript errors
- "Google Maps API error"

**Solutions:**

1. **Check API Key**
```javascript
// In browser console
console.log(typeof google !== 'undefined' ? 'Maps loaded' : 'Maps not loaded');
```

2. **Verify API Restrictions**
- Go to Google Cloud Console
- Check API key restrictions
- Ensure domain is whitelisted

3. **Check JavaScript Errors**
```javascript
// Look for errors in console
// Common: "RefererNotAllowedMapError"
// Solution: Add domain to API key restrictions
```

### Listings Not Displaying

**Symptoms:**
- Empty results
- "No listings found"
- Database has data but not showing

**Solutions:**

1. **Check Query**
```php
// Debug query
global $wpdb;
$wpdb->show_errors();
$results = $wpdb->get_results($query);
echo $wpdb->last_error;
```

2. **Verify Permissions**
```php
// Check user capabilities
if (!current_user_can('read')) {
    error_log('User cannot read listings');
}
```

3. **Clear Cache**
```php
// Clear all transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bme_%'");
```

---

## Diagnostic Tools

### Debug Mode Configuration

```php
// Complete debug setup in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
define('SAVEQUERIES', true);

// Plugin-specific debugging
define('BME_DEBUG', true);
define('BME_LOG_QUERIES', true);
define('BME_LOG_API_CALLS', true);
```

### Health Check Script

Create `health-check.php`:
```php
<?php
require_once('wp-load.php');

echo "=== BME Pro Health Check ===\n\n";

// Check plugin active
if (is_plugin_active('bridge-mls-extractor-pro/bridge-mls-extractor-pro.php')) {
    echo "✓ Plugin is active\n";
} else {
    echo "✗ Plugin is not active\n";
}

// Check database tables
global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}bme_%'");
echo "✓ Found " . count($tables) . " BME tables\n";

// Check API connection
$api = bme_pro()->get('api');
if ($api->validate_credentials()) {
    echo "✓ API connection successful\n";
} else {
    echo "✗ API connection failed\n";
}

// Check memory
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max execution time: " . ini_get('max_execution_time') . "\n";

// Check recent extractions
$recent = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}bme_activity_logs
     WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
);
echo "Activities in last 24h: " . $recent . "\n";
```

### Query Monitor

```php
// Add to functions.php for query monitoring
add_action('shutdown', function() {
    if (!defined('SAVEQUERIES') || !SAVEQUERIES) return;

    global $wpdb;

    $slow_queries = array_filter($wpdb->queries, function($query) {
        return $query[1] > 0.05; // Queries taking > 0.05 seconds
    });

    if (!empty($slow_queries)) {
        error_log('=== Slow Queries ===');
        foreach ($slow_queries as $query) {
            error_log(sprintf(
                "Query: %s\nTime: %f\nCaller: %s\n",
                $query[0],
                $query[1],
                $query[2]
            ));
        }
    }
});
```

### API Call Logger

```php
// Log all API calls for debugging
add_filter('bme_api_request', function($args, $endpoint) {
    error_log(sprintf(
        "API Request: %s\nArgs: %s",
        $endpoint,
        json_encode($args)
    ));
    return $args;
}, 10, 2);

add_filter('bme_api_response', function($response, $endpoint) {
    error_log(sprintf(
        "API Response from %s: %s",
        $endpoint,
        substr(json_encode($response), 0, 500)
    ));
    return $response;
}, 10, 2);
```

---

## Error Codes

### BME001 - API Authentication Failed
**Meaning:** API credentials are invalid or expired
**Solution:** Update API credentials in settings

### BME002 - API Rate Limit Exceeded
**Meaning:** Too many API requests
**Solution:** Wait and retry, increase delay between requests

### BME003 - API Timeout
**Meaning:** API request took too long
**Solution:** Increase timeout, check network connection

### BME004 - Database Connection Failed
**Meaning:** Cannot connect to database
**Solution:** Check database credentials, server status

### BME005 - Invalid Extraction Profile
**Meaning:** Extraction configuration is corrupted
**Solution:** Recreate extraction profile

### BME006 - Memory Limit Exceeded
**Meaning:** PHP ran out of memory
**Solution:** Increase memory limit, use batch processing

### BME007 - Time Limit Exceeded
**Meaning:** Script execution time exceeded
**Solution:** Increase time limit, use background processing

### BME008 - Invalid Filter Syntax
**Meaning:** API filter format is incorrect
**Solution:** Check filter syntax in extraction profile

### BME009 - Table Creation Failed
**Meaning:** Cannot create database tables
**Solution:** Check database permissions, charset issues

### BME010 - Lock Acquisition Failed
**Meaning:** Another process is running
**Solution:** Wait or clear stale locks

---

## Getting Help

### Before Contacting Support

1. **Enable Debug Mode** and collect error logs
2. **Run Health Check** script
3. **Document Steps** to reproduce issue
4. **Check Version** compatibility
5. **Review This Guide** for solutions

### Information to Provide

When contacting support, include:

```
=== Environment ===
WordPress Version:
PHP Version:
MySQL Version:
Plugin Version:
Server Type:

=== Issue ===
Description:
Error Messages:
When Started:
Steps to Reproduce:

=== Debug Info ===
[Attach debug.log]
[Attach health check output]
[Attach relevant database queries]
```

### Support Channels

1. **Documentation**
   - README.md - General information
   - TECHNICAL.md - Architecture details
   - API.md - API reference
   - HOOKS.md - Customization guide

2. **Logs to Check**
   ```bash
   # WordPress debug log
   wp-content/debug.log

   # PHP error log
   /var/log/php-error.log

   # MySQL error log
   /var/log/mysql/error.log

   # Apache/Nginx error log
   /var/log/apache2/error.log
   /var/log/nginx/error.log
   ```

3. **Emergency Recovery**
   ```php
   // If plugin breaks site, add to wp-config.php
   define('BME_EMERGENCY_DISABLE', true);

   // Or rename plugin folder via FTP/SSH
   mv bridge-mls-extractor-pro bridge-mls-extractor-pro.disabled
   ```

---

## Quick Fixes Checklist

### Daily Maintenance
- [ ] Check error logs
- [ ] Verify extraction schedule
- [ ] Monitor API quota
- [ ] Check disk space

### Weekly Maintenance
- [ ] Optimize database tables
- [ ] Clear old logs
- [ ] Review slow queries
- [ ] Update statistics

### Monthly Maintenance
- [ ] Full database backup
- [ ] Review and archive old data
- [ ] Check for plugin updates
- [ ] Performance audit

### When Issues Occur
1. [ ] Check error logs
2. [ ] Verify API connection
3. [ ] Clear cache
4. [ ] Check database tables
5. [ ] Review recent changes
6. [ ] Test in staging environment
7. [ ] Rollback if necessary

---

**Remember:** Always backup your database before making changes, and test fixes in a staging environment first.