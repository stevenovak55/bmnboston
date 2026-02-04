# Appointment Booking Flow

How appointments are created on iOS and web.

## Code Path Overview

**CRITICAL:** iOS and web use completely different code paths.

| Platform | Endpoint | Handler File |
|----------|----------|--------------|
| iOS App | REST API `POST /snab/v1/appointments` | `class-snab-rest-api.php` |
| Web Widget | AJAX `snab_book_appointment` | `class-snab-frontend-ajax.php` |

**When fixing bugs or adding features, ALWAYS update BOTH files.**

## iOS Booking Flow

```
1. User selects appointment type
   │
   ▼
2. AppointmentViewModel fetches availability
   GET /snab/v1/availability?type_id=X&date=Y
   │
   ▼
3. User selects date and time slot
   │
   ▼
4. AppointmentViewModel creates booking
   POST /snab/v1/appointments
   {
       "type_id": 1,
       "date": "2026-01-15",
       "time": "14:00",
       "listing_id": "abc123",
       "notes": "Optional notes"
   }
   │
   ▼
5. class-snab-rest-api.php handles request
   ├── Validates input
   ├── Creates appointment record
   ├── Syncs to Google Calendar (if connected)
   └── Sends confirmation email
   │
   ▼
6. Returns appointment details
   {
       "id": 42,
       "status": "confirmed",
       "google_synced": true,
       "can_cancel": true,
       "can_reschedule": true
   }
```

## Web Booking Flow

```
1. User loads booking widget
   [snab_booking_form] shortcode
   │
   ▼
2. JavaScript fetches availability
   AJAX: snab_get_availability
   │
   ▼
3. User selects date/time
   │
   ▼
4. JavaScript submits booking
   AJAX: snab_book_appointment
   {
       action: "snab_book_appointment",
       nonce: "xxx",
       type_id: 1,
       date: "2026-01-15",
       time: "14:00"
   }
   │
   ▼
5. class-snab-frontend-ajax.php handles request
   ├── Verifies nonce
   ├── Validates input
   ├── Creates appointment record
   ├── Syncs to Google Calendar
   └── Sends confirmation email
   │
   ▼
6. Returns result
   { success: true, data: { ... } }
```

## Google Calendar Sync

### Per-Staff Connection (Required for Sync)

Each staff member must have their own Google Calendar connected:

```php
// Check if staff has calendar connected
if ($google->is_staff_connected($staff_id)) {
    // Create event in their calendar
    $event_id = $google->create_staff_event($staff_id, [
        'summary' => $appointment_title,
        'start' => $start_datetime,  // Must include seconds!
        'end' => $end_datetime,
        'description' => $notes
    ]);
}
```

### DateTime Format

Google Calendar requires RFC3339 format with seconds:

```php
// Input from form: "2026-01-15" and "14:00"

// CORRECT: Add seconds
$start_datetime = $date . 'T' . $time . ':00';
// Result: "2026-01-15T14:00:00"

// WRONG: Missing seconds (causes "Bad Request")
$start_datetime = $date . 'T' . $time;
// Result: "2026-01-15T14:00"
```

## Cancellation Flow

### iOS

```
POST /snab/v1/appointments/{id}/cancel
Authorization: Bearer {token}
```

### Web

```
AJAX: snab_cancel_appointment
{
    action: "snab_cancel_appointment",
    nonce: "xxx",
    appointment_id: 42
}
```

### Both Paths Must:

1. Check cancellation policy (hours before)
2. Update appointment status to "cancelled"
3. Delete Google Calendar event
4. Send cancellation email

## Reschedule Flow

### iOS

```
PATCH /snab/v1/appointments/{id}/reschedule
Authorization: Bearer {token}
{
    "date": "2026-01-20",
    "time": "15:00"
}
```

### Both Paths Must:

1. Check reschedule policy
2. Check max reschedules not exceeded
3. Update appointment date/time
4. Update Google Calendar event
5. Send reschedule confirmation email

## Guest Booking

Appointments can be created without authentication:

- iOS: No Authorization header required
- Web: No user logged in

Guest bookings require:
- Email address
- Phone number

These are stored in the appointment record for follow-up.

## Agent Booking on Behalf of Clients (v1.9.7)

Agents can book appointments on behalf of their assigned clients. The iOS app shows a client picker when the logged-in user is an agent with assigned clients.

### iOS Flow

```
1. Agent opens booking form
   │
   ▼
2. AppointmentViewModel.loadAgentClients()
   GET /mld-mobile/v1/agent/clients
   │
   ▼
3. ContactDetailsView shows client picker
   ├── "Myself" option (default)
   ├── Horizontal scrolling client chips
   └── Selecting a client auto-fills name/email/phone
   │
   ▼
4. Booking submitted with client's contact info
   │
   ▼
5. Server associates appointment with client user account
   - Looks up user by client_email
   - If found, sets user_id to client's WordPress user ID
   - If not found, falls back to agent's user ID
```

### Server-Side User Association

Both REST API and AJAX paths include client email lookup:

```php
// Determine user_id for the appointment
// Priority: 1) User matching client_email, 2) Currently logged-in user
$user_id = 0;

// First, check if client_email matches a registered user
$client_user = get_user_by('email', $client_email);
if ($client_user) {
    $user_id = $client_user->ID;
} elseif (is_user_logged_in()) {
    // Fall back to currently logged-in user
    $user_id = get_current_user_id();
}
```

This allows:
- Clients to see appointments booked on their behalf in their account
- Agents to book for clients without needing to log in as the client
- Proper appointment history for both agents and clients

## Key Differences Between Paths

| Feature | iOS (REST) | Web (AJAX) |
|---------|------------|------------|
| Auth | JWT token | WordPress nonce |
| Response | JSON object | JSON with `success` boolean |
| Errors | HTTP status codes | Error in response body |
| User ID | From token | From WordPress session |

## Testing Checklist

- [ ] Book via iOS → Google Calendar sync
- [ ] Book via Web → Google Calendar sync
- [ ] Cancel via iOS → Calendar event deleted
- [ ] Cancel via Web → Calendar event deleted
- [ ] Reschedule via iOS → Calendar event updated
- [ ] Guest booking works (no auth)
- [ ] Confirmation emails sent
