# BMN Schools - Lessons Learned & What NOT To Do

This document captures mistakes, gotchas, and best practices discovered during development.

---

## From Existing BMN Plugins

These lessons are inherited from the MLS Listings Display and Bridge MLS Extractor plugins:

### Database

❌ **DON'T** use `listing_key` for cross-table joins (it's an MD5 hash for API lookups only)
✅ **DO** use `listing_id` (or `school_id`) for cross-table relationships

❌ **DON'T** use JOINs for list queries on large tables (archive has 90K+ rows)
✅ **DO** use denormalized summary tables for fast list queries (25x faster)

❌ **DON'T** forget to specify collation
✅ **DO** ensure all tables use `utf8mb4_unicode_520_ci` for UNION compatibility

❌ **DON'T** use `timestamp` for date columns (WordPress uses DATETIME)
✅ **DO** use `created_at DATETIME` and `updated_at DATETIME`

### Version Management

❌ **DON'T** update just one version location
✅ **DO** update ALL 3 locations: version.json, header comment, constant

### API Integration

❌ **DON'T** call external APIs without rate limiting
✅ **DO** add delays between API calls (at least 1-2 seconds)

❌ **DON'T** skip caching for external API responses
✅ **DO** cache responses for 30+ minutes to reduce API calls

❌ **DON'T** silently fail on API errors
✅ **DO** log all API errors with full context (URL, params, response)

### Data Import

❌ **DON'T** import data without validation
✅ **DO** validate and sanitize all imported data

❌ **DON'T** process large datasets in a single request
✅ **DO** use batch processing with progress tracking

❌ **DON'T** lose track of data sources
✅ **DO** store metadata about where data came from and when

### REST API

❌ **DON'T** return inconsistent response formats
✅ **DO** always wrap responses: `{ "success": true, "data": {...} }`

❌ **DON'T** skip permission checks
✅ **DO** use `permission_callback` for every route

### Testing

❌ **DON'T** test against local Docker dev server
✅ **DO** test against PRODUCTION (bmnboston.com)

---

## BMN Schools Specific

(Will be populated as we encounter issues during development)

### Data Sources

(Add lessons about NCES, DESE, ATTOM APIs here)

### School Data

(Add lessons about school data quirks here)

### Geographic Data

(Add lessons about GeoJSON, boundaries, point-in-polygon here)

---

## Quick Reference: Common Patterns

### Safe Database Query
```php
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bmn_schools WHERE city = %s LIMIT %d",
    $city,
    $limit
));
```

### Proper Error Logging
```php
BMN_Schools_Logger::log('error', 'api_call', 'NCES API failed', [
    'url' => $url,
    'response_code' => wp_remote_retrieve_response_code($response),
    'body' => wp_remote_retrieve_body($response)
]);
```

### Rate-Limited API Call
```php
public function fetch_with_rate_limit($url) {
    static $last_call = 0;
    $delay = 2; // seconds

    $elapsed = microtime(true) - $last_call;
    if ($elapsed < $delay) {
        usleep(($delay - $elapsed) * 1000000);
    }

    $response = wp_remote_get($url, ['timeout' => 30]);
    $last_call = microtime(true);

    return $response;
}
```
