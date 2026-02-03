# Feature Parity Matrix

Cross-platform feature comparison: iOS App vs Web.

**Last Updated:** January 14, 2026

---

## Property Search Features

| Feature | iOS | Web | Synced | Notes |
|---------|-----|-----|--------|-------|
| **Map Display** | v1+ | Yes | - | iOS: MKMapView; Web: Leaflet |
| **List View** | v1+ | Yes | - | Both support sorting |
| **City Filter** | v1+ | Yes | - | Single or multiple cities |
| **ZIP Filter** | v1+ | Yes | - | Single or multiple ZIPs |
| **Neighborhood Filter** | v1+ | Yes | - | - |
| **Price Filter** | v1+ | Yes | - | min_price, max_price |
| **Beds/Baths Filter** | v1+ | Yes | - | - |
| **Property Type Filter** | v1+ | Yes | - | Residential, Condo, Multi-Family |
| **Status Filter** | v1+ | Yes | - | Active, Pending, Sold |
| **Map Bounds Search** | v1+ | Yes | - | bounds=south,west,north,east |
| **Autocomplete** | v1+ | Yes | - | Different endpoints, same data |
| **Address Filter** | v6.27.17+ | v6.27.17+ | - | Exact address match |
| **MLS Number Filter** | v6.27.17+ | v6.27.17+ | - | Exact MLS match |
| **Street Name Filter** | v6.27.17+ | v6.27.17+ | - | Partial match |
| **Sqft Filter** | v1+ | Yes | - | sqft_min, sqft_max |
| **Lot Size Filter** | v1+ | Yes | - | lot_size_min, lot_size_max |
| **Year Built Filter** | v1+ | Yes | - | year_built_min, year_built_max |
| **Garage Filter** | v1+ | Yes | - | garage_spaces_min |
| **Days on Market** | v1+ | Yes | - | max_dom |
| **New Listings** | v1+ | Yes | - | new_listing_days |
| **Price Reduced** | v1+ | Yes | - | price_reduced=true |
| **Open Houses** | v1+ | Yes | - | open_house_only=true |
| **Sort Options** | v1+ | Yes | - | price, date, beds, sqft |
| **Polygon Draw Search** | v98+ | No | - | **iOS only** - gesture-based |
| **Quick Filter Presets** | v43+ | No | - | **iOS only** - UI pattern |
| **Heatmap Toggle** | v43+ | No | - | **iOS only** - price overlay |
| **City Boundary Display** | v127+ | No | - | **iOS only** - GeoJSON polygons |

---

## Amenity Filters

| Feature | iOS | Web | Notes |
|---------|-----|-----|-------|
| Pool | v1+ | v6.30.21+ | - |
| Waterfront | v1+ | v6.30.21+ | - |
| Fireplace | v1+ | v6.30.21+ | - |
| Garage | v1+ | v6.30.21+ | - |
| AC/Central Air | v1+ | v6.30.21+ | - |
| Basement | v1+ | v6.30.21+ | - |

---

## School Integration

| Feature | iOS | Web | Notes |
|---------|-----|-----|-------|
| **Nearby Schools Display** | v58+ | v6.29.0+ | Property detail view |
| **School Letter Grades** | v60+ | v6.30.6+ | A-F color badges |
| **School Grade Filter** | v79+ | v6.30.10+ | Filter by A/B/C schools |
| **Near Top Elementary** | v79+ | v6.30.10+ | near_top_elementary=true |
| **Near Top High School** | v79+ | v6.30.10+ | near_top_high=true |
| **District Grades** | v79+ | v6.30.6+ | District-level ratings |
| **MCAS Scores** | v67+ | v6.28.0+ | Test score display |
| **School Demographics** | v85+ | v6.29.0+ | Enrollment, diversity |
| **School Highlights** | v60+ | v6.30.15+ | Programs, AP courses |
| **Glossary Terms** | v85+ | v6.30.15+ | Education terminology |
| **College Outcomes** | v91+ | v6.30.14+ | Where graduates go |
| **School Safety Data** | v94+ | v6.30.14+ | Discipline rates |
| **MIAA Sports** | v96+ | v6.30.15+ | High school sports |

**Critical Note:** School filters use post-query filtering on web, direct WHERE on iOS.

---

## Saved Searches

| Feature | iOS | Web | Synced | Notes |
|---------|-----|-----|--------|-------|
| **Create Saved Search** | v105+ | Yes | Yes | Server-synced |
| **Apply Saved Search** | v106+ | Yes | Yes | - |
| **Delete Saved Search** | v105+ | Yes | Yes | - |
| **List Saved Searches** | v105+ | Yes | Yes | Profile tab |
| **Filter Summary Display** | v108+ | Yes | - | Shows active filters |

**Cross-Platform Note:** iOS v110+ uses web-compatible keys. Older iOS versions used snake_case; web uses PascalCase.

---

## Favorites

| Feature | iOS | Web | Synced | Notes |
|---------|-----|-----|--------|-------|
| **Add Favorite** | v1+ | Yes | Yes | Requires auth |
| **Remove Favorite** | v1+ | Yes | Yes | - |
| **View Favorites** | v1+ | Yes | Yes | - |
| **Favorite Count** | v1+ | Yes | - | Badge display |

---

## Authentication

| Feature | iOS | Web | Notes |
|---------|-----|-----|-------|
| **Login** | v1+ | Yes | JWT tokens on iOS; session on web |
| **Register** | v1+ | Yes | Unified signup at `/signup` (v6.62.0) |
| **Logout** | v1+ | Yes | - |
| **Token Refresh** | v1+ | N/A | iOS only (JWT) |
| **Guest Mode** | v1+ | Yes | Browse without auth |
| **Password Reset** | v1+ | Yes | Email-based |

### Registration Field Parity (v6.62.0 / iOS v277)

| Field | iOS | Web | Saved |
|-------|-----|-----|-------|
| First Name | Required | Required | Yes |
| Last Name | Optional | Optional | Yes |
| Email | Required | Required | Yes |
| Phone | Optional | Optional | Yes |
| Password | Required | Required | Yes |
| Referral Code | Optional | Optional | Yes (assigns agent) |

**Web Signup Page:** `/signup` - works with or without `?ref=CODE` parameter.

**iOS Deep Link:** Referral code from deep link pre-fills registration form.

---

## Appointments

| Feature | iOS | Web | Notes |
|---------|-----|-----|-------|
| **View Appointments** | v118+ | Yes | Different code paths! |
| **Book Appointment** | v118+ | Yes | Different code paths! |
| **Cancel Appointment** | v118+ | Yes | - |
| **Reschedule** | v118+ | Yes | - |
| **Get Availability** | v118+ | Yes | - |
| **Google Calendar Sync** | v118+ | Yes | Per-staff connection |
| **ICS Download** | v118+ | Yes | Calendar file |
| **Push Notifications** | v118+ | No | **iOS only** - APNs |

**Critical:** iOS uses `class-snab-rest-api.php`, Web uses `class-snab-frontend-ajax.php`. Update BOTH when fixing bugs!

---

## UI/UX Features

| Feature | iOS | Web | Notes |
|---------|-----|-----|-------|
| **Dark Mode** | v128+ | No | **iOS only** - iOS 16+ |
| **Pull to Refresh** | v1+ | No | Mobile pattern |
| **Infinite Scroll** | v1+ | Yes | Pagination |
| **Photo Gallery** | v1+ | Yes | Swipe on iOS |
| **Share Property** | v1+ | Yes | Native share sheet |
| **Contact Agent** | v1+ | Yes | - |
| **Directions** | v1+ | Yes | Opens Maps app |

---

## Web-Only Features

| Feature | Notes |
|---------|-------|
| **CMA (Comparative Market Analysis)** | Backend-heavy analysis |
| **Property Comparison Tool** | Multi-property selection |
| **Market Reports** | Aggregate statistics |
| **Instant Notifications** | Background email alerts |
| **Admin Dashboard** | Plugin administration |
| **School Research Pages** | /schools/{district}/{school} |
| **City Landing Pages** | /homes-for-sale/{city}/ |
| **XML Sitemaps** | SEO infrastructure |
| **Blog/Content Pages** | WordPress pages |

---

## API Version Compatibility

| Feature | Min iOS Version | Min API Version | Notes |
|---------|-----------------|-----------------|-------|
| Polygon Draw Search | v98 | v6.30.24 | - |
| Address Filter | Any | v6.27.17 | - |
| School Filters | v79 | v6.30.10 | Year rollover fixed v6.31.8 |
| Amenity Filters | Any | v6.30.21 | - |
| Saved Search Sync | v110 | v6.31+ | Key format compatibility |

---

## When to Implement on Both Platforms

### Always Both Platforms
- Core search filters (price, beds, baths, location)
- Saved searches (must sync)
- Favorites (must sync)
- Authentication
- Property details

### Mobile-Only Justified
- Polygon draw (requires gesture handling)
- Heatmap overlay (performance concerns on web)
- Push notifications (APNs)
- Dark mode (native feature)
- City boundary display (mobile map UX)

### Web-Only Justified
- Multi-page reports
- Admin dashboards
- Complex analytics
- SEO pages (sitemaps, landing pages)
- Blog/CMS content
