# Incident Response Runbook

Quick reference for diagnosing and resolving common production issues.

---

## Quick Reference

| Symptom | Likely Cause | Jump To |
|---------|--------------|---------|
| API returns 500 | PHP fatal error | [API Down](#1-api-endpoint-returns-500-error) |
| API returns 403 | Nonce expired or permissions | [403 Errors](#2-api-returns-403-forbidden) |
| Page loads but content blank | CSS/JS permissions | [Permission Issues](#3-page-loads-but-content-is-blankblack) |
| Properties not loading | Database or CDN | [Database Issues](#4-properties-not-loading) |
| Push notifications not arriving | APNs config | [Push Issues](#5-push-notifications-not-delivering) |
| User can't log in | Rate limiting or auth | [Login Issues](#6-user-cannot-log-in) |
| Wrong user data shown | CDN caching auth endpoints | [Identity Issues](#7-wrong-user-data-displayed) |
| iOS app crashes | Swift decode failure | [iOS Crashes](#8-ios-app-crashes-on-launch) |
| School filter returns 0 | Year rollover bug | [School Filter](#9-school-filter-returns-0-results) |

---

## 1. API Endpoint Returns 500 Error

### Symptoms
- Curl returns HTTP 500
- iOS app shows "Failed to load"
- Web pages show white screen

### Diagnosis

```bash
# Check server error log (last 50 lines)
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "tail -50 ~/logs/error.log"

# Check WordPress debug log
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "tail -50 ~/public/wp-content/debug.log"
```

### Common Causes & Fixes

**Missing callback method (v6.74.1 bug):**
```
Fatal error: Call to undefined method MLD_Email_Validator::validate_user_email_on_create
```
Fix: Remove or implement the missing method. See CLAUDE.md #44.

**PHP syntax error after deployment:**
```bash
# Validate PHP syntax locally first
php -l wordpress/wp-content/plugins/PLUGIN/file.php

# If deployed, rollback:
./shared/scripts/rollback.sh PLUGIN --last
```

**Database connection lost:**
```bash
# Test database connectivity
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db check"
```

---

## 2. API Returns 403 Forbidden

### Symptoms
- API calls return 403
- "Cookie check failed" in response
- Works after page refresh but fails again later

### Diagnosis

```bash
# Test with fresh request (no cache)
curl -I "https://bmnboston.com/wp-json/mld-mobile/v1/properties?_nocache=$(date +%s)"
```

### Common Causes & Fixes

**Nonce on public endpoint (CLAUDE.md #11):**
- Public endpoints should NOT send `X-WP-Nonce` header
- Remove nonce from JavaScript fetch calls for public endpoints

**JWT/Session conflict (CLAUDE.md #20):**
- JWT must take priority over WordPress session
- Check `class-mld-mobile-rest-api.php` auth order

**Expired nonce on authenticated endpoint:**
- Normal behavior - user needs to refresh page
- For long-lived sessions, increase nonce lifetime

---

## 3. Page Loads But Content is Blank/Black

### Symptoms
- HTML loads (can see in View Source)
- Main content area is black or empty
- Navigation may work, gallery doesn't

### Diagnosis

```bash
# Check for permission errors
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "tail -50 ~/logs/error.log | grep -i permission"

# Check CSS file permissions
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "ls -la ~/public/wp-content/plugins/mls-listings-display/assets/css/"
```

### Fix

```bash
# Fix CSS/JS permissions on both servers
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "find ~/public/wp-content/plugins -name '*.css' -exec chmod 644 {} \; && \
   find ~/public/wp-content/plugins -name '*.js' -exec chmod 644 {} \;"

sshpass -p 'nxGDPBDdpeuh2Io' ssh -p 50594 stevenovakrealestate@35.236.219.140 \
  "find ~/public/wp-content/plugins -name '*.css' -exec chmod 644 {} \; && \
   find ~/public/wp-content/plugins -name '*.js' -exec chmod 644 {} \;"
```

Also check for orphan modal overlay (CLAUDE.md #42).

---

## 4. Properties Not Loading

### Symptoms
- Property list returns empty
- API returns `{"total": 0, "properties": []}`
- Worked before, suddenly stopped

### Diagnosis

```bash
# Test API directly
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" | python3 -m json.tool

# Check database has data
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'SELECT COUNT(*) FROM wp_bme_listing_summary WHERE standard_status = \"Active\"'"

# Check if OPcache is stale
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "touch ~/public/wp-content/plugins/mls-listings-display/*.php"
```

### Common Causes & Fixes

**CDN caching old response:**
```bash
# Test with cache bypass
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1&_nocache=$(date +%s)"
```
If this works, purge Kinsta CDN cache in dashboard.

**Summary table out of sync:**
```bash
# Check active vs summary counts
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'SELECT COUNT(*) as listings FROM wp_bme_listings WHERE StandardStatus = \"Active\"; \
   SELECT COUNT(*) as summary FROM wp_bme_listing_summary WHERE standard_status = \"Active\"'"
```

**Status filter mismatch (CLAUDE.md #27):**
- Check if status filter is using wrong values
- "Pending" should map to both "Pending" AND "Active Under Contract"

---

## 5. Push Notifications Not Delivering

### Symptoms
- User doesn't receive push notifications
- Notifications logged as "sent" but not received
- Works for some users but not others

### Diagnosis

```bash
# Check APNs environment setting
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp option get mld_apns_environment"

# Check recent notification logs
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'SELECT id, user_id, status, error_message, created_at FROM wp_mld_push_notifications ORDER BY id DESC LIMIT 20'"
```

### Common Causes & Fixes

**Sandbox vs Production APNs (CLAUDE.md #22):**
- TestFlight/App Store builds use PRODUCTION APNs
- Debug builds use SANDBOX APNs
```bash
# Ensure production setting for TestFlight users
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp option update mld_apns_environment production"
```

**BadDeviceToken errors:**
- User's device token is stale
- Have user log out and back in to refresh token

**Rate limiting:**
- APNs may throttle if too many notifications sent quickly
- Check for notification batching issues

---

## 6. User Cannot Log In

### Symptoms
- "Too many login attempts" error
- "Invalid credentials" when credentials are correct
- Works in web but not iOS (or vice versa)

### Diagnosis

```bash
# Check rate limiting status
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'SELECT * FROM wp_options WHERE option_name LIKE \"%mld_auth_login%\"'"

# Check user lockout
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp user meta get USER_ID bme_failed_login_attempts; \
   wp user meta get USER_ID bme_lockout_time"
```

### Fix - Clear Rate Limits

```bash
# Clear MLD rate limiting (transients)
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'DELETE FROM wp_options WHERE option_name LIKE \"%mld_auth_login%\"'"

# Clear BME brute force protection (user meta)
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp user meta delete USER_ID bme_failed_login_attempts; \
   wp user meta delete USER_ID bme_lockout_time"
```

See CLAUDE.md #18 for rate limit configuration.

---

## 7. Wrong User Data Displayed

### Symptoms
- User sees another user's data
- `/me` endpoint returns wrong user
- Happens after app restart

### Diagnosis

Check CDN cache headers:
```bash
curl -I "https://bmnboston.com/wp-json/mld-mobile/v1/auth/me" \
  -H "Authorization: Bearer TOKEN"
```

Look for: `X-Kinsta-Cache: HIT` (bad) vs `BYPASS` (good)

### Causes & Fixes

**CDN caching authenticated endpoints (CLAUDE.md #21):**
```php
// Add to authenticated endpoint responses:
$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
$response->header('X-Kinsta-Cache', 'BYPASS');
```

**JWT not taking priority over session (CLAUDE.md #20):**
- Ensure JWT auth runs before WordPress session check
- Review `check_auth()` in `class-mld-mobile-rest-api.php`

**Token refresh not saving user data (CLAUDE.md #16):**
- iOS must save `authData.user` after token refresh

---

## 8. iOS App Crashes on Launch

### Symptoms
- App crashes immediately or during data load
- Crash log shows decode error
- Worked before, broke after server update

### Diagnosis

Test API response structure:
```bash
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/ENDPOINT" | python3 -m json.tool
```

Compare with iOS model expectations.

### Common Causes (CLAUDE.md #9)

**Missing required field:**
- API returns null for field iOS expects as non-optional Int
- Fix: Make iOS field optional (`Int?`) or ensure API always returns value

**Wrong wrapper structure:**
- API returns `{data: {...}}` but iOS expects `{data: {client: {...}}}`
- Fix: Match exact structure iOS model expects

**Silent array decode failure:**
- If ANY item in array fails to decode, entire array returns empty
- Add `init(from decoder:)` with defaults for robust parsing

---

## 9. School Filter Returns 0 Results

### Symptoms
- `?school_grade=A` returns 0 properties
- Was working yesterday, broken today
- Happens around January 1st

### Diagnosis

```bash
# Check what year is in the database
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "wp db query 'SELECT DISTINCT year FROM wp_bmn_school_rankings ORDER BY year DESC LIMIT 3'"

# Test school filter
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | \
  python3 -c "import sys,json; print(json.load(sys.stdin).get('total', 0))"
```

### Fix - Year Rollover Bug (CLAUDE.md #2)

The code is using `date('Y')` instead of getting the latest year from data:

```php
// WRONG
$year = date('Y');

// CORRECT
$year = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");
```

Check these files:
- `class-mld-mobile-rest-api.php`
- `class-mld-query.php`
- `class-mld-bmn-schools-integration.php`

---

## 10. Rollback Procedure

If a deployment caused issues:

```bash
# View recent commits for the component
./shared/scripts/rollback.sh mls-listings-display

# Rollback to previous deployment
./shared/scripts/rollback.sh mls-listings-display --last

# Rollback to specific commit
./shared/scripts/rollback.sh mls-listings-display abc1234
```

### After Rollback

1. Verify API is responding: `curl https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1`
2. Test school filter: returns 1000+
3. Check error logs cleared
4. Notify team of rollback
5. Create ticket to investigate root cause

---

## Emergency Contacts

| Issue | Contact | Method |
|-------|---------|--------|
| Server down | Kinsta Support | Dashboard chat |
| Database issues | Kinsta Support | Dashboard chat |
| iOS App Store | Apple Developer | developer.apple.com |
| APNs issues | Apple Developer | developer.apple.com |

---

## Post-Incident Checklist

After resolving any incident:

- [ ] Root cause identified and documented
- [ ] Fix deployed and verified
- [ ] Add to CLAUDE.md if new pitfall discovered
- [ ] Update this runbook if needed
- [ ] Consider adding automated test/monitor

---

*Last updated: 2026-02-03*
