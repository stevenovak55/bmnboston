# Troubleshooting Guide

Comprehensive guide for investigating and resolving issues across iOS and WordPress platforms.

---

## General Approach

1. **Identify the platform** - iOS app or web?
2. **Identify the code path** - REST API or AJAX?
3. **Check logs** - WordPress debug, PHP errors, iOS console
4. **Test API directly** - curl commands
5. **Trace the code** - Follow the request through

---

## Checking Logs

### WordPress Debug Log

Enable in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

View on production:
```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -100 ~/public/wp-content/debug.log"
```

### PHP Error Log

```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -100 /tmp/php_errors.log"
```

### iOS Console Logs

```bash
xcrun devicectl device process launch --console-pty \
    --device 00008140-00161D3A362A801C \
    com.bmnboston.realestate
```

---

## Common Issues

### iOS Issues

#### "Profile has not been explicitly trusted"

**Cause:** Development certificate not trusted on device.

**Fix:** iPhone Settings > General > VPN & Device Management > Trust certificate

---

#### Map search not refreshing

**Cause:** API doesn't support lat/lng/radius.

**Fix:** Use `bounds` parameter only:
```swift
filters.mapBounds = bounds
filters.latitude = nil
filters.longitude = nil
```

---

#### Only 1 photo showing

**Cause:** Query using `listing_key` instead of `listing_id` for media table.

**Fix:** Use `$listing->listing_id` for media queries.

---

#### PropertyDetail decode failing

**Cause:** CodingKeys don't match API response.

**Fix:** Verify field mappings:
| API | Model | CodingKey |
|-----|-------|-----------|
| `status` | `standardStatus` | `case standardStatus = "status"` |
| `agent` | `listingAgent` | `case listingAgent = "agent"` |

---

#### Filter toggles do nothing (UI never updates)

**Cause:** Task self-cancellation bug.

**Fix:** See [iOS Troubleshooting](../platforms/ios/troubleshooting.md#task-self-cancellation-bug).

---

### WordPress Issues

#### Dashboard page blank (header + footer, no content)

**Cause:** Dashboard asset files (`mld-client-dashboard.js`, `mld-client-dashboard.css`) have 600 permissions after SCP upload. The web server can't read them, Vue.js never initializes, and `v-cloak` hides the entire template.

**Symptoms:**
- User is logged in (name visible in header) but dashboard content is blank
- Only header and footer visible, no tabs/data between them
- No visible error message on the page

**Diagnosis:**
```bash
# Check file permissions
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "ls -la ~/public/wp-content/plugins/mls-listings-display/assets/js/dashboard/ && ls -la ~/public/wp-content/plugins/mls-listings-display/assets/css/dashboard/"

# Check if files return 403
curl -sI "https://bmnboston.com/wp-content/plugins/mls-listings-display/assets/js/dashboard/mld-client-dashboard.js" | head -1
```

**Fix:**
```bash
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "chmod 644 ~/public/wp-content/plugins/mls-listings-display/assets/js/dashboard/*.js ~/public/wp-content/plugins/mls-listings-display/assets/css/dashboard/*.css"
```

**Key files:** `assets/js/dashboard/vue.global.prod.js`, `assets/js/dashboard/mld-client-dashboard.js`, `assets/css/dashboard/mld-client-dashboard.css`

**Related:** Pitfall #45 in CLAUDE.md (File Permissions After Deployment)

---

#### CSS changes not appearing

**Cause:** Kinsta CDN caches with 1-year max-age.

**Fix:**
1. Bump `MLD_VERSION` constant
2. Verify asset enqueued with version
3. Use DevTools "Disable cache"

---

#### PHP changes not reflecting

**Cause:** OPcache not invalidated.

**Fix:**
```bash
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "touch ~/public/wp-content/plugins/PLUGIN/includes/*.php"
```

---

#### API returns 403 for CSS/JS files

**Cause:** File permissions set to 600 after SCP upload.

**Fix:**
```bash
chmod 644 ~/public/wp-content/plugins/*/assets/css/*.css
chmod 644 ~/public/wp-content/plugins/*/assets/js/*.js
```

---

### Database Issues

#### Queries taking 4-5 seconds

**Cause:** Using multi-table JOINs instead of summary tables.

**Fix:** Use `bme_listing_summary` or `bme_listing_summary_archive`.

See [Database Guide](../plugins/mls-listings-display/database.md).

---

#### Photos query returns empty

**Cause:** Using `listing_key` instead of `listing_id`.

**Fix:**
```php
// Get listing by hash
$listing = $wpdb->get_row("... WHERE listing_key = %s", $hash);

// Query photos by listing_id (MLS number)
$photos = $wpdb->get_col("... WHERE listing_id = %s", $listing->listing_id);
```

---

### API Issues

#### School filters returning 0 results

**Cause:** Year rollover bug (using `date('Y')` for rankings).

**Fix:** See [Year Rollover Bug](year-rollover-bug.md).

**Quick Test:**
```bash
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"
# Expected: ~1,600 results, NOT 0
```

---

#### Feature works on iOS but not web

**Cause:** iOS and web use different code paths.

**Fix:** Update BOTH files:
- iOS: `class-mld-mobile-rest-api.php`
- Web: `class-mld-query.php`

See [Code Paths](../architecture/code-paths.md).

---

#### Appointment not syncing to Google Calendar

**Cause:** Using global connection instead of per-staff.

**Fix:**
```php
// CORRECT
$google->is_staff_connected($staff_id);
$google->create_staff_event($staff_id, $data);
```

See [Booking Flow](../plugins/sn-appointment-booking/booking-flow.md).

---

#### Google Calendar "Bad Request" error

**Cause:** DateTime missing seconds.

**Fix:**
```php
// Include seconds
$start = $date . 'T' . $time . ':00';  // "2026-01-15T14:00:00"
```

---

#### Saved search from iOS not working on web

**Cause:** Different filter key formats.

**Fix:** Handle both formats:
```javascript
const cities = filters.City || filters.city;
const minPrice = filters.price_min || filters.min_price;
```

---

### Deployment Issues

#### Changes deployed but not appearing

**Checklist:**
1. File actually uploaded? Check on server.
2. Correct permissions (644)?
3. Version bumped?
4. OPcache invalidated (touch files)?
5. CDN cache (for CSS/JS)?

---

#### Docker containers won't start

**Fix:**
```bash
docker compose down -v
docker compose up -d
```

---

## API Testing

### Property Endpoints

```bash
# Basic test
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# With filters
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?city=Boston&beds=3&per_page=1"

# School filter (critical - test after year rollover)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"
```

### Authenticated Endpoints

```bash
# Get token
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@bmnboston.com","password":"demo1234"}' | \
  python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Use token
curl "https://bmnboston.com/wp-json/snab/v1/appointments" \
    -H "Authorization: Bearer $TOKEN"
```

### Verbose Output

```bash
curl -v "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" 2>&1 | head -50
```

---

## Database Debugging

### Connect to MySQL

```bash
# Docker (local)
docker compose exec db mysql -u wordpress -pwordpress wordpress

# Production (via SSH)
ssh -p 57105 stevenovakcom@35.236.219.140
mysql -u wordpress -p'PASSWORD' wordpress
```

### Common Queries

```sql
-- Check property counts
SELECT standard_status, COUNT(*) FROM wp_bme_listing_summary GROUP BY standard_status;

-- Check school rankings
SELECT year, COUNT(*) FROM wp_bmn_school_rankings GROUP BY year;

-- Check recent appointments
SELECT * FROM wp_snab_appointments ORDER BY created_at DESC LIMIT 10;
```

---

## Tracing Code Paths

### Property Search (iOS)

1. `PropertySearchViewModel.search()`
2. `APIClient.request(.properties(filters))`
3. WordPress: `/wp-json/mld-mobile/v1/properties`
4. `class-mld-mobile-rest-api.php` → `get_active_properties()`
5. `bme_listing_summary` table query
6. If school filters: `apply_school_filter()`
7. JSON response returned

### Property Search (Web)

1. JavaScript: `$.ajax({ action: 'get_map_listings' })`
2. WordPress: AJAX handler
3. `class-mld-query.php` → `get_listings_for_map_optimized()`
4. `bme_listing_summary` table query
5. If school filters: `apply_school_filter()`
6. JSON response returned

### Appointment Booking (iOS)

1. `AppointmentViewModel.book()`
2. `APIClient.request(.createAppointment(...))`
3. WordPress: `/wp-json/snab/v1/appointments`
4. `class-snab-rest-api.php` → `create_appointment()`
5. Insert into `snab_appointments`
6. Google Calendar sync (if connected)
7. Send confirmation email

---

## Common Debug Points

### Filter Not Working

1. Is parameter being read?
   ```php
   error_log("my_param: " . print_r($request->get_param('my_param'), true));
   ```

2. Is WHERE clause being added?
   ```php
   error_log("SQL: " . $sql);
   ```

3. Is it in BOTH code paths?
   - Check `class-mld-mobile-rest-api.php`
   - Check `class-mld-query.php`

### Database Query Issues

1. Print the query:
   ```php
   error_log("Query: " . $wpdb->last_query);
   ```

2. Check for errors:
   ```php
   error_log("Error: " . $wpdb->last_error);
   ```

3. Verify table exists:
   ```sql
   SHOW TABLES LIKE 'wp_bme_%';
   ```

### Google Calendar Sync

1. Check if staff connected:
   ```php
   error_log("Staff connected: " . ($google->is_staff_connected($staff_id) ? 'yes' : 'no'));
   ```

2. Check event creation:
   ```php
   error_log("Event ID: " . $event_id);
   error_log("Calendar error: " . print_r($google->get_last_error(), true));
   ```

---

## Production Debugging

### Add Temporary Logging

```php
// Add to file
file_put_contents('/tmp/debug.log', date('Y-m-d H:i:s') . " - Debug info\n", FILE_APPEND);

// Check on server
ssh -p 57105 stevenovakcom@35.236.219.140 "cat /tmp/debug.log"
```

### Quick File Check

```bash
# Verify file content
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "grep 'SEARCH_TERM' ~/public/wp-content/plugins/PLUGIN/file.php"
```

---

## Quick Diagnostic Commands

```bash
# Check if file deployed correctly
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "grep 'EXPECTED_CONTENT' ~/public/wp-content/plugins/PLUGIN/file.php"

# Check file permissions
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "ls -la ~/public/wp-content/plugins/PLUGIN/file.php"

# Check WordPress debug log
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -50 ~/public/wp-content/debug.log"

# Check PHP error log
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "tail -50 /tmp/php_errors.log"
```

---

## When to Escalate

- Database corruption suspected
- Performance degradation across all users
- Security concerns
- Third-party API failures (Bridge, Google)

---

## Related Documentation

- [Year Rollover Bug](year-rollover-bug.md) - Critical deep-dive
- [Critical Pitfalls](../cross-cutting/critical-pitfalls.md) - Common bugs to avoid
- [Code Paths](../architecture/code-paths.md) - iOS vs Web paths
- [iOS Troubleshooting](../platforms/ios/troubleshooting.md) - iOS-specific issues
