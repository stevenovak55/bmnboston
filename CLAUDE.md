# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**Version Reference:** See [.context/VERSIONS.md](.context/VERSIONS.md) for current component versions.

## MANDATORY: Read Before Coding

Read [.context/AGENT_PROTOCOL.md](.context/AGENT_PROTOCOL.md) before any code changes. Covers:
- Documentation updates required with every change
- Version number updates (all locations)
- Dual code path rules (iOS/Web)
- **Git commit discipline (Rule 15)**

Full docs: [.context/](.context/)

---

## CRITICAL: Git Commit Rules (Prevents Lost Work)

**Bug fixes have been lost because changes weren't committed. Follow these rules:**

### Commit Triggers (MANDATORY)

| When | Action |
|------|--------|
| User says "update documentation" | **COMMIT IMMEDIATELY** |
| User says "close session" or "wrap up" | **COMMIT IMMEDIATELY** |
| After fixing ANY bug | **COMMIT IMMEDIATELY** |
| After modifying 5+ files | **COMMIT IMMEDIATELY** |
| Before ending YOUR turn | Check `git status`, commit if changes exist |

### Quick Commit Commands

```bash
# Check status
git status --short

# Commit all changes
git add -A && git commit -m "Fix: [description]" && git push origin main

# If push fails (auth), at least changes are committed locally
```

### Session-End Protocol

When user requests "update docs" or signals session end:

1. **Run `git status`** - Check for uncommitted changes
2. **Commit ALL changes** - Use descriptive message with session summary
3. **Attempt push** - May require user authentication
4. **Verify clean** - `git status` should show "nothing to commit"

**NEVER end a session with uncommitted changes. Uncommitted code WILL be lost.**

See [.context/AGENT_PROTOCOL.md Rule 15](.context/AGENT_PROTOCOL.md#rule-15-git-commit-discipline-critical) for full details.

---

## 45 Critical Pitfalls

### 1. Dual Code Paths - iOS vs Web

| Feature | iOS Path | Web Path |
|---------|----------|----------|
| Property Search | `class-mld-mobile-rest-api.php` | `class-mld-query.php` |
| Appointments | `class-snab-rest-api.php` | `class-snab-frontend-ajax.php` |
| Saved Searches | REST API endpoint | AJAX handler |
| Favorites | REST API endpoint | AJAX handler |
| Authentication | JWT tokens | WordPress nonces |

**When adding features or fixing bugs, update BOTH paths.**

### 2. Year Rollover Bug (CRITICAL)
```php
// WRONG - breaks Jan 1 when data is from prior year
$year = date('Y');

// CORRECT - get latest year from data
$year = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");
```

**Acceptable `date('Y')` uses (NOT bugs):**
- Copyright footers in emails/PDFs (display-only)
- Blog topic search queries (`class-mld-topic-researcher.php`) - intentionally uses current year
- New construction detection (`template-facts-features-v2.php`) - fallback; explicit flag takes priority
- UI placeholders (admin form max/placeholder values)

### 3. Task Self-Cancellation (iOS Swift)
Never cancel `searchTask` from within an async function called by that task. The caller handles cancellation BEFORE creating a new task.

### 4. Summary Tables for Performance
Use `bme_listing_summary` for list queries (25x faster than JOINs):
```php
// Fast (~200ms)
"SELECT * FROM bme_listing_summary WHERE standard_status = 'Active'"
// Slow (4-5s)
"SELECT * FROM bme_listings l LEFT JOIN bme_listing_details d ON ..."
```

### 5. CDN Caching
Bump `MLD_VERSION` when changing CSS/JS - Kinsta CDN has 1-year cache.

### 6. Google Calendar Per-Staff Connection
Two types of connections exist:
- **Global**: `wp_options: snab_google_refresh_token` (Admin dashboard)
- **Per-Staff**: `wp_snab_staff.google_refresh_token` (Booking sync)

```php
// CORRECT - use per-staff methods
$google->is_staff_connected($staff_id);
$google->create_staff_event($staff_id, $data);

// WRONG - these use global connection
$google->is_connected();
$google->create_event($data);
```

### 7. Table Identifier Mismatch
Cross-table queries require correct identifier:
- `bme_listing_summary` uses `listing_key` (MD5 hash) for API lookups
- Other tables (`bme_media`, `bme_listing_details`) use `listing_id` (MLS number)

```php
// Get listing by hash from API
$listing = $wpdb->get_row("SELECT * FROM bme_listing_summary WHERE listing_key = %s", $hash);

// Use listing_id (NOT listing_key) for related tables
$photos = $wpdb->get_col("SELECT media_url FROM bme_media WHERE listing_id = %s", $listing->listing_id);
```

### 8. Property URLs Use listing_id (RECURRING BUG)
Property detail page URLs must use `listing_id` (MLS number), NOT `listing_key` (hash):

```php
// CORRECT - property pages use MLS number
$url = home_url('/property/' . $listing->listing_id . '/');
// https://bmnboston.com/property/73464868/

// WRONG - hash doesn't work for URLs!
$url = home_url('/property/' . $listing->listing_key . '/');
// https://bmnboston.com/property/928c77fa6877d5c35c852989c83e5068/ (BROKEN!)
```

**When building API responses, ALWAYS include `listing_id` for URL construction.**

### 9. iOS API Response Format Mismatch (RECURRING BUG)

iOS shows "Failed to parse response" or **silently returns empty arrays** when the API returns data in a different format than the Swift model expects.

**Common Causes:**
1. **Missing wrapper object**: iOS expects `{data: {client: {...}}}` but API returns `{data: {...}}`
2. **Missing required fields**: iOS model has non-optional Int but API returns null
3. **Different key names**: iOS expects `first_name` but API returns `firstName`
4. **Silent array decode failure**: When decoding `[Item]`, if ANY item fails to decode, the ENTIRE array returns empty (no error thrown!)

**Silent Decode Failure Example (v361 Fix):**
```swift
// Model has required fields not in API response
struct OpenHouseAttendee: Codable {
    let openHouseId: Int      // REQUIRED - but API doesn't return this!
    let syncStatus: SyncStatus // REQUIRED - but API doesn't return this!
    let signedInAt: Date      // Date type - but API returns "2026-01-24 20:05:31" string
}

// When decoding [OpenHouseAttendee], if openHouseId is missing,
// the entire attendees array silently becomes [] with no error!

// FIX: Add custom init(from decoder:) with defaults:
init(from decoder: Decoder) throws {
    openHouseId = (try? container.decode(Int.self, forKey: .openHouseId)) ?? 0
    syncStatus = (try? container.decode(SyncStatus.self, forKey: .syncStatus)) ?? .synced
    // Parse date string manually...
}
```

**Prevention Protocol for NEW API Endpoints:**

1. **Test with curl FIRST** - Before writing ANY iOS code:
   ```bash
   curl -X POST "https://bmnboston.com/wp-json/mld-mobile/v1/new-endpoint" \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"test":"data"}' | python3 -m json.tool
   ```

2. **Match existing patterns** - Check how similar endpoints format responses:
   - List endpoints return `{data: {items: [...], count: N}}`
   - Create endpoints return `{data: {item: {...}, message: "..."}}`
   - Always include ALL fields the iOS model expects (use 0/null for new items)

3. **iOS model checklist**:
   - All Int fields that could be null → use `Int?`
   - All Date fields → use `Date?` (API may return null)
   - CodingKeys for snake_case → camelCase mapping

**Example Fix (Create Client endpoint):**
```php
// WRONG - missing fields, no wrapper
return array('data' => array('id' => 1, 'email' => '...'));

// CORRECT - matches iOS AgentClient model with wrapper
return array('data' => array(
    'client' => array(
        'id' => 1,
        'email' => '...',
        'searches_count' => 0,     // Required Int in iOS
        'favorites_count' => 0,    // Required Int in iOS
        'hidden_count' => 0,       // Required Int in iOS
        'last_activity' => null,   // Optional Date
        'assigned_at' => $now,     // Optional Date
    ),
    'message' => 'Success'
));
```

### 10. WordPress Timezone vs PHP Timezone (CRITICAL)

**WordPress is configured to `America/New_York`.** Never use raw PHP time functions.

```php
// WRONG - uses server timezone (often UTC, 5 hours off)
$now = date('Y-m-d H:i:s');
$timestamp = time();

// CORRECT - uses WordPress configured timezone
$now = current_time('mysql');
$timestamp = current_time('timestamp');
$datetime = new DateTime('now', wp_timezone());
```

**Quick Reference:**
| Need | Wrong | Correct |
|------|-------|---------|
| Current datetime | `date('Y-m-d H:i:s')` | `current_time('mysql')` |
| Current timestamp | `time()` | `current_time('timestamp')` |
| DateTime object | `new DateTime()` | `new DateTime('now', wp_timezone())` |
| Format a date | `date('Y-m-d', $ts)` | `wp_date('Y-m-d', $ts)` |
| **Display stored time** | `strtotime($row->date)` | `(new DateTime($row->date, wp_timezone()))->getTimestamp()` |

**CRITICAL - Displaying Stored Timestamps:**
```php
// WRONG - strtotime assumes UTC, causes 5-hour error!
echo wp_date('M j, Y g:i A', strtotime($row->created_at));

// CORRECT - Tell DateTime the string is in WP timezone
$date = new DateTime($row->created_at, wp_timezone());
echo wp_date('M j, Y g:i A', $date->getTimestamp());
```

**Why this matters:** Server runs UTC but WordPress is America/New_York (UTC-5). Using `time()` or `strtotime()` instead of `current_time()` causes 5-hour mismatches.

**CRITICAL - Never combine `current_time('timestamp')` with `wp_date()`:**
```php
// WRONG - double timezone conversion! Shifts queries ~5 hours (v1.10.2 bug)
$now = current_time('timestamp');   // Already offset by -5h
$in_1h = $now + HOUR_IN_SECONDS;
wp_date('Y-m-d H:i:s', $in_1h);    // Applies -5h AGAIN! → 10 hours off!

// CORRECT - time() is real UTC, wp_date() converts to local once
$now = time();
$in_1h = $now + HOUR_IN_SECONDS;
wp_date('Y-m-d H:i:s', $in_1h);    // Single conversion: UTC → EST ✓

// ALSO CORRECT - wp_schedule_event() expects UTC timestamp
wp_schedule_event(time(), 'hourly', 'my_hook');        // ✓ UTC
wp_schedule_event(current_time('timestamp'), ...);     // ✗ Wrong!
```

**Rule of thumb:** `wp_date()` and `wp_schedule_event()` expect real UTC timestamps (`time()`). Never pass `current_time('timestamp')` to them.

**Full timezone guide (PHP, JavaScript, iOS):** See [.context/AGENT_PROTOCOL.md Rule 13](.context/AGENT_PROTOCOL.md#rule-13-timezone-consistency)

### 11. Don't Send Nonces on Public REST Endpoints (NEW)

Public REST API endpoints (with `permission_callback => '__return_true'`) should NOT send WordPress nonces in JavaScript requests.

```javascript
// WRONG - nonce causes 403 when expired, even on public endpoints
fetch(publicEndpoint, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce  // DON'T DO THIS!
    }
})

// CORRECT - no nonce needed for public endpoints
fetch(publicEndpoint, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    }
})
```

**Why this matters:** WordPress validates the nonce whenever the `X-WP-Nonce` header is present, regardless of the endpoint's permission callback. When the nonce expires (after ~24 hours), requests fail with `403 Cookie check failed` - even though the endpoint is public and doesn't require authentication.

**Symptoms:** Intermittent 403 errors that work after page refresh (new nonce) but fail after browser tab is open for hours.

### 12. Null Coalescing for Unrated Schools (NEW)

Private schools and schools without MCAS data showed "F" grade instead of "N/A" due to incorrect null coalescing.

```php
// WRONG - ?? 0 passes 0 for unrated schools → shows "F" grade!
'letter_grade' => bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? 0),

// CORRECT - ?? null allows function to return "N/A"
'letter_grade' => bmn_get_letter_grade_from_percentile($ranking->percentile_rank ?? null),
```

**Why this matters:** Private schools (Phillips Academy, etc.) don't have MCAS rankings. Displaying "F" is misleading. The `bmn_get_letter_grade_from_percentile()` function returns "N/A" for `null` but "F" for `0`.

### 13. Score vs Percentile for Letter Grades (NEW)

District average grades showed wrong letter grades (B+ instead of A+) because raw composite scores were passed to `bmn_get_letter_grade_from_percentile()`.

```php
// WRONG - treats 64.9 as 64.9th percentile → "B+"
bmn_get_letter_grade_from_percentile($elementary_avg)

// CORRECT - calculates actual percentile for score → "A+"
bmn_get_letter_grade_from_score($elementary_avg, 'elementary')
```

**Why this matters:** A score of 64.9 is in the 97th percentile among elementary schools (should be A+), but passing it directly returns B+ because 64.9 < 70.

### 14. Push Notification Payload Type Mismatch (iOS)

When parsing push notification payloads in iOS, numeric values may come as either Int or String depending on how the server sends them.

```swift
// WRONG - fails silently if server sends integer
listingId = userInfo["listing_id"] as? String  // Returns nil for Int value

// CORRECT - handle both types
private static func extractListingId(from userInfo: [AnyHashable: Any]) -> String? {
    if let stringId = userInfo["listing_id"] as? String {
        return stringId
    } else if let intId = userInfo["listing_id"] as? Int {
        return String(intId)
    }
    return nil
}
```

**Why this matters:** Push notification payloads from WordPress/PHP may send `listing_id: 73464868` (Int) but iOS code expecting `as? String` silently returns nil. This causes deep links from notifications to fail without any error.

### 15. iOS Notification Parsing Order (NEW)

When iOS parses push notification payloads, the order of field checks matters. Property notifications include BOTH `saved_search_id` (context) AND `listing_id` (deep link target).

```swift
// WRONG - checks saved_search_id first, never extracts listingId
if let searchId = userInfo["saved_search_id"] as? Int {
    type = .savedSearch  // Wrong type!
    // listingId not extracted
} else if let notificationType = userInfo["notification_type"] as? String {
    // This branch never reached if saved_search_id exists
}

// CORRECT - check notification_type first for property-specific types
if let notificationType = userInfo["notification_type"] as? String {
    switch notificationType {
    case "new_listing", "price_change", "status_change":
        type = .newListing  // or appropriate type
        listingId = extractListingId(from: userInfo)  // Extract for navigation
        savedSearchId = userInfo["saved_search_id"] as? Int  // Also capture context
    default:
        break
    }
}
// Only fall back to saved_search_id check if notification_type didn't match
if type == .general {
    if let searchId = userInfo["saved_search_id"] as? Int {
        type = .savedSearch
    }
}
```

**Why this matters:** Property alert notifications include `saved_search_id` for context (which search triggered it) but the deep link target is `listing_id`. If iOS parses as `.savedSearch` first, it won't extract `listingId` and tap navigation fails silently.

### 16. Token Refresh Must Save User Data (NEW)

When iOS refreshes JWT tokens, it must also save the user data from the response. Otherwise, stale user data persists and causes identity confusion.

```swift
// WRONG - only saves tokens, user data becomes stale
private func refreshToken() async throws {
    let authResponse = try await requestWithoutAuth(...)
    await TokenManager.shared.saveTokens(
        accessToken: authData.accessToken,
        refreshToken: authData.refreshToken
    )
    // Missing: authData.user.save()
}

// CORRECT - save user data too
private func refreshToken() async throws {
    let authResponse = try await requestWithoutAuth(...)
    await TokenManager.shared.saveTokens(
        accessToken: authData.accessToken,
        refreshToken: authData.refreshToken
    )
    authData.user.save()  // CRITICAL: Keep user data in sync
}
```

**Why this matters:** User logged in as UserA, closed app. On reopen, token refresh returned UserA's data but wasn't saved. Stale UserB data from a previous login appeared. User saw wrong identity.

### 17. Failed Push Notifications Still Have Value (NEW)

When building notification history endpoints, include notifications with `status = 'failed'`, not just `status = 'sent'`.

```php
// WRONG - user sees 0 notifications when all failed
$where = "status = 'sent'";

// CORRECT - show all attempted notifications
$where = "status IN ('sent', 'failed')";
```

**Why this matters:** Push can fail for many reasons (BadDeviceToken, network, rate limits) but the notification record is still valuable for in-app notification center. User can see what they "missed" even if push didn't arrive.

### 18. Multiple Rate Limiting Systems May Exist (NEW)

WordPress sites may have multiple overlapping rate limiting mechanisms. When debugging "too many attempts" errors, check ALL of them:

```bash
# MLD Plugin rate limiting (transients) - 20 attempts, 5 min lockout
DELETE FROM wp_options WHERE option_name LIKE '%mld_auth_login%';

# BME Plugin brute force protection (user meta) - 20 attempts, 5 min lockout
wp user meta delete USER_ID bme_failed_login_attempts
wp user meta delete USER_ID bme_lockout_time

# Plugin-specific tables
SELECT * FROM wp_mld_login_attempts WHERE user_id = X;
```

**Current Rate Limit Configuration (v6.50.8):**
| Plugin | Max Attempts | Lockout Duration | Window |
|--------|--------------|------------------|--------|
| MLD | 20 | 5 minutes | 15 minutes |
| BME | 20 | 5 minutes | N/A |

**Why this matters:** Clearing one rate limiter doesn't help if another is still blocking. Check MLD, BME, and any security plugins. iOS app token refresh failures can accumulate quickly and trigger lockout - the relaxed limits (20 attempts) prevent this.

### 19. Local-Only Operations Need Server Sync (NEW)

Operations that only modify local state will be undone when the app syncs with the server. Design for server as source of truth.

```swift
// WRONG - local only, undone on next sync
func clearAll() {
    notifications.removeAll()  // Server will restore them on sync
    saveNotifications()
}

// CORRECT - sync to server, then clear locally
func clearAll() async {
    notifications.removeAll()  // Optimistic update
    try await APIClient.shared.request(.dismissAllNotifications)
    // If server fails, consider reverting local state
}
```

**Why this matters:** User cleared notifications, but they reappeared after app restart because `clearAll()` only cleared local UserDefaults. Server still had the notifications and sync restored them.

### 20. JWT Must Take Priority Over WordPress Session Cookies (NEW)

When the WordPress REST API receives both a JWT token AND a WordPress session cookie, the JWT should ALWAYS take priority.

```php
// WRONG - WordPress session overrides JWT
public static function check_auth($request) {
    // If WordPress session exists, JWT is ignored!
    if (is_user_logged_in()) {
        return true;  // Returns session user, not JWT user
    }
    // JWT only processed if no session
    $token = $request->get_header('Authorization');
    // ...
}

// CORRECT - JWT always takes priority when present
public static function check_auth($request) {
    $auth_header = $request->get_header('Authorization');
    $has_jwt = !empty($auth_header) && strpos($auth_header, 'Bearer ') === 0;

    // If JWT is present, ALWAYS use JWT (ignore cookies)
    if ($has_jwt) {
        $payload = self::verify_jwt(substr($auth_header, 7));
        wp_set_current_user($payload['sub']);
        return true;
    }

    // Only use WordPress session if NO JWT present
    if (is_user_logged_in()) {
        return true;
    }
    return new WP_Error('no_auth', 'Authorization required');
}
```

**Why this matters:** User A logs into web, creating WordPress session cookie. Later, user B logs into iOS app, getting JWT for user B. iOS sends both the JWT AND any cookies the device may have cached. If WordPress session is checked first, `/me` returns user A instead of user B. User sees wrong identity after app restart.

### 21. CDN Caching of Authenticated Endpoints (NEW)

Kinsta CDN (and other CDNs) may cache REST API responses even for authenticated endpoints, causing user A to receive user B's data.

```php
// WRONG - CDN caches user-specific response
public function handle_get_me($request) {
    $user = wp_get_current_user();
    return new WP_REST_Response(['user' => $user->data]);
}

// CORRECT - Add cache bypass headers for user-specific endpoints
public function handle_get_me($request) {
    $user = wp_get_current_user();
    $response = new WP_REST_Response(['user' => $user->data]);

    // Prevent CDN from caching user-specific response
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $response->header('Pragma', 'no-cache');
    $response->header('X-Kinsta-Cache', 'BYPASS');

    return $response;
}
```

**iOS side - cache-busting parameter:**
```swift
static var me: APIEndpoint {
    let timestamp = Int(Date().timeIntervalSince1970)
    return APIEndpoint(path: "/auth/me?_nocache=\(timestamp)", requiresAuth: true)
}
```

**Why this matters:** User A logged in and `/me` response was cached by CDN. User B logs in with different JWT, but CDN returns user A's cached response. User sees wrong identity. Server logs show `HIT KINSTAWP` for every request.

**Debugging tip:** Check Kinsta CDN logs for `HIT KINSTAWP` vs `BYPASS`:
```bash
tail -f ~/logs/kinsta-cache-perf.log | grep auth/me
```

### 22. APNs Sandbox vs Production for TestFlight (CRITICAL - iOS)

TestFlight builds use **production** APNs, NOT sandbox. The `sandboxReceipt` check is for StoreKit, not APNs.

```swift
// WRONG - sandboxReceipt is for StoreKit (in-app purchases), NOT APNs!
if let receiptURL = Bundle.main.appStoreReceiptURL {
    return receiptURL.lastPathComponent == "sandboxReceipt"  // True for TestFlight, but WRONG for APNs
}

// CORRECT - APNs environment follows provisioning profile
static func isAPNsSandbox() -> Bool {
    #if DEBUG
    return true   // Debug builds use development profile = sandbox APNs
    #else
    return false  // TestFlight/App Store use distribution profile = production APNs
    #endif
}
```

**Why this matters:**
- APNs environment is determined by the **provisioning profile's `aps-environment`** entitlement
- Development profile → `aps-environment = development` → sandbox APNs
- Distribution profile (TestFlight/App Store) → `aps-environment = production` → production APNs
- Device tokens are specific to the APNs environment - production tokens fail with `BadDeviceToken` on sandbox

**Server configuration:** Must use production APNs for TestFlight:
```bash
wp option update mld_apns_environment production
```

### 23. Push Notification Function Signature & Two Notification Systems (NEW)

**`send_activity_notification()` has a specific signature - don't pass arrays where strings are expected:**

```php
// Function signature:
send_activity_notification($user_id, $title, $body, $notification_type, $context)
//                         int       string  string  STRING            ARRAY

// WRONG - passing array as 4th arg
send_activity_notification(30, "Title", "Body", array("notification_type" => "test", "image_url" => "..."));
// Result: image_url ends up nested wrong, NotificationServiceExtension sees nil

// CORRECT - 4th arg is STRING, 5th arg is ARRAY
send_activity_notification(30, "Title", "Body", "appointment_reminder", array(
    "image_url" => "https://...",
    "appointment_id" => 27
));
```

**Two Separate Notification Systems - Both Need Correct Data:**

1. **Push Notification Banner** (APNs + NotificationServiceExtension)
   - Payload goes directly from server to APNs to device
   - `image_url` must be at root level for extension to find it
   - Extension downloads image and attaches to notification

2. **In-App Notification Center** (fetches from `/notifications/history` API)
   - iOS calls `NotificationStore.syncFromServer()` on app launch
   - Server must return ALL fields: `notification_type`, `listing_id`, `appointment_id`, etc.
   - Type mapping in `ServerNotification.toNotificationItem()` determines navigation
   - Missing fields = no deep linking even if push notification worked

**When adding new notification types:**
1. Add to `MLD_Push_Notifications::send_activity_notification()` context handling
2. Add to `/notifications/history` API response
3. Add case in iOS `ServerNotification.toNotificationItem()` switch statement
4. Add navigation handling in `NotificationCenterView.handleNotificationTap()`

### 24. Notification Deduplication - Per-Device Logging (NEW)

Push notifications are logged **per device token**, not per user. If a user has 2 devices, each notification creates 2 database entries. This is intentional for APNs delivery tracking but causes duplicate entries in user-facing notification history.

```php
// Each device token gets its own log entry
foreach ($tokens as $token_data) {
    $send_result = $instance->send_notification($token_data->device_token, ...);
    self::log_notification(...);  // Called N times for N devices
}
```

**Problem:** User with 2 devices × 3 rapid syncs = duplicates accumulate with each login. User sees 12+ identical notifications.

**Solution (v6.53.0):**

1. **Server-side history deduplication** - `/notifications/history` now groups by `(user_id, notification_type, listing_id, hour)`:
   ```php
   GROUP BY user_id, notification_type,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.listing_id')), title),
            DATE_FORMAT(created_at, '%Y-%m-%d %H')
   ```

2. **Send-side duplicate prevention** - `was_recently_sent()` check prevents sending same notification twice within 1 hour

3. **iOS sync throttling** - `NotificationStore` throttles syncs to 10-second minimum interval

**For user-facing history:** Deduplicate by (user, type, listing, hour)
**For APNs delivery tracking:** Keep per-device entries (valuable for debugging delivery issues)

### 25. Staff Access to Client-Booked Appointments (NEW)

Staff members must be able to view, cancel, and reschedule appointments that clients booked WITH them, not just their own bookings.

```php
// WRONG - only checks who booked the appointment
$appt = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM appointments WHERE id = %d AND user_id = %d",
    $id, $current_user_id
));
// Result: Staff can't see appointments clients booked with them!

// CORRECT - check if user booked OR is the assigned staff
$staff_id = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}snab_staff WHERE user_id = %d",
    $current_user_id
));

if ($staff_id) {
    $appt = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM appointments WHERE id = %d AND (user_id = %d OR staff_id = %d)",
        $id, $current_user_id, $staff_id
    ));
} else {
    // Non-staff users only see their own appointments
    $appt = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM appointments WHERE id = %d AND user_id = %d",
        $id, $current_user_id
    ));
}
```

**Staff should also bypass time restrictions:**
```php
// Determine if user is staff (staff can cancel without time restrictions)
$is_staff_cancelling = $staff_id && ($appt->staff_id == $staff_id);

// Staff can cancel anytime; clients must respect the cancellation deadline
if (!$is_staff_cancelling && $hours_until < $cancel_hours) {
    return new WP_REST_Response(array(
        'success' => false,
        'message' => 'Cancellation deadline passed'
    ), 400);
}

// Record who cancelled for audit trail
$cancelled_by = $is_staff_cancelling ? 'staff' : 'client';
```

**Endpoints that need this pattern (in `class-snab-rest-api.php`):**
1. `get_appointment()` - View appointment details
2. `cancel_appointment()` - Cancel with staff bypass
3. `reschedule_appointment()` - Reschedule with staff bypass
4. `get_reschedule_slots()` - Get available slots for rescheduling
5. `download_ics()` - Download calendar file

**Why this matters:** Client books appointment with staff member. Staff tries to cancel/reschedule → "This information is no longer available" because WHERE clause only checked `user_id`, not `staff_id`.

### 26. DATE_FORMAT Escaping in wpdb->prepare() (NEW)

When using MySQL `DATE_FORMAT()` in queries that go through `$wpdb->prepare()`, the `%` characters must be escaped as `%%`.

```php
// WRONG - % interpreted as placeholders, query returns empty/fails
$dedup_group_by = "DATE_FORMAT(created_at, '%Y-%m-%d %H')";
$result = $wpdb->get_var($wpdb->prepare($sql . $dedup_group_by, $params));
// Result: Empty string or 0 results!

// CORRECT - escape % as %% for MySQL DATE_FORMAT
$dedup_group_by = "DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H')";
$result = $wpdb->get_var($wpdb->prepare($sql . $dedup_group_by, $params));
```

**Why this matters:** WordPress `$wpdb->prepare()` interprets `%s`, `%d`, `%f` as placeholders. The `%Y`, `%m`, `%d`, `%H` in DATE_FORMAT get misinterpreted, causing the prepared statement to silently fail or return empty results.

**Affected patterns:**
- `DATE_FORMAT(col, '%Y-%m-%d')` → `DATE_FORMAT(col, '%%Y-%%m-%%d')`
- `DATE_FORMAT(col, '%H:%i:%s')` → `DATE_FORMAT(col, '%%H:%%i:%%s')`
- Any MySQL function using `%` format specifiers

**Files where this applies:**
- `class-mld-mobile-rest-api.php` - notification deduplication
- `class-mld-notification-ajax.php` - web notification queries
- Any query using DATE_FORMAT with prepare()

### 27. Property Status Filter Mapping (v6.62.2)

The database uses granular status values, but the UI uses simplified names. Status filters must map correctly AND use OR logic when multiple statuses are selected.

**Database status values:** `Active`, `Pending`, `Active Under Contract`, `Closed`, `Withdrawn`, `Expired`, `Canceled`

**UI/API status mappings:**
| UI/API Value | Database Values |
|--------------|-----------------|
| `Active` | `Active` |
| `Pending` | `Pending` OR `Active Under Contract` |
| `Under Agreement` | `Pending` OR `Active Under Contract` |
| `Sold` | `Closed` |

```php
// WRONG - puts unmapped statuses in $where (AND logic), mapped in $status_conditions (OR logic)
// Results in: WHERE status = 'Active' AND (status = 'Pending' OR status = 'Active Under Contract')
// This returns 0 results!
foreach ($statuses as $s) {
    if ($s === 'Pending') {
        $status_conditions[] = "status = 'Pending'";
        $status_conditions[] = "status = 'Active Under Contract'";
    } else {
        $where[] = "status = '$s'";  // WRONG - goes to main WHERE with AND
    }
}

// CORRECT - ALL status conditions go into same array with OR logic
foreach ($statuses as $s) {
    if ($s === 'Pending' || $s === 'Under Agreement') {
        $status_conditions[] = "status = 'Pending'";
        $status_conditions[] = "status = 'Active Under Contract'";
    } elseif ($s === 'Sold') {
        $status_conditions[] = "status = 'Closed'";
    } else {
        $status_conditions[] = $wpdb->prepare("status = %s", $s);  // CORRECT - same array
    }
}
$where[] = "(" . implode(' OR ', $status_conditions) . ")";
```

**Also handle comma-separated values:**
```php
// Handle array, comma-separated string, or single value
if (is_array($status)) {
    $statuses_to_check = $status;
} elseif (strpos($status, ',') !== false) {
    $statuses_to_check = array_map('trim', explode(',', $status));
} else {
    $statuses_to_check = [$status];
}
```

**Files with this logic (update BOTH for dual code paths):**
- `class-mld-mobile-rest-api.php` - iOS API (~line 2894)
- `class-mld-query.php` - Web queries (`build_summary_filter_conditions` ~line 1302, `build_where_conditions` ~line 2128)

**Why this matters:** User selects "Active" + "Pending" filters expecting to see all active and under-agreement listings. Without proper OR logic, they see 0 results because the query becomes impossible (status can't be both Active AND Pending simultaneously).

### 28. Exclusive Listing Property Sub-Type Mapping (NEW)

Exclusive listings (manually entered, `listing_id < 1,000,000`) must use MLS-compatible property sub-type values. The exclusive listings admin form uses simplified values that don't match MLS data.

**Value Mapping Required:**
| Exclusive Listing Value | MLS-Compatible Value |
|------------------------|---------------------|
| `Single Family` | `Single Family Residence` |
| `Condo` | `Condominium` |
| `Townhouse` | `Townhouse` |
| `Multi-Family` | `Multi Family` |
| `Apartment` | `Condominium` |

```php
// In class-el-bme-sync.php - map during BME sync
const PROPERTY_SUB_TYPE_MAP = array(
    'Single Family'  => 'Single Family Residence',
    'Condo'          => 'Condominium',
    'Townhouse'      => 'Townhouse',
    'Multi-Family'   => 'Multi Family',
    'Apartment'      => 'Condominium',
    // ... other mappings
);

public static function map_property_sub_type($sub_type) {
    if (empty($sub_type)) {
        return 'Single Family Residence';  // Default
    }
    return isset(self::PROPERTY_SUB_TYPE_MAP[$sub_type])
        ? self::PROPERTY_SUB_TYPE_MAP[$sub_type]
        : $sub_type;
}
```

**Why this matters:** iOS property filters use MLS values like "Single Family Residence". Exclusive listings with "Single Family" won't appear when users filter by property type, even though they should match.

**Files affected:**
- `exclusive-listings/includes/class-el-bme-sync.php` - Apply mapping in `sync_to_listings()` and `sync_to_summary()`
- `exclusive-listings/includes/class-el-validator.php` - Defines valid input values (keep as-is for admin UI)

### 29. Map Bounds Conflict with Address/MLS Search (iOS)

When iOS selects an autocomplete suggestion for an address, MLS number, or street name, the map bounds must be cleared. Otherwise, the API combines the exact-match filter with AND logic against the current map bounds, returning 0 results if the property is outside the visible area.

```swift
// WRONG - keeps map bounds, search fails if property outside current view
case .address:
    filters.address = suggestion.value
    // mapBounds still set from previous search!

// CORRECT - clear bounds for exact-match searches
case .address:
    filters.address = suggestion.value
    filters.mapBounds = nil  // Clear so API finds property anywhere
    mapBounds = nil

case .mlsNumber:
    filters.mlsNumber = suggestion.value
    filters.mapBounds = nil
    mapBounds = nil

case .streetName:
    filters.streetName = suggestion.value
    filters.mapBounds = nil
    mapBounds = nil
```

**Why this matters:** User is viewing Boston on map, searches for "58 Oak Street, Reading" via autocomplete. Reading is outside Boston bounds. Without clearing bounds, API returns: `WHERE address LIKE '%58 Oak%' AND (lat BETWEEN 42.2 AND 42.4) AND (lng BETWEEN -71.2 AND -70.9)` → 0 results because Reading coords don't match Boston bounds.

**File:** `ios/BMNBoston/Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` - `applySuggestion()` method

### 30. Duplicate Field Assignments in Large PHP Files (NEW)

In large API handler files, the same response field may be set in multiple places. Later assignments silently overwrite earlier correct values.

```php
// Line ~4078 - CORRECT assignment from $financial table
$result['association_fee_includes'] = self::format_array_field($financial->association_fee_includes ?? null);

// ... 100 lines later ...

// Line ~4171 - OVERWRITES with NULL from wrong table!
$result['association_fee_includes'] = self::format_array_field($details->association_fee_includes ?? null);
```

**Prevention:**
1. Search the entire file for the field name before adding/modifying
2. Add comments indicating the authoritative source: `// Set from $financial - do NOT overwrite`
3. When debugging "field is NULL", search for ALL assignments to that field

**Why this matters:** HOA fee data existed in `wp_bme_listing_financial` and was correctly read at line 4078, but line 4171 (in a different section) overwrote it with NULL from `wp_bme_listing_details`. The API returned NULL despite database having data.

**File:** `class-mld-mobile-rest-api.php` - `handle_get_property()` method

### 31. SwiftUI ViewBuilder Limitations (iOS)

SwiftUI's ViewBuilder has strict type-checking requirements. Complex logic inside ViewBuilder blocks can cause compiler crashes or runtime issues.

```swift
// WRONG - `let` statements cause ViewBuilder type-checking failures
if isExpanded {
    let hasData = property.hoaFee != nil || property.name != nil
    if !hasData {
        Text("No data available")
    }
}

// CORRECT - extract logic to helper function
private func hasDataToDisplay() -> Bool {
    guard let property = viewModel.property else { return false }
    return property.hoaFee != nil || property.name != nil
}

// In ViewBuilder:
if isExpanded {
    if !hasDataToDisplay() {
        Text("No data available")
    }
}
```

**Also avoid in ViewBuilder:**
- Complex boolean expressions with many OR conditions
- Nested ternary operators
- `switch` statements with complex associated values

**Why this matters:** Adding a `let` statement inside a collapsible section caused the app to crash when opening property details. The Swift compiler's ViewBuilder type inference couldn't handle the complexity.

### 32. BME Summary Table Archival Bug (v4.0.15 Fix)

The `class-bme-data-processor.php` file has multiple places that determine whether a listing should go to active or archive tables. These MUST stay in sync:

**The Four Locations That Were Out of Sync:**

1. **Line 54 - `$archived_statuses` array** - Class property defining archive statuses
2. **Line 655 - `process_listing_summary()`** - Was hardcoded to active table, ignoring `$table_suffix`
3. **Line 2168 - `is_listing_archived()`** - Had duplicate local array that was out of sync
4. **Line 2512+ - `move_related_data()`** - Didn't move summary table data

```php
// WRONG - archived_statuses included active statuses
private $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Pending', 'Canceled', 'Active Under Contract'];

// CORRECT - only truly archived statuses
private $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Canceled'];

// WRONG - is_listing_archived had its own array
private function is_listing_archived($listing_data) {
    $archived_statuses = ['Closed', 'Expired', 'Withdrawn', 'Pending', 'Canceled', 'Active Under Contract'];
    return in_array($status, $archived_statuses);
}

// CORRECT - use class method for single source of truth
private function is_listing_archived($listing_data) {
    $status = $listing_data['StandardStatus'] ?? '';
    return $this->is_archived_status($status);
}

// WRONG - process_listing_summary ignored $table_suffix
$summary_table = $wpdb->prefix . 'bme_listing_summary';

// CORRECT - use $table_suffix for correct routing
$summary_table = $wpdb->prefix . 'bme_listing_summary' . $table_suffix;
```

**Symptoms of this bug:**
- Closed listings appear in active summary table (API returns stale data)
- Duplicate listings exist in both active and archive tables
- Listing count discrepancies between tables

**Fix verification:**
```bash
# Check for Closed listings in active table (should be 0)
wp db query "SELECT COUNT(*) FROM wp_bme_listing_summary WHERE property_status = 'Closed'"

# Check for duplicates between active and archive (should be 0)
wp db query "SELECT COUNT(*) FROM wp_bme_listing_summary s
  INNER JOIN wp_bme_listing_summary_archive a ON s.listing_id = a.listing_id"
```

**Why this matters:** This bug was introduced when summary table writing changed from stored procedure to inline PHP (BME v4.0.14). The `$table_suffix` parameter was added to route data to active or archive tables, but the summary processing method ignored it.

### 33. False New Listing Alerts After Database Cleanup (v6.66.3 Fix)

After database cleanup/resync, users receive "new listing" alerts for properties that are actually Pending, Under Agreement, or Closed.

**Root Cause:**
1. BME sees cleaned-up listings as "new" (INSERT operation)
2. `bme_listing_imported` hook fires with status in metadata
3. `MLD_Import_Notification_Bridge` was re-firing hooks incorrectly
4. `MLD_Instant_Matcher` never received the callback during extraction

**The Fix (3 parts):**

```php
// 1. mls-listings-display.php - Load instant notifications on EVERY request
function mld_init_instant_notifications() {
    $init_path = MLD_PLUGIN_PATH . 'includes/instant-notifications/class-mld-instant-notifications-init.php';
    if (file_exists($init_path)) {
        require_once $init_path;
        if (class_exists('MLD_Instant_Notifications_Init')) {
            MLD_Instant_Notifications_Init::get_instance();
        }
    }
}
add_action('plugins_loaded', 'mld_init_instant_notifications', 15);

// 2. class-mld-instant-matcher.php - Early status filter in handle_new_listing()
$metadata_status = $metadata['status'] ?? null;
if ($metadata_status && !in_array($metadata_status, ['Active', 'Coming Soon'])) {
    $this->log("Skipping non-active listing $listing_id with status: $metadata_status", 'debug');
    return;
}

// 3. class-mld-import-notification-bridge.php - Direct matcher call (not hook re-fire)
if ($mld_instant_matcher_instance && method_exists($mld_instant_matcher_instance, 'handle_new_listing')) {
    $mld_instant_matcher_instance->handle_new_listing($listing_id, $enhanced_data, $metadata);
}
```

**Important:** This fix ONLY affects new listing alerts (`bme_listing_imported`). Price change and status change alerts use separate hooks and are NOT affected.

**Files involved:**
- `mls-listings-display.php` - Hook registration
- `class-mld-instant-matcher.php` - Status filtering
- `class-mld-import-notification-bridge.php` - Direct invocation
- `class-mld-notification-dispatcher.php` - Defense-in-depth check

### 34. Direct Property Lookup Must Bypass Filters (v6.68.0)

When searching for a specific property via MLS number or exact address (autocomplete selection), the API must return that property regardless of any active filters. The iOS REST API was combining direct lookups with AND logic against all filters, returning 0 results when the property didn't match.

**Example:** User has "Active" status filter, searches for MLS# 73469676 which is "Pending" → returned blank listing cards.

```php
// WRONG - treats MLS/address as just another filter (AND logic)
if (!empty($mls_number)) {
    $where[] = "s.listing_id = %s";
}
if (!empty($status)) {
    $where[] = "s.standard_status = 'Active'";  // Combined with AND!
}
// Result: WHERE listing_id = '73469676' AND status = 'Active' → 0 results

// CORRECT - bypass restrictive filters for direct property lookups
$has_direct_property_lookup = !empty($mls_number) || !empty($address);

if (!$has_direct_property_lookup && !empty($status)) {
    // Apply status filter only for regular searches
}
```

**Filters bypassed for direct lookups:** status, price, beds/baths, sqft, year built, lot size, property type, map bounds

**Files:** `class-mld-mobile-rest-api.php` (fixed v6.68.0), `class-mld-query.php` (already correct)

### 35. District Grade Rounding Bug (v6.68.8 Fix)

When calculating district average grades for saved search school filters, using `round()` on the average percentile inflated borderline grades, causing incorrect filter matches.

```php
// In get_all_district_averages() - class-mld-bmn-schools-integration.php

// WRONG - round() causes grade inflation at boundaries
$district_averages[$row->district_id] = $this->percentile_to_grade(round($row->avg_percentile));
// Example: Dartmouth has 69.8% avg → round(69.8) = 70 → A- (WRONG!)

// CORRECT - use raw percentile value (v6.68.8)
$district_averages[$row->district_id] = $this->percentile_to_grade($row->avg_percentile);
// Example: Dartmouth has 69.8% avg → 69.8 < 70 → B+ (CORRECT!)
```

**Grade Thresholds:**
| Percentile | Grade |
|------------|-------|
| 70-79 | A- |
| 60-69 | B+ |

**Why this matters:** User created saved search with `school_grade = A` filter. Dartmouth district has 69.8% average percentile. With `round()`: 69.8 → 70 → A- → filter incorrectly PASSES. Without `round()`: 69.8 < 70 → B+ → filter correctly FAILS.

**Files:** `class-mld-bmn-schools-integration.php` - `get_all_district_averages()` method

**Cache Note:** After fixing, clear: `wp transient delete mld_all_district_averages`

### 36. Duplicate Listings in Active/Archive Tables (BME v4.0.37 Fix)

Duplicate listings can exist in BOTH active and archive tables when status changes aren't handled correctly.

**Two Bugs Fixed:**

**Bug 1 (v4.0.36):** When a listing's status changed (e.g., Active → Closed), `process_single_listing()` routed it to the archive table, but the old record in the active table was never deleted.

**Bug 2 (v4.0.37):** The v4.0.36 fix used `move_listing_to_active()` which tried to INSERT into the target table. If the listing already existed in BOTH tables (unique key on `listing_key`), the INSERT failed silently and the duplicate was never cleaned up.

**Example (99 Grove Street):**
- Listing 73454722 existed in `wp_bme_listings` (Active) AND `wp_bme_listings_archive` (Active Under Contract)
- v4.0.36 found it in archive, tried to move to active, but INSERT failed (already existed)
- User saw duplicate listings when searching

**The Fix (v4.0.37):**

Check if listing exists in BOTH tables. If so, simply delete from the wrong table instead of trying to move:

```php
// v4.0.37: Check if listing exists in BOTH tables
$existing_in_opposite = $wpdb->get_row($wpdb->prepare(
    "SELECT id, listing_id FROM {$opposite_table} WHERE listing_key = %s",
    $listing_key
));

if ($existing_in_opposite) {
    // Also check if it exists in target table
    $existing_in_target = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$target_table} WHERE listing_key = %s",
        $listing_key
    ));

    if ($existing_in_target) {
        // EXISTS IN BOTH - just delete from wrong table, continue normal processing
        $wpdb->delete($opposite_table, ['id' => $existing_in_opposite->id]);
        // Also delete related data from opposite tables...
    } else {
        // Only in opposite table - use move functions
        $this->move_listing_to_archive($existing_in_opposite->id, ...);
        return;
    }
}
```

**Cleanup SQL (run if duplicates exist):**
```sql
-- Check for duplicates in main tables
SELECT COUNT(*) FROM wp_bme_listings l
INNER JOIN wp_bme_listings_archive a ON l.listing_id = a.listing_id;

-- Delete from archive where listing exists in active (active wins)
DELETE a FROM wp_bme_listings_archive a
INNER JOIN wp_bme_listings l ON a.listing_id = l.listing_id;

-- Check for duplicates in summary tables
SELECT COUNT(*) FROM wp_bme_listing_summary s
INNER JOIN wp_bme_listing_summary_archive a ON s.listing_id = a.listing_id;

-- Delete summary duplicates (active wins for non-archived statuses)
DELETE a FROM wp_bme_listing_summary_archive a
INNER JOIN wp_bme_listing_summary s ON a.listing_id = s.listing_id
WHERE s.standard_status IN ('Active', 'Pending', 'Active Under Contract');
```

**Files:** `class-bme-data-processor.php` - `process_single_listing()` (~line 416-480)

### 37. Active/Archive Tables Have Identical Schemas (FIXED Jan 2026)

All active/archive table pairs are designed to have **IDENTICAL SCHEMAS** for easy data transfer. As of v1.3.12 (Jan 20, 2026), all schemas are synchronized.

**Schema Parity (verified):**
| Table Pair | Columns |
|------------|---------|
| `wp_bme_listings` / `_archive` | 74 |
| `wp_bme_listing_details` / `_archive` | 100 |
| `wp_bme_listing_location` / `_archive` | 28 |
| `wp_bme_listing_financial` / `_archive` | 72 |
| `wp_bme_listing_features` / `_archive` | 49 |
| `wp_bme_listing_summary` / `_archive` | 48 |

**Direct SQL transfer now works:**
```sql
-- Move listing from active to archive
INSERT INTO wp_bme_listings_archive SELECT * FROM wp_bme_listings WHERE listing_id = 12345;
DELETE FROM wp_bme_listings WHERE listing_id = 12345;

-- Move all related tables too
INSERT INTO wp_bme_listing_details_archive SELECT * FROM wp_bme_listing_details WHERE listing_id = 12345;
DELETE FROM wp_bme_listing_details WHERE listing_id = 12345;
-- Repeat for location, financial, features, summary
```

**If schemas drift in the future:** Use this verification query to check parity:
```sql
SELECT 'listings' as tbl,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wp_bme_listings') as active,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wp_bme_listings_archive') as archive;
-- Repeat for each table pair
```

**History:** Schema drift occurred between Dec 2025 - Jan 2026. Fixed by adding:
- `last_imported_at`, `import_source` to `wp_bme_listings_archive`
- `subdivision_name`, `unparsed_address` to `wp_bme_listing_summary`

**Files:**
- `bridge-mls-extractor-pro-review/includes/class-bme-data-processor.php`
- `.context/architecture/listing-data-mapping.md` (schema documentation)

### 38. Autocomplete Archive Tables and Condo Unit Search (v6.68.9/v6.68.10)

Autocomplete wasn't finding non-active properties (Pending, Active Under Contract, Closed) or condo units when users searched without the `#` symbol.

**Bug 1 (v6.68.9): Archive Table Join Error**

Address autocomplete archive UNION was joining the ACTIVE location table with the archive summary table, but archived properties have no data in active tables.

```php
// WRONG - joins active location table with archive summary (no matching data)
UNION
SELECT l.unparsed_address, l.street_number, l.street_name, l.city, l.listing_id, l.listing_key
FROM {$location_table} l  // <-- active table!
INNER JOIN {$archive_table_addr} a ON l.listing_id = a.listing_id

// CORRECT (v6.68.9) - query archive summary directly (has denormalized address fields)
UNION
SELECT a.unparsed_address, a.street_number, a.street_name, a.city, a.listing_id, a.listing_key
FROM {$archive_table_addr} a
WHERE a.unparsed_address LIKE %s AND a.unparsed_address IS NOT NULL
```

**Bug 2 (v6.68.9): Property Detail Archive Lookup**

Property detail checked `bme_listings_archive` which requires JOINs for address data. Should check `bme_listing_summary_archive` first (has denormalized fields).

```php
// WRONG - listings_archive doesn't have street_number, street_name, etc.
$listing = $wpdb->get_row("SELECT * FROM wp_bme_listings_archive WHERE listing_key = %s");

// CORRECT (v6.68.9) - summary_archive has all fields denormalized
$listing = $wpdb->get_row("SELECT * FROM wp_bme_listing_summary_archive WHERE listing_key = %s");
```

**Bug 3 (v6.68.10): Condo Unit Number Search**

Users search "135 Seaport 1807" but database stores "135 Seaport Blvd # 1807". Need pattern matching for unit numbers.

```php
// WRONG - exact pattern doesn't match street suffix variations
$search_term = '%135 Seaport 1807%';  // Won't match "135 Seaport Blvd # 1807"

// CORRECT (v6.68.10) - detect unit pattern and add wildcard
if (preg_match('/^(.+)\s+(\d+)$/', $term, $matches)) {
    // "135 Seaport 1807" → "%135 Seaport% # 1807%"
    $unit_search_term = '%' . $wpdb->esc_like($matches[1]) . '% # ' . $wpdb->esc_like($matches[2]) . '%';
}
```

**CDN Cache Note:** After deploying autocomplete fixes, CDN may cache old responses. Test with cache-busting:
```bash
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=73469723&nocache=$(date +%s)"
```

**Files:** `class-mld-mobile-rest-api.php` - `handle_search_autocomplete()` and `handle_get_property()`

### 39. School Grade Method Consistency (v6.68.14)

Two different methods existed for calculating district grades, giving inconsistent results:

| Method | Calculation | Norfolk Result |
|--------|-------------|----------------|
| `get_district_average_grade_by_city()` | Average of all school percentiles | A- (79%) |
| `get_district_grade_for_city()` | Uses `district_rankings.composite_score` | B+ (62%) |

**The first method was wrong** because it averaged individual school percentiles rather than using the official composite score that accounts for district-wide metrics.

```php
// WRONG - DEPRECATED in v6.68.15 (now commented out)
$grade = $schools_integration->get_district_average_grade_by_city($city);
// Returns A- for Norfolk based on averaging individual school percentiles

// CORRECT - Use this for consistency with API display
$district_info = $schools_integration->get_district_grade_for_city($city);
$grade = $district_info ? $district_info['grade'] : null;
// Returns B+ for Norfolk based on district_rankings composite score
```

**Why this matters:** Users saw a property's district as "B+" on the property detail page, but their "A school" filter let it through because the filter used the wrong calculation method.

**Deprecated Methods (v6.68.15):**
- `get_district_average_grade_by_city()` - commented out
- `get_all_district_averages()` - helper for above, also commented out

**Files:** `class-mld-bmn-schools-integration.php`

### 40. New Search Notification Lookback Bug (v6.68.15)

New saved searches were receiving alerts for properties added BEFORE the search was created.

**Root Cause:** The notification processors didn't compare the change timestamp with the search creation date. When a user created a search, the next cron run would match ALL recent changes against it, including changes from before the search existed.

**Example:**
- User creates saved search at 7:41 PM
- Cron runs at 8:00 PM with 15-minute window (7:45-8:00 PM)
- Properties added at 4:00-6:00 PM are in `property_history` (re-synced)
- User gets alerts for 3-4 hour old properties they never asked about

**The Fix (v6.68.15):**

1. **Change Detector** now includes `change_detected_at` timestamp in results
2. **Fifteen-Minute Processor** compares `change_detected_at` with `search.created_at`
3. **Instant Matcher** compares `listing.modification_timestamp` with `search.created_at`

```php
// v6.68.15: Skip changes detected before this search was created
if ($search_created_at > 0 && !empty($change_data['change_detected_at'])) {
    $change_time = strtotime($change_data['change_detected_at']);
    if ($change_time < $search_created_at) {
        continue; // Don't notify about pre-existing properties
    }
}
```

**Files:**
- `class-mld-change-detector.php` - Added `change_detected_at` to result
- `class-mld-fifteen-minute-processor.php` - Added creation date filter
- `class-mld-instant-matcher.php` - Added creation date filter to all handlers

### 41. CMA Cache Key Must Include All Filter Parameters (v6.68.23)

AJAX responses that depend on user-selected filters must include ALL filter parameters in cache keys, not just a subset.

```php
// WRONG - cache key only includes 4 of 17+ filters
// Users with different bedroom/bathroom filters get incorrect cached results!
$cache_key = 'mld_comps_' . md5(
    $listing_id . '_' . $radius . '_' . $months_back . '_' . implode(',', $statuses)
);

// CORRECT - include ALL filter parameters
$cache_key = 'mld_comps_v2_' . md5(
    $subject['listing_id'] . '|' .
    json_encode($filters, JSON_SORT_KEYS | JSON_NUMERIC_CHECK)
);
```

**Why this matters:** User A requests comparables with 3-bed filter, result is cached. User B requests same property with 4-bed filter, gets User A's 3-bed cached results because cache key only checked radius/months_back/statuses, not beds.

**Additional v6.68.23 CMA fixes:**
- Moved nonce check before rate limiting (prevents rate limit exhaustion attacks)
- Added CDN IP detection for accurate rate limiting behind proxies
- Added coordinate validation (-90/90 lat, -180/180 lng)
- Added status whitelist validation for JSON input
- Added price validation - skips comparables with NULL/zero prices
- Fixed confidence calculator to return 0 for < 3 comparables (FHA requirement)
- Fixed comparability score adjustment weighting (removed /2 divisor)
- Enhanced frontend AJAX error detection (429, 403, 500, 504, network errors)

**Files:**
- `class-mld-comparable-ajax.php` - Cache key, validation, rate limiting, CDN IP
- `class-mld-comparable-sales.php` - Price validation, score weighting, market data logging
- `class-mld-cma-confidence-calculator.php` - Minimum 3 comparable requirement
- `mld-comparable-sales.js` - Error type detection

### 42. Orphan Modal Overlay Blocking Touch Events (v6.72.4 Fix)

On mobile property detail pages, an orphan `.mld-modal-overlay` element can cover the entire screen and block all touch interactions, making the gallery and page unresponsive.

**Symptoms:**
- Gallery images appear grayed out / non-functional on page load
- Bottom sheet (shelf) works fine, but everything above is unresponsive
- Touch events report hitting `.mld-modal-overlay` instead of intended elements
- Issue may appear on one site but not another (different plugin sync states)

**Root Cause:** Modal overlay elements remain in the DOM without their parent modal being active. The overlay has `position: fixed` and covers the viewport, intercepting all touch events.

**Debugging:** Add diagnostic to track what element receives touches:
```javascript
document.addEventListener('touchstart', function(e) {
    var el = document.elementFromPoint(e.touches[0].clientX, e.touches[0].clientY);
    console.log('Touch at y=' + e.touches[0].clientY + ' -> ' + (el ? el.className : 'none'));
});
```

If output shows `mld-modal-overlay` for most touches, this is the issue.

**The Fix (v6.72.4):**

```css
/* Hide all modal overlays by default on mobile property pages */
body.mld-property-mobile-v3 .mld-modal-overlay {
  display: none !important;
  pointer-events: none !important;
  visibility: hidden !important;
  opacity: 0 !important;
}

/* Only show overlay when parent modal is active */
body.mld-property-mobile-v3 .mld-modal.active .mld-modal-overlay,
body.mld-property-mobile-v3 .mld-comparison-modal.active .mld-modal-overlay,
body.mld-property-mobile-v3 .mld-trends-modal.active .mld-modal-overlay {
  display: block !important;
  pointer-events: auto !important;
  visibility: visible !important;
  opacity: 1 !important;
}
```

**Why this matters:** This bug can appear unexpectedly after plugin syncs or updates. The modal overlay is invisible (transparent or semi-transparent) so users see the gallery but can't interact with it. Without knowing to check for orphan overlays, debugging is difficult.

**Files:** `assets/css/property-mobile-v3.css` - Lines ~4792-4810

### 43. Content Security Policy Blocks Video Embeds (v1.5.9 Fix)

YouTube and Vimeo iframes won't load if the Content Security Policy (CSP) doesn't include them in `frame-src`.

**Symptoms:**
- Video section shows but iframe is blank/blocked
- Browser console shows CSP violation error
- Works in some browsers but not others

**Root Cause:** The MLD plugin sets a CSP header that restricts which domains can be embedded in iframes.

**Fix Location:** `mls-listings-display/mls-listings-display.php` - Search for `frame-src`

```php
// WRONG - YouTube/Vimeo blocked
"frame-src 'self' https://*.google.com https://www.google.com https://*.matterport.com"

// CORRECT - includes video platforms
"frame-src 'self' https://*.google.com https://www.google.com https://*.matterport.com https://my.matterport.com https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com"
```

**Note:** This must be updated on BOTH bmnboston.com and steve-novak.com when adding new embed sources.

### 44. WordPress Filter Callbacks Must Exist (v6.74.1 Fix)

When registering WordPress filter/action hooks, the callback method MUST exist. A missing callback causes a fatal error when WordPress invokes the hook.

**Symptoms:**
- HTTP 500 Internal Server Error on specific operations (registration, login, etc.)
- Error occurs consistently, not intermittently
- PHP fatal error in logs: "Call to undefined method"

**Example Bug (v6.74.1):**
```php
// WRONG - method doesn't exist, causes fatal error when filter runs!
class MLD_Email_Validator {
    public static function init() {
        add_filter('pre_user_email', array(__CLASS__, 'validate_user_email_on_create'));
        // ^^^ This method was NEVER DEFINED in the class!
    }
    // Missing: public static function validate_user_email_on_create($email) { ... }
}

// CORRECT - only register hooks for methods that exist
class MLD_Email_Validator {
    public static function init() {
        // Removed broken hook - validation handled by wp_pre_insert_user_data filter
        add_filter('wp_pre_insert_user_data', array(__CLASS__, 'validate_user_data_on_insert'), 10, 4);
    }

    public static function validate_user_data_on_insert($data, $update, $user_id, $userdata) {
        // Method exists!
    }
}
```

**Prevention:**
1. After adding `add_filter()` or `add_action()`, immediately verify the callback method exists
2. Use IDE "Find usages" to check if method names in hooks have matching definitions
3. When refactoring/renaming methods, search for all `add_filter`/`add_action` references
4. Test the specific operation (registration, login, etc.) after any changes to hook registration

**Files:** `class-mld-email-validator.php` - `init()` method

### 45. File Permissions After Deployment (CRITICAL)

After deploying CSS/JS files via SCP, file permissions may be incorrect, preventing the web server from reading them. This causes pages to load but appear broken (black/empty content areas).

**Symptoms:**
- Page HTML loads but content area is black/empty
- Navigation elements visible but main content missing
- Browser DevTools → Network tab shows 403 errors on CSS/JS files
- Server error log shows: `failed (13: Permission denied)`

**The Fix - ALWAYS run after deploying CSS/JS files:**
```bash
# bmnboston.com
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "chmod 644 ~/public/wp-content/plugins/PLUGIN/assets/css/*.css ~/public/wp-content/plugins/PLUGIN/assets/js/*.js"

# steve-novak.com
sshpass -p 'nxGDPBDdpeuh2Io' ssh -p 50594 stevenovakrealestate@35.236.219.140 \
  "chmod 644 ~/public/wp-content/plugins/PLUGIN/assets/css/*.css ~/public/wp-content/plugins/PLUGIN/assets/js/*.js"
```

**To diagnose:**
```bash
# Check for permission errors
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "tail -50 ~/logs/error.log | grep -i permission"
```

**Why this happens:** SCP preserves local file permissions which may not match what the web server (www-data) needs. Files need 644 (rw-r--r--) to be readable.

### 46. CMA PDF Generator Data Structure Requirements (v6.74.7)

The CMA PDF generator (`class-mld-cma-pdf-generator.php`) expects a specific nested data structure. Passing flat data or missing fields causes blank sections.

**Required `$cma_data` structure:**
```php
$cma_data = array(
    'summary' => array(
        'estimated_value' => array(
            'low' => 450000,
            'high' => 550000,
            'mid' => 500000,
            'confidence' => 'high', // high|medium|low|insufficient
        ),
        'total_found' => 10,
        'avg_price' => 500000,
        'median_price' => 495000,
        'avg_dom' => 45,
        'price_per_sqft' => array('avg' => 350),
    ),
    'comparables' => array(
        array(
            'comparability_grade' => 'A', // A|B|C|D|F - PDF shows only A/B!
            'unparsed_address' => '123 Main St',
            'list_price' => 525000,
            'adjusted_price' => 510000,
            'bedrooms_total' => 3,
            'bathrooms_total' => 2.0,
            'building_area_total' => 1500,
            'year_built' => '2010',
            'distance_miles' => '0.5',
            'standard_status' => 'Closed',
            'days_on_market' => 30,
            'adjustments' => array(
                'total_adjustment' => 0,
                'items' => array(),
            ),
        ),
        // ... more comparables
    ),
);
```

**Common Failures:**
1. **"Insufficient Data" in value section** - Missing `summary` object or `estimated_value` nested array
2. **Empty comparables section** - Missing `comparability_grade` field (PDF filters for A/B grades only)
3. **Blank comparable cards** - Missing required fields like `unparsed_address`, `adjusted_price`, `adjustments`

**Comparability Grade Calculation (v6.74.7):**
```php
$grade_score = 100;
$grade_score -= min(40, $distance * 10);           // Distance penalty
$grade_score -= min(20, $sqft_diff_pct / 2);       // Sqft difference penalty
$grade_score -= $bed_diff * 10;                     // Bedroom difference penalty

// Score to grade: ≥85=A, ≥70=B, ≥55=C, ≥40=D, <40=F
```

**Files:**
- `class-mld-mobile-rest-api.php` - `handle_generate_cma_pdf()` builds data
- `class-mld-cma-pdf-generator.php` - Expects specific structure

### 47. CMA PDF Selected Comparables Must Query Directly (v6.74.11)

When users select specific comparables in the iOS app for PDF generation, the backend must query those exact properties by `listing_key`, NOT re-run a generic search query.

**Problem (v6.74.10 and earlier):** The PDF generator ran its own query with different criteria than the CMA display endpoint. User-selected comparables might not appear in the PDF query results.

```php
// WRONG - Generic query might not include user's selected properties
$comparables = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$archive_table}
     WHERE city = %s AND bedrooms_total BETWEEN %d AND %d
     LIMIT 10",
    $subject->city, $bed_low, $bed_high
));
// Then filter to selected_comparables - but they might not be in results!

// CORRECT - Query selected comparables directly by listing_key
if (!empty($selected_comparables)) {
    $placeholders = implode(',', array_fill(0, count($selected_comparables), '%s'));
    $comparables = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$archive_table}
         WHERE listing_key IN ({$placeholders})",
        $selected_comparables
    ));
}
```

### 48. Archive Summary Table Column Names (v6.74.12)

The archive summary table (`bme_listing_summary_archive`) uses different column names than expected. Always check the actual schema.

| Expected | Actual Column | Notes |
|----------|---------------|-------|
| `list_date` | `listing_contract_date` | Date property was listed |
| DOM calculation | `days_on_market` | Pre-calculated, use directly |

```php
// WRONG - list_date doesn't exist
if (!empty($comp->close_date) && !empty($comp->list_date)) {
    $dom = (strtotime($comp->close_date) - strtotime($comp->list_date)) / 86400;
}

// CORRECT - use days_on_market column, fallback to listing_contract_date
if (!empty($comp->days_on_market) && $comp->days_on_market > 0) {
    $dom = (int) $comp->days_on_market;
} elseif (!empty($comp->close_date) && !empty($comp->listing_contract_date)) {
    $dom = (int) ((strtotime($comp->close_date) - strtotime($comp->listing_contract_date)) / 86400);
}
```

**Symptom:** "Average Days on Market: N/A" in CMA PDF despite having valid comparables.

---

## Authentication & Token Configuration

### JWT Token Expiration (v6.50.8)

| Token Type | Expiration | Constant |
|------------|-----------|----------|
| Access Token | 30 days | `ACCESS_TOKEN_EXPIRY = 2592000` |
| Refresh Token | 30 days | `REFRESH_TOKEN_EXPIRY = 2592000` |

**Location:** `class-mld-mobile-rest-api.php` (lines 28-38)

**Why 30 days:** Originally access tokens expired after 15 minutes with refresh tokens lasting 7 days. This caused unexpected logouts when:
1. User put app in background
2. Access token expired (15 min)
3. User reopened app after 30-60 minutes
4. Token refresh failed (network issue, CDN cache, race condition)
5. After 2 failed refresh attempts, iOS cleared all tokens → user logged out

**30-day tokens are safe for this app because:**
- No financial transactions occur
- Property data is public information
- Users can still manually log out
- Changing password invalidates all tokens server-side
- Standard practice for consumer mobile apps

---

## Local Development (Docker)

```bash
cd ~/Development/BMNBoston/wordpress
docker-compose up -d
```
- WordPress: http://localhost:8080
- phpMyAdmin: http://localhost:8081
- Mailhog: http://localhost:8025

---

## Build & Deploy Commands

### iOS Build (Simulator)
```bash
cd ~/Development/BMNBoston/ios
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15' build
```

### iOS Build (Device)
```bash
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS,id=00008140-00161D3A362A801C' \
    -allowProvisioningUpdates build
```

### iOS Tests
```bash
xcodebuild test -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15'
```

### WordPress Deployment

**See [.context/credentials/server-credentials.md](.context/credentials/server-credentials.md) for full credentials.**

**NOTE:** `sshpass -p 'pass'` fails on this machine. Use `-e` flag with environment variable:

```bash
# bmnboston.com (port 57105)
SSHPASS='cFDIB2uPBj5LydX' sshpass -e scp -o StrictHostKeyChecking=no -P 57105 file.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/PLUGIN/
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 "touch ~/public/wp-content/plugins/PLUGIN/*.php"

# steve-novak.com (port 50594)
SSHPASS='nxGDPBDdpeuh2Io' sshpass -e scp -o StrictHostKeyChecking=no -P 50594 file.php stevenovakrealestate@35.236.219.140:~/public/wp-content/plugins/PLUGIN/
SSHPASS='nxGDPBDdpeuh2Io' sshpass -e ssh -o StrictHostKeyChecking=no -p 50594 stevenovakrealestate@35.236.219.140 "touch ~/public/wp-content/plugins/PLUGIN/*.php"
```

**CRITICAL: Fix File Permissions After Deployment**

After deploying CSS/JS files, ALWAYS fix permissions to ensure the web server can read them:

```bash
# bmnboston.com - fix CSS/JS permissions
sshpass -p 'cFDIB2uPBj5LydX' ssh -p 57105 stevenovakcom@35.236.219.140 "chmod 644 ~/public/wp-content/plugins/PLUGIN/assets/css/*.css ~/public/wp-content/plugins/PLUGIN/assets/js/*.js"

# steve-novak.com - fix CSS/JS permissions
sshpass -p 'nxGDPBDdpeuh2Io' ssh -p 50594 stevenovakrealestate@35.236.219.140 "chmod 644 ~/public/wp-content/plugins/PLUGIN/assets/css/*.css ~/public/wp-content/plugins/PLUGIN/assets/js/*.js"
```

**Symptoms of permission issues:**
- Page HTML loads but content area is black/empty
- Browser DevTools Network tab shows 403 errors on CSS/JS files
- Server error log shows: `failed (13: Permission denied)`

**To diagnose:** `tail -50 ~/logs/error.log | grep -i permission`

---

## API Testing

```bash
# Property search
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1"

# School filter (must return 1000+, NOT 0)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Autocomplete
curl "https://bmnboston.com/wp-json/mld-mobile/v1/search/autocomplete?term=boston"

# Schools near location
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

**Common filters**: `city`, `min_price`, `max_price`, `beds`, `baths`, `status`, `school_grade`, `bounds`, `price_reduced`, `new_listing_days`

### Authenticated API Testing

```bash
# Get auth token for testing
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d '{"email":"demo@bmnboston.com","password":"demo1234"}' | \
    python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Use with authenticated endpoints
curl "https://bmnboston.com/wp-json/mld-mobile/v1/favorites" \
    -H "Authorization: Bearer $TOKEN"

# Agent endpoints (requires agent account)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/agent/clients" \
    -H "Authorization: Bearer $TOKEN"
```

### Analytics Dashboard Endpoints

```bash
# Trends with preset range (7d, 30d, 90d, 12m, ytd)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/analytics/trends?range=7d"

# Trends with custom date range (overrides range param when both provided)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/analytics/trends?start_date=2026-01-01&end_date=2026-01-31"

# Top content (pages, properties, searches)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/analytics/top-content?type=pages&start_date=2026-01-28&end_date=2026-02-04"

# Traffic sources
curl "https://bmnboston.com/wp-json/mld-mobile/v1/analytics/traffic-sources?start_date=2026-01-28&end_date=2026-02-04"

# Geographic data (countries, cities)
curl "https://bmnboston.com/wp-json/mld-mobile/v1/analytics/geo?type=countries&start_date=2026-01-28&end_date=2026-02-04"
```

**Analytics date params**: Use `range=7d|30d|90d|12m|ytd` for presets, OR `start_date=YYYY-MM-DD&end_date=YYYY-MM-DD` for custom ranges. Custom dates override `range` when both provided.

---

## Post-Deployment Verification

After deploying WordPress changes, verify these pass:
```bash
# Must return results (check 'total' field)
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Total: {d.get(\"total\", 0)}')"

# Must return 1000+ (NOT 0) - verifies school filter & year rollover
curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'School filter: {d.get(\"total\", 0)}')"

# Must return status: ok
curl -s "https://bmnboston.com/wp-json/bmn-schools/v1/health" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'Health: {d.get(\"status\", \"error\")}')"
```

---

## Version Management

**Full version table and bump instructions:** See [.context/VERSIONS.md](.context/VERSIONS.md)

Quick reference for current versions:
| Component | Current Version |
|-----------|-----------------|
| iOS App | v402 (1.8) |
| MLS Listings Display | v6.75.8 |
| BMN Schools | v0.6.39 |
| SN Appointments | v1.10.4 |
| Exclusive Listings | v1.5.3 |
| BMN Flip Analyzer | v0.6.1 |

---

## Key Files

| Purpose | Path |
|---------|------|
| iOS API Client | `ios/BMNBoston/Core/Networking/APIClient.swift` |
| iOS Search ViewModel | `ios/BMNBoston/Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` |
| iOS Notification Store | `ios/BMNBoston/Core/Storage/NotificationStore.swift` |
| iOS Rich Notification Extension | `ios/NotificationServiceExtension/NotificationService.swift` |
| Mobile REST API (iOS) | `wordpress/.../mls-listings-display/includes/class-mld-mobile-rest-api.php` |
| Push Notifications (Server) | `wordpress/.../mls-listings-display/includes/notifications/class-mld-push-notifications.php` |
| Web Query Builder | `wordpress/.../mls-listings-display/includes/class-mld-query.php` |
| Schools REST API | `wordpress/.../bmn-schools/includes/class-rest-api.php` |
| Appointments REST API | `wordpress/.../sn-appointment-booking/includes/class-snab-rest-api.php` |
| Appointments AJAX (Web) | `wordpress/.../sn-appointment-booking/includes/class-snab-frontend-ajax.php` |
| Agent-Client Manager | `wordpress/.../mls-listings-display/includes/saved-searches/class-mld-agent-client-manager.php` |
| Email Utilities | `wordpress/.../mls-listings-display/includes/class-mld-email-utilities.php` |
| CMA PDF Generator | `wordpress/.../mls-listings-display/includes/class-mld-cma-pdf-generator.php` |
| Analytics Dashboard JS | `wordpress/.../mls-listings-display/assets/js/admin/mld-analytics-dashboard.js` |
| Analytics Dashboard CSS | `wordpress/.../mls-listings-display/assets/css/admin/mld-analytics-dashboard.css` |
| Analytics REST API | `wordpress/.../mls-listings-display/includes/class-mld-extended-analytics.php` |
| Analytics Dashboard View | `wordpress/.../mls-listings-display/includes/analytics/admin/views/analytics-dashboard.php` |

---

## Agent-Client System

Enterprise agent-client management system. Full docs: `.context/features/agent-client-system/`

### Key Endpoints
```
GET  /mld-mobile/v1/agents           - List all agents
GET  /mld-mobile/v1/my-agent         - Get client's assigned agent
GET  /mld-mobile/v1/agent/clients    - Get agent's client list
POST /mld-mobile/v1/agent/clients    - Create new client
GET  /mld-mobile/v1/agent/metrics    - Get agent stats
GET  /mld-mobile/v1/shared-properties - Get properties shared with client
POST /mld-mobile/v1/agent/searches/batch - Create searches for client
```

### Database Tables
| Table | Purpose |
|-------|---------|
| `wp_mld_agent_profiles` | Agent details |
| `wp_mld_agent_client_relationships` | Agent-client assignments |
| `wp_mld_shared_properties` | Properties shared by agent |
| `wp_mld_user_types` | User classification (agent/client) |

---

## Email Notification System (v6.63.0)

Centralized email utilities for dynamic "from" addresses and unified footers across MLD and SNAB plugins.

### Core Class: `MLD_Email_Utilities`

**Location:** `includes/class-mld-email-utilities.php`

```php
// Get dynamic from header (uses agent's email if client has assigned agent)
$headers = MLD_Email_Utilities::get_email_headers($recipient_user_id);

// Get unified footer with App Store promotion
$footer = MLD_Email_Utilities::get_unified_footer([
    'context' => 'property_alert', // property_alert, appointment, general
    'show_social' => true,
    'show_app_download' => true,
    'compact' => false,
]);
```

### From Address Logic

| Recipient | From Email |
|-----------|------------|
| Admin/Agent | MLD Email Settings |
| Client WITHOUT agent | MLD Email Settings |
| Client WITH agent | Agent's email address |

### WordPress System Email Hooks

These filters customize WordPress core emails:
- `password_change_email` - Password change confirmation
- `wp_new_user_notification_email_admin` - Admin new user notification
- `email_change_email` - Email change notification

### Email Types (16 Total)

| Category | Emails |
|----------|--------|
| Property | Alert, CMA, Shared, Open House |
| User | Welcome, Password Reset, Password Change, Email Change |
| Contact | Form Confirmation (Admin + User), Tour Request |
| Appointments | Confirmation, Reminder, Cancellation, Reschedule |
| Admin | New User Registration, Agent Assignment |

---

## Project Structure

```
~/Development/BMNBoston/
├── ios/                    # iOS App (SwiftUI)
├── wordpress/wp-content/plugins/
│   ├── mls-listings-display/     # Property search
│   ├── bmn-schools/              # School data
│   ├── sn-appointment-booking/   # Appointments
│   └── bridge-mls-extractor-pro/ # MLS extraction
└── .context/               # Full documentation
```

---

## Environment

- **Production API**: https://bmnboston.com/wp-json/
- **Admin/Agent Account**: steve@bmnboston.com / steve@bmnboston.com (password = email) **DO NOT CHANGE THIS PASSWORD**
- **Demo Account**: demo@bmnboston.com / demo1234
- **Test Device**: iPhone 16 Pro (`00008140-00161D3A362A801C`)
- **SSH**: `ssh stevenovakcom@35.236.219.140 -p 57105`
- **Plugin Path**: `~/public/wp-content/plugins/`
