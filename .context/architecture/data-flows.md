# Data Flow Diagrams

Visual representations of how data flows through the system.

**Last Updated:** January 1, 2026

---

## Property Search Flow (iOS)

```
┌─────────────────────────────────────────────────────────────────────┐
│  iOS App                                                            │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│  User taps "Search" or adjusts filter                               │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────┐                                │
│  │  PropertySearchViewModel.swift  │                                │
│  │  ───────────────────────────────│                                │
│  │  1. Validate filters            │                                │
│  │  2. Cancel previous searchTask  │                                │
│  │  3. Create new Task             │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  APIClient.swift                │                                │
│  │  ───────────────────────────────│                                │
│  │  4. Build URL with query params │                                │
│  │  5. Add auth header (if logged) │                                │
│  │  6. Execute URLSession request  │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
└──────────────────┼──────────────────────────────────────────────────┘
                   │
                   │ HTTPS Request
                   │ GET /wp-json/mld-mobile/v1/properties?city=Boston&beds=3
                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  WordPress Server                                                   │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│  ┌─────────────────────────────────┐                                │
│  │  class-mld-mobile-rest-api.php  │                                │
│  │  ───────────────────────────────│                                │
│  │  7. Parse query parameters      │                                │
│  │  8. Validate JWT (if present)   │                                │
│  │  9. Build SQL query             │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  │ If school_grade filter                           │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────────┐                    │
│  │  class-mld-bmn-schools-integration.php      │                    │
│  │  ───────────────────────────────────────────│                    │
│  │  10. Query bmn_school_rankings              │                    │
│  │  11. Get schools with grade A (2mi radius)  │                    │
│  │  12. Filter properties near those schools   │                    │
│  └───────────────┬─────────────────────────────┘                    │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  Database Query                 │                                │
│  │  ───────────────────────────────│                                │
│  │  SELECT * FROM bme_listing_     │                                │
│  │  summary WHERE city = 'Boston'  │                                │
│  │  AND beds >= 3                  │                                │
│  │  LIMIT 20                       │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  JSON Response Builder          │                                │
│  │  ───────────────────────────────│                                │
│  │  13. Format property data       │                                │
│  │  14. Add photos from bme_media  │                                │
│  │  15. Add agent info             │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
└──────────────────┼──────────────────────────────────────────────────┘
                   │
                   │ JSON Response
                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  iOS App (continued)                                                │
│  ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│  ┌─────────────────────────────────┐                                │
│  │  Property.swift (Decodable)     │                                │
│  │  ───────────────────────────────│                                │
│  │  16. Decode JSON to [Property]  │                                │
│  │  17. Handle photo format        │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  PropertySearchViewModel        │                                │
│  │  ───────────────────────────────│                                │
│  │  18. Update @Published props    │                                │
│  │  19. properties = results       │                                │
│  │  20. totalCount = total         │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  SwiftUI Views                  │                                │
│  │  ───────────────────────────────│                                │
│  │  21. PropertyMapView updates    │                                │
│  │  22. PropertyListView updates   │                                │
│  │  23. Pin annotations render     │                                │
│  └─────────────────────────────────┘                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Property Search Flow (Web)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Browser                                                            │
│                                                                     │
│  User adjusts filter or moves map                                   │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────┐                                │
│  │  main.js                        │                                │
│  │  ───────────────────────────────│                                │
│  │  1. Collect filter values       │                                │
│  │  2. Build AJAX request          │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
└──────────────────┼──────────────────────────────────────────────────┘
                   │
                   │ AJAX Request
                   │ POST admin-ajax.php?action=get_map_listings
                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  WordPress Server                                                   │
│                                                                     │
│  ┌─────────────────────────────────┐                                │
│  │  class-mld-query.php            │  ◄── Different from iOS!      │
│  │  ───────────────────────────────│                                │
│  │  3. Parse POST parameters       │                                │
│  │  4. get_listings_for_map_       │                                │
│  │     optimized()                 │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  Database Query                 │                                │
│  │  ───────────────────────────────│                                │
│  │  5. Query bme_listing_summary   │                                │
│  │  6. Apply school filter (post)  │  ◄── Post-query filtering!    │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
└──────────────────┼──────────────────────────────────────────────────┘
                   │
                   │ JSON Response
                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Browser (continued)                                                │
│                                                                     │
│  ┌─────────────────────────────────┐                                │
│  │  main.js                        │                                │
│  │  ───────────────────────────────│                                │
│  │  7. Parse JSON response         │                                │
│  │  8. Update Leaflet map markers  │                                │
│  │  9. Update property list        │                                │
│  └─────────────────────────────────┘                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Critical Note:** iOS and Web use DIFFERENT code paths!

---

## Authentication Flow (iOS)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Login Flow                                                         │
│                                                                     │
│  User enters email/password                                         │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────┐                                │
│  │  AuthViewModel.swift            │                                │
│  │  login(email, password)         │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────┐                                │
│  │  APIClient.swift                │                                │
│  │  POST /auth/login               │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│         ┌───────┴────────┐                                          │
│         ▼                ▼                                          │
│     Success           Failure                                       │
│         │                │                                          │
│         ▼                ▼                                          │
│  ┌─────────────┐  ┌─────────────┐                                   │
│  │ TokenManager│  │ Show Error  │                                   │
│  │ saveTokens()│  │             │                                   │
│  └──────┬──────┘  └─────────────┘                                   │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────┐                                │
│  │  Keychain Storage               │                                │
│  │  ───────────────────────────────│                                │
│  │  - Access token (15 min TTL)    │                                │
│  │  - Refresh token (7 day TTL)    │                                │
│  └─────────────────────────────────┘                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Token Refresh Flow (Automatic)                                     │
│                                                                     │
│  API request made with expired token                                │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────┐                                │
│  │  TokenManager.swift             │                                │
│  │  refreshTokenIfNeeded()         │                                │
│  │  ───────────────────────────────│                                │
│  │  Check: isAccessTokenExpired?   │                                │
│  │  Check: hasValidRefreshToken?   │                                │
│  └───────────────┬─────────────────┘                                │
│                  │                                                  │
│         ┌───────┴────────┐                                          │
│         ▼                ▼                                          │
│   Token Valid      Token Expired                                    │
│         │                │                                          │
│         │                ▼                                          │
│         │         ┌─────────────────────────────────┐               │
│         │         │  POST /auth/refresh             │               │
│         │         │  Body: { refresh_token: "..." } │               │
│         │         └───────────────┬─────────────────┘               │
│         │                         │                                 │
│         │                ┌────────┴────────┐                        │
│         │                ▼                 ▼                        │
│         │           Success            Failure                      │
│         │                │                 │                        │
│         │                ▼                 ▼                        │
│         │         Save new tokens    Clear tokens                   │
│         │                │           Redirect login                 │
│         │                │                                          │
│         └────────────────┴──────────────────                        │
│                          │                                          │
│                          ▼                                          │
│               Continue with API request                             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Appointment Booking Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  iOS App                                 │  Web Widget              │
│  ─────────────────────────────           │  ───────────────         │
│                                          │                          │
│  AppointmentViewModel                    │  booking-widget.js       │
│         │                                │         │                │
│         ▼                                │         ▼                │
│  POST /snab/v1/appointments              │  AJAX snab_book_         │
│         │                                │  appointment             │
│         │                                │         │                │
└─────────┼────────────────────────────────┼─────────┼────────────────┘
          │                                │         │
          │     DIFFERENT CODE PATHS!      │         │
          ▼                                │         ▼
┌─────────────────────────────┐            │  ┌─────────────────────────┐
│  class-snab-rest-api.php    │            │  │  class-snab-frontend-   │
│  create_appointment()       │            │  │  ajax.php               │
│                             │            │  │  handle_booking()       │
└───────────────┬─────────────┘            │  └───────────────┬─────────┘
                │                          │                  │
                └──────────────────────────┼──────────────────┘
                                           │
                                           ▼
                    ┌─────────────────────────────────────────┐
                    │  Shared Booking Logic                   │
                    │  ─────────────────────────────────────  │
                    │                                         │
                    │  1. Validate availability               │
                    │  2. Create appointment record           │
                    │  3. Sync to Google Calendar             │
                    │  4. Send confirmation email             │
                    └───────────────┬─────────────────────────┘
                                    │
                                    ▼
                    ┌─────────────────────────────────────────┐
                    │  class-snab-google-calendar.php         │
                    │  create_staff_event($staff_id, $data)   │
                    │  ─────────────────────────────────────  │
                    │                                         │
                    │  • Get staff's Google access token      │
                    │  • Create event in their calendar       │
                    │  • Store google_event_id                │
                    └─────────────────────────────────────────┘
                                    │
                                    ▼
                    ┌─────────────────────────────────────────┐
                    │  Database: wp_snab_appointments         │
                    │  ─────────────────────────────────────  │
                    │                                         │
                    │  id, type_id, staff_id, start_time,     │
                    │  end_time, booking_email, google_        │
                    │  event_id, status                       │
                    └─────────────────────────────────────────┘
```

---

## School Filter Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  Property Search with school_grade=A                                │
│                                                                     │
│  Request: GET /properties?school_grade=A&city=Boston                │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────────────┐                        │
│  │  class-mld-mobile-rest-api.php          │                        │
│  │  ───────────────────────────────────────│                        │
│  │  Detect school_grade parameter          │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  class-mld-bmn-schools-integration.php  │                        │
│  │  filter_properties_by_school_criteria() │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Step 1: Get latest year               │                        │
│  │  ────────────────────────────────────── │                        │
│  │                                         │                        │
│  │  SELECT MAX(year) FROM                  │                        │
│  │  wp_bmn_school_rankings                 │                        │
│  │                                         │                        │
│  │  Result: 2025  ◄── NOT date('Y')!       │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Step 2: Get A-grade schools            │                        │
│  │  ───────────────────────────────────────│                        │
│  │                                         │                        │
│  │  SELECT s.id, s.latitude, s.longitude   │                        │
│  │  FROM wp_bmn_schools s                  │                        │
│  │  JOIN wp_bmn_school_rankings r          │                        │
│  │    ON s.id = r.school_id                │                        │
│  │  WHERE r.letter_grade = 'A'             │                        │
│  │    AND r.year = 2025                    │                        │
│  │                                         │                        │
│  │  Result: ~500 schools with coordinates  │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Step 3: Filter properties by distance  │                        │
│  │  ───────────────────────────────────────│                        │
│  │                                         │                        │
│  │  For each property in results:          │                        │
│  │    Check if any A-grade school is       │                        │
│  │    within 2 miles (Haversine formula)   │                        │
│  │                                         │                        │
│  │  Keep only properties near A schools    │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Return filtered properties             │                        │
│  │  With school_grade field added          │                        │
│  └─────────────────────────────────────────┘                        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Saved Search Sync Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  Save Search (iOS)                                                  │
│                                                                     │
│  User taps "Save This Search"                                       │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────────────┐                        │
│  │  PropertySearchViewModel.swift          │                        │
│  │  saveCurrentSearch()                    │                        │
│  │  ───────────────────────────────────────│                        │
│  │  1. Convert filters to dictionary       │                        │
│  │  2. Use web-compatible keys (v110+)     │                        │
│  │     city → city (not City)              │                        │
│  │     min_price → min_price               │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  POST /mld-mobile/v1/saved-searches     │                        │
│  │  ───────────────────────────────────────│                        │
│  │  {                                      │                        │
│  │    "name": "My Search",                 │                        │
│  │    "filters": {                         │                        │
│  │      "city": ["Boston"],                │                        │
│  │      "beds": 3,                         │                        │
│  │      "min_price": 500000                │                        │
│  │    }                                    │                        │
│  │  }                                      │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Database: wp_mld_saved_searches        │                        │
│  │  ───────────────────────────────────────│                        │
│  │  - user_id                              │                        │
│  │  - name                                 │                        │
│  │  - filters (JSON)                       │                        │
│  │  - created_at                           │                        │
│  └─────────────────────────────────────────┘                        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Apply Saved Search (iOS)                                           │
│                                                                     │
│  User selects saved search from list                                │
│         │                                                           │
│         ▼                                                           │
│  ┌─────────────────────────────────────────┐                        │
│  │  PropertySearchViewModel.swift          │                        │
│  │  applySavedSearch(search)               │                        │
│  │  ───────────────────────────────────────│                        │
│  │  1. Parse filters JSON                  │                        │
│  │  2. Handle both iOS and web key formats │                        │
│  │     (city and City both work)           │                        │
│  │  3. Set filters on ViewModel            │                        │
│  │  4. Trigger search()                    │                        │
│  └───────────────┬─────────────────────────┘                        │
│                  │                                                  │
│                  ▼                                                  │
│  ┌─────────────────────────────────────────┐                        │
│  │  Post notification                      │                        │
│  │  .switchToSearchTab                     │                        │
│  │  ───────────────────────────────────────│                        │
│  │  MainTabView observes and switches tab  │                        │
│  └─────────────────────────────────────────┘                        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Summary Table Performance

```
┌─────────────────────────────────────────────────────────────────────┐
│  CORRECT: Using Summary Table (~200ms)                              │
│                                                                     │
│  SELECT * FROM bme_listing_summary                                  │
│  WHERE city = 'Boston'                                              │
│    AND beds >= 3                                                    │
│    AND standard_status = 'Active'                                   │
│  LIMIT 20                                                           │
│                                                                     │
│  • Pre-joined data (no JOINs needed)                                │
│  • ~7,400 rows for active listings                                  │
│  • Indexed for common queries                                       │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  WRONG: Using Normalized Tables (4-5 seconds)                       │
│                                                                     │
│  SELECT l.*, d.*, loc.*                                             │
│  FROM bme_listings l                                                │
│  LEFT JOIN bme_listing_details d                                    │
│    ON l.listing_id = d.listing_id                                   │
│  LEFT JOIN bme_listing_location loc                                 │
│    ON l.listing_id = loc.listing_id                                 │
│  WHERE loc.city = 'Boston'                                          │
│    AND d.beds >= 3                                                  │
│                                                                     │
│  • Multiple JOINs across large tables                               │
│  • 25x slower than summary table                                    │
│  • Especially bad for archive (~90K rows)                           │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Rule:** Always use `bme_listing_summary` for list queries!
