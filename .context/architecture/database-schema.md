# Database Schema

## Critical: Summary Tables

**ALWAYS use summary tables for list queries - 25x faster than JOINs.**

| Table | Purpose | Row Count | Use Case |
|-------|---------|-----------|----------|
| `bme_listing_summary` | Active/Pending listings | ~7,400 | Status: Active, Pending, Coming Soon |
| `bme_listing_summary_archive` | Sold/Closed listings | ~90,000 | Status: Sold, Closed, Expired |

### Summary Table Columns

Both tables have identical structure:

| Column | Type | Description |
|--------|------|-------------|
| `listing_id` | INT | Primary key, MLS number |
| `listing_key` | VARCHAR(128) | MD5 hash for API lookups |
| `property_type` | VARCHAR(50) | Residential, Commercial, Land |
| `standard_status` | VARCHAR(50) | Active, Pending, Closed, etc. |
| `list_price` | DECIMAL(20,2) | Current/final list price |
| `bedrooms_total` | INT | Number of bedrooms |
| `bathrooms_total` | DECIMAL(3,1) | Total bathrooms |
| `building_area_total` | INT | Square footage |
| `city` | VARCHAR(100) | City name |
| `postal_code` | VARCHAR(10) | ZIP code |
| `latitude` | DECIMAL(10,8) | GPS latitude |
| `longitude` | DECIMAL(11,8) | GPS longitude |
| `main_photo_url` | VARCHAR(500) | Primary photo URL |

### Status-to-Table Routing

| Status Value | Table | Notes |
|--------------|-------|-------|
| Active | `bme_listing_summary` | Current listings |
| Pending | `bme_listing_summary` | Under contract |
| Coming Soon | `bme_listing_summary` | Pre-market |
| Sold | `bme_listing_summary_archive` | Maps to "Closed" in DB |
| Closed | `bme_listing_summary_archive` | Completed sales |
| Expired | `bme_listing_summary_archive` | Listing expired |

**Important:** iOS uses "Sold" but database stores "Closed".

### Query Pattern

```php
// FAST: Use summary table (~200ms)
$sql = "SELECT * FROM {$wpdb->prefix}bme_listing_summary
        WHERE standard_status = 'Active' AND property_type = 'Residential'";

// SLOW: Multi-table JOIN (4-5 seconds)
$sql = "SELECT l.*, d.* FROM bme_listings l
        LEFT JOIN bme_listing_details d ON l.listing_id = d.listing_id";
```

### Cross-Table Joins

```php
// Get listing from summary by hash
$listing = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM bme_listing_summary WHERE listing_key = %s",
    $hash
));

// Use listing_id (NOT listing_key) for related tables
$photos = $wpdb->get_col($wpdb->prepare(
    "SELECT media_url FROM bme_media WHERE listing_id = %s ORDER BY order_index",
    $listing->listing_id
));
```

---

## School Tables

| Table | Purpose | Records |
|-------|---------|---------|
| `bmn_schools` | School directory | 2,636 |
| `bmn_school_districts` | Districts with boundaries | 342 |
| `bmn_school_rankings` | Composite scores by year | 4,930 |
| `bmn_district_rankings` | District scores | 275 |
| `bmn_school_test_scores` | MCAS scores | 44,213 |
| `bmn_school_demographics` | Enrollment data | 5,460 |
| `bmn_school_features` | Programs/staffing | 21,787 |
| `bmn_school_sports` | MIAA sports data | 8,114 |
| `bmn_state_benchmarks` | State averages | 4 |

### Schools Table

```sql
CREATE TABLE bmn_schools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state_school_id VARCHAR(20),
    name VARCHAR(255) NOT NULL,
    school_type ENUM('public', 'private', 'charter'),
    level ENUM('elementary', 'middle', 'high', 'combined'),
    district_id BIGINT UNSIGNED,
    city VARCHAR(100),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8)
);
```

### Rankings Table

```sql
CREATE TABLE bmn_school_rankings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    year INT NOT NULL,
    composite_score DECIMAL(5,2),
    percentile_rank INT,
    state_rank INT,
    letter_grade VARCHAR(2),
    UNIQUE KEY unique_ranking (school_id, year)
);
```

---

## Appointment Tables

| Table | Purpose |
|-------|---------|
| `snab_staff` | Staff with Google Calendar connections |
| `snab_appointment_types` | Appointment configurations |
| `snab_availability_rules` | Staff availability |
| `snab_appointments` | Booked appointments |
| `snab_notifications_log` | Email/push history |

### Appointments Table

```sql
CREATE TABLE snab_appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    staff_id BIGINT UNSIGNED NOT NULL,
    type_id BIGINT UNSIGNED NOT NULL,
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('confirmed', 'cancelled', 'completed'),
    google_event_id VARCHAR(255),
    listing_id VARCHAR(50),
    notes TEXT,
    created_at DATETIME,
    updated_at DATETIME
);
```

---

## User Data Tables

| Table | Purpose |
|-------|---------|
| `mld_saved_searches` | User saved search filters |
| `mld_favorites` | User favorite properties |

### Saved Searches Table

```sql
CREATE TABLE mld_saved_searches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    filters LONGTEXT NOT NULL,  -- JSON
    notifications_enabled TINYINT(1) DEFAULT 0,
    created_at DATETIME,
    updated_at DATETIME
);
```

---

## Table Prefixes

| Prefix | Plugin |
|--------|--------|
| `bme_` | Bridge MLS Extractor |
| `mld_` | MLS Listings Display |
| `bmn_` | BMN Schools |
| `snab_` | SN Appointment Booking |

---

## Performance Tips

1. **Always use summary tables** for property lists
2. **Index on common filters**: city, postal_code, standard_status, property_type
3. **Use UNION ALL** for combined Active + Sold queries (not separate queries)
4. **Cache school lookups** using WordPress transients (30 min TTL)
5. **Archive summary refresh** via stored procedure hourly
