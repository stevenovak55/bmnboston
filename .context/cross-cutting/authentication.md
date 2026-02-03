# Authentication

JWT-based authentication for the mobile app and API access.

## Token Configuration

| Token Type | Expiry | Storage |
|------------|--------|---------|
| Access Token | 30 days | Keychain (iOS) |
| Refresh Token | 30 days | Keychain (iOS) |

**Note:** Extended from 15 min/7 days to 30 days (v6.50.8) to prevent unexpected logouts. Safe for this app since no financial transactions occur and property data is public.

## Authentication Flow

### Login

```
POST /wp-json/mld-mobile/v1/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123"
}
```

Response:
```json
{
    "success": true,
    "data": {
        "access_token": "eyJ...",
        "refresh_token": "eyJ...",
        "user": {
            "id": 123,
            "email": "user@example.com",
            "name": "John Doe",
            "first_name": "John",
            "last_name": "Doe",
            "phone": "555-123-4567",
            "avatar_url": "https://...",
            "user_type": "client",
            "assigned_agent": { ... }
        }
    }
}
```

### Registration

```
POST /wp-json/mld-mobile/v1/auth/register
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123",
    "first_name": "John",
    "last_name": "Doe",
    "phone": "555-123-4567",
    "referral_code": "ABC123"
}
```

**Parameters:**
- `email` (required): User's email address
- `password` (required): Minimum 6 characters
- `first_name` (required): User's first name
- `last_name` (optional): User's last name
- `phone` (optional): Phone number
- `referral_code` (optional): Agent referral code - assigns user to referring agent

**Backwards Compatibility:** Also accepts `name` parameter (single combined field). If `first_name`/`last_name` not provided, parses `name` into parts.

Response: Same format as login response.

### Token Refresh

```
POST /wp-json/mld-mobile/v1/auth/refresh
Content-Type: application/json

{
    "refresh_token": "eyJ..."
}
```

Response:
```json
{
    "success": true,
    "data": {
        "access_token": "new_eyJ..."
    }
}
```

### Using Access Token

```
GET /wp-json/mld-mobile/v1/favorites
Authorization: Bearer eyJ...
```

## iOS Implementation

### TokenManager

Located in `Core/Networking/TokenManager.swift`:

```swift
class TokenManager {
    static let shared = TokenManager()

    var accessToken: String? {
        KeychainManager.shared.get("access_token")
    }

    var refreshToken: String? {
        KeychainManager.shared.get("refresh_token")
    }

    func refreshIfNeeded() async throws {
        // Check if access token expired
        // If so, use refresh token to get new access token
    }
}
```

### KeychainManager

Located in `Core/Storage/KeychainManager.swift`:
- Stores tokens securely in iOS Keychain
- Handles token retrieval and deletion

### APIClient Integration

Located in `Core/Networking/APIClient.swift`:
- Automatically adds Authorization header if token exists
- Handles 401 responses by triggering token refresh
- Retries request after successful refresh

## WordPress Implementation

### JWT Token Generation

Located in `class-mld-mobile-rest-api.php`:

```php
function generate_jwt_token($user_id, $type = 'access') {
    $secret = get_option('mld_jwt_secret');
    $expiry = $type === 'access' ? 15 * 60 : 7 * 24 * 60 * 60;

    $payload = [
        'iss' => get_site_url(),
        'iat' => time(),
        'exp' => time() + $expiry,
        'sub' => $user_id,
        'type' => $type
    ];

    return jwt_encode($payload, $secret);
}
```

### Token Validation

```php
function validate_jwt_token($token) {
    $secret = get_option('mld_jwt_secret');

    try {
        $payload = jwt_decode($token, $secret);

        if ($payload->exp < time()) {
            return new WP_Error('token_expired', 'Token has expired');
        }

        return $payload;
    } catch (Exception $e) {
        return new WP_Error('invalid_token', 'Invalid token');
    }
}
```

## Guest Mode

Some endpoints work without authentication:

| Endpoint | Auth Required |
|----------|---------------|
| `GET /properties` | No |
| `GET /properties/{id}` | No |
| `GET /search/autocomplete` | No |
| `GET /favorites` | Yes |
| `POST /favorites` | Yes |
| `GET /saved-searches` | Yes |
| `POST /appointments` | Optional (guest booking allowed) |

## Security Considerations

### Rate Limiting

- Login attempts: 5 per 15 minutes per IP
- After exceeding: 15-minute lockout

### Password Requirements

- Minimum 8 characters
- Validated by WordPress default rules

### Token Storage

- **Never** store tokens in UserDefaults
- **Always** use iOS Keychain
- Clear tokens on logout

## Demo Account

For testing:
- Email: `demo@bmnboston.com`
- Password: `demo1234`

## Testing Authentication

```bash
# Login
curl -X POST "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d '{"email":"demo@bmnboston.com","password":"demo1234"}'

# Get token for scripting
TOKEN=$(curl -s "https://bmnboston.com/wp-json/mld-mobile/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d '{"email":"demo@bmnboston.com","password":"demo1234"}' | \
    python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")

# Use token
curl "https://bmnboston.com/wp-json/mld-mobile/v1/favorites" \
    -H "Authorization: Bearer $TOKEN"
```
