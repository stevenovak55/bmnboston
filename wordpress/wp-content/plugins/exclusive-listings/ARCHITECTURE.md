# Exclusive Listings - Architecture Documentation

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    EXCLUSIVE LISTINGS PLUGIN                     │
├─────────────────────────────────────────────────────────────────┤
│  Agent UI (Web Admin)             Agent UI (iOS App)            │
│       │                                │                         │
│       ▼                                ▼                         │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              REST API ENDPOINTS                           │   │
│  │  POST /exclusive-listings       (create)                  │   │
│  │  PUT  /exclusive-listings/{id}  (update)                  │   │
│  │  DELETE /exclusive-listings/{id} (delete/archive)         │   │
│  │  POST /exclusive-listings/{id}/photos (upload)            │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │           EXCLUSIVE LISTINGS SERVICE LAYER                │   │
│  │  - ID generation (sequential from 1)                      │   │
│  │  - listing_key generation (MD5)                           │   │
│  │  - Address geocoding (Nominatim/Google)                   │   │
│  │  - Image upload + optimization                            │   │
│  │  - Validation (required fields, enum values)              │   │
│  └──────────────────────────────────────────────────────────┘   │
│                           │                                      │
│                           ▼                                      │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │         BME TABLE POPULATION (Sync Layer)                 │   │
│  │  INSERT/UPDATE into:                                      │   │
│  │  - wp_bme_listings                                        │   │
│  │  - wp_bme_listing_summary                                 │   │
│  │  - wp_bme_listing_details                                 │   │
│  │  - wp_bme_listing_location                                │   │
│  │  - wp_bme_listing_features                                │   │
│  │  - wp_bme_media                                           │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                   EXISTING BME TABLES                            │
│  (Exclusive listings indistinguishable from MLS listings)        │
│                                                                  │
│  Web Search (class-mld-query.php) ──────► bme_listing_summary   │
│  iOS Search (class-mld-mobile-rest-api.php) ► bme_listing_summary│
│  Property Detail ───────────────────────► bme_listings + JOINs  │
│  Map Display ───────────────────────────► bme_listing_location   │
└─────────────────────────────────────────────────────────────────┘
```

## ID Strategy

### Range Allocation
```
Exclusive: 1 ──────────────────────────► ~1,000 (expected max)
                                             │
                    [59+ million ID gap]     │
                                             │
MLS IDs:   60,000,000 ◄─────────────────────┘  71,000,000 ► 73,000,000+
```

### Implementation
```sql
-- Sequence table
CREATE TABLE wp_exclusive_listing_sequence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
) AUTO_INCREMENT = 1;

-- Generate new ID
INSERT INTO wp_exclusive_listing_sequence VALUES (NULL);
SELECT LAST_INSERT_ID();
```

### Detection Function
```php
function is_exclusive_listing($listing_id) {
    return $listing_id < 1000000;  // Under 1 million = exclusive
}
```

## Database Tables

### Plugin-Owned Tables

| Table | Purpose |
|-------|---------|
| `wp_exclusive_listing_sequence` | Auto-increment ID generation |
| `wp_exclusive_listings_meta` | Plugin-specific metadata (future) |

### BME Tables (Populated, Not Owned)

| Table | Columns Used |
|-------|--------------|
| `wp_bme_listings` | Core listing data (240+ fields) |
| `wp_bme_listing_summary` | Denormalized for fast queries |
| `wp_bme_listing_details` | Beds, baths, sqft, year built |
| `wp_bme_listing_location` | Address, coordinates, schools |
| `wp_bme_listing_features` | Amenities (pool, basement, etc.) |
| `wp_bme_media` | Photo URLs and ordering |

## Class Structure

```
exclusive-listings/
├── exclusive-listings.php           # Main plugin file, singleton
├── includes/
│   ├── class-el-activator.php       # Activation/deactivation hooks
│   ├── class-el-database.php        # Schema management, upgrades
│   ├── class-el-id-generator.php    # Sequential ID generation
│   ├── class-el-rest-api.php        # Health/diagnostics REST endpoints
│   ├── class-el-validator.php       # Input validation ✓ (v1.1.0)
│   ├── class-el-geocoder.php        # Address geocoding ✓ (v1.1.0)
│   ├── class-el-bme-sync.php        # BME table population ✓ (v1.1.0)
│   ├── class-el-image-handler.php   # Photo upload/management ✓ (v1.1.0)
│   ├── class-el-mobile-rest-api.php # iOS CRUD endpoints ✓ (v1.1.0, updated v1.2.2)
│   └── class-el-archive.php         # Archive workflow (Phase 3)
└── admin/
    ├── class-el-admin.php           # Admin UI ✓ (v1.2.0)
    ├── css/el-admin.css             # Admin styles ✓ (v1.2.0)
    └── js/el-admin.js               # Photo gallery management ✓ (v1.2.0)
```

### Class Responsibilities (v1.2.2)

| Class | Purpose |
|-------|---------|
| `EL_Validator` | Validates required fields, property types, statuses, price > 0 |
| `EL_Geocoder` | Nominatim API with transient cache (1 week), Google fallback |
| `EL_BME_Sync` | Atomic INSERT/UPDATE to 6 BME tables with transaction support |
| `EL_Image_Handler` | WordPress media library, custom upload dir, photo reordering |
| `EL_Mobile_REST_API` | Full CRUD under `/mld-mobile/v1/exclusive-listings/`, options endpoint |
| `EL_Admin` | WordPress admin interface with list table, forms, bulk operations |

## Image Storage

### Directory Structure
```
wp-content/uploads/exclusive-listings/
└── {YYYY}/
    └── {MM}/
        └── {listing_id}/
            ├── exterior-front.jpg
            ├── exterior-front-150x150.jpg
            ├── exterior-front-300x300.jpg
            ├── exterior-front-768x0.jpg
            └── exterior-front-1024x1024.jpg
```

### Upload Pipeline
1. Receive file via `wp_handle_upload()`
2. Attach to media library via `wp_insert_attachment()`
3. Generate thumbnails via `wp_generate_attachment_metadata()`
4. Store in `wp_bme_media` table with listing_id FK
5. Update `main_photo_url` in summary table (first photo)

## Archive Workflow

### Status Change Trigger
```php
add_action('el_listing_status_changed', function($listing_id, $old, $new) {
    if ($new === 'Closed') {
        EL_Archive_Service::migrate_to_archive($listing_id);
    }
});
```

### Migration Steps
1. Copy to `_archive` tables (6 tables)
2. Update `wp_bme_media.source_table` = 'archive'
3. Delete from active tables

## REST API Design

### Namespace
- Plugin endpoints: `/exclusive-listings/v1/`
- Mobile endpoints: `/mld-mobile/v1/exclusive-listings/`

### Authentication
- Plugin endpoints: WordPress nonce (admin UI)
- Mobile endpoints: JWT Bearer token (iOS app)

### Response Format
```json
{
    "success": true,
    "data": {
        "listing": { ... },
        "message": "Listing created successfully"
    }
}
```

## Error Handling

### Validation Errors
```json
{
    "success": false,
    "code": "validation_failed",
    "message": "Validation failed",
    "errors": {
        "list_price": "Price must be greater than 0",
        "city": "City is required"
    }
}
```

### Server Errors
```json
{
    "success": false,
    "code": "server_error",
    "message": "An unexpected error occurred",
    "debug": "Database connection failed" // Only in WP_DEBUG mode
}
```

## Security Considerations

### Input Validation
- All user input sanitized via WordPress functions
- Price validated as positive decimal
- Address components escaped for SQL
- File uploads validated for type and size

### Authorization
- Agents can only modify their own listings
- Admin capability required for others' listings
- JWT verified for iOS requests

### Data Integrity
- Foreign key relationships enforced in code
- Transaction-like behavior for multi-table writes
- Rollback on partial failure

## Performance Considerations

### Query Optimization
- Uses existing BME summary table indexes
- No new indexes required
- Same query path as MLS listings

### Caching
- Transient cache for geocoding results (1 week)
- REST API responses cached per user
- Image CDN caching (1 year)

### Concurrency
- MySQL auto-increment handles ID generation
- No race conditions for ID assignment
- Optimistic locking for edits (modification_timestamp)

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01-14 | Initial release: plugin skeleton, ID generation, health endpoint |
| 1.1.0 | 2026-01-15 | Data layer: BME sync, geocoding, image handler, mobile REST API |
| 1.2.0 | 2026-01-15 | WordPress Admin UI with CRUD forms and photo management |
| 1.2.1 | 2026-01-15 | Bulk operations: archive, activate, delete |
| 1.2.2 | 2026-01-15 | Options endpoint: property_sub_types grouped by property type |

## Production Status (v1.2.2)

The exclusive listings system is fully operational in production with:
- 2 test listings (IDs 6, 7) in Reading, MA
- Full search integration with MLS listings
- Property detail pages rendering correctly
- iOS app support via mobile REST API

### Verified Endpoints
```
Health:      https://bmnboston.com/wp-json/exclusive-listings/v1/health
Search:      https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Reading
Property:    https://bmnboston.com/property/7/
```
