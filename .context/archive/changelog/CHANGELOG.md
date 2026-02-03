# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [MLS Listings Display v6.68.8] - 2026-01-19

### Fixed - District Grade Rounding Bug

**Problem:** Saved search school filter notifications were incorrectly including properties in B+ school districts when users filtered for A-grade districts.

**Root Cause:** The `get_all_district_averages()` method in `class-mld-bmn-schools-integration.php` was using `round()` on average percentile values, which inflated borderline grades:
- Dartmouth district: 69.8% average percentile
- With `round(69.8)` = 70 → A- grade (incorrect)
- Without rounding: 69.8 < 70 → B+ grade (correct)

**Fix:** Removed `round()` from the percentile-to-grade conversion. Now uses raw percentile value.

**Files Changed:**
- `class-mld-bmn-schools-integration.php` - Removed round() in get_all_district_averages()
- `class-mld-enhanced-filter-matcher.php` - Added debug logging (v6.68.7)
- `class-mld-fifteen-minute-processor.php` - Added debug logging (v6.68.7)

**Related:** Also includes debug logging from v6.68.7 to monitor school filter behavior in production.

---

## [MLS Listings Display v6.62.2 + Theme v1.5.8] - 2026-01-14

### Enhanced - iOS App Store Banner System (Cache-Compatible)

**Problem Solved:** App Store banners weren't showing correctly due to Kinsta CDN full-page caching. Server-side PHP user-agent detection cached one version (desktop) and served it to everyone.

**Solution:** Rewrote entire banner system to use client-side JavaScript device detection. Same HTML is served to all users, JavaScript runs after page load to show the appropriate element.

#### Components

| Component | Location | Visibility | File |
|-----------|----------|------------|------|
| Mobile Banner | Fixed top of page | iOS devices only | `class-mld-app-store-banner.php` |
| Desktop Footer | Page footer | Desktop browsers only | `class-mld-app-store-banner.php` |
| Hero Promo | Homepage hero (below CTA buttons) | Desktop browsers only | `section-hero.php` (theme) |

#### Mobile Banner Features
- Fixed position banner at top
- "BMN Boston - Get our mobile app!" with App Store badge
- Dismissible with 30-day cookie (`mld_app_banner_dismissed`)
- Skips iOS app WebView users (BMNBoston in user agent)
- Pushes page content down using `margin-top` on `<html>`

#### Desktop Footer Features
- QR code generated via qrserver.com API
- "Scan with your phone" label
- "Get the BMN Boston App" headline
- Feature description text
- App Store badge with hover effect
- Dark gradient background design

#### Hero Section Promo Features
- Compact inline design below "Search Properties" button
- Small QR code (60x60px) with rounded corners
- "Get the iOS App" label
- App Store badge
- Light card styling with subtle shadow
- Hidden on mobile (CSS media query)

#### Technical Pattern
```javascript
// All components hidden by default (display: none)
// JS adds visibility class based on device detection
var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
var isAndroid = /Android/.test(navigator.userAgent);

if (isIOS && !dismissed) banner.classList.add('mld-banner-visible');
if (!isIOS && !isAndroid) footer.classList.add('mld-footer-visible');
```

### Fixed - Missing JS/CSS Files Causing 403/404 Errors

**Problem:** steve-novak.com search page appeared "blurry" (lazy-load blur never removed) due to JavaScript files failing to load.

**Root Causes:**
1. `mld-saved-searches-mobile-fix.js` - File was being enqueued but didn't exist (404)
2. `mld-schools-glossary.js` and `mld-public-tracker.js` - Wrong permissions (600 instead of 644) causing 403

**Solution:**
- Created placeholder files: `mld-saved-searches-mobile-fix.js` and `.css`
- Fixed file permissions to 644 across all JS/CSS assets

### Files Modified

**Plugin (mls-listings-display):**
- `includes/class-mld-app-store-banner.php` - Complete rewrite for cache compatibility
- `assets/js/mld-saved-searches-mobile-fix.js` - NEW placeholder file
- `assets/css/mld-saved-searches-mobile-fix.css` - NEW placeholder file

**Theme (flavor-flavor-flavor):**
- `template-parts/homepage/section-hero.php` - Added hero section app promo

### Deployed To
- bmnboston.com
- steve-novak.com

### App Store URL
`https://apps.apple.com/us/app/bmn-boston/id6745724401`

---

## [MLS Listings Display v6.62.0] - 2026-01-14

### Fixed - Critical iOS Registration Bug

**iOS user names were NOT being saved during registration!**
- iOS sent `first_name`, `last_name` as separate parameters
- Server expected `name` (single combined field)
- Result: All iOS user registrations had empty first_name/last_name in database

### Added - Unified Signup Experience

**Server Registration Endpoint (`class-mld-mobile-rest-api.php`)**
- Now accepts `first_name`/`last_name` directly (priority over `name` parameter)
- Falls back to parsing `name` if separate fields not provided (backwards compatible)
- Added `phone` to login/register responses

**New Web Signup Page**
- Created `/signup` page with `[mld_signup]` shortcode
- Works with or without `?ref=` referral code parameter
- Full-width page template without sidebar
- Form fields: First Name, Last Name, Email, Phone (optional), Password, Referral Code (optional)
- If no referral code: assigns user to default agent

**iOS Registration Enhancements**
- Added phone field to RegisterView (optional)
- Added referral code field (pre-filled from deep link if available)
- Updated APIEndpoint.register() with phone parameter
- Added phone property to User model

**Updated Registration Links Sitewide**
- All "Create Account" / "Sign Up" links now point to `/signup/`
- Updated: map-ui.php, single-property-desktop-v3.php, single-property-mobile-v3.php
- Updated: class-mld-saved-search-init.php, mld-saved-searches.js
- Updated: theme header.php (2 locations)

### Files Modified
- `includes/class-mld-mobile-rest-api.php` - Registration fix, phone in responses
- `includes/class-mld-referral-signup.php` - New `[mld_signup]` shortcode
- `includes/class-mld-saved-search-init.php` - Updated registerUrl
- `templates/partials/map-ui.php` - Updated signup link
- `templates/single-property-desktop-v3.php` - Updated signup link
- `templates/single-property-mobile-v3.php` - Updated signup link
- `assets/js/mld-saved-searches.js` - Updated fallback signup URL
- `version.json` - Version 6.62.0
- `mls-listings-display.php` - Version bump

### Theme Files Modified
- `themes/flavor-flavor-flavor/header.php` - Updated 2 registration URLs
- `themes/flavor-flavor-flavor/page-signup.php` - NEW full-width page template

### Platform Parity Achieved
| Field | iOS | Web |
|-------|-----|-----|
| First Name | Collected & saved | Collected & saved |
| Last Name | Collected & saved | Collected & saved |
| Phone | Collected & saved | Collected & saved |
| Referral Code | Optional | Optional |

---

## [iOS App v277] - 2026-01-14

### Fixed
- User names now properly saved during registration (server-side fix)

### Added
- Phone number field in registration (optional)
- Referral code field in registration (optional, pre-filled from deep link)
- Phone property in User model

### Files Modified
- `Features/Authentication/Views/LoginView.swift` - Phone and referral code fields
- `Core/Networking/APIEndpoint.swift` - Phone parameter in register()
- `Features/Authentication/ViewModels/AuthViewModel.swift` - Pass phone to API
- `Core/Models/User.swift` - Added phone property

---

## [MLS Listings Display v6.61.0] - 2026-01-14

### Added - iOS App Store Promotion

**App Store Link**: https://apps.apple.com/us/app/bmn-boston/id6745724401

**Mobile Smart App Banner** (`class-mld-app-store-banner.php`)
- Fixed position banner at top of page for iOS Safari users
- Shows "BMN Boston - Get our mobile app!" with App Store badge
- Pushes page content down (not overlay) using margin-top on html element
- Dismissible with 30-day cookie to remember preference
- Skips iOS app WebView users to avoid showing banner in-app

**Desktop Footer Promotion**
- Subtle footer banner for desktop visitors
- Shows "Get instant property alerts on your iPhone" with App Store badge

**Email Template Badges**
- Added App Store badge to MLD Email Template Engine footer (saved search alerts, digests, welcome emails)
- Added App Store badge to Alert Email Builder footer (property alerts)
- Added App Store badge to enhanced listing updates template
- Added App Store badge to SN Appointment Booking email template (confirmations, reminders)

**Documentation Updates**
- Added App Store URL to `/CLAUDE.md` Environment section
- Added App Store URL to `.context/README.md`
- Added App Store URL to `.context/getting-started/overview.md` Key URLs
- Added App Store URL to `.context/platforms/ios/README.md` Project Info table

### Fixed
- Corrected App Store URL in `referral-signup-page.php` (was `id6740043829`, now `id6745724401`)

### Files Added
- `includes/class-mld-app-store-banner.php` - Smart app banner for iOS promotion

### Files Modified
- `mls-listings-display.php` - Version bump, banner class initialization
- `version.json` - Version 6.61.0
- `templates/referral-signup-page.php` - Fixed App Store URL
- `includes/saved-searches/class-mld-email-template-engine.php` - App Store badge in footer
- `includes/saved-searches/class-mld-alert-email-builder.php` - App Store badge in footer
- `templates/emails/listing-updates-enhanced.php` - App Store badge in footer
- `sn-appointment-booking/templates/emails/base.php` - App Store badge in footer

### Testing Verified
- Property Search API: 17,787 properties
- School Filter (A grade): 2,372 properties
- Schools Health Check: healthy
- App Store URL: Returns HTTP 200
- All 4 email templates have App Store badge
- Mobile banner pushes content down correctly

---

## [MLS Listings Display v6.56.0] - 2026-01-11

### Added - iOS/Web Alignment (Phase 2-5)

**Phase 2: Filter Enhancements (Web)**
- Bathrooms filter changed to discrete buttons (Any, 1+, 1.5+, 2+, 2.5+, 3+) matching iOS
- Days on Market filter with 7/14/30/90 day presets
- Lot Size filter with acre presets (¼, ½, 1, 2, 5 acres)
- Live matching count on Apply button
- Acre-friendly filter tag labels (shows "½ acre" instead of "21780 sq ft")

**Phase 3: Map Enhancements (Web)**
- My Location GPS button to center map on user's location
- Auto-search toggle (manual vs auto search on map pan)

**Phase 4: Property Detail Enhancements (Web)**
- Horizontal scrolling property highlight chips (Pool, Waterfront, View, etc.)
- Expanded school info with trends, demographics, college outcomes
- Education Glossary modal with tappable terms

**Phase 5: Saved Searches**
- Web: Share action for saved searches
- iOS: Duplicate action for saved searches (SavedSearchesView.swift)

### Changed
- Border radius standardized to 12px (was 8px) for property cards
- Lot size preset button styling added to main.css
- Filter state restoration includes lot size presets

### Files Modified
- `templates/partials/map-ui.php` - Filter UI, map controls
- `assets/css/main.css` - Lot size preset button styles
- `assets/js/map-filters.js` - Filter logic, state management, acre labels
- `assets/js/map-core.js` - My Location, auto-search toggle
- `assets/js/saved-searches-frontend.js` - Share action
- `includes/class-mld-query.php` - days_on_market, lot_size parameters
- iOS: `SavedSearchesView.swift` - Duplicate action

### Impact
- Mobile web experience now closely mirrors iOS app
- Improved filter UX with discrete buttons and presets
- Better map controls for location-aware searching
- Enhanced property detail pages with school insights

---

## [iOS App v235] - 2026-01-11

### Added
- Duplicate action for saved searches (swipe menu)

### Files Modified
- `Features/SavedSearches/Views/SavedSearchesView.swift`

---

## [MLS Listings Display v6.55.2] - 2026-01-11

### Changed - Debug Cleanup & Performance Optimization

**JavaScript Console.log Cleanup (85% reduction)**
- Removed ~86 console.log debug statements from production JavaScript files
- Cleaned files: map-core.js, map-api.js, map-filters.js, mld-client-dashboard.js, mld-standalone-cma.js, mld-card-generator.js, chatbot-widget.js, chatbot-session-manager.js, mld-public-tracker.js
- Retained console.error calls for actual error reporting

**PHP Debug Cleanup**
- Removed commented-out debug code from class-mld-seo.php
- Removed commented-out debug code from class-mld-ajax.php

**Documentation Updates**
- Updated DEPRECATED.md version reference from 6.43.1 to 6.55.0
- Fixed BMN Schools version inconsistency (0.6.37 → 0.6.38) in documentation

### Impact
- Reduced client-side console noise in production
- Improved JavaScript execution performance (fewer I/O operations)
- Cleaner browser developer tools experience for debugging

---

## [Unreleased]

### Added
- **Map View** - Full map view for property search with property pins showing prices
- **PropertyMapView.swift** - New iOS 16 compatible map component using MKMapView
- **PropertyAnnotation** - Custom map annotations with price labels and callouts
- **PropertyMapCard** - Bottom card showing selected property details on map

### Changed
- **Filter parameters** - Fixed beds/baths filter to send correct API parameter names (`beds` and `baths` instead of `min_beds` and `min_baths`)
- **Property types** - Updated filter options to match actual database values:
  - Residential Lease, Residential, Residential Income, Commercial Lease, Commercial Sale, Land, Business Opportunity
- **City filter** - Added city picker to filters (Cambridge, Reading)
- **Removed status filter** - API only returns Active listings, so removed non-functional status filter

### Fixed
- **Map/List toggle** - Now properly switches between list view and map view
- **PropertyLocationMapView** - Renamed existing map component in PropertyDetailView to avoid name conflict

### Removed
- Status filter (API hardcodes `standard_status = 'Active'`)

---

## [1.0.2] - 2025-12-18

### Added - WordPress Docker Integration & API Connection

**Docker Environment (Already Running)**
- WordPress backend accessible at http://localhost:8080
- phpMyAdmin at http://localhost:8081
- Mailhog (email testing) at http://localhost:8025
- MySQL at localhost:3306
- Created missing `wp_bme_listing_photos` database table for property photos

**iOS App - API Integration**
- Added `Info.plist` with App Transport Security exception for localhost HTTP connections
- Added guest mode functionality (`continueAsGuest()` in AuthViewModel)
- Added `canAccessApp` computed property for auth state (authenticated OR guest)
- Added detailed API decoding error logging with field-level diagnostics

### Changed - Model Updates for API Compatibility

**Property.swift**
- Changed `id` type from `Int` to `String` to match API response
- Changed address from nested `PropertyAddress` object to flat structure (address, city, state, zip)
- Renamed fields: `bedrooms` → `beds`, `bathrooms` → `baths`, `squareFeet` → `sqft`
- Added flexible `baths` decoder to handle both Int and Double from API
- Added `PropertyListData` wrapper for paginated API responses
- Added `PropertySearchFilters.toDictionary()` for API query parameters
- Removed unused `PropertyAddress`, `Coordinates`, `PropertyImage`, `PropertyStatus` types

**User.swift**
- Renamed `AuthResponse` to `AuthResponseData` with snake_case CodingKeys
- Changed `displayName` to `name` to match API response
- Added explicit CodingKeys for snake_case field mapping (first_name, last_name, etc.)

**APIClient.swift**
- Removed `.convertFromSnakeCase` decoder strategy (conflicts with explicit CodingKeys)
- Updated token refresh to use `AuthResponseData` type
- Added raw JSON debug logging (first 500 chars)
- Added detailed DecodingError diagnostics (keyNotFound, typeMismatch, valueNotFound, dataCorrupted)

**APIEndpoint.swift**
- Changed `propertyDetail(id:)` parameter from `Int` to `String`
- Changed `addFavorite(listingId:)` parameter from `Int` to `String`
- Changed `removeFavorite(listingId:)` parameter from `Int` to `String`

**AuthViewModel.swift**
- Added `isGuestMode` published property with UserDefaults persistence
- Updated login/register to use `AuthResponseData` directly (not wrapped in APIResponse)
- Updated `fetchCurrentUser()` to use `User` directly

**PropertySearchViewModel.swift**
- Updated to use `PropertyListData` directly from API (not wrapped in APIResponse)

**PropertyDetailView.swift**
- Updated to use `PropertyDetail` directly from API (not wrapped)
- Fixed iOS 16 compatible map using `MKMapView` UIViewRepresentable

**ContentView.swift**
- Changed auth check from `isAuthenticated` to `canAccessApp`

**LoginView.swift**
- Connected "Continue as Guest" button to `authViewModel.continueAsGuest()`

**Xcode Project (project.pbxproj)**
- Added Info.plist file reference
- Added INFOPLIST_FILE build setting for Debug and Release configurations

### Fixed
- Fixed API decoding errors caused by snake_case/camelCase key conflicts
- Fixed "Continue as Guest" button not working (was empty action)
- Fixed login not working due to APIResponse double-wrapping
- Fixed property list not loading due to `baths` Int/Double type mismatch
- Fixed property detail 500 error due to missing database table

### Database Changes
- Created `wp_bme_listing_photos` table in WordPress MySQL database:
  ```sql
  CREATE TABLE wp_bme_listing_photos (
      id INT AUTO_INCREMENT PRIMARY KEY,
      listing_id VARCHAR(64) NOT NULL,
      media_url VARCHAR(500) NOT NULL,
      display_order INT DEFAULT 0,
      INDEX idx_listing_id (listing_id)
  );
  ```

### Verified Working
- Property list loads 473 listings from API
- Property detail view with map
- User login with demo@bmnboston.com / demo1234
- User registration
- Continue as Guest mode
- JWT token authentication

---

## [1.0.1] - 2024-12-18

### Fixed - iOS 16 Compatibility & Build Issues
- Fixed MapKit iOS 17+ API usage - replaced `Map(initialPosition:)` and `Marker` with `UIViewRepresentable` wrapper using `MKMapView` and `MKPointAnnotation` (PropertyDetailView.swift)
- Fixed `ContentUnavailableView` iOS 17+ API - replaced with custom VStack-based error/empty state views (PropertyDetailView.swift, PropertySearchView.swift)
- Fixed `[String: Any]` encoding error - changed from `JSONEncoder` to `JSONSerialization.data(withJSONObject:)` (APIClient.swift:149)
- Fixed variable shadowing issue - renamed local `request` variable to `urlRequest` to avoid conflict with `request()` method (APIClient.swift)
- Fixed closure capture semantics - added explicit `self.` reference in string interpolation (PropertySearchViewModel.swift:66)
- Fixed Xcode project file paths - moved source files from `ios/BMNBoston/BMNBoston/` to `ios/BMNBoston/` to match project.pbxproj references
- Fixed test target locations - moved BMNBostonTests and BMNBostonUITests to `ios/` level (sibling to BMNBoston folder)

### Changed
- Updated DEVELOPMENT_ASSET_PATHS in project.pbxproj to correct path

---

## [1.0.0] - 2024-12-18

### Added - iOS App Foundation
- **Xcode Project**: Created BMNBoston.xcodeproj with iOS 16+ deployment target
  - Bundle ID: com.bmnboston.app
  - Development Team: TH87BB2YU9
  - Targets: BMNBoston, BMNBostonTests, BMNBostonUITests
  - Debug and Release configurations

- **App Entry Point** (`ios/BMNBoston/App/`)
  - BMNBostonApp.swift - SwiftUI app entry point
  - ContentView.swift - Root view with authentication routing
  - MainTabView.swift - Tab bar with Search, Saved, Appointments, Profile
  - Environment.swift - API environment configuration (dev/staging/prod)

- **Core Networking Layer** (`ios/BMNBoston/Core/Networking/`)
  - APIClient.swift - Async/await HTTP client with JWT token management
  - APIEndpoint.swift - All REST API endpoints mapped to functions
  - APIError.swift - Typed error handling
  - TokenManager.swift - Secure token storage with Keychain

- **Data Models** (`ios/BMNBoston/Core/Models/`)
  - Property.swift - Property listing and detail models with search filters
  - User.swift - User authentication model with storage helpers
  - SavedSearch.swift - Saved search criteria and notification preferences
  - Appointment.swift - Appointment booking model with staff and time slots

- **Storage** (`ios/BMNBoston/Core/Storage/`)
  - KeychainManager.swift - Secure keychain access wrapper

- **Authentication Feature** (`ios/BMNBoston/Features/Authentication/`)
  - AuthViewModel.swift - Login/register/logout state management
  - LoginView.swift - Login, register, and forgot password views

- **Property Search Feature** (`ios/BMNBoston/Features/PropertySearch/`)
  - PropertySearchViewModel.swift - Search, filter, and pagination logic
  - PropertySearchView.swift - Property list with filters and sorting
  - PropertyDetailView.swift - Full property details with images, map, agent info

- **UI Components** (`ios/BMNBoston/UI/`)
  - PropertyCard.swift - Property card variants (full, compact, row)
  - Colors.swift - App colors and button styles (Primary, Secondary, Outline)

- **Resources** (`ios/BMNBoston/Resources/`)
  - Assets.xcassets with AccentColor and AppIcon placeholders
  - Preview Assets for SwiftUI previews

- **Tests** (`ios/BMNBostonTests/`, `ios/BMNBostonUITests/`)
  - BMNBostonTests.swift - Unit tests for Property and User models
  - BMNBostonUITests.swift - UI tests for app flow
  - BMNBostonUITestsLaunchTests.swift - Launch performance tests

### Added - Initial Project Setup (2024-12-17)
- Created development environment directory structure
- Set up documentation framework

---

## Version History

### [0.0.1] - 2024-12-17
#### Added
- Initial project scaffold
- Directory structure for WordPress and iOS development
- Documentation framework
- Docker configuration templates
- Development scripts

---

## Template for New Entries

```markdown
## [X.X.X] - YYYY-MM-DD

### Added
- New feature description [#issue] (files affected)

### Changed
- Change description [#issue] (files affected)

### Fixed
- Bug fix description [#issue] (files affected)

### Removed
- Removed feature description [#issue] (files affected)

### Database Changes
- Description of DB migration (migration file: XXX)

### Breaking Changes
- Description of breaking change and migration path
```
