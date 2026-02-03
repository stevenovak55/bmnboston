# Property Detail Bottom Sheet Crash - Debug Documentation

**Created:** 2026-01-18
**Status:** UNRESOLVED
**Current Version:** 303

## Issue Description

The app crashes when opening the property details bottom sheet (tapping on a property in the list). This started after implementing the "Unified Property Details Implementation Plan" which added:
- `PropertyTypeCategory` enum
- `FactSection` enum
- Refactored section state management
- New sections: Rental Details, Investment Metrics, Disclosures

## Root Cause Hypothesis

CLAUDE.md Pitfall #31: SwiftUI ViewBuilder Limitations
- `let` statements inside ViewBuilder closures cause type-checking failures and runtime crashes
- Nested ternary operators can cause ViewBuilder type-checking issues
- `ForEach` with `id: \.self` crashes when there are duplicate values

## File Location

**Primary file:** `/Users/bmnboston/Development/BMNBoston/ios/BMNBoston/Features/PropertySearch/Views/PropertyDetailView.swift`
- ~3,469 lines
- Contains the bottom sheet implementation

## What Has Been Tried (All FAILED to fix crash)

### Round 1 (Previous Session)
- Fixed `petPolicyBadges` - extracted `let` statements
- Fixed `investmentMetricsSection` - extracted `let` statements

### Round 2 (Previous Session)
- Fixed HOA section
- Fixed `virtualTourSection`
- Fixed `photoBackground`

### Round 3 (Previous Session)
- Fixed contact sheet
- Fixed `agentSection`
- Fixed `agentOnlyInfoSection`
- Fixed `contactSection`

### Round 4 (Previous Session)
- Fixed `body` property - extracted `collapsedHeight` and `expandedHeight`
- Fixed `bottomSheet` - split into wrapper + `bottomSheetContent()`
- Fixed `statusTagsSection` - extracted `formatPriceReduction()`

### Round 5 (Current Session)
- Fixed nested ternary operators in `soldStatisticsSection` - created helper functions:
  - `soldComparisonIcon(for:)`
  - `soldComparisonLabel(for:)`
  - `soldComparisonColor(for:)`

### Round 6 (Current Session)
- Fixed `ForEach` with `id: \.self` in `rentalDetailsSection` (line ~2327)
  - Changed to use `Array(enumerated())` with `id: \.offset`
  - Added `parseRentIncludes()` helper
- Fixed `ForEach` with `id: \.self` in `featuresSection` (line ~1309)
  - Changed to use `Array(enumerated())` with `id: \.offset`

## Areas Already Investigated (Appeared Safe)

- All `@ViewBuilder` functions checked
- Button action closures (NOT ViewBuilder context - safe)
- Force unwraps (found safe ones with guards)
- Array index accesses
- `PropertyTypeCategory` enum
- `FactSection` enum
- `CollapsibleSection` component
- `FlowLayout` custom layout
- `.onAppear` and `.task` modifiers

## Potential Areas Still To Investigate

1. **More `let` statements in ViewBuilder contexts** - may have missed some
2. **Complex conditional logic** in ViewBuilder that causes type inference issues
3. **State initialization** - `expandedSections` Set initialization timing
4. **Computed properties** accessed during view construction
5. **Optional chaining** in ViewBuilder that might fail
6. **The new enums themselves** - initialization or case matching issues
7. **Memory issues** - too many views being created at once
8. **Lazy loading** - sections that should be lazy but aren't

## Key Patterns to Look For

```swift
// BAD - let in ViewBuilder
var body: some View {
    let value = someComputation()  // CRASH
    Text(value)
}

// BAD - ForEach with duplicate IDs
ForEach(items, id: \.self) { item in  // CRASH if duplicates

// BAD - Nested ternaries in ViewBuilder
Image(systemName: x ? "a" : y ? "b" : "c")  // Can crash

// GOOD - Extract to helper
private func computeValue() -> String { ... }
var body: some View {
    Text(computeValue())  // Safe
}
```

## Files to Reference

1. **PropertyDetailView.swift** - The crashing view
2. **Property.swift** - `PropertyDetail` model at `/Users/bmnboston/Development/BMNBoston/ios/BMNBoston/Core/Models/Property.swift`
3. **Plan file** - `/Users/bmnboston/.claude/plans/transient-strolling-bonbon.md`
4. **CLAUDE.md** - `/Users/bmnboston/Development/BMNBoston/CLAUDE.md` (Pitfall #31)

## Debugging Approach for Next Session

1. **Add crash logging** - Wrap sections in do/catch or add print statements
2. **Binary search** - Comment out half the sections to isolate which one crashes
3. **Xcode debugger** - Run from Xcode to get actual crash stack trace
4. **Simplify** - Create minimal reproduction by removing sections one by one

## Build Commands

```bash
# Build
cd /Users/bmnboston/Development/BMNBoston/ios
xcodebuild -project BMNBoston.xcodeproj -scheme BMNBoston \
    -destination 'generic/platform=iOS' \
    -configuration Debug \
    CODE_SIGNING_ALLOWED=YES \
    DEVELOPMENT_TEAM=HQUB5Y4Y69 \
    CODE_SIGN_IDENTITY="Apple Development" \
    build 2>&1 | tail -50

# Install on device
xcrun devicectl device install app --device 00008140-00161D3A362A801C \
    /Users/bmnboston/Library/Developer/Xcode/DerivedData/BMNBoston-bbzegnadblobusbgqyucefnwatpe/Build/Products/Debug-iphoneos/BMNBoston.app
```

## Version History

- v301 - Before unified property details
- v302 - Initial unified property details (crash introduced)
- v303 - ForEach fixes (still crashing)
