# Changelog

All notable changes to the Exclusive Listings plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.2] - 2026-01-15

### Changed
- **Options Endpoint** - `property_sub_types` now returns a dictionary grouped by property type instead of a flat array
- iOS app can now properly filter sub-types based on selected property type in create/edit forms

### Response Format Change
```json
// Before (v1.2.1 - flat array):
"property_sub_types": ["Single Family", "Condo", "Townhouse", ...]

// After (v1.2.2 - grouped dictionary):
"property_sub_types": {
    "Residential": ["Single Family", "Condo", "Townhouse", "Apartment", "Mobile Home", "Other"],
    "Commercial": ["Commercial", "Other"],
    "Land": ["Land", "Farm", "Ranch", "Other"],
    "Multi-Family": ["Multi-Family", "Other"],
    "Rental": ["Single Family", "Condo", "Townhouse", "Apartment", "Other"]
}
```

### Verified (Production)
- Health endpoint: v1.2.2, healthy
- 2 exclusive listings in production (IDs 6, 7)
- Search integration working (Reading search returns both)
- Property detail page: https://bmnboston.com/property/7/

---

## [1.2.1] - 2026-01-15

### Added
- **Bulk Operations** in admin list table
  - Archive: Move selected listings to archive status
  - Set to Active: Reactivate archived listings
  - Delete Permanently: Remove listings entirely (admin only)
- Select-all checkbox in table header and footer
- Confirmation dialogs for destructive bulk actions
- Admin capability check (`manage_options`) for permanent deletion

---

## [1.2.0] - 2026-01-15

### Added
- **WordPress Admin Interface** (`class-el-admin.php`)
  - Admin menu under "Exclusive Listings"
  - List table with search and status filters
  - Add/Edit forms with all property fields
  - Photo upload with drag-to-reorder functionality
  - AJAX-based photo management with real-time feedback
  - Inline photo delete and reorder
- Admin assets (CSS/JS) for photo gallery management

### Admin Features
- Property type and sub-type dropdowns
- Address fields with geocoding on save
- Price and property details inputs
- Feature checkboxes (pool, fireplace, basement, HOA)
- Status dropdown (Active, Pending, Closed, etc.)
- Photo gallery with sortable thumbnails

---

## [1.1.0] - 2026-01-15

### Added
- **Data Layer Implementation** - Full CRUD functionality for exclusive listings
- **EL_Validator** (`class-el-validator.php`) - Input validation with property type/status enums
- **EL_Geocoder** (`class-el-geocoder.php`) - Address-to-coordinates using Nominatim API with 1-week transient cache
- **EL_BME_Sync** (`class-el-bme-sync.php`) - Populates all 6 BME tables atomically
- **EL_Image_Handler** (`class-el-image-handler.php`) - WordPress media library integration (max 50 photos, 10MB each)
- **EL_Mobile_REST_API** (`class-el-mobile-rest-api.php`) - CRUD endpoints under `mld-mobile/v1/exclusive-listings/`

### REST API Endpoints (v1.1.0)
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/mld-mobile/v1/exclusive-listings` | GET | JWT | List agent's listings |
| `/mld-mobile/v1/exclusive-listings` | POST | JWT | Create new listing |
| `/mld-mobile/v1/exclusive-listings/{id}` | GET | JWT | Get single listing |
| `/mld-mobile/v1/exclusive-listings/{id}` | PUT | JWT | Update listing |
| `/mld-mobile/v1/exclusive-listings/{id}` | DELETE | JWT | Delete listing |
| `/mld-mobile/v1/exclusive-listings/{id}/photos` | GET | JWT | Get listing photos |
| `/mld-mobile/v1/exclusive-listings/{id}/photos` | POST | JWT | Upload photo |
| `/mld-mobile/v1/exclusive-listings/{id}/photos/{photo_id}` | DELETE | JWT | Delete photo |
| `/mld-mobile/v1/exclusive-listings/{id}/photos/order` | PUT | JWT | Reorder photos |
| `/mld-mobile/v1/exclusive-listings/options` | GET | Public | Get valid property types/statuses |

### BME Tables Populated
- `wp_bme_listings` - Core listing data
- `wp_bme_listing_summary` - Denormalized for fast queries (45 columns)
- `wp_bme_listing_details` - Property specifications
- `wp_bme_listing_location` - Address, coordinates (POINT), county
- `wp_bme_listing_features` - Amenities (pool, fireplace, etc.)
- `wp_bme_media` - Photo URLs and ordering

### Verified
- Exclusive listings appear in standard property search results
- Property detail pages work via listing_key
- Geocoding returns correct Boston coordinates
- Direct MLS number lookup works (e.g., `?mls_number=4`)

---

## [1.0.0] - 2026-01-15

### Added
- Initial plugin skeleton and foundation
- ID generation system using sequential IDs starting from 1
- Database schema with `wp_exclusive_listing_sequence` table for ID generation
- Health check REST endpoint at `/wp-json/exclusive-listings/v1/health` (public)
- Diagnostics endpoint at `/wp-json/exclusive-listings/v1/diagnostics` (admin only, requires nonce)
- Test ID endpoint at `/wp-json/exclusive-listings/v1/test-id` (admin only, WP_DEBUG mode)
- Plugin activation/deactivation hooks with dependency checking
- Integration with MLS Listings Display plugin (required dependency)

### Technical Details
- **ID Strategy**: Sequential IDs (1, 2, 3, ...) with 59M+ gap from MLS IDs
- **MLS ID Range**: 60,000,000+ (Bridge MLS assigned)
- **Exclusive ID Range**: 1 - ~999,999 (plugin assigned)
- **Collision Risk**: Effectively zero
- **Dependency Check**: Uses `MLD_VERSION` constant (not class name)

### Database Tables
- `wp_exclusive_listing_sequence` - Auto-increment sequence for ID generation

### REST API Endpoints
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/exclusive-listings/v1/health` | GET | Public | System health check |
| `/exclusive-listings/v1/diagnostics` | GET | Admin + Nonce | Detailed system diagnostics |
| `/exclusive-listings/v1/test-id` | POST | Admin + WP_DEBUG | Generate test ID |

### Notes
- Admin endpoints require `X-WP-Nonce` header for cookie authentication
- Use `wp.apiFetch()` in browser console for automatic nonce handling
- Health endpoint works without authentication for monitoring systems
