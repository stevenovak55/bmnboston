# MLS Database Guide

Critical database patterns for the MLS Listings Display plugin.

## Summary Tables (CRITICAL)

**ALWAYS use summary tables for list queries - 25x faster than JOINs.**

| Table | Purpose | Rows | Use Case |
|-------|---------|------|----------|
| `bme_listing_summary` | Active/Pending | ~7,400 | Active, Pending, Coming Soon |
| `bme_listing_summary_archive` | Sold/Closed | ~90,000 | Sold, Closed, Expired |

## Performance Comparison

| Query Type | Using JOINs | Using Summary | Improvement |
|------------|-------------|---------------|-------------|
| Active listings | N/A | ~190ms | Baseline |
| Sold listings | 4-5 seconds | ~180ms | **25x faster** |
| Combined | 3-4 seconds | ~170ms | **20x faster** |

## Query Patterns

### CORRECT: Use Summary Table

```php
// Fast - ~200ms
$sql = "SELECT listing_key, listing_id, city, list_price, bedrooms_total,
        bathrooms_total, latitude, longitude, main_photo_url
        FROM {$wpdb->prefix}bme_listing_summary
        WHERE standard_status = 'Active'
        AND property_type = 'Residential'
        LIMIT 50";
```

### WRONG: Use Multi-Table JOINs

```php
// Slow - 4-5 seconds (DON'T DO THIS)
$sql = "SELECT l.*, d.bedrooms_total, loc.latitude, loc.longitude
        FROM {$wpdb->prefix}bme_listings_archive l
        LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON l.listing_id = d.listing_id
        LEFT JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id";
```

## Status-to-Table Routing

| Status Value | Table | Notes |
|--------------|-------|-------|
| Active | `bme_listing_summary` | Current |
| Pending | `bme_listing_summary` | Under contract |
| Coming Soon | `bme_listing_summary` | Pre-market |
| Sold | `bme_listing_summary_archive` | Maps to "Closed" |
| Closed | `bme_listing_summary_archive` | Completed |
| Expired | `bme_listing_summary_archive` | Expired |

**Important:** iOS uses "Sold" but database stores "Closed":
```php
$status_map = ['Sold' => 'Closed'];
$db_status = $status_map[$status] ?? $status;
```

## Identifier Types

| Table | Column | Format | Use For |
|-------|--------|--------|---------|
| Summary | `listing_key` | MD5 hash | API lookups |
| Summary | `listing_id` | MLS number | Cross-table joins |
| Details | `listing_id` | MLS number | Always |
| Media | `listing_id` | MLS number | Always |
| Location | `listing_id` | MLS number | Always |

## Cross-Table Join Pattern

```php
// 1. Get listing from summary by hash
$listing = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bme_listing_summary WHERE listing_key = %s",
    $hash
));

// 2. Use listing_id (NOT listing_key) for other tables
$photos = $wpdb->get_col($wpdb->prepare(
    "SELECT media_url FROM {$wpdb->prefix}bme_media
     WHERE listing_id = %s AND media_category = 'Photo'
     ORDER BY order_index ASC",
    $listing->listing_id  // <-- MLS number
));

$details = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %s",
    $listing->listing_id
));

$location = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bme_listing_location WHERE listing_id = %s",
    $listing->listing_id
));
```

## Combined Status Queries

When querying both active AND sold, use UNION:

```php
$active_sql = "SELECT listing_key, listing_id, city, list_price
               FROM {$wpdb->prefix}bme_listing_summary
               WHERE standard_status IN ('Active', 'Pending')";

$archive_sql = "SELECT listing_key, listing_id, city, list_price
                FROM {$wpdb->prefix}bme_listing_summary_archive
                WHERE standard_status IN ('Closed')";

$combined = "({$active_sql}) UNION ALL ({$archive_sql})
             ORDER BY list_date DESC LIMIT 50";
```

**CRITICAL:** Both tables MUST have same collation (`utf8mb4_unicode_520_ci`).

## Summary Table Columns

| Column | Type | Description |
|--------|------|-------------|
| `listing_id` | INT | MLS number |
| `listing_key` | VARCHAR(128) | MD5 hash |
| `property_type` | VARCHAR(50) | Residential, Commercial, Land |
| `standard_status` | VARCHAR(50) | Active, Pending, Closed |
| `list_price` | DECIMAL(20,2) | Current price |
| `close_price` | DECIMAL(20,2) | Sale price (archive) |
| `bedrooms_total` | INT | Bedrooms |
| `bathrooms_total` | DECIMAL(3,1) | Bathrooms |
| `building_area_total` | INT | Square feet |
| `city` | VARCHAR(100) | City |
| `postal_code` | VARCHAR(10) | ZIP |
| `latitude` | DECIMAL(10,8) | GPS lat |
| `longitude` | DECIMAL(11,8) | GPS lng |
| `main_photo_url` | VARCHAR(500) | Primary photo |
| `days_on_market` | INT | DOM |

## Archive Summary Refresh

The archive summary table is populated via stored procedure:

```sql
CALL populate_listing_summary_archive();
```

Run this:
- After bulk archive imports
- Hourly via cron
- After any archive modifications

## Common Mistakes

1. **Using `listing_key` for media queries** - Use `listing_id`
2. **Forgetting status mapping** - "Sold" → "Closed"
3. **Multi-table JOINs for list queries** - Use summary tables
4. **Wrong table for status** - Check routing table above

## Recently Viewed Properties Table (v6.57.0)

Tracks property views from iOS app and web for analytics.

### Schema

```sql
CREATE TABLE wp_mld_recently_viewed_properties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    listing_id VARCHAR(50) NOT NULL,
    listing_key VARCHAR(128) NOT NULL,
    viewed_at DATETIME NOT NULL,
    view_source ENUM('search', 'direct', 'shared', 'notification', 'saved_search') DEFAULT 'search',
    platform ENUM('ios', 'web', 'admin') DEFAULT 'ios',
    ip_address VARCHAR(45) NULL COMMENT 'IP for anonymous visitors (user_id=0)',
    UNIQUE KEY user_listing (user_id, listing_id),
    KEY listing_id (listing_id),
    KEY viewed_at (viewed_at),
    KEY platform (platform)
);
```

### Key Points

| Column | Purpose |
|--------|---------|
| `user_id` | WordPress user ID (0 for anonymous) |
| `listing_id` | MLS number (for cross-table joins) |
| `listing_key` | MD5 hash (for API lookups) |
| `view_source` | How user arrived (search, direct URL, notification, etc.) |
| `platform` | iOS app, web browser, or admin preview |
| `ip_address` | Captured for anonymous visitors for geolocation |

### Tracking Sources

- **iOS**: `POST /recently-viewed` endpoint with JWT auth
- **Web**: `do_action('mld_property_viewed', $listing_id)` hook in templates
- Uses `ON DUPLICATE KEY UPDATE` to update timestamp on repeat views
