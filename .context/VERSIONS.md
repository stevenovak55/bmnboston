# Current Versions

Single source of truth for all component versions. Update this file when bumping versions.

## Active Components

| Component | Version | Updated | Source of Truth |
|-----------|---------|---------|-----------------|
| iOS App | v402 (1.8) | Feb 5, 2026 | `ios/BMNBoston.xcodeproj/project.pbxproj` |
| MLS Listings Display | v6.75.8 | Feb 4, 2026 | `mls-listings-display/version.json` |
| BMN Schools | v0.6.39 | Jan 21, 2026 | `bmn-schools/version.json` |
| SN Appointments | v1.10.4 | Feb 5, 2026 | `sn-appointment-booking/version.json` |
| Exclusive Listings | v1.5.3 | Jan 27, 2026 | `exclusive-listings/version.json` |
| Bridge MLS Extractor | v4.0.32 | Jan 21, 2026 | `bridge-mls-extractor-pro-review/bridge-mls-extractor-pro.php` |
| Theme (flavor-flavor-flavor) | v1.5.9 | Jan 28, 2026 | `flavor-flavor-flavor/version.json` |
| BMN Flip Analyzer | v0.19.0 | Feb 7, 2026 | `bmn-flip-analyzer/bmn-flip-analyzer.php` |

## Version Bump Scripts

All components have automated bump scripts in `shared/scripts/`:

| Component | Script | Example |
|-----------|--------|---------|
| iOS App | `bump-ios-version.sh` | `./bump-ios-version.sh 392` |
| MLS Listings Display | `bump-mld-version.sh` | `./bump-mld-version.sh 6.75.3` |
| BMN Schools | `bump-schools-version.sh` | `./bump-schools-version.sh 0.6.40` |
| SN Appointments | `bump-appointments-version.sh` | `./bump-appointments-version.sh 1.9.6` |
| Bridge MLS Extractor | `bump-bme-version.sh` | `./bump-bme-version.sh 4.0.33` |
| Exclusive Listings | `bump-exclusive-version.sh` | `./bump-exclusive-version.sh 1.5.4` |
| Theme | `bump-theme-version.sh` | `./bump-theme-version.sh 1.6.0` |

**Usage:** Run without arguments to see current version.

## Version Bump Locations (Manual Reference)

### iOS App
Update `CURRENT_PROJECT_VERSION = N;` in **6 occurrences** in `project.pbxproj` - use find/replace all.

### WordPress Plugins (3-4 locations each)

**MLS Listings Display & BMN Schools:**
1. `version.json` → `"version"` and `"last_updated"`
2. `plugin-name.php` header → `Version: X.Y.Z`
3. `plugin-name.php` constant → `define('PLUGIN_VERSION', 'X.Y.Z')`

**SN Appointments (4 locations):**
1. `version.json` → `"version"` and `"last_updated"`
2. `sn-appointment-booking.php` header → `Version: X.Y.Z`
3. `sn-appointment-booking.php` → `define('SNAB_VERSION', 'X.Y.Z')`
4. `includes/class-snab-upgrader.php` → `CURRENT_VERSION` constant

**Bridge MLS Extractor (3 locations):**
1. `bridge-mls-extractor-pro.php` header → `Version: X.Y.Z`
2. `bridge-mls-extractor-pro.php` → `define('BME_PRO_VERSION', 'X.Y.Z')`
3. `bridge-mls-extractor-pro.php` → `define('BME_VERSION', 'X.Y.Z')`

**Exclusive Listings (3 locations):**
1. `version.json` → `"version"` and `"last_updated"`
2. `exclusive-listings.php` header → `Version: X.Y.Z`
3. `exclusive-listings.php` → `define('EL_VERSION', 'X.Y.Z')`

### Theme
1. `version.json` → `"version"` and `"updated"`
2. `style.css` header → `Version: X.Y.Z`

---

*Last updated: 2026-02-06*
