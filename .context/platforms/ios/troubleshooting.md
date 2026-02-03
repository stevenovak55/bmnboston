# iOS Troubleshooting

## Task Self-Cancellation Bug

**This is the #1 iOS bug source.** See [Critical Pitfalls](../../cross-cutting/critical-pitfalls.md#3-task-self-cancellation-bug-ios).

### Problem

When a Task calls an async function that cancels `searchTask`, it cancels itself.

### Wrong Pattern

```swift
func toggleFilter() {
    searchTask?.cancel()  // Cancel previous
    searchTask = Task {   // Create new task
        await search()    // search() cancels searchTask = THIS task!
    }
}

func search() async {
    searchTask?.cancel()  // BAD: Cancels itself!
    // API call...
    guard !Task.isCancelled else { return }  // Exits silently
}
```

### Correct Pattern

```swift
func toggleFilter() {
    searchTask?.cancel()  // Caller handles cancellation
    searchTask = Task {
        await search()    // search() does NOT cancel
    }
}

func search() async {
    // Don't cancel searchTask here
    // API call...
}
```

---

## Photo Decoding Issues

### Problem
API returns photos in different formats: `[String]` or `[{url, caption, order}]`.

### Solution
Use flexible decoding:

```swift
if let photoStrings = try? container.decodeIfPresent([String].self, forKey: .photos) {
    photos = photoStrings
} else if let photoObjects = try? container.decodeIfPresent([PhotoObject].self, forKey: .photos) {
    photos = photoObjects.map { $0.url }
}
```

---

## Date Decoding Issues

### Problem
API returns dates in ISO8601 format with timezone offset: `2025-12-26T09:18:36+00:00`

### Solution
Custom date decoder in `APIClient.swift`:

```swift
let decoder = JSONDecoder()
decoder.dateDecodingStrategy = .custom { decoder in
    let container = try decoder.singleValueContainer()
    let dateString = try container.decode(String.self)
    // Parse with multiple formatters
}
```

---

## Map Bounds vs Lat/Lng

### Problem
API expects `bounds=south,west,north,east` format, NOT lat/lng/radius.

### Solution
```swift
// Use bounds parameter
filters.mapBounds = bounds
filters.latitude = nil
filters.longitude = nil

// Format: ?bounds=42.2,-71.2,42.4,-71.0
```

---

## CodingKey Mismatches

### Problem
API returns different field names than expected.

### Common Mappings

| API Returns | Model Property | CodingKey |
|-------------|----------------|-----------|
| `id` | `id` | Default |
| `dom` | `dom` | Default |
| `status` | `standardStatus` | `case standardStatus = "status"` |
| `agent` | `listingAgent` | `case listingAgent = "agent"` |

---

## Autocomplete Response Format

### Problem
Autocomplete returns array directly, not wrapped in object.

### Correct Usage
```swift
let suggestions: [AutocompleteSuggestion] = try await APIClient.shared.request(
    .autocomplete(term: query)
)
// NOT: response.suggestions
```

---

## City Boundary Not Clearing

### Problem (Fixed v128)
Removing city filter chip didn't remove boundary polygon from map.

### Solution
Fixed in v128 - city boundaries now properly clear when filter removed.

---

## Common Build Issues

### "Profile has not been explicitly trusted"
iPhone Settings > General > VPN & Device Management > Trust certificate

### Signing errors
1. Open Xcode
2. Select project
3. Signing & Capabilities
4. Verify team and provisioning profile

### Module not found
1. Clean build folder (Cmd+Shift+K)
2. Delete DerivedData
3. Rebuild
