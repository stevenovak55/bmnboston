# Code Paths: iOS vs Web

**CRITICAL:** The iOS app and web interface use completely different code paths for the same data. This is the #1 source of bugs when adding features.

## Property Search Code Paths

| Platform | Endpoint | Handler | File |
|----------|----------|---------|------|
| **iOS** | REST API `/wp-json/mld-mobile/v1/properties` | `get_active_properties()` | `class-mld-mobile-rest-api.php` |
| **Web** | AJAX `get_map_listings` | `get_listings_for_map_optimized()` | `class-mld-query.php` |

### Request Flow

**iOS App:**
```
SwiftUI → APIClient → REST API → class-mld-mobile-rest-api.php
                                   ├── get_active_properties()
                                   ├── apply_school_filter()
                                   └── Returns JSON
```

**Web Map:**
```
JavaScript → AJAX → class-mld-query.php
                      ├── get_listings_for_map_optimized()
                      ├── apply_school_filter()
                      └── Returns JSON
```

### What This Means for Development

1. **Adding a new filter?** Update BOTH files
2. **Fixing a bug?** Check if it affects both paths
3. **Testing a feature?** Test on both iOS AND web

### Historical Bugs from Path Mismatch

**Dec 21, 2025 - School Filter Bug:**
- `school_grade` filter worked on iOS but not web
- iOS REST API called `apply_school_filter()`
- Web Query class did NOT call `apply_school_filter()`
- Fix: Added the call to web path

**Dec 25, 2025 - Amenity Filter Parity:**
- iOS had 10+ amenity filters
- Web only had 3
- Fix: Added all amenity filters to `class-mld-query.php`

## Appointment Booking Code Paths

| Platform | Endpoint | Handler | File |
|----------|----------|---------|------|
| **iOS** | REST API `POST /snab/v1/appointments` | `create_appointment()` | `class-snab-rest-api.php` |
| **Web** | AJAX `snab_book_appointment` | `handle_booking()` | `class-snab-frontend-ajax.php` |

### Key Differences

| Feature | iOS (REST API) | Web (AJAX) |
|---------|----------------|------------|
| Authentication | JWT token | WordPress nonce |
| Response format | JSON object | JSON wrapped |
| Error handling | HTTP status codes | `success` boolean |

## Saved Searches Code Paths

| Platform | Save Format | Apply Format |
|----------|-------------|--------------|
| **iOS** | `city`, `min_price`, `property_type` | Filters applied directly |
| **Web** | `City`, `price_min`, `PropertyType` | URL hash parameters |

**Cross-platform compatibility:**
- When applying iOS-saved search on web, must check both key formats
- `buildSearchUrl()` in JavaScript handles both formats

## Filter Parameter Mapping

Some filters use different names:

| iOS Parameter | Web Parameter | Notes |
|---------------|---------------|-------|
| `city` | `City` | Case difference |
| `min_price` | `price_min` | Order difference |
| `property_type` | `PropertyType` | Case and format |
| `status` (Sold) | `standard_status` (Closed) | Value mapping needed |

## Development Checklist

When adding ANY new feature:

- [ ] Identify which code paths are affected
- [ ] Implement in iOS REST API (`class-mld-mobile-rest-api.php`)
- [ ] Implement in Web Query (`class-mld-query.php`)
- [ ] Test on iOS app
- [ ] Test on web browser
- [ ] Verify filter counts match between platforms
