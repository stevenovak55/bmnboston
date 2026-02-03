# Exclusive Listings Feature

## Overview

Exclusive listings are properties that are NOT in the MLS system. They are manually entered by agents through the WordPress admin and synced to the BME (Bridge MLS Extractor) tables for unified search.

**Key Identifier:** Exclusive listings have `listing_id < 1,000,000` while MLS listings have IDs `60,000,000+`

## Architecture

### WordPress Plugin
- **Location:** `wordpress/wp-content/plugins/exclusive-listings/`
- **Main File:** `exclusive-listings.php`
- **Admin Interface:** WordPress admin pages for listing management
- **Current Version:** 1.2.2 (deployed 2026-01-15)
- **Detailed Architecture:** See [ARCHITECTURE.md](../../../wordpress/wp-content/plugins/exclusive-listings/ARCHITECTURE.md) for system diagrams and class structure

### Key Classes

| Class | File | Purpose |
|-------|------|---------|
| `EL_BME_Sync` | `includes/class-el-bme-sync.php` | Syncs exclusive listings to BME tables |
| `EL_Validator` | `includes/class-el-validator.php` | Validates input data |
| `EL_Admin` | `includes/class-el-admin.php` | WordPress Admin interface (v1.2.0) |
| `EL_Geocoder` | `includes/class-el-geocoder.php` | Address geocoding |
| `EL_Mobile_REST_API` | `includes/class-el-mobile-rest-api.php` | iOS app REST API endpoints |
| `EL_Image_Handler` | `includes/class-el-image-handler.php` | Photo upload and management |
| `EL_ID_Generator` | `includes/class-el-id-generator.php` | Sequential ID generation |

### Database Tables

Exclusive listings are stored in the main `wp_el_listings` table, then synced to BME tables:

| Table | Purpose |
|-------|---------|
| `wp_el_listings` | Primary exclusive listing data |
| `wp_bme_listings` | Synced for unified search (shared with MLS) |
| `wp_bme_listing_summary` | Synced summary for fast queries |
| `wp_bme_media` | Photos synced here |

## Data Flow

```
Admin creates listing → wp_el_listings → EL_BME_Sync → wp_bme_* tables → API returns in search
```

## Critical Implementation Details

### 1. Property Sub-Type Mapping (CLAUDE.md Pitfall #28)

The admin form uses simplified property sub-types, but MLS uses different values. The sync process must map these:

```php
// In class-el-bme-sync.php
const PROPERTY_SUB_TYPE_MAP = array(
    'Single Family'  => 'Single Family Residence',
    'Condo'          => 'Condominium',
    'Townhouse'      => 'Townhouse',
    'Multi-Family'   => 'Multi Family',
    'Land'           => 'Land',
    'Commercial'     => 'Commercial',
    'Apartment'      => 'Condominium',
    'Mobile Home'    => 'Mobile Home',
    'Farm'           => 'Farm',
    'Ranch'          => 'Farm',
    'Other'          => 'Other',
);
```

### 2. Listing ID Assignment

Exclusive listings get sequential IDs starting from 1. The `EL_BME_Sync::get_next_listing_id()` method finds the next available ID:

```php
public static function get_next_listing_id() {
    global $wpdb;
    $max_id = $wpdb->get_var(
        "SELECT MAX(listing_id) FROM {$wpdb->prefix}el_listings"
    );
    return max(1, intval($max_id) + 1);
}
```

### 3. Photo Handling

Photos are uploaded to WordPress media library and synced to `bme_media` table:

```php
// Photos array format in wp_el_listings
[
    'https://bmnboston.com/wp-content/uploads/2025/01/photo1.jpg',
    'https://bmnboston.com/wp-content/uploads/2025/01/photo2.jpg',
]

// Synced to bme_media with order preserved
```

### 4. Geocoding

Addresses are geocoded using Google Maps API when coordinates aren't provided:

```php
$coords = EL_Geocoder::geocode($address);
// Returns: ['latitude' => 42.5137, 'longitude' => -71.1145]
```

## iOS Integration

### Visual Distinction (v6.64.0 / iOS v284)

Exclusive listings now have visual distinction in the iOS app:

**Gold "Exclusive" Badge:**
- Appears on PropertyCard (list view)
- Appears on PropertyMapCard (map popup)
- Appears on PropertyDetailView (detail page)
- Gold color (#D9A621) with star icon
- Placed after other status badges (New, Price Reduced, etc.)

**Exclusive Only Filter:**
- Located in Advanced Filters → Special section
- Toggle: "Exclusive Listings Only"
- Shows as "Exclusive Only" chip when active
- API parameter: `exclusive_only=1`

**API Field:**
- `is_exclusive` (boolean) included in all property responses
- Calculated as `listing_id < 1000000`

### Search Results
Exclusive listings appear in normal property search results. No special handling needed in iOS since they're in BME tables.

### Autocomplete
Exclusive listings appear in autocomplete suggestions via the same `/search/autocomplete` endpoint.

**Important (CLAUDE.md Pitfall #29):** When selecting an autocomplete result for address/MLS/street, iOS must clear map bounds:

```swift
case .address:
    filters.address = suggestion.value
    filters.mapBounds = nil  // REQUIRED - see pitfall #29
    mapBounds = nil
```

### Property Details
Use the same `/properties/{listing_key}` endpoint. Works for both MLS and exclusive listings.

## API Endpoints

### Property Search (shared with MLS)
All existing MLD Mobile REST API endpoints work with exclusive listings:

| Endpoint | Notes |
|----------|-------|
| `GET /properties` | Returns exclusive + MLS listings, includes `is_exclusive` field (v6.64.0) |
| `GET /properties?exclusive_only=1` | Filter to show only exclusive listings (v6.64.0) |
| `GET /properties/{key}` | Works with exclusive listing_key, includes `is_exclusive` field |
| `GET /search/autocomplete` | Includes exclusive listing addresses |

### Exclusive Listings Management (iOS app)
These endpoints are for agents to manage their exclusive listings:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/exclusive-listings` | GET | List agent's exclusive listings |
| `/exclusive-listings` | POST | Create new exclusive listing |
| `/exclusive-listings/{id}` | GET | Get single listing |
| `/exclusive-listings/{id}` | PUT | Update listing |
| `/exclusive-listings/{id}` | DELETE | Archive/delete listing |
| `/exclusive-listings/{id}/photos` | GET | Get listing photos |
| `/exclusive-listings/{id}/photos` | POST | Upload photos |
| `/exclusive-listings/{id}/photos/{photo_id}` | DELETE | Delete photo |
| `/exclusive-listings/{id}/photos/order` | PUT | Reorder photos |
| `/exclusive-listings/options` | GET | Get valid property types/statuses |

## Admin Workflow

### Creating Listings
1. Navigate to **Exclusive Listings** in WordPress admin
2. Click **Add New**
3. Fill required fields: address, price, property type
4. Upload photos
5. Click **Publish**
6. Listing automatically syncs to BME tables
7. Appears in iOS/web search immediately

### Bulk Operations (v1.2.1)
1. Select listings using checkboxes (header checkbox selects all)
2. Choose action from dropdown: **Archive**, **Set to Active**, or **Delete Permanently**
3. Click **Apply**
4. Confirmation dialog appears for Archive and Delete actions
5. **Delete Permanently** requires admin (`manage_options`) capability

## Validation Rules

Required fields (enforced by `EL_Validator`):
- `property_type` - Must be: Residential, Commercial, Land, Multi-Family, or Rental
- `list_price` - Must be > 0
- `street_number`
- `street_name`
- `city`
- `state_or_province` - 2-letter code (e.g., MA)
- `postal_code` - 5-digit or 9-digit format

## Testing

### Quick Health Check
```bash
curl -s "https://bmnboston.com/wp-json/exclusive-listings/v1/health" | python3 -m json.tool
```

### Verify Exclusive Listings in Search
```bash
# Search in Reading (where test listings exist)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Reading&per_page=50" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); data=d.get('data',d); \
  excl=[l for l in data.get('listings',[]) if str(l.get('mls_number','')).isdigit() and int(l.get('mls_number',99999999))<1000000]; \
  print(f'Exclusive listings: {len(excl)}'); [print(f'  ID {e[\"mls_number\"]}: {e[\"address\"]}') for e in excl]"
```

### Verify Autocomplete
```bash
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=58+oak+reading"
```

### Verify Property Detail
```bash
# Listing 7: 58 Oak street, Reading
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties/04604afd43791c5a0a346e3e8ed747e1" | python3 -m json.tool | head -30
```

### Verify Web Property Page
Visit: https://bmnboston.com/property/7/

## Known Issues & Solutions

| Issue | Solution |
|-------|----------|
| Listing not appearing in iOS | Check property_sub_type mapping (pitfall #28) |
| Autocomplete click shows blank | Clear map bounds in iOS (pitfall #29) |
| Photos not showing | Verify URLs synced to bme_media table |
| Wrong coordinates | Re-save listing to trigger geocoding |

## Production Status (v1.2.2)

**Verified Working (2026-01-15):**
| Feature | Status | Test Data |
|---------|--------|-----------|
| Health Endpoint | ✅ Working | v1.2.2, 2 exclusive listings |
| Search Integration | ✅ Working | IDs 6, 7 appear in Reading search |
| Property Detail | ✅ Working | ID 7: 58 Oak street, $1.1M, 3 photos |
| Autocomplete | ✅ Working | "58 Oak street, Reading" found |
| Web Property Page | ✅ Working | https://bmnboston.com/property/7/ |
| Options Endpoint | ✅ Working | property_sub_types grouped by type |

**Live Exclusive Listings:**
| ID | Address | City | Price |
|----|---------|------|-------|
| 6 | 863 Main street | Reading | $1,625,000 |
| 7 | 58 Oak street | Reading | $1,100,000 |

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01-14 | Initial release with BME sync |
| 1.1.0 | 2026-01-15 | Added mobile REST API, image handler, geocoding |
| 1.2.0 | 2026-01-15 | Added WordPress Admin interface with photo management |
| 1.2.1 | 2026-01-15 | Added bulk operations: Archive, Set to Active, Delete Permanently |
| 1.2.2 | 2026-01-15 | Options endpoint returns property_sub_types grouped by property type |

**Related: MLS Listings Display Plugin**

| Version | Date | Changes |
|---------|------|---------|
| 6.64.0 | 2026-01-16 | Added `is_exclusive` field to property responses, `exclusive_only` filter |

**Related: iOS App**

| Version | Date | Changes |
|---------|------|---------|
| 284 | 2026-01-16 | Added Exclusive badges (gold star) and "Exclusive Only" filter toggle |

## Files Reference

```
wordpress/wp-content/plugins/exclusive-listings/
├── exclusive-listings.php          # Main plugin file
├── includes/
│   ├── class-el-admin.php          # Admin interface
│   ├── class-el-bme-sync.php       # BME table synchronization
│   ├── class-el-geocoder.php       # Address geocoding
│   ├── class-el-validator.php      # Input validation
│   └── class-el-api.php            # REST API extensions (if any)
├── assets/
│   ├── css/                        # Admin styles
│   └── js/                         # Admin scripts
└── version.json                    # Version tracking
```
