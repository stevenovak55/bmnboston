# Claude Code Reference - BMN Boston iOS App

Comprehensive reference for AI-assisted development.

**Current Project Version:** 391 (Marketing Version 1.6)
**Last Updated:** February 2, 2026

---

## MUST READ Before Modifying Complex Views

**Before making changes to `PropertyDetailView.swift` or any large SwiftUI view file, you MUST read:**

**[SwiftUI ViewBuilder Stack Overflow Guide](docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md)**

This documents a critical bug pattern that caused persistent app crashes and how to avoid it. Key points:
- Large `@ViewBuilder` functions with 15+ items can cause stack overflow at runtime
- The crash occurs during Swift type metadata resolution, not at compile time
- Solution: Extract complex view functions into separate `View` structs (opaque type boundaries)
- Watch for: Slow compilation, crashes on view appear, "expression too complex" errors

---

## Critical Rules

### 1. ALWAYS Update Version Numbers
When modifying code, increment `CURRENT_PROJECT_VERSION` in `project.pbxproj`:
```
CURRENT_PROJECT_VERSION = N+1;
```
There are 8 occurrences that get updated with replace_all (6 for main app + 2 for NotificationServiceExtension).

### 2. Bundle ID
**Use:** `com.bmnboston.app` (matches App Store Connect)

### 3. API Environment & Testing
**IMPORTANT:** All testing is done against **PRODUCTION** server, NOT localhost dev server.
- **API URL:** `https://bmnboston.com/wp-json/mld-mobile/v1`
- **Configured in:** `Environment.swift` (returns `.production` for all builds)
- **Test Device:** iPhone 16 Pro (Device ID: `00008140-00161D3A362A801C`)

### 4. Adding New Swift Files to Xcode Project
When creating new `.swift` files, you MUST manually add them to `project.pbxproj`:
1. Add `PBXBuildFile` entry in the build file section
2. Add `PBXFileReference` entry in the file reference section
3. Add file reference to appropriate group (e.g., Storage, Views, etc.)
4. Add build file reference to `PBXSourcesBuildPhase` files array

### 5. Task Self-Cancellation Bug (CRITICAL)

**Problem:** When a Task calls an async function that cancels `searchTask`, it cancels itself.

**Bug Pattern (DO NOT DO THIS):**
```swift
// In ViewModel
private var searchTask: Task<Void, Never>?

func toggleFilter() {
    searchTask?.cancel()  // Cancel previous task (good)
    searchTask = Task {   // Create new task
        await search()    // But search() cancels searchTask = THIS task!
    }
}

func search() async {
    searchTask?.cancel()  // BAD: This cancels the task that called this method!
    // ... API call ...
    guard !Task.isCancelled else { return }  // Silently exits, UI never updates
}
```

**Correct Pattern:**
```swift
// Callers handle cancellation BEFORE creating new tasks
func toggleFilter() {
    searchTask?.cancel()  // Cancel previous task
    searchTask = Task {
        await search()    // search() does NOT cancel searchTask
    }
}

func search() async {
    // Note: Don't cancel searchTask here - callers handle cancellation
    // Cancelling here would cancel the task that called this method
    // ... API call ...
}
```

**Rule:** Never cancel a Task reference from within an async function that was called by that Task.

### 6. APNs Sandbox vs Production (CRITICAL)

**APNs environment is determined by the provisioning profile, NOT the app receipt.**

| Build Type | Provisioning Profile | aps-environment | APNs Endpoint | is_sandbox |
|------------|---------------------|-----------------|---------------|------------|
| Xcode Debug | Development | development | sandbox | true |
| TestFlight | Distribution | **production** | **production** | **false** |
| App Store | Distribution | production | production | false |

**WRONG - Do NOT use receipt URL to detect APNs environment:**
```swift
// BAD: sandboxReceipt is for StoreKit, NOT APNs!
if let receiptURL = Bundle.main.appStoreReceiptURL {
    let isTestFlight = receiptURL.lastPathComponent == "sandboxReceipt"
    return isTestFlight  // WRONG: Returns true for TestFlight, but TestFlight uses production APNs
}
```

**CORRECT - Use build configuration:**
```swift
static func isAPNsSandbox() -> Bool {
    #if DEBUG
    return true   // Debug builds use development profile = sandbox APNs
    #else
    return false  // Release builds (TestFlight/App Store) use distribution profile = production APNs
    #endif
}
```

**Why this matters:**
- TestFlight builds have `sandboxReceipt` for **StoreKit** (in-app purchases)
- But TestFlight uses **distribution** provisioning profile with `aps-environment = production`
- Device tokens from distribution builds only work with production APNs
- Sending production tokens to sandbox APNs returns `BadDeviceToken`

**Server Configuration:**
- Server must use **production** APNs for TestFlight/App Store builds
- Set via: `wp option update mld_apns_environment production`
- Per-token routing uses `is_sandbox` column in `wp_mld_device_tokens` table

### 7. SwiftUI ViewBuilder Stack Overflow (CRITICAL)

**Full documentation:** [docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md](docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md)

Large `@ViewBuilder` functions with many nested views can cause **runtime stack overflow** during Swift type metadata resolution. This is NOT a compile-time error - the app will build successfully but crash when the view is displayed.

**Warning Signs:**
- Slow compilation of a single file
- `EXC_BAD_ACCESS (SIGSEGV)` crash with `swift_getTypeByMangledName` in stack trace
- Crashes immediately when a view appears (before any user interaction)
- "Expression too complex" compiler errors

**The Rule: Limit ViewBuilder complexity to ~10-15 top-level items.**

```swift
// BAD - Too many items in ViewBuilder (causes stack overflow)
func expandedContent() -> some View {
    VStack {
        section1()  // Each returns complex nested types
        section2()
        section3()
        // ... 20+ more sections
    }
}

// GOOD - Extract to View structs (creates opaque type boundaries)
struct ExpandedContentView: View {
    var body: some View {
        VStack {
            Section1View()  // Opaque type - runtime doesn't resolve internals
            Section2View()
            Section3View()
        }
    }
}
```

**Key Insight:** Each `struct SomeView: View` is an opaque type boundary. The Swift runtime doesn't need to resolve its internal `body` type when resolving the parent's type.

**Also Note - Button Style Ternary Operators Don't Work:**
```swift
// WRONG - Compilation error (different types)
.buttonStyle(condition ? PrimaryButtonStyle() : SecondaryButtonStyle())

// CORRECT - Use if/else
if condition {
    Button { }.buttonStyle(PrimaryButtonStyle())
} else {
    Button { }.buttonStyle(SecondaryButtonStyle())
}
```

### 8. Location Filter Types Require Multiple Touchpoints (NEW)

When adding a new location filter type (like `streetName`), you must update **6 different locations**:

| Location | File | Purpose |
|----------|------|---------|
| `hasLocationFilters` | `SearchModalView.swift` | Controls bubble section visibility in search modal |
| `locationFilterBubbles` | `SearchModalView.swift` | Renders bubble in search modal |
| `hasLocationFilters` | `PropertySearchView.swift` | Controls bubble section visibility in main view |
| `mapLocationFilterBubbles` | `PropertySearchView.swift` | Renders bubble in map view |
| `listLocationFilterBubbles` | `PropertySearchView.swift` | Renders bubble in list view |
| `activeFilterChips` | `Property.swift` | Shows in filter summary chips |
| `removeFilter()` | `Property.swift` | Handles chip removal |
| `iconForType` | `SearchModalView.swift` | Icon for the filter bubble |

**Also update for saved searches:**
| Location | File | Purpose |
|----------|------|---------|
| `toDictionary()` | `Property.swift` | Sends to API for queries |
| `toSavedSearchDictionary()` | `Property.swift` | Saves with saved searches |
| `fromServerJSON()` | `Property.swift` | Restores from saved searches |

**Example - Adding streetName bubble:**
```swift
// In LocationFilterBubble's iconForType:
case "Street": return "road.lanes"

// In hasLocationFilters:
|| (filters.streetName != nil && !filters.streetName!.isEmpty)

// In locationFilterBubbles view:
if let streetName = filters.streetName, !streetName.isEmpty {
    LocationFilterBubble(text: streetName, type: "Street", onRemove: { ... })
}
```

### 9. Empty Filter Chip Conditions (NEW)

When checking whether to display a filter chip, always check for **both non-empty AND non-default** values. Empty collections can pass "not equal to default" checks.

```swift
// WRONG - Empty set [] != [.active] is TRUE, creates chip with empty label
if statuses != [.active] {
    chips.append(FilterChip(id: "status", label: statuses.map { $0.displayName }.joined(separator: ", "), ...))
    // If statuses is [], label becomes "" → shows as empty colored circle
}

// CORRECT - Check non-empty first
if !statuses.isEmpty && statuses != [.active] {
    chips.append(FilterChip(id: "status", label: statuses.map { $0.displayName }.joined(separator: ", "), ...))
}
```

**Why this happens:** When selecting certain autocomplete suggestions (address, MLS#, street name), filters are reset and `statuses = []` is set. The condition `[] != [.active]` evaluates to `true`, creating a chip with an empty label that renders as just a colored circle.

### 10. Universal Links (iOS Deep Linking)

Universal Links allow web URLs to open directly in the app instead of Safari.

**Supported URL Patterns (v348):**
| URL Pattern | Opens |
|-------------|-------|
| `https://bmnboston.com/property/{mls_number}/` | Property detail |
| `https://bmnboston.com/listing/{mls_number}/` | Property detail |
| `https://bmnboston.com/saved-search/` | Saved searches list |
| `https://bmnboston.com/saved-search/{id}/` | Specific saved search |

**How It Works:**
1. **AASA File** - Server hosts Apple App Site Association at `/.well-known/apple-app-site-association`
2. **Entitlements** - App declares associated domains in `BMNBoston.entitlements`
3. **Handler** - `BMNBostonApp.swift` has `.onContinueUserActivity` handler that parses URLs

**Key Files:**
| File | Purpose |
|------|---------|
| `BMNBoston.entitlements` | Declares `applinks:bmnboston.com` |
| `BMNBostonApp.swift` | `handleUniversalLink()` parses URLs and navigates |
| Server: `/wp-content/mu-plugins/aasa.php` | Serves AASA JSON file |

**Adding New Universal Link Paths:**

1. **Update server AASA** (`/wp-content/mu-plugins/aasa.php`):
   ```php
   'components' => array(
       array('/' => '/property/*'),
       array('/' => '/new-path/*'),  // Add new path
   )
   ```

2. **Add iOS handler** in `BMNBostonApp.swift` `handleUniversalLink()`:
   ```swift
   if path.hasPrefix("/new-path/") {
       // Parse path and navigate
       NotificationCenter.default.post(name: .someNotification, object: nil)
       return
   }
   ```

3. **Wait for Apple CDN** - Apple caches AASA files for 24-48 hours

**Debugging:**
- Check Apple's cached AASA: `curl https://app-site-association.cdn-apple.com/a/v1/bmnboston.com`
- Check server AASA: `curl https://bmnboston.com/.well-known/apple-app-site-association`
- For development, add `?mode=developer` to entitlements to bypass Apple CDN (requires enabling "Associated Domains Development" in Settings > Developer on device)

**AASA Format (v2):**
```json
{
  "applinks": {
    "details": [{
      "appIDs": ["938F7PURZS.com.bmnboston.app"],
      "components": [
        { "/": "/property/*" },
        { "/": "/saved-search/*" }
      ]
    }]
  }
}
```

**Team ID:** `938F7PURZS` (must match in AASA `appIDs` and Xcode project)

---

## Key Files

| Purpose | File |
|---------|------|
| App Entry | `BMNBoston/App/BMNBostonApp.swift` |
| Environment | `BMNBoston/App/Environment.swift` |
| Main Tab View | `BMNBoston/App/MainTabView.swift` |
| Appearance Manager | `BMNBoston/Core/Storage/AppearanceManager.swift` |
| Colors (Adaptive) | `BMNBoston/UI/Styles/Colors.swift` |
| Models | `BMNBoston/Core/Models/Property.swift` |
| Appointment Models | `BMNBoston/Core/Models/Appointment.swift` |
| API Client | `BMNBoston/Core/Networking/APIClient.swift` |
| Endpoints | `BMNBoston/Core/Networking/APIEndpoint.swift` |
| Appointment Service | `BMNBoston/Core/Services/AppointmentService.swift` |
| Search View | `BMNBoston/Features/PropertySearch/Views/PropertySearchView.swift` |
| Detail View | `BMNBoston/Features/PropertySearch/Views/PropertyDetailView.swift` |
| Map View | `BMNBoston/Features/PropertySearch/Views/PropertyMapView.swift` |
| Full Screen Map Modal | `BMNBoston/Features/PropertySearch/Views/FullScreenPropertyMapView.swift` |
| Map Preview Section | `BMNBoston/Features/PropertySearch/Views/MapPreviewSection.swift` |
| ViewModel | `BMNBoston/Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` |
| Property Card | `BMNBoston/UI/Components/PropertyCard.swift` |
| Appointments View | `BMNBoston/Features/Appointments/Views/AppointmentsView.swift` |
| Appointment ViewModel | `BMNBoston/Features/Appointments/ViewModels/AppointmentViewModel.swift` |
| Book Appointment | `BMNBoston/Features/Appointments/Views/BookAppointmentView.swift` |
| Saved Properties View | `BMNBoston/Features/SavedProperties/Views/SavedPropertiesView.swift` |
| Hidden Properties View | `BMNBoston/Features/HiddenProperties/Views/HiddenPropertiesView.swift` |
| My Clients View | `BMNBoston/Features/AgentClients/Views/MyClientsView.swift` |
| Notification Service Extension | `NotificationServiceExtension/NotificationService.swift` |
| Push Notification Manager | `BMNBoston/Core/Services/PushNotificationManager.swift` |
| Notification Store | `BMNBoston/Core/Storage/NotificationStore.swift` |
| Referral Code Manager | `BMNBoston/Core/Storage/ReferralCodeManager.swift` |
| Notification Center View | `BMNBoston/Features/Notifications/Views/NotificationCenterView.swift` |
| Agent Referral View | `BMNBoston/Features/Profile/Views/AgentReferralView.swift` |

---

## Critical Lesson: Token Refresh Must Save User Data

**Problem (v194):** User logged in as mail@steve-novak.com, closed app, reopened and was logged in as steve@bmnboston.com.

**Root Cause:** When the app launches, `refreshToken()` is called to get a new access token. The refresh response includes user data, but `refreshToken()` only saved the tokens, not the user. If the original `User.save()` call during login had failed, the wrong (stale) user would persist.

**Fix:**
```swift
// In APIClient.refreshToken()
private func refreshToken() async throws {
    // ... get new tokens ...
    if let authData = authResponse.data {
        await TokenManager.shared.saveTokens(
            accessToken: authData.accessToken,
            refreshToken: authData.refreshToken
        )
        // CRITICAL: Also save user to ensure consistency
        authData.user.save()
    }
}
```

**Additional Fix:** Clear old state before new login to prevent confusion:
```swift
// In AuthViewModel.login()
func login(email: String, password: String) async {
    // Clear any existing tokens/user BEFORE login
    await TokenManager.shared.clearTokens()
    User.clearStorage()
    currentUser = nil

    // Now proceed with login...
}
```

**Rule:** Any method that receives user data from the server should save it, not just the primary login flow.

---

## Recent Changes (v390-v391)

### ActivityTracker Network Request & Flush Timer Fixes (Feb 2, 2026)

Fixed critical bug where iOS client activity (property views, searches, browsing behavior) was not being recorded in the database due to network request cancellation and flush timer design issues.

**Problem:** Network requests to `/analytics/activity/batch` were being cancelled with `NSURLErrorDomain Code=-999` ("cancelled"), and the flush timer kept resetting on each new event, preventing `flush()` from ever being called during active user sessions.

#### v390 - Task.detached for Network Isolation

**Root Cause:** When the parent Task was cancelled (e.g., user navigates away), the network request inherited that cancellation.

**Solution:** Use `Task.detached` to completely isolate the network request from the parent Task's cancellation:

```swift
// v390: Use a detached task to prevent request cancellation
let session = dedicatedSession
let result: (Data, URLResponse) = try await Task.detached {
    var request = URLRequest(url: url)
    request.httpMethod = "POST"
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
    request.httpBody = bodyData
    return try await session.data(for: request)
}.value
```

Also added a dedicated `URLSession` with ephemeral configuration to avoid shared session state issues.

#### v391 - Flush Timer Design Fix

**Root Cause:** `scheduleFlush()` was cancelling any existing scheduled task before creating a new one. With continuous user activity, the 30-second timer kept resetting and never completed.

**Before (bug):**
```
🔔 ActivityTracker.scheduleFlush(): Scheduling flush in 30.0s (queue: 1)
🔔 ActivityTracker.scheduleFlush(): Scheduling flush in 30.0s (queue: 2)
🔔 ActivityTracker.scheduleFlush(): Task was cancelled, skipping flush  ← Timer reset!
🔔 ActivityTracker.scheduleFlush(): Scheduling flush in 30.0s (queue: 3)
🔔 ActivityTracker.scheduleFlush(): Task was cancelled, skipping flush  ← Timer reset!
```

**Solution:** Don't cancel existing scheduled flush - let it run and batch all accumulated events:

```swift
private func scheduleFlush() {
    if isFlushInProgress { return }

    // v391: Don't cancel existing scheduled flush - let it run and batch all events
    if flushTask != nil {
        debugLog("🔔 ActivityTracker.scheduleFlush(): Flush already scheduled (queue: \(eventQueue.count))")
        return
    }

    flushTask = Task {
        try? await Task.sleep(nanoseconds: UInt64(flushInterval * 1_000_000_000))
        if Task.isCancelled { return }
        await flush()
    }
}
```

**After (fixed):**
```
🔔 ActivityTracker.scheduleFlush(): Scheduling flush in 30.0s (queue: 1)
🔔 ActivityTracker.scheduleFlush(): Flush already scheduled (queue: 2)
🔔 ActivityTracker.scheduleFlush(): Flush already scheduled (queue: 3)
🔔 ActivityTracker.scheduleFlush(): Timer fired, calling flush()
🔔 ActivityTracker: POST .../activity/batch with 4 activities
🔔 ActivityTracker: Response status 201
🔔 ActivityTracker: Server confirmed 4 activities recorded
```

**Files Changed:**
- `Core/Services/ActivityTracker.swift` - Added dedicated URLSession, Task.detached for network isolation, fixed flush timer design

**Key Lesson:** When designing batched event systems:
1. Use `Task.detached` to isolate network requests from parent Task cancellation
2. Don't reset timers on each new event - let the original timer complete and batch all accumulated events
3. Use a dedicated URLSession for analytics to avoid interference with other network activity

---

## Recent Changes (v385)

### Market Insights API Response Fix (Jan 30, 2026)

Fixed "Unable to load market data" error in the Market Insights section.

**Problem:** The Market Insights feature (added in v384) was showing "Unable to load market data" even though the API was returning valid data.

**Root Cause:** The `/mld/v1/property-analytics/{city}` endpoint returns raw JSON data directly, but `APIClient.request<T>()` expected responses wrapped in `{"success": true, "data": {...}}` format. The decoder was failing because it couldn't find the `success` and `data` fields in the raw response.

**Solution:** Added `requestRaw<T>()` method to APIClient that decodes responses directly without expecting the standard wrapper:

```swift
/// Request that decodes response directly without expecting the standard API wrapper.
/// Use for endpoints that return raw data (like /mld/v1/ namespace endpoints).
func requestRaw<T: Decodable>(_ endpoint: APIEndpoint) async throws -> T {
    // ... network request ...
    // Decode directly without wrapper
    return try decoder.decode(T.self, from: data)
}
```

Updated `loadMarketInsights()` in PropertyDetailView to use `requestRaw` instead of `request`.

**Files Changed:**
- `Core/Networking/APIClient.swift` - Added `requestRaw<T>()` method for non-wrapped API responses
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Use `requestRaw` for market insights API call

**When to Use `requestRaw` vs `request`:**
- **`request<T>()`**: Standard mobile API endpoints (`/mld-mobile/v1/`) that return `{success: true, data: {...}}`
- **`requestRaw<T>()`**: Web API endpoints (`/mld/v1/`) that return raw data without wrapper

---

## Recent Changes (v384)

### Market Insights Section (Jan 30, 2026)

Added "Market Insights" collapsible section to property detail pages showing city-level market analytics.

#### Features

- **Market Heat Indicator**: Shows market classification (Hot, Warm, Balanced, Cool) with color-coded badge and score
- **Key Stats Grid**: Displays median price, avg DOM, price/sqft, months of supply, YoY price change
- **12-Month Sales**: Shows count of homes sold in the last year for context

#### Implementation

1. **New Models** (`Property.swift`):
   - `CityMarketInsights`: Top-level response wrapper
   - `CityMarketSummary`: Contains market statistics with formatting helpers
   - `MarketHeatIndex`: Classification with icon and color name
   - `MarketInsightsMeta`: City and timestamp metadata

2. **New API Endpoint** (`APIEndpoint.swift`):
   - `cityMarketInsights(city:)` - Uses `/mld/v1/property-analytics/{city}` endpoint
   - Uses `useMldNamespace: true` flag for the different API namespace

3. **New Namespace Support** (`Environment.swift`, `APIClient.swift`):
   - Added `mldNamespace` and `mldAPIURL` properties
   - `APIClient.buildRequest()` now handles `useMldNamespace` flag

4. **New FactSection** (`PropertyDetailView.swift`):
   - Added `.marketInsights` case to `FactSection` enum
   - Added `marketInsightsSection(_:)` function with loading state
   - Added `marketStatCard(value:label:icon:)` helper
   - Added `colorForHeat(_:)` color name to Color converter

#### Files Changed
- `Core/Models/Property.swift` - New market insights models
- `Core/Networking/APIEndpoint.swift` - New cityMarketInsights endpoint, useMldNamespace flag
- `Core/Networking/APIClient.swift` - Support for mldNamespace in URL building
- `App/Environment.swift` - Added mldNamespace and mldAPIURL
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Market Insights section UI

---

## Recent Changes (v368-v371)

### Property Detail Layout Fix & Linked Text Enhancements (Jan 30, 2026)

**v368-v371:** Fixed property detail page layout issues where content was being cut off on the right side, and enhanced LinkedTextView with phone action sheet.

#### 1. Status Badge Layout Fix (v368-v371)

Fixed "Active" status badge and "Single Family Residence" property type being truncated/cut off on the right side of the screen.

**Problem:** Status badges in PriceSectionView were overflowing their container, causing text to be clipped.

**Solution (v370-v371):** Applied multiple layout constraints:
```swift
// ExpandedContentView ScrollView
.frame(maxWidth: .infinity)  // Ensure ScrollView fills available width

// VStack inside ScrollView
.frame(maxWidth: .infinity, alignment: .leading)  // Content fills width

// Price Row HStack - constrained badge width
VStack(alignment: .trailing, spacing: 4) {
    // Status badge
    Text(property.standardStatus.displayName)
        .lineLimit(1)
        // ...

    // Property type badge
    Text(property.propertySubtype ?? property.propertyType)
        .lineLimit(1)
        .minimumScaleFactor(0.7)
        // ...
}
.frame(maxWidth: 140)  // v370: Constrain to prevent overflow
```

#### 2. Phone Action Sheet (v369)

Enhanced LinkedTextView to show Call/Text action sheet when tapping phone numbers in agent remarks.

**Implementation:**
- Added `showPhoneActionSheet` and `selectedPhoneNumber` state variables
- Phone numbers in showing instructions, agent remarks, and office remarks are now tappable
- Tapping shows action sheet with "Call" and "Text" options
- Added `formatPhoneForDisplay()` helper for nice formatting: `(617) 555-1234`

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Layout fix, phone action sheet, LinkedTextView enhancements
- `project.pbxproj` - Version bump 367 → 371

---

## Recent Changes (v365-v367)

### ShowingTime Integration & Text Agent Button (Jan 30, 2026)

**v365-v367:** Added ShowingTime scheduling link detection and Text Agent button for agent users.

#### 1. Text Agent Button (v366)

Added "Text Agent" button alongside existing "Call Agent" button in PropertyDetailView for agent/admin users.

**Location:** `agentSectionContent()` in PropertyDetailView.swift

**Implementation:**
- Both buttons appear side-by-side in an HStack when agent's phone is available
- Call Agent: Opens `tel:` URL scheme
- Text Agent: Opens `sms:` URL scheme
- Only visible to agent/admin users (`isAgentUser`)

#### 2. ShowingTime Link Detection (v365-v367)

Auto-detects "ShowingTime" text in showing instructions, agent remarks, and office remarks, making it a tappable link that opens ShowingTime scheduling.

**Key Components:**

1. **TextSegment enum** - Added `.showingTime(String)` case for ShowingTime text segments

2. **LinkedTextView** - Parses text for phone numbers, emails, and ShowingTime references
   - Accepts `mlsNumber` and `agentMlsId` parameters
   - Generates ShowingTime URL when both parameters are available

3. **ShowingTime URL Format:**
   ```
   https://schedulingsso.showingtime.com/icons?siteid=PROP.MLSPIN.I&MLSID=MLSPIN&raid=[AGENT_MLS_ID]&listingid=[MLS_NUMBER]
   ```

4. **IMPORTANT: Uses Current User's MLS Agent ID (v367)**
   - The `agentMlsId` parameter uses `authViewModel.currentUser?.mlsAgentId`
   - This is the **logged-in agent's** MLS ID, NOT the listing agent's ID
   - The agent scheduling the showing needs THEIR ID in the URL

**User Model Update:**
```swift
struct User: Identifiable, Codable {
    // ... existing fields ...
    let mlsAgentId: String?  // MLS Agent ID for ShowingTime integration

    enum CodingKeys: String, CodingKey {
        // ...
        case mlsAgentId = "mls_agent_id"
    }
}
```

**Server-Side Support (MLD v6.72.4):**
- Added `get_user_mls_agent_id()` helper function
- Login and /me endpoints now return `mls_agent_id` field
- MLS Agent ID sourced from `wp_mld_agent_profiles.mls_agent_id` (synced from Team Members)

**Files Changed:**
- `Core/Models/User.swift` - Added `mlsAgentId` field
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Added LinkedTextView, TextSegment enum, Text Agent button
- `project.pbxproj` - Version bump 364 → 367, marketing version 1.5 → 1.6

#### WordPress Theme Changes

**Team Members MLS Agent ID Field:**
- Added "MLS Agent ID" field to Team Members edit screen (after License Number)
- Field syncs to `wp_mld_agent_profiles.mls_agent_id` when Team Member is saved
- Used for ShowingTime SSO URL generation

**Files:**
- `themes/flavor-flavor-flavor/inc/class-custom-post-types.php` - Added field and sync

#### MLD Plugin Fix (v6.72.4)

Fixed `$db_format` array in `MLD_Agent_Client_Manager::update_agent_profile()` - was missing format specifier for `mls_agent_id` field, causing sync to silently fail.

---

## Recent Changes (v362-v364)

### Saved Search Cross-Platform Compatibility (Jan 27, 2026)

**v364:** Bumped marketing version from 1.4 to 1.5 (TestFlight required new marketing version train).

**v362-v363:** Fixed iOS saved searches for cross-platform compatibility:

1. **Changed `beds` to `beds_min`** in `toSavedSearchDictionary()`:
   - iOS was saving exact bed selection as array (`beds: [3, 4]`)
   - Web and server normalize beds to minimum value (`beds_min: 3`)
   - This caused filter mismatch when web tried to use iOS-created searches

2. **Status filter fix**: Ensured status array is always saved (removed condition that skipped default "Active" status)

3. **Server-side URL generation (v6.72.3)**: Server now generates `search_url` for iOS-created saved searches so they work in admin panel

**Files Changed:**
- `Core/Models/Property.swift` - `toSavedSearchDictionary()` now uses `beds_min` key
- `project.pbxproj` - Version bump 361 → 362 → 363 → 364, marketing version 1.4 → 1.5

---

## Recent Changes (v360-v361)

### Open House Attendee Display Fix (Jan 24, 2026)

Fixed critical bug where open house attendees were not displaying when clicking "View Attendees" despite the API returning them correctly.

**Problem:** The open house list showed correct attendee counts (e.g., "2 attendees") but when clicking to view attendees, the list was empty.

**Root Cause:** The `OpenHouseAttendee` model had required fields that weren't in the API response:

1. **`openHouseId: Int`** - Required field, but API doesn't include `open_house_id` in attendee objects when returning detail response (it's implied from parent)
2. **`syncStatus: SyncStatus`** - Required field for offline tracking, but API doesn't return this
3. **`signedInAt: Date`** - API returns string `"2026-01-24 20:05:31"` but automatic decoder couldn't parse it

**API Response vs Model Mismatch:**
```json
// API returns:
{
  "id": 2,
  "local_uuid": "7DAF5710-18DF-4D60-9B29-183D8695A2AA",
  "first_name": "Steve",
  "signed_in_at": "2026-01-24 20:05:31"
  // Missing: open_house_id, sync_status
}
```

**Solution:** Added custom `init(from decoder:)` to `OpenHouseAttendee` that:
- Defaults `openHouseId` to 0 if not present
- Defaults `syncStatus` to `.synced` for server-retrieved data
- Parses date string manually with correct format and timezone
- Handles all optional fields gracefully with default values

**Key Code Addition (OpenHouse.swift):**
```swift
init(from decoder: Decoder) throws {
    let container = try decoder.container(keyedBy: CodingKeys.self)

    // openHouseId - may not be in the response, default to 0
    openHouseId = (try? container.decode(Int.self, forKey: .openHouseId)) ?? 0

    // signedInAt - handle date string format "yyyy-MM-dd HH:mm:ss"
    if let dateString = try? container.decode(String.self, forKey: .signedInAt) {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        formatter.timeZone = TimeZone(identifier: "America/New_York")
        signedInAt = formatter.date(from: dateString) ?? Date()
    } else {
        signedInAt = Date()
    }

    // syncStatus - not in API response, default to .synced for server data
    syncStatus = (try? container.decode(SyncStatus.self, forKey: .syncStatus)) ?? .synced

    // ... other fields with defaults
}
```

**Also in v360:**
- Fixed `PreApprovedStatus` typo (was `PreApprovedStatus`, corrected to `PreApprovalStatus`)
- Fixed `OpenHouseAttendee` memberwise initializer argument order

**Files Changed:**
- `Core/Models/OpenHouse.swift` - Added custom decoder to `OpenHouseAttendee`, added explicit memberwise initializer
- `Features/OpenHouse/Views/OpenHouseListView.swift` - Fixed typo and argument order
- `project.pbxproj` - Version bump 359 → 360 → 361

**Lesson Learned:** When iOS models have required fields that the API doesn't return, decoding fails silently and returns empty arrays. Always verify API response format matches model requirements, especially for:
- Fields only used for local tracking (`syncStatus`, `openHouseId` context)
- Date fields that may be strings instead of ISO8601
- UUIDs that may be strings instead of UUID type

---

## Recent Changes (v351)

### Hide Floor Layout Section When Data Is Limited (Jan 23, 2026)

Added logic to hide the Floor & Layout section in property details when room data is too sparse relative to the property size.

**Problem:** A 6-bedroom house might show only 2 rooms with level data, giving an incomplete and misleading floor diagram.

**Solution:** Added `hasAdequateFloorData()` helper function that compares rooms with level data to expected room count.

**Location:** `PropertyDetailView.swift` lines ~2053-2071

**Logic:**
```swift
private func hasAdequateFloorData(_ property: PropertyDetail, rooms: [Room]) -> Bool {
    let roomsWithLevel = rooms.filter { room in
        room.hasLevel == true || (room.level != nil && !room.level!.isEmpty)
    }.count

    let beds = property.beds
    let baths = Int(ceil(property.baths))
    let expectedKeyRooms = beds + baths

    // Minimum: at least 3 rooms with level, or half expected
    let minimumRooms = max(3, expectedKeyRooms / 2)

    // Lower threshold for small units (studio, 1-bed)
    let adjustedMinimum = expectedKeyRooms <= 3 ? 2 : minimumRooms

    return roomsWithLevel >= adjustedMinimum
}
```

**Thresholds:**
| Property | Beds | Baths | Min Rooms Needed |
|----------|------|-------|------------------|
| Small condo | 1 | 1 | 2 |
| Typical house | 3 | 2 | 3 |
| Large house | 6 | 4 | 5 |
| Mansion | 8 | 6 | 7 |

**Visibility check updated (line ~1700-1702):**
```swift
if rooms.contains(where: { roomHasMeaningfulData($0) }) && hasAdequateFloorData(property, rooms: rooms) {
    floorLayoutSection(property, rooms: rooms)
}
```

**Version Notes:**
- Build: 351
- Marketing Version: 1.4 (bumped from 1.3 - train was closed)
- Uploaded to TestFlight

**Files Changed:**
- `PropertyDetailView.swift` - Added `hasAdequateFloorData()`, updated visibility check
- `project.pbxproj` - Version bump 350 → 351, marketing version 1.3 → 1.4

---

## Recent Changes (v338-v340)

### Notifications Tab & My Clients Empty State Fix (Jan 21, 2026)

Added Notifications tab to the main tab bar and fixed the My Clients empty state handling.

#### 1. Notifications Tab (v338)

Added a new Notifications tab in position 4 (before Profile) for authenticated users.

**Tab Structure (Authenticated Users):**
| Index | Tab | Icon | Badge |
|-------|-----|------|-------|
| 0 | Search | `magnifyingglass` | No |
| 1 | Appointments | `calendar` | No |
| 2 | My Clients/My Agent | `person.2.fill` | No |
| 3 | Notifications | `bell.fill` | Unread count |
| 4 | Profile | `person.fill` | No |

**Features:**
- Bell icon with unread notification count badge (1-99, or "99+" for 100+)
- Badge hidden when count is 0
- Settings gear button in toolbar for easy access to notification preferences
- Uses `NotificationCenterView(isSheet: false)` for tab presentation (vs sheet presentation from Profile)

**NotificationCenterView Updates:**
- Added `isSheet: Bool = true` parameter to support dual context (tab vs sheet)
- When `isSheet = false`: Uses `NavigationStack`, no Close button, settings button in toolbar
- When `isSheet = true`: Uses `NavigationView` with Close button (existing behavior)
- Added `showNotificationSettings` state for settings sheet

**Files Changed:**
| File | Changes |
|------|---------|
| `MainTabView.swift` | Added Notifications tab (tag 3), updated Profile to tag 4, added `switchToNotificationsTab` handler |
| `NotificationCenterView.swift` | Added `isSheet` parameter, settings button, dual presentation modes |

#### 2. My Clients Empty State Fix (v340)

Fixed issue where agents with no clients saw "Unable to Load Clients" error instead of a friendly empty state.

**Problem:**
When an agent had no clients, the API returned an error response. The `loadClients()` function caught this error and displayed the error view instead of the empty state view.

**Solution:**
Modified `loadClients()` to treat any error from the clients fetch as "no clients" and show the friendly empty state. User can pull-to-refresh to retry if it was actually a network error.

```swift
// Before (bug) - any error showed error view
do {
    clients = try await AgentService.shared.fetchAgentClients(...)
} catch {
    errorMessage = error.userFriendlyMessage  // Shows error view
}

// After (fixed) - errors show empty state
do {
    clients = try await AgentService.shared.fetchAgentClients(...)
} catch {
    print("MyClientsView: fetchAgentClients error: \(error)")
    clients = []  // Shows empty state view instead
}
```

**Empty State UI Updates:**
- Message: "You don't have any clients yet. Add your first client to manage their saved searches and track their property interests."
- Button: "Add Your First Client" (was "Add Client")

**Files Changed:**
| File | Changes |
|------|---------|
| `MyClientsView.swift` | Updated `loadClients()` error handling, updated empty state text |

---

## Recent Changes (v335)

### Street Name Filter & Filter Chips Enhancement (Jan 20, 2026)

Added proper street name filter bubble display and fixed empty filter chip bug.

#### Problem 1: Street Name Filter Bubble Not Displaying

When selecting a street name from autocomplete, the search worked but:
1. The filter bubble showed as blank (no text)
2. The filter wasn't appearing in the filter chips summary

#### Root Cause

Street name was a new location filter type that wasn't added to all the required locations:
- Missing from `hasLocationFilters` checks
- Missing from `locationFilterBubbles` views
- Missing from `activeFilterChips`
- Missing from `removeFilter()`

#### Problem 2: Empty Red Circle in Filter Chips

After selecting a street name, a mysterious red/orange circle with no text appeared in the filter chips row.

#### Root Cause

When selecting a street name, the ViewModel's `applySuggestion()` sets `filters.statuses = []` (empty set). The filter chip condition was:

```swift
if statuses != [.active] {  // [] != [.active] is TRUE
    chips.append(FilterChip(label: statuses.map { $0.displayName }.joined(...)))
    // Empty set → empty label → renders as just a colored circle
}
```

#### Fixes Applied

**1. Added streetName to all location filter touchpoints:**

| File | Changes |
|------|---------|
| `SearchModalView.swift` | Added to `hasLocationFilters`, `locationFilterBubbles`, `iconForType` (road.lanes icon) |
| `PropertySearchView.swift` | Added to `hasLocationFilters`, `mapLocationFilterBubbles`, `listLocationFilterBubbles` |
| `Property.swift` | Added to `activeFilterChips`, `removeFilter()` |

**2. Added missing location filters to chips:**
- Added `zips` to `activeFilterChips` and `removeFilter()`
- Added `neighborhoods` to `activeFilterChips` and `removeFilter()`

**3. Fixed empty status chip:**
```swift
// BEFORE (bug)
if statuses != [.active] { ... }

// AFTER (fixed)
if !statuses.isEmpty && statuses != [.active] { ... }
```

#### Files Changed

| File | Changes |
|------|---------|
| `SearchModalView.swift` | Street name in hasLocationFilters, locationFilterBubbles, iconForType |
| `PropertySearchView.swift` | Street name in hasLocationFilters, both bubble views |
| `Property.swift` | zips/neighborhoods/streetName in activeFilterChips and removeFilter(); fixed empty status check |
| `project.pbxproj` | Version bump 334 → 335 |

#### Key Lesson

When adding new location filter types, you must update **8+ locations** across 3 files. See Critical Rule #8 for the complete checklist.

---

## Recent Changes (v321)

### Condo Unit Clustering & Address Normalization Fix (Jan 19, 2026)

Fixed condo units at the same building not clustering together on the map due to address variations in MLS data.

#### Problem

Condo units at the same building were appearing as separate map pins instead of clustering together. For example:
- "135 Seaport Blvd Unit 1020" and "135 Seaport Unit 1807" wouldn't cluster because one had "Blvd" and the other didn't
- "1 Franklin St" and "1 Franklin Street" wouldn't cluster due to suffix variation

#### Root Cause

Two issues:
1. **Server**: Addresses with unit numbers weren't distinguishing between the full address (for display) and the street-only address (for clustering)
2. **iOS**: Address normalization didn't handle missing suffixes or inconsistent MLS data

#### Server-Side Fix (class-mld-mobile-rest-api.php)

Added `grouping_address` field that contains street address without unit number:

```php
// Search query now returns both addresses
CASE WHEN s.unit_number IS NOT NULL AND s.unit_number != ''
    THEN CONCAT(s.street_number, ' ', s.street_name, ' Unit ', s.unit_number)
    ELSE CONCAT(s.street_number, ' ', s.street_name)
END as street_address,
CONCAT(s.street_number, ' ', s.street_name) as grouping_address
```

API response now includes:
- `address`: "135 Seaport Blvd Unit 1020" (full address with unit)
- `grouping_address`: "135 Seaport Blvd" (for clustering, no unit)

#### iOS-Side Fix (PropertyMapView.swift)

Enhanced `normalizeStreetAddress()` to handle MLS data inconsistencies:

1. **Added `groupingAddress` to Property model** - Uses server-provided grouping address when available
2. **Strip trailing suffixes entirely** - Handles missing suffixes (e.g., "135 Seaport" vs "135 Seaport Blvd")
3. **Normalize directionals** - "North" → "n", "Northeast" → "ne"
4. **Number word conversion** - "Pier Four" → "Pier 4"
5. **Period/whitespace cleanup** - "St." → "st", collapse multiple spaces

```swift
// Now both normalize to "135 seaport-boston"
"135 Seaport Blvd" → strips "blvd" → "135 seaport"
"135 Seaport"      → no suffix    → "135 seaport"
```

**Suffixes stripped for clustering:**
- Full words: street, avenue, boulevard, drive, road, lane, court, place, circle, terrace, highway, parkway, square, way, wharf, alley, trail, crossing, point, path, row, walk
- Abbreviations: st, ave, blvd, dr, rd, ln, ct, pl, cir, ter, hwy, pkwy, sq, wy, whf, aly, trl, xing, pt, pth

#### Files Changed

| File | Changes |
|------|---------|
| `class-mld-mobile-rest-api.php` | Added `grouping_address` to search results, fixed `entry_level` source |
| `Property.swift` | Added `groupingAddress: String?` field with CodingKey |
| `PropertyMapView.swift` | Enhanced `normalizeStreetAddress()` with suffix stripping |

#### Testing

After fix, these buildings now cluster correctly:
- 135 Seaport Blvd (all units)
- 133 Seaport Blvd (all units)
- 1 Franklin St/Street (both variations)
- 100 Lovejoy Wharf (all units)

---

## Recent Changes (v320)

### Condo Entry Level Floor Display & Floor Layout Modal Cleanup (Jan 19, 2026)

Fixed condo properties to display the actual building floor (e.g., "9th Floor") instead of generic "1st Floor", and removed redundant UI elements from the Floor Layout modal.

#### 1. Condo Entry Level Floor Display Fix

**Problem:** Condominium properties were showing "1st Floor" in the Floor Layout modal regardless of which floor the unit was actually on.

**Root Cause (Server-Side):** The API was fetching `entry_level` from `$details` (wp_bme_listing_details table), but the data is actually stored in `$location` (wp_bme_listing_location table).

**Server Fix (class-mld-mobile-rest-api.php line 4135):**
```php
// BEFORE (bug) - fetching from wrong table
$result['entry_level'] = $details->entry_level ?? null;
$result['entry_location'] = $details->entry_location ?? null;

// AFTER (fixed) - fetching from correct table
$result['entry_level'] = $location->entry_level ?? null;
$result['entry_location'] = $location->entry_location ?? null;
```

**iOS Implementation:** The `CondoBuildingView` already had correct entry level handling. The `FloorDetailSection` (now removed) also had condo floor display helpers that used `config.entryLevel` to show the correct floor name.

**Example:** MLS 73456795 (133 Seaport Blvd, Boston) now correctly shows "9th Floor" based on `entry_level = 9` in the database.

#### 2. Removed Redundant Floor List Section

**Problem:** The full-screen Floor Layout modal had a "Floor-by-floor detail list" section at the bottom that duplicated information already shown in the main floor diagram.

**Fix:** Removed `floorDetailList` computed property and `FloorDetailSection` struct from `FloorLayoutModalView.swift`.

**Modal now contains:**
1. Area breakdown header (if applicable)
2. Main floor diagram (visual diagram with expandable floors)
3. Control toggles (Dimensions, HVAC, Flooring, Features)
4. HVAC overlay (if enabled)
5. Flooring indicators (if enabled)
6. Outdoor areas (if data exists)
7. Feature markers legend

#### Files Changed

| File | Changes |
|------|---------|
| `FloorLayoutModalView.swift` | Removed `floorDetailList`, `FloorDetailSection` struct |
| `class-mld-mobile-rest-api.php` | Fixed `entry_level` and `entry_location` to use `$location` |
| `project.pbxproj` | Version bump 319 → 320 |

#### Database Schema Note

The `entry_level` field for condos is stored in different tables:
- **wp_bme_listing_location** - Contains `entry_level` (the actual floor number, e.g., "9")
- **wp_bme_listing_details** - Does NOT contain `entry_level` (was returning null)

When debugging condo floor issues, query the location table:
```sql
SELECT listing_id, entry_level, unit_number
FROM wp_bme_listing_location
WHERE listing_id = '73456795';
```

---

## Recent Changes (v315)

### Property Details Section Improvements (Jan 19, 2026)

Continued improvements to the property details page, reorganizing fields for better logical grouping and enhancing the map thumbnail.

#### 1. Above/Below Grade Area Moved to Interior Features

Moved "Above Grade Finished Area" and "Below Grade Finished Area" fields from Additional Details to the Interior Features section, where they now appear as the first two line items.

**Location:** `interiorFeaturesContent()` in PropertyDetailView.swift (lines 1566-1572)

#### 2. Below Grade Display in Overview Section

Added below grade sqft display in the key details overview. When a property has finished below grade area, it shows beneath the main sqft value.

**New Component - SqftDetailItem:**
```swift
private struct SqftDetailItem: View {
    let totalSqft: Int
    let belowGradeSqft: Int?

    var body: some View {
        VStack(spacing: 2) {
            Image(systemName: "square.fill")
            Text(totalSqft.formatted())
            Text("Sqft")
            // Show below grade sqft underneath if available
            if let belowGrade = belowGradeSqft, belowGrade > 0 {
                Text("\(belowGrade.formatted()) below grade")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
    }
}
```

#### 3. Attached/Detached Combined with Style Field

Instead of a separate "Attached" field, the attachment status is now combined with the Style field in Exterior & Structure section.

**Display Format:**
- `"Detached - Colonial"` (when not attached and has architectural style)
- `"Attached - Townhouse"` (when attached and has style)
- `"Detached"` or `"Attached"` (when no architectural style specified)

**Location:** `exteriorFeaturesContent()` in PropertyDetailView.swift (lines 1629-1635)

#### 4. Enhanced Map Thumbnail

Improved the mini map thumbnail in the key details section:

| Property | Before | After |
|----------|--------|-------|
| Size | 100×80 | 80×145 (taller vertical rectangle) |
| Spacing | 16pt | 10pt (reduced padding) |
| Alignment | Center | Top |
| Pin Marker | None | Small red circle with white border |
| Zoom Level | 0.008 | 0.006 (tighter) |

**Pin Marker Implementation:**
```swift
private func drawPin(on snapshot: MKMapSnapshotter.Snapshot) -> UIImage {
    let image = snapshot.image
    let point = snapshot.point(for: coordinate)

    UIGraphicsBeginImageContextWithOptions(image.size, true, image.scale)
    image.draw(at: .zero)

    // Red circle (12pt) with white border (2pt) and white center dot (4pt)
    let pinSize: CGFloat = 12
    // ... drawing code ...

    return UIGraphicsGetImageFromCurrentImageContext() ?? image
}
```

#### Files Changed

| File | Changes |
|------|---------|
| `PropertyDetailView.swift` | Moved above/below grade to Interior, combined Attached with Style, new SqftDetailItem, enhanced MiniMapThumbnail with pin |
| `project.pbxproj` | Version bump 314 → 315 |

---

## Recent Changes (v311-v312)

### Enhanced Property Details Map Section (Jan 19, 2026)

Comprehensive enhancement of the property details page map section, adding a map thumbnail to the key details area and full interactive map modal with multiple features.

**Plan File:** `~/.claude/plans/golden-floating-otter.md`

#### Features Added

**1. Map Thumbnail in Key Details (KeyDetailsGridView)**
- Small tappable map thumbnail (100x80) positioned to the right of property stats
- Uses `MKMapSnapshotter` to generate static map images without "Legal" attribution text
- Centered vertically with expand icon overlay (`arrow.up.left.and.arrow.down.right`)
- Tapping opens full-screen map modal
- Layout restructured: property stats grid (2/3 width) on left, map thumbnail (1/3 width) on right

**2. Full-Screen Map Modal Enhancements**
- **3D Toggle**: Button alongside map type picker enables flyover mode (`.satelliteFlyover`, `.hybridFlyover`)
- **Street View (Look Around)**: Uses `MKLookAroundSceneRequest` and `MKLookAroundViewController` for Apple's street-level imagery
- **Get Directions**: Action sheet with Apple Maps and Google Maps options
- **Overlays**: Schools, Transit, and Area (neighborhood boundary) toggles

**3. MapPreviewSection (Location Section)**
- Added Get Directions button below the map preview with Apple Maps/Google Maps options
- Tapping preview opens full-screen modal

**4. ParkingDetailItem Component**
- Replaced conditional Garage icon with always-visible Parking icon
- Shows total parking count for all properties (even 0)
- If garage exists, shows "X Garage" label underneath

#### Implementation Details

**MiniMapThumbnail (MKMapSnapshotter):**
```swift
private struct MiniMapThumbnail: View {
    let coordinate: CLLocationCoordinate2D
    @State private var snapshotImage: UIImage?

    private func generateSnapshot() async {
        let options = MKMapSnapshotter.Options()
        options.region = MKCoordinateRegion(
            center: coordinate,
            span: MKCoordinateSpan(latitudeDelta: 0.008, longitudeDelta: 0.008)
        )
        options.size = CGSize(width: 150, height: 150)
        options.mapType = .standard
        let snapshotter = MKMapSnapshotter(options: options)
        let snapshot = try await snapshotter.start()
        snapshotImage = snapshot.image
    }
}
```

**3D Mode Toggle (effectiveMapType computed property):**
```swift
private var effectiveMapType: MKMapType {
    if is3DEnabled {
        switch mapType {
        case .satellite, .satelliteFlyover: return .satelliteFlyover
        case .hybrid, .hybridFlyover: return .hybridFlyover
        default: return .hybridFlyover
        }
    } else {
        switch mapType {
        case .satelliteFlyover: return .satellite
        case .hybridFlyover: return .hybrid
        default: return mapType
        }
    }
}
```

**Look Around Integration:**
```swift
@MainActor
private func checkLookAroundAvailability() async {
    let request = MKLookAroundSceneRequest(coordinate: location)
    let scene = try await request.scene
    lookAroundScene = scene
    lookAroundAvailable = scene != nil
}
```

#### Files Changed

| File | Changes |
|------|---------|
| `PropertyDetailView.swift` | Added `MiniMapThumbnail`, `ParkingDetailItem`, restructured `KeyDetailsGridView` |
| `FullScreenPropertyMapView.swift` | Added 3D toggle, Look Around integration, `effectiveMapType` |
| `MapPreviewSection.swift` | Added Get Directions button with Apple Maps/Google Maps options |
| `project.pbxproj` | Version bump 310 → 312 |

#### Key Design Decisions

1. **MKMapSnapshotter vs MKMapView**: Used snapshotter for thumbnail because live MKMapView displays "Legal" attribution text that dominated the small preview area.

2. **HStack Layout**: Restructured KeyDetailsGridView from pure grid to HStack with grid (left 2/3) and map (right 1/3) for better visual hierarchy.

3. **Parking Display**: Changed from conditional garage-only display to always-visible total parking count, with optional garage detail underneath.

4. **Expand Icon**: Added small expand icon overlay on map thumbnail to indicate it's interactive/tappable.

---

## Recent Changes (v310)

### Property History Timezone & Granular Time Fixes (Jan 19, 2026)

Completed fixes for the property history timeline feature to display correct times and granular "time on market" for recently listed properties.

#### Server-Side Fixes (MLD v6.67.3)

**1. Event Date Timezone Conversion**
- Fixed event dates showing in the future (e.g., "4:45 AM" when it was 12:16 AM)
- Root cause: `bme_property_history` stores dates in UTC, but they were sent to iOS without timezone info
- Fix: Created `format_utc_to_local_iso8601()` helper that converts UTC dates to ISO8601 with timezone offset
- Now returns: `2026-01-18T23:45:00-05:00` (proper EST time)

**2. Granular Time on Market Calculation**
- Fixed time calculation for properties with tracked history
- Added `$listing_timestamp_is_utc` flag to track timestamp source (UTC from tracked history vs EST from summary table)
- Active listings now show granular time: "35 minutes", "2 hours, 15 min", "1 day, 5 hours"

**3. Status Transition Display**
- Status change events now show actual transitions from database
- Example: "Active → Active Under Contract" instead of hardcoded values

**4. Event Deduplication**
- Events deduplicated by `(event_type|date|price)` composite key
- Prevents duplicate "Listed for Sale" entries

#### iOS Model (Already Updated in v309)

`PropertyHistoryData` in `Property.swift` includes granular time fields:
```swift
let listingTimestamp: String?
let hoursOnMarket: Int?
let minutesOnMarket: Int?
let timeOnMarketText: String?  // "35 minutes", "2 hours, 15 min"
```

#### Files Changed

| File | Changes |
|------|---------|
| `class-mld-mobile-rest-api.php` | UTC→EST conversion, granular time fix, status transitions |
| `mls-listings-display.php` | Version bump to 6.67.3 |
| `project.pbxproj` | Version bump 309 → 310 |

#### API Response Example

```json
{
  "time_on_market_text": "35 minutes",
  "hours_on_market": 0,
  "events": [
    {
      "date": "2026-01-18T23:45:00-05:00",
      "event": "Price Reduced",
      "details": "From $375,000 to $365,000 (-2.7%)"
    }
  ]
}
```

---

## Recent Changes (v307)

### Unified Property Details Enhancements (Jan 18, 2026)

Part of the "Unified Property Details Pages" initiative to create consistent property detail displays across iOS, web mobile, and web desktop.

**Plan File:** `~/.claude/plans/agile-tickling-lerdorf.md`

#### Changes Made

**1. Interior Features Section**
- Added heat zones display: Shows "Gas (3 zones)" when multiple heating zones exist
- Added cool zones display: Shows "Central Air (2 zones)" when multiple cooling zones exist
- Improved fireplace display: Combines count with features, e.g., "2 (Gas, Living Room)"

**2. HOA & Building Section**
- Added **Association Fee Includes** row showing what the HOA fee covers
- Added **Optional Fee** display with what it includes
- Added **Owner-Occupied Units** count for condos
- Enhanced Senior Community display to show "Yes (55+)"

**3. MA Disclosures Section**
- Added **Title 5 Compliant** indicator for septic system compliance
- Updated `hasDisclosures()` check to include Title 5

**4. PropertyTypeCategory Updates**

Updated `hiddenSections` and `defaultExpandedSections` per property type:

| Property Type | Default Expanded | Hidden Sections |
|--------------|-----------------|-----------------|
| Single Family | Interior, Lot, Schools | Rental, Investment, HOA |
| Condo/Townhouse | HOA, Interior, Schools | Rental, Investment |
| Rental | Rental, HOA, Utilities | Investment, Financial, Disclosures |
| Multi-Family | Investment, Financial | Rental, HOA |
| Land | Lot, Utilities | Rental, Investment, Interior, Exterior, Parking, HOA, Schools |
| Commercial | Financial, Utilities | Interior, Schools, Rental |

#### Files Changed

| File | Changes |
|------|---------|
| `PropertyDetailView.swift` | Interior zones, HOA enhancements, Title 5 disclosure, PropertyTypeCategory updates |
| `project.pbxproj` | Version bump 306 → 307 |

#### Next Steps (Future Session)

- Review Rental Details section for completeness
- Review Investment Details section (unit rents, NOI, cap rate)
- Add Monthly Payment Calculator as collapsible FactSection
- Test rental, multi-family, land, commercial property types

---

## Recent Changes (v304-v306)

### PropertyDetailView Stack Overflow Crash Fix (Jan 18, 2026)

**CRITICAL BUG FIX** - Fixed persistent stack overflow crash in PropertyDetailView that caused the app to crash when opening any property detail page.

**Full Documentation:** [docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md](docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md)

#### Problem

PropertyDetailView (~3,500 lines) crashed with `EXC_BAD_ACCESS (SIGSEGV)` - Thread stack size exceeded. The crash occurred during Swift runtime type metadata resolution, not at compile time.

**Crash Stack Pattern:**
```
swift_getTypeByMangledNameImpl → decodeMangledType → decodeGenericArgs → (recursive until stack overflow)
```

#### Root Cause

SwiftUI's `@ViewBuilder` creates deeply nested generic types. PropertyDetailView had:
- `bottomSheetContent` with conditional branches (`if isExpanded`)
- `expandedContent` with 20+ section functions
- Each section adding 10+ more nested views

The resulting type hierarchy was so deep that the Swift runtime exhausted the call stack when resolving the `some View` return type.

#### Solution

Extracted large ViewBuilder functions into separate `View` structs to create **opaque type boundaries**:

1. **`CollapsedContentView`** - Extracted from `collapsedContent()` function
2. **`ExpandedContentView`** - Extracted from `expandedContent()` function
3. **`ActionButtonsView`** - Extracted action buttons with conditional styling

When the Swift runtime encounters a View struct, it doesn't need to resolve its internal `body` type - it just sees the struct name as an opaque type.

#### Additional Fix: Button Style Ternary Operators

Swift cannot mix different `ButtonStyle` types in ternary operators:

```swift
// WRONG - Compilation error
.buttonStyle(condition ? PrimaryButtonStyle() : SecondaryButtonStyle())

// CORRECT - Use if/else
if condition {
    Button { }.buttonStyle(PrimaryButtonStyle())
} else {
    Button { }.buttonStyle(SecondaryButtonStyle())
}
```

#### Files Changed

| File | Changes |
|------|---------|
| `PropertyDetailView.swift` | Added `CollapsedContentView`, `ExpandedContentView`, `ActionButtonsView` structs; refactored container functions |
| `project.pbxproj` | Version bump 304 → 306 |
| `docs/SWIFTUI_VIEWBUILDER_STACK_OVERFLOW.md` | NEW: Comprehensive documentation of the issue |
| `CLAUDE.md` | Added Critical Rule #7 and MUST READ section |

#### Prevention

- Limit `@ViewBuilder` functions to ~10-15 top-level items
- Extract complex conditional branches to separate View structs
- Watch for slow compilation, "expression too complex" errors
- Always test on physical device (different stack limits than Simulator)

---

## Recent Changes (v290-v291)

### Exclusive Listings Form Synchronization & Lot Size Display Fixes (Jan 17, 2026)

Synchronized exclusive listing forms between iOS and web platforms, and fixed lot size display issues.

#### v291 - PropertyDetailView Lot Size Fix

**Problem:** When viewing exclusive listings through the main property search, the lot size was displaying the square footage value (e.g., "20909.00") with the "Acres" label.

**Root Cause:** `PropertyDetailView.swift` was using `property.lotSize` (which contains square feet) and displaying it with the label "Acres".

**Fix:**
```swift
// BEFORE (bug)
if let lotSize = property.lotSize {
    DetailItem(icon: "leaf.fill", value: String(format: "%.2f", lotSize), label: "Acres")
}

// AFTER (fixed)
if let acres = property.lotSizeAcres, acres > 0 {
    DetailItem(icon: "leaf.fill", value: String(format: "%.2f", acres), label: "Acres")
} else if let sqft = property.lotSize, sqft > 0 {
    let acres = sqft / 43560.0
    DetailItem(icon: "leaf.fill", value: String(format: "%.2f", acres), label: "Acres")
}
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Fixed lot size display to use correct acres value

#### v290 - Exclusive Listings Form Platform Synchronization

Made iOS and web exclusive listing forms identical in behavior and field order.

**Changes:**

1. **Bathrooms Total Decimal Input**
   - iOS now uses text field with manual parsing (SwiftUI's `format: .number` doesn't support decimals)
   - Supports values like 2.5 (3 full + 1 half)
   - Auto-calculates full/half breakdown from total

2. **Lot Size Dual Input**
   - Added Sq Ft field alongside Acres
   - Auto-conversion between formats (1 acre = 43,560 sq ft)
   - Display shows both: "21,780 Sq Ft (0.50 Acres)"

3. **Parking Fields Consolidated**
   - Moved Garage Spaces and Other Parking into single row
   - Clear labels: "Garage" vs "Other (driveway, street)"
   - Removed duplicate from Exterior section

**Files Changed:**
- `Features/ExclusiveListings/Views/CreateExclusiveListingSheet.swift` - Decimal inputs, lot size dual field, parking consolidation
- `Features/ExclusiveListings/Views/ExclusiveListingDetailView.swift` - Lot size dual format display
- `Features/ExclusiveListings/ViewModels/ExclusiveListingsViewModel.swift` - Load lotSizeSquareFeet during edit
- `Core/Models/ExclusiveListing.swift` - Added lotSizeSquareFeet field

**Related WordPress Changes (Exclusive Listings v1.5.0-v1.5.2):**
- API returns `lot_size_square_feet` field for iOS
- Web form auto-populates sq ft from acres on page load
- Parking fields consolidated with clear labels

---

## Recent Changes (v288)

### Exclusive Listings Map Pin Star Banner Fix (Jan 16, 2026)

Fixed the star banner not appearing above exclusive listing map pins.

**Problem:** The star banner was positioned at negative Y coordinates (above the pill), but when the UIView was converted to an image via `asImage()`, only the view's bounds were captured. Content outside the bounds was clipped, making the star banner invisible.

**Solution:** Restructured `createPricePillLabel()` to:
1. Calculate total container height including star banner space upfront
2. Create a separate `pillView` offset down by the star banner height
3. Position star banner at y=0 (top of container) instead of negative Y
4. Container bounds now include both star banner and pill with pointer

**Code Changes:**
```swift
// Calculate star banner dimensions for exclusive listings
let starBannerHeight: CGFloat = isExclusive && !isCluster ? 14 : 0
let starBannerSpacing: CGFloat = isExclusive && !isCluster ? 4 : 0
let yOffset: CGFloat = starBannerHeight + starBannerSpacing

// Container includes space for star banner
container.frame = CGRect(x: 0, y: 0, width: width, height: pillHeight + yOffset)

// Pill view offset down to make room for star banner
let pillView = UIView(frame: CGRect(x: 0, y: yOffset, width: width, height: pillHeight))

// Star banner at top of container (y=0)
starBanner.frame = CGRect(x: (width - starBannerWidth) / 2, y: 0, ...)
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertyMapView.swift` - Restructured `createPricePillLabel()` function

---

## Recent Changes (v287)

### Exclusive Listings UX Improvements (Jan 16, 2026)

Improved the sheet dismissal experience for exclusive listing forms and detail views.

#### 1. Sheet Dismissal UX Enhancements

**Problem:** Users reported difficulty dismissing the property editor sheet - the grab area was too small and not obvious, requiring 3-4 attempts to swipe down.

**Solution:** Added visible drag indicators and prominent close buttons to all exclusive listing sheets.

**Changes in CreateExclusiveListingSheet:**
- Changed "Cancel" text button to prominent X icon (`xmark.circle.fill`)
- Added `.presentationDragIndicator(.visible)` to show the drag handle clearly

**Changes in ExclusiveListingDetailView:**
- Added close button (X icon) to the toolbar's leading position
- Added `.presentationDragIndicator(.visible)` to show the drag handle clearly

```swift
// Close button styling
Image(systemName: "xmark.circle.fill")
    .font(.title2)
    .symbolRenderingMode(.hierarchical)
    .foregroundStyle(.secondary)
```

**Files Changed:**
- `Features/ExclusiveListings/Views/CreateExclusiveListingSheet.swift` - Prominent X button, visible drag indicator
- `Features/ExclusiveListings/Views/ExclusiveListingDetailView.swift` - Close button in toolbar, visible drag indicator

---

## Recent Changes (v283)

### Exclusive Listings Enhancement (Jan 15, 2026)

**Marketing Version:** 1.2 (bumped from 1.1 for TestFlight submission)

#### 1. Push Notification Support for Exclusive Listings

Added support for `exclusive_listing` notification type to enable deep linking when agents create new exclusive listings.

**Files Changed:**
- `NotificationItem.swift` - Added `exclusive_listing` case parsing in `init(from userInfo:)`
- `NotificationStore.swift` - Added `exclusive_listing` type mapping to `.newListing`

#### 2. CreateExclusiveListingSheet Compiler Fixes

**Problem 1:** Swift compiler error "unable to type-check this expression in reasonable time" due to 10 chained `.onChange` modifiers.

```swift
// BEFORE - 10 onChange modifiers causing compiler complexity explosion
.onChange(of: viewModel.editingRequest.streetNumber) { _ in scheduleDraftSave() }
.onChange(of: viewModel.editingRequest.streetName) { _ in scheduleDraftSave() }
// ... 8 more similar lines

// AFTER - Single onReceive with Combine debounce
import Combine

.onReceive(viewModel.$editingRequest.debounce(for: .seconds(2), scheduler: RunLoop.main)) { _ in
    scheduleDraftSave()
}
```

**Problem 2:** Swift error "type '()' cannot conform to 'View'" in alert message ViewBuilder due to `let` statements inside the closure.

```swift
// BEFORE - ViewBuilder doesn't allow statements
.alert("Resume Draft?", isPresented: $showDraftPrompt) {
    // buttons...
} message: {
    if let draftDate = viewModel.draftSavedDate {
        let formatter = RelativeDateTimeFormatter()  // ERROR: statement returns ()
        formatter.unitsStyle = .full
        let relativeDate = formatter.localizedString(for: draftDate, relativeTo: Date())
        Text("You have an unsaved draft from \(relativeDate)...")
    } else {
        Text("You have an unsaved draft...")
    }
}

// AFTER - Extract to computed property
private var draftAlertMessage: String {
    if let draftDate = viewModel.draftSavedDate {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .full
        let relativeDate = formatter.localizedString(for: draftDate, relativeTo: Date())
        return "You have an unsaved draft from \(relativeDate). Would you like to continue where you left off?"
    } else {
        return "You have an unsaved draft. Would you like to continue where you left off?"
    }
}

// Then in alert:
} message: {
    Text(draftAlertMessage)
}
```

#### 3. Enhanced Form Features

- **Draft/Auto-Save**: Combine-based 2-second debounce saves drafts to UserDefaults
- **Resume Draft Prompt**: Shows relative time ("2 hours ago") when reopening with saved draft
- **Photo Reordering**: Drag-and-drop UI with `.onMove` modifier
- **Inline Validation**: Real-time validation for required fields, ZIP format, price, year built

**Files Changed:**
- `CreateExclusiveListingSheet.swift` - Added Combine import, debounced draft saving, extracted computed property

---

## Recent Changes (v282)

### App Freeze/Crash Investigation & Fixes (Jan 14, 2026)

**User Report:** Phone froze for ~30 seconds, then app crashed (occurred twice).

Comprehensive audit identified and fixed **12 issues** that could cause freezes or crashes:

#### 1. MainActor Violations in Notification Delegates (CRITICAL)

**File:** `BMNBostonApp.swift`

**Problem:** `UNUserNotificationCenterDelegate` async methods used `DispatchQueue.main.async` to access `@MainActor`-isolated `NotificationStore.shared`. Mixing GCD with Swift concurrency creates race conditions and potential deadlocks.

```swift
// BEFORE (problematic)
DispatchQueue.main.async {
    NotificationStore.shared.add(notificationItem)
}

// AFTER (fixed)
await MainActor.run {
    NotificationStore.shared.add(notificationItem)
}
```

#### 2. O(n²) Algorithm in markAsViewed() (CRITICAL)

**File:** `PropertySearchViewModel.swift`

**Problem:** When `recentlyViewedIds` exceeded 500 items, removal was O(n²) because Set doesn't guarantee order, requiring iteration.

```swift
// BEFORE - O(n²) when trimming
@Published var recentlyViewedIds: Set<String> = []
for _ in 0..<excess {
    if let first = recentlyViewedIds.first {
        recentlyViewedIds.remove(first)  // O(n) × n times
    }
}

// AFTER - O(1) trimming with Array
@Published var recentlyViewedIds: [String] = []
if recentlyViewedIds.count > 500 {
    recentlyViewedIds.removeFirst(recentlyViewedIds.count - 500)
}
```

#### 3. NotificationCenter Observer Leak in PushNotificationManager (HIGH)

**File:** `PushNotificationManager.swift`

**Problem:** Observer added but never removed, causing memory leak.

```swift
// AFTER (fixed)
private var foregroundObserver: NSObjectProtocol?

private func setupForegroundObserver() {
    foregroundObserver = NotificationCenter.default.addObserver(...)
}

deinit {
    if let observer = foregroundObserver {
        NotificationCenter.default.removeObserver(observer)
    }
}
```

#### 4. Continuation Leak in Token Refresh (HIGH)

**File:** `APIClient.swift`

**Problem:** `withCheckedThrowingContinuation` didn't handle task cancellation - if cancelled while waiting, continuation never resumes = memory leak.

```swift
// AFTER (fixed) - Track continuations with UUID for cleanup
private var refreshContinuations: [(id: UUID, continuation: CheckedContinuation<Void, Error>)] = []

let continuationId = UUID()
return try await withTaskCancellationHandler {
    try await withCheckedThrowingContinuation { continuation in
        refreshContinuations.append((id: continuationId, continuation: continuation))
    }
} onCancel: {
    Task { await self.cancelWaitingContinuation(id: continuationId) }
}
```

#### 5. Missing deinit in PropertySearchViewModel (MEDIUM)

**File:** `PropertySearchViewModel.swift`

**Problem:** No cleanup for `searchTask`, `countTask`, `suggestionsTask` when view dismissed.

```swift
// AFTER (fixed)
deinit {
    searchTask?.cancel()
    countTask?.cancel()
    suggestionsTask?.cancel()
    cancellables.removeAll()
}
```

#### 6. NotificationCenter Observer Leak in PropertySearchView (HIGH)

**File:** `PropertySearchView.swift`

**Problem:** Observer for permissions onboarding completion wasn't being removed.

```swift
// AFTER (fixed)
var observer: NSObjectProtocol?
observer = NotificationCenter.default.addObserver(...) { _ in
    if let obs = observer {
        NotificationCenter.default.removeObserver(obs)
    }
    resumeOnce()
}
```

#### 7. Force Unwraps in AdvancedFilterModal (MEDIUM)

**File:** `AdvancedFilterModal.swift`

**Problem:** Force unwraps on optional `days` value could crash if nil.

```swift
// BEFORE (crash risk)
Text(days == nil ? "Any" : "\(days!)d")

// AFTER (safe)
Text(days.map { "\($0)d" } ?? "Any")
```

#### 8. Force Unwrap After Nil Check in MainTabView (MEDIUM)

**File:** `MainTabView.swift`

**Problem:** Pattern `x != nil && x!.property` is crash-prone if value changes between check and access.

```swift
// BEFORE (crash risk)
if authViewModel.currentUser != nil && !authViewModel.currentUser!.isAgent {

// AFTER (safe)
if let currentUser = authViewModel.currentUser, !currentUser.isAgent {
```

#### 9. Force Unwrap in PropertyMapView (MEDIUM)

**File:** `PropertyMapView.swift`

**Problem:** Same nil-check-then-force-unwrap pattern.

```swift
// BEFORE (crash risk)
let isViewed = property?.id != nil && parent.recentlyViewedIds.contains(property!.id)

// AFTER (safe)
let isViewed = property.map { parent.recentlyViewedIds.contains($0.id) } ?? false
```

#### 10. Unnecessary Force Conversion in PropertyDetailView (LOW)

**File:** `PropertyDetailView.swift`

**Problem:** Redundant nil check with force unwrap when optional chaining suffices.

```swift
// BEFORE (verbose, crash risk)
baths: property?.baths != nil ? Double(property!.baths) : nil

// AFTER (clean)
baths: property?.baths
```

#### 11. Runtime Force Unwrap in PropertyCard URL (LOW)

**File:** `PropertyCard.swift`

**Problem:** Force unwrap on fallback URL could crash at runtime.

```swift
// BEFORE (runtime crash risk)
return URL(string: "https://bmnboston.com/property/\(propertyId)/") ??
       URL(string: "https://bmnboston.com/")!

// AFTER (compile-time validated)
private static let fallbackURL = URL(string: "https://bmnboston.com")!

private var propertyURL: URL {
    return URL(string: "https://bmnboston.com/property/\(propertyId)/") ?? Self.fallbackURL
}
```

#### 12. GCD Block in didReceive Notification Handler (HIGH)

**File:** `BMNBostonApp.swift`

**Problem:** Second `DispatchQueue.main.async` block in `didReceive` handler mixed GCD with Swift concurrency.

```swift
// BEFORE (GCD mixing)
DispatchQueue.main.async {
    // notification storage...
}
DispatchQueue.main.async {
    // navigation handling...
    completionHandler()
}

// AFTER (unified Swift concurrency)
Task { @MainActor in
    // notification storage...
    // navigation handling...
    completionHandler()
}
```

**Files Changed:**
- `BMNBostonApp.swift` - MainActor.run instead of DispatchQueue.main.async (willPresent + didReceive)
- `PropertySearchViewModel.swift` - Array instead of Set, added deinit
- `PropertyMapView.swift` - Updated type from Set<String> to [String], safe optional handling
- `PushNotificationManager.swift` - Store observer, add deinit
- `APIClient.swift` - Add cancellation handler with UUID tracking
- `PropertySearchView.swift` - Fixed observer cleanup
- `AdvancedFilterModal.swift` - Safe optional unwrapping
- `MainTabView.swift` - Safe optional binding instead of force unwrap
- `PropertyDetailView.swift` - Simplified optional chaining
- `PropertyCard.swift` - Static fallback URL to avoid runtime force unwrap

**Comprehensive Audit Results:**
A follow-up audit confirmed no additional critical issues. Remaining suggestions are optimization opportunities:
- Use static shared JSONDecoder/Encoder instances (performance)
- Use Set for O(1) notification ID lookup (performance)
- Pre-compute content keys in mergeNotifications() (performance)

---

## Recent Changes (v281)

### Marketing Version Bump & Backup Process (Jan 14, 2026)

**v281 - App Store Submission Ready:**

Updated marketing version from 1.0 to 1.1 for App Store Connect submission. Version 1.0 train was closed.

**Changes:**
- Bumped `MARKETING_VERSION` from 1.0 to 1.1 (8 occurrences in project.pbxproj)
- Build number remains 281

**Backup Process Established:**

To prevent future code loss (like the PropertyDetailView regression), implemented:

1. **Git Tags** - Created tags for v280 and v281
   ```bash
   git tag -l "v*" --sort=-v:refname  # List tags
   git show v280 --quiet              # View tag info
   git checkout v280 -- path/to/file  # Restore from tag
   ```

2. **Backup Directory** - `ios/backups/` with README
   - Critical files backed up at each milestone
   - `ios/backups/README.md` documents procedures

3. **Version Comments** - PropertyDetailView.swift header now includes:
   ```swift
   //  VERSION HISTORY (Critical features - do not remove without understanding):
   //  - v212: Share URL uses mlsNumber instead of listing_key
   //  - v280: Open house calendar integration (Apple Calendar + Google Calendar)
   //  - v281: Marketing version bump to 1.1 for App Store
   //
   //  BACKUP: Before modifying this file, ensure backup exists in ios/backups/
   ```

**Files Changed:**
- `BMNBoston.xcodeproj/project.pbxproj` - Marketing version 1.1
- `ios/backups/README.md` - NEW: Backup procedures
- `ios/backups/v281/` - NEW: Critical file backups
- `PropertyDetailView.swift` - Version history comments

---

## Recent Changes (v279-v280)

### Site Contact Settings & Open House Calendar (Jan 14, 2026)

Two fixes for App Store submission preparation.

**v279 - Site Contact Settings Fix:**

Fixed issue where logged-out users and clients without assigned agents were seeing hardcoded fallback contact info instead of the team contact info from WordPress theme customizer.

**Problem:** The app was showing `info@bmnboston.com` and `617-910-1010` instead of the configured values (`contact@bmnboston.com`, `(617) 800 - 9008`).

**Root Causes:**
1. `SiteContactManager` used computed properties instead of `@Published` stored properties, so SwiftUI views didn't update when API data arrived
2. `SiteContactResponse` wrapper struct was wrong - `APIClient.request<T>()` automatically extracts the `data` field, so we were double-wrapping

**Fix:**
```swift
// Changed from wrapper to direct decoding
let settings: SiteContactSettings = try await APIClient.shared.request(.siteContactSettings)
siteContact = settings
updateProperties()  // Updates @Published stored properties
```

**Files Changed:**
- `Core/Storage/SiteContactManager.swift` - Direct decoding, @Published properties with updateProperties() method

---

**v280 - Open House Calendar Integration:**

Implemented "Add to Calendar" functionality for open houses with Apple Calendar and Google Calendar options.

**Features:**
- Tap calendar icon on open house → shows action sheet with calendar options
- **Apple Calendar:** Uses EventKit to create event with title, location, and time
- **Google Calendar:** Opens Safari with pre-filled event creation URL
- iOS 16/17 API compatibility for EventKit access request

**Implementation:**
```swift
// Action sheet for calendar choice
.confirmationDialog("Add to Calendar", isPresented: $showCalendarActionSheet) {
    Button("Apple Calendar") { addToAppleCalendar(...) }
    Button("Google Calendar") { addToGoogleCalendar(...) }
}

// Apple Calendar with iOS version check
if #available(iOS 17.0, *) {
    eventStore.requestFullAccessToEvents { granted, _ in saveEvent(granted) }
} else {
    eventStore.requestAccess(to: .event) { granted, _ in saveEvent(granted) }
}

// Google Calendar via URL
let url = "https://calendar.google.com/calendar/render?action=TEMPLATE&text=...&dates=...&location=..."
UIApplication.shared.open(url)
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Added EventKit import, calendar state variables, action sheet, and calendar methods

---

## Recent Changes (v257-v259)

### Draw Search Polygon Fixes & Saved Search Integration (Jan 13, 2026)

Fixed multiple issues with the draw search feature and added support for saving/restoring polygon shapes with saved searches.

**Issues Fixed:**

1. **Polygon search results overwritten by bounds search (v257)** - When performing a polygon search, the map would zoom to fit results, triggering `updateMapBounds()` which overwrote polygon results with bounds-based results.

2. **Search not resetting when shapes cleared (v258)** - Deleting shapes or clearing the drawing didn't reset the search to show all properties.

3. **Polygon shapes not saved with saved searches (v259)** - Drawing shapes and saving a search didn't preserve the shapes for later restoration.

**Solution - Timestamp-Based Cooldown (v257):**

Replaced boolean flag approach with timestamp-based cooldown to prevent bounds updates from overriding polygon searches:

```swift
// In PropertySearchViewModel
private var lastPolygonSearchTime: Date?
private let polygonSearchCooldownSeconds: TimeInterval = 3.0

func updateMapBounds(_ bounds: MapBounds) {
    // Skip bounds update if polygon search just completed
    if let lastSearch = lastPolygonSearchTime {
        let elapsed = Date().timeIntervalSince(lastSearch)
        if elapsed < polygonSearchCooldownSeconds {
            logger.debug("Skipping bounds update - polygon search cooldown active")
            return
        }
    }
    // ... rest of bounds update logic
}
```

**Solution - Clear Polygon Callback (v258):**

Added `onPolygonCleared` callback to `PropertyMapViewWithCard` that triggers when all shapes are deleted:

```swift
// In PropertyMapView
var onPolygonCleared: (() -> Void)?

private func clearDrawing() {
    polygonCoordinates = []
    completedShapes = []
    onPolygonCleared?()  // Notify to reset search
}

// In ViewModel
func clearPolygonSearch() {
    filters.polygonCoordinates = nil
    polygonShapes = []
    lastPolygonSearchTime = nil
    // Trigger bounds-based search
}
```

**Solution - Saved Search Polygon Support (v259):**

Added `polygonShapes` property to ViewModel and updated save/restore flow:

```swift
// In PropertySearchViewModel
@Published var polygonShapes: [[CLLocationCoordinate2D]] = []

// Store shapes when performing multi-shape search
func searchMultipleShapes(_ shapes: [[CLLocationCoordinate2D]]) async {
    polygonShapes = shapes  // Store for saved search
    // ... perform search
    lastPolygonSearchTime = Date()  // Set cooldown
}

// Pass shapes when saving
func saveCurrentSearch(name: String, ...) async {
    let created = try await SavedSearchService.shared.createSearch(
        name: name,
        filters: filters,
        shapes: polygonShapes,  // Include polygon shapes
        frequency: notificationFrequency
    )
}

// Restore shapes when loading
func applySavedSearch(_ search: SavedSearch) {
    let savedPolygonShapes = search.toPolygonCoordinates()
    polygonShapes = savedPolygonShapes

    if savedPolygonShapes.count > 1 {
        searchTask = Task { await searchMultipleShapes(savedPolygonShapes) }
    } else {
        searchTask = Task { await search() }
    }
}
```

**Files Changed:**
- `PropertySearchViewModel.swift` - Added `polygonShapes`, `lastPolygonSearchTime`, cooldown logic, updated save/apply methods
- `PropertyMapView.swift` - Added `onPolygonCleared` callback to `PropertyMapViewWithCard`
- `PropertySearchView.swift` - Connected `onPolygonCleared` callback
- `SavedSearchService.swift` - Added `shapes` parameter to `createSearch()`
- `SavedSearch.swift` - Added `toPolygonCoordinates()` and `hasMultiplePolygons` helpers

**Key Architecture:**
- Polygon shapes stored as `[[CLLocationCoordinate2D]]` (array of shapes, each shape is array of coordinates)
- Server stores as `polygon_shapes` JSON array of `{lat, lng}` point arrays
- 3-second cooldown prevents map zoom animation from triggering bounds search
- Multi-shape search uses OR logic (properties in ANY shape are included)

---

## Recent Changes (v246)

### Granular Pet Filters for Rentals (Jan 12, 2026)

Replaced simple Yes/No/Any pet filter with granular multi-select options for rental properties.

**New Pet Filter Options:**
| Filter | API Parameter | Listings Count |
|--------|---------------|----------------|
| Dogs Allowed | `pets_dogs=1` | 312 |
| Cats Allowed | `pets_cats=1` | 336 |
| No Pets | `pets_none=1` | 581 |
| Negotiable | `pets_negotiable=1` | 85 |

**UI Changes:**
- Pet filter now shows 4 toggle buttons in 2 rows (Dogs/Cats, No Pets/Negotiable)
- Each option can be selected independently (multi-select)
- Icons: dog.fill, cat.fill, nosign, questionmark.bubble.fill
- Selected filters appear as removable chips

**Model Changes (`PropertySearchFilters` in Property.swift):**
```swift
// Old (removed)
var petsAllowed: Bool? = nil

// New (v6.60.2)
var petsDogs: Bool = false
var petsCats: Bool = false
var petsNone: Bool = false
var petsNegotiable: Bool = false
```

**API Parameters:**
- `pets_dogs=1` - Dogs allowed (structured data + remarks fallback)
- `pets_cats=1` - Cats allowed (structured data + remarks fallback)
- `pets_none=1` - No pets allowed
- `pets_negotiable=1` - Pet policy is negotiable/conditional

**Files Changed:**
- `Core/Models/Property.swift` - Replaced `petsAllowed` with 4 granular filter bools, updated toDictionary(), activeFilterChips, removeFilter(), fromServerJSON()
- `Features/PropertySearch/Views/AdvancedFilterModal.swift` - New 2x2 pet filter button grid, updated hasRentalDetailsFilters check

**Server-Side (MLD v6.60.2):**
- Added `pets_negotiable` column to listing tables
- Added `pets_negotiable` parameter to mobile REST API
- Pet data parsed from RESO PetsAllowed field with values: "Yes", "No", "Cats OK", "Dogs OK", "Negotiable", "Call", etc.

---

## Recent Changes (v238)

### Enhanced Property Comparison Feature (Jan 12, 2026)

Significantly improved the Property Comparison feature with organized sections and more comprehensive attributes.

**What Changed:**

1. **Organized by Sections** - Comparison table now organized into 7 logical sections:
   - Price & Value (Price, Price/Sq Ft, Price Reduced)
   - Size & Layout (Bedrooms, Bathrooms, Square Feet, Stories, Lot Size)
   - Property Details (Property Type, Year Built, Style)
   - Parking (Garage Spaces, Total Parking)
   - Market Info (Status, Days on Market, Open House)
   - Features (Pool, Fireplace, Central AC, Waterfront, View, Outdoor Space)
   - Location (Neighborhood, School District)

2. **22 Comparison Attributes** (up from 9):
   - Added: Price Reduced, Stories, Property Type, Style, Total Parking, Status, Open House, Pool, Fireplace, Central AC, Waterfront, View, Outdoor Space, Neighborhood, School District

3. **Smart Section Display** - Sections only appear if at least one property has data for that section

4. **Feature Indicators** - Yes/No attributes show checkmark icons (green check for Yes, gray minus for No)

5. **Better Labels** - More descriptive labels (e.g., "Bedrooms" instead of "Beds", "Square Feet" instead of "Sq Ft")

**Files Changed:**
- `Features/PropertyComparison/Views/PropertyComparisonView.swift` - Complete rewrite with sections and expanded attributes

---

## Recent Changes (v237)

### Property Comparison Feature (Jan 11, 2026)

Added side-by-side property comparison feature for saved properties (favorites).

**What It Does:**
- Users can select 2-5 saved properties from their favorites list to compare
- Comparison view shows all properties in a horizontal carousel with detailed attribute comparison table
- Best values are highlighted (green for lowest price/DOM, blue for highest beds/baths/sqft/lot/year)
- Selection mode activates via "Compare" toolbar button when user has 2+ favorites

**New Files:**

1. **ComparisonStore.swift** (`Core/Storage/`):
   - Singleton state manager for property selection
   - Tracks selected property IDs and selection mode state
   - Enforces min (2) and max (5) property limits
   - Provides haptic feedback on selection

2. **PropertyComparisonView.swift** (`Features/PropertyComparison/Views/`):
   - Main comparison screen with property carousel header
   - Scrollable comparison table with attribute rows
   - Highlights best values (lowest price = green, highest sqft = blue, etc.)
   - Comparison attributes: Price, Beds, Baths, Sq Ft, Price/Sq Ft, Lot Size, Year Built, Days on Market, Garage

**Modified Files:**

1. **SavedPropertiesView.swift**:
   - Added "Compare" toolbar button (when 2+ properties saved)
   - Selection mode with checkbox overlays on property cards
   - Floating "Compare X Properties" button during selection
   - Selection hint message showing min/max requirements

2. **SavedPropertyCard**:
   - Added selection mode support with checkbox overlay
   - Selection state with teal border highlight
   - Tap behavior changes between navigation and selection modes

**UI Flow:**
1. User opens Saved Properties from Profile menu
2. Taps "Compare" button in toolbar (visible when 2+ properties saved)
3. Selects 2-5 properties by tapping cards
4. Taps floating "Compare X Properties" button
5. Comparison view opens as sheet with side-by-side comparison

**Files Changed:**
- `Core/Storage/ComparisonStore.swift` - NEW: Selection state manager
- `Features/PropertyComparison/Views/PropertyComparisonView.swift` - NEW: Comparison UI
- `Features/SavedProperties/Views/SavedPropertiesView.swift` - Selection mode integration

---

## Recent Changes (v236)

### Recently Viewed Properties Tracking (Jan 12, 2026)

Added server-side tracking of property views from iOS app.

**What It Does:**
- When a user views a property, the iOS app calls `POST /recently-viewed` to record the view
- Server stores: user_id, listing_id, listing_key, view timestamp, platform (ios)
- Admin dashboard shows which properties are being viewed most

**Implementation:**

1. **New API Endpoint** (`APIEndpoint.swift`):
```swift
extension APIEndpoint {
    static func recordRecentlyViewed(
        listingId: String,
        listingKey: String? = nil,
        viewSource: String = "search"
    ) -> APIEndpoint {
        var parameters: [String: Any] = [
            "listing_id": listingId,
            "view_source": viewSource,
            "platform": "ios"
        ]
        if let key = listingKey {
            parameters["listing_key"] = key
        }
        return APIEndpoint(path: "/recently-viewed", method: .post, parameters: parameters, requiresAuth: true)
    }
}
```

2. **PropertyDetailView Integration**:
```swift
private func trackPropertyView() async {
    // Existing analytics tracking...

    // Record to recently viewed for logged-in users
    await recordRecentlyViewed()
}

private func recordRecentlyViewed() async {
    let listingId = property?.mlsNumber ?? propertyId
    do {
        let _: EmptyResponse = try await APIClient.shared.request(
            .recordRecentlyViewed(
                listingId: listingId,
                listingKey: propertyId,
                viewSource: "search"
            )
        )
    } catch {
        // Silent failure - non-critical feature
    }
}
```

**Files Changed:**
- `Core/Networking/APIEndpoint.swift` - Added `recordRecentlyViewed()` endpoint
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Added `recordRecentlyViewed()` call in `trackPropertyView()`

**Server Side (MLD v6.57.0):**
- `POST /mld-mobile/v1/recently-viewed` endpoint
- Web tracking via `do_action('mld_property_viewed', $listing_id)` hook
- Admin dashboard: MLS Listings → Recently Viewed
- IP geolocation for anonymous web visitors

---

## Recent Changes (v232)

### PropertyDetailView State Management Refactoring (Jan 11, 2026)

Consolidated 11 separate `@State` boolean variables for collapsible sections into a single `Set<FactSection>` with an enum.

**Problem:** PropertyDetailView had 35+ `@State` variables, including 11 booleans just for tracking expanded/collapsed sections (history, interior, exterior, lot, parking, hoa, financial, utilities, schools, rooms, additional).

**Solution:**

1. Created `FactSection` enum with all section cases:
```swift
private enum FactSection: String, CaseIterable {
    case history, interior, exterior, lot, parking
    case hoa, financial, utilities, schools, rooms, additional
}
```

2. Replaced 11 `@State` booleans with single Set:
```swift
// BEFORE - 11 separate state variables
@State private var isHistoryExpanded = false
@State private var isInteriorExpanded = false
@State private var isExteriorExpanded = false
// ... 8 more

// AFTER - single Set
@State private var expandedSections: Set<FactSection> = []
```

3. Added helper function to create bindings:
```swift
private func sectionBinding(_ section: FactSection) -> Binding<Bool> {
    Binding(
        get: { expandedSections.contains(section) },
        set: { isExpanded in
            if isExpanded {
                expandedSections.insert(section)
            } else {
                expandedSections.remove(section)
            }
        }
    )
}
```

4. Updated all CollapsibleSection usages:
```swift
// BEFORE
CollapsibleSection(title: "Interior", isExpanded: $isInteriorExpanded)

// AFTER
CollapsibleSection(title: "Interior", isExpanded: sectionBinding(.interior))
```

**Benefits:**
- Cleaner state management with single source of truth
- Easier to add new collapsible sections
- More SwiftUI-idiomatic pattern
- Reduced @State variable count by 10

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Refactored section state management

---

## Recent Changes (v231)

### Loading States for Favorite/Hide Toggle Actions (Jan 11, 2026)

Added loading indicators for favorite and hide toggle buttons to prevent duplicate taps and provide visual feedback during API calls.

**Problem:** Users could tap favorite/hide buttons multiple times while the API call was in progress, potentially causing duplicate requests and race conditions.

**Solution:**

1. **Added loading state tracking to ViewModel:**
```swift
// PropertySearchViewModel already had these:
@Published var favoriteLoadingIds: Set<String> = []
@Published var hiddenLoadingIds: Set<String> = []

// Added loading tracking to unhideProperty():
func unhideProperty(id: String) async {
    guard !hiddenLoadingIds.contains(id) else { return }  // Prevent duplicate taps
    hiddenLoadingIds.insert(id)
    defer { hiddenLoadingIds.remove(id) }
    // ... API call
}
```

2. **Updated CompactPropertyCard with loading parameters:**
```swift
struct CompactPropertyCard: View {
    let property: Property
    let onFavoriteTap: () -> Void
    var onHideTap: (() -> Void)? = nil
    var isFavoriteLoading: Bool = false  // NEW
    var isHideLoading: Bool = false       // NEW

    // Button shows ProgressView when loading, disabled during loading
}
```

3. **Updated PropertyRow with same parameters:**
```swift
struct PropertyRow: View {
    // Same loading parameters added
    var isFavoriteLoading: Bool = false
    var isHideLoading: Bool = false
}
```

4. **Updated SavedPropertyCard and HiddenPropertyCard:**
```swift
struct SavedPropertyCard: View {
    var isLoading: Bool = false
    // Shows "Removing..." text and ProgressView when loading
}

struct HiddenPropertyCard: View {
    var isLoading: Bool = false
    // Shows "Unhiding..." text and ProgressView when loading
}
```

5. **Updated all call sites to pass loading states:**
```swift
// In PropertySearchView
PropertyCard(
    property: property,
    onFavoriteTap: { ... },
    onHideTap: { ... },
    isFavoriteLoading: viewModel.favoriteLoadingIds.contains(property.id),
    isHideLoading: viewModel.hiddenLoadingIds.contains(property.id)
)
```

**Files Changed:**
- `UI/Components/PropertyCard.swift` - Added loading parameters to CompactPropertyCard and PropertyRow
- `Features/PropertySearch/Views/PropertySearchView.swift` - Pass loading states to all card types
- `Features/SavedProperties/Views/SavedPropertiesView.swift` - Pass loading state to SavedPropertyCard
- `Features/HiddenProperties/Views/HiddenPropertiesView.swift` - Pass loading state to HiddenPropertyCard
- `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` - Added loading tracking to unhideProperty()

---

## Recent Changes (v230)

### Integration Tests for iOS ↔ Backend Parity (Jan 11, 2026)

Added comprehensive integration tests that verify iOS app correctly parses production API responses.

**New Test File:** `BMNBostonTests/IntegrationTests.swift`

**Test Classes (19 tests total):**

| Class | Tests | Purpose |
|-------|-------|---------|
| `PropertyAPIIntegrationTests` | 8 | Property search, filters (price, city, beds, status, bounds, school grade), pagination |
| `AutocompleteIntegrationTests` | 3 | City, address, and neighborhood autocomplete suggestions |
| `SchoolAPIIntegrationTests` | 2 | Property schools endpoint, health check |
| `PropertyDetailIntegrationTests` | 1 | Property detail endpoint parsing |
| `APIResponseFormatTests` | 2 | Response wrapper format, error response format |
| `FilterSerializationParityTests` | 3 | All filter types serialize, web-compatible keys, polygon format |

**Key Findings & Fixes:**

1. **Schools Health Endpoint** - API returns `{data: {status: "healthy"}}` not `{status: "ok"}`
2. **Property Detail Endpoint** - API returns property fields directly in `data`, not wrapped in `listing` object
3. **Property ID Format** - Detail endpoint uses `listing_key` (hash), not `mls_number`

**Running Integration Tests:**
```bash
xcodebuild test -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 17 Pro' \
    -only-testing:BMNBostonTests
```

**Files Added:**
- `BMNBostonTests/IntegrationTests.swift` - 19 integration tests
- Updated `project.pbxproj` - Added IntegrationTests.swift to test target

**Test Requirements:**
- Tests call production API (requires network)
- Some tests may fail if API changes response format
- Integration tests complement unit tests in `BMNBostonTests.swift`

---

## Recent Changes (v229)

### PropertyCard Performance Optimization (Jan 10, 2026)

Major performance improvements for property list rendering by eliminating per-cell allocations and caching expensive calculations.

**Changes in PropertyCard.swift:**

1. **Static NumberFormatter Singleton**
   - Added `currencyFormatter` as a static property
   - Eliminates creating new NumberFormatter for every cell render
   - Shared across all PropertyCard instances

2. **Monthly Payment Cache**
   - Added `monthlyPaymentCache` (NSCache) to store calculated payments by price
   - Avoids redundant `pow()` calculations for properties with the same price
   - Cache key: price in dollars, Value: formatted payment string

3. **Updated `formatPrice()`**
   - Now uses static formatter instead of creating new one each time

4. **Updated `calculateMonthlyPayment()`**
   - Checks cache first before calculating
   - Stores result in cache for reuse
   - Only calculates once per unique price

**Code Example:**
```swift
struct PropertyCard: View {
    // Static formatter - shared across all instances
    private static let currencyFormatter: NumberFormatter = {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter
    }()

    // Cache for monthly payment calculations
    private static let monthlyPaymentCache = NSCache<NSNumber, NSString>()

    private func calculateMonthlyPayment(price: Int?) -> String? {
        guard let price = price, price > 0 else { return nil }

        // Check cache first
        let cacheKey = NSNumber(value: price)
        if let cached = Self.monthlyPaymentCache.object(forKey: cacheKey) {
            return cached as String
        }

        // Calculate and cache
        // ... pow() calculation ...
        Self.monthlyPaymentCache.setObject(formatted as NSString, forKey: cacheKey)
        return formatted
    }
}
```

**Performance Impact:**
- Before: Every PropertyCard render created 2 NumberFormatters and called pow()
- After: Single shared formatter, pow() only calculated once per unique price
- Smoother scrolling in property lists with many items

**Files Changed:**
- `UI/Components/PropertyCard.swift` - Added static formatters and payment cache

---

## Recent Changes (v222)

### School Comparison & Trends UI Removed (Jan 10, 2026)

Removed school comparison and historical trends features from iOS due to data format and aggregation issues. These features remain available on web.

**What Was Removed from NearbySchoolsSection.swift:**
- School comparison selection UI and "Compare Schools" button
- School trends view trigger and sheet
- State variables: `selectedSchoolsForComparison`, `showComparisonView`, `showTrendsSheet`, `selectedSchoolForTrends`
- Functions: `toggleSchoolSelection()`, `isSchoolSelected()`
- Sheet modifiers for `SchoolComparisonView` and `SchoolTrendsView`

**Why Removed:**
1. **Trends API Format Issue**: API returns trends data as a dictionary keyed by subject name (`{"English Language Arts": {...}}`), but iOS model expected an array. Fixed with custom decoder, but...
2. **Data Aggregation Issue**: API returns one data point per grade per year (3 grades × 4 years = 12 points), causing charts to show scattered incomplete data instead of clear yearly trends.
3. **User Feedback**: "the trends and compare feature on ios still doesn't work well. let's remove it for now"

**Files Still Exist (but unused):**
- `SchoolTrendsView.swift` - Has working code with aggregation fix, but not accessible from UI
- `SchoolComparisonView.swift` - Has working code, but not accessible from UI
- `School.swift` - Contains `SchoolTrendsResponse`, `SubjectTrend`, `aggregatedData` computed property

**Web Alternative:**
School comparison and trends work correctly on web via `mld-schools-compare-trends.js` (v6.54.1). Users can access these features at bmnboston.com property pages.

**Files Changed:**
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Removed all comparison/trends UI elements

---

## Recent Changes (v221)

### School Trends Data Aggregation (Jan 10, 2026)

Added data aggregation for school trends to handle multiple data points per year.

**Problem:** API returns one data point per grade per year (e.g., 3 grades × 4 years = 12 points), causing charts to show scattered incomplete data.

**Fix:** Added `aggregatedData` computed property to `SubjectTrend` that groups by year and averages:

```swift
var aggregatedData: [YearlyData] {
    var yearGroups: [Int: [YearlyData]] = [:]
    for point in data {
        yearGroups[point.year, default: []].append(point)
    }
    return yearGroups.keys.sorted().compactMap { year -> YearlyData? in
        guard let points = yearGroups[year], !points.isEmpty else { return nil }
        let validPcts = points.compactMap { $0.proficientPct }
        let avgPct = validPcts.isEmpty ? nil : validPcts.reduce(0, +) / Double(validPcts.count)
        // Similar aggregation for avgScore and tested
        return YearlyData(year: year, proficientPct: avgPct, avgScore: avgScore, tested: totalTested)
    }
}
```

**Note:** This fix was implemented but the feature was subsequently removed in v222.

---

## Recent Changes (v220)

### School Trends API Parsing Fix (Jan 10, 2026)

Fixed iOS trends parsing to handle API response format mismatch.

**Problem:** iOS expected `trends` as an array, but API returns it as a dictionary keyed by subject name.

**API Response Format:**
```json
{
  "school": {...},
  "trends": {
    "English Language Arts": {"data": [...]},
    "Math": {"data": [...]}
  },
  "years": [2021, 2022, 2023, 2024]
}
```

**Fix:** Added custom `init(from decoder:)` to `SchoolTrendsResponse`:

```swift
init(from decoder: Decoder) throws {
    let container = try decoder.container(keyedBy: CodingKeys.self)
    school = try container.decode(TrendSchoolInfo.self, forKey: .school)
    years = (try? container.decode([Int].self, forKey: .years)) ?? []

    // Convert dictionary to array
    if let trendsDict = try? container.decode([String: TrendDataWrapper].self, forKey: .trends) {
        trends = trendsDict.map { (subject, wrapper) in
            SubjectTrend(subject: subject, data: wrapper.data)
        }.sorted { $0.subject < $1.subject }
    } else {
        trends = (try? container.decode([SubjectTrend].self, forKey: .trends)) ?? []
    }
}
```

**Note:** This fix was implemented but the feature was subsequently removed in v222.

---

## Recent Changes (v218)

### Notification Deduplication Fix (Jan 10, 2026)

Fixed duplicate notifications appearing in Notification Center on every login.

**Problem:** Users saw 12+ duplicate notifications on each login, with duplicates accumulating over time.

**Root Cause (Two-Sided):**

1. **iOS Side:** Multiple sync calls on login
   - `handleScenePhaseChange()` called `syncFromServer()` when app becomes active
   - `handleAuthChange()` called `syncFromServer()` when auth changes
   - `NotificationCenterView.task` called `syncFromServer()` when view appears

2. **Server Side:** Per-device logging
   - Each push notification creates ONE database entry per device token
   - User with 2 devices = 2 entries per notification
   - History API wasn't deduplicating

**Fixes Applied:**

1. **Sync Throttling** (`NotificationStore.swift`)
   - Added 10-second minimum interval between syncs
   - Prevents rapid duplicate syncs on login
   ```swift
   private let minimumSyncInterval: TimeInterval = 10.0
   private var lastSyncStartTime: Date?
   ```

2. **Removed Redundant Sync** (`BMNBostonApp.swift`)
   - Removed `syncFromServer()` from `handleScenePhaseChange()` when app becomes active
   - Notification sync still happens on login (handleAuthChange) and when Notification Center opens

**Files Changed:**
- `Core/Storage/NotificationStore.swift` - Added sync throttling
- `App/BMNBostonApp.swift` - Removed redundant sync call

**Server-Side Fixes (MLD v6.53.0):**
- `/notifications/history` now deduplicates by (user, type, listing, hour)
- Added `was_recently_sent()` check to prevent duplicate sends

---

## Recent Changes (v217)

### First Launch UX Improvements (Jan 10, 2026)

Three UX improvements for first-time users and general usability.

**1. Permission Flow - Wait Before Loading**

On first launch, the app now waits for the permissions onboarding flow to complete before loading properties. This ensures the map can center on the user's location accurately.

```swift
// In PropertySearchView.swift .task block
if !hasCompletedFirstLaunch {
    // Check if permissions onboarding is needed
    let permissionsCompleted = UserDefaults.standard.bool(forKey: permissionsOnboardingKey)
    if !permissionsCompleted {
        isWaitingForPermissions = true
        // Poll for permissions completion
        while !UserDefaults.standard.bool(forKey: permissionsOnboardingKey) {
            try? await Task.sleep(nanoseconds: 500_000_000)
        }
        isWaitingForPermissions = false
    }
    hasCompletedFirstLaunch = true
}
```

**2. Default to Map View on First Launch**

First-time users now see the map view instead of the list view. Uses `@AppStorage("hasCompletedFirstLaunch")` to track.

**3. Search Bar Tap Area Fixed**

Added `.contentShape(Rectangle())` to both map and list view search bars so the entire bar is tappable, not just the text portion.

**4. List View Toggle Button Improved**

Changed from a plain icon to a prominent pill-shaped button with "List" text in brand teal color for better visibility against the map.

```swift
HStack(spacing: 6) {
    Image(systemName: "list.bullet")
        .font(.system(size: 14, weight: .semibold))
    Text("List")
        .font(.subheadline)
        .fontWeight(.semibold)
}
.foregroundStyle(AppColors.brandTeal)
.padding(.horizontal, 14)
.padding(.vertical, 10)
.background(Color(.systemBackground))
.clipShape(Capsule())
.shadow(color: .black.opacity(0.1), radius: 4, y: 2)
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertySearchView.swift` - All four improvements

---

## Recent Changes (v216)

### Phase 2 Search UI Improvements (Jan 10, 2026)

Completed Phase 2 of the search UI improvement plan with three major features.

**1. Filter Sliders for Square Footage and Year Built**

Added visual `RangeSlider` component with dual thumbs for numeric range selection.

```swift
struct RangeSlider: View {
    @Binding var minValue: Double
    @Binding var maxValue: Double
    let range: ClosedRange<Double>
    let step: Double
    let formatValue: (Double) -> String
    // Drag gestures, value labels, haptic feedback
}
```

- Sqft slider: 0-10,000 sqft range with 100 sqft steps
- Year Built slider: 1900-2026 range
- Text fields remain for precise input
- Preset buttons still available

**2. Multiple List View Modes**

Added three view modes for property listings with persistence via `@AppStorage`.

| Mode | Description | Icon |
|------|-------------|------|
| Card | Full-width cards (default) | `rectangle.grid.1x2` |
| Grid | 2-column compact cards | `square.grid.2x2` |
| Compact | Row-style list | `list.bullet` |

Toggle available in toolbar via Menu.

**3. Toast Notification System**

Global toast notifications for user feedback, replacing alerts.

```swift
@MainActor
class ToastManager: ObservableObject {
    static let shared = ToastManager()
    @Published var currentToast: Toast?

    func success(_ message: String, icon: String = "checkmark.circle.fill")
    func error(_ message: String, icon: String = "exclamationmark.triangle.fill")
    func info(_ message: String, icon: String = "info.circle.fill")
}
```

- Used in `CreateSavedSearchSheet` for "Search saved!" confirmation
- Auto-dismisses after 3 seconds
- Appears at top of screen with spring animation

**4. Enhanced Save Search Flow**

- Auto-generated search names from filters (e.g., "Boston 2+ bed under $800K")
- Toast confirmation replaces modal alert
- Improved `suggestedName` computed property

**Files Changed:**
- `UI/Components/FilterComponents.swift` - Added `RangeSlider` component
- `Features/PropertySearch/Views/AdvancedFilterModal.swift` - Sliders for sqft/year
- `Features/PropertySearch/Views/PropertySearchView.swift` - View mode toggle and rendering
- `Core/Storage/ToastManager.swift` - NEW: Global toast state manager
- `UI/Components/ToastView.swift` - NEW: Toast UI component
- `App/BMNBostonApp.swift` - Added `ToastOverlay` to root view
- `Features/SavedSearches/Views/CreateSavedSearchSheet.swift` - Toast integration, improved auto-naming

---

## Recent Changes (v212)

### Property Share URL Fix (Jan 10, 2026)

Fixed property share URLs to use MLS number instead of listing_key hash.

**Problem:** When sharing a property, the URL used `property.id` which could contain the `listing_key` (MD5 hash) instead of the `listing_id` (MLS number). This resulted in broken URLs like:
- Wrong: `https://bmnboston.com/property/928c77fa6877d5c35c852989c83e5068/`
- Correct: `https://bmnboston.com/property/73464868/`

**Fix in PropertyDetailView.swift:**
```swift
// Before (broken)
if let url = AppConstants.propertyURL(id: property.id) {

// After (fixed - uses MLS number)
let propertyId = property.mlsNumber ?? property.id
if let url = AppConstants.propertyURL(id: propertyId) {
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Use `mlsNumber` for share URL

---

## Recent Changes (v211)

### Agent Referral Code Deep Linking (Jan 10, 2026)

Added support for agent referral links that automatically assign new users to the referring agent when they register.

**New URL Scheme:**
- Registered `bmnboston://` URL scheme in Info.plist
- Handles `bmnboston://signup?ref=CODE` deep links

**New File - ReferralCodeManager.swift:**
```swift
@MainActor
class ReferralCodeManager: ObservableObject {
    static let shared = ReferralCodeManager()

    @Published var pendingReferralCode: String?
    @Published var pendingAgentName: String?

    func storeReferralCode(_ code: String, agentName: String? = nil)
    func consumeReferralCode() -> String?
    func clearReferralCode()
    var hasReferralCode: Bool
}
```

**Deep Link Flow:**
1. User taps referral link: `bmnboston://signup?ref=STEVEN547`
2. `BMNBostonApp.handleDeepLink()` extracts referral code
3. `ReferralCodeManager.storeReferralCode()` saves to UserDefaults
4. Posts `.showRegistrationWithReferral` notification
5. User registers → `AuthViewModel.register()` includes referral code
6. Server assigns user to referring agent

**Modified Files:**
- `Info.plist` - Added `CFBundleURLTypes` with `bmnboston` scheme
- `App/BMNBostonApp.swift` - Added `.onOpenURL` handler and `handleDeepLink()` method
- `Core/Storage/ReferralCodeManager.swift` - NEW: Manages pending referral codes
- `Core/Networking/APIEndpoint.swift` - Updated `register()` to accept optional `referralCode`
- `Features/Authentication/ViewModels/AuthViewModel.swift` - Gets referral code from parameter or ReferralCodeManager

**APIEndpoint.register() Updated:**
```swift
static func register(email: String, password: String, firstName: String, lastName: String, referralCode: String? = nil) -> APIEndpoint {
    var params: [String: Any] = [
        "email": email,
        "password": password,
        "first_name": firstName,
        "last_name": lastName
    ]
    if let code = referralCode, !code.isEmpty {
        params["referral_code"] = code
    }
    return APIEndpoint(path: "/auth/register", method: .post, parameters: params, requiresAuth: false)
}
```

**New Notification Name:**
```swift
extension Notification.Name {
    static let showRegistrationWithReferral = Notification.Name("showRegistrationWithReferral")
}
```

---

## Recent Changes (v209-v210)

### Agent Referral Link Management (Jan 9-10, 2026)

Added agent referral link feature allowing agents to share personalized signup links with clients.

**New View - AgentReferralView.swift:**
- Displays agent's unique referral URL
- Copy to clipboard functionality
- Share sheet integration
- Referral statistics display (total signups, this month, last 3 months)

**New API Endpoints:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/agent/referral-link` | Get agent's referral URL and stats |
| `POST` | `/agent/referral-link` | Update custom referral code |

**Files Changed:**
- `Core/Networking/APIEndpoint.swift` - Added `agentReferralLink`, `updateReferralCode` endpoints
- `Features/Profile/Views/AgentReferralView.swift` - NEW: Referral link management UI
- `App/MainTabView.swift` - Added "Referral Link" row for agent users in Profile

---

## Recent Changes (v208)

### iOS App Open Tracking (Jan 9, 2026)

Added feature to report app opens to the server, triggering agent notifications when clients open the app.

**New Endpoint:**
```swift
// APIEndpoint.swift
static var appOpened: APIEndpoint {
    return APIEndpoint(
        path: "/app/opened",
        method: .post,
        requiresAuth: true
    )
}
```

**Implementation in BMNBostonApp.swift:**
- Added `reportAppOpened()` function that calls `POST /app/opened`
- Called from `handleScenePhaseChange()` when app becomes `.active`
- Only reports if user is authenticated (endpoint requires auth)
- Silently fails on error (non-critical feature)

**How It Works:**
1. Client opens iOS app
2. App reports to `POST /app/opened` endpoint
3. Server triggers `mld_app_opened` action (implemented in MLD v6.43.0)
4. `MLD_Agent_Activity_Notifier::handle_app_open()` checks 2-hour debounce
5. If debounce passes, agent receives push notification: "Your client just opened the app"

**Files Changed:**
- `Core/Networking/APIEndpoint.swift` - Added `appOpened` endpoint
- `App/BMNBostonApp.swift` - Added `reportAppOpened()` function and call

---

## Recent Changes (v207)

### Rich Push Notification & In-App Notification Center Fixes (Jan 9, 2026)

Fixed multiple issues with rich push notifications and in-app notification center deep linking.

**Issues Fixed:**

1. **NotificationServiceExtension not receiving image_url** - Extension ran but `image_url` was nil
2. **Image download 404 errors** - Extension received URL but image didn't exist
3. **In-app Notification Center missing deep links** - Push banner worked but history didn't navigate
4. **`tour_requested` notification type not mapped** - Fell through to `.general` with no navigation

**Root Cause 1 - Wrong Function Arguments (Server):**
```php
// WRONG - 4th arg is string, not array!
send_activity_notification($user_id, $title, $body, array("notification_type" => "test", "image_url" => "..."))

// CORRECT - 4th arg is notification_type STRING, 5th arg is context ARRAY
send_activity_notification($user_id, $title, $body, "appointment_reminder", array("image_url" => "...", "appointment_id" => 27))
```

**Root Cause 2 - Missing Type Mapping (iOS):**
The `appointment_reminder` and `tour_requested` notification types weren't mapped in `ServerNotification.toNotificationItem()`, causing them to fall through to `.general` which has no deep linking.

**Root Cause 3 - Missing appointmentId Field (iOS):**
`ServerNotification` struct didn't include `appointmentId` field, so even with correct type mapping, the appointment ID wasn't passed for navigation.

**Fixes Applied:**

1. **NotificationStore.swift** - Added notification type mappings:
```swift
case "appointment_reminder", "tour_requested":
    type = .appointmentReminder
case "agent_activity", "client_login":
    type = .agentActivity
```

2. **NotificationStore.swift** - Added `appointmentId` to `ServerNotification`:
```swift
struct ServerNotification: Decodable {
    // ... existing fields ...
    let appointmentId: Int?

    enum CodingKeys: String, CodingKey {
        // ...
        case appointmentId = "appointment_id"
    }
}
```

3. **Server (class-mld-mobile-rest-api.php)** - Added `appointment_id` to notification history response

4. **Removed debug code** - Cleaned up `[EXT]` prefix and `logDebug()` calls from NotificationService.swift

**Notification Type to iOS Enum Mapping:**

| Server `notification_type` | iOS `NotificationType` | Deep Link Target |
|---------------------------|----------------------|------------------|
| `new_listing` | `.newListing` | Property Detail |
| `price_change` | `.priceChange` | Property Detail |
| `status_change` | `.statusChange` | Property Detail |
| `open_house` | `.openHouse` | Property Detail |
| `saved_search` | `.savedSearch` | Saved Search Results |
| `appointment_reminder` | `.appointmentReminder` | Appointments Tab |
| `tour_requested` | `.appointmentReminder` | Appointments Tab |
| `agent_activity` | `.agentActivity` | Profile Tab |
| `client_login` | `.agentActivity` | Profile Tab |
| (anything else) | `.general` | No navigation |

**Debugging NotificationServiceExtension:**

The extension runs in a separate process and can't use normal logging. Use App Groups to share debug data:

```swift
// In NotificationServiceExtension - write logs
private func logDebug(_ message: String) {
    guard let defaults = UserDefaults(suiteName: "group.com.bmnboston.app") else { return }
    var logs = defaults.array(forKey: "extension_debug_logs") as? [String] ?? []
    logs.append("[\(Date())] \(message)")
    defaults.set(logs, forKey: "extension_debug_logs")
}

// In main app - read logs
if let defaults = UserDefaults(suiteName: "group.com.bmnboston.app"),
   let logs = defaults.array(forKey: "extension_debug_logs") as? [String] {
    for log in logs { print(log) }
}
```

**Key Insight - Two Separate Notification Systems:**

1. **Push notification banner** (APNs + NotificationServiceExtension)
   - Handled by `AppDelegate.userNotificationCenter(_:willPresent:)` and `didReceive`
   - Uses payload directly from APNs
   - NotificationServiceExtension downloads images for rich notifications

2. **In-app Notification Center** (fetches from server history API)
   - Handled by `NotificationStore.syncFromServer()`
   - Uses `/notifications/history` endpoint
   - Requires server to return all fields (including `appointment_id`)
   - Type mapping in `ServerNotification.toNotificationItem()` determines navigation

**Files Changed:**
- `NotificationServiceExtension/NotificationService.swift` - Removed debug code
- `BMNBoston/App/BMNBostonApp.swift` - Removed debug log printing
- `BMNBoston/Core/Storage/NotificationStore.swift` - Added type mappings and `appointmentId` field

---

## Recent Changes (v202)

### APNs Sandbox/Production Fix for TestFlight (Jan 9, 2026)

Fixed critical bug where push notifications weren't being received on TestFlight builds despite APNs returning 200.

**Problem:**
- TestFlight builds registered device tokens with `is_sandbox = true`
- Server sent these tokens to sandbox APNs endpoint
- APNs returned `BadDeviceToken` (400) because tokens from distribution builds are production tokens

**Root Cause:**
The `isAPNsSandbox()` function used `sandboxReceipt` detection:
```swift
// WRONG - sandboxReceipt is for StoreKit, NOT APNs
if let receiptURL = Bundle.main.appStoreReceiptURL {
    return receiptURL.lastPathComponent == "sandboxReceipt"  // True for TestFlight
}
```

This was incorrect because:
- `sandboxReceipt` indicates StoreKit sandbox (for in-app purchases)
- APNs environment is determined by **provisioning profile**, not receipt
- TestFlight uses **distribution** profile with `aps-environment = production`
- Device tokens from distribution builds only work with production APNs

**Fix:**
```swift
static func isAPNsSandbox() -> Bool {
    #if DEBUG
    return true   // Debug = development profile = sandbox APNs
    #else
    return false  // Release (TestFlight/App Store) = distribution profile = production APNs
    #endif
}
```

**Server Configuration:**
- Changed server APNs environment to production: `wp option update mld_apns_environment production`
- Server now correctly routes to production APNs for TestFlight/App Store tokens

**Files Changed:**
- `Core/Services/PushNotificationManager.swift` - Fixed `isAPNsSandbox()` logic

---

## Recent Changes (v197)

### Force Device Token Re-Registration on App Launch (Jan 9, 2026)

Fixed issue where push notifications weren't being received despite server successfully sending to APNs (returning 200 status).

**Problem:**
- Server sent notifications to APNs sandbox endpoint
- APNs returned 200 (success) for all notifications
- But device never received the notifications
- User confirmed: TestFlight build, permissions enabled, sandbox environment correct

**Root Cause:**
APNs returning 200 does NOT guarantee delivery - it only means Apple accepted the notification. If the device token is stale (from a previous installation or before an iOS update), APNs accepts the notification but silently fails to deliver.

The iOS app cached registration status in UserDefaults. Once `mldRegistrationStatus.isRegistered = true`, the app wouldn't re-register even if the device token had changed. This led to the server having an old token that no longer reached the device.

**Fix:**
1. Added `forceReRegister` parameter to `registerDeviceToken()` method
2. Added `forceReRegisterIfNeeded()` method that always re-registers on app launch when authenticated
3. Called from `BMNBostonApp.handleScenePhaseChange()` when app becomes active

```swift
// PushNotificationManager.swift
func registerDeviceToken(_ token: String, forceReRegister: Bool = false) async {
    let needsMldRegistration = !mldRegistrationStatus.isRegistered || tokenChanged || forceReRegister
    // ...
}

func forceReRegisterIfNeeded() async {
    guard await TokenManager.shared.isAuthenticated() else { return }
    guard let token = deviceToken, !token.isEmpty else {
        UIApplication.shared.registerForRemoteNotifications()
        return
    }
    await registerDeviceToken(token, forceReRegister: true)
}
```

**Files Changed:**
- `Core/Services/PushNotificationManager.swift` - Added `forceReRegister` parameter and `forceReRegisterIfNeeded()` method
- `App/BMNBostonApp.swift` - Call `forceReRegisterIfNeeded()` on app active

**Key Lesson:**
APNs 200 status only means "accepted for delivery", not "delivered". Stale device tokens can cause silent notification drops. Always re-register the device token on app launch to ensure the server has the current token.

---

## Recent Changes (v192-v195)

### Server-Driven Notification Center (Jan 8, 2026)

Complete implementation of server-driven notification management so notifications persist across app reinstalls and sync read/dismissed status across devices.

**v195 - Dismiss All Server Sync:**
- Added `dismissAllNotifications` endpoint to `APIEndpoint.swift`
- Added `DismissAllResponse` struct for parsing server response
- Changed `clearAll()` to async method that syncs with server
- Uses optimistic UI update (clears immediately, reverts on failure)
- Updated call sites in `NotificationCenterView.swift` and `BMNBostonApp.swift`

**v194 - Authentication State Fixes:**
- Fixed token refresh not saving user data (caused identity mismatch after app restart)
- Added `authData.user.save()` to `refreshToken()` method in `APIClient.swift`
- Added clearing of old tokens/user before new login in `AuthViewModel.swift`
- Prevents confusing state where wrong user appears after app restart

**v192-v193 - NotificationStore Server Integration:**
- Added `syncFromServer()` method to fetch notification history from server
- Server is source of truth for notification state
- `markAsRead()` and `dismiss()` now sync to server with optimistic UI updates
- New server response models: `NotificationHistoryResponse`, `ServerNotification`, `NotificationActionResponse`, `MarkAllReadResponse`
- Added server-provided fields: `serverId`, `isDismissed`, `readAt`, `dismissedAt`

**Key Architecture:**
```swift
// Server sync on app launch and notification center open
func syncFromServer() async {
    let response: NotificationHistoryResponse = try await APIClient.shared.request(
        .notificationHistory(limit: 100)
    )
    // Server notifications include is_read, is_dismissed status
    await mergeNotifications(serverNotifications, serverUnreadCount: response.unreadCount)
}

// All mutations sync to server
func markAsRead(_ notification: NotificationItem) async {
    // Optimistic update
    notifications[index].isRead = true
    // Sync to server
    try await APIClient.shared.request(.markNotificationRead(id: serverId))
}
```

**New API Endpoints Used:**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/notifications/history` | Fetch notification history with read/dismissed status |
| `POST` | `/notifications/{id}/read` | Mark notification as read |
| `POST` | `/notifications/{id}/dismiss` | Dismiss a notification |
| `POST` | `/notifications/mark-all-read` | Mark all as read |
| `POST` | `/notifications/dismiss-all` | Dismiss all notifications (v6.50.3) |

**Files Changed:**
- `Core/Storage/NotificationStore.swift` - Server sync, async mutations
- `Core/Networking/APIEndpoint.swift` - Notification endpoints
- `Core/Networking/APIClient.swift` - Token refresh user save fix
- `Features/Authentication/ViewModels/AuthViewModel.swift` - Clear state before login
- `Features/Notifications/Views/NotificationCenterView.swift` - Async clearAll
- `App/BMNBostonApp.swift` - Async clearAll on logout

---

## Recent Changes (v188-v189)

### Dynamic Tab Bar Based on User Type (Jan 8, 2026)

Implemented dynamic bottom navigation that changes based on user authentication state and user type (agent vs client).

**Tab Structure by User Type:**

| User Type | Tab 0 | Tab 1 | Tab 2 | Tab 3 |
|-----------|-------|-------|-------|-------|
| Agent | Search | Appointments | My Clients | Profile |
| Client | Search | Appointments | My Agent | Profile |
| Guest | Search | Appointments | Login | - |

**New Views Created:**

1. **LoginPromptTabView** - Shown to non-authenticated users
   - Sign In button triggers `authViewModel.isGuestMode = false` to show login
   - Create Account button presents `RegisterView` as sheet (reuses existing registration flow)
   - Clean branded UI with logo and feature description

2. **MyAgentTabView** - Shown to client users
   - Displays assigned agent's photo, name, and contact info
   - Contact action buttons: Call, Text, Email
   - Quick schedule appointment button
   - Shows properties shared by the agent
   - Handles loading and error states gracefully

3. **MyClientsTabView** - Shown to agent users
   - Wraps existing `MyClientsView` component
   - Provides full client list management

4. **PropertyDetailFromKeyView** - Helper view
   - Loads property details from `listing_key` (used for shared properties)
   - Handles loading and error states

**Key Implementation Details:**

```swift
// Dynamic tab rendering in MainTabView
var body: some View {
    TabView(selection: $selectedTab) {
        // Tab 0: Search (always)
        PropertySearchView().tag(0)

        // Tab 1: Appointments (always)
        AppointmentsView().tag(1)

        // Tab 2: Dynamic based on user type
        if let user = authViewModel.currentUser {
            if user.isAgent {
                MyClientsTabView().tag(2)
            } else {
                MyAgentTabView().tag(2)
            }
        }

        // Tab 3 (or 2 for guests): Profile or Login
        if authViewModel.isAuthenticated {
            ProfileView().tag(3)
        } else {
            LoginPromptTabView().tag(2)
        }
    }
}
```

**ProfileView Simplification:**
- Removed "My Agent" section (now has dedicated tab)
- Removed "My Clients" section (now has dedicated tab)
- Profile tab is now focused on user settings and account management

**New Notification Names:**
- `.switchToMyAgentTab` - Navigate to My Agent tab
- `.switchToMyClientsTab` - Navigate to My Clients tab

**Files Changed:**
- `BMNBoston/App/MainTabView.swift` - Major restructure with new tab views

**Bug Fix (v189):**
- Fixed "Create Account" button that was opening non-existent web URL
- Changed to present in-app `RegisterView` sheet instead

---

## Recent Changes (v187)

### Notification Center Server Sync (Jan 8, 2026)

Added server-side persistence for the notification center, allowing notifications to sync across devices and survive app reinstalls.

**New WordPress Endpoint:**
- `GET /mld-mobile/v1/notifications/history` - Fetch user's notification history
- Returns last 100 notifications ordered by date descending
- Includes all notification types: new_listing, price_change, status_change, open_house, saved_search

**iOS Implementation:**

```swift
// In NotificationCenterViewModel
func syncFromServer() async {
    guard authViewModel?.isAuthenticated == true else { return }

    do {
        let response: NotificationHistoryResponse = try await APIClient.shared.request(.notificationHistory)

        // Convert server items to local NotificationItems
        for serverItem in response.notifications {
            let localItem = NotificationItem.from(serverNotification: serverItem)
            await addNotificationIfNotExists(localItem)
        }
    } catch {
        print("Failed to sync notifications from server: \(error)")
    }
}
```

**How It Works:**
1. On app launch, `syncFromServer()` fetches notification history from server
2. Server notifications are converted to local `NotificationItem` format
3. Items are merged with local notifications (avoiding duplicates by ID)
4. Local storage updated with combined notifications

**Files Changed:**
- `BMNBoston/Core/Networking/APIEndpoint.swift` - Added `.notificationHistory` endpoint
- `BMNBoston/Features/Notifications/ViewModels/NotificationCenterViewModel.swift` - Added `syncFromServer()` method
- `BMNBoston/Core/Models/NotificationItem.swift` - Added `from(serverNotification:)` factory method

---

## Recent Changes (v180)

### In-App Notification Center New Listing Click Fix (Jan 8, 2026)

Fixed issue where tapping new listing notifications in the in-app Notification Center didn't navigate to the property detail page, while price reductions and status changes worked correctly.

**Root Cause:**
Two issues combined to cause this bug:

1. **Server Bug (MLD v6.49.4 and earlier):** The `build_property_payload()` method sent `notification_type: "saved_search"` for new listings instead of `"new_listing"`:
   ```php
   // BEFORE - incorrect
   'notification_type' => $change_type === 'new_listing' ? 'saved_search' : $change_type
   ```

2. **iOS Parsing Order Bug:** `NotificationItem.from()` checked for `saved_search_id` FIRST before checking `notification_type`. Since property notifications include both fields, they were incorrectly parsed as `.savedSearch` type (which doesn't extract `listingId`).

**Solution:**

1. **Server Fix (MLD v6.49.5):** Now sends actual `change_type` (`new_listing`, `price_change`, `status_change`)

2. **iOS Fix:** Restructured `NotificationItem.from()` to check `notification_type` FIRST for property-specific types:
   ```swift
   // Check notification_type FIRST for property-specific notifications
   if let notificationType = userInfo["notification_type"] as? String {
       switch notificationType {
       case "new_listing":
           type = .newListing
           listingId = Self.extractListingId(from: userInfo)
           listingKey = userInfo["listing_key"] as? String
           savedSearchId = userInfo["saved_search_id"] as? Int
       // ... similar for price_change, status_change, open_house
       }
   }

   // Only fall back to saved_search_id check if notification_type didn't match
   if type == .general {
       if let searchId = userInfo["saved_search_id"] as? Int {
           type = .savedSearch
           // ...
       }
   }
   ```

**Files Changed:**
- `Core/Models/NotificationItem.swift` - Restructured parsing to check `notification_type` first

**Related WordPress Fix:**
- MLD v6.49.5 - `includes/notifications/class-mld-push-notifications.php` line 841

---

## Recent Changes (v179)

### Push Notification System Audit Completion (Jan 8, 2026)

Final implementation of 7 push notification enhancement tasks from the comprehensive audit.

**Completed Tasks:**
1. ✅ Token re-registration on Settings enable (iOS)
2. ✅ Notification engagement tracking (iOS + WordPress)
3. ✅ Rich notification image failure logging
4. ✅ SNAB unified logging to MLD log table
5. ✅ Rate limit monitoring alerts
6. ✅ Cron job health visibility (already implemented)
7. ✅ Batch notification coalescing for 5+ matches

See WordPress MLD v6.49.4 changelog for server-side implementation details.

---

## Recent Changes (v177-v178)

### Notification Service Extension for Rich Push Notifications (Jan 8, 2026)

Added iOS Notification Service Extension that downloads and attaches property images to push notifications, creating rich visual notifications.

**New Files Created:**
- `NotificationServiceExtension/NotificationService.swift` - Main extension class
- `NotificationServiceExtension/Info.plist` - Extension configuration
- `NotificationServiceExtension/NotificationServiceExtension.entitlements` - App group entitlements

**How It Works:**
1. Server sends push notification with `mutable-content: 1` and `image_url` field
2. iOS routes notification to NotificationServiceExtension before display
3. Extension downloads the property image from `image_url`
4. Extension creates `UNNotificationAttachment` with the downloaded image
5. User sees rich notification with property thumbnail

**NotificationService.swift Key Methods:**
```swift
override func didReceive(_ request: UNNotificationRequest,
                        withContentHandler: @escaping (UNNotificationContent) -> Void) {
    // Extract image URL from payload (image_url, photo_url, or thumbnail_url)
    // Download and attach image to notification
}

override func serviceExtensionTimeWillExpire() {
    // Deliver best attempt content if download takes too long
}

private func downloadAndAttachImage(from url: URL, to content: UNMutableNotificationContent,
                                   completion: @escaping (UNMutableNotificationContent) -> Void) {
    // Download image, save to temp file, create UNNotificationAttachment
}
```

**Extension Bundle ID:** `com.bmnboston.app.NotificationServiceExtension`

**Server-Side Support (MLD v6.48.7):**
- Property notifications include `image_url` from `main_photo_url`
- Agent activity notifications support `image_url` from context
- All notifications already had `mutable-content: 1`

**Testing:**
- Extension only works on physical devices (not simulator)
- Requires push notification with `mutable-content: 1` and `image_url` in payload
- Image appears as thumbnail in notification

**Files Changed:**
- `BMNBoston.xcodeproj/project.pbxproj` - Added extension target, build phases, dependencies
- Created `NotificationServiceExtension/` directory with 3 files

---

## Recent Changes (v176)

### In-App Notification Center Navigation Fix (Jan 8, 2026)

Fixed issue where tapping notifications in the in-app Notification Center didn't navigate to the property detail page, even though tapping the same notification from iOS notification center worked correctly.

**Problem:**
When a user tapped a property notification (new listing, price change, etc.) from within the app's Notification Center, nothing happened. But tapping the same notification from iOS system notification center correctly navigated to the property.

**Root Cause:**
In `NotificationItem.from(userInfo:)`, the `listing_id` was extracted using:
```swift
listingId = userInfo["listing_id"] as? String
```
But the push notification payload sends `listing_id` as an **integer**, not a string. The `as? String` cast failed silently, leaving `listingId` as `nil`. Without the listing ID, the notification center couldn't navigate to the property.

**Solution:**
Added `extractListingId()` helper method that handles both integer and string values:

```swift
private static func extractListingId(from userInfo: [AnyHashable: Any]) -> String? {
    if let stringId = userInfo["listing_id"] as? String {
        return stringId
    } else if let intId = userInfo["listing_id"] as? Int {
        return String(intId)
    }
    return nil
}
```

Updated all notification type parsing to use this helper method instead of direct `as? String` casting.

**Files Changed:**
- `Core/Models/NotificationItem.swift` - Added `extractListingId()` helper, updated parsing for newListing, priceChange, statusChange, openHouse, and agentActivity types

---

## Recent Changes (v175)

### Push Notification Deep Link Navigation Fix (Jan 7, 2026)

Fixed issue where tapping a property alert notification wouldn't navigate to the property detail.

**Problem:**
When a user tapped a property notification (new listing, price change, etc.) from the notification center, the app would switch to the Search tab but wouldn't navigate to the property detail view.

**Root Cause:**
When the notification was tapped, `setPendingPropertyNavigation()` was called and the tab switch was posted. However, PropertySearchView's `onChange` handlers only fire when the view is already visible. If the user was on a different tab, the pending navigation was set but PropertySearchView wasn't visible to observe the change.

**Solution:**
Added `checkPendingNavigation()` function called from `.onAppear` to handle cases where the pending property was set before the view became visible:

```swift
.onAppear {
    checkPendingNavigation()
}

private func checkPendingNavigation() {
    let listingId = notificationStore.pendingPropertyListingId
    let listingKey = notificationStore.pendingPropertyListingKey
    guard listingId != nil || listingKey != nil else { return }

    // Small delay to ensure navigation stack is ready after tab switch
    Task {
        try? await Task.sleep(nanoseconds: 100_000_000) // 100ms
        await fetchAndNavigateToProperty(listingId: listingId, listingKey: listingKey)
    }
}
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertySearchView.swift` - Added `checkPendingNavigation()` and `.onAppear` call

---

## Recent Changes (v174)

### APNs Sandbox Auto-Detection (Jan 7, 2026)

Enhanced push notification device token registration to properly detect TestFlight vs App Store builds.

**Problem:**
The previous implementation used `#if DEBUG` to determine sandbox mode, which only worked for Debug builds. TestFlight builds are Release builds but still use the APNs sandbox environment, causing push notifications to fail for TestFlight users.

**Solution:**
Added `isAPNsSandbox()` helper method that properly detects:
- **Debug builds** → sandbox (via `#if DEBUG`)
- **TestFlight builds** → sandbox (detected via `appStoreReceiptURL` containing "sandboxReceipt")
- **App Store builds** → production

**How It Works:**
```swift
static func isAPNsSandbox() -> Bool {
    #if DEBUG
    return true  // Debug builds always use sandbox
    #else
    // TestFlight apps have receipt URL ending with "sandboxReceipt"
    if let receiptURL = Bundle.main.appStoreReceiptURL {
        return receiptURL.lastPathComponent == "sandboxReceipt"
    }
    return false  // App Store builds use production
    #endif
}
```

**Files Changed:**
- `Core/Services/PushNotificationManager.swift` - Added `isAPNsSandbox()` method, updated `registerDeviceToken()` to use it

**Backend Support (WordPress v6.48.4):**
- `wp_mld_device_tokens` table now has `is_sandbox` column
- Each token stores whether it's sandbox or production
- Push notifications are routed to correct APNs endpoint per-token
- Allows simultaneous support for TestFlight and App Store users

---

## Recent Changes (v156-158)

### TestFlight Setup & Company Branding (Jan 7, 2026)

Configured the app for TestFlight distribution and updated branding throughout the app.

**App Store Connect Configuration (v156-157):**
- Changed Bundle ID from `com.bmnboston.realestate` to `com.bmnboston.app` to match App Store Connect
- Added 1024x1024 app icon (required for App Store)
- Removed alpha channel from app icon (Apple requirement)
- Added `CFBundleIconName` to Info.plist
- Added `NSCalendarsUsageDescription` to Info.plist (iOS 16 requirement alongside existing `NSCalendarsFullAccessUsageDescription`)

**Company Branding Updates (v158):**
- Added custom splash screen with BMN + Douglas Elliman logo
- Updated login screen to display company logo instead of generic house icon
- Logo displays at 280pt max width with "Find your perfect home" tagline

**New/Modified Files:**

| File | Purpose |
|------|---------|
| `BMNBoston/Resources/LaunchScreen.storyboard` | Custom splash screen with centered logo |
| `BMNBoston/Resources/Assets.xcassets/Logo.imageset/` | Company logo asset (1563x684) |
| `BMNBoston/Resources/Assets.xcassets/AppIcon.appiconset/` | App icon (1024x1024, no alpha) |
| `BMNBoston/Info.plist` | Added CFBundleIconName, UILaunchStoryboardName, NSCalendarsUsageDescription |
| `BMNBoston/Features/Authentication/Views/LoginView.swift` | Logo instead of SF Symbol |

**LoginView.swift Changes:**
```swift
// BEFORE - generic house icon
Image(systemName: "house.fill")
    .font(.system(size: 60))
    .foregroundStyle(AppColors.primary)
Text("BMN Boston")
    .font(.largeTitle)
    .fontWeight(.bold)

// AFTER - company logo
Image("Logo")
    .resizable()
    .aspectRatio(contentMode: .fit)
    .frame(maxWidth: 280)
Text("Find your perfect home")
    .font(.subheadline)
    .foregroundStyle(.secondary)
```

**LaunchScreen.storyboard Structure:**
- White background
- Centered logo image (300x150)
- Uses Auto Layout constraints for center positioning

**TestFlight Deployment Steps:**
1. Open Xcode project
2. Product → Archive
3. Distribute App → App Store Connect → Upload
4. Wait for processing in App Store Connect
5. Build available in TestFlight

---

## Recent Changes (v154-155)

### Public Analytics Heartbeat Fix (Jan 4, 2026)

Fixed issue where iOS app users weren't appearing in the "Active Now" section of the analytics dashboard.

**Problem:** iOS app waited 60 seconds before sending first heartbeat, so presence entries expired before the first heartbeat arrived if the app was closed quickly.

**Root Cause:** `startHeartbeat()` used `Task.sleep()` BEFORE calling `sendHeartbeat()`:
```swift
// BEFORE - waits 60 seconds before first heartbeat
while !Task.isCancelled {
    try? await Task.sleep(nanoseconds: UInt64(heartbeatInterval * 1_000_000_000))
    await sendHeartbeat()
}
```

**Fix:** Send immediate heartbeat first, then continue with periodic heartbeats:
```swift
// AFTER - immediate heartbeat on app launch
await sendHeartbeat()  // Send immediately
while !Task.isCancelled {
    try? await Task.sleep(nanoseconds: UInt64(heartbeatInterval * 1_000_000_000))
    await sendHeartbeat()
}
```

**Files Changed:**
- `Core/Analytics/PublicAnalyticsService.swift` - Fixed `startHeartbeat()` to send immediately

**Related WordPress Fix (v6.39.10-11):**
- Fixed timezone mismatch in presence table queries (PHP `date()` vs WordPress `current_time()`)
- Fixed API field name mismatch (`active_count` vs `total`)

---

## Recent Changes (v141-143)

### Sprint 3: Property Sharing - Client View (Jan 3, 2026)

Added iOS support for clients to view and respond to properties shared by their agent.

**New Files:**
- `Features/SharedProperties/Views/SharedPropertiesView.swift` - Client view for shared properties with response sheet

**Modified Files:**
- `Core/Models/SharedProperty.swift` - Added SharedProperty, SharedPropertyAgent, SharedPropertyData, ClientResponse models
- `Core/Services/AgentService.swift` - Added fetchSharedProperties(), updateSharedPropertyResponse(), dismissSharedProperty() methods
- `Core/Networking/APIEndpoint.swift` - Added sharedProperties, updateSharedProperty, dismissSharedProperty endpoints
- `App/MainTabView.swift` - Added "From My Agent" row in Profile menu for client users

**New API Endpoints Used:**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/shared-properties` | Get properties shared with current user |
| `PUT` | `/shared-properties/{id}` | Update response (interested/not interested) |
| `DELETE` | `/shared-properties/{id}` | Dismiss a shared property |

**Key Models:**

```swift
struct SharedProperty: Identifiable, Codable, Equatable {
    let id: Int
    let listingKey: String
    let agentNote: String?
    let sharedAt: Date?
    let viewedAt: Date?
    let viewCount: Int
    let clientResponse: ClientResponse
    let clientNote: String?
    let agent: SharedPropertyAgent?
    let property: SharedPropertyData?
}

enum ClientResponse: String, Codable {
    case none, interested, notInterested = "not_interested"
}
```

**Bugs Fixed:**
1. Parameter name mismatch - iOS sent `client_response` but API expected `response` (v142)
2. Missing data field in dismiss response - API needed to return `data` field for iOS parsing (v142)
3. Blank response sheet on first tap - Fixed SwiftUI timing issue by using `.sheet(item:)` instead of `.sheet(isPresented:)` (v143)

**Remaining Work (Next Session):**
- Add "Share with Clients" button to PropertyDetailView (agent users only)
- Create ShareWithClientsView modal for agent to select clients
- Add "From Agent" badge to PropertyCard for shared properties

---

## Recent Changes (v139-140)

### Custom Profile Avatar & Edit Profile (Jan 3, 2026)

Enhanced ProfileView with custom avatar display and Edit Profile functionality.

**Changes in v139:**
- ProfileView now displays user's custom avatar image from API (`avatar_url` field)
- Uses `AsyncImage` with loading state and fallback to system icon
- Avatar size increased to 44x44 for better visibility

**Changes in v140:**
- Added "Edit Profile" button to Account section in ProfileView
- Opens Safari to `bmnboston.com/my-dashboard/` for web-based profile editing
- Uses external link icon to indicate browser navigation

**Modified Files:**
- `App/MainTabView.swift` - Updated ProfileView with AsyncImage for avatar, added Edit Profile button

**API Changes (WordPress MLD Plugin v6.34.3):**
- Login endpoint (`/auth/login`) now returns `avatar_url` field in user object
- `/me` endpoint now returns `avatar_url` field
- Avatar URL is fetched from `mld_agent_profiles.photo_url` table, falls back to Gravatar

**User Model Already Supported:**
```swift
struct User: Identifiable, Codable {
    let avatarUrl: String?  // Already existed, now populated by API
}
```

---

## Recent Changes (v138)

### Agent Client Management for Agent Users (Jan 2, 2026)

Added "My Clients" feature allowing agent users to view and manage their assigned clients from the iOS app.

**New Files:**
- `Features/AgentClients/Views/MyClientsView.swift` - Main client list view, client detail view, and create client form

**Modified Files:**
- `App/MainTabView.swift` - Added "My Clients" section in ProfileView for agent users, loads agent metrics
- `Core/Models/Agent.swift` - Added `AgentClient`, `ClientSavedSearch`, `ClientFavorite`, `ClientHiddenProperty`, `AgentMetrics`, `CreateClientResponse` models
- `Core/Services/AgentService.swift` - Added client management methods (fetchAgentClients, fetchClientSearches, fetchClientFavorites, fetchClientHidden, createClient, fetchAgentMetrics)
- `Core/Networking/APIEndpoint.swift` - Added agent client endpoints (agentClients, agentClientDetail, agentClientSearches, agentClientFavorites, agentClientHidden, createAgentClient, agentMetrics)

**New API Endpoints:**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/agent/clients` | List agent's clients with stats |
| `GET` | `/agent/clients/{id}` | Get client details |
| `POST` | `/agent/clients` | Create new client |
| `GET` | `/agent/clients/{id}/searches` | Get client's saved searches |
| `GET` | `/agent/clients/{id}/favorites` | Get client's favorites |
| `GET` | `/agent/clients/{id}/hidden` | Get client's hidden properties |
| `GET` | `/agent/metrics` | Get agent dashboard stats |

**Key Models:**

```swift
struct AgentClient: Identifiable, Codable, Equatable {
    let id: Int
    let email: String
    let firstName: String?
    let lastName: String?
    let phone: String?
    let searchesCount: Int
    let favoritesCount: Int
    let hiddenCount: Int
    let lastActivity: Date?
    let assignedAt: Date?
}

struct AgentMetrics: Codable {
    let totalClients: Int
    let activeClients: Int
    let totalSearches: Int
    let totalFavorites: Int
    let totalHidden: Int
    let newClientsThisMonth: Int?
    let activeSearchesThisWeek: Int?
}
```

**UI Features:**
- Client list with metrics summary (total clients, active clients, total searches)
- Client cards showing name, email, and activity counts
- Client detail view with contact actions (call, email)
- Client saved searches, favorites, and hidden properties display
- Create client form with welcome email option

---

## Recent Changes (v137)

### Agent API Response Parsing Fix (Jan 2, 2026)

Fixed issue where "My Agent" section wasn't appearing in Profile tab for client users.

**Problem:**
- `Agent.userId` was required but API doesn't return `user_id`
- `fetchMyAgent()` expected a wrapper response but API returns agent directly in `data`

**Fixes:**
- Made `userId` optional in Agent model
- Added `canBookAppointment` field from API
- Changed `fetchMyAgent()` to decode Agent directly (not wrapped)

**Files Changed:**
- `Core/Models/Agent.swift` - Made userId optional, added canBookAppointment
- `Core/Services/AgentService.swift` - Fixed decoding to use Agent directly

---

## Recent Changes (v136)

### Phase 5: Agent-Client Collaboration System (Jan 2, 2026)

Added iOS support for the Agent-Client Collaboration system, enabling agents to create saved searches for clients and clients to see agent-recommended content.

**New Files:**
- `Core/Models/Agent.swift` - Agent, AgentSummary, UserType, AgentListResponse, MyAgentResponse models
- `Core/Services/AgentService.swift` - Actor-based service for agent API operations with caching
- `UI/Components/MyAgentCard.swift` - UI components for displaying agent info (MyAgentCard, CompactAgentCard, AgentAvatarView, AgentPickBadge, AgentNotesView)

**Modified Files:**
- `Core/Models/User.swift` - Added `userType: UserType?` and `assignedAgent: AgentSummary?` fields
- `Core/Models/SavedSearch.swift` - Added collaboration fields (`createdByUserId`, `isAgentRecommended`, `agentNotes`, `ccAgentOnNotify`)
- `Core/Models/Property.swift` - Renamed `Agent` to `ListingAgent` to avoid conflict with new Agent model
- `Core/Networking/APIEndpoint.swift` - Added agent endpoints (agents, agentDetail, myAgent, emailPreferences)
- `Features/SavedSearches/Views/SavedSearchDetailView.swift` - Added Agent Pick badge and Agent Notes display

**New API Endpoints:**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/agents` | List all active agents |
| `GET` | `/agents/{id}` | Get specific agent details |
| `GET` | `/my-agent` | Get current user's assigned agent |
| `GET` | `/email-preferences` | Get user's email preferences |
| `POST` | `/email-preferences` | Update email preferences |

**Key Models:**

```swift
// Agent for collaboration system
struct Agent: Identifiable, Codable, Equatable {
    let id: Int
    let userId: Int
    let name: String
    let email: String
    let phone: String?
    let title: String?
    let photoUrl: String?
    let officeName: String?
    let bio: String?
    let snabStaffId: Int?  // For booking capability
}

// User type enum
enum UserType: String, Codable {
    case client = "client"
    case agent = "agent"
    case admin = "admin"
}

// SavedSearch collaboration fields
struct SavedSearch {
    // ... existing fields ...
    let createdByUserId: Int?
    let isAgentRecommended: Bool?
    let agentNotes: String?
    let ccAgentOnNotify: Bool?
}
```

**UI Components:**
- `MyAgentCard` - Full card with photo, info, and contact buttons (call, email, schedule)
- `CompactAgentCard` - Horizontal inline display for lists
- `AgentAvatarView` - Reusable avatar with photo or initials fallback
- `AgentPickBadge` - Orange badge for agent-recommended searches
- `AgentNotesView` - Display agent notes in a styled container

**Profile Tab Updates:**
- Added "My Agent" section that shows user's assigned agent (if they have one)
- Uses `MyAgentCard` with schedule button that opens booking flow
- Falls back to `AgentSummaryCard` if full agent data is loading

**PropertyDetailView Updates:**
- Added "Your Agent" section after listing agent section
- Shows `CompactAgentCard` for quick contact with user's assigned agent
- Subtitle: "Ask about this property" encourages inquiries

---

## Recent Changes (v135)

### Saved Search Platform Audit Improvements (Jan 2, 2026)

Comprehensive audit of the saved search system with fixes for cross-platform compatibility.

**Fixes Applied:**

1. **Price Display Bug** - Fixed price formatting in `SavedSearchDetailView.swift`
   - Added `formatPrice()` helper that properly handles millions ($2.5M) and thousands ($500K)
   - Was showing "$2K" for $2,495,000 due to integer division

2. **Neighborhood Key Consistency** - Fixed in `Property.swift`
   - Changed `toSavedSearchDictionary()` to use PascalCase `"Neighborhood"` (matching City)
   - Updated `fromServerJSON()` to handle both `"neighborhood"` and `"Neighborhood"` keys

3. **Filter Summary Display** - Enhanced `SavedSearchDetailView.swift`
   - Added listing type display (For Sale/For Rent)
   - Added status display (Active, Pending, Sold)
   - Added computed properties for iOS/web key compatibility

4. **Missing Amenities** - Updated `CreateSavedSearchSheet.swift`
   - Added 7 additional amenity types: DPR, Attached, Lender Owned, 55+, Outdoor Space, Virtual Tour, Water View

**Files Changed:**
- `Core/Models/Property.swift` - Neighborhood key PascalCase
- `Features/SavedSearches/Views/SavedSearchDetailView.swift` - Price formatting, filter summary
- `Features/SavedSearches/Views/CreateSavedSearchSheet.swift` - Additional amenities

---

## Recent Changes (v133)

### Saved Search Filter Restoration Fix (Jan 2, 2026)

Fixed bug where city and baths filters were not being restored when loading a saved search.

**Problem:**
When saving a search, iOS uses web-compatible keys (PascalCase), but when loading a saved search, it was only checking for iOS keys (lowercase). This caused filters to be lost.

**Root Cause:**
- City filter: Saved as `"City"` (web format), but `fromServerJSON` only checked for `"city"`
- Baths filter: Saved as `"baths_min"` (web format), but `fromServerJSON` only checked for `"baths"`

**Fix in `Property.swift` (`fromServerJSON` initializer):**
```swift
// City - now handles both iOS "city" and web "City" keys
if let cities = json["city"]?.stringArrayValue ?? json["City"]?.stringArrayValue {
    self.cities = Set(cities)
}

// Baths - now handles both iOS "baths" and web "baths_min" keys
if let baths = json["baths"]?.doubleValue ?? json["baths_min"]?.doubleValue {
    self.minBaths = baths
}
```

**Files Changed:**
- `Core/Models/Property.swift` - Fixed `fromServerJSON` to check both iOS and web key formats for city and baths filters

---

## Recent Changes (v129-v132)

### Hidden Properties & Saved Properties Views (Jan 1-2, 2026)

Added complete Hidden Properties feature and Saved Properties view accessible from the Profile menu.

**New Features:**
- **Hidden Properties View** - View and unhide properties you've hidden from search results
- **Saved Properties View** - View and manage favorited properties from Profile menu
- **Property Card Navigation** - Tap any card in either view to see property details
- **CDN Cache Busting** - Added timestamp parameters to `/favorites` and `/hidden` endpoints to bypass Cloudflare caching

**New Files:**
- `Features/HiddenProperties/Views/HiddenPropertiesView.swift` - Hidden properties list with unhide functionality
- `Features/SavedProperties/Views/SavedPropertiesView.swift` - Saved/favorited properties list

**API Endpoints Added:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/hidden` | Get all hidden properties |
| `POST` | `/hidden/{listing_id}` | Hide a property |
| `DELETE` | `/hidden/{listing_id}` | Unhide a property |

**Key Implementation Details:**

1. **iOS 16 Compatible Navigation**: Uses `NavigationLink(destination:)` instead of iOS 17+ `navigationDestination(item:)` for property card navigation

2. **CDN Cache Busting** (in `APIEndpoint.swift`):
```swift
static var favorites: APIEndpoint {
    let timestamp = Int(Date().timeIntervalSince1970)
    return APIEndpoint(path: "/favorites?_nocache=\(timestamp)", requiresAuth: true)
}

static var hidden: APIEndpoint {
    let timestamp = Int(Date().timeIntervalSince1970)
    return APIEndpoint(path: "/hidden?_nocache=\(timestamp)", requiresAuth: true)
}
```

3. **ViewModel Methods Added** (in `PropertySearchViewModel.swift`):
   - `loadHiddenProperties(forceRefresh:)` - Fetch hidden properties from server
   - `toggleHidden(for:)` - Hide/unhide a property with optimistic UI update
   - `unhideProperty(id:)` - Unhide a specific property

4. **toggleFavorite Fix**: Changed from requiring property to be in search results to checking `preferences.likedPropertyIds` for state, allowing favorites to be removed from the Saved Properties view

**Files Modified:**
- `Core/Networking/APIEndpoint.swift` - Added hidden endpoints + cache busting
- `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` - Added hidden properties state and methods, fixed toggleFavorite
- `App/MainTabView.swift` - Added Saved Properties and Hidden Properties rows to Profile menu

---

## Recent Changes (v111-v122)

### Appointment Booking System (Dec 27-28, 2025)

Full appointment booking, viewing, canceling, and rescheduling implemented for iOS with cross-platform support (web uses same REST API).

**New Features:**
- View upcoming and past appointments
- Book new appointments (5-step wizard flow)
- Cancel appointments with optional reason
- Reschedule appointments to new date/time
- Guest booking support (no login required)
- Integration with property showings (from PropertyDetailView)

**API Namespace:**
Appointments use the `snab/v1` REST API namespace (SN Appointment Booking plugin):
```
https://bmnboston.com/wp-json/snab/v1/...
```

**API Endpoints:**

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| `GET` | `/appointment-types` | No | List appointment types |
| `GET` | `/staff` | No | List staff members |
| `GET` | `/availability` | No | Get available time slots |
| `POST` | `/appointments` | No* | Book appointment |
| `GET` | `/appointments` | Yes | List user's appointments |
| `GET` | `/appointments/{id}` | Yes | Get appointment detail |
| `DELETE` | `/appointments/{id}` | Yes | Cancel appointment |
| `PATCH` | `/appointments/{id}/reschedule` | Yes | Reschedule appointment |
| `GET` | `/appointments/{id}/reschedule-slots` | Yes | Get reschedule availability |
| `GET` | `/portal/policy` | No | Get cancel/reschedule policies |

*Guest booking captures name/email/phone; authenticated booking links to user_id

**Key Models (`Appointment.swift`):**

```swift
struct Appointment: Identifiable, Decodable {
    let id: Int
    let typeName: String
    let status: AppointmentStatus
    let date: String           // "2025-12-30"
    let startTime: String      // "14:00:00" (database format with seconds)
    let endTime: String
    let staffName: String?
    let propertyAddress: String?
    let canCancel: Bool
    let canReschedule: Bool

    var formattedDate: String  // "December 30, 2025"
    var formattedTime: String  // "2:00 PM - 2:30 PM"
    var dateTime: Date?        // Combined date/time for sorting
    var isPast: Bool
    var isUpcoming: Bool
}

struct AppointmentType: Identifiable, Decodable {
    let id: Int
    let name: String
    let durationMinutes: Int
    let color: String?
    let requiresLogin: Bool
}

struct StaffMember: Identifiable, Decodable {
    let id: Int
    let name: String
    let title: String?
    let avatarUrl: String?
}

struct TimeSlot: Identifiable, Decodable {
    let value: String   // "14:00"
    let label: String   // "2:00 PM"
}

struct AvailabilityResponse: Decodable {
    let datesWithAvailability: [String]
    let slots: [String: [TimeSlot]]
}

struct BookingResponse: Decodable {
    let appointmentId: Int
    let status: String
    let typeName: String
    let date: String
    let time: String
}

struct RescheduleResponse: Decodable {
    let id: Int
    let status: String
    let date: String
    let rescheduleCount: Int
}

struct CancelResponse: Decodable {
    let id: Int
    let status: String
}
```

**AppointmentService (`Core/Services/AppointmentService.swift`):**

Actor-based service with caching:
- Appointment types cached for 1 hour
- Staff cached for 1 hour (keyed by typeId)
- User appointments cached for 1 minute
- Availability NOT cached (always fresh)

```swift
actor AppointmentService {
    static let shared = AppointmentService()

    func fetchAppointmentTypes(forceRefresh: Bool = false) async throws -> [AppointmentType]
    func fetchStaff(forTypeId: Int?, forceRefresh: Bool = false) async throws -> [StaffMember]
    func fetchAvailability(startDate: String, endDate: String, typeId: Int, staffId: Int?) async throws -> AvailabilityResponse
    func bookAppointment(request: BookAppointmentRequest) async throws -> BookingResponse
    func fetchUserAppointments(status: String?, page: Int, forceRefresh: Bool) async throws -> [Appointment]
    func cancelAppointment(id: Int, reason: String?) async throws
    func rescheduleAppointment(id: Int, newDate: String, newTime: String) async throws
    func fetchRescheduleSlots(appointmentId: Int, startDate: String, endDate: String) async throws -> AvailabilityResponse
}
```

**Booking Flow (BookAppointmentView):**

5-step wizard:
1. **Select Type** - Choose appointment type (Property Showing, Consultation, etc.)
2. **Select Staff** - Choose agent/staff member
3. **Select Date/Time** - Calendar picker + available time slots
4. **Contact Info** - Name, email, phone (pre-filled if logged in)
5. **Confirm** - Review and book

**Cancel Flow (CancelAppointmentSheet):**

Sheet-based cancellation with:
- Warning header (orange styling)
- Appointment info display
- Optional reason text field
- Cancel/Keep buttons with loading state
- Error display

**Reschedule Flow (RescheduleView):**

- Shows current appointment date/time
- Calendar for new date selection
- Available time slots for selected date
- Confirmation before rescheduling

**Time Format Handling (IMPORTANT):**

Database stores times as `HH:mm:ss` (e.g., "14:00:00") but some API responses use `HH:mm`. The `Appointment` model handles both:

```swift
var dateTime: Date? {
    let formatter = DateFormatter()
    // Try with seconds first (database format)
    formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
    if let date = formatter.date(from: "\(date) \(startTime)") {
        return date
    }
    // Fall back to without seconds
    formatter.dateFormat = "yyyy-MM-dd HH:mm"
    return formatter.date(from: "\(date) \(startTime)")
}
```

**Files Created:**
- `Core/Models/Appointment.swift` - All appointment models
- `Core/Services/AppointmentService.swift` - Actor-based API service
- `Features/Appointments/ViewModels/AppointmentViewModel.swift` - View state management
- `Features/Appointments/Views/AppointmentsView.swift` - Main list + CancelAppointmentSheet
- `Features/Appointments/Views/AppointmentDetailView.swift` - Single appointment view
- `Features/Appointments/Views/BookAppointmentView.swift` - Booking wizard container
- `Features/Appointments/Views/AppointmentTypeSelectionView.swift` - Step 1
- `Features/Appointments/Views/StaffSelectionView.swift` - Step 2
- `Features/Appointments/Views/DateTimeSelectionView.swift` - Step 3
- `Features/Appointments/Views/GuestInfoView.swift` - Step 4
- `Features/Appointments/Views/BookingConfirmationView.swift` - Step 5
- `Features/Appointments/Views/RescheduleView.swift` - Reschedule flow

**Files Modified:**
- `Core/Networking/APIEndpoint.swift` - Added all appointment endpoints
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Added "Schedule Showing" button
- `App/MainTabView.swift` - Updated Appointments tab

**Known Issues Fixed (v118-v122):**
- Time parsing for `HH:mm:ss` format (v118)
- Reschedule parameter names `new_date`/`new_time` (v119)
- PHP `get_available_slots()` call signature (v120)
- PHP `send_reschedule()` parameter order (v120)
- PHP `send_cancellation()` parameter (v121)
- Cancel/Reschedule response parsing with simplified response structs (v121-v122)
- Cancel reason prompt UI (v122)

---

## Cross-Platform API Integration: Lessons Learned

This section documents common pitfalls encountered when building iOS features that communicate with WordPress REST APIs. Use this as a reference when building new features.

### 1. Response Format Mismatch (CRITICAL)

**Problem:** iOS expects a specific response structure, but PHP returns something different.

**Example - Reschedule Response:**
```swift
// iOS expected full Appointment object
let appointment: Appointment = try await APIClient.shared.request(...)
// But API returned simplified response:
// {"id": 42, "status": "confirmed", "date": "2025-12-30", "reschedule_count": 1}
```

**Solution:** Create dedicated response structs that match exactly what the API returns:
```swift
struct RescheduleResponse: Decodable {
    let id: Int
    let status: String
    let date: String
    let rescheduleCount: Int

    private enum CodingKeys: String, CodingKey {
        case id, status, date
        case rescheduleCount = "reschedule_count"
    }
}
```

**Best Practice:** Before writing iOS code, test the API with `curl` and document the exact response structure:
```bash
curl -X PATCH "https://bmnboston.com/wp-json/snab/v1/appointments/42/reschedule" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"new_date":"2025-12-30","new_time":"14:00"}' | python3 -m json.tool
```

### 2. Parameter Name Mismatches

**Problem:** iOS sends parameters with different names than PHP expects.

**Example - Reschedule Parameters:**
```swift
// iOS was sending:
parameters: ["date": newDate, "time": newTime]

// But PHP expected:
$new_date = $request->get_param('new_date');
$new_time = $request->get_param('new_time');
```

**Solution:** Always check the PHP endpoint's `get_param()` calls and match exactly:
```swift
parameters: ["new_date": newDate, "new_time": newTime]
```

**Best Practice:** When creating new endpoints, use consistent naming:
- PHP: Use snake_case (`new_date`, `staff_id`, `appointment_type_id`)
- iOS: Use CodingKeys to map snake_case to camelCase

### 3. Date/Time Format Differences

**Problem:** Database stores times differently than what iOS expects.

**Example - Time Format:**
```
Database stores: "14:00:00" (HH:mm:ss)
iOS was parsing: "HH:mm" (no seconds)
Result: Parsing fails, dates return nil, all appointments appear as "upcoming"
```

**Solution:** Handle multiple formats with fallback:
```swift
var dateTime: Date? {
    let formatter = DateFormatter()

    // Try database format first (HH:mm:ss)
    formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
    if let date = formatter.date(from: "\(date) \(startTime)") {
        return date
    }

    // Fall back to API format (HH:mm)
    formatter.dateFormat = "yyyy-MM-dd HH:mm"
    return formatter.date(from: "\(date) \(startTime)")
}
```

**Best Practice:** Document the exact format in API responses. Consider normalizing in PHP before sending.

### 4. PHP Function Signature Mismatches

**Problem:** Calling PHP methods with wrong parameter order or types.

**Example - Notification Methods:**
```php
// Method signature:
public function send_reschedule($appointment_id, $old_date, $old_time, $reason = '') { ... }

// Incorrect call (missing parameters):
$notifications->send_reschedule($id, 'client');

// Correct call:
$notifications->send_reschedule($id, $appt->appointment_date, $appt->start_time);
```

**Solution:** Always check function signatures before calling. Use IDE "Go to Definition" or grep:
```bash
grep -n "function send_reschedule" includes/*.php
```

### 5. Missing `data` Field in API Responses

**Problem:** iOS `APIClient.request<T>()` expects responses wrapped in `{success: true, data: {...}}` format.

**Example - Cancel Response:**
```php
// WRONG - iOS can't decode this:
return new WP_REST_Response(array(
    'success' => true,
    'message' => 'Cancelled'
), 200);

// CORRECT - includes data field:
return new WP_REST_Response(array(
    'success' => true,
    'message' => 'Cancelled',
    'data' => array(
        'id' => $id,
        'status' => 'cancelled'
    )
), 200);
```

**Best Practice:** Use a standard response helper in PHP:
```php
private static function success_response($data, $message = '', $code = 'success') {
    return new WP_REST_Response(array(
        'success' => true,
        'code' => $code,
        'message' => $message,
        'data' => $data
    ), 200);
}
```

### 6. Testing Strategy for New Endpoints

**Step-by-step approach that would have caught these issues earlier:**

1. **Write PHP endpoint first** - Implement the full endpoint with proper response structure

2. **Test with curl** - Verify exact response format before writing iOS code:
   ```bash
   # Test unauthenticated
   curl "https://bmnboston.com/wp-json/snab/v1/appointment-types" | python3 -m json.tool

   # Test authenticated
   curl -X POST "https://bmnboston.com/wp-json/snab/v1/appointments" \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"appointment_type_id":1,...}' | python3 -m json.tool
   ```

3. **Document the response** - Copy exact JSON structure to comments in iOS model

4. **Create iOS model** - Build struct that matches documented response exactly

5. **Test error cases** - Verify error responses also match expected format:
   ```bash
   # Test missing required field
   curl -X POST "..." -d '{"appointment_type_id":1}' | python3 -m json.tool

   # Test invalid auth
   curl -X GET ".../appointments" | python3 -m json.tool
   ```

### 7. Debugging iOS-to-API Issues

**When iOS shows "Failed to parse response":**

1. **Check Xcode console** for the raw error (often includes response data)

2. **Test same request with curl** to see actual response

3. **Compare response to Swift struct** - check:
   - Field names (snake_case vs camelCase, CodingKeys)
   - Field types (Int vs String, optional vs required)
   - Nested structure (is `data` field present?)

4. **Add temporary logging** in iOS:
   ```swift
   // In APIClient, temporarily print raw response
   print("Raw response: \(String(data: data, encoding: .utf8) ?? "nil")")
   ```

### 8. Checklist for New Cross-Platform Features

Before starting iOS implementation:
- [ ] PHP endpoints are deployed and tested with curl
- [ ] Response format is documented (copy actual JSON)
- [ ] Error responses follow same format as success
- [ ] All field names are documented (snake_case)
- [ ] Date/time formats are documented

Before testing on device:
- [ ] iOS models have CodingKeys for all snake_case fields
- [ ] Optional fields marked as `?` in Swift
- [ ] Response wrapper struct matches actual API response
- [ ] Parameter names in APIEndpoint match PHP `get_param()` calls

After deployment:
- [ ] Test happy path (successful operation)
- [ ] Test error cases (validation, auth, not found)
- [ ] Verify notifications/emails sent correctly
- [ ] Check logs for any PHP errors

---

## Recent Changes (v110)

### Web-Compatible Saved Search Filter Keys (Dec 26, 2025)

Updated iOS to use web-compatible filter keys when saving searches, ensuring cross-platform compatibility.

**Problem:**
iOS-created saved searches weren't opening correctly on web because iOS used different filter keys than web:
- iOS: `city`, `min_price`, `max_price`, `property_type`, `baths`
- Web: `City`, `price_min`, `price_max`, `PropertyType`, `baths_min`

**Solution:**
Created new `toSavedSearchDictionary()` method in `PropertySearchFilters` that outputs web-compatible keys:

```swift
func toSavedSearchDictionary() -> [String: Any] {
    var dict: [String: Any] = [:]
    dict["listing_type"] = listingType.rawValue
    if !homeTypes.isEmpty { dict["PropertyType"] = Array(homeTypes) }
    if let minPrice = minPrice { dict["price_min"] = minPrice }
    if let maxPrice = maxPrice { dict["price_max"] = maxPrice }
    if !beds.isEmpty { dict["beds"] = beds.min() ?? 0 }
    if let minBaths = minBaths { dict["baths_min"] = minBaths }
    if !cities.isEmpty { dict["City"] = Array(cities) }
    // ... all other web-compatible keys
    return dict
}
```

**Key Difference:**
- `toDictionary()` - Used for API queries (iOS format, snake_case)
- `toSavedSearchDictionary()` - Used for saved search storage (web format, mixed case)

**Files Changed:**
- `Core/Models/Property.swift` - Added `toSavedSearchDictionary()` method
- `Core/Models/SavedSearch.swift` - Updated `CreateSavedSearchRequest` to use web keys
- `Core/Services/SavedSearchService.swift` - Updated `updateSearch()` to use web keys

---

## Recent Changes (v109)

### Save Search Button Visibility Fix (Dec 26, 2025)

Fixed issue where the save search button was not visible in the toolbar.

**Problem:**
The save search button (bookmark icon) was not appearing in the navigation bar.

**Root Cause:**
Multiple `ToolbarItem` elements with the same `.primaryAction` placement can cause items to be hidden or grouped in an overflow menu.

**Fixes:**
1. Changed toolbar from multiple `ToolbarItem` to single `ToolbarItemGroup` with `.topBarTrailing` placement
2. All three buttons (save, filter, map) now grouped together and always visible
3. Added "Save Search" option to map view's control panel for access in both modes

**Files Changed:**
- `Features/PropertySearch/Views/PropertySearchView.swift` - Consolidated toolbar, added save to map controls

---

## Recent Changes (v108)

### Saved Search Cross-Platform Audit Fixes (Dec 26, 2025)

Comprehensive audit of the saved search system identified and fixed multiple issues to ensure iOS and web-created searches work correctly on both platforms.

**Fixes Applied:**

1. **Map Region & City Boundaries When Applying Saved Search**
   - Added `setMapRegionForSavedSearch()` method to `PropertySearchViewModel`
   - When applying a saved search, the map now zooms to: polygon bounds (priority 1), map bounds (priority 2), or first city (priority 3)
   - City boundary polygons now load correctly when applying searches with city filters

2. **Listing Type Preservation**
   - Added `listing_type` to `PropertySearchFilters.toDictionary()`
   - Added parsing in `fromServerJSON()` to restore listing type from saved search

3. **Web/iOS Filter Key Compatibility**
   - `SavedSearch.filterSummary` now handles both iOS and web key formats:
     - `city` / `City`, `property_type` / `PropertyType`
     - `min_price` / `price_min`, `max_price` / `price_max`
   - Added school filter display to filter summary

4. **String Boolean Handling**
   - Fixed `AnyCodableValue.boolValue` to parse string booleans (`"true"`, `"1"`)
   - Fixed `intValue` and `doubleValue` to parse string numbers

5. **Enhanced Filter Displays**
   - `CreateSavedSearchSheet` now shows: Year Built, Status, School Filters, Amenities, Open Houses, Polygon
   - `SavedSearchDetailView` now shows all filter categories with both iOS and web key support

6. **Safe Conflict Resolution**
   - Fixed force unwrap crash risk in `SavedSearchService.updateSearch()`
   - Now safely handles case when search isn't in cache during conflict

**Files Changed:**
- `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` - Map region targeting
- `Core/Models/Property.swift` - Listing type serialization
- `Core/Models/SavedSearch.swift` - Web key support, string boolean parsing
- `Features/SavedSearches/Views/CreateSavedSearchSheet.swift` - Extended filter display
- `Features/SavedSearches/Views/SavedSearchDetailView.swift` - Web key support, extended filters
- `Core/Services/SavedSearchService.swift` - Safe conflict handling

---

## Recent Changes (v107)

### Saved Search Polygon Display Fix (Dec 26, 2025)

Fixed polygon shapes from saved searches not displaying on the map.

**Problem:**
When loading a saved search that included a drawn polygon area, the polygon filters were applied correctly (correct properties returned) but the polygon shape wasn't visible on the map.

**Root Cause:**
`PropertyMapViewWithCard` has its own local `@State private var polygonCoordinates` for handling user-drawn polygons. When a saved search set `viewModel.filters.polygonCoordinates`, the map view's local state wasn't synced because it was completely separate.

**Fix:**
1. Added `externalPolygonCoordinates` parameter to `PropertyMapViewWithCard` to receive polygon coordinates from the ViewModel
2. Added `.onAppear` and `.onChange` modifiers to sync the local polygon state when external coordinates change
3. Updated `PropertySearchView` to pass `viewModel.filters.polygonCoordinates` to the map view

```swift
// PropertyMapViewWithCard now accepts external polygon coordinates
var externalPolygonCoordinates: [CLLocationCoordinate2D]? = nil

// Sync on appear and change
.onAppear {
    if let external = externalPolygonCoordinates, !external.isEmpty {
        polygonCoordinates = external
    }
}
.onChange(of: externalPolygonCoordinates) { newValue in
    if let external = newValue, !external.isEmpty {
        polygonCoordinates = external
    } else {
        polygonCoordinates = []
    }
}
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertyMapView.swift` - Added `externalPolygonCoordinates` parameter and sync logic
- `Features/PropertySearch/Views/PropertySearchView.swift` - Pass `viewModel.filters.polygonCoordinates` to map view

---

## Recent Changes (v106)

### Saved Search Filters Now Apply Correctly (Dec 26, 2025)

Fixed saved searches not applying filters when tapped from the Profile menu.

**Problem:**
When tapping a saved search, it would switch to the Search tab but show ALL properties instead of filtered results.

**Root Causes (2 issues):**

1. **Separate ViewModel Instances** (CRITICAL BUG):
   - `PropertySearchView` was creating its OWN `@StateObject private var viewModel = PropertySearchViewModel()`
   - `SavedSearchesView` was using the SHARED `@EnvironmentObject var viewModel`
   - When saved search was applied, it updated the shared ViewModel
   - But PropertySearchView was using its own separate instance with default filters

2. **Web Filter Key Incompatibility**:
   - Web-created searches use different keys: `PropertyType`, `price_min`, `polygon_shapes`
   - iOS expected: `property_type`, `min_price`, `polygon`
   - Also, web sends numbers as strings (e.g., `"2495"` instead of `2495`)

**Fixes Applied:**

1. Changed `PropertySearchView` to use shared EnvironmentObject:
```swift
// BEFORE (bug):
@StateObject private var viewModel = PropertySearchViewModel()

// AFTER (fixed):
@EnvironmentObject var viewModel: PropertySearchViewModel
```

2. Added alternate key support in `fromServerJSON`:
```swift
// Price - handle both iOS keys and web keys
if let minPrice = json["min_price"]?.intValue ?? json["price_min"]?.intValue

// Property Types - handle both formats
if let types = json["property_type"]?.stringArrayValue ?? json["PropertyType"]?.stringArrayValue

// Polygon - handle web nested array format
if case .array(let shapesArray) = json["polygon_shapes"] { ... }
```

3. Fixed `AnyCodableValue.intValue` to handle string numbers:
```swift
// Handle string numbers (web sends "2495" instead of 2495)
if case .string(let value) = self { return Int(value) }
```

**Files Changed:**
- `Features/PropertySearch/Views/PropertySearchView.swift` - Use @EnvironmentObject instead of @StateObject
- `Core/Models/Property.swift` - Added alternate key support in `fromServerJSON`
- `Core/Models/SavedSearch.swift` - Fixed `intValue`/`doubleValue` to parse string numbers

---

## Recent Changes (v105)

### Saved Search Sync & Tab Navigation (Dec 26, 2025)

Implemented saved search sync between iOS and WordPress with proper navigation flow.

**Fixes:**
1. **Date Decoding Fix** - `APIClient.swift` now handles PHP's ISO8601 date format with timezone offset (`2025-12-26T09:18:36+00:00`) using a custom date decoder that tries multiple formats
2. **CDN Cache Bypass** - Added timestamp cache-busting parameter to `/saved-searches` endpoint to prevent Cloudflare from serving stale responses
3. **Tab Navigation** - When tapping a saved search, the app now switches to the Search tab so users can see results (was staying on Profile tab)

**New Notification:**
```swift
// In MainTabView.swift
extension Notification.Name {
    static let switchToSearchTab = Notification.Name("switchToSearchTab")
}
```

**Files Changed:**
- `Core/Networking/APIClient.swift` - Custom date decoder for ISO8601 with timezone offset
- `Core/Networking/APIEndpoint.swift` - Added cache-busting to savedSearches endpoint
- `App/MainTabView.swift` - Added `.switchToSearchTab` notification listener
- `Features/SavedSearches/Views/SavedSearchesView.swift` - Posts notification to switch tabs after applying search

---

## Recent Changes (v98)

### Polygon Draw Search Query Encoding Fix (Dec 25, 2025)

Fixed critical bug where draw search polygon wasn't being properly sent to the API, causing properties outside the drawn area to appear.

**Problem:**
When drawing a search area on the map, properties were appearing outside the drawn polygon. The polygon filter wasn't being applied correctly.

**Root Cause:**
The `APIClient.swift` was converting the polygon array of dictionaries into string representations:
- **Before:** `polygon[]=["lat": 42.3, "lng": -71.1]` (PHP can't parse this)
- **After:** `polygon[0][lat]=42.3&polygon[0][lng]=-71.1` (PHP parses correctly)

**Fix:**
Added recursive `encodeQueryParameter()` method in `APIClient.swift` that properly encodes:
- Arrays of dictionaries: `key[index][subkey]=value`
- Simple arrays: `key[]=value`
- Nested dictionaries: `key[subkey]=value`

**Files Changed:**
- `Core/Networking/APIClient.swift` - Added `encodeQueryParameter()` helper, refactored GET parameter encoding

---

## Recent Changes (v97)

### Property History Decoding Fix (Dec 25, 2025)

Fixed decoding error when loading property price/status history.

**Problem:**
The API returns `null` for `date` field on some history events (e.g., "Price Reduced" events without a specific date), but the `PropertyHistoryEvent` model expected a non-optional `String`.

**API Response Example:**
```json
{
  "events": [
    {"date": "2025-10-28", "event": "Listed", "price": 3599000, "change": null},
    {"date": null, "event": "Price Reduced", "price": 3299000, "change": -300000}
  ]
}
```

**Fix:**
- Changed `PropertyHistoryEvent.date` from `String` to `String?`
- Updated `formattedDate` computed property to return `String?`
- Updated `id` computed property to handle nil date
- Updated `PropertyDetailView` to conditionally show date when available

**Files Changed:**
- `Core/Models/Property.swift` - Made `date` optional in `PropertyHistoryEvent`
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Handle optional `formattedDate`

---

## Recent Changes (v96)

### MIAA Sports Data Display (Dec 22, 2025)

Added display of high school sports participation data from Massachusetts Interscholastic Athletic Association (MIAA).

**New Models:**
```swift
struct SchoolSports: Decodable {
    let sportsCount: Int
    let totalParticipants: Int
    let boysParticipants: Int
    let girlsParticipants: Int
    let sports: [SchoolSport]

    var isStrongAthletics: Bool  // Computed: sportsCount >= 15
    var summary: String          // "20 sports • 2,762 athletes"
}

struct SchoolSport: Decodable, Identifiable {
    let sport: String
    let gender: String
    let participants: Int
    var id: String { "\(sport)-\(gender)" }
}
```

**Updated NearbySchool Model:**
```swift
struct NearbySchool: Decodable, Identifiable {
    // ... existing fields ...
    let sports: SchoolSports?  // NEW - for high schools
}
```

**Display in NearbySchoolsSection:**
- Sports row appears for high schools with MIAA data
- Shows summary: "20 sports • 2,762 athletes"
- Teal color for Strong Athletics schools (15+ sports)
- Icon: sportscourt.fill

**Files Changed:**
- `Core/Models/School.swift` - Added SchoolSports, SchoolSport structs
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added sports row display

---

## Recent Changes (v95)

### Discipline Percentile Display (Dec 22, 2025)

Enhanced discipline data display to show percentile ranking comparing district to state average.

**Updated DistrictDiscipline Model:**
```swift
struct DistrictDiscipline: Decodable {
    // ... existing fields ...
    let percentile: Int?           // NEW - state percentile (0-100)
    let percentileLabel: String?   // NEW - "Very Low", "Low", "Average", etc.
}
```

**Display Updates:**
- Shows percentile label: "3.7% • Bottom 25% (Very Low)"
- Green styling for bottom 25% (safest)
- Orange styling for above average discipline rates

**Files Changed:**
- `Core/Models/School.swift` - Added percentile fields to DistrictDiscipline
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Updated discipline display

---

## Recent Changes (v94)

### District Discipline Data Display (Dec 22, 2025)

Added display of district-level discipline/school safety data in the Nearby Schools section.

**New Model - DistrictDiscipline:**
```swift
struct DistrictDiscipline: Decodable {
    let year: Int
    let enrollment: Int?
    let studentsDisciplined: Int?
    let inSchoolSuspensionPct: Double?
    let outOfSchoolSuspensionPct: Double?
    let expulsionPct: Double?
    let removedToAlternatePct: Double?
    let emergencyRemovalPct: Double?
    let disciplineRate: Double?

    var isLowDiscipline: Bool  // Computed: rate < 3.0
    var summary: String        // "Very low discipline rate", etc.
    var rateFormatted: String? // "3.7%"
}
```

**Updated SchoolDistrict Model:**
```swift
struct SchoolDistrict: Decodable, Identifiable {
    let id: Int
    let name: String
    let type: String?
    let ranking: DistrictRanking?
    let collegeOutcomes: CollegeOutcomes?
    let discipline: DistrictDiscipline?  // NEW
}
```

**Display in NearbySchoolsSection:**
- "School Safety" card appears below district info (if data available)
- Shows discipline rate with summary text (e.g., "3.7% Low discipline rate")
- Breakdown pills: Suspensions, In-School, Expulsions, Emergency
- Comparison to state average ("1.8% below state avg")
- Green styling for low discipline (<3%), orange for average/above

**Files Changed:**
- `Core/Models/School.swift` - Added DistrictDiscipline struct, updated SchoolDistrict
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added disciplineRow() display

---

## Changes (v92)

### Education Glossary Expansion (Dec 22, 2025)

Expanded the education glossary from 5 inline chips to 10 chips in 2 rows, plus a "See All 20 Terms" button that opens a full categorized glossary modal.

**New State Variables in NearbySchoolsSection:**
```swift
@State private var showFullGlossary = false
@State private var fullGlossary: GlossaryResponse?
@State private var fullGlossaryLoading = false
```

**Expanded Inline Chips (2 rows):**
- Row 1 (Rating terms): Composite Score, Letter Grades, Percentile, MCAS, Class Size
- Row 2 (Programs): AP Courses, MassCore, Attendance, Spending, Special Ed

**Full Glossary Sheet Features:**
- Fetches all 20 terms from `/wp-json/bmn-schools/v1/glossary/`
- Terms organized by category with icons:
  - Rating System (star.fill)
  - Academic Programs (book.fill)
  - Student Metrics (person.3.fill)
  - Financial (dollarsign.circle.fill)
  - School Types (building.2.fill)
- Each term shows slug as title with full definition

**API Response Structure (already supported by GlossaryResponse):**
```swift
struct GlossaryResponse: Decodable {
    let terms: [String: GlossaryTerm]  // keyed by slug
    let categories: [String: [String]] // category -> [slugs]
}
```

**Files Changed:**
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added fullGlossarySheet, expanded glossaryLinksSection, loadFullGlossary()

---

## Changes (v91)

### College Enrollment Outcomes (Dec 22, 2025)

Added display of where high school graduates go after graduation. This data comes from MA DESE E2C Hub (dataset vj54-j4q3) and shows district-level outcomes.

**New SchoolDistrict Fields:**
```swift
struct SchoolDistrict: Decodable, Identifiable {
    let id: Int
    let name: String
    let type: String?
    let ranking: DistrictRanking?         // NEW: District composite ranking
    let collegeOutcomes: CollegeOutcomes? // NEW: Where graduates go
}
```

**New CollegeOutcomes Struct:**
```swift
struct CollegeOutcomes: Decodable {
    let year: Int                      // Graduation year (e.g., 2021)
    let gradCount: Int                 // Number of graduates
    let totalPostsecondaryPct: Double  // % attending college
    let fourYearPct: Double            // % at 4-year colleges
    let twoYearPct: Double             // % at 2-year colleges
    let outOfStatePct: Double          // % out of state
    let employedPct: Double            // % employed
}
```

**New DistrictRanking Struct:**
```swift
struct DistrictRanking: Decodable {
    let compositeScore: Double?
    let percentileRank: Int?
    let stateRank: Int?
    let letterGrade: String?           // District overall grade
    let schoolsCount: Int?
    let elementaryAvg: Double?         // Average by level
    let middleAvg: Double?
    let highAvg: Double?
}
```

**Display in NearbySchoolsSection:**
- District row now shows letter grade badge if available
- "Where Graduates Go" section appears below district name (if data available)
- Shows headline: "52% attend college"
- Shows breakdown pills: 4-Year, 2-Year, Out of State, Working
- Purple-themed styling to match graduation theme

**Data Coverage:**
- 71 districts have college outcomes data (Class of 2021)
- Major districts include: Boston, Cambridge, Wellesley, Newton, Brookline

**Files Changed:**
- `Core/Models/School.swift` - Added DistrictRanking, CollegeOutcomes structs
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added collegeOutcomesRow display

---

## Changes (v86-88)

### Regional School Support (Dec 21, 2025)

Massachusetts has many cities where students attend schools in neighboring cities for certain grade levels (e.g., Nahant students attend Swampscott for middle/high school). This update adds proper handling for these regional arrangements.

**New NearbySchool Fields:**
```swift
struct NearbySchool: Decodable, Identifiable {
    // ... existing fields ...
    let city: String?           // City where school is located (for regional schools)
    let isRegional: Bool?       // True if this is a shared/regional school
    let regionalNote: String?   // e.g., "Students from Nahant attend this school"
}
```

**RankingTrend Fields Made Optional (v88 fix):**
Regional schools may have incomplete trend data. These fields are now optional:
```swift
struct RankingTrend: Decodable {
    let direction: String       // Required
    let rankChange: Int         // Required
    let scoreChange: Double?    // Optional (may be missing for regional schools)
    let percentileChange: Int?  // Optional
    let previousYear: Int?      // Optional
    let previousRank: Int?      // Optional
    let previousScore: Double?  // Optional
}
```

**Regional Note Display in NearbySchoolsSection:**
```swift
// Shows below school name for regional schools
if let regionalNote = school.regionalNote {
    HStack(spacing: 4) {
        Image(systemName: "arrow.triangle.branch")
            .font(.system(size: 10))
        Text(regionalNote)
            .font(.caption2)
            .italic()
    }
    .foregroundStyle(.blue)
}
```

**Better Error Handling:**
`NearbySchoolsSection.loadSchools()` now shows specific error messages:
- "Data format error" - JSON decoding issue
- "School data not found" - API returned 404
- "Network connection issue" - Network error
- Server error messages shown directly

**Files Changed:**
- `Core/Models/School.swift` - Added regional fields, made trend fields optional
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Regional note display, better errors

**Cities with Regional School Mappings (50+):**
Nahant→Swampscott, Boxborough→Acton, Lincoln→Sudbury, Dover→Sherborn, and many more. Full list in BMN Schools plugin `class-rest-api.php`.

---

## Changes (v85)

### School Glossary & Elementary Enhancements (Dec 21, 2025)

**Glossary Integration:**
- Added `GlossaryTerm` model with term, fullName, category, description, parentTip
- Added `fetchGlossaryTerm()` and `fetchGlossary()` methods to SchoolService
- Added glossary sheet in NearbySchoolsSection with term explanations
- Added "Learn about these ratings" section with tappable term chips (Composite Score, Letter Grades, MCAS, MassCore, Percentile)

**Elementary School Display:**
- Added `limitedDataNote` field to `DataCompleteness` model
- Elementary schools with < 3 data points show orange warning: "Rating based on limited data..."
- Different confidence thresholds for elementary (4=comprehensive, 3=good)

**Files Changed:**
- `Core/Models/School.swift` - Added GlossaryTerm, GlossaryResponse, limitedDataNote
- `Core/Networking/APIEndpoint.swift` - Added glossary() endpoint
- `Core/Services/SchoolService.swift` - Added glossary fetch methods
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added glossary sheet, info buttons, limited data warning

---

## Changes (v68)

### Map Bounds Preserved When Switching Views

Fixed bug where switching from map to list view would reset filters and show all properties instead of respecting the current map bounds.

**Problem:**
When user zoomed to an area (e.g., Marblehead) in map view and switched to list view, the list would show properties from all of MA instead of the zoomed area.

**Root Cause:**
In `PropertySearchView.swift`, the list mode button was explicitly clearing all map bounds after calling `setMapMode(false)`:
```swift
// REMOVED - was causing the bug:
viewModel.filters.mapBounds = nil
viewModel.filters.latitude = nil
viewModel.filters.longitude = nil
viewModel.filters.radius = nil
viewModel.mapBounds = nil
```

**Solution:**
1. Removed bounds-clearing code from list mode button action
2. Added `savedMapRegion` in ViewModel to preserve map region when switching modes
3. `setMapMode(false)` now triggers a search with existing bounds when switching to list
4. Map region is restored via `targetMapRegion` when switching back to map

**Files Changed:**
- `Features/PropertySearch/Views/PropertySearchView.swift` - Removed bounds clearing from button action
- `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` - Added `savedMapRegion`, updated `setMapMode()` and `updateMapBounds()`

---

## Changes (v67)

### Price Drop Indicator Format

Changed price reduction display format from "30k off" to "-$30K" for cleaner appearance.

**Files Changed:**
- `UI/Components/PropertyCard.swift`
- `Features/PropertySearch/Views/PropertyDetailView.swift`
- `Features/PropertySearch/Views/PropertyMapView.swift`

---

## Changes (v65-66)

### Bottom Sheet UX Improvements - Gesture Separation

Fixed the issue where scrolling content up in the expanded bottom sheet would inadvertently close it.

**Problem Solved:**
When scrolling content UP (finger moving down) to return to the top of the sheet, the sheet would close prematurely because `simultaneousGesture` couldn't distinguish between scroll and drag.

**Solution - Gesture Separation:**
Instead of complex two-phase tracking, we separated gestures by area:
1. **Drag handle area (50pt):** Has drag gesture for collapse/expand
2. **Expanded content:** NO drag gesture - ScrollView scrolls freely
3. **Collapsed content:** Has drag gesture for expand/dismiss

**Key Changes:**
- Removed `simultaneousGesture` from entire sheet
- Added explicit drag gesture only to drag handle and collapsed content
- Visual feedback: drag handle changes color/size when dragging to close
- Rubber-band effect for first 20pt of drag
- Haptic feedback at key transitions

**State Variables:**
```swift
@State private var showCloseIndicator: Bool = false
@State private var overscrollOffset: CGFloat = 0
```

**Thresholds:**
| Action | Threshold | Velocity |
|--------|-----------|----------|
| Collapse sheet | 50pt | 200 |
| Dismiss view | 100pt | 400 |
| Expand sheet | -50pt | -200 |

**Files Changed:**
- `Features/PropertySearch/Views/PropertyDetailView.swift` - Gesture handling rewrite

---

## Changes (v60)

### School Rankings Display
Added composite school rankings to property detail view:

- **SchoolRanking model:** New model with composite score, percentile rank, letter grade, and component scores (MCAS, graduation, MassCore, attendance, AP, growth, spending, student-teacher ratio)
- **NearbySchoolRanking:** Simplified ranking for nearby schools endpoint
- **NearbySchoolsSection:** Displays letter grade badges (A-F) with color coding and composite scores
- **API integration:** Property/schools endpoint now returns ranking data for each school

**Files Changed:**
- `Core/Models/School.swift` - Added SchoolRanking, RankingComponents, NearbySchoolRanking structs; updated NearbySchool and SchoolDetail to use new ranking structure
- `Features/PropertySearch/Views/NearbySchoolsSection.swift` - Added grade badges and score display

**Grade Color Coding:**
```swift
switch grade.prefix(1) {
case "A": return .green    // Excellent
case "B": return .blue     // Good
case "C": return .yellow   // Average
case "D": return .orange   // Below Average
default: return .red       // Needs Improvement
}
```

**Backend Requirements:**
- BMN Schools plugin v0.6.0+ with ranking calculator
- Property/schools API returns `ranking` object with `composite_score`, `percentile_rank`, `letter_grade`

---

## Changes (v44)

### Real Geographic Boundaries
Replaced approximate circular boundaries with real GeoJSON polygons from OpenStreetMap:

- **CityBoundaryService rewritten:** Now fetches real boundaries from `/boundaries/location` API
- **API integration:** Uses Nominatim-sourced data cached on WordPress server (30-day cache)
- **Local caching:** 7-day disk cache in UserDefaults with memory cache layer
- **Fallback:** Circular approximation if API fails (for cities only)
- **Multi-type support:** Cities (blue), Neighborhoods (purple), ZIP codes (green)

**Files Changed:**
- `Core/Services/CityBoundaryService.swift` - Complete rewrite for API integration
- `Core/Models/BoundaryResponse.swift` - New model for GeoJSON parsing
- `Core/Networking/APIEndpoint.swift` - Added `boundary()` endpoint
- `Features/PropertySearch/Views/PropertyMapView.swift` - Differentiated boundary styling

**New Boundary Styling:**
```swift
switch polygon.title {
case "cityBoundary":      // Blue with solid line
case "neighborhoodBoundary": // Purple with solid line
case "zipcodeBoundary":   // Green with solid line
default:                  // User-drawn search polygon (teal)
}
```

---

## RESOLVED: City Boundary Not Clearing When Filter Removed (v58 → v128)

**Issue**: When removing a city filter chip, the boundary polygon remained on the map.

**Resolution**: Fixed in v128 - city boundaries now properly clear when filter is removed. The fix involved ensuring the `@Binding` pattern correctly propagated boundary state changes to the map view.

---

## Changes (v43)

### Quick Filter Presets
Added horizontal scrolling preset chips below the search bar in list view:
- **New This Week** - Filters to listings added in last 7 days
- **Price Reduced** - Filters to listings with price reductions
- **Open Houses** - Filters to listings with scheduled open houses

Presets toggle on/off and show active state with teal fill.

### Heatmap Toggle
Added toggleable neighborhood price overlay for map view:
- "City Prices" button in map controls panel
- Shows median prices for neighborhoods when zoomed out
- Uses existing `NeighborhoodAnalytics` API endpoint

### Bug Fix: Task Self-Cancellation (v43)
Fixed critical bug where quick filters and other search triggers weren't updating the UI in list view.

**Root Cause:** The `search()` method contained `searchTask?.cancel()` at the start. When a filter toggle created a new Task assigned to `searchTask` and called `await search()`, the search method immediately cancelled itself. After the API returned, `guard !Task.isCancelled` silently exited without updating properties.

**Fix:** Removed `searchTask?.cancel()` from inside `search()`. Callers (filter toggles, etc.) already cancel before creating new tasks, so this was redundant and harmful.

---

## Changes (v33)

### Filter Defaults & Logic
- **Default Listing Type:** "Buy" (For Sale) selected on first load
- **Default Property Type:** "Residential" selected for Buy, "Residential Lease" for Rent
- **Validation:** At least one property type must remain selected (prevents empty selection)
- When switching listing types, default property type auto-selects

### Filter Label Changes (Display Only)
Labels changed for user-friendliness without affecting API queries:

| Internal Value | Display Label |
|----------------|---------------|
| For Sale | Buy |
| For Rent | Rent |
| Residential | House/Condo |
| Residential Income | Multi-Family |
| Commercial Sale | Commercial |
| Residential Lease | Residential |
| Commercial Lease | Commercial |

```swift
// In Property.swift
static func displayLabel(for propertyType: String) -> String {
    switch propertyType {
    case "Residential": return "House/Condo"
    case "Residential Income": return "Multi-Family"
    // ...
    }
}
```

### Map UI Redesign
Complete overhaul of map view interface:

- **Search bar:** Moved inline with list/map toggle and filter button at top
- **Top bar:** Transparent glass effect with 40% opacity background
- **Map extends:** Full screen to top edge (below iOS status bar)
- **Filter chips:** Removed from map view (still in list view)
- **Property count:** Moved to bottom left at 50% opacity

### Unified Map Controls Panel
Modern collapsible control panel in bottom right corner:

```swift
// Controls available in panel:
- Auto-search toggle (search as map moves)
- Map type toggle (Standard/Satellite)
- My Location button
- Draw Area toggle
```

Features:
- Collapsible with smooth spring animation
- Main button rotates 90° when expanded
- `.ultraThinMaterial` background with rounded corners
- Uses NotificationCenter for cross-view communication

```swift
// Notification names for map control communication
extension Notification.Name {
    static let mapTypeChanged = Notification.Name("mapTypeChanged")
    static let centerOnUserLocation = Notification.Name("centerOnUserLocation")
    static let toggleDrawingTools = Notification.Name("toggleDrawingTools")
}
```

### Map Type Binding
PropertyMapView now accepts `@Binding var mapType: MKMapType` to support satellite view toggle:

```swift
// In PropertyMapView updateUIView:
if mapView.mapType != mapType {
    mapView.mapType = mapType
}
```

---

## Changes (v16)

### Feature Parity with MLD Web Plugin

Major update bringing iOS property displays to feature parity with the MLD WordPress plugin.

### Property Model Enhancements
- Added `originalPrice`, `bathsFull`, `bathsHalf`, `nextOpenHouse` to Property struct
- Added computed properties: `isPriceReduced`, `priceReductionAmount`, `priceReductionPercent`, `isNewListing`, `formattedBathroomsDetailed`
- Completely rewrote `PropertyDetail` struct with 50+ new fields
- Added `PropertyHighlight` enum for feature tags (Pool, Waterfront, View, Garage, Fireplace)
- Enhanced `Agent` struct with office info
- Rewrote `OpenHouse` struct with date parsing and formatting

### PropertyCard Updates
- Added status tags overlay (New Listing, Price Reduced, Open House)
- Added MLS number display below address
- Added property highlight icons row (Pool, Waterfront, View, Garage, Fireplace)
- Added original price strikethrough for reduced listings

### PropertyDetailView Overhaul
- MLS number with tap-to-copy (checkmark animation feedback)
- Price reduction info with original price strikethrough
- Status tags section with horizontal scrolling
- Open house section with calendar integration
- Collapsible Facts & Features sections (6 categories: Interior, Exterior, Lot, Parking, HOA, Financial)
- Payment calculator with adjustable down payment, interest rate, and loan term
- Enhanced agent section with photo, office, and contact buttons

### PropertyMapCard Updates
- Added status tags row (New, Price Reduced, Open House)
- Added status badge for non-active listings
- Added MLS number display
- Updated bathroom display to use detailed format

---

## Changes (v15)

### PropertyMapCard Sizing
The map property card uses a compact horizontal layout with larger image:
- Image size: 140x110 (was 100x80)
- Horizontal layout (image left, details right)
- Compact padding for smaller overall footprint

```swift
struct PropertyMapCard: View {
    var body: some View {
        HStack(spacing: 12) {
            AsyncImage(url: property.primaryImageURL) { ... }
                .frame(width: 140, height: 110)
                .clipShape(RoundedRectangle(cornerRadius: 8))
            // ... details
        }
        .padding(12)
    }
}
```

### PropertyCard Full-Width in List View
List view property cards are now full-width:
- Image height: 220px (was 180px)
- No horizontal padding on card
- No rounded corners (full edge-to-edge)

### Auto-Select Single Result
When a search returns exactly one property (e.g., address or MLS# search), the map automatically:
1. Selects that property's annotation
2. Displays the property card
3. Zooms to that location

```swift
.onChange(of: propertiesVersion) { _ in
    if autoSelectSingleResult && properties.count == 1,
       let property = properties.first {
        // Auto-select logic
    }
}
```

### Location Button Fix
The "nearby" location button now properly re-centers on subsequent taps:

```swift
func requestLocationAndCenter() {
    pendingCenterOnUser = true
    locationManager.requestLocation()  // Request fresh location
}

func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
    guard pendingCenterOnUser, let location = locations.last else { return }
    pendingCenterOnUser = false
    centerOnLocation(location.coordinate)
}
```

### Autocomplete Parsing
API returns suggestions array directly (not wrapped):
```swift
let apiSuggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(
    .autocomplete(term: query)
)
let suggestions = apiSuggestions.map { $0.toSearchSuggestion() }
```

### Filter Support
New filter types supported in applySuggestion():
- `.address` - Sets `filters.address` for exact match
- `.mlsNumber` - Sets `filters.mlsNumber` for exact match
- `.streetName` - Sets `filters.streetName` for partial match

---

## Dark Mode Implementation

### Overview
The app supports Light/Dark mode with a simple toggle in the Profile tab. Brand teal is brighter in dark mode for better visibility.

### AppearanceManager (`Core/Storage/AppearanceManager.swift`)
- Singleton `@MainActor` class managing appearance state
- Persists preference to UserDefaults with key `"com.bmnboston.appearanceMode"`
- `AppearanceMode` enum: `.light` and `.dark`

```swift
@StateObject private var appearanceManager = AppearanceManager.shared
// Apply in BMNBostonApp:
.environmentObject(appearanceManager)
.preferredColorScheme(appearanceManager.colorScheme)
```

### Adaptive Colors (`UI/Styles/Colors.swift`)

| Color | Light | Dark |
|-------|-------|------|
| `brandTeal` | #0891B2 | #22D3EE |
| `brandTealHover` | #0E7490 | #67E8F9 |
| `textPrimary` | #000000 | #FFFFFF |
| `textSecondary` | #374151 | #D1D5DB |
| `textMuted` | #6B7280 | #9CA3AF |
| `border` | #E5E7EB | #374151 |
| `markerActive` | #4A5568 | #E2E8F0 |
| `activeStatus` | #059669 | #10B981 |
| `pendingStatus` | #D97706 | #F59E0B |
| `soldStatus` | #DC2626 | #EF4444 |

Semantic colors:
- `shimmerBase` - skeleton loading background
- `shimmerHighlight` - shimmer animation highlight
- `shadowLight` / `shadowMedium` - adaptive shadow colors
- `overlayBackground` - semi-transparent overlay

### UIColor Extensions for Map (`PropertyMapView.swift`)
Map markers use UIColor extensions for trait collection support:
- `UIColor.adaptiveBrandTeal`
- `UIColor.adaptiveMarkerActive`
- `UIColor.adaptiveMarkerBorder`
- `UIColor.adaptiveMarkerArchived`

---

## Map View Implementation

### Smart Annotation Diffing
Map pins use a smart diff approach to prevent flickering:

```swift
// In updateUIView:
let existingPropertyIds = Set(existingPropertyAnnotations.flatMap { $0.properties.map { $0.id } })
if existingPropertyIds != newPropertyIds {
    // Only update changed annotations
}
```

### Map Bounds Search
When map moves, `onBoundsChanged` callback triggers `viewModel.updateMapBounds(bounds)` which:
1. Validates bounds (skips invalid/too small)
2. Sets `filters.mapBounds`
3. Clears `latitude`, `longitude`, `radius`

### "Search This Area" Button
When `mapSearchEnabled` is false, a "Search This Area" button appears after panning:
```swift
if mapSearchEnabled {
    viewModel.updateMapBounds(bounds)
} else {
    pendingBounds = bounds
    showSearchAreaButton = true
}
```

### Selection State Clearing
Pin selection clears when properties change:
```swift
@Published var propertiesVersion: Int = 0  // Increments on each search result
// PropertyMapView observes this and clears selectedAnnotation
```

---

## Services

### GeocodingService (`Core/Services/GeocodingService.swift`)
Actor-based service for geocoding location searches:
- Caches geocoding results
- Returns appropriate zoom level per location type
- Used by `applySuggestion()` to auto-zoom map

```swift
actor GeocodingService {
    static let shared = GeocodingService()
    func regionForLocation(_ location: String, type: SearchSuggestion.SuggestionType) async -> MKCoordinateRegion?
}
```

### CityBoundaryService (`Core/Services/CityBoundaryService.swift`)
Actor-based service for fetching city boundary polygons:
- Creates approximate circular boundary polygons
- Has pre-defined radii for Greater Boston cities
- Boundaries shown with dashed blue outline on map

### SchoolService (`Core/Services/SchoolService.swift`)
Actor-based service for fetching Massachusetts school data:
- Fetches nearby schools grouped by level (Elementary, Middle, High)
- Uses separate API namespace (`bmn-schools/v1` instead of `mld-mobile/v1`)
- Caches results for 30 minutes
- Returns `PropertySchoolsData` with schools array and district info

---

## Schools Integration (v58+)

### Overview
The app displays nearby schools on property maps and in property details. School data comes from the BMN Schools WordPress plugin which has its own REST API namespace.

### Key Files

| Purpose | File |
|---------|------|
| School Models | `Core/Models/School.swift` |
| School Service | `Core/Services/SchoolService.swift` |
| Schools Section | `Features/PropertySearch/Views/NearbySchoolsSection.swift` |
| School Pins | `Features/PropertySearch/Views/PropertyMapView.swift` |

### API Namespace Difference (IMPORTANT)

Schools use a **different API namespace** than property endpoints:
- **Properties:** `https://bmnboston.com/wp-json/mld-mobile/v1/...`
- **Schools:** `https://bmnboston.com/wp-json/bmn-schools/v1/...`

In `APIEndpoint.swift`, schools endpoints use the `useBaseURL: true` flag:

```swift
case propertySchools(lat: Double, lng: Double, radius: Double)

var path: String {
    case .propertySchools(let lat, let lng, let radius):
        return "property/schools?lat=\(lat)&lng=\(lng)&radius=\(radius)"
}

var useBaseURL: Bool {
    switch self {
    case .propertySchools:
        return true  // Uses bmn-schools/v1 namespace
    default:
        return false // Uses mld-mobile/v1 namespace
    }
}
```

### School Models (`Core/Models/School.swift`)

```swift
// Response from /property/schools endpoint
struct PropertySchoolsData: Codable {
    let schools: SchoolsByLevel     // elementary, middle, high arrays
    let district: SchoolDistrict?
}

// Individual school with ranking and highlights
struct NearbySchool: Codable, Identifiable {
    let id: Int
    let name: String
    let level: String              // "Elementary", "Middle", "High"
    let grades: String?            // "K-5", "6-8", "9-12"
    let distance: Double           // Miles from property
    let compositeScore: Double?    // 0-100 ranking score
    let letterGrade: String?       // A+, A, B+, etc.
    let stateRankFormatted: String? // "#67 of 843"
    let percentileContext: String?  // "Top 25%"
    let trend: RankingTrend?       // Year-over-year change
    let dataCompleteness: DataCompleteness? // Confidence indicator
    let benchmarks: RankingBenchmarks?  // vs state/category avg
    let demographics: NearbySchoolDemographics?
    let highlights: [SchoolHighlight]? // Notable features
}

// Trend direction and change
struct RankingTrend: Codable {
    let direction: String          // "up", "down", "stable"
    let rankChange: Int
    let rankChangeText: String     // "Improved 5 spots from last year"
    let trendIcon: String          // "arrow.up", "arrow.down"
}

// Data completeness indicator
struct DataCompleteness: Codable {
    let componentsAvailable: Int
    let componentsTotal: Int
    let confidenceLevel: String    // "comprehensive", "good", "limited"
    let shortLabel: String         // "6/8 factors"
    let limitedDataNote: String?   // Warning for elementary schools
}

// Benchmark comparisons
struct RankingBenchmarks: Codable {
    let stateAverage: Double?
    let vsState: String?           // "+12.3 above state avg"
}

// School highlight chips
struct SchoolHighlight: Codable, Identifiable {
    let type: String               // "ap", "ratio", "diversity", etc.
    let text: String
    let shortText: String
    let detail: String?
    let icon: String               // SF Symbol name
    let priority: Int
    var id: String { type }
}

// Glossary term
struct GlossaryTerm: Codable, Identifiable {
    let term: String
    let fullName: String
    let category: String
    let description: String
    let parentTip: String
    var id: String { term }
}

// For map pins
struct MapSchool: Codable, Identifiable {
    let id: Int
    let name: String
    let level: String
    let lat: Double
    let lng: Double
    let mcasScore: Double?
}

// District info with optional ranking
struct SchoolDistrict: Codable {
    let id: Int
    let name: String
    let ranking: DistrictRanking?
}
```

### Map School Pins

School pins are displayed on the map when the schools toggle is enabled:

```swift
// In PropertyMapView
@Binding var showSchools: Bool
@Binding var schools: [MapSchool]

// SchoolAnnotation for map display
class SchoolAnnotation: NSObject, MKAnnotation {
    let school: MapSchool
    var coordinate: CLLocationCoordinate2D { ... }
    var title: String? { school.name }
    var subtitle: String? { school.level }
}

// School marker styling in mapView(_:viewFor:)
if annotation is SchoolAnnotation {
    // Yellow/gold markers for schools
    // Different shades based on MCAS score
}
```

### Schools Toggle in Map Controls

The map control panel includes a schools toggle button:
- Icon: `graduationcap.fill`
- Fetches schools when enabled
- Displays school pins alongside property pins
- Schools persist across map pans (don't refresh with bounds)

### NearbySchoolsSection (`Features/PropertySearch/Views/NearbySchoolsSection.swift`)

Displays schools grouped by level in PropertyDetailView with comprehensive ranking information:

**Features:**
- Letter grade badges with color coding (A=green, B=blue, C=yellow, D=orange, F=red)
- Percentile context display ("A- (Top 25%)")
- State rank display ("#67 of 843")
- Trend text ("Improved 5 spots from last year")
- Data completeness indicator with confidence level
- Limited data warning for elementary schools (orange text)
- Benchmark comparison ("Above state average +12.3")
- Demographics row (students, diversity %, free lunch %)
- Horizontal scrolling highlight chips
- Glossary info links section ("Learn about these ratings")
- Glossary sheet with term explanations and parent tips

```swift
struct NearbySchoolsSection: View {
    let latitude: Double
    let longitude: Double
    let city: String?

    @State private var schoolsData: PropertySchoolsData?
    @State private var showGlossarySheet = false
    @State private var glossaryTerm: GlossaryTerm?

    var body: some View {
        // Groups: Elementary Schools, Middle Schools, High Schools
        // Each shows: Grade badge, Name, Percentile, State Rank, Score,
        // Trend, Benchmarks, Data Completeness, Demographics, Highlights
        // Footer: Glossary chips (Composite Score, Letter Grades, MCAS, etc.)
    }
}
```

### Schools API Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/property/schools?lat=X&lng=Y&radius=2` | Schools for property detail (grouped by level, with rankings) |
| `/schools/map?bounds=S,W,N,E` | Schools for map display (minimal data) |
| `/schools/{id}` | Full school detail with MCAS history |
| `/districts/for-point?lat=X&lng=Y` | District for coordinates |
| `/glossary/` | All education terms with definitions and parent tips |
| `/glossary/?term=mcas` | Single term lookup |

### MCAS Score Display

MCAS scores are displayed as percentages:
- **80%+**: Excellent (green)
- **60-79%**: Good (yellow)
- **Below 60%**: Needs improvement (orange)

```swift
func mcasColor(score: Double) -> Color {
    switch score {
    case 80...: return .green
    case 60..<80: return .yellow
    default: return .orange
    }
}
```

### School Quality Filters in Property Search

The app includes school quality filters in AdvancedFilterModal:

**Active Filters (v79):**
- Near A-rated school (within 1 mi) - Elementary, Middle, High
- Near A or B-rated school (within 1 mi) - Elementary, Middle, High

These use the `property_near_top_school()` method which checks if any school of the specified level and grade is within 1 mile of the property.

**Disabled Filter: District Average Grade**

A "School District Average Grade" filter was implemented but disabled due to incomplete data.

**Why Disabled:**
- Only 23 of 300+ districts have school ranking data computed
- The ranking data requires `district_id` to be set on schools, but only 67 schools have this field populated
- Cities without district data would return no results, making the filter unreliable

**Implementation (ready for when data is complete):**

iOS UI code is in `AdvancedFilterModal.swift` (commented out):
```swift
// School District Average Grade
VStack(alignment: .leading, spacing: 8) {
    Text("School District Average Grade")
    HStack {
        ForEach(["Any", "A", "B", "C"]) { grade in
            // Grade selection buttons
            localFilters.schoolGrade = grade == "Any" ? nil : grade
        }
    }
}
```

PHP backend is in `class-mld-bmn-schools-integration.php`:
- `get_district_average_grade_by_city($city)` - Returns average grade for city's district
- `get_all_district_averages()` - Cached map of district_id => letter_grade
- `get_city_to_district_map()` - Cached map of city name => district_id
- Filter param: `school_grade=A` (or B, C)

**To Enable:**
1. Populate `district_id` field for all schools in `wp_bmn_schools` table
2. Ensure all districts have schools with rankings in `wp_bmn_school_rankings`
3. Clear transient caches: `mld_city_to_district_map`, `mld_all_district_averages`
4. Uncomment the District Average Grade UI in `AdvancedFilterModal.swift`

**Currently Available Districts (23):**
Wellesley (94%), Natick (97%), Walpole (75%), Franklin (70%), Boston (34%), Cambridge (39%), and 17 others.

---

## API Response Mapping

| API Returns | Model Property | CodingKey |
|-------------|----------------|-----------|
| `id` | `id` | `case id` |
| `dom` | `dom` | `case dom` |
| `status` | `standardStatus` | `case standardStatus = "status"` |
| `agent` | `listingAgent` | `case listingAgent = "agent"` |
| `photos` | `photos` | Array of URL strings |

---

## Common Patterns

### Decode flexible photo formats
```swift
if let photoStrings = try? container.decodeIfPresent([String].self, forKey: .photos) {
    photos = photoStrings
} else if let photoObjects = try? container.decodeIfPresent([PhotoObject].self, forKey: .photos) {
    photos = photoObjects.map { $0.url }
}
```

### Map search bounds
```swift
// API uses bounds=south,west,north,east (NOT lat/lng/radius)
filters.mapBounds = bounds
filters.latitude = nil
filters.longitude = nil
filters.radius = nil
```

### Search Task Cancellation
```swift
private var searchTask: Task<Void, Never>?

func search() async {
    searchTask?.cancel()
    searchTask = Task { /* search logic */ }
}
```

---

## Build Commands

### Simulator
```bash
cd /Users/bmnboston/Development/BMNBoston/ios
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15 Pro' \
    -configuration Debug build
```

### Device (Physical iPhone)
```bash
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS,id=00008140-00161D3A362A801C' \
    -allowProvisioningUpdates build
```

### Install to Device
```bash
xcrun devicectl device install app \
    --device 00008140-00161D3A362A801C \
    /Users/bmnboston/Library/Developer/Xcode/DerivedData/BMNBoston-bbzegnadblobusbgqyucefnwatpe/Build/Products/Debug-iphoneos/BMNBoston.app

xcrun devicectl device process launch \
    --device 00008140-00161D3A362A801C \
    com.bmnboston.app
```

### List Connected Devices
```bash
xcrun devicectl list devices
```

---

## Team/Signing

| Setting | Value |
|---------|-------|
| Team ID | TH87BB2YU9 |
| Developer | steve@bmnboston.com |
| Code Sign Style | Automatic |
| Signing Identity | Apple Development: steve@bmnboston.com (5FQKZH82J3) |

---

## File Structure

```
BMNBoston/
├── App/
│   ├── BMNBostonApp.swift          # App entry, injects AppearanceManager
│   ├── ContentView.swift           # Root view with auth check
│   ├── MainTabView.swift           # Tab bar + ProfileView with appearance settings
│   └── Environment.swift           # API environment config
├── Core/
│   ├── Models/
│   │   ├── Property.swift          # Main property model + PropertySearchFilters
│   │   ├── User.swift
│   │   ├── SavedSearch.swift
│   │   └── Appointment.swift
│   ├── Networking/
│   │   ├── APIClient.swift
│   │   ├── APIEndpoint.swift
│   │   ├── APIError.swift
│   │   └── TokenManager.swift
│   ├── Services/
│   │   ├── GeocodingService.swift  # Location geocoding
│   │   └── CityBoundaryService.swift # City boundary polygons
│   ├── Storage/
│   │   ├── KeychainManager.swift
│   │   └── AppearanceManager.swift # Dark mode management
│   └── Constants/
│       ├── SearchConstants.swift
│       └── AppConstants.swift
├── Features/
│   ├── Authentication/
│   │   ├── ViewModels/AuthViewModel.swift
│   │   └── Views/LoginView.swift
│   └── PropertySearch/
│       ├── ViewModels/PropertySearchViewModel.swift
│       └── Views/
│           ├── PropertySearchView.swift    # Main search with list/map toggle
│           ├── PropertyDetailView.swift    # Property detail (hides tab bar)
│           ├── PropertyMapView.swift       # Map, PropertyMapCard, PropertyMapViewWithCard
│           ├── AdvancedFilterModal.swift
│           └── SearchAutocompleteView.swift
└── UI/
    ├── Components/
    │   ├── PropertyCard.swift      # Full-width cards for list view
    │   └── FilterComponents.swift
    └── Styles/
        └── Colors.swift            # Adaptive color system
```

---

## Related Documentation

- Main project guide: `/CLAUDE.md`
- WordPress API: `/wordpress/wp-content/plugins/mls-listings-display/CLAUDE.md`
- Schools API: `/wordpress/wp-content/plugins/bmn-schools/CLAUDE.md`
