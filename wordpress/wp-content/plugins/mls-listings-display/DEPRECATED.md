# DEPRECATED.md - MLS Listings Display

This document tracks deprecated code, features, and functions scheduled for removal in future versions.

**Last Updated:** January 21, 2026
**Current Version:** 6.68.18

---

## Deprecated Shortcodes

### 1. `[mld_user_dashboard]` (v6.34.0)
**Location:** `includes/class-mld-shortcodes.php:679`
**Replacement:** Vue.js dashboard at `/my-dashboard/`
**Status:** Redirects to Vue.js dashboard, will be fully removed in future version

```php
// DEPRECATED in v6.34.0: This shortcode now redirects to the Vue.js dashboard.
// The old PHP-rendered dashboard files (user-dashboard.php, user-dashboard-enhanced.php)
// and their assets are deprecated and will be removed in a future version.
```

### 2. Legacy Shortcodes (v6.20.9)
**Location:** `includes/class-mld-shortcodes.php:24`
**Status:** Handlers removed, shortcodes no longer functional

These shortcodes were removed in v6.20.9:
- `[mld_property_search]` - Replaced by improved search components
- Other legacy shortcodes documented in v6.20.9 changelog

---

## Deprecated Functions

### 1. `get_city_grade()` (class-mld-bmn-schools-integration.php)
**Location:** `includes/class-mld-bmn-schools-integration.php:1632`
**Replacement:** `get_district_grade_for_city()`
**Reason:** Function name was misleading; new name is more descriptive

```php
/**
 * DEPRECATED: Use get_district_grade_for_city instead
 */
public function get_city_grade($city) {
    $info = $this->get_district_grade_for_city($city);
    return $info ? $info['grade'] : null;
}
```

### 2. `get_district_average_grade_by_city()` (v6.68.15)
**Location:** `includes/class-mld-bmn-schools-integration.php`
**Replacement:** `get_district_grade_for_city()`
**Reason:** Method averaged individual school percentiles instead of using official district composite scores, causing grade inconsistencies. See Pitfall #39.

```php
/**
 * DEPRECATED v6.68.15: Use get_district_grade_for_city() instead
 * This method calculated grades incorrectly by averaging school percentiles
 * instead of using the official district_rankings composite_score.
 */
// public function get_district_average_grade_by_city($city) { ... }
```

### 3. `get_all_district_averages()` (v6.68.15)
**Location:** `includes/class-mld-bmn-schools-integration.php`
**Replacement:** Query `bmn_district_rankings` table directly
**Reason:** Helper for deprecated `get_district_average_grade_by_city()`

```php
/**
 * DEPRECATED v6.68.15: Helper for deprecated get_district_average_grade_by_city()
 */
// private function get_all_district_averages() { ... }
```

### 2. `legacy_filter_check()` (class-mld-saved-search-notifications.php)
**Location:** `includes/saved-searches/class-mld-saved-search-notifications.php:421`
**Replacement:** `MLD_Enhanced_Filter_Matcher::matches()`
**Reason:** Unified filter matching logic in dedicated class

```php
/**
 * @deprecated Use MLD_Enhanced_Filter_Matcher::matches() instead
 */
```

### 3. `process_searches()` (class-mld-saved-search-cron.php)
**Location:** `includes/saved-searches/class-mld-saved-search-cron.php:300`
**Replacement:** `process_with_unified_processor()`
**Reason:** Consolidated processing logic for better maintainability

```php
/**
 * @deprecated Use process_with_unified_processor instead
 */
```

---

## Deprecated Cron Jobs

**Location:** `includes/class-mld-activator.php:592-595`

These cron jobs have no handlers and are deprecated:
- `mld_process_instant_searches` (every_minute)
- `mld_process_hourly_searches` (hourly)
- `mld_process_daily_searches` (daily)
- `mld_process_weekly_searches` (weekly)

**Replacement:** Consolidated into unified search processing system (instant-notifications)

---

## Deprecated Files

### Moved to `/deprecated/` Directory
- `templates/user-dashboard.php` - Old PHP dashboard
- `templates/user-dashboard-enhanced.php` - Enhanced PHP dashboard
- `assets/css/mld-saved-searches-dashboard.css` - Old dashboard styles
- `assets/js/mld-saved-searches-dashboard.js` - Old dashboard scripts

**Replacement:** Vue.js client dashboard system

---

## Deprecated Features

### 1. School District Filtering (v6.30.0)
**Status:** Completely removed
**Reason:** Replaced with more accurate school-based filtering using BMN Schools plugin

The old `school_district` filter parameter has been replaced with:
- `school_grade` - Filter by school letter grade (A, A-, B+, etc.)
- Direct integration with BMN Schools plugin for accurate school assignments

---

## Future Removal Schedule

| Item | Deprecated In | Remove In | Priority |
|------|--------------|-----------|----------|
| `[mld_user_dashboard]` redirect | v6.34.0 | v7.0.0 | Low |
| `get_city_grade()` wrapper | v6.30.0 | v7.0.0 | Low |
| `get_district_average_grade_by_city()` | v6.68.15 | v7.0.0 | Low |
| `get_all_district_averages()` | v6.68.15 | v7.0.0 | Low |
| Legacy cron job references | v6.35.0 | v7.0.0 | Low |
| Deprecated dashboard files | v6.34.0 | v7.0.0 | Low |

---

## Code Consolidation Opportunities

These are not deprecated but are candidates for consolidation in future major versions:

### 1. Dual Notification Systems (~12,000 lines)
- `/includes/notifications/` - Legacy notification system
- `/includes/instant-notifications/` - New unified system

**Recommendation:** Fully migrate to instant-notifications system

### 2. Multiple Logger Implementations
- `MLD_Logger` (mls-listings-display)
- `SNAB_Logger` (sn-appointment-booking)
- `BMN_Schools_Logger` (bmn-schools)

**Recommendation:** Create shared base logger class

### 3. Analytics Systems
- `/includes/analytics/` - Mixed legacy classes
- `/includes/analytics/public/` - New public analytics (v6.39+)

**Recommendation:** Consolidate to public analytics system

---

## Notes for Developers

1. **Before removing deprecated code:**
   - Verify no external integrations depend on it
   - Add error logging to track any remaining usage
   - Update documentation and CHANGELOG

2. **When deprecating new code:**
   - Add `@deprecated` PHPDoc tag with version and replacement
   - Add entry to this document
   - Log deprecation warnings when the code is called (optional)
