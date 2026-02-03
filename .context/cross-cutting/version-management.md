# Version Management

How to bump versions across all components.

## iOS App

### Version Number Location

Update `CURRENT_PROJECT_VERSION` in `ios/BMNBoston.xcodeproj/project.pbxproj`.

There are **6 occurrences** - use Claude Code's `replace_all` to update all at once:

```
Old: CURRENT_PROJECT_VERSION = 128;
New: CURRENT_PROJECT_VERSION = 129;
```

### When to Bump

- Every build submitted to TestFlight
- Any release to App Store
- Significant feature additions

### Adding New Swift Files

When creating new `.swift` files, manually add to `project.pbxproj`:

1. `PBXBuildFile` section
2. `PBXFileReference` section
3. Appropriate group
4. `PBXSourcesBuildPhase` files array

---

## MLS Listings Display Plugin

### Version Locations (All 3 Required)

**1. version.json:**
```json
{
    "version": "6.68.18",
    "db_version": "6.68.18",
    "last_updated": "2026-01-21"
}
```

**2. mls-listings-display.php header (~line 6):**
```php
* Version: 6.68.18
```

**3. mls-listings-display.php constant:**
```php
define('MLD_VERSION', '6.68.18');
```

### When to Bump

- Any bug fix
- New filter added
- API changes
- CSS/JS changes (MLD_VERSION triggers cache bust)

---

## BMN Schools Plugin

### Version Locations (All 3 Required)

**1. version.json:**
```json
{
    "version": "0.6.39",
    "db_version": "0.6.38",
    "last_updated": "2026-01-21"
}
```

**2. bmn-schools.php header:**
```php
* Version: 0.6.39
```

**3. bmn-schools.php constant:**
```php
define('BMN_SCHOOLS_VERSION', '0.6.39');
```

### When to Bump

- Ranking algorithm changes
- Data import updates
- API endpoint changes
- New glossary terms

---

## SN Appointment Booking Plugin

### Version Locations (All 4 Required)

**1. version.json:**
```json
{
    "version": "1.9.5",
    "db_version": "1.9.5",
    "last_updated": "2026-01-15"
}
```

**2. sn-appointment-booking.php header:**
```php
* Version: 1.9.5
```

**3. sn-appointment-booking.php constant:**
```php
define('SNAB_VERSION', '1.9.5');
```

**4. class-snab-upgrader.php constants:**
```php
const CURRENT_VERSION = '1.9.5';
const CURRENT_DB_VERSION = '1.9.5';
```

### When to Bump

- Booking flow changes
- Google Calendar integration updates
- Email template changes
- Database schema changes

---

## Version Numbering Convention

We use Semantic Versioning:

```
MAJOR.MINOR.PATCH

MAJOR = Breaking changes
MINOR = New features, non-breaking
PATCH = Bug fixes, minor changes
```

### Examples

- `6.31.8` → `6.31.9` (bug fix)
- `6.31.9` → `6.32.0` (new filter feature)
- `6.32.0` → `7.0.0` (API breaking change)

---

## Quick Reference

| Component | Locations | Key File |
|-----------|-----------|----------|
| iOS | 6 in project.pbxproj | `CURRENT_PROJECT_VERSION` |
| MLD | 3 locations | `mls-listings-display.php`, `version.json` |
| Schools | 3 locations | `bmn-schools.php`, `version.json` |
| Appointments | 4 locations | `sn-appointment-booking.php`, `version.json`, `class-snab-upgrader.php` |

---

## Automation Tips

### Claude Code Replace

Use `replace_all` flag in Edit tool:
- `old_string`: `CURRENT_PROJECT_VERSION = 128;`
- `new_string`: `CURRENT_PROJECT_VERSION = 129;`
- `replace_all`: `true`

### After Version Bump

1. Commit the version changes
2. Deploy to production (WordPress plugins)
3. Build and submit (iOS)
4. Update release notes if applicable
