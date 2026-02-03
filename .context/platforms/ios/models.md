# iOS Data Models

Swift data models for the BMN Boston iOS app, mapped from WordPress REST API responses.

**Last Updated:** January 2, 2026

---

## Core Models Overview

| Model | File | Purpose |
|-------|------|---------|
| Property | `Core/Models/Property.swift` | Property listings with full details |
| User | `Core/Models/User.swift` | Authentication and user profile |
| School | `Core/Models/School.swift` | School data with rankings |
| Appointment | `Core/Models/Appointment.swift` | Booking appointments |
| SavedSearch | `Core/Models/SavedSearch.swift` | Saved search criteria |

---

## Codable Patterns

### Snake Case to CamelCase Mapping

All models use explicit `CodingKeys` to map from WordPress snake_case to Swift camelCase:

```swift
struct Property: Codable {
    let listingKey: String
    let listPrice: Decimal
    let daysOnMarket: Int

    enum CodingKeys: String, CodingKey {
        case listingKey = "listing_key"
        case listPrice = "list_price"
        case daysOnMarket = "days_on_market"
    }
}
```

### Date Decoding

The `APIClient` uses a custom date decoder that handles multiple formats from the API:

1. **ISO8601 with fractional seconds**: `2025-12-24T12:30:00.000Z`
2. **ISO8601 with timezone offset**: `2025-12-24T12:30:00+00:00` (PHP format)
3. **MySQL datetime**: `2025-12-24 12:30:00`

See `APIClient.swift:28-57` for implementation.

### Optional vs Required

- Required fields that may be null in API: Use optionals with defaults
- Fields that should always exist: Required, decode failure is an error

```swift
// API may return null - use optional
let clientPhone: String?

// API always provides - required
let id: Int
let date: String
```

---

## Property Models

### Property (List View)

Used for search results and list display. Lightweight version.

| Field | Type | Notes |
|-------|------|-------|
| listing_key | String | MD5 hash, used for API lookups |
| listing_id | String | MLS number |
| list_price | Decimal | Current asking price |
| beds | Int | Alias for bedrooms_total |
| baths | Double | Full + half baths |
| sqft | Int? | Living area square footage |
| city | String | City name |
| latitude/longitude | Double | Map coordinates |
| main_photo_url | URL? | Primary listing photo |

### PropertyDetail (Detail View)

Extended property with full details loaded from `/properties/{listing_key}`.

Additional fields:
- `photos`: Array of all listing photos
- `description`: Full property description
- `features`: Amenities and features
- `schools`: Nearby schools with distances
- `agent`/`office`: Listing agent contact info

---

## School Models

### School

```swift
struct School: Codable, Identifiable {
    let id: Int
    let name: String
    let schoolType: String       // Elementary, Middle, High
    let letterGrade: String?     // A+, A, B+, etc.
    let compositeScore: Double?  // 0-100 ranking score
    let distanceInMiles: Double
    let districtName: String?

    enum CodingKeys: String, CodingKey {
        case id, name
        case schoolType = "school_type"
        case letterGrade = "letter_grade"
        case compositeScore = "composite_score"
        case distanceInMiles = "distance"
        case districtName = "district_name"
    }
}
```

---

## Appointment Models

### Appointment

```swift
struct Appointment: Codable, Identifiable {
    let id: Int
    let staffId: Int
    let appointmentTypeId: Int
    let status: AppointmentStatus  // pending, confirmed, cancelled
    let date: String               // YYYY-MM-DD
    let startTime: String          // HH:MM
    let endTime: String
    let clientName: String
    let clientEmail: String
    let clientPhone: String?
    let listingId: String?
    let propertyAddress: String?
}

enum AppointmentStatus: String, Codable {
    case pending, confirmed, cancelled, completed
    case noShow = "no_show"
}
```

### TimeSlot

Available booking times returned from `/slots` endpoint:

```swift
struct TimeSlot: Codable, Identifiable {
    let time: String     // "09:00", "09:30", etc.
    let available: Bool

    var id: String { time }
}
```

---

## Authentication Models

### AuthResponse

Response from `/auth/login` and `/auth/register`:

```swift
struct AuthResponseData: Codable {
    let accessToken: String
    let refreshToken: String
    let expiresIn: Int  // Seconds until access token expires
    let user: User

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case expiresIn = "expires_in"
        case user
    }
}
```

---

## Pagination

Property list responses include pagination:

```swift
struct PropertyListData: Codable {
    let properties: [Property]
    let total: Int
    let page: Int
    let perPage: Int
    let totalPages: Int

    var hasNextPage: Bool { page < totalPages }
}
```

---

## Common Gotchas

1. **Property ID type**: API returns string `listing_key` (MD5 hash), not integer ID
2. **Baths as Double**: API returns `1.5` for 1 full + 1 half bath
3. **Price as Decimal**: Use `Decimal` not `Double` to avoid floating point issues
4. **Dates as Strings**: Most date fields come as strings, not `Date` objects
5. **Null vs Missing**: Some fields may be `null` vs missing entirely - handle both

---

## Related Documentation

- [Services](services.md) - API client and service layer
- [Build & Deploy](build-deploy.md) - Building the iOS app
- [Troubleshooting](troubleshooting.md) - Common iOS issues
