# SN Appointment Booking Plugin

Google Calendar-integrated appointment booking system.

## Quick Info

| Setting | Value |
|---------|-------|
| Version | 1.10.0 |
| API Namespace | `/wp-json/snab/v1` |
| Main File | `sn-appointment-booking.php` |
| Current Phase | 17 - Multi-Attendee Booking |

## Key Files

| File | Purpose |
|------|---------|
| `includes/class-snab-rest-api.php` | iOS REST API endpoints |
| `includes/class-snab-frontend-ajax.php` | Web AJAX handlers |
| `includes/class-snab-google-calendar.php` | Google Calendar integration |
| `includes/class-snab-availability-service.php` | Slot calculation |
| `includes/class-snab-notifications.php` | Email notifications |

## Documentation

- [Booking Flow](booking-flow.md) - iOS + Web booking flows
- [Main CLAUDE.md](../../../CLAUDE.md) - Critical pitfalls (Google Calendar, dual code paths)

## Critical Rules

### 1. Two Booking Code Paths

Bookings come through TWO different paths:
- **iOS**: REST API `POST /snab/v1/appointments` → `class-snab-rest-api.php`
- **Web**: AJAX `snab_book_appointment` → `class-snab-frontend-ajax.php`

**When fixing bugs, ALWAYS update BOTH files.**

### 2. Per-Staff Google Calendar

Use per-staff methods, NOT global:

```php
// CORRECT
$google->is_staff_connected($staff_id);
$google->create_staff_event($staff_id, $data);

// WRONG
$google->is_connected();
$google->create_event($data);
```

### 3. DateTime Format

Include seconds in datetime strings:

```php
// CORRECT: "2025-12-29T12:00:00"
$start = $date . 'T' . $time . ':00';

// WRONG: "2025-12-29T12:00" (causes Bad Request)
$start = $date . 'T' . $time;
```

## Quick API Tests

```bash
# Get availability
curl "https://bmnboston.com/wp-json/snab/v1/availability?date=2026-01-15"

# Get appointment types
curl "https://bmnboston.com/wp-json/snab/v1/appointment-types"

# Check user appointments (auth required)
TOKEN="..."
curl "https://bmnboston.com/wp-json/snab/v1/appointments" \
    -H "Authorization: Bearer $TOKEN"
```

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_snab_staff` | Staff with Google connections |
| `wp_snab_appointment_types` | Type configurations |
| `wp_snab_availability_rules` | Staff availability |
| `wp_snab_appointments` | Booked appointments |
| `wp_snab_appointment_attendees` | Multi-attendee support (v1.10.0) |
| `wp_snab_notifications_log` | Email/push history |

## Version Updates

Update ALL 4 locations:
1. `version.json`
2. `sn-appointment-booking.php` header
3. `SNAB_VERSION` constant
4. `class-snab-upgrader.php` constants

## Cancellation Policy

Controlled by wp_options:
- `snab_cancellation_hours_before` - Hours before (0 = anytime)
- `snab_reschedule_hours_before` - Hours before (0 = anytime)
- `snab_max_reschedules_per_appointment` - Max reschedules allowed

Current: Clients can cancel/reschedule at any time.

## Deployment

```bash
# Upload file
scp -P 57105 includes/class-snab-rest-api.php \
    stevenovakcom@35.236.219.140:~/public/wp-content/plugins/sn-appointment-booking/includes/

# Touch for opcache
ssh -p 57105 stevenovakcom@35.236.219.140 \
    "touch ~/public/wp-content/plugins/sn-appointment-booking/includes/*.php"
```
