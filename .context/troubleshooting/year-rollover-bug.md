# Year Rollover Bug

**Severity:** Critical
**First Occurrence:** January 1, 2026
**Status:** Fixed (v6.31.8)

## Summary

On January 1, 2026, all school-based property filters stopped working because the code used `date('Y')` to query rankings data, but the data was from the previous year.

## Symptoms

- `school_grade=A` filter returns 0 results
- `near_a_elementary=true` filter returns 0 or full unfiltered count
- `near_top_elementary=true` filter returns 0 or full unfiltered count
- School rankings not displaying on property details

## Root Cause

```php
// BAD: This returns 2026 on Jan 1, 2026
$year = (int) date('Y');

// Rankings table only has 2025 data
$sql = "SELECT * FROM bmn_school_rankings WHERE year = %d";
// Result: 0 matches
```

## Solution

Query for the latest year that has data:

```php
// GOOD: Get most recent year with actual data
private function get_latest_ranking_year() {
    global $wpdb;
    $rankings_table = $wpdb->prefix . 'bmn_school_rankings';

    $result = $wpdb->get_var("SELECT MAX(year) FROM {$rankings_table}");

    // Fallback to previous year if no data
    return $result ? (int) $result : (int) date('Y') - 1;
}

// Usage
$year = $this->get_latest_ranking_year();  // Returns 2025 even on Jan 1, 2026
```

## Files Affected

### class-mld-bmn-schools-integration.php

Three methods used `date('Y')`:

1. `get_top_schools_cached()` - For proximity filters
2. `get_best_nearby_school_grade()` - For grade lookups
3. `get_all_district_averages()` - For district grade filter

### Fix Applied

Added `get_latest_ranking_year()` helper method and replaced all three occurrences.

## Quick Test

Run this after any year transition:

```bash
# Should return ~1,600 results, NOT 0
curl "https://bmnboston.com/wp-json/mld-mobile/v1/properties?school_grade=A&per_page=1"

# Should return schools with letter_grade values
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2" | grep "letter_grade"
```

## Prevention

### Pattern to Avoid

```php
// DON'T DO THIS for time-series data
$year = (int) date('Y');
$year = date('Y');
$current_year = 2026;  // Hardcoded
```

### Pattern to Use

```php
// DO THIS: Query for latest available year
$year = $wpdb->get_var("SELECT MAX(year) FROM {$table_name}");

// With fallback
$year = $year ?: (int) date('Y') - 1;
```

### Where This Applies

Any table with year-based data:
- `bmn_school_rankings`
- `bmn_school_test_scores`
- `bmn_school_demographics`
- `bmn_school_features`
- Any other time-series educational data

## Related

- [Critical Pitfalls](../cross-cutting/critical-pitfalls.md) - Pitfall #2 (Year Rollover Bug)
- [Changelog](../archive/changelog/CHANGELOG.md) - v6.31.8 fix
