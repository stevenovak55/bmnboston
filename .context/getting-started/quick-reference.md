# Quick Reference

Common commands and patterns for daily development.

## API Testing

```bash
# Property search
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# Property detail
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties/LISTING_KEY_HASH"

# Autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston"

# School filters
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Schools near property
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2"

# Glossary term
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/?term=mcas"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

## Authentication

```bash
# Login and get token
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@bmnboston.com","password":"demo1234"}' | \
  python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Use token
curl "https://bmnboston.com/wp-json/snab/v1/appointments" \
  -H "Authorization: Bearer $TOKEN"
```

## Git Workflow

```bash
# View recent commits
git log --oneline -20

# Create feature branch
git checkout -b feature/my-feature

# Commit with proper message
git commit -m "Add feature X to Y

- Detail 1
- Detail 2

Generated with Claude Code"
```

## Version Bumping

### iOS App

Update `CURRENT_PROJECT_VERSION` in `project.pbxproj` (6 occurrences):
```bash
# Use replace_all in Claude Code to update all at once
CURRENT_PROJECT_VERSION = N+1;
```

### WordPress Plugins

Update ALL THREE locations:
1. `version.json` - version field and last_updated date
2. `plugin-name.php` - Header comment `Version: X.Y.Z`
3. `plugin-name.php` - Constant `define('PLUGIN_VERSION', 'X.Y.Z');`

## Database Queries

```bash
# MySQL CLI access (Docker)
docker compose exec db mysql -u wordpress -pwordpress wordpress

# Check table exists
SHOW TABLES LIKE 'wp_bmn_%';

# Describe table
DESCRIBE wp_bme_listing_summary;

# Count records
SELECT COUNT(*) FROM wp_bme_listing_summary WHERE standard_status = 'Active';
```

## Log Viewing

```bash
# WordPress debug log (Docker)
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log

# iOS console logs
xcrun devicectl device process launch --console-pty \
    --device 00008140-00161D3A362A801C com.bmnboston.realestate
```

## Key File Locations

| Purpose | Path |
|---------|------|
| iOS Entry Point | `ios/BMNBoston/App/BMNBostonApp.swift` |
| iOS API Client | `ios/BMNBoston/Core/Networking/APIClient.swift` |
| iOS Search ViewModel | `ios/BMNBoston/Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` |
| Mobile REST API | `wordpress/.../mls-listings-display/includes/class-mld-mobile-rest-api.php` |
| Web Query Builder | `wordpress/.../mls-listings-display/includes/class-mld-query.php` |
| Schools REST API | `wordpress/.../bmn-schools/includes/class-rest-api.php` |
| Appointments REST API | `wordpress/.../sn-appointment-booking/includes/class-snab-rest-api.php` |

## Common Filters

| Filter | Parameter | Example |
|--------|-----------|---------|
| City | `city` | `?city=Boston` |
| Price | `min_price`, `max_price` | `?min_price=500000` |
| Beds/Baths | `beds`, `baths` | `?beds=3&baths=2` |
| Status | `status` | `?status=Active` or `?status=Sold` |
| School Grade | `school_grade` | `?school_grade=A` |
| Map Bounds | `bounds` | `?bounds=42.2,-71.2,42.4,-71.0` |
| Price Reduced | `price_reduced` | `?price_reduced=true` |
| New Listings | `new_listing_days` | `?new_listing_days=7` |
