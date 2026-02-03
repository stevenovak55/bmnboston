# File-to-Feature Index

Find the exact files that implement each feature.

**Last Updated:** January 14, 2026

---

## Property Search

### Basic Search (No Filters)
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `search()` |
| **iOS API** | `APIClient.swift` | `.properties(filters:)` |
| **REST API** | `class-mld-mobile-rest-api.php` | `get_active_properties()` |
| **Web Query** | `class-mld-query.php` | `get_listings_for_map_optimized()` |
| **Database** | `bme_listing_summary` | Primary table |

### Map Bounds Search
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS View** | `PropertyMapView.swift` | `regionDidChangeAnimated()` |
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `updateMapBounds()` |
| **REST API** | `class-mld-mobile-rest-api.php` | Line ~280: bounds parsing |
| **Web Query** | `class-mld-query.php` | `apply_bounds_filter()` |

### Polygon Draw Search
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS View** | `PropertyMapView.swift` | `handlePolygonDraw()` |
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `searchPolygon` property |
| **REST API** | `class-mld-mobile-rest-api.php` | Line ~320: polygon parsing |
| **Web** | *Not implemented* | - |

---

## School Grade Filter

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `filters.schoolGrade` |
| **iOS Model** | `PropertyFilters.swift` | `schoolGrade: String?` |
| **REST API** | `class-mld-mobile-rest-api.php` | `get_active_properties()` |
| **Integration** | `class-mld-bmn-schools-integration.php` | `apply_school_filter()` |
| **School Query** | `class-mld-bmn-schools-integration.php` | `get_top_schools_cached()` |
| **Web Query** | `class-mld-query.php` | `apply_school_grade_filter()` |
| **Rankings Table** | `wp_bmn_school_rankings` | `letter_grade`, `year` columns |

**Critical:** Uses `MAX(year)` query to avoid year rollover bug!

---

## Near Top School Filters

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `filters.nearTopElementary` |
| **REST API** | `class-mld-mobile-rest-api.php` | `near_top_elementary` param |
| **Integration** | `class-mld-bmn-schools-integration.php` | `filter_by_top_schools()` |
| **Web Query** | `class-mld-query.php` | Same integration class |

---

## Saved Searches

### Save a Search
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `saveCurrentSearch()` |
| **iOS Model** | `SavedSearch.swift` | Full model definition |
| **iOS API** | `APIClient.swift` | `POST .savedSearches` |
| **REST API** | `class-mld-mobile-rest-api.php` | `create_saved_search()` |
| **Web AJAX** | `class-mld-ajax.php` | `save_search()` |
| **Database** | `wp_mld_saved_searches` | Primary table |

### Apply Saved Search
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `applySavedSearch()` |
| **iOS Decoder** | `SavedSearch.swift` | `toPropertyFilters()` |
| **Web JS** | `main.js` | `applySavedSearch()` |

---

## Favorites

### Add/Remove Favorite
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS Service** | `FavoritesService.swift` | `addFavorite()`, `removeFavorite()` |
| **iOS API** | `APIClient.swift` | `POST/DELETE .favorites(id:)` |
| **REST API** | `class-mld-mobile-rest-api.php` | `add_favorite()`, `remove_favorite()` |
| **Web AJAX** | `class-mld-ajax.php` | `toggle_favorite()` |
| **Database** | `wp_usermeta` | `mld_favorites` key |

---

## Authentication

### Login
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `AuthViewModel.swift` | `login()` |
| **iOS API** | `APIClient.swift` | `POST .login` |
| **iOS Token** | `TokenManager.swift` | `saveTokens()` |
| **REST API** | `class-mld-mobile-rest-api.php` | `handle_login()` |
| **JWT** | `class-mld-jwt.php` | `generate_token()` |
| **Web** | WordPress native | `wp_signon()` |

### Token Refresh
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS** | `TokenManager.swift` | `refreshTokenIfNeeded()` |
| **iOS API** | `APIClient.swift` | `POST .refreshToken` |
| **REST API** | `class-mld-mobile-rest-api.php` | `handle_refresh()` |

---

## Appointments

### Book Appointment
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `AppointmentViewModel.swift` | `bookAppointment()` |
| **iOS Service** | `AppointmentService.swift` | `createAppointment()` |
| **iOS API** | `APIClient.swift` | `POST .appointments` |
| **REST API** | `class-snab-rest-api.php` | `create_appointment()` |
| **Web AJAX** | `class-snab-frontend-ajax.php` | `handle_booking()` |
| **Google Cal** | `class-snab-google-calendar.php` | `create_staff_event()` |
| **Database** | `wp_snab_appointments` | Primary table |

**Critical:** iOS and Web use DIFFERENT code paths!

### Get Availability
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS Service** | `AppointmentService.swift` | `getAvailability()` |
| **REST API** | `class-snab-rest-api.php` | `get_availability()` |
| **Web AJAX** | `class-snab-frontend-ajax.php` | `get_availability()` |
| **Availability** | `class-snab-availability-service.php` | `calculate_slots()` |

### Cancel Appointment
| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS** | `AppointmentService.swift` | `cancelAppointment()` |
| **REST API** | `class-snab-rest-api.php` | `cancel_appointment()` |
| **Web AJAX** | `class-snab-frontend-ajax.php` | `cancel_appointment()` |

---

## Autocomplete

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS ViewModel** | `PropertySearchViewModel.swift` | `fetchSuggestions()` |
| **iOS API** | `APIClient.swift` | `.autocomplete(term:)` |
| **REST API** | `class-mld-mobile-rest-api.php` | `get_autocomplete()` |
| **Web AJAX** | `class-mld-ajax.php` | `get_autocomplete_suggestions()` |

---

## Property Detail

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS View** | `PropertyDetailView.swift` | Main view |
| **iOS ViewModel** | `PropertyDetailViewModel.swift` | `loadProperty()` |
| **iOS API** | `APIClient.swift` | `.propertyDetail(id:)` |
| **REST API** | `class-mld-mobile-rest-api.php` | `get_property_detail()` |
| **Web Template** | `single-property.php` | WordPress template |
| **Photos** | `bme_media` table | `listing_id` join |
| **Details** | `bme_listing_details` table | Extended info |
| **Location** | `bme_listing_location` table | Address, coordinates |

---

## Schools Display (on Property)

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS View** | `PropertyDetailView.swift` | Schools section |
| **iOS Model** | `School.swift` | Full model |
| **REST API** | `class-rest-api.php` (bmn-schools) | `get_property_schools()` |
| **Web Template** | `single-property.php` | `get_nearby_schools()` |
| **Rankings** | `class-ranking-calculator.php` | `calculate_composite_score()` |

---

## Dark Mode

| Platform | File | Function/Method |
|----------|------|-----------------|
| **iOS Manager** | `AppearanceManager.swift` | `toggleDarkMode()` |
| **iOS Colors** | `Colors.swift` | Adaptive color definitions |
| **iOS App** | `BMNBostonApp.swift` | `.preferredColorScheme()` |
| **Web** | *Not implemented* | Browser handles |

---

## Sitemaps

| Platform | File | Function/Method |
|----------|------|-----------------|
| **Generator** | `class-mld-sitemap-generator.php` | `generate_sitemap()` |
| **Property** | `class-mld-sitemap-generator.php` | `get_property_urls()` |
| **Schools** | `class-mld-sitemap-generator.php` | `get_school_urls()` |
| **Index** | `class-mld-sitemap-generator.php` | `get_sitemap_index()` |
| **Cache** | `wp-content/cache/mld-sitemaps/` | XML files |

---

## iOS App Store Banner

| Component | File | Function/Method |
|-----------|------|-----------------|
| **Mobile Banner** | `class-mld-app-store-banner.php` | `render_banner()` |
| **Desktop Footer** | `class-mld-app-store-banner.php` | `render_desktop_footer()` |
| **Hero Promo** | `section-hero.php` (theme) | Inline HTML + JS |
| **Banner Class** | `class-mld-app-store-banner.php` | `MLD_App_Store_Banner` singleton |

**App Store URL:** `https://apps.apple.com/us/app/bmn-boston/id6745724401`

**Key Pattern:** Client-side JavaScript device detection for CDN cache compatibility. All HTML rendered, visibility controlled via CSS classes added by JS based on `navigator.userAgent`.

---

## Quick Reference Table

| Feature | iOS Entry Point | API Handler | Web Handler |
|---------|-----------------|-------------|-------------|
| Property Search | PropertySearchViewModel | class-mld-mobile-rest-api | class-mld-query |
| School Filter | PropertySearchViewModel | class-mld-bmn-schools-integration | class-mld-bmn-schools-integration |
| Saved Searches | PropertySearchViewModel | class-mld-mobile-rest-api | class-mld-ajax |
| Favorites | FavoritesService | class-mld-mobile-rest-api | class-mld-ajax |
| Authentication | AuthViewModel | class-mld-mobile-rest-api | WordPress native |
| Appointments | AppointmentService | class-snab-rest-api | class-snab-frontend-ajax |
| Autocomplete | PropertySearchViewModel | class-mld-mobile-rest-api | class-mld-ajax |
| Property Detail | PropertyDetailViewModel | class-mld-mobile-rest-api | single-property.php |
| App Store Banner | N/A (native app) | class-mld-app-store-banner | class-mld-app-store-banner + theme |

---

## File Path Reference

### iOS Files
```
ios/BMNBoston/
├── Features/
│   ├── PropertySearch/
│   │   ├── ViewModels/PropertySearchViewModel.swift
│   │   └── Views/PropertyMapView.swift
│   ├── Authentication/
│   │   └── ViewModels/AuthViewModel.swift
│   └── Appointments/
│       └── ViewModels/AppointmentViewModel.swift
├── Core/
│   ├── Models/
│   │   ├── Property.swift
│   │   ├── SavedSearch.swift
│   │   └── School.swift
│   ├── Networking/
│   │   ├── APIClient.swift
│   │   └── TokenManager.swift
│   └── Services/
│       ├── FavoritesService.swift
│       └── AppointmentService.swift
└── UI/Styles/Colors.swift
```

### WordPress Files
```
wordpress/wp-content/plugins/
├── mls-listings-display/includes/
│   ├── class-mld-mobile-rest-api.php    # iOS API
│   ├── class-mld-query.php              # Web queries
│   ├── class-mld-bmn-schools-integration.php
│   ├── class-mld-ajax.php               # Web AJAX
│   ├── class-mld-sitemap-generator.php
│   └── class-mld-app-store-banner.php   # iOS App Store promotion
├── bmn-schools/includes/
│   ├── class-rest-api.php               # Schools API
│   ├── class-ranking-calculator.php
│   └── class-database-manager.php
└── sn-appointment-booking/includes/
    ├── class-snab-rest-api.php          # iOS API
    ├── class-snab-frontend-ajax.php     # Web AJAX
    ├── class-snab-availability-service.php
    └── class-snab-google-calendar.php

wordpress/wp-content/themes/flavor-flavor-flavor/
└── template-parts/homepage/
    └── section-hero.php                 # Homepage hero with app promo
```
