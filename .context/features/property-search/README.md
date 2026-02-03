# Property Search Feature

Cross-platform property search functionality.

## Overview

Property search is the core feature of BMN Boston, available on:
- iOS App (SwiftUI map and list views)
- Web (JavaScript map and filters)

## Code Paths

**CRITICAL:** iOS and web use different code paths.

| Platform | Handler |
|----------|---------|
| iOS | `class-mld-mobile-rest-api.php` |
| Web | `class-mld-query.php` |

When adding features, update BOTH. See [Code Paths](../../architecture/code-paths.md).

## Documentation

*Feature documentation is inline in the code:*
- **Map bounds**: `class-mld-mobile-rest-api.php` and `class-mld-query.php`
- **Saved searches**: `class-mld-saved-search-*` files
- **School filters**: `class-mld-bmn-schools-integration.php`

## Key Components

### iOS

- `PropertySearchViewModel` - Main search state
- `PropertyMapView` - Map display
- `PropertyListView` - List display
- `PropertyFiltersView` - Filter UI

### Web

- `class-mld-query.php` - Query builder
- `main.js` - Map and filter JavaScript
- Filter modal UI

## Filters

See [Filters Guide](../../plugins/mls-listings-display/filters.md) for all supported parameters.

## Quick Filter Presets (v6.58.0)

Horizontal chip buttons for common filter combinations, available on both platforms:

| Preset | Filter Parameters | iOS | Web |
|--------|------------------|-----|-----|
| New This Week | `new_listing_days=7` | ✅ | ✅ |
| Price Reduced | `price_reduced=true` | ✅ | ✅ |
| Open Houses | `open_house_only=true` | ✅ | ✅ |

**Web Implementation:**
- HTML: `templates/partials/map-ui.php` (lines 69-82)
- CSS: `assets/css/mld-enhanced-filters.css`
- JS: `assets/js/map-filters.js` (v4.4.0)

**iOS Implementation:**
- Uses existing filter parameters in `PropertySearchFilters`
- No preset UI (users apply filters via filter modal)

## Property Comparison (iOS v237)

Compare 2-5 saved properties side-by-side from favorites:
- Selection mode in SavedPropertiesView
- ComparisonStore for state management
- PropertyComparisonView for comparison display

## Quick Tests

```bash
# Basic search
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# With filters
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Boston&beds=3"

# Map bounds
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?bounds=42.35,-71.1,42.37,-71.05"

# Quick Filter: Price Reduced
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?price_reduced=1&per_page=1"

# Quick Filter: New This Week
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?new_listing_days=7&per_page=1"

# Quick Filter: Open Houses
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?open_house_only=1&per_page=1"
```
