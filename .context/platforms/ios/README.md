# iOS App Development

Quick reference for iOS app development.

## Project Info

| Setting | Value |
|---------|-------|
| Bundle ID | `com.bmnboston.app` |
| Team ID | TH87BB2YU9 |
| Deployment Target | iOS 16.0 |
| Current Version | v348 |
| Architecture | SwiftUI + MVVM |
| **App Store** | https://apps.apple.com/us/app/bmn-boston/id6745724401 |

## Key Files

| Purpose | Path |
|---------|------|
| App Entry | `App/BMNBostonApp.swift` |
| Environment | `App/Environment.swift` |
| API Client | `Core/Networking/APIClient.swift` |
| Token Manager | `Core/Networking/TokenManager.swift` |
| Search ViewModel | `Features/PropertySearch/ViewModels/PropertySearchViewModel.swift` |
| Map View | `Features/PropertySearch/Views/PropertyMapView.swift` |
| Property Model | `Core/Models/Property.swift` |
| School Model | `Core/Models/School.swift` |

## Documentation

- [Build & Deploy](build-deploy.md) - Build commands, device deployment
- [Models](models.md) - Swift data models
- [Services](services.md) - Service layer
- [Troubleshooting](troubleshooting.md) - Common iOS issues

## Quick Commands

```bash
# Build for simulator
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15' build

# Install to device
xcrun devicectl device install app \
    --device 00008140-00161D3A362A801C \
    /path/to/BMNBoston.app
```

## Version Bumping

Update `CURRENT_PROJECT_VERSION` in `project.pbxproj` (6 occurrences):
```
CURRENT_PROJECT_VERSION = N+1;
```

Use `replace_all` to update all at once.

## Critical Rules

1. **Never cancel searchTask from within search()** - See [Troubleshooting](troubleshooting.md#task-self-cancellation)
2. **Always test against production API** - Not localhost
3. **Add new files to project.pbxproj** - Xcode doesn't auto-add
