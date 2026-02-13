# Claude Code Reference - MLS Listings Display Plugin

Quick reference for AI-assisted development.

**Current Version:** 6.76.0
**Last Updated:** February 13, 2026

---

## Testing Environment

**IMPORTANT:** All testing is done against **PRODUCTION** (bmnboston.com), NOT localhost.
- **API Base URL:** `https://bmnboston.com/wp-json/mld-mobile/v1`
- **Baseline Data:** ~18,500 total properties, ~7,400 For Sale (Residential)

---

## Database Architecture - CRITICAL FOR PERFORMANCE

### Summary Tables (USE THESE FOR LIST QUERIES!)

The database uses **denormalized summary tables** for fast property list queries. These tables contain pre-joined data from multiple normalized tables, eliminating the need for expensive JOINs.

| Table | Purpose | Row Count | Use Case |
|-------|---------|-----------|----------|
| `bme_listing_summary` | Active/Pending listings | ~7,400 | Status: Active, Pending, Coming Soon |
| `bme_listing_summary_archive` | Sold/Closed listings | ~90,000 | Status: Sold, Closed, Expired, Withdrawn |

### CRITICAL: Always Use Summary Tables for List Queries

**CORRECT (Fast - ~200ms):**
```php
// Active listings - use summary table directly
$sql = "SELECT listing_key, listing_id, city, list_price, bedrooms_total,
        bathrooms_total, latitude, longitude, main_photo_url
        FROM {$wpdb->prefix}bme_listing_summary
        WHERE standard_status = 'Active' AND property_type = 'Residential'
        LIMIT 50";

// Sold listings - use archive summary table directly
$sql = "SELECT listing_key, listing_id, city, list_price, close_price,
        bedrooms_total, bathrooms_total, latitude, longitude, main_photo_url
        FROM {$wpdb->prefix}bme_listing_summary_archive
        WHERE standard_status = 'Closed' AND property_type = 'Residential'
        LIMIT 50";
```

**WRONG (Slow - 4-5 seconds):**
```php
// DO NOT use multi-table JOINs for list queries!
$sql = "SELECT l.*, d.bedrooms_total, loc.latitude, loc.longitude
        FROM {$wpdb->prefix}bme_listings_archive l
        LEFT JOIN {$wpdb->prefix}bme_listing_details_archive d ON l.listing_id = d.listing_id
        LEFT JOIN {$wpdb->prefix}bme_listing_location_archive loc ON l.listing_id = loc.listing_id
        LEFT JOIN {$wpdb->prefix}bme_listing_features_archive f ON l.listing_id = f.listing_id
        WHERE l.standard_status = 'Closed'";
// This query takes 4-5 seconds with 90K rows!
```

### Summary Table Column Reference

Both summary tables (`bme_listing_summary` and `bme_listing_summary_archive`) have identical structure:

| Column | Type | Description |
|--------|------|-------------|
| `listing_id` | INT | Primary key, MLS number |
| `listing_key` | VARCHAR(128) | MD5 hash for API lookups |
| `property_type` | VARCHAR(50) | Residential, Commercial, Land |
| `property_sub_type` | VARCHAR(50) | Single Family, Condo, etc. |
| `standard_status` | VARCHAR(50) | Active, Pending, Closed, etc. |
| `list_price` | DECIMAL(20,2) | Current/final list price |
| `original_list_price` | DECIMAL(20,2) | Original list price |
| `close_price` | DECIMAL(20,2) | Sale price (archive only) |
| `bedrooms_total` | INT | Number of bedrooms |
| `bathrooms_total` | DECIMAL(3,1) | Total bathrooms |
| `bathrooms_full` | INT | Full bathrooms |
| `bathrooms_half` | INT | Half bathrooms |
| `building_area_total` | INT | Square footage |
| `lot_size_acres` | DECIMAL(10,4) | Lot size in acres |
| `year_built` | INT | Year constructed |
| `street_number` | VARCHAR(50) | Street number |
| `street_name` | VARCHAR(100) | Street name |
| `city` | VARCHAR(100) | City name |
| `state_or_province` | VARCHAR(2) | State code (MA) |
| `postal_code` | VARCHAR(10) | ZIP code |
| `latitude` | DECIMAL(10,8) | GPS latitude |
| `longitude` | DECIMAL(11,8) | GPS longitude |
| `main_photo_url` | VARCHAR(500) | Primary photo URL |
| `listing_contract_date` | DATE | List date |
| `close_date` | DATE | Sale date (archive only) |
| `days_on_market` | INT | DOM |
| `subdivision_name` | VARCHAR(100) | Neighborhood name |
| `garage_spaces` | INT | Number of garage spaces |

### Status-to-Table Routing

| Status Value | Table to Query | Notes |
|--------------|----------------|-------|
| `Active` | `bme_listing_summary` | Current active listings |
| `Pending` | `bme_listing_summary` | Under contract |
| `Coming Soon` | `bme_listing_summary` | Pre-market |
| `Sold` | `bme_listing_summary_archive` | Maps to `Closed` in DB |
| `Closed` | `bme_listing_summary_archive` | Completed sales |
| `Expired` | `bme_listing_summary_archive` | Listing expired |
| `Withdrawn` | `bme_listing_summary_archive` | Taken off market |
| `Canceled` | `bme_listing_summary_archive` | Listing canceled |

**Important:** The iOS app uses "Sold" but the database stores "Closed". Always map:
```php
$status_map = array('Sold' => 'Closed');
$db_status = isset($status_map[$status]) ? $status_map[$status] : $status;
```

### Combined Status Queries (Active + Sold)

When querying both active AND archive statuses, use UNION:

```php
// Fast combined query using both summary tables
$active_sql = "SELECT listing_key, listing_id, city, list_price, ...
               FROM {$wpdb->prefix}bme_listing_summary
               WHERE standard_status IN ('Active', 'Pending')";

$archive_sql = "SELECT listing_key, listing_id, city, list_price, ...
                FROM {$wpdb->prefix}bme_listing_summary_archive
                WHERE standard_status IN ('Closed')";

$combined_sql = "({$active_sql}) UNION ALL ({$archive_sql})
                 ORDER BY list_date DESC LIMIT 50";
```

**CRITICAL:** Both tables MUST have the same collation (`utf8mb4_unicode_520_ci`) for UNION to work!

### When to Use Normalized Tables

Use the normalized archive tables ONLY for:
1. **Property Detail** - When you need ALL fields for a single listing
2. **Data Export** - When exporting complete records
3. **Admin/Backend** - Non-performance-critical operations

Normalized tables:
- `bme_listings_archive` - Core listing data
- `bme_listing_details_archive` - Extended details (rooms, features)
- `bme_listing_location_archive` - Address, coordinates
- `bme_listing_features_archive` - Amenities, pool, etc.
- `bme_listing_financial_archive` - HOA, taxes

### Performance Comparison (Tested Dec 19, 2025)

| Query Type | Using JOINs | Using Summary Table | Improvement |
|------------|-------------|---------------------|-------------|
| Active listings | N/A | ~190ms | Baseline |
| Sold listings | 4-5 seconds | ~180ms | **25x faster** |
| Sold with map bounds | 4-5 seconds | ~150ms | **30x faster** |
| Combined Active+Sold | 3-4 seconds | ~170ms | **20x faster** |

**All queries now complete in under 200ms.**

### Archive Summary Table Refresh

The `bme_listing_summary_archive` table is populated via stored procedure:
```sql
CALL populate_listing_summary_archive();
```

This should be run:
- After bulk archive imports
- Periodically (hourly recommended) via cron
- After any archive table modifications

---

## Critical Rules

### 1. ALWAYS Update Version Numbers (ALL 3 LOCATIONS!)

**1. version.json:**
```json
{
    "version": "X.Y.Z",
    "db_version": "X.Y.Z",
    "last_updated": "YYYY-MM-DD"
}
```

**2. mls-listings-display.php header:**
```php
* Version: X.Y.Z
```

**3. mls-listings-display.php constant:**
```php
define('MLD_VERSION', 'X.Y.Z');
```

### 2. Add Changelog Entry
```php
* Version X.Y.Z - BRIEF DESCRIPTION
* - FIX/FEATURE: What changed
```

### 3. Create Updated Zip
```bash
cd ~/Development/BMNBoston/wordpress/wp-content/plugins
zip -r mls-listings-display-X.Y.Z.zip mls-listings-display \
    -x "*.git*" -x "*node_modules*" -x "*.DS_Store"
```

---

## Database Schema - CRITICAL

### Table Identifier Formats

| Table | Use This Column | Format |
|-------|-----------------|--------|
| `bme_listing_summary` | `listing_key` | MD5 hash (for lookups) |
| `bme_listing_summary` | `listing_id` | MLS number |
| `bme_listing_details` | `listing_id` | MLS number |
| `bme_listing_location` | `listing_id` | MLS number |
| `bme_media` | `listing_id` | MLS number |

### IMPORTANT: Cross-Table Joins

```php
// After getting listing from summary table...
$listing = $wpdb->get_row("SELECT * FROM bme_listing_summary WHERE listing_key = %s", $id);

// Use listing_id (NOT listing_key) for other tables:
$details = $wpdb->get_row("SELECT * FROM bme_listing_details WHERE listing_id = %s", $listing->listing_id);
$photos = $wpdb->get_col("SELECT media_url FROM bme_media WHERE listing_id = %s", $listing->listing_id);
$location = $wpdb->get_row("SELECT * FROM bme_listing_location WHERE listing_id = %s", $listing->listing_id);
```

### Photos Query Pattern
```php
$photos = $wpdb->get_col($wpdb->prepare(
    "SELECT media_url FROM {$wpdb->prefix}bme_media
     WHERE listing_id = %s AND media_category = 'Photo'
     ORDER BY order_index ASC",
    $listing->listing_id  // MLS number, NOT listing_key hash
));
```

### CRITICAL: Property Page URLs Use listing_id (MLS Number)

**Property detail page URLs MUST use `listing_id` (MLS number), NOT `listing_key` (MD5 hash).**

```php
// CORRECT - Use listing_id for property URLs
$url = home_url('/property/' . $listing->listing_id . '/');
// Result: https://bmnboston.com/property/73464868/

// WRONG - Don't use listing_key for URLs
$url = home_url('/property/' . $listing->listing_key . '/');
// Result: https://bmnboston.com/property/928c77fa6877d5c35c852989c83e5068/ (BROKEN!)
```

**In Vue.js/JavaScript templates:**
```javascript
// CORRECT - Use listing_id for href
:href="homeUrl + '/property/' + property.listing_id + '/'"

// WRONG - Don't use id or listing_key
:href="homeUrl + '/property/' + property.id + '/'"  // This is listing_key!
```

**When building API responses that include property data, ALWAYS include both:**
```php
return [
    'id' => $listing->listing_key,           // For API lookups/identification
    'listing_id' => $listing->listing_id,    // For property page URLs
    'listing_key' => $listing->listing_key,  // Explicit key for clarity
    // ... other fields
];
```

| Field | Purpose | Example |
|-------|---------|---------|
| `listing_id` | Property page URLs, display to users | `73464868` |
| `listing_key` | API lookups, database queries | `928c77fa6877d5c35c852989c83e5068` |
| `id` | Often equals `listing_key` in APIs | (check context) |

---

## Mobile REST API

### Namespace
```
/wp-json/mld-mobile/v1/
```

### Key Endpoints

| Endpoint | Method | Key Params |
|----------|--------|------------|
| `/properties` | GET | See filter parameters below |
| `/properties/{id}` | GET | id = listing_key hash |
| `/search/autocomplete` | GET | `term` (query string) |
| `/auth/login` | POST | `email`, `password` |
| `/favorites` | GET/POST | Requires auth |
| `/saved-searches` | GET/POST | Requires auth |

### Supported Filter Parameters

| Category | Parameters |
|----------|------------|
| **Location** | `city`, `zip`, `neighborhood`, `address`, `mls_number`, `street_name`, `bounds`, `polygon` |
| **Property Type** | `property_type` (Residential, Residential Lease, Commercial Sale, Land) |
| **Price** | `min_price`, `max_price`, `price_reduced` |
| **Rooms** | `beds`, `baths` |
| **Size** | `sqft_min`, `sqft_max`, `lot_size_min`, `lot_size_max` |
| **Age** | `year_built_min`, `year_built_max` |
| **Parking** | `garage_spaces_min`, `parking_total_min` |
| **Status** | `status` (Active, Pending, Sold) |
| **Time** | `new_listing_days`, `max_dom` |
| **Amenities** | `PoolPrivateYN`, `WaterfrontYN`, `FireplaceYN`, `GarageYN`, `CoolingYN`, `SpaYN`, `ViewYN`, `MLSPIN_WATERVIEW_FLAG`, `SeniorCommunityYN` |
| **Special** | `open_house_only`, `has_virtual_tour` |
| **Schools** | `school_grade`, `near_top_elementary`, `near_top_high`, `school_district_id` |
| **Sort** | `sort` (price_asc, price_desc, list_date_asc, beds_desc, sqft_desc) |

### School Filter Parameters (v6.29.0)

| Parameter | Type | Description |
|-----------|------|-------------|
| `school_grade` | string | Minimum school grade: A, B, C (properties near schools at or above this grade within 2mi) |
| `near_top_elementary` | bool | `true` = within 2 miles of A-rated elementary school |
| `near_top_high` | bool | `true` = within 3 miles of A-rated high school |
| `school_district_id` | int | Filter by specific school district ID |

**Note:** School filters use post-query filtering. When active, the API over-fetches (3x) to ensure consistent pagination.

### New Location Filters (v6.27.17)

| Parameter | Description |
|-----------|-------------|
| `address` | Full address for exact match against `unparsed_address` |
| `mls_number` | MLS number for exact match against `listing_id` |
| `street_name` | Street name for partial (LIKE) match |

### Map Bounds Parameter
```
?bounds=south,west,north,east
```
Example: `?bounds=42.2,-71.2,42.4,-71.0`

**Note:** API does NOT support lat/lng/radius for map search.

### Polygon Parameter (v6.30.24)

For draw search, polygon coordinates are passed as an array of lat/lng objects:
```
?polygon[0][lat]=42.35&polygon[0][lng]=-71.06&polygon[1][lat]=42.36&polygon[1][lng]=-71.05&...
```

The API uses point-in-polygon (ray casting algorithm) to filter properties to only those within the drawn shape. This provides precise filtering compared to bounding box approximation.

**iOS Note:** iOS v98+ properly encodes nested arrays for PHP. Earlier versions sent malformed query strings.

---

## Autocomplete Endpoint

### Endpoint
```
GET /wp-json/mld-mobile/v1/search/autocomplete?term={query}
```

### Response Format
Returns array directly (not wrapped in object):
```json
{
  "success": true,
  "data": [
    { "value": "Boston", "type": "City", "icon": "building.2.fill", "count": 150 },
    { "value": "02101", "type": "ZIP Code", "icon": "mappin.circle.fill", "count": 45 },
    { "value": "Main Street", "type": "Street Name", "icon": "road.2", "count": 23 },
    { "value": "123 Main Street, Boston", "type": "Address", "icon": "house.fill" },
    { "value": "Back Bay", "type": "Neighborhood", "icon": "map.fill", "count": 87 },
    { "value": "12345678", "type": "MLS Number", "icon": "number.circle.fill" }
  ]
}
```

### Supported Suggestion Types
- **City** - From `bme_listing_summary.city`
- **ZIP Code** - From `bme_listing_summary.postal_code`
- **Neighborhood** - From `bme_listing_location.subdivision_name`
- **Street Name** - From `bme_listing_location.street_name`
- **Address** - From `bme_listing_location.unparsed_address`
- **MLS Number** - From `bme_listing_summary.listing_id`

---

## Key Files

| Purpose | File |
|---------|------|
| Mobile API | `includes/class-mld-mobile-rest-api.php` |
| Main Plugin | `mls-listings-display.php` |
| Version | `version.json` |
| Query Class | `includes/class-mld-query.php` |
| BME Provider | `includes/class-mld-bme-data-provider.php` |
| Sitemap Generator | `includes/class-mld-sitemap-generator.php` |
| Incremental Sitemaps | `includes/class-mld-incremental-sitemaps.php` |

---

## XML Sitemap System

The plugin provides a custom XML sitemap system that replaces WordPress default sitemaps.

### Sitemap URLs

| Sitemap | URL | Contents |
|---------|-----|----------|
| **Index** | `/sitemap.xml` | Master index linking to all sitemaps |
| **Properties** | `/property-sitemap.xml` | Active/pending property listings (~7,400) |
| **New Listings** | `/new-listings-sitemap.xml` | Properties listed in last 7 days |
| **Modified** | `/modified-listings-sitemap.xml` | Recently modified listings |
| **Cities** | `/city-sitemap.xml` | City landing pages (e.g., `/boston/`) |
| **States** | `/state-sitemap.xml` | State landing pages |
| **Property Types** | `/property-type-sitemap.xml` | Type pages (condos, single-family, etc.) |
| **Neighborhoods** | `/neighborhood-sitemap.xml` | Neighborhood pages from `mls_area_major` |
| **Schools** | `/schools-sitemap.xml` | BMN Schools virtual pages (~5,985 URLs) |
| **Pages** | `/pages-sitemap.xml` | WordPress pages |
| **Posts** | `/posts-sitemap.xml` | WordPress blog posts |

### Schools Sitemap Details

The schools sitemap (`schools-sitemap.xml`) generates URLs for:

1. **Main Browse Page**: `/schools/` (priority 0.9)
2. **District Pages**: `/schools/{district-slug}/` (~313 pages, priority 0.5-0.8)
3. **School Pages**: `/schools/{district-slug}/{school-slug}/` (~2,500+ pages, priority 0.4-0.7)

**Priority Calculation:**
- Districts: Based on `composite_score` from `bmn_district_rankings`
- Schools: Based on `rating_band` or `composite_score` from `bmn_school_rankings`

**Slug Generation:**
- Slugs are generated dynamically from names using `sanitize_title()`
- No slug columns exist in database tables

### Caching

Sitemaps are cached in `wp-content/cache/mld-sitemaps/`:
- Cache duration: 24 hours
- Regenerated daily via WordPress cron (`mld_regenerate_sitemaps`)
- Manual regeneration: Delete cache files and request sitemap

### Testing Sitemaps

```bash
# View sitemap index
curl -s "https://bmnboston.com/sitemap.xml"

# View schools sitemap (first 50 URLs)
curl -s "https://bmnboston.com/schools-sitemap.xml" | head -50

# Count URLs in any sitemap
curl -s "https://bmnboston.com/schools-sitemap.xml" | grep -c "<url>"

# Verify robots.txt includes sitemaps
curl -s "https://bmnboston.com/robots.txt" | grep Sitemap

# Clear sitemap cache (on server)
rm -f ~/public/wp-content/cache/mld-sitemaps/*.xml
```

### Key Methods in `class-mld-sitemap-generator.php`

| Method | Purpose |
|--------|---------|
| `generate_sitemap_index()` | Creates master sitemap index |
| `generate_property_sitemap($page)` | Generates paginated property sitemaps |
| `generate_schools_sitemap()` | Generates schools/districts sitemap |
| `generate_city_sitemap()` | Generates city landing page sitemap |
| `generate_neighborhood_sitemap()` | Generates neighborhood sitemap |
| `add_sitemap_to_robots()` | Adds sitemap URLs to robots.txt |
| `regenerate_all_sitemaps()` | Regenerates all sitemaps (cron job) |
| `has_schools()` | Checks if BMN Schools tables exist with data |

---

## API Response Format

All responses wrapped in:
```json
{
    "success": true,
    "data": { ... }
}
```

Or on error:
```json
{
    "success": false,
    "code": "error_code",
    "message": "Error description"
}
```

---

## Property List Response
```php
return array(
    'id' => $listing->listing_key,  // Hash for detail lookup
    'address' => $listing->street_address,
    'city' => $listing->city,
    'price' => (int) $listing->list_price,
    'beds' => (int) $listing->bedrooms_total,
    'baths' => (float) $listing->bathrooms_total,
    'photo_url' => $listing->main_photo_url,
    // ...
);
```

## Property Detail Response
```php
return array(
    'id' => $listing->listing_key,
    'photos' => $photos,  // Array of URL strings from bme_media
    'dom' => (int) $listing->days_on_market,
    'status' => $listing->standard_status,
    'agent' => $agent_data,
    // ...
);
```

---

## Common Mistakes to Avoid

1. **Using listing_key for media/details queries** - Use listing_id instead
2. **Forgetting to update all 3 version locations**
3. **Using wrong table** - bme_listing_photos doesn't exist, use bme_media
4. **Wrong column names** - order_index not display_order
5. **Wrong autocomplete endpoint** - Use `/search/autocomplete` not `/properties/autocomplete`
6. **Wrong autocomplete param** - Use `term` not `q`

---

## Testing API

```bash
# List properties
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# Property detail
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties/LISTING_KEY_HASH"

# Autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston"

# Test MLS number filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?mls_number=12345678"

# Test address filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?address=123%20Main%20Street"

# Test street name filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?street_name=Beacon"

# Test price reduced filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?price_reduced=true&per_page=1"
```

---

## Deployment Debugging Checklist

When CSS/JS changes aren't working on production, verify **in this order**:

1. **File deployed?** Check file exists on server with correct content:
   ```bash
   sshpass -p 'PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
     "grep 'EXPECTED_CONTENT' ~/public/wp-content/plugins/PLUGIN/path/to/file"
   ```

2. **File permissions?** Must be 644 for CSS/JS, 755 for directories:
   ```bash
   sshpass -p 'PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
     "ls -la ~/public/wp-content/plugins/PLUGIN/path/to/file"
   # Fix if needed:
   chmod 644 ~/public/wp-content/plugins/PLUGIN/path/to/file.css
   ```

3. **Version parameter updated?** Check page loads new version:
   ```bash
   curl -s "https://bmnboston.com/PAGE" | grep "filename.css"
   # Should show ?ver=NEW_VERSION
   ```

4. **CDN serving correct file?** Fetch with new version param:
   ```bash
   curl -sL "https://bmnboston.com/path/to/file.css?ver=NEW_VERSION" | grep "EXPECTED_CONTENT"
   ```

5. **PHP opcache?** Touch PHP files to invalidate:
   ```bash
   sshpass -p 'PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
     "touch ~/public/wp-content/plugins/PLUGIN/*.php ~/public/wp-content/plugins/PLUGIN/includes/*.php"
   ```

---

## Push Notification System

The MLD plugin provides comprehensive push notification support for iOS devices.

### Architecture

```
iOS App (PushNotificationManager)
    ↓ registers token
WordPress (MLD + SNAB Plugins)
    ↓ sends via HTTP/2
APNs (Apple Push Notification service)
    ↓ delivers to device
iOS App (NotificationServiceExtension)
```

### Key Classes

| Class | File | Purpose |
|-------|------|---------|
| `MLD_Push_Notifications` | `includes/notifications/class-mld-push-notifications.php` | Core APNs integration |
| `MLD_Client_Notification_Preferences` | `includes/notifications/class-mld-client-notification-preferences.php` | User preferences |
| `MLD_Health_Dashboard` | `admin/class-mld-health-dashboard.php` | Queue monitoring UI |

### Key Methods

```php
// Send notification for property alert
MLD_Push_Notifications::send_property_notification($user_id, $property, $search_name, $search_id);

// Send saved search summary
MLD_Push_Notifications::send_to_user($user_id, $listing_count, $search_name, $search_id);

// Get badge count for user
MLD_Push_Notifications::get_badge_count($user_id);

// Check if user has push enabled for type
MLD_Client_Notification_Preferences::is_push_enabled($user_id, 'price_change');

// Check quiet hours
MLD_Client_Notification_Preferences::is_quiet_hours($user_id);
```

### Database Tables

| Table | Purpose |
|-------|---------|
| `wp_mld_device_tokens` | APNs device tokens with sandbox flag |
| `wp_mld_push_notification_log` | Delivery log (30-day retention) |
| `wp_mld_push_retry_queue` | Failed notifications for retry |
| `wp_mld_user_badge_counts` | Server-side badge tracking |
| `wp_mld_client_notification_preferences` | Per-user notification settings |

### Notification Types

| Type | Trigger | Payload Key |
|------|---------|-------------|
| `new_listing` | Saved search match | `listing_id`, `listing_key` |
| `price_change` | Price reduction on favorited property | `listing_id`, `listing_key` |
| `status_change` | Status update (pending, sold) | `listing_id`, `listing_key` |
| `agent_activity` | Agent shared property/activity | `notification_type`, `client_id` |

### Rate Limiting (v6.49.1)

- **Limit:** 500 requests/second (APNs soft limit is ~1000)
- **Threshold:** 60% (starts throttling at 300/sec)
- **Behavior:** Gradual delay increase as approaching limit
- **Monitoring:** `MLD_Push_Notifications::get_rate_limit_stats()`

### Admin Monitoring

System Health dashboard (WP Admin → MLS Listings → System Health) shows:
- Retry queue status (pending, processing, completed, failed)
- 24-hour delivery statistics with success rate
- Rate limiting status and utilization
- Manual "Process Queue Now" button

---

## Version History

### Version 6.75.2 - FIX CMA PDF FIELD NAME MISMATCHES (Feb 3, 2026)

Fixed additional CMA PDF data issues where field names didn't match what the PDF generator expected.

**Problems Fixed:**
1. **ZIP Code showing as "N/A"** - PDF generator expects `postal_code` but we were passing `zip`
2. **List Price showing as "N/A"** - PDF generator expects `price` but we were passing only `list_price`

**Root Cause:** The `handle_generate_cma_pdf()` function built the `$subject_property` array with field names that didn't match what `class-mld-cma-pdf-generator.php` expects.

**PDF Generator Expected Fields (line ~838-850):**
```php
array('Zip Code', $subject['postal_code'] ?? 'N/A'),  // expects 'postal_code'
array('List Price', isset($subject['price']) ? '$' . number_format($subject['price']) : 'N/A'),  // expects 'price'
```

**Fix:** Updated field names to match PDF generator expectations:
```php
'postal_code' => $subject->postal_code,  // Was 'zip'
'price' => (int) $subject->list_price,   // Added (PDF expects 'price')
'list_price' => (int) $subject->list_price,  // Keep for backward compatibility
'property_sub_type' => $subject->property_sub_type,  // Added for completeness
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Fixed field names in `handle_generate_cma_pdf()`

---

### Version 6.75.1 - FIX CMA PDF MISSING GARAGE SPACES (Feb 3, 2026)

Fixed garage spaces showing as 0 in CMA PDF for properties that have garages.

**Problem:** CMA PDF showed "Garage: 0 spaces" for the subject property even when the property had a garage (e.g., 99 Grove St, Reading with 1 garage space).

**Root Cause:** When building the `$subject_property` array for the PDF generator in `handle_generate_cma_pdf()`, the `garage_spaces` field was not included. The PDF generator at line 848 uses:
```php
array('Garage', ($subject['garage_spaces'] ?? 0) . ' spaces'),
```
Since `garage_spaces` was missing from the array, it defaulted to 0.

**Fix:** Added `garage_spaces` field to both subject property and comparables arrays:
```php
// Subject property (line ~7281)
'garage_spaces' => (int) ($subject->garage_spaces ?? 0),
'pool' => !empty($subject->has_pool) ? 1 : 0,

// Comparables (line ~7459)
'garage_spaces' => (int) ($comp->garage_spaces ?? 0),
'lot_size_area' => $comp->lot_size_acres ?? null,
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added `garage_spaces` to subject property array and comparables array in `handle_generate_cma_pdf()`

---

### Version 6.75.0 - CMA MANUAL ADJUSTMENTS FOR PDF GENERATION (Feb 3, 2026)

Added support for iOS CMA manual adjustments to be applied during PDF generation.

**Problem:** CMA generation did not take into account manual adjustments made on the iOS app. Users could set condition ratings, pool, and waterfront adjustments, but these were ignored when generating the PDF.

**Solution:** iOS now passes adjustment data to the backend, which applies relative condition adjustments and feature adjustments to comparable prices.

**How It Works:**

1. **iOS sends adjustments** via `generateCMAPDF` endpoint:
   - `subject_condition` - The condition rating of the subject property
   - `manual_adjustments` - Dictionary keyed by comparable ID with condition, pool, waterfront flags

2. **Backend applies relative adjustments:**
   - Condition: `(subject_pct - comp_pct) × sold_price`
   - Pool: -$50,000 if comp has pool (subject doesn't)
   - Waterfront: -$200,000 if comp is waterfront (subject isn't)

3. **Estimated value recalculated** using adjusted comparable prices

**Condition Adjustment Percentages:**
| Condition | Adjustment |
|-----------|------------|
| New Construction | +20% |
| Fully Renovated | +12% |
| Some Updates | 0% (baseline) |
| Needs Updating | -12% |
| Distressed | -30% |

**Example:** Subject is "Some Updates" (0%), Comp is "Fully Renovated" (+12%)
- Adjustment = (0% - 12%) × $500,000 = -$60,000
- Comp's adjusted price = $500,000 - $60,000 = $440,000

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added `subject_condition` and `manual_adjustments` parameter handling in `handle_generate_cma_pdf()`

**iOS Files Changed:**
- `BMNBoston/Features/CMA/Views/CMASheet.swift` - Passes adjustments to API
- `BMNBoston/Core/Networking/APIEndpoint.swift` - Updated endpoint parameters
- `BMNBoston/Core/Models/CMA.swift` - Added response models

---

### Version 6.74.12 - CMA PDF DAYS ON MARKET FIX (Feb 3, 2026)

Fixed "Average Days on Market: N/A" showing in CMA PDF despite valid comparables.

**Problem:** DOM calculation was looking for `$comp->list_date` which doesn't exist in the archive summary table.

**Root Cause:** The `bme_listing_summary_archive` table uses:
- `listing_contract_date` (not `list_date`)
- `days_on_market` (pre-calculated value)

**Fix:** Now uses `days_on_market` directly, with fallback to calculating from `listing_contract_date`:
```php
if (!empty($comp->days_on_market) && $comp->days_on_market > 0) {
    $dom = (int) $comp->days_on_market;
} elseif (!empty($comp->close_date) && !empty($comp->listing_contract_date)) {
    $dom = (int) ((strtotime($comp->close_date) - strtotime($comp->listing_contract_date)) / 86400);
}
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Fixed DOM calculation in two places

---

### Version 6.74.11 - CMA PDF COMPARABLE SELECTION FIX (Feb 3, 2026)

Fixed "Insufficient Data" error when generating CMA PDF with user-selected comparables.

**Problem:** Users could select specific comparables in the iOS app, but the PDF showed "Insufficient Data" for all statistics.

**Root Cause:** The PDF generator ran its own query with different criteria than the CMA display endpoint. User-selected comparables might not appear in the PDF query results due to different LIMIT, bedroom/sqft ranges, etc.

**Fix:** When `selected_comparables` is provided, query those exact properties directly by `listing_key`:
```php
if (!empty($selected_comparables)) {
    $placeholders = implode(',', array_fill(0, count($selected_comparables), '%s'));
    $comparables = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$archive_table} WHERE listing_key IN ({$placeholders})",
        $selected_comparables
    ));
}
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Direct query for selected comparables

---

### Version 6.53.0 - NOTIFICATION DEDUPLICATION FIX (Jan 10, 2026)

Fixed duplicate notifications appearing in Notification Center on every login.

**Problem:** Users saw 12+ duplicate notifications on each login, with duplicates accumulating over time. Root cause was two-fold:
1. Per-device logging (each push creates N entries for N devices)
2. No deduplication in `/notifications/history` API

**Fixes Applied:**

1. **History API Deduplication** (`class-mld-mobile-rest-api.php`)
   - `/notifications/history` now groups by `(user_id, notification_type, listing_id, hour)`
   - Uses `MIN(id)` to keep oldest entry in each group
   - Updated count query to match grouping

2. **Send-Side Duplicate Prevention** (`class-mld-push-notifications.php`)
   - Added `was_recently_sent()` function to check if similar notification sent within 1 hour
   - Added duplicate check in `send_property_notification()` that skips if recently sent

**Key Code:**
```php
// History deduplication
GROUP BY user_id, notification_type,
         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
         DATE_FORMAT(created_at, '%Y-%m-%d %H')

// Send prevention
if (self::was_recently_sent($user_id, $change_type, $listing_id, 60)) {
    $result['skipped_reason'] = 'duplicate_within_hour';
    return $result;
}
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - History API deduplication
- `includes/notifications/class-mld-push-notifications.php` - `was_recently_sent()` + duplicate check

**iOS Changes (v218):**
- Added 10-second sync throttling in `NotificationStore.swift`
- Removed redundant sync call from `BMNBostonApp.swift`

---

### Version 6.52.3 - LOGIN REDIRECT FIX (Jan 10, 2026)

Fixed login redirect to use correct dashboard URL.

**Problem:** After login/registration, users were redirected to `/dashboard/` which doesn't exist. The correct URL is `/my-dashboard/`.

**Files Changed:**
- `includes/class-mld-referral-signup.php` - Fixed 4 occurrences of dashboard URL
- `templates/referral-signup-page.php` - Fixed login link redirect
- `templates/emails/listing-updates.php` - Fixed "Manage Your Saved Searches" link

---

### Version 6.52.2 - REFERRAL SIGNUP PAGE ENHANCEMENTS (Jan 10, 2026)

Added social sharing meta tags and iOS app download section to referral signup page.

**New Features:**
- Open Graph and Twitter Card meta tags for beautiful social sharing previews
- Meta tags show agent photo, name, and invitation message when link is shared
- iOS app download section on referral signup page with App Store badge
- Platform-specific deep link: web users see App Store, iOS users launch app directly

**Implementation:**
- `MLD_Referral_Signup::output_meta_tags()` - Dynamic meta tag generation with agent data
- Enhanced `referral-signup-page.php` template with app download call-to-action
- New CSS styles in `mld-referral-signup.css` for app section

**Files Changed:**
- `includes/class-mld-referral-signup.php` - Added `output_meta_tags()` method
- `templates/referral-signup-page.php` - Added iOS app download section
- `assets/css/mld-referral-signup.css` - App section styling

---

### Version 6.52.1 - REFERRAL REST API ENDPOINTS (Jan 10, 2026)

Added REST API endpoints for agent referral link management (iOS app integration).

**New Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/agent/referral-link` | Get agent's referral URL and statistics |
| POST | `/agent/referral-link` | Update custom referral code |
| POST | `/agent/referral-link/regenerate` | Generate new referral code |
| GET | `/agent/referral-stats` | Get detailed referral statistics |

**Response Format:**
```json
{
    "success": true,
    "data": {
        "referral_code": "STEVE2026",
        "referral_url": "https://bmnboston.com/signup?ref=STEVE2026",
        "stats": {
            "total_signups": 15,
            "this_month": 3,
            "last_signup": "2026-01-05T14:30:00"
        }
    }
}
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added 4 new endpoints for referral management

---

### Version 6.52.0 - AGENT REFERRAL SYSTEM (Jan 10, 2026)

Implemented automatic agent-client matching with referral links for BMN Boston.

**New Features:**
- Default agent assignment for organic signups (no referral code)
- Agent referral links with custom codes (e.g., `/signup?ref=STEVE2026`)
- Dedicated signup page with agent photo and introduction
- Admin UI for managing default agent and viewing referral stats
- Registration API updated to accept `referral_code` parameter

**Database Schema:**
```sql
-- Agent referral codes
CREATE TABLE wp_mld_agent_referral_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    agent_user_id BIGINT UNSIGNED NOT NULL,
    referral_code VARCHAR(50) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME,
    updated_at DATETIME,
    PRIMARY KEY (id)
);

-- Referral signup tracking
CREATE TABLE wp_mld_referral_signups (
    id BIGINT UNSIGNED AUTO_INCREMENT,
    client_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    agent_user_id BIGINT UNSIGNED NOT NULL,
    referral_code VARCHAR(50),
    signup_source ENUM('organic', 'referral_link', 'agent_created'),
    platform ENUM('web', 'ios', 'admin'),
    created_at DATETIME,
    PRIMARY KEY (id)
);
```

**New WordPress Option:**
- `mld_default_agent_user_id` - Default agent for organic signups

**Files Created:**
- `includes/referrals/class-mld-referral-manager.php` - Core referral logic
- `includes/class-mld-referral-signup.php` - Signup page controller
- `templates/referral-signup-page.php` - Agent intro signup template
- `assets/css/mld-referral-signup.css` - Signup page styles

**Files Changed:**
- `includes/saved-searches/class-mld-saved-search-database.php` - Added new tables, DB_VERSION 1.12.0
- `includes/class-mld-mobile-rest-api.php` - Updated `handle_register()` for referral codes
- `admin/views/agent-management-page.php` - Added default agent badge and referral UI
- `assets/js/agent-management-admin.js` - Copy-to-clipboard and set-default functionality
- `mls-listings-display.php` - Require new classes, version bump

---

### Version 6.50.8 - TOKEN EXPIRATION & RATE LIMITING FIX (Jan 9, 2026)

Fixed auto-logout issue where users were unexpectedly logged out after 30-60 minutes of inactivity.

**Problem:** Users reported being logged out of the iOS app after 30-60 minutes, even though they didn't manually log out.

**Root Cause:** Access tokens expired after only 15 minutes. While refresh tokens lasted 7 days, the iOS token refresh mechanism could fail under certain conditions (network issues, CDN caching, race conditions). After 2 failed refresh attempts, iOS cleared all tokens and logged out the user.

**Fix:**
- Increased `ACCESS_TOKEN_EXPIRY` from 900 seconds (15 min) to 2592000 seconds (30 days)
- Increased `REFRESH_TOKEN_EXPIRY` from 604800 seconds (7 days) to 2592000 seconds (30 days)

**Also Fixed - Rate Limiting Too Aggressive:**
During investigation, discovered iOS app's token refresh failures were accumulating and triggering account lockouts. Two overlapping rate limiting systems were blocking logins:

| Plugin | Before | After |
|--------|--------|-------|
| MLD | 5 attempts, 15 min lockout | 20 attempts, 5 min lockout |
| BME | 5 attempts, 30 min lockout | 20 attempts, 5 min lockout |

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Token expiration constants (lines 28-38), rate limiting (lines 122-128)

---

### Version 6.50.7 - EMAIL NOTIFICATIONS FOR PROPERTY CHANGES (Jan 9, 2026)

Completed push notification system by adding email fallback for property change notifications.

**New Features:**
- Email notifications now sent for price changes and status changes when push fails or user prefers email
- Deferred notification system queues notifications during quiet hours and sends at 8 AM
- Stale device token cleanup removes tokens not seen in 30+ days

**Implementation:**
- Added `build_price_change_payload()` helper method for deferred price change emails
- Added `build_status_change_payload()` helper method for deferred status change emails
- Both methods format notification data consistently with push notification format

**Files Changed:**
- `includes/notifications/class-mld-property-change-notifier.php` - Added helper methods (lines 680-735)

---

### Version 6.50.6 - NOTIFICATION TIMEZONE FIX (Jan 9, 2026)

Fixed notification timestamps showing wrong "time ago" in iOS Notification Center (e.g., "5 hours ago" for notifications just received).

**Problem:** Notifications displayed timestamps that were 5 hours earlier than actual time.

**Root Cause:** WordPress stores timestamps in its configured timezone (America/New_York = UTC-5), but PHP's `strtotime()` interprets datetime strings in PHP's default timezone (UTC on most servers). This caused:
- Database stores: `2026-01-09 09:57:08` (9:57 AM Eastern)
- `strtotime()` interprets: `09:57:08` as UTC
- iOS receives: `2026-01-09T09:57:08+00:00` (UTC)
- iOS displays: "5 hours ago" because 9:57 UTC was 5 hours ago in Eastern time

**Fix:**
- Added `format_datetime_iso8601()` helper that uses WordPress's `wp_timezone()` to correctly interpret MySQL datetimes
- Now outputs: `2026-01-09T09:57:08-05:00` (with Eastern timezone offset)
- iOS correctly parses as 9:57 AM Eastern and displays "Just now"

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added helper function and updated `handle_get_notification_history()`

---

### Version 6.50.5 - NOTIFICATION CENTER TYPE FIX (Jan 8, 2026)

Fixed new listing notifications showing wrong icon (magnifying glass) instead of house icon in iOS Notification Center.

**Problem:** New listing notifications appeared with search icon instead of green house icon, and weren't properly navigating to property details.

**Root Cause:** In `send_property_notification()`, line 754 was converting `new_listing` to `saved_search` when logging to the database:
```php
// OLD (wrong) - converted new_listing to saved_search for logging
$notification_type = $change_type === 'new_listing' ? 'saved_search' : $change_type;
```

This meant:
- Push payload sent to iOS had correct `notification_type: 'new_listing'`
- But database stored `notification_type: 'saved_search'`
- When iOS synced from `/notifications/history`, it got `saved_search` → showed magnifying glass icon

**Fix:**
```php
// NEW (correct) - use actual change_type for logging
$notification_type = $change_type;
```

**Database Migration:** Updated 338 existing `saved_search` entries to `new_listing` where title was "New Listing":
```sql
UPDATE wp_mld_push_notification_log
SET notification_type = 'new_listing'
WHERE notification_type = 'saved_search' AND title = 'New Listing';
```

**Files Changed:**
- `includes/notifications/class-mld-push-notifications.php` - Fixed notification type logging

---

### Version 6.50.4 - CDN CACHE BYPASS FOR /auth/me (Jan 8, 2026)

Fixed wrong user appearing after app restart due to Kinsta CDN caching the `/auth/me` endpoint response.

**Problem:** After logging out and logging in as a different user, closing and reopening the app would show the first user's data instead of the second user.

**Root Cause:** Kinsta CDN was caching the `/auth/me` response. Server logs showed `HIT KINSTAWP` for every request, meaning cached data was returned regardless of the JWT token's user.

**Fix (Two Parts):**

1. **Server-side** - Added cache bypass headers to `/auth/me` response:
```php
$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
$response->header('Pragma', 'no-cache');
$response->header('X-Kinsta-Cache', 'BYPASS');
```

2. **iOS-side** - Added cache-busting timestamp to `/me` endpoint (already in iOS v195):
```swift
static var me: APIEndpoint {
    let timestamp = Int(Date().timeIntervalSince1970)
    return APIEndpoint(path: "/auth/me?_nocache=\(timestamp)", requiresAuth: true)
}
```

**Also Fixed:** `check_auth()` now prioritizes JWT over WordPress session cookies. Previously, if a WordPress session cookie existed from a web login, the JWT was ignored.

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Cache headers and auth priority fix

---

### Version 6.50.0-6.50.3 - SERVER-DRIVEN NOTIFICATION CENTER (Jan 8, 2026)

Complete server-driven notification management system enabling cross-device sync and persistent read/dismissed states.

**v6.50.3 - Dismiss All Endpoint:**
- Added `POST /notifications/dismiss-all` endpoint
- Marks all user's notifications as dismissed with current timestamp
- Returns `dismissed_count` in response
- Enables iOS "Clear All" to persist across devices/reinstalls

**v6.50.2 - Include Failed Notifications in History:**
- Fixed: steve@bmnboston.com showing 0 notifications when all 105 had `status = 'failed'` (BadDeviceToken)
- Changed history query from `status = 'sent'` to `status IN ('sent', 'failed')`
- Failed notifications are still valuable for in-app notification center even if push delivery failed
- Updated unread count query to match

**v6.50.1 - History Endpoint Fixes:**
- Added `is_read`, `is_dismissed`, `read_at`, `dismissed_at` fields to history response
- Added `unread_count` field for accurate badge count

**v6.50.0 - Server-Driven Notification Architecture:**
- Added `is_read`, `read_at`, `is_dismissed`, `dismissed_at` columns to `wp_mld_push_notification_log`
- Added index `idx_user_status (user_id, is_read, is_dismissed)` for fast queries
- New endpoints:
  - `GET /notifications/history` - Returns notifications with read/dismissed status
  - `POST /notifications/{id}/read` - Mark single notification as read
  - `POST /notifications/{id}/dismiss` - Dismiss a notification
  - `POST /notifications/mark-all-read` - Mark all as read
- Server is source of truth - iOS syncs on launch and notification center open

**Database Schema Changes:**
```sql
ALTER TABLE wp_mld_push_notification_log
ADD COLUMN is_read TINYINT(1) DEFAULT 0,
ADD COLUMN read_at DATETIME DEFAULT NULL,
ADD COLUMN is_dismissed TINYINT(1) DEFAULT 0,
ADD COLUMN dismissed_at DATETIME DEFAULT NULL;

ADD INDEX idx_user_status (user_id, is_read, is_dismissed);
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - All notification endpoints
- `includes/saved-searches/class-mld-saved-search-database.php` - DB schema
- `mls-listings-display.php` - Version bump
- `version.json` - Version bump

**Key Lesson - Failed Notifications Still Valuable:**
Push notifications can fail for various reasons (BadDeviceToken, network issues) but the notification record is still useful:
- Users can see notification history in-app even if push delivery failed
- Helps debug delivery issues (user sees "I should have received 100 notifications")
- Don't filter out failed notifications from history endpoint

---

### Version 6.49.14 - WEB CONTACT AGENT ROUTING (Jan 8, 2026)

Web property detail Contact Agent modal now routes to assigned agent (matching iOS behavior).

**New Features:**
- Contact Agent modal shows assigned agent's photo, name, and "Your Agent" label for logged-in clients
- Falls back to site contact settings (from theme customizer) for non-logged-in users
- Form submissions (Contact Agent + Schedule Tour) route to assigned agent's email when available
- Cross-platform consistency with iOS app

**Implementation Details:**

1. **Template Logic** (both desktop and mobile):
   ```php
   // Check for assigned agent
   if (is_user_logged_in() && class_exists('MLD_Agent_Client_Manager')) {
       $assigned_agent = MLD_Agent_Client_Manager::get_client_agent(get_current_user_id());
       if ($assigned_agent) {
           $agent_api_data = MLD_Agent_Client_Manager::get_agent_for_api($assigned_agent['user_id']);
           // Use agent info...
       }
   }
   // Fall back to theme customizer settings
   if (empty($contact_agent_email)) {
       $theme_mods = get_theme_mods();
       $contact_agent_name = $theme_mods['bne_agent_name'] ?? get_bloginfo('name');
       // etc...
   }
   ```

2. **Form Hidden Field:**
   - Added `<input type="hidden" name="agent_email" value="...">` to Contact and Tour forms
   - AJAX handler uses this field to route email notifications

3. **Email Routing** (`class-mld-ajax.php`):
   ```php
   $to = !empty($submission_data['agent_email'])
       ? $submission_data['agent_email']
       : ($settings['notification_email'] ?? get_option('admin_email'));
   ```

**Files Changed:**
- `templates/single-property-mobile-v3.php` - Contact agent logic + modal UI
- `templates/single-property-desktop-v3.php` - Contact agent logic + modal UI
- `includes/class-mld-ajax.php` - Email routing for `handle_contact_form()` and `handle_tour_form()`
- `assets/css/property-mobile-v3.css` - Contact agent info styles
- `assets/css/property-desktop-v3.css` - Contact agent info styles

**Testing:**
- Non-logged-in: Contact modal shows "BMN Boston Real Estate" (site default)
- Logged in as `s.novak55@gmail.com`: Contact modal shows "Steve Novak" with "Your Agent" label

---

### Version 6.49.13 - USER TYPE MANAGER ROLE FALLBACK (Jan 8, 2026)

Fixed admin users not being recognized as admin type for permission checks.

**Problem:** Admin account (`mail@steve-novak.com`) couldn't see "Call Agent" button on iOS because `MLD_User_Type_Manager::get_user_type()` returned "client" instead of "admin".

**Root Cause:** User type manager only checked `wp_mld_user_types` table. Admins without explicit table entries weren't recognized.

**Fix:** Added WordPress role fallback in `get_user_type()`:
```php
if (!$user_type) {
    $user = get_userdata($user_id);
    if (in_array('administrator', (array) $user->roles)) {
        $user_type = self::TYPE_ADMIN;
    } elseif (in_array('agent', (array) $user->roles) || in_array('editor', (array) $user->roles)) {
        $user_type = self::TYPE_AGENT;
    }
}
```

**Files Changed:**
- `includes/class-mld-user-type-manager.php` - Added WordPress role fallback

---

### Version 6.49.12 - SITE CONTACT SETTINGS API (Jan 8, 2026)

Added REST API endpoint for site default contact information.

**New Endpoint:** `GET /mld-mobile/v1/settings/site-contact`

**Response:**
```json
{
  "success": true,
  "data": {
    "name": "BMN Boston Real Estate",
    "phone": "617-955-2224",
    "email": "info@bmnboston.com",
    "photo_url": "https://...",
    "brokerage_name": "BMN Boston Real Estate"
  }
}
```

**Source:** Values come from theme customizer settings (`bne_agent_name`, `bne_phone_number`, `bne_agent_email`, `bne_agent_photo`, `bne_group_name`).

**Use Case:** iOS app uses this when user doesn't have an assigned agent.

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added `handle_get_site_contact_settings()` endpoint

---

### Version 6.49.11 - iOS LISTING AGENT DATA FIX (Jan 8, 2026)

Fixed listing agent information not displaying on iOS property details.

**Problem:** iOS property detail page showed "Unknown" for listing agent name, brokerage, and MLS IDs.

**Root Cause:** `bme_listing_summary` table doesn't have agent columns. API returned null for all agent fields.

**Fix:** Added JOIN query to fetch agent data from `bme_listings`, `bme_agents`, and `bme_offices` tables in `handle_get_property()`.

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Added agent JOIN query

---

### Version 6.49.9-6.49.10 - iOS CONTACT AGENT SHEET (Jan 8, 2026)

iOS updates for assigned agent display in Contact Agent sheet. See iOS CLAUDE.md for details.

---

### Version 6.49.8 - NEIGHBORHOOD AUTOCOMPLETE FIX (Jan 8, 2026)

Fixed iOS autocomplete not showing neighborhoods like "Back Bay" or "Beacon Hill".

**Problem:** Typing "Back Bay" in iOS search box showed no neighborhood suggestions.

**Root Cause:** Autocomplete only searched `subdivision_name` column, but neighborhoods like "Back Bay" are stored in `mls_area_minor`.

**Fix:** Updated autocomplete to search all 3 neighborhood-related columns using UNION query:
- `subdivision_name`
- `mls_area_major`
- `mls_area_minor`

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - `handle_autocomplete()` method

---

### Version 6.49.7 - iOS NEIGHBORHOOD FILTER FIX (Jan 8, 2026)

Fixed iOS neighborhood filter having no effect on search results.

**Problem:** Selecting a neighborhood in iOS Advanced Filters returned all 17,000+ properties instead of filtering.

**Root Cause:** REST API read the `neighborhood` parameter but never added a WHERE clause to filter results.

**Fix:** Added WHERE clause to all 3 property query methods:
- `get_active_properties()` - Active listings
- `get_archive_properties()` - Sold/archive listings
- `get_combined_properties()` - Combined Active+Sold queries

```php
// Added to each method
$where[] = "(loc.subdivision_name = %s OR loc.mls_area_major = %s OR loc.mls_area_minor = %s)";
```

**Files Changed:**
- `includes/class-mld-mobile-rest-api.php` - Three methods updated

**Verification:**
- Back Bay filter: 197 properties (was 17,427 unfiltered)
- Beacon Hill filter: 82 properties

---

### Version 6.49.6 - NEIGHBORHOOD FILTER CRITICAL ERROR FIX (Jan 8, 2026)

Fixed fatal PHP error when applying neighborhood filter on web map.

**Error:** `Cannot use object of type stdClass as array` in class-mld-ajax.php:534

**Root Cause:** Web map's traditional query path (`get_listings_for_map_traditional()`) returned stdClass objects instead of arrays. The AJAX handler expected arrays.

**Fix:** Added `ARRAY_A` to `$wpdb->get_results()` and converted all object syntax (`$listing->field`) to array syntax (`$listing['field']`).

**Files Changed:**
- `includes/class-mld-query.php` - Traditional query path and apply_school_filter()

---

### Version 6.49.5 - NEW LISTING NOTIFICATION TYPE FIX (Jan 8, 2026)

Fixed bug where new listing notifications sent incorrect `notification_type` causing in-app Notification Center tap navigation to fail.

**Problem:**
- `build_property_payload()` sent `notification_type: "saved_search"` for new listings instead of `"new_listing"`
- iOS didn't recognize the notification type and couldn't extract `listing_id` for navigation
- Price change and status change notifications worked because they sent the correct `notification_type`

**Fix:**
```php
// BEFORE - incorrect for new_listing
'notification_type' => $change_type === 'new_listing' ? 'saved_search' : $change_type

// AFTER - send actual change type
'notification_type' => $change_type
```

**Files Changed:**
- `includes/notifications/class-mld-push-notifications.php` line 841

**iOS Fix Required:** v180 restructures `NotificationItem.from()` parsing order (see iOS CLAUDE.md)

---

### Version 6.49.4 - PUSH NOTIFICATION SYSTEM ENHANCEMENTS (Jan 8, 2026)

Additional push notification enhancements from audit.

- **Rate limit monitoring alerts** - Admin email when APNs utilization exceeds 80%
- **Batch notification coalescing** - 5+ matches send single summary notification
- **Notification engagement tracking** - Database table and REST endpoints for tracking opens
- **Rich notification image failure logging** - iOS logs failures via App Groups

---

### Version 6.49.0-6.49.3 - PUSH NOTIFICATION AUDIT COMPLETION (Jan 8, 2026)

Final implementation of all audit findings.

**v6.49.0 - Server-Side Badge Count:**
- New `wp_mld_user_badge_counts` table
- REST endpoints: `GET /badge-count`, `POST /badge-count/reset`
- Badge syncs across all notification types

**v6.49.1 - Proactive Rate Limiting:**
- Tracks requests per second across all sends
- Gradual slowdown at 60% of limit (300/sec)
- Prevents APNs 429 rate limit errors

**v6.49.2 - Preference Enforcement:**
- Server-side check before sending
- Respects quiet hours configuration
- Logs skipped notifications with reason

**v6.49.3 - Queue Monitoring Dashboard:**
- Push Notification Queue section in System Health
- Shows retry queue and delivery statistics
- Rate limiting status visualization

---

### Version 6.48.0-6.48.7 - PUSH NOTIFICATION SYSTEM AUDIT (Jan 7-8, 2026)

Comprehensive audit and enhancement of the push notification system across MLD and SNAB plugins.

**Phase 1 - Quick Wins (v6.48.0-6.48.1):**
- Added `apns-expiration` header to SNAB push notifications
- Added `thread-id: appointments` to SNAB payloads for notification grouping
- Added JWT caching to SNAB (was generating new JWT for every notification)
- Standardized badge count handling

**Phase 2 - User Preferences (v6.48.2):**
- Created iOS Notification Settings screen with per-type toggles
- Added quiet hours configuration support

**Phase 3 - Infrastructure (v6.48.3-6.48.6):**
- Implemented retry queue for failed notifications with exponential backoff
- Created `wp_mld_notification_retry_queue` table
- Added `MLD_Notification_Retry_Queue` class with process_queue() cron job
- Added APNs sandbox detection via `is_sandbox` column in device tokens table
- iOS now properly detects TestFlight vs App Store builds (v6.48.4)

**Phase 4 - Rich Notifications (v6.48.7):**
- Added `image_url` to property notification payloads (from `main_photo_url`)
- Added `image_url` support to agent activity notifications
- Enables iOS Notification Service Extension to display property thumbnails

**Files Modified:**
- `includes/notifications/class-mld-push-notifications.php` - Added image_url to payloads, retry queue integration
- `includes/notifications/class-mld-notification-retry-queue.php` (new) - Retry queue with exponential backoff
- `sn-appointment-booking/includes/class-snab-push-notifications.php` - Added headers, JWT caching

**Push Notification Payload Structure (v6.48.7):**
```php
// Property notifications now include:
$payload = array(
    'aps' => array(
        'alert' => array('title' => '...', 'body' => '...'),
        'sound' => 'default',
        'badge' => $count,
        'mutable-content' => 1,  // Required for Notification Service Extension
        'thread-id' => 'saved-search-' . $search_id,
    ),
    'listing_id' => $listing_id,
    'listing_key' => $listing_key,
    'image_url' => $photo_url,  // NEW - for rich notifications
    'notification_type' => 'new_listing',
    // ...
);
```

**Retry Queue Behavior:**
- Transient failures (network error, 429 rate limit) queued for retry
- Exponential backoff: 1min, 2min, 4min, 8min, 16min (max 5 retries)
- 410 Unregistered tokens still deactivate immediately (no retry)
- Cron job processes queue every minute

---

### Version 6.47.0-6.47.2 - ENHANCED LIVE ACTIVITY LOGS (Jan 6, 2026)

Major enhancement to the Site Analytics admin dashboard with richer visitor information and filtering capabilities.

**New Features (v6.47.0):**
- Rich visitor info (logged-in username/email, anonymous visitor hash)
- Traffic source display (Google, Facebook, Direct, etc.) with icons
- Returning visitor badge for repeat visitors
- Time range selector (15m, 1h, 4h, 24h, 7 days)
- Platform filter (Web Desktop, Web Mobile, iOS App)
- Logged-in only filter toggle
- Pagination for activity stream (50 per page)
- Session journey side panel showing navigation path
- New REST API endpoint `/admin/session/{id}/journey`

**Bug Fixes:**
| Version | Issue | Root Cause | Fix |
|---------|-------|------------|-----|
| 6.47.1 | Activity stream showing no events | Timezone mismatch - used WordPress time but DB stores UTC | Changed to `time()` and `gmdate()` for UTC |
| 6.47.1 | User display name not showing | JS used wrong field name `display_name` | Fixed to `user_display_name` |
| 6.47.2 | Platform filter dropdown not working | Database only handled generic 'web'/'ios', not specific values | Added `in_array()` check for specific platforms |
| 6.47.2 | apiRequest overwriting platform param | JS always set platform from top checkboxes | Added check to not override existing platform value |

**Files Modified:**
- `includes/analytics/public/class-mld-public-analytics-database.php` - Enhanced activity stream query with user JOIN, platform filter fix
- `includes/analytics/public/class-mld-public-analytics-rest-api.php` - UTC timezone fix, session journey endpoint
- `assets/js/admin/mld-analytics-dashboard.js` - Enhanced rendering, filter handlers, pagination, platform fix
- `assets/css/admin/mld-analytics-dashboard.css` - User badges, source icons, filter bar, pagination styles
- `includes/analytics/admin/views/analytics-dashboard.php` - Time range dropdown, platform filter, pagination controls

---

### Version 6.46.0 - ANALYTICS DATA CAPTURE FIXES (Jan 6, 2026)

Improvements to analytics data capture accuracy.

**Fixes:**
- IP detection now checks Kinsta/CDN headers (X-Real-IP, True-Client-IP, etc.)
- Referrer captured at init time instead of flush time (prevents internal overwrite)
- Search engine domains normalized (google.com, google.co.uk → "Google")
- Geographic distribution limit increased from 10 to 50 cities
- Traffic sources limit increased from 10 to 30

---

### Version 6.45.0-6.45.8 - SITE ANALYTICS ADMIN DASHBOARD FIXES (Jan 5, 2026)

Bug fixes for the Site Analytics admin dashboard introduced in v6.39.x.

**Issues Fixed:**

| Version | Issue | Root Cause | Fix |
|---------|-------|------------|-----|
| 6.45.3 | Timestamps showing "Just now" for old events | Timezone mismatch (server UTC vs WP America/New_York) | Use `current_time('timestamp')` instead of `time()` |
| 6.45.4 | Event counts not matching database | `get_today_stats()` using wrong date comparison | Fixed SQL to use `DATE(created_at)` comparison |
| 6.45.5 | Property views not incrementing | Events had `listing_id=null` | Fixed `trackPageView()` to include property data |
| 6.45.6 | Traffic Trends chart blank | Missing `range` parameter handling | Added `range` param conversion to start/end dates |
| 6.45.7 | Debug logging for property tracking | Need visibility into tracker execution | Added console.log statements to mld-public-tracker.js |
| 6.45.8 | Active Now showing 0 | JS sent X-WP-Nonce on heartbeat, caused 403 when expired | Removed nonce header from heartbeat requests |

**Key Code Changes (v6.45.8):**
```javascript
// mld-public-tracker.js - sendHeartbeat()
// v6.45.8 fix: Don't send nonce - endpoint is public and nonce causes 403 when expired
fetch(config.heartbeat_url, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
        // REMOVED: 'X-WP-Nonce': config.nonce
    },
    body: JSON.stringify(payload)
})
```

**Files Modified:**
- `assets/js/mld-public-tracker.js` - Fixed heartbeat nonce issue, added debug logging
- `includes/analytics/public/class-mld-public-analytics-rest-api.php` - Added `range` parameter support
- `includes/analytics/public/class-mld-public-analytics-database.php` - Fixed timezone in queries
- `mls-listings-display.php` - Version bumps
- `version.json` - Version bumps

**Lesson Learned:** Public REST API endpoints (with `permission_callback => '__return_true'`) should NOT send WordPress nonces. While it seems harmless, WordPress still validates the nonce when the `X-WP-Nonce` header is present, and expired nonces cause 403 errors even on public endpoints.

---

### Version 6.43.0 - AGENT CLIENT ACTIVITY NOTIFICATIONS (Jan 4, 2026)

Real-time notification system alerting agents when their assigned clients perform key activities.

**Major Features:**
- Real-time email AND push notifications for 5 activity types
- Per-type toggles (agents can enable/disable each notification type independently)
- 2-hour debounce for app open notifications to avoid spam
- Branded HTML email templates for each notification type

**Notification Triggers:**
| Event | Description | Debounce |
|-------|-------------|----------|
| Client Login | Client logs into profile (iOS or web) | None |
| App Open | Client opens iOS app | 2 hours |
| Favorite Added | Client favorites a property | None |
| Search Created | Client creates a saved search | None |
| Tour Requested | Client requests a showing/tour | None |

**New REST API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/agent/notification-preferences` | Get agent's notification settings |
| PUT | `/agent/notification-preferences` | Update agent's notification settings |
| POST | `/app/opened` | iOS app reports launch (triggers agent notification) |

**New Files Created:**
- `includes/notifications/class-mld-agent-notification-preferences.php` - Per-type toggle management
- `includes/notifications/class-mld-agent-notification-log.php` - Notification logging utility
- `includes/notifications/class-mld-agent-notification-email.php` - Branded HTML email builder
- `includes/notifications/class-mld-agent-activity-notifier.php` - Main orchestrator with event hooks

**New Database Tables:**
- `wp_mld_agent_notification_preferences` - Per-agent, per-type email/push settings
- `wp_mld_agent_notification_log` - Notification delivery tracking
- `wp_mld_client_app_opens` - 2-hour debounce tracking for app open notifications

**Files Modified:**
- `class-mld-mobile-rest-api.php` - Added hooks for login, favorite, saved search + new endpoints
- `class-mld-saved-search-database.php` - Added new table definitions (DB_VERSION 1.6.0)
- `class-mld-push-notifications.php` - Added `send_activity_notification()` method
- `class-snab-rest-api.php` - Added hook for tour request notifications

**iOS Integration Required:**
1. Call `POST /app/opened` on app launch
2. Add notification preferences UI in agent settings
3. Handle `CLIENT_ACTIVITY` push notification category

---

### Version 6.39.0-6.39.11 - COMPREHENSIVE SITE ANALYTICS (Jan 4, 2026)

Full cross-platform analytics system tracking ALL visitors (Web + iOS).

**Major Features:**
- Real-time visitor tracking (web + iOS app)
- Admin dashboard with live activity stream
- Geographic distribution (city-level via MaxMind/ip-api)
- Device/browser/platform breakdowns
- Traffic source tracking (referrer, UTM params)
- Hourly/daily aggregation with 30-day retention

**Files Created:**
- `includes/analytics/public/class-mld-public-analytics-database.php` - CRUD + queries
- `includes/analytics/public/class-mld-public-analytics-tracker.php` - Event processing
- `includes/analytics/public/class-mld-public-analytics-rest-api.php` - REST endpoints
- `includes/analytics/public/class-mld-public-analytics-aggregator.php` - Cron aggregation
- `includes/analytics/public/class-mld-device-detector.php` - UA parsing
- `includes/analytics/public/class-mld-geolocation-service.php` - IP geolocation
- `includes/analytics/admin/class-mld-analytics-admin-dashboard.php` - Admin page
- `includes/analytics/admin/views/analytics-dashboard.php` - Dashboard template
- `assets/js/mld-public-tracker.js` - Client-side tracker
- `assets/js/admin/mld-analytics-dashboard.js` - Dashboard JS (Chart.js)
- `assets/css/admin/mld-analytics-dashboard.css` - Dashboard styles

**Database Tables:**
- `wp_mld_public_sessions` - Visitor sessions
- `wp_mld_public_events` - Tracking events (30-day retention)
- `wp_mld_analytics_hourly` - Pre-aggregated hourly stats
- `wp_mld_analytics_daily` - Daily aggregates (permanent)
- `wp_mld_realtime_presence` - MEMORY table for live tracking

**Bug Fixes (v6.39.6-11):**
- Fixed platform/device/browser breakdown display (v6.39.7)
- Fixed geographic distribution showing "Unknown" (v6.39.8)
- Fixed traffic trends chart fallback to raw data (v6.39.8)
- Fixed countries aggregation showing multiple rows (v6.39.9)
- **CRITICAL**: Fixed timezone mismatch in presence queries (v6.39.10)
- Fixed realtime API field names for JS compatibility (v6.39.11)

**See Also:** `ANALYTICS_IMPLEMENTATION_PROGRESS.md` for full implementation details.

---

### Version 6.36.8 - PASSWORD CONFIRMATION EMAIL (Jan 4, 2026)
Custom branded email when client sets their password for the first time.

**New Features:**
- Hooks into WordPress `password_change_email` filter for client accounts
- Branded HTML template matching welcome email style
- Includes green checkmark success indicator, "You're All Set!" header
- Agent info card (if assigned), feature highlights, CTA buttons
- "View My Dashboard" and "Browse Properties" links
- iOS app download link in footer

**Files Modified:**
- `includes/saved-searches/class-mld-agent-client-manager.php` - Added `init_password_email_hooks()`, `customize_password_change_email()`, `build_password_confirmed_email_html()`
- `mls-listings-display.php` - Added password email hook initialization on `init` action

---

### Version 6.36.7 - iOS CREATE CLIENT API FIX (Jan 4, 2026)
Fixed "Failed to parse response" error when creating clients from iOS app.

**Problem:** iOS showed "Failed to Parse response: The data could not be read or it is missing" when agent created a new client.

**Root Cause:** API returned flat data without `client` wrapper object, and missing required Int fields that iOS model expected.

**Fix:** Updated `handle_create_client()` response format:
- Added `client` wrapper object
- Included all required fields: `searches_count`, `favorites_count`, `hidden_count`, `last_activity`, `assigned_at`
- Added `first_name`, `last_name` fields for proper name parsing

**Files Modified:**
- `includes/class-mld-mobile-rest-api.php` - Fixed response format in `handle_create_client()`

**Prevention:** Added Pitfall #9 to main CLAUDE.md documenting iOS API response format requirements.

---

### Version 6.36.6 - WELCOME EMAIL IMPROVEMENTS (Jan 4, 2026)
Enhanced client welcome email with proper From address and updated messaging.

**Changes:**
1. **From Address**: Now uses agent's email address (if available), falling back to MLD notification settings, then admin email
2. **Welcome Message**: Updated to focus on platform features ("search properties, schedule tours, ask questions, learn about real estate, manage your transaction")

**Files Modified:**
- `includes/saved-searches/class-mld-agent-client-manager.php` - Updated `send_client_welcome_email()` From header logic and message text

---

### Version 6.34.3 - CUSTOM AVATAR URL IN API (Jan 3, 2026)
Added custom profile photo support to authentication and user endpoints.

**New Features:**
- Login endpoint (`/auth/login`) now returns `avatar_url` field in user object
- `/me` endpoint now returns `avatar_url` field
- New `get_user_avatar_url()` helper method in REST API class

**Avatar Resolution Order:**
1. Custom photo from `mld_agent_profiles.photo_url` table (if user has agent profile)
2. Fallback to Gravatar URL

**Files Modified:**
- `includes/class-mld-mobile-rest-api.php` - Added `get_user_avatar_url()` helper, updated login and /me responses

**API Response Change:**
```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "name": "User Name",
    "avatar_url": "https://example.com/photo.jpg",
    ...
  }
}
```

**iOS Integration:**
- iOS `User` model already had `avatarUrl` field
- ProfileView updated to display avatar using AsyncImage

---

### Version 6.31.8 - SCHOOL FILTERS YEAR ROLLOVER FIX (Jan 1, 2026)
**CRITICAL FIX:** School filters completely broken after New Year due to year mismatch.

**Problem:** On January 1, 2026, all school filters (`school_grade`, `near_a_elementary`, `near_top_elementary`, etc.) returned 0 results because code used `date('Y')` (returning 2026) but ranking data was from 2025.

**Root Cause:**
```php
// This returned 2026 on Jan 1, but rankings table only has 2025 data
$year = (int) date('Y');
$sql = "... WHERE r.year = %d";  // 0 matches!
```

**Fix:**
- Added `get_latest_ranking_year()` helper method that queries `MAX(year)` from rankings
- Replaced all 3 occurrences of `date('Y')` with the new helper
- Added legacy filter parameters (`near_top_elementary`, `near_top_high`) to REST API

**Files Modified:**
- `includes/class-mld-bmn-schools-integration.php` - New helper + 3 method fixes
- `includes/class-mld-mobile-rest-api.php` - Added legacy filter parameter support

**Test Results:**
| Filter | Before Fix | After Fix |
|--------|------------|-----------|
| `school_grade=A` | 0 | 1,663 |
| `near_a_elementary=true` | 0 | 1,407 |
| `near_top_elementary=true` | 6,396 (unfiltered) | 2,217 |

**Prevention:** See "Lesson #7: Year Rollover Bug" in Lessons Learned section.

### Version 6.31.7 - SCHOOLS SITEMAP (Dec 27, 2025)
Added XML sitemap generation for BMN Schools virtual pages to improve SEO discoverability.

**New Feature:**
- `schools-sitemap.xml` generates URLs for all school pages
- Included in sitemap index (`sitemap.xml`)
- Added to `robots.txt` output
- Added to scheduled regeneration

**URLs Generated (~5,985 total):**
- `/schools/` - Main browse page (priority 0.9)
- `/schools/{district-slug}/` - 313 district pages (priority 0.5-0.8 based on ranking)
- `/schools/{district-slug}/{school-slug}/` - ~2,500+ individual school pages (priority 0.4-0.7 based on rating)

**Technical Details:**
- Slugs generated dynamically from names using `sanitize_title()`
- Priority calculated from `composite_score` and `rating_band`
- 24-hour cache with automatic regeneration
- Database tables queried: `bmn_school_districts`, `bmn_schools`, `bmn_school_rankings`, `bmn_district_rankings`

**Files Modified:**
- `includes/class-mld-sitemap-generator.php` - Added `generate_schools_sitemap()`, `has_schools()`, schools handling in index/robots/regeneration

**Testing:**
```bash
# View schools sitemap
curl -s "https://bmnboston.com/schools-sitemap.xml" | head -50

# Count URLs
curl -s "https://bmnboston.com/schools-sitemap.xml" | grep -c "<url>"

# Verify in sitemap index
curl -s "https://bmnboston.com/sitemap.xml" | grep schools

# Verify in robots.txt
curl -s "https://bmnboston.com/robots.txt" | grep schools
```

### Version 6.31.4 - DASHBOARD VIEW RESULTS BUTTON FIX (Dec 26, 2025)
Fixed "View Results" button on dashboard not working for iOS-created saved searches.

**Problem:** Clicking "View Results" on iOS-created saved searches would reload the dashboard page instead of navigating to the search page with filters applied.

**Root Causes:**
1. iOS-created searches don't store a `search_url` field (it's empty)
2. The dashboard template used `$search['search_url']` directly for the href
3. Empty href causes page reload instead of navigation
4. Base URL was missing trailing slash (`/search` vs `/search/`)
5. Polygon data wasn't being included in the generated URL

**Fixes:**
- Added `mld_build_search_url_from_filters()` helper function to build URL from filters
- Helper handles both iOS format (`city`, `min_price`) and web format (`City`, `price_min`)
- Ensures trailing slash before hash parameters
- Converts polygon coordinates from `{lat, lng}` objects to `[lat, lng]` arrays for URL
- URL-encodes polygon JSON for proper transmission

**URL Format Generated:**
```
https://bmnboston.com/search/#City=Marblehead&PropertyType=Residential&polygon_shapes=%5B%5B42.43...%5D%5D
```

**Files Modified:**
- `templates/user-dashboard-enhanced.php` - Added helper function and updated link logic

### Version 6.31.3 - iOS SAVED SEARCH WEB COMPATIBILITY (Dec 26, 2025)
Fixed iOS-created saved searches not opening correctly on web saved searches page.

**Problem:** When viewing saved searches on the web (not dashboard), iOS-created searches wouldn't apply filters correctly.

**Root Cause:** The `buildSearchUrl()` JavaScript function only checked for web-format keys (`City`, `price_min`, `PropertyType`) but iOS saves with different keys (`city`, `min_price`, `property_type`).

**Fix:** Updated `buildSearchUrl()` in `saved-searches-frontend.js` to handle both formats:
```javascript
const cities = filters.City || filters.city || filters.selected_cities || filters.keyword_City;
const minPrice = filters.price_min || filters.min_price;
const propType = filters.PropertyType || filters.property_type;
```

**Files Modified:**
- `assets/js/saved-searches-frontend.js` - Handle both iOS and web filter key formats

### Version 6.30.24 - iOS POLYGON DRAW SEARCH (Dec 25, 2025)
Added true polygon filtering to iOS REST API, enabling draw search functionality on mobile.

**New Feature:** iOS "Draw Area" now filters properties to only those within the drawn polygon (previously only used bounding box approximation).

**Technical Details:**
- Added `polygon` parameter to REST API (array of `{lat, lng}` objects)
- Uses `MLD_Spatial_Filter_Service::build_summary_polygon_condition()` for point-in-polygon SQL
- Added `table_alias` parameter to spatial service to prevent ambiguous column errors with JOINs
- Polygon filtering works for active, archive, and combined status queries

**API Usage:**
```
GET /wp-json/mld-mobile/v1/properties?polygon[0][lat]=42.35&polygon[0][lng]=-71.06&polygon[1][lat]=42.36&...
```

**Files Modified:**
- `includes/class-mld-mobile-rest-api.php` - Added polygon parameter and filtering
- `includes/services/class-mld-spatial-filter-service.php` - Added table_alias parameter

### Version 6.30.23 - POLYGON + SCHOOL FILTER FIX (Dec 25, 2025)
Fixed critical bug where combining polygon search with school filters returned no properties.

**Problem:** When both polygon search and school filters were applied, no properties showed.

**Root Cause:** The `filter_properties_by_school_criteria()` function looked for lowercase property names (`city`, `latitude`, `longitude`), but when polygon filters are present, the query uses the "traditional path" which returns PascalCase properties (`City`, `Latitude`, `Longitude`).

**Fix:** Updated the function to handle both cases using null coalescing:
```php
$lat = $property->latitude ?? $property->Latitude ?? null;
$lng = $property->longitude ?? $property->Longitude ?? null;
$city = $property->city ?? $property->City ?? null;
```

**Files Modified:**
- `includes/class-mld-bmn-schools-integration.php` - Handle both property name cases in `filter_properties_by_school_criteria()`

### Version 6.30.22 - COMPLETE FILTER PARITY (Dec 25, 2025)
Achieved 100% filter parity between iOS REST API and Web AJAX paths.

**Added to Web Path:**
- `school_district_id` - Added to unsupported_filters for post-query filtering
- `school_grade` - Added to unsupported_filters for consistency

**Added to iOS Path:**
- `has_basement` - Filter for properties with basements (summary table)
- `pet_friendly` - Filter for pet-friendly properties (summary table)

**Files Modified:**
- `includes/class-mld-query.php` - Added school filters to unsupported_filters list
- `includes/class-mld-mobile-rest-api.php` - Added has_basement and pet_friendly filters

### Version 6.30.21 - WEB AMENITY FILTER PARITY (Dec 25, 2025)
Added all amenity filters to web query builder with conditional JOIN architecture.

**New Filters in Web Path (Summary Table):**
- `has_virtual_tour` - Filter for listings with virtual tours
- `PoolPrivateYN` - Mapped to `has_pool` column
- `FireplaceYN` - Mapped to `has_fireplace` column

**New Filters in Web Path (Require JOINs):**
- `CoolingYN` - From details table
- `GarageYN` - From details table
- `AttachedGarageYN` - From details table
- `parking_total_min` - From details table
- `WaterfrontYN` - From features table
- `ViewYN` - From features table
- `SpaYN` - From features table
- `MLSPIN_WATERVIEW_FLAG` - From features table
- `MLSPIN_OUTDOOR_SPACE_AVAILABLE` - From features table
- `SeniorCommunityYN` - From features table

**New Helper Functions:**
- `has_details_filters()` - Detects if details table JOIN needed
- `has_features_filters()` - Detects if features table JOIN needed
- `build_details_filter_conditions()` - Builds conditions for details filters
- `build_features_filter_conditions()` - Builds conditions for features filters

**Architecture:**
- JOINs only added when necessary (no performance impact for basic searches)
- Conditional JOIN pattern preserves fast summary-only queries
- Complete parity with iOS REST API amenity filters

**Files Modified:**
- `includes/class-mld-query.php` - Added conditional JOIN support, 4 new helper functions

### Version 6.30.20 - SHARED QUERY BUILDER (Dec 25, 2025)
Created unified query builder class for iOS/Web code path parity.

**New Class:**
- `MLD_Shared_Query_Builder` - Shared filter logic for both REST API and AJAX

**Methods:**
- `normalize_filters()` - Converts various key formats to canonical snake_case
- `build_conditions()` - Returns array of SQL conditions and params
- `build_where_clause()` - Returns prepared WHERE clause string
- `has_school_filters()` - Detects school-related filter criteria
- `build_school_criteria()` - Builds array for BMN Schools integration
- `get_sort_clause()` - Consistent sort options

**Integration:**
- Query class `has_school_filters()` now delegates to shared builder
- Query class `build_school_criteria()` now delegates to shared builder
- Full filter migration deferred - Query class has specialized street handling

**Files Created:**
- `includes/class-mld-shared-query-builder.php` - New shared class (~400 lines)

**Files Modified:**
- `includes/class-mld-query.php` - School helpers now delegate to shared builder

### Version 6.30.19 - WEB FILTER PARITY (Dec 25, 2025)
Added missing filters to web query builder for parity with iOS REST API.

**New Filters in Web Path:**
- `price_reduced` - Filter for properties with price reductions
- `new_listing_days` - Filter for listings within X days
- `max_dom` - Maximum days on market filter
- `neighborhood` - Filter by subdivision_name

**Documentation:**
- Created `/docs/CODE_PATH_PARITY_AUDIT.md` documenting all filter differences between iOS and Web paths

**Files Modified:**
- `includes/class-mld-query.php` - Added 4 new filter cases to `build_summary_filter_conditions()`

### Version 6.30.18 - GLOSSARY MODAL POSITION FIX (Dec 25, 2025)
Fixed glossary modal opening off-screen on property detail pages.

**Root Cause:** File permissions were 600 (owner-only) after SCP upload, blocking web server access.

**Fixes:**
- Modal content now uses explicit fixed positioning (`top: 50%; left: 50%; transform: translate(-50%, -50%)`)
- Added `!important` to all positioning rules to override theme conflicts
- JS moves modal to `document.body` on init to avoid ancestor transform issues

**Files Modified:**
- `assets/css/mld-schools.css` - Explicit centering for `.mld-modal-content`
- `assets/js/mld-schools-glossary.js` - Move modal to body on init

**Lesson Learned:** Always verify file permissions (644) after SCP deployment before debugging CSS issues.

### Version 6.30.16 - MIAA SPORTS DISPLAY (Dec 22, 2025)
Added display of high school sports participation data from MIAA.

**New Features:**
- Sports row in Nearby Schools section for high schools
- Shows sports count and total athletes ("20 sports • 2,762 athletes")
- Strong Athletics badge for schools with 15+ sports programs
- Teal styling for Strong Athletics schools

**Files Modified:**
- `includes/class-mld-bmn-schools-integration.php` - Added `render_sports_row()` method
- `assets/css/mld-schools.css` - Added `.mld-sports-row` styles

### Version 6.30.15 - DISCIPLINE PERCENTILE DISPLAY (Dec 22, 2025)
Enhanced discipline data display to show percentile ranking.

**New Features:**
- Discipline percentile label ("Bottom 25% - Very Low")
- Color-coded styling based on percentile (green for safest, orange for above average)
- State comparison context

**Files Modified:**
- `includes/class-mld-bmn-schools-integration.php` - Updated `render_discipline_data()` for percentile display

### Version 6.30.14 - DISCIPLINE & COLLEGE OUTCOMES DISPLAY (Dec 22, 2025)
Added district-level discipline data and college outcomes display to property detail pages.

**New Features:**
- "School Safety" section shows discipline rates with breakdown (suspensions, expulsions, etc.)
- "Where Graduates Go" section shows college enrollment percentages
- Both sections appear below district info in Nearby Schools section
- Green/orange styling based on discipline rate vs state average

**Files Modified:**
- `includes/class-mld-bmn-schools-integration.php` - Added `render_discipline_data()`, `render_college_outcomes()`
- `assets/css/mld-schools.css` - Added styles for discipline and outcomes sections

### Version 6.30.10 - WEB SCHOOL FILTER POST-PROCESSING FIX (Dec 21, 2025)
**Problem:** School filters (district rating, proximity) worked on iOS but not on web map.
**Root Cause:** `get_listings_for_map_optimized()` added school data to listings for display but never called `apply_school_filter()` to actually filter them out.

**Fixes:**
- Added `apply_school_filter()` call to optimized function (was only in traditional function)
- Increased fetch limit from 200 to 2000 when school filters active (post-filtering reduces results by 60-90%)
- Added `count_only` handling after school filter post-processing
- Converted array↔object for `apply_school_filter()` compatibility

**Files Modified:**
- `includes/class-mld-query.php` - Added school filter post-processing to optimized path

### Version 6.30.9 - WEB SCHOOL FILTER DETECTION FIX (Dec 21, 2025)
**Problem:** `school_grade` filter wasn't being detected as a school filter for count updates.

**Fixes:**
- Added `school_grade` check to `has_school_filters()` function
- Added `school_grade` to `build_school_criteria()` function
- Fixed CSS toggle layout in `main.css` (was overriding `mld-enhanced-filters.css`)

### Version 6.30.8 - WEB MAP DISTRICT GRADE DISPLAY (Dec 21, 2025)
- Added `district_grade` and `district_percentile` to `get_listings_for_map_optimized()` response
- Added field mappings to `transform_to_pascalcase()` function
- Web property cards now show district grade badge (e.g., "A+ TOP 7%")

### Version 6.30.6/6.30.7 - WEB DISTRICT RATING FILTER UI (Dec 21, 2025)
- Added Minimum District Rating picker (iOS-style segmented control) to web filter modal
- Updated property card display format: `🎓 A- top 30%` instead of `A Schools`
- Fixed grade filter to include variants (A includes A+/A/A-)
- Added CSS for `.bme-district-rating-section` and `.bme-district-grade-btn`

### Version 6.29.1 - SCHOOL FILTER TOTAL COUNT FIX (Dec 20, 2025)
- Fixed bug where school filter total count was calculated after trimming to page size
- Bug caused all grade levels to report similar totals (~322)
- Now correctly calculates filter pass rate before trimming results
- Corrected totals: A=355 (37%), B=870 (90%), C=967 (100%)

### Version 6.29.0 - SCHOOL QUALITY PROPERTY FILTERS (Dec 20, 2025)
- Added school quality filters to property search API (Phase 4 BMN Schools integration)
- New filter parameters: `school_grade`, `near_top_elementary`, `near_top_high`, `school_district_id`
- Extended `MLD_BMN_Schools_Integration` with 6 new methods for school-based filtering
- Uses direct database queries for performance (no REST API calls during filtering)
- Grid-based caching for school lookups (~0.7mi grid, 30-minute TTL)
- Over-fetch pagination (3x) for consistent page sizes when school filters active

### Version 6.28.1 - BMN SCHOOLS DATABASE VERIFICATION (Dec 20, 2025)
- Added 10 BMN Schools plugin tables to database verification tool
- Tables verified: `bmn_schools`, `bmn_school_districts`, `bmn_school_test_scores`, `bmn_school_demographics`, `bmn_school_features`, `bmn_school_rankings`, `bmn_school_attendance_zones`, `bmn_school_locations`, `bmn_school_data_sources`, `bmn_schools_activity_log`
- Enables one-click repair for all school data tables
- Part of unified database health monitoring

### Version 6.27.24 - ARCHIVE SUMMARY TABLE OPTIMIZATION (Dec 19, 2025)
**Problem:** Sold/archive queries took 4-5 seconds due to 5-table JOINs across 90K rows.
**Solution:** Created `bme_listing_summary_archive` table mirroring active summary structure.

**Mobile API Changes (`class-mld-mobile-rest-api.php`):**
- Created denormalized archive summary table with 90,466 pre-joined records
- Rewrote `get_archive_properties()` to query single table (no JOINs)
- Rewrote `get_combined_properties()` to UNION two summary tables
- Fixed table collation mismatch (`utf8mb4_unicode_520_ci`)
- Increased cache TTL from 2 minutes to 30 minutes

**Web Data Provider Changes (`class-mld-bme-data-provider.php`):**
- Added `detect_archive_summary_table()` method for table availability detection
- Added `get_archive_listings_optimized()` method using single-table query
- Added `build_archive_summary_where_clauses()` for filter handling
- Updated `get_archive_listings()` to try optimized method first with fallback

**Results:** All archive queries improved from 4-5 seconds to <200ms (25x faster)

### Version 6.27.17 - ADDRESS/MLS/STREET NAME FILTER SUPPORT (Dec 19, 2025)
- Properties endpoint now supports `address`, `mls_number`, and `street_name` filters
- When user selects address from autocomplete, only that listing is returned
- When user selects MLS number from autocomplete, only that listing is returned
- Street name filter uses partial LIKE matching
- Added INNER JOIN with location table for address filtering on `unparsed_address`

### Version 6.27.16 - MOBILE AUTOCOMPLETE FIX (Dec 18, 2025)
- Autocomplete now queries `bme_listing_location` table for neighborhoods, streets, addresses
- Added street name suggestions (searches `street_name` column)
- Neighborhoods now search `subdivision_name` from location table with proper JOIN
- Addresses now search `unparsed_address` from location table with proper JOIN

### Version 6.27.15 - PRICE REDUCED FILTER FIX (Dec 18, 2025)
**Problem:** `price_reduced=true` filter was not working - returned same count as unfiltered.
**Cause:** Parameter was read but never used in WHERE clause.
**Fix:**
```php
if ($price_reduced) {
    $where[] = "(s.original_list_price IS NOT NULL AND s.original_list_price > 0 AND s.list_price < s.original_list_price)";
}
```

### Known Data Issues
- `MLSPIN_OUTDOOR_SPACE_AVAILABLE`: Filter logic works, but returns 0 results (no data in database)

---

## Lessons Learned / Common Pitfalls

### 1. Web vs Mobile Use Different Code Paths (CRITICAL)

The web map and iOS app use **completely different code paths**:

| Platform | Data Endpoint | Query Function | File |
|----------|---------------|----------------|------|
| **iOS App** | REST API `/wp-json/mld-mobile/v1/properties` | `get_active_properties()` | `class-mld-mobile-rest-api.php` |
| **Web Map** | AJAX `get_map_listings` action | `get_listings_for_map_optimized()` | `class-mld-query.php` |

**When adding new features, you MUST update BOTH paths.** A feature working on iOS doesn't mean it works on web.

**Example (Dec 21, 2025):** District grade filter worked on iOS but not web because:
- REST API called `apply_school_filter()` ✓
- AJAX/Query path did NOT call `apply_school_filter()` ✗

### 2. CSS Conflicts Between Files

Multiple CSS files can define the same selectors:
- `main.css` - Core styles for map/search page
- `mld-enhanced-filters.css` - Filter modal styles

**Problem (Dec 21, 2025):** `.bme-toggle-switch` was defined in both files:
- `main.css` had `display: inline-block; width: 36px; height: 20px;`
- `mld-enhanced-filters.css` had `display: flex; align-items: center;`
- `main.css` loaded last and overrode the filter modal styles

**Solution:** Add specific overrides in `main.css` for school filter context:
```css
.bme-school-toggles .bme-toggle-switch {
    display: flex !important;
    width: auto !important;
    height: auto !important;
}
```

### 3. Post-Query Filtering Requires Larger Initial Fetch

School filters use **post-query filtering** (can't be done in SQL). When these filters are active:

```php
// BAD: Only fetch 200, then filter - might end up with 40 results
$limit = 200;

// GOOD: Fetch more when post-filtering will reduce results
$limit = $has_school_filters ? 2000 : 200;
```

### 4. Helper Functions Must Include All Filter Types

When adding new filter types, update ALL related helper functions:

```php
// has_school_filters() - detects if school filtering needed
// MUST include school_grade, not just proximity filters
if (!empty($filters['school_grade'])) {  // <-- Easy to forget!
    return true;
}

// build_school_criteria() - builds criteria array for integration
// MUST include all filter keys
'school_grade' => $filters['school_grade'] ?? null,  // <-- Easy to forget!
```

### 5. CDN Caching and Version Parameters

CSS/JS files are cached by Kinsta CDN with 1-year max-age. Changes won't appear unless:
1. Version parameter changes (e.g., `main.css?ver=6.30.10`)
2. File is enqueued with `MLD_VERSION` constant
3. Browser cache is cleared with DevTools "Disable cache"

**Always update MLD_VERSION when deploying CSS/JS changes.**

### 6. Testing School Filters

Quick API tests to verify school filtering works:
```bash
# Baseline
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?property_type=Residential&per_page=1"
# Expected: ~7,100+ total

# A-rated districts only
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?property_type=Residential&school_grade=A&per_page=1"
# Expected: ~800-900 total (12% of baseline)

# Near A-rated elementary
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?property_type=Residential&near_a_elementary=true&per_page=1"
# Expected: ~1,300-1,400 total (19% of baseline)
```

### 7. Year Rollover Bug - NEVER Use date('Y') for Data Queries (CRITICAL)

**Problem (Jan 1, 2026):** All school filters stopped working on January 1, 2026 because the code used `date('Y')` to query school rankings, but ranking data is from the previous year.

**Root Cause:**
```php
// BAD: This returns 2026 on Jan 1, 2026, but rankings are from 2025
$year = (int) date('Y');
$sql = "SELECT ... FROM rankings WHERE year = %d";  // Finds 0 rows!
```

**Symptom:** School filters return 0 results or the full unfiltered count (no filtering applied).

**Solution:** Always query for the most recent year that has data:
```php
// GOOD: Query for MAX(year) to get latest available data
private function get_latest_ranking_year() {
    $result = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");
    return $result ? (int) $result : (int) date('Y') - 1;
}

$year = $this->get_latest_ranking_year();  // Returns 2025 even on Jan 1, 2026
```

**Files affected:**
- `class-mld-bmn-schools-integration.php` - Three methods used `date('Y')`:
  - `get_top_schools_cached()` - For proximity filters
  - `get_best_nearby_school_grade()` - For grade lookups
  - `get_all_district_averages()` - For district grade filter

**Prevention:** When querying time-series data (rankings, test scores, demographics), NEVER assume the current calendar year has data. Always query for the most recent year available.

**Quick Test After Year Rollover:**
```bash
# If this returns 0, year rollover bug has occurred
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"
# Expected: 1000+ results, NOT 0
```

### 8. Neighborhood Data Stored in Multiple Columns (CRITICAL)

**Problem (Jan 8, 2026):** Neighborhood filter worked (returned 197 Back Bay properties), but autocomplete showed no results when typing "Back Bay".

**Root Cause:** Neighborhood data is stored across THREE different columns in `bme_listing_location`:
- `subdivision_name` - Primary neighborhood field
- `mls_area_major` - MLS-defined major area (e.g., "Greater Boston")
- `mls_area_minor` - MLS-defined minor area (e.g., "Back Bay", "Beacon Hill")

The autocomplete only searched `subdivision_name`, missing neighborhoods stored in other columns.

**Two Bugs Fixed (v6.49.7 + v6.49.8):**

1. **Filter not working (v6.49.7):** REST API read the `neighborhood` parameter but never added a WHERE clause
   ```php
   // BAD: Parameter read but never used
   $neighborhood = $request->get_param('neighborhood');
   // ... no WHERE clause!

   // GOOD: Add WHERE clause searching all 3 columns
   $where[] = "(loc.subdivision_name = %s OR loc.mls_area_major = %s OR loc.mls_area_minor = %s)";
   ```

2. **Autocomplete not finding neighborhoods (v6.49.8):** Only searched one column
   ```php
   // BAD: Only searches subdivision_name
   SELECT DISTINCT subdivision_name FROM location WHERE subdivision_name LIKE %s

   // GOOD: Search all 3 columns with UNION
   SELECT DISTINCT neighborhood FROM (
       SELECT subdivision_name AS neighborhood FROM location WHERE subdivision_name LIKE %s
       UNION
       SELECT mls_area_major AS neighborhood FROM location WHERE mls_area_major LIKE %s
       UNION
       SELECT mls_area_minor AS neighborhood FROM location WHERE mls_area_minor LIKE %s
   ) AS all_neighborhoods
   ```

**Prevention:** When working with neighborhood/location data, always consider all 3 columns:
- `subdivision_name` - Developer/builder neighborhood names
- `mls_area_major` - Broad regional areas
- `mls_area_minor` - Specific neighborhood names (Back Bay, Beacon Hill, etc.)

**Quick Test:**
```bash
# Autocomplete should return "Back Bay" as a Neighborhood
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=Back%20Bay"

# Filter should return ~200 properties (not 17,000+)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?neighborhood=Back%20Bay&per_page=1"
```

---

## Adding New Filter Parameters

When adding a new filter parameter to the properties endpoint:

1. **Read the parameter** (around line 650):
```php
$my_param = sanitize_text_field($request->get_param('my_param'));
```

2. **Add WHERE clause** (around line 780):
```php
if (!empty($my_param)) {
    $where[] = "s.column_name = %s";
    $params[] = $my_param;
}
```

3. **If needing a JOIN**, add to join logic (around line 710):
```php
$needs_my_join = !empty($my_param);
```
And add the join (around line 987):
```php
if ($needs_my_join) {
    $joins .= " INNER JOIN {$my_table} AS mt ON s.listing_id = mt.listing_id";
}
```

---

## BMN Schools Integration

The MLD Database Verification tool now includes all BMN Schools plugin tables:

### Verified Tables (10 total)

| Table | Purpose | Category |
|-------|---------|----------|
| `bmn_schools` | School directory (2,636 schools) | schools |
| `bmn_school_districts` | Districts with boundaries (342) | schools |
| `bmn_school_test_scores` | MCAS scores (44,213) | schools |
| `bmn_school_demographics` | Enrollment data (5,460) | schools |
| `bmn_school_features` | Programs/staffing (15,073) | schools |
| `bmn_school_rankings` | Third-party ratings (Phase 6) | schools |
| `bmn_school_attendance_zones` | School boundaries (Phase 6) | schools |
| `bmn_school_locations` | Location mapping (optional) | schools |
| `bmn_school_data_sources` | Sync tracking | schools |
| `bmn_schools_activity_log` | Debug logging | schools |

### Access
WordPress Admin > MLS Listings Display > Database Verification

The "Fix All Issues" button will repair any missing BMN Schools tables.

---

## Related Documentation

- Main project guide: `/CLAUDE.md`
- iOS app: `/ios/CLAUDE.md`
- BMN Schools plugin: `/wordpress/wp-content/plugins/bmn-schools/CLAUDE.md`
