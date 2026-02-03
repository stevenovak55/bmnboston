# CLAUDE.md - SN Appointment Booking

## Quick Reference

**Plugin Name**: SN Appointment Booking
**Plugin Location**: `wordpress/wp-content/plugins/sn-appointment-booking/`
**Current Version**: 1.8.0
**Last Updated**: December 28, 2025

---

## Critical Pitfalls (READ FIRST)

### 1. Two Booking Code Paths - iOS REST API vs Web AJAX
**CRITICAL**: Bookings come through TWO different code paths:
- **iOS App**: REST API `POST /wp-json/snab/v1/appointments` → `class-snab-rest-api.php`
- **Web Widget**: AJAX `snab_book_appointment` → `class-snab-frontend-ajax.php`

**When fixing bugs or adding features, ALWAYS update BOTH files.**

### 2. Google Calendar: Per-Staff vs Global Connection
There are TWO types of Google Calendar connections:
- **Global** (wp_options): `snab_google_refresh_token` - used for admin dashboard
- **Per-Staff** (wp_snab_staff table): `google_refresh_token` column - used for booking sync

**For booking sync to work:**
- Use `$google->is_staff_connected($staff_id)` NOT `$google->is_connected()`
- Use `$google->create_staff_event($staff_id, $data)` NOT `$google->create_event($data)`

### 3. DateTime Format for Google Calendar API
Google Calendar requires RFC3339 format with seconds:
```php
// CORRECT: 2025-12-29T12:00:00
$time_with_seconds = (strlen($time) === 5) ? $time . ':00' : $time;
$start_datetime = $date . 'T' . $time_with_seconds;

// WRONG: 2025-12-29T12:00 (missing seconds - causes "Bad Request")
$start_datetime = $date . 'T' . $time;
```

### 4. Cancellation Policy Settings
Cancellation/reschedule permissions are controlled by wp_options:
- `snab_cancellation_hours_before` - Hours before appointment when cancellation is allowed (0 = anytime)
- `snab_reschedule_hours_before` - Hours before appointment when reschedule is allowed (0 = anytime)
- `snab_max_reschedules_per_appointment` - Maximum reschedules allowed per appointment

**Current Settings**: Clients can cancel/reschedule at any time (0 hours restriction).

### 5. Staff Selection in Bookings
When no staff is selected during booking:
- System falls back to primary staff (`is_primary = 1` in wp_snab_staff)
- Ensure primary staff has Google Calendar connected for sync to work

---

## Before Starting Any Session

1. **Read** `.context/SESSION_RESUME.md` for current state
2. **Check** `.context/TASKS.md` for next priorities
3. **Review** `.context/DEVELOPMENT_LOG.md` for recent changes (if exists)
4. **Update** SESSION_RESUME.md at end of session

---

## Plugin Purpose

Google Calendar-integrated appointment booking system for real estate services. Allows clients to:
- View available time slots (weekly calendar view)
- Book appointments (showings, consultations, etc.)
- Receive confirmation and reminder emails

Admin can:
- Configure Google Calendar integration
- Manage appointment types
- Set availability rules
- View and manage bookings

---

## Key Files

| File | Purpose |
|------|---------|
| `sn-appointment-booking.php` | Main plugin file |
| `includes/class-snab-activator.php` | Plugin activation, table creation |
| `includes/class-snab-deactivator.php` | Plugin deactivation |
| `includes/class-snab-upgrader.php` | Version management |
| `includes/class-snab-admin.php` | Admin menu setup |
| `includes/class-snab-admin-settings.php` | Settings page |
| `includes/class-snab-admin-types.php` | Appointment types CRUD |
| `includes/class-snab-admin-availability.php` | Availability management |
| `includes/class-snab-google-calendar.php` | Google Calendar API integration |
| `includes/class-snab-availability.php` | Availability calculation service |
| `includes/class-snab-ajax.php` | AJAX handlers |
| `includes/class-snab-shortcodes.php` | Frontend shortcodes |
| `includes/class-snab-notifications.php` | Email notifications |
| `assets/js/booking-widget.js` | Frontend calendar widget |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_snab_staff` | Staff members with Google Calendar connections |
| `wp_snab_appointment_types` | Appointment type configurations |
| `wp_snab_availability_rules` | Availability rules per staff |
| `wp_snab_appointments` | Booked appointments |
| `wp_snab_notifications_log` | Email notification log |

---

## Version Locations (Keep in Sync!)

When bumping version, update ALL these locations:

1. `sn-appointment-booking.php` - Header comment (line ~6)
2. `sn-appointment-booking.php` - `SNAB_VERSION` constant
3. `includes/class-snab-upgrader.php` - `CURRENT_VERSION` constant
4. `includes/class-snab-upgrader.php` - `CURRENT_DB_VERSION` constant
5. `version.json` - `version` field
6. `.context/SESSION_RESUME.md` - Current Version field

---

## Development Commands

```bash
# Navigate to project
cd /home/snova/projects/bmnv2

# Start Docker environment
./wp-helper.sh start

# Check plugin status
./wp-helper.sh wp plugin list | grep appointment

# Activate plugin
./wp-helper.sh wp plugin activate sn-appointment-booking

# Deactivate plugin
./wp-helper.sh wp plugin deactivate sn-appointment-booking

# View WordPress debug log
docker compose exec wordpress tail -f /var/www/html/wp-content/debug.log

# Check database tables
docker compose exec db mysql -u wordpress -pwordpress wordpress \
  -e "SHOW TABLES LIKE 'wp_snab%';"

# Check table structure
docker compose exec db mysql -u wordpress -pwordpress wordpress \
  -e "DESCRIBE wp_snab_appointments;"

# Check stored version
./wp-helper.sh wp option get snab_db_version

# Simulate upgrade from previous version
./wp-helper.sh wp option update snab_db_version "0.9.0"
./wp-helper.sh wp plugin deactivate sn-appointment-booking
./wp-helper.sh wp plugin activate sn-appointment-booking
```

---

## Coding Standards

### WordPress Patterns
- Use `$wpdb->prefix` for table names (never hardcode `wp_`)
- Use `current_time('mysql')` for timestamps (WordPress timezone)
- Use `wp_date()` for displaying dates
- Use `sanitize_text_field()`, `absint()`, etc. for input
- Use `esc_html()`, `wp_kses_post()` for output
- Use `$wpdb->prepare()` for all SQL queries

### Security
- All AJAX handlers must use nonce verification
- Admin pages check `current_user_can('manage_options')`
- Sanitize all user inputs
- Escape all outputs

### Database Column Standards
- Foreign keys: `listing_id VARCHAR(50)` pattern
- Timestamps: `created_at DATETIME`, `updated_at DATETIME`
- Use `ON UPDATE CASCADE, ON DELETE RESTRICT` for FKs

### File Permissions
- Files: `chmod 644`
- Directories: `chmod 755`

---

## Class Prefix

All classes use `SNAB_` prefix:
- `SNAB_Activator`
- `SNAB_Deactivator`
- `SNAB_Upgrader`
- `SNAB_Admin`
- etc.

## Function Prefix

All functions use `snab_` prefix:
- `snab_activate()`
- `snab_deactivate()`
- etc.

## Option Names

All options use `snab_` prefix:
- `snab_db_version`
- `snab_google_client_id`
- `snab_google_client_secret`
- `snab_google_refresh_token` (encrypted)
- `snab_settings`

## AJAX Actions

All AJAX actions use `snab_` prefix:
- `wp_ajax_snab_get_availability`
- `wp_ajax_nopriv_snab_get_availability`
- `wp_ajax_snab_book_appointment`
- `wp_ajax_nopriv_snab_book_appointment`

---

## Google Calendar Integration

### OAuth2 Flow
1. Admin enters Client ID/Secret from Google Cloud Console
2. Admin clicks "Authorize" button
3. Redirect to Google OAuth consent screen
4. Google redirects back with auth code
5. Exchange code for access + refresh tokens
6. Store refresh token (encrypted) in database
7. Use refresh token to get new access tokens as needed

### API Endpoints Used
- `freebusy.query` - Check availability
- `events.insert` - Create appointment event
- `events.update` - Reschedule appointment
- `events.delete` - Cancel appointment

---

## Shortcodes

### `[snab_booking_form]`
Renders the weekly calendar booking interface.

**Attributes:**
- `type` - Filter to specific appointment type slug(s)
- `weeks` - Number of weeks to show (default: 2)
- `show_timezone` - Show timezone selector (default: true)

**Example:**
```
[snab_booking_form type="property-showing,buyer-consultation" weeks="3"]
```

---

## Documentation Files

| File | Purpose |
|------|---------|
| `.context/README.md` | Project overview & navigation |
| `.context/SESSION_RESUME.md` | Current state for session continuity |
| `.context/ROADMAP.md` | Implementation phases & progress |
| `.context/TASKS.md` | Current tasks & todos |
| `.context/ARCHITECTURE.md` | Technical architecture details |
| `.context/DATABASE_SCHEMA.md` | Complete database documentation |
| `.context/DEVELOPMENT_LOG.md` | Chronological change log |

---

## Error Handling

Log errors using the SNAB_Logger class:
```php
SNAB_Logger::error('Error message', ['context' => 'data']);
SNAB_Logger::warning('Warning message');
SNAB_Logger::info('Info message');
SNAB_Logger::debug('Debug message');
```

Logs written to WordPress debug.log when WP_DEBUG is enabled.

---

## Testing Checklist

Before deploying any changes:

1. [ ] Plugin activates without errors
2. [ ] All database tables exist
3. [ ] Admin pages load correctly
4. [ ] AJAX endpoints return expected data
5. [ ] Frontend shortcode renders
6. [ ] Booking flow works end-to-end
7. [ ] Google Calendar events created automatically on booking
8. [ ] Emails sent correctly
9. [ ] No PHP warnings/notices in debug.log
10. [ ] Deactivation doesn't break site

### Appointment Feature Regression Tests
**Run these tests after ANY change to appointment code:**

1. [ ] **Book via iOS**: Create appointment through iOS app → should sync to Google Calendar
2. [ ] **Book via Web**: Create appointment through web widget → should sync to Google Calendar
3. [ ] **Cancel**: Cancel an appointment → should show cancel option, remove from Google Calendar
4. [ ] **Reschedule**: Reschedule an appointment → should show reschedule option, update Google Calendar
5. [ ] **API Response**: Check `/wp-json/snab/v1/appointments` returns `can_cancel: true` for upcoming appointments

### Quick API Test Commands
```bash
# Get fresh token
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"mail@steve-novak.com","password":"YOUR_PASSWORD"}' | \
  python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Check appointments have can_cancel/can_reschedule
curl -s "https://bmnboston.com/wp-json/snab/v1/appointments" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | grep -E '"can_cancel"|"can_reschedule"'

# Check Google Calendar sync status
curl -s "https://bmnboston.com/wp-json/snab/v1/appointments" \
  -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | grep '"google_synced"'
```

---

## Key Files (Updated)

| File | Purpose |
|------|---------|
| `sn-appointment-booking.php` | Main plugin file |
| `includes/class-snab-rest-api.php` | **iOS booking endpoint** - REST API for mobile app |
| `includes/class-snab-frontend-ajax.php` | **Web booking endpoint** - AJAX for web widget |
| `includes/class-snab-google-calendar.php` | Google Calendar API integration |
| `includes/class-snab-activator.php` | Plugin activation, table creation |
| `includes/class-snab-availability-service.php` | Availability calculation |
| `includes/class-snab-notifications.php` | Email notifications |
| `includes/class-snab-admin.php` | Admin menu setup |

---

## Production Deployment

```bash
# Upload single file
sshpass -p 'KINSTA_PASSWORD' scp -P 57105 \
  /Users/bmnboston/Development/BMNBoston/wordpress/wp-content/plugins/sn-appointment-booking/includes/FILE.php \
  stevenovakcom@35.236.219.140:~/public/wp-content/plugins/sn-appointment-booking/includes/

# Check debug log on production
sshpass -p 'KINSTA_PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "cat /tmp/snab_debug.log"
```
