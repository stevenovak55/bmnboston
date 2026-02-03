# SwiftUI ViewBuilder Stack Overflow: PropertyDetailView Case Study

**Date:** January 2026
**Affected File:** `PropertyDetailView.swift`
**Versions:** v304-v306
**Severity:** Critical (App Crash)

---

## Executive Summary

PropertyDetailView (~3,500 lines) experienced persistent stack overflow crashes during SwiftUI's runtime type metadata resolution. The root cause was an extremely deep and complex generic type hierarchy created by ViewBuilder functions with many nested views. The fix involved extracting large ViewBuilder functions into separate `View` structs to create opaque type boundaries.

---

## The Problem

### Symptoms

1. **Initial Crash (v304):** App crashed when scrolling the expanded bottom sheet in property detail view
2. **After First Fix Attempt (v305):** App crashed immediately when opening ANY property detail page
3. **Crash Type:** `EXC_BAD_ACCESS (SIGSEGV)` - Thread stack size exceeded
4. **Crash Location:** `swift_getTypeByMangledName` during type metadata resolution

### Crash Stack Trace Pattern

```
Thread 0 Crashed:
0   libswiftCore.dylib    swift_getTypeByMangledNameImpl
1   libswiftCore.dylib    swift::_swift_buildDemanglingForMetadata
2   libswiftCore.dylib    swift_getTypeByMangledNode
... (hundreds of recursive frames)
N   BMNBoston             PropertyDetailView.bottomSheetContent(property:)
N+1 BMNBoston             PropertyDetailView.body.getter
```

The key indicator was the recursive pattern of:
- `swift_getTypeByMangledName`
- `decodeMangledType`
- `decodeGenericArgs`

Repeating until stack exhaustion.

---

## Root Cause Analysis

### How SwiftUI ViewBuilder Creates Type Hierarchies

SwiftUI's `@ViewBuilder` transforms view code into nested generic types:

```swift
// This simple code:
VStack {
    Text("Hello")
    Text("World")
}

// Becomes this type:
VStack<TupleView<(Text, Text)>>
```

For more complex views with conditionals:

```swift
// This code:
VStack {
    if condition {
        Text("A")
    } else {
        Text("B")
    }
    Text("C")
}

// Becomes:
VStack<TupleView<(_ConditionalContent<Text, Text>, Text)>>
```

### The Problem with PropertyDetailView

PropertyDetailView had several large `@ViewBuilder` functions:

1. **`bottomSheetContent(property:)`** - Container with conditional branches
2. **`expandedContent(property:maxHeight:)`** - 20+ sections in a VStack
3. **`collapsedContent(property:)`** - Multiple nested views
4. **Individual section functions** - Each adding 10+ more nested views

The resulting type hierarchy looked something like:

```
VStack<
  TupleView<(
    _ConditionalContent<
      // expandedContent branch
      ScrollView<
        VStack<
          TupleView<(
            // priceSection - 15+ nested views
            VStack<TupleView<(..., ..., _ConditionalContent<...>, ...)>>,
            // statusTagsSection - 10+ nested views
            HStack<TupleView<(ForEach<...>, _ConditionalContent<...>)>>,
            // 18 more sections, each with similar depth
            ...
          )>
        >
      >,
      // collapsedContent branch
      VStack<TupleView<(..., ..., ...)>>
    >,
    // drag handle, etc.
    ...
  )>
>
```

### Why This Causes Stack Overflow

When SwiftUI needs to resolve the `some View` return type, the Swift runtime must:

1. Parse the mangled type name
2. Recursively resolve each generic argument
3. Each `_ConditionalContent`, `TupleView`, `ForEach`, etc. adds stack frames
4. With 20+ sections, each with 10+ nested views, the recursion depth exceeds the stack limit

The crash happens at **runtime**, not compile time. The compiler can build the type (slowly), but when the runtime tries to resolve it for the first time, the call stack overflows.

---

## Why Previous Fix Attempts Failed

### Attempt 1: Extracting `let` statements

```swift
// Before
if isExpanded {
    let hasData = property.hoaFee != nil
    if !hasData { Text("No data") }
}

// After
private func hasDataToDisplay() -> Bool { ... }
if isExpanded {
    if !hasDataToDisplay() { Text("No data") }
}
```

**Why it failed:** This fixed ViewBuilder compilation issues but didn't reduce the runtime type resolution depth. The type hierarchy remained equally deep.

### Attempt 2: Extracting section functions to View structs (incomplete)

In v305, we extracted 9 section views (`PriceSectionView`, `StatusTagsSectionView`, etc.) but left the main container functions (`collapsedContent`, `expandedContent`) as functions returning `some View`.

**Why it failed:** The crash moved from `expandedContent` to `collapsedContent`. Both branches of the `bottomSheetContent` conditional still needed to be resolved, and `collapsedContent` still had a complex type hierarchy.

---

## The Correct Solution

### Key Insight: Opaque Type Boundaries

Each `struct SomeView: View` creates an **opaque type boundary**. When the Swift runtime encounters `SomeView`, it doesn't need to resolve its internal `body` type - it just sees "SomeView".

```swift
// This creates deep type hierarchy:
func expandedContent() -> some View {
    VStack {
        priceSection()      // Type: VStack<TupleView<(...)>>
        statusSection()     // Type: HStack<TupleView<(...)>>
        // ... 18 more sections
    }
}
// Full type must be resolved, including ALL nested types

// This creates opaque boundary:
struct ExpandedContentView: View {
    var body: some View {
        VStack {
            PriceSectionView()    // Type: PriceSectionView (opaque!)
            StatusSectionView()   // Type: StatusSectionView (opaque!)
            // ...
        }
    }
}
// Each child is just its struct name - no deep resolution needed
```

### Implementation

**1. Extract `collapsedContent` to `CollapsedContentView`:**

```swift
// Before
private func collapsedContent(property: PropertyDetail) -> some View {
    VStack(alignment: .leading, spacing: 12) {
        // Price row
        HStack(alignment: .top) { ... }
        // Beds, Baths, Sqft
        HStack(spacing: 16) { ... }
        // Address
        Text(property.fullAddress)
    }
}

// After
private struct CollapsedContentView: View {
    let property: PropertyDetail

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Same content, but now it's an opaque type
        }
    }
}

private func collapsedContent(property: PropertyDetail) -> some View {
    CollapsedContentView(property: property)
}
```

**2. Extract `expandedContent` to `ExpandedContentView`:**

```swift
private struct ExpandedContentView: View {
    let property: PropertyDetail
    let maxHeight: CGFloat
    @Binding var mlsCopied: Bool
    @Binding var showingContactSheet: Bool
    // ... other bindings

    // Use AnyView for complex sections that can't be easily extracted
    let propertyHistoryContent: AnyView
    let factsAndFeaturesContent: AnyView

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                PriceSectionView(property: property, mlsCopied: $mlsCopied)
                KeyDetailsGridView(property: property)
                // ... other section views
                propertyHistoryContent  // AnyView for complex sections
            }
        }
    }
}
```

**3. Extract action buttons to `ActionButtonsView`:**

```swift
private struct ActionButtonsView: View {
    let property: PropertyDetail
    let isUserAgent: Bool
    // ... bindings

    var body: some View {
        VStack(spacing: 12) {
            // Buttons with conditional styles using if/else (not ternary)
            if isUserAgent {
                Button { ... }.buttonStyle(SecondaryButtonStyle())
            } else {
                Button { ... }.buttonStyle(PrimaryButtonStyle())
            }
        }
    }
}
```

### Important: Button Style Ternary Operators Don't Work

Swift cannot use different `ButtonStyle` types in a ternary operator:

```swift
// WRONG - Compilation error: mismatching types
.buttonStyle(isUserAgent ? SecondaryButtonStyle() : PrimaryButtonStyle())

// CORRECT - Use if/else
if isUserAgent {
    Button { ... }.buttonStyle(SecondaryButtonStyle())
} else {
    Button { ... }.buttonStyle(PrimaryButtonStyle())
}
```

---

## Prevention Guidelines

### Rule 1: Maximum ViewBuilder Complexity

**Limit ViewBuilder functions to ~10-15 top-level items.** If you need more, extract to a separate View struct.

```swift
// BAD - Too many items
func body: some View {
    VStack {
        section1()
        section2()
        // ... 20 more sections
    }
}

// GOOD - Extract to structs
var body: some View {
    VStack {
        TopSectionGroup()
        MiddleSectionGroup()
        BottomSectionGroup()
    }
}
```

### Rule 2: Extract Conditional Branches

If you have `if/else` or `switch` with complex content in both branches, extract each branch to its own View struct.

```swift
// BAD - Both branches have complex types
if isExpanded {
    ScrollView { /* 500 lines */ }
} else {
    VStack { /* 100 lines */ }
}

// GOOD - Opaque boundaries
if isExpanded {
    ExpandedContentView(...)
} else {
    CollapsedContentView(...)
}
```

### Rule 3: Use AnyView Sparingly for Complex Sections

For sections that are difficult to extract (require many parameters, closures, etc.), use `AnyView` to type-erase:

```swift
ExpandedContentView(
    // ... simple parameters
    propertyHistoryContent: AnyView(propertyHistorySection(property)),
    factsAndFeaturesContent: AnyView(factsAndFeaturesSection(property))
)
```

Note: `AnyView` has performance implications (disables some SwiftUI optimizations), so prefer extracting to View structs when possible.

### Rule 4: Watch for Warning Signs

If you experience any of these, consider refactoring:

1. **Slow compilation** - ViewBuilder type-checking is exponential
2. **"Expression too complex" errors** - Type hierarchy too deep for compiler
3. **Random crashes on view appear** - Possible stack overflow
4. **Xcode indexing/autocomplete issues** - Complex types slow down IDE

### Rule 5: Test on Device

Stack overflow crashes may not occur in Simulator (different stack limits). Always test complex views on physical devices.

---

## Debugging Stack Overflow Crashes

### Identifying the Culprit

1. Look for `swift_getTypeByMangledName` in crash logs
2. Find the last app frame before the recursive pattern
3. That function has a type hierarchy that's too deep

### Quick Diagnostic

Add temporary breakpoints or print statements to narrow down which function is crashing:

```swift
func body: some View {
    print("body start")  // If this prints but view never appears = crash in body
    let result = actualBody
    print("body end")    // If this never prints = crash during type resolution
    return result
}
```

### Measuring Type Complexity

While there's no direct way to measure type depth, you can get hints from:

1. Compile time - Longer compile = deeper types
2. Xcode memory usage during build
3. Size of `.swiftinterface` files

---

## Summary

| Aspect | Details |
|--------|---------|
| **Problem** | Stack overflow during SwiftUI type metadata resolution |
| **Root Cause** | Deep generic type hierarchy from ViewBuilder with 20+ sections |
| **Solution** | Extract ViewBuilder functions to View structs (opaque type boundaries) |
| **Key Insight** | Each View struct is an opaque type - runtime doesn't resolve its internals |
| **Prevention** | Limit ViewBuilder complexity, extract conditional branches, test on device |

---

## Files Changed in Fix

| File | Changes |
|------|---------|
| `PropertyDetailView.swift` | Added `CollapsedContentView`, `ExpandedContentView`, `ActionButtonsView` structs; updated container functions to use structs |
| `project.pbxproj` | Version bump to 306 |

---

## References

- [Swift Forums: ViewBuilder type-checking complexity](https://forums.swift.org/t/primitives-for-reducing-viewbuilder-compilation-times/)
- [WWDC 2021: Demystify SwiftUI](https://developer.apple.com/videos/play/wwdc2021/10022/)
- Apple Documentation: SwiftUI View Protocol
