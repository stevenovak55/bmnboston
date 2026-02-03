# iOS Services & Networking

Service layer architecture for the BMN Boston iOS app.

**Last Updated:** January 2, 2026

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                         Views                                │
│  PropertySearchView, PropertyDetailView, BookAppointmentView │
└─────────────────────────┬───────────────────────────────────┘
                          │ @StateObject
┌─────────────────────────▼───────────────────────────────────┐
│                      ViewModels                              │
│   PropertySearchViewModel, AuthViewModel, AppointmentsVM     │
└─────────────────────────┬───────────────────────────────────┘
                          │ async/await
┌─────────────────────────▼───────────────────────────────────┐
│                      APIClient                               │
│         Handles all HTTP requests to WordPress API           │
└─────────────────────────┬───────────────────────────────────┘
                          │
┌─────────────────────────▼───────────────────────────────────┐
│                    TokenManager                              │
│         Manages JWT access/refresh tokens in Keychain        │
└─────────────────────────────────────────────────────────────┘
```

---

## APIClient

**File:** `Core/Networking/APIClient.swift`

Central HTTP client handling all API requests.

### Key Features

1. **Async/await**: Modern Swift concurrency
2. **Automatic token refresh**: Handles 401 responses
3. **Custom date decoding**: Multiple date formats from PHP
4. **Error handling**: Typed `APIError` responses

### Basic Usage

```swift
let client = APIClient()

// GET request
let properties = try await client.request(.properties(filters: filters))

// POST request with body
let appointment = try await client.request(.createAppointment(data))

// Authenticated request (auto-includes JWT)
let favorites = try await client.request(.favorites)
```

### Request Flow

1. Build `URLRequest` from `APIEndpoint`
2. Add authentication header if token exists
3. Execute request with `URLSession`
4. Check for 401 → attempt token refresh
5. Decode response using custom `JSONDecoder`
6. Return typed result or throw `APIError`

### Date Decoder Configuration

```swift
// APIClient.swift lines 28-57
let decoder = JSONDecoder()
decoder.dateDecodingStrategy = .custom { decoder in
    // Try ISO8601 with fractional seconds
    // Try ISO8601 with timezone offset (PHP format)
    // Try MySQL datetime format
    // Throw if none match
}
```

---

## TokenManager

**File:** `Core/Networking/TokenManager.swift`

Secure JWT token storage using iOS Keychain.

### Token Types

| Token | Purpose | Expiry |
|-------|---------|--------|
| Access Token | API authentication | 15 minutes |
| Refresh Token | Get new access token | 30 days |

### Key Methods

```swift
class TokenManager {
    static let shared = TokenManager()

    func saveTokens(access: String, refresh: String)
    func getAccessToken() -> String?
    func getRefreshToken() -> String?
    func clearTokens()
    var isAuthenticated: Bool { get }
}
```

### Token Refresh Flow

1. API returns 401 Unauthorized
2. APIClient checks if refresh token exists
3. Calls `/auth/refresh` with refresh token
4. Saves new access token
5. Retries original request
6. If refresh fails → clear tokens, redirect to login

---

## Environment

**File:** `App/Environment.swift`

API base URL configuration.

```swift
enum AppEnvironment {
    case development  // localhost:8080
    case staging      // staging.bmnboston.com
    case production   // bmnboston.com

    static var current: AppEnvironment {
        // Always returns .production for all builds
        return .production
    }

    var baseURL: URL {
        URL(string: "https://bmnboston.com/wp-json")!
    }

    var apiNamespace: String {
        "mld-mobile/v1"
    }
}
```

**Note:** All builds (Debug and Release) use production API. No localhost testing.

---

## APIEndpoint

**File:** `Core/Networking/APIEndpoint.swift`

Defines all API endpoints as enum cases.

### Property Endpoints

```swift
case properties(filters: PropertyFilters)  // GET /properties
case propertyDetail(key: String)           // GET /properties/{key}
case autocomplete(term: String)            // GET /search/autocomplete
```

### Authentication Endpoints

```swift
case login(email: String, password: String)
case register(data: RegisterRequest)
case refreshToken(token: String)
case logout
```

### User Endpoints

```swift
case favorites                             // GET /favorites
case addFavorite(listingKey: String)       // POST /favorites
case removeFavorite(listingKey: String)    // DELETE /favorites/{key}
case savedSearches                         // GET /saved-searches
case createSavedSearch(data: SavedSearchRequest)
```

### Appointment Endpoints

```swift
case appointmentTypes                      // GET /appointment-types
case staff                                 // GET /staff
case availableSlots(staffId: Int, date: String)
case createAppointment(data: BookAppointmentRequest)
case myAppointments                        // GET /appointments
case cancelAppointment(id: Int)            // DELETE /appointments/{id}
```

### School Endpoints

```swift
case schoolsNearProperty(lat: Double, lng: Double, radius: Double)
case schoolDetail(id: Int)
case schoolGlossary
```

---

## Error Handling

**File:** `Core/Networking/APIError.swift`

```swift
enum APIError: Error {
    case invalidURL
    case noData
    case decodingError(Error)
    case serverError(code: String, message: String, status: Int)
    case unauthorized
    case networkError(Error)
    case unknown
}
```

### Error Display

ViewModels convert `APIError` to user-friendly messages:

```swift
func errorMessage(for error: APIError) -> String {
    switch error {
    case .unauthorized:
        return "Please log in to continue"
    case .networkError:
        return "Unable to connect. Check your internet connection."
    case .serverError(_, let message, _):
        return message
    default:
        return "Something went wrong. Please try again."
    }
}
```

---

## ViewModel Patterns

### Search ViewModel (PropertySearchViewModel)

**File:** `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift`

Manages property search state and API calls.

```swift
@MainActor
class PropertySearchViewModel: ObservableObject {
    @Published var properties: [Property] = []
    @Published var isLoading = false
    @Published var error: APIError?
    @Published var filters = PropertyFilters()

    private var searchTask: Task<Void, Never>?
    private let client = APIClient()

    func search() async {
        // Note: Don't cancel searchTask here - callers handle cancellation
        isLoading = true
        do {
            let response = try await client.request(.properties(filters: filters))
            properties = response.properties
        } catch let error as APIError {
            self.error = error
        }
        isLoading = false
    }
}
```

**Critical:** Never cancel `searchTask` from within `search()`. See [Troubleshooting](troubleshooting.md#task-self-cancellation).

### Auth ViewModel

**File:** `Features/Authentication/ViewModels/AuthViewModel.swift`

Manages login state and token lifecycle.

```swift
@MainActor
class AuthViewModel: ObservableObject {
    @Published var isAuthenticated = false
    @Published var isGuestMode = false
    @Published var currentUser: User?

    var canAccessApp: Bool {
        isAuthenticated || isGuestMode
    }

    func login(email: String, password: String) async throws {
        let response = try await client.request(.login(email: email, password: password))
        TokenManager.shared.saveTokens(access: response.accessToken, refresh: response.refreshToken)
        currentUser = response.user
        isAuthenticated = true
    }
}
```

---

## Request/Response Logging

Debug builds include request/response logging:

```swift
#if DEBUG
print("➡️ \(method) \(url)")
print("📦 Request: \(body)")
print("⬅️ Response: \(statusCode)")
print("📄 Data: \(responseString)")
#endif
```

---

## Related Documentation

- [Models](models.md) - Data model definitions
- [Build & Deploy](build-deploy.md) - Building the iOS app
- [Troubleshooting](troubleshooting.md) - Common iOS issues
- [API Responses](../../plugins/mls-listings-display/api-responses.md) - WordPress API formats
