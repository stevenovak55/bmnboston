# API Response Schemas

Actual API response formats for MLS Listings Display endpoints.

**Base URL:** `https://bmnboston.com/wp-json/mld-mobile/v1`

**Last Updated:** January 1, 2026

---

## GET /properties

### Request
```
GET /properties?city=Boston&beds=3&per_page=20&page=1
```

### Response (Success)
```json
{
  "properties": [
    {
      "id": "a1b2c3d4e5f6...",
      "listing_id": "12345678",
      "price": 850000,
      "beds": 3,
      "baths": 2.5,
      "sqft": 2100,
      "lot_size": 5000,
      "year_built": 1920,
      "address": "123 Main Street",
      "city": "Boston",
      "state": "MA",
      "zip": "02101",
      "neighborhood": "Back Bay",
      "latitude": 42.3520,
      "longitude": -71.0700,
      "status": "Active",
      "property_type": "Residential",
      "dom": 14,
      "list_date": "2025-12-15",
      "price_reduced": false,
      "original_price": 850000,
      "photos": [
        "https://photos.bmnboston.com/12345678/photo1.jpg",
        "https://photos.bmnboston.com/12345678/photo2.jpg"
      ],
      "agent": {
        "name": "John Smith",
        "phone": "617-555-1234",
        "email": "john@example.com",
        "photo": "https://..."
      },
      "office": {
        "name": "ABC Realty",
        "phone": "617-555-0000"
      },
      "school_grade": "A",
      "has_open_house": false
    }
  ],
  "total": 1234,
  "page": 1,
  "per_page": 20,
  "total_pages": 62
}
```

### Field Notes

| Field | Type | Notes |
|-------|------|-------|
| `id` | string | MD5 hash of listing_key (use for API lookups) |
| `listing_id` | string | MLS number |
| `price` | int | Current list price |
| `baths` | float | Can be decimal (2.5 = 2 full + 1 half) |
| `status` | string | "Active", "Pending", "Sold" (API returns "Sold", DB stores "Closed") |
| `dom` | int | Days on market |
| `photos` | array | Can be strings OR objects with url/caption/order |
| `school_grade` | string | Optional, only if school data available |
| `agent` | object | Can be null if no agent assigned |

### Photos Format Variations

**String array (common):**
```json
"photos": ["url1", "url2", "url3"]
```

**Object array (sometimes):**
```json
"photos": [
  {"url": "url1", "caption": "Front", "order": 0},
  {"url": "url2", "caption": "Kitchen", "order": 1}
]
```

**iOS must handle both formats:**
```swift
if let photoStrings = try? container.decodeIfPresent([String].self, forKey: .photos) {
    photos = photoStrings
} else if let photoObjects = try? container.decodeIfPresent([PhotoObject].self, forKey: .photos) {
    photos = photoObjects.map { $0.url }
}
```

---

## GET /properties/{id}

### Request
```
GET /properties/a1b2c3d4e5f6...
```

### Response (Success)
```json
{
  "id": "a1b2c3d4e5f6...",
  "listing_id": "12345678",
  "price": 850000,
  "beds": 3,
  "baths": 2.5,
  "sqft": 2100,
  "lot_size": 5000,
  "year_built": 1920,
  "address": "123 Main Street",
  "city": "Boston",
  "state": "MA",
  "zip": "02101",
  "neighborhood": "Back Bay",
  "latitude": 42.3520,
  "longitude": -71.0700,
  "status": "Active",
  "property_type": "Residential",
  "dom": 14,
  "list_date": "2025-12-15",
  "photos": ["url1", "url2", "url3"],
  "agent": {
    "name": "John Smith",
    "phone": "617-555-1234",
    "email": "john@example.com",
    "photo": "https://..."
  },
  "office": {
    "name": "ABC Realty",
    "phone": "617-555-0000"
  },
  "description": "Beautiful 3-bedroom home in Back Bay...",
  "features": {
    "garage_spaces": 2,
    "parking_total": 3,
    "pool": false,
    "fireplace": true,
    "waterfront": false,
    "basement": true,
    "cooling": "Central Air",
    "heating": "Forced Air"
  },
  "rooms": [
    {"name": "Living Room", "level": "First", "dimensions": "15x20"},
    {"name": "Kitchen", "level": "First", "dimensions": "12x15"}
  ],
  "schools": [
    {
      "id": 123,
      "name": "Boston Latin School",
      "level": "High",
      "distance": 0.8,
      "letter_grade": "A",
      "composite_score": 92.5
    }
  ],
  "open_houses": [
    {
      "date": "2025-12-20",
      "start_time": "12:00",
      "end_time": "14:00"
    }
  ]
}
```

### Response (Not Found)
```json
{
  "code": "property_not_found",
  "message": "Property not found",
  "data": {
    "status": 404
  }
}
```

---

## GET /search/autocomplete

### Request
```
GET /search/autocomplete?term=boston
```

### Response (Success)
```json
[
  {
    "type": "city",
    "value": "Boston",
    "label": "Boston, MA",
    "count": 1234
  },
  {
    "type": "neighborhood",
    "value": "Boston - Back Bay",
    "label": "Back Bay, Boston",
    "count": 89
  },
  {
    "type": "zip",
    "value": "02101",
    "label": "02101 (Boston)",
    "count": 45
  },
  {
    "type": "address",
    "value": "123 Boston Street",
    "label": "123 Boston Street, Cambridge",
    "listing_id": "12345678"
  }
]
```

**Note:** Response is array directly, NOT wrapped in object.

---

## POST /auth/login

### Request
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

### Response (Success)
```json
{
  "success": true,
  "user": {
    "id": 123,
    "email": "user@example.com",
    "name": "John Doe",
    "display_name": "John"
  },
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires_in": 900
}
```

### Response (Invalid Credentials)
```json
{
  "code": "invalid_credentials",
  "message": "Invalid email or password",
  "data": {
    "status": 401
  }
}
```

### Token Expiry
- Access Token: 15 minutes (900 seconds)
- Refresh Token: 7 days

---

## POST /auth/refresh

### Request
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
}
```

### Response (Success)
```json
{
  "success": true,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires_in": 900
}
```

---

## GET /saved-searches

**Requires Authentication**

### Response (Success)
```json
{
  "searches": [
    {
      "id": 456,
      "name": "Back Bay Condos",
      "filters": {
        "city": ["Boston"],
        "neighborhood": ["Back Bay"],
        "property_type": "Condo",
        "min_price": 500000,
        "max_price": 1000000,
        "beds": 2
      },
      "notification_frequency": "daily",
      "created_at": "2025-12-01T10:30:00+00:00",
      "updated_at": "2025-12-15T14:20:00+00:00",
      "match_count": 23
    }
  ]
}
```

**Date Format:** ISO8601 with timezone offset (2025-12-26T09:18:36+00:00)

---

## POST /saved-searches

**Requires Authentication**

### Request
```json
{
  "name": "My Search",
  "filters": {
    "city": ["Boston"],
    "beds": 3,
    "min_price": 400000
  },
  "notification_frequency": "daily"
}
```

### Response (Success)
```json
{
  "success": true,
  "search": {
    "id": 457,
    "name": "My Search",
    "filters": {
      "city": ["Boston"],
      "beds": 3,
      "min_price": 400000
    },
    "notification_frequency": "daily",
    "created_at": "2025-12-28T10:00:00+00:00"
  }
}
```

---

## GET /favorites

**Requires Authentication**

### Response (Success)
```json
{
  "favorites": [
    {
      "id": "a1b2c3d4...",
      "listing_id": "12345678",
      "price": 850000,
      "beds": 3,
      "baths": 2.5,
      "address": "123 Main Street",
      "city": "Boston",
      "photos": ["url1"],
      "status": "Active",
      "added_at": "2025-12-20T15:30:00+00:00"
    }
  ],
  "total": 5
}
```

---

## POST /favorites/{id}

**Requires Authentication**

### Response (Success)
```json
{
  "success": true,
  "message": "Property added to favorites"
}
```

---

## DELETE /favorites/{id}

**Requires Authentication**

### Response (Success)
```json
{
  "success": true,
  "message": "Property removed from favorites"
}
```

---

## Error Response Format

All errors follow this format:

```json
{
  "code": "error_code",
  "message": "Human-readable message",
  "data": {
    "status": 400
  }
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `invalid_credentials` | 401 | Wrong email/password |
| `token_expired` | 401 | Access token expired |
| `token_invalid` | 401 | Malformed token |
| `unauthorized` | 401 | Authentication required |
| `property_not_found` | 404 | Property ID not found |
| `validation_error` | 400 | Invalid request parameters |
| `server_error` | 500 | Internal server error |

---

## Schools API Responses

**Base URL:** `https://bmnboston.com/wp-json/bmn-schools/v1`

### GET /property/schools

```
GET /property/schools?lat=42.35&lng=-71.07&radius=3
```

```json
{
  "elementary": [
    {
      "id": 123,
      "name": "Lincoln Elementary",
      "distance": 0.5,
      "letter_grade": "A",
      "composite_score": 88.5,
      "enrollment": 450,
      "grades_served": "K-5"
    }
  ],
  "middle": [...],
  "high": [...]
}
```

### GET /health

```json
{
  "status": "ok",
  "schools_count": 2636,
  "districts_count": 342,
  "rankings_count": 4930,
  "latest_year": 2025,
  "last_sync": "2025-12-15T08:00:00+00:00"
}
```

---

## Appointments API Responses

**Base URL:** `https://bmnboston.com/wp-json/snab/v1`

### GET /availability

```
GET /availability?type_id=1&staff_id=2&date=2025-12-30
```

```json
{
  "date": "2025-12-30",
  "slots": [
    {"time": "09:00", "available": true},
    {"time": "09:30", "available": true},
    {"time": "10:00", "available": false},
    {"time": "10:30", "available": true}
  ]
}
```

### POST /appointments

```json
{
  "type_id": 1,
  "staff_id": 2,
  "date": "2025-12-30",
  "time": "09:00",
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "617-555-1234",
  "notes": "First-time buyer"
}
```

**Response:**
```json
{
  "success": true,
  "appointment": {
    "id": 789,
    "type": "Property Showing",
    "staff": "Jane Agent",
    "date": "2025-12-30",
    "start_time": "09:00",
    "end_time": "09:30",
    "status": "confirmed",
    "google_event_id": "abc123...",
    "ics_url": "https://bmnboston.com/wp-json/snab/v1/appointments/789/ics"
  }
}
```

### GET /appointments

**Requires Authentication**

```json
{
  "appointments": [
    {
      "id": 789,
      "type": "Property Showing",
      "staff": "Jane Agent",
      "date": "2025-12-30",
      "start_time": "09:00",
      "end_time": "09:30",
      "status": "confirmed",
      "property": {
        "address": "123 Main St",
        "city": "Boston"
      }
    }
  ]
}
```

---

## Notification Endpoints

### GET /notifications/history

**Requires Authentication**

Returns the user's notification history with deduplication.

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | 50 | Number of notifications to return |
| `page` | int | 1 | Page number for pagination |

```json
{
  "notifications": [
    {
      "id": 12345,
      "title": "New Listing Matches Your Search",
      "body": "A new property at 123 Main St, Boston matches your saved search.",
      "notification_type": "new_listing",
      "listing_id": "73464868",
      "saved_search_id": 42,
      "image_url": "https://bmnboston.com/wp-content/uploads/listing-photos/73464868-1.jpg",
      "created_at": "2026-01-21T10:30:00-05:00",
      "read_at": null
    },
    {
      "id": 12344,
      "title": "Price Reduced",
      "body": "Price reduced on 456 Oak Ave from $750,000 to $725,000",
      "notification_type": "price_change",
      "listing_id": "73464899",
      "saved_search_id": null,
      "image_url": null,
      "created_at": "2026-01-20T14:15:00-05:00",
      "read_at": "2026-01-20T15:00:00-05:00"
    },
    {
      "id": 12340,
      "title": "Appointment Reminder",
      "body": "Your property showing at 789 Elm St is tomorrow at 2:00 PM",
      "notification_type": "appointment_reminder",
      "listing_id": null,
      "appointment_id": 789,
      "image_url": null,
      "created_at": "2026-01-19T09:00:00-05:00",
      "read_at": null
    }
  ],
  "total": 25,
  "page": 1,
  "per_page": 50
}
```

**Notification Types:**
| Type | Description | Has listing_id | Has appointment_id |
|------|-------------|----------------|-------------------|
| `new_listing` | New property matches saved search | ✅ | ❌ |
| `price_change` | Price changed on watched property | ✅ | ❌ |
| `status_change` | Status changed on watched property | ✅ | ❌ |
| `appointment_reminder` | Upcoming appointment reminder | ❌ | ✅ |
| `appointment_confirmed` | Appointment confirmation | ❌ | ✅ |
| `appointment_cancelled` | Appointment cancellation | ❌ | ✅ |
| `general` | General notification | ❌ | ❌ |

**Deduplication Note:** Notifications are deduplicated by `(user_id, notification_type, listing_id, hour)` to prevent duplicates from multi-device push delivery.
