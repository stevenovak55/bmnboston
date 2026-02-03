# iOS Build & Deploy

## Build Commands

### Simulator

```bash
cd ~/Development/BMNBoston/ios

xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15' build
```

### Physical Device

```bash
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS,id=00008140-00161D3A362A801C' \
    -allowProvisioningUpdates build
```

Device ID for iPhone 16 Pro: `00008140-00161D3A362A801C`

## Device Deployment

### Install App

```bash
xcrun devicectl device install app \
    --device 00008140-00161D3A362A801C \
    /Users/bmnboston/Library/Developer/Xcode/DerivedData/BMNBoston-*/Build/Products/Debug-iphoneos/BMNBoston.app
```

### Launch App

```bash
xcrun devicectl device process launch \
    --device 00008140-00161D3A362A801C \
    com.bmnboston.realestate
```

### View Console Logs

```bash
xcrun devicectl device process launch --console-pty \
    --device 00008140-00161D3A362A801C \
    com.bmnboston.realestate
```

## Testing

```bash
xcodebuild test -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'platform=iOS Simulator,name=iPhone 15'
```

## Version Management

### Bump Version Number

The version number appears in 6 places in `project.pbxproj`:

```
CURRENT_PROJECT_VERSION = 128;
```

Use Claude Code's `replace_all` to update all occurrences:
- Old: `CURRENT_PROJECT_VERSION = 128;`
- New: `CURRENT_PROJECT_VERSION = 129;`

### Adding New Swift Files

When creating new `.swift` files, you MUST manually add them to `project.pbxproj`:

1. Add `PBXBuildFile` entry in build file section
2. Add `PBXFileReference` entry in file reference section
3. Add file reference to appropriate group
4. Add build file reference to `PBXSourcesBuildPhase` files array

## Environment Configuration

The app uses `Environment.swift` for configuration:

```swift
enum Environment {
    static let apiBaseURL = "https://bmnboston.com/wp-json"
    static let isProduction = true
}
```

**All testing is done against production** - there is no staging environment.

## Troubleshooting

### "Profile has not been explicitly trusted"
iPhone Settings > General > VPN & Device Management > Trust certificate

### Build fails with signing error
Ensure Xcode has the correct development team selected and provisioning profiles are up to date.

### App crashes on launch
Check console logs using `devicectl` command above.
