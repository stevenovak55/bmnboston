# MLS Listings Display Plugin

Property search, REST API, and site features plugin.

## Quick Info

| Setting | Value |
|---------|-------|
| Version | 6.68.18 |
| API Namespace | `/wp-json/mld-mobile/v1` |
| Main File | `mls-listings-display.php` |
| Location | `wordpress/wp-content/plugins/mls-listings-display/` |

## Key Files

| File | Purpose |
|------|---------|
| `includes/class-mld-mobile-rest-api.php` | iOS REST API endpoints |
| `includes/class-mld-query.php` | Web AJAX query builder |
| `includes/class-mld-bmn-schools-integration.php` | School filter integration |
| `includes/class-mld-sitemap-generator.php` | XML sitemap generation |
| `includes/class-mld-bme-data-provider.php` | Bridge MLS data interface |
| `includes/class-mld-recently-viewed-tracker.php` | Web property view tracking |
| `admin/class-mld-recently-viewed-admin.php` | Recently viewed admin dashboard |
| `includes/class-mld-app-store-banner.php` | iOS App Store promotion (mobile/desktop) |

## Documentation

- [API Responses](api-responses.md) - Mobile REST API response formats
- [Filters](filters.md) - All supported filter parameters
- [Database](database.md) - Summary tables and query patterns
- [Main CLAUDE.md](../../../CLAUDE.md) - Critical pitfalls and version history

## Critical Rules

### 1. Update BOTH Code Paths

iOS and web use different code paths. When adding features:
- **iOS**: Update `class-mld-mobile-rest-api.php`
- **Web**: Update `class-mld-query.php`

See [Code Paths](../../architecture/code-paths.md).

### 2. Use Summary Tables

Always use `bme_listing_summary` for list queries. See [Database](database.md).

### 3. Version Updates

Update ALL 3 locations:
1. `version.json`
2. `mls-listings-display.php` header
3. `MLD_VERSION` constant

## Recently Viewed Properties (v6.57.0)

Tracks property views from both iOS app and web, with admin dashboard.

### Tracking Paths
- **iOS**: Calls `POST /recently-viewed` when viewing property details
- **Web**: Template hook `do_action('mld_property_viewed', $listing_id)` fires on property pages

### Admin Dashboard
- **Location**: MLS Listings → Recently Viewed
- **Features**:
  - Recent Views tab: All views with user/IP, location, property, price, platform
  - Most Viewed tab: Properties ranked by view count
  - Date range filter (1/3/7/14/30 days)
  - IP geolocation for anonymous visitors (via ip-api.com, cached 24h)

### Database Table
```sql
wp_mld_recently_viewed_properties
- id, user_id, listing_id, listing_key
- viewed_at, view_source, platform
- ip_address (for anonymous visitors)
```

## iOS App Store Banner (v6.61.0+)

Promotes the BMN Boston iOS app across all pages. Uses client-side JavaScript detection to work with full-page caching (Kinsta CDN).

**App Store URL:** `https://apps.apple.com/us/app/bmn-boston/id6745724401`

### Components

| Component | Location | Visibility |
|-----------|----------|------------|
| Mobile Banner | Fixed top banner | iOS devices only |
| Desktop Footer | Footer section | Desktop browsers only |
| Hero Promo | Homepage hero section | Desktop browsers only |

### Key Files

| File | Purpose |
|------|---------|
| `includes/class-mld-app-store-banner.php` | Mobile banner + Desktop footer (plugin) |
| `themes/.../template-parts/homepage/section-hero.php` | Hero section app promo (theme) |

### How It Works

All components are rendered to HTML but hidden by default with CSS (`display: none`). JavaScript on page load checks `navigator.userAgent` to determine device type and shows the appropriate element:

```javascript
// Client-side detection pattern (cache-compatible)
var ua = navigator.userAgent;
var isIOS = /iPhone|iPad|iPod/.test(ua);
var isAndroid = /Android/.test(ua);

if (isIOS && !dismissed) {
    banner.classList.add('mld-banner-visible');    // Show mobile banner
} else if (!isIOS && !isAndroid) {
    footer.classList.add('mld-footer-visible');    // Show desktop footer
    heroPromo.classList.add('bne-promo-visible');  // Show hero promo
}
```

### Mobile Banner Features
- Fixed position at top of page
- Dismissible with 30-day cookie (`mld_app_banner_dismissed`)
- Skips iOS app WebView users (checks for `BMNBoston` in user agent)
- Pushes page content down using `margin-top` on `<html>`

### Desktop Footer Features
- QR code linking to App Store (via qrserver.com API)
- "Get the BMN Boston App" headline
- Dark gradient background
- App Store badge with hover effect

### Hero Section Promo Features
- Compact inline design below CTA buttons
- Small QR code (60x60px)
- "Get the iOS App" label
- App Store badge
- Light rounded card styling

### Cache Compatibility

**Why client-side detection?** Kinsta CDN caches entire page HTML. Server-side PHP user-agent detection would cache the desktop version and serve it to mobile users (or vice versa). Client-side JavaScript runs after page load and shows the correct element for each device type.

## Quick API Tests

```bash
# Properties
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# With filters
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Boston&beds=3&per_page=1"

# Autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston"

# School filter
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Record recently viewed (authenticated)
curl -X POST "https://bmnboston.com/wp-json/mld-mobile/v1/recently-viewed" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"listing_id":"73464868","view_source":"search","platform":"ios"}'
```

## Deployment

```bash
# Upload file
scp -P 57105 includes/file.php \
    stevenovakcom@35.236.219.140:~/public/wp-content/plugins/mls-listings-display/includes/

# Invalidate opcache
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "touch ~/public/wp-content/plugins/mls-listings-display/includes/*.php"
```
