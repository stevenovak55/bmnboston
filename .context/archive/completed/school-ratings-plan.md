# School Ratings System Enhancement Plan

> **Historical Document:** This planning document is from December 2025.
> Version references (v0.6.19-v0.6.21) reflect the state at time of planning.
> See plugin `version.json` files for current versions.

**Created:** December 22, 2025
**Status:** Ready for Implementation
**Priority:** Data Quality First

---

## Executive Summary

This plan outlines enhancements to the BMN Boston school ratings system to improve data quality, add new data sources, and achieve feature parity between iOS and web platforms.

### Current System State

| Component | Version | Status |
|-----------|---------|--------|
| BMN Schools Plugin | v0.6.19 | Production |
| MLS Listings Display | v6.30.13 | Production |
| iOS App | v90 | Production |

### Data Coverage

| Data Type | Records | Source |
|-----------|---------|--------|
| Schools | 2,636 | MassGIS |
| Districts | 342 | NCES EDGE |
| District Rankings | 275 (80%) | Calculated |
| MCAS Scores | 44,213 | MA DESE |
| Demographics | 5,460 | E2C Hub |
| Features | 21,787+ | E2C Hub (multi-year) |

---

## Current Ranking Algorithm

### Middle/High School Weights (8 factors)

| Factor | Weight | Data Source |
|--------|--------|-------------|
| MCAS Proficiency | 25% | `bmn_school_test_scores` |
| Student-Teacher Ratio | 20% | `bmn_school_features` (staffing) |
| Graduation Rate | 12% | `bmn_school_features` (graduation) |
| MassCore Completion | 10% | `bmn_school_features` (masscore) |
| Attendance | 10% | `bmn_school_features` (attendance) |
| MCAS Growth | 10% | `bmn_school_test_scores` |
| AP Performance | 8% | `bmn_school_features` (ap_summary) |
| Per-Pupil Spending | 5% | `bmn_school_features` (expenditure) |

### Elementary School Weights (4 factors)

| Factor | Weight | Notes |
|--------|--------|-------|
| MCAS Proficiency | 40% | Primary metric |
| Attendance | 25% | Higher weight for elementary |
| Student-Teacher Ratio | 20% | Class size matters more |
| MCAS Growth | 15% | Year-over-year improvement |

### Letter Grades (Percentile-Based)

| Percentile | Grade |
|------------|-------|
| 90-100 | A+ |
| 80-89 | A |
| 70-79 | A- |
| 60-69 | B+ |
| 50-59 | B |
| 40-49 | B- |
| 30-39 | C+ |
| 20-29 | C |
| 10-19 | C- |
| 1-9 | D |
| 0 | F |

---

## Identified Gaps

### Data Quality Issues

1. **Elementary Schools**: Only 2-3 data factors vs 8 for high schools (shows "limited data" warning)
2. **District Coverage**: 275/342 districts have rankings (80%) - iOS filter works but not all cities covered
3. **Private Schools**: Limited MCAS participation (optional for private schools)
4. **Data Freshness**: Some schools have 2023 data only

### Feature Parity Issues

1. **District Grade Filter**: Enabled on web, was disabled on iOS (now working with 275 districts)
2. **School Comparison**: API endpoint `/schools/compare` exists, no UI
3. **Trend Visualization**: Data exists (`/schools/{id}/trends`), not charted
4. **Glossary**: 20 terms in API, iOS shows limited subset

---

## Implementation Phases

### Phase 1: Data Quality (PRIORITY - START HERE)

#### 1.1 Complete District Ranking Coverage (DONE - 80%)

**Status:** District coverage increased from 23 to 275 districts (80% of MA)

**What was done:**
- Added `map_schools_to_districts()` method with 3-strategy matching
- Integrated into annual sync process
- District grade filter now functional on both platforms

**Remaining gap:** 67 districts still missing (mostly very small districts)

#### 1.2 Improve Elementary School Ratings (DONE - v0.6.21)

**Status:** Completed December 22, 2025

**What was done:**
1. Added district-level per-pupil spending fallback to ranking calculator
   - 73% of elementary schools (648/883) now have spending data
   - Previously 0% had school-level spending data
2. Updated elementary weights (now 5 factors):
   - MCAS: 35% (was 40%), Attendance: 25%, Ratio: 20%, Growth: 10% (was 15%), Spending: 10% (was 0%)
3. Updated confidence thresholds: 5=comprehensive, 4=good, <4=limited
4. Improved limited data messaging with specific reasons
5. Verified student-teacher ratio is working correctly (831/834 schools have it)

**Remaining gap:**
- Schools without MCAS data (~167) will always show "limited" - these are typically private schools or pre-K programs
- This is by design since MCAS is the primary quality metric

**Files modified:**
- `bmn-schools/includes/class-ranking-calculator.php`
- `bmn-schools/includes/class-rest-api.php` (2 locations)

#### 1.3 Add College Enrollment Outcomes Data (DONE - v0.6.20)

**Status:** Completed December 22, 2025

**What was done:**
- Imported college outcomes data for 71 districts from E2C Hub dataset `vj54-j4q3`
- Data includes: 4-year college %, 2-year college %, employed %, out-of-state %
- Stored in districts.extra_data JSON column under `college_outcomes` key
- Added AJAX handler for admin import
- iOS app updated (v91) to display college outcomes in district section

**Data Source:** E2C Hub dataset `vj54-j4q3` (College and Career Outcomes)
- URL: `https://educationtocareer.data.mass.gov/resource/vj54-j4q3.json`
- Contains: 2-year college %, 4-year college %, workforce %, military %

**Database Changes:**
```sql
CREATE TABLE bmn_school_outcomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    year INT NOT NULL,
    two_year_college_pct DECIMAL(5,2),
    four_year_college_pct DECIMAL(5,2),
    workforce_pct DECIMAL(5,2),
    military_pct DECIMAL(5,2),
    other_pct DECIMAL(5,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_school_year (school_id, year),
    KEY idx_school_id (school_id)
);
```

**API Response Addition:**
```json
{
  "college_outcomes": {
    "year": 2024,
    "four_year_college": 65.2,
    "two_year_college": 18.5,
    "workforce": 12.1,
    "military": 2.8
  }
}
```

**Files to modify:**
- `bmn-schools/includes/data-providers/class-dese-provider.php` - Add import method
- `bmn-schools/includes/class-database-manager.php` - Add outcomes table
- `bmn-schools/includes/class-rest-api.php` - Include in school response
- `ios/BMNBoston/Core/Models/School.swift` - Add college outcomes fields
- `ios/BMNBoston/Features/PropertySearch/Views/NearbySchoolsSection.swift` - Display

---

### Phase 2: Safety & Discipline Data

#### 2.1 Add Discipline Data Integration

**Goal:** Provide school safety metrics for parents

**Data Source:** DESE School Safety Discipline Report (SSDR)
- URL: https://profiles.doe.mass.edu/statereport/ssdr.aspx
- Contains: in-school suspensions, out-of-school suspensions, expulsions, emergency removals

**Database Changes:**
```sql
CREATE TABLE bmn_school_discipline (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    year INT NOT NULL,
    enrollment INT,
    in_school_suspensions INT,
    out_of_school_suspensions INT,
    expulsions INT,
    emergency_removals INT,
    discipline_rate DECIMAL(5,2),  -- per 100 students
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_school_year (school_id, year),
    KEY idx_school_id (school_id)
);
```

**Highlight Badge:** "Low Discipline Rate" for schools in bottom 25% of discipline incidents

**Files to modify:**
- `bmn-schools/includes/data-providers/class-ssdr-provider.php` (NEW)
- `bmn-schools/includes/class-database-manager.php`
- `bmn-schools/includes/class-ranking-calculator.php` - Add highlight generation

---

### Phase 3: Sports/Programs Data

#### 3.1 Add MIAA Sports Participation

**Goal:** Show available sports programs at high schools

**Data Source:** MIAA participation survey reports (annual)

**Database Changes:**
```sql
CREATE TABLE bmn_school_sports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    year INT NOT NULL,
    sport VARCHAR(100) NOT NULL,
    gender ENUM('Boys', 'Girls', 'Coed') NOT NULL,
    participants INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_school_id (school_id),
    KEY idx_sport (sport)
);
```

**Highlight Badge:** "Strong Athletics" for schools with 15+ sports programs

---

### Phase 4: Feature Parity & UX

#### 4.1 School Comparison Feature

**Goal:** Allow side-by-side comparison of 2-5 schools

**Current State:** API endpoint `/schools/compare` exists but no UI

**API Endpoint:**
```
GET /wp-json/bmn-schools/v1/schools/compare?ids=123,456,789
```

**Tasks:**
1. Create iOS `SchoolComparisonView` with radar chart (SwiftUI Charts)
2. Create web comparison modal/page
3. Add "Compare" button to school cards
4. Share comparison via URL

**Files to create/modify:**
- `ios/BMNBoston/Features/PropertySearch/Views/SchoolComparisonView.swift` (NEW)
- `mls-listings-display/templates/school-comparison.php` (NEW)

#### 4.2 Historical Trend Charts

**Goal:** Visualize 5-year MCAS trends

**Current State:** Trend data exists (`/schools/{id}/trends`), not visualized

**API Endpoint:**
```
GET /wp-json/bmn-schools/v1/schools/123/trends
```

**Tasks:**
1. Create iOS trend chart component (SwiftUI Charts)
2. Create web trend chart (Chart.js)
3. Add to school detail expansion

#### 4.3 Expand Glossary (DONE - v92)

**Status:** Completed December 22, 2025

**What was done:**
1. Expanded inline glossary chips from 5 to 10 terms in 2 rows:
   - Row 1: Composite Score, Letter Grades, Percentile, MCAS, Class Size
   - Row 2: AP Courses, MassCore, Attendance, Spending, Special Ed
2. Added "See All 20 Terms" button that opens full glossary modal
3. Full glossary sheet shows all 20 terms organized by category:
   - Rating System: mcas, composite_score, letter_grade, percentile_rank
   - Academic Programs: masscore, ap, cte, early_college, innovation_pathway
   - Student Metrics: chronic_absence, student_teacher_ratio, sped, ell, metco
   - Financial: chapter_70, per_pupil_spending
   - School Types: charter_school, regional_school, accountability, school_committee

**Files Changed:**
- `ios/BMNBoston/Features/PropertySearch/Views/NearbySchoolsSection.swift`

**Remaining:**
- Web platform still shows limited glossary (can be addressed separately)

---

### Phase 5: Performance Optimizations

#### 5.1 Pre-compute School Grades for Grid Cells

**Goal:** Faster school proximity filtering

**Current Issue:** Over-fetches 3x results when school filters active

**Solution:**
1. Create geographic grid (0.5 mile cells)
2. Pre-calculate best school grade in each cell
3. Store in lookup table for O(1) filtering

#### 5.2 Lazy Loading School Details

**Goal:** Faster initial page load

**Solution:**
1. Load basic school info first (name, grade, distance)
2. Fetch demographics, highlights, benchmarks on expand

---

## Critical Files Reference

### BMN Schools Plugin (`bmn-schools/`)

| File | Purpose | Lines |
|------|---------|-------|
| `includes/class-ranking-calculator.php` | Rating algorithm, weights, highlights | ~1,500 |
| `includes/class-rest-api.php` | All REST endpoints | ~3,200 |
| `includes/class-database-manager.php` | Table schemas, migrations | ~800 |
| `includes/data-providers/class-dese-provider.php` | E2C Hub imports | ~1,200 |
| `admin/class-admin.php` | Admin UI, annual sync | ~600 |

### iOS App (`ios/BMNBoston/`)

| File | Purpose |
|------|---------|
| `Core/Models/School.swift` | All school data models |
| `Features/PropertySearch/Views/NearbySchoolsSection.swift` | School display on property pages |
| `Features/PropertySearch/Views/AdvancedFilterModal.swift` | School filter controls |
| `Core/Networking/APIEndpoint.swift` | API endpoint definitions |
| `Core/Services/SchoolService.swift` | School API calls |

### MLS Listings Display (`mls-listings-display/`)

| File | Purpose | Lines |
|------|---------|-------|
| `includes/class-mld-bmn-schools-integration.php` | School integration | ~1,650 |
| `includes/class-mld-enhanced-filters.php` | Web filter UI | ~400 |
| `assets/css/mld-schools.css` | School styling | ~1,000 |
| `assets/js/mld-schools-glossary.js` | Glossary interactivity | ~200 |
| `templates/single-property-desktop-v3.php` | Desktop property page | ~800 |
| `templates/single-property-mobile-v3.php` | Mobile property page | ~600 |

---

## Recommended Implementation Order

| Priority | Phase | Task | Effort | Value | Status |
|----------|-------|------|--------|-------|--------|
| 1 | 1.3 | College outcomes data | 4-6 hrs | HIGH | DONE (v0.6.20) |
| 2 | 1.2 | Elementary school ratings | 3-4 hrs | MEDIUM | DONE (v0.6.21) |
| 3 | 4.3 | Expand glossary | 2-3 hrs | LOW | DONE (iOS v92) |
| 4 | 2.1 | Discipline data | 8-12 hrs | MEDIUM | Pending |
| 5 | 4.1 | School comparison | 6-8 hrs | MEDIUM | Pending |
| 6 | 4.2 | Trend charts | 4-6 hrs | LOW | Pending |
| 7 | 3.1 | Sports participation | 6-8 hrs | LOW | Pending |
| 8 | 5.1 | Grid pre-computation | 4-6 hrs | LOW | Pending |

**Recommended Next Task:** Phase 2.1 - Discipline Data
- Provides valuable school safety metrics for parents
- Uses DESE School Safety Discipline Report (SSDR) data
- Will add "Low Discipline Rate" highlight badge

---

## API Testing Commands

```bash
# List schools
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools?per_page=10"

# School detail with ranking
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools/737"

# Schools for property (iOS endpoint)
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2"

# School trends
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools/737/trends"

# Compare schools
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools/compare?ids=737,738,739"

# Glossary
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

---

## Version Update Checklist

### BMN Schools Plugin (3 locations)
1. `version.json` - version field
2. `bmn-schools.php` - Header comment `Version: X.Y.Z`
3. `bmn-schools.php` - Constant `define('BMN_SCHOOLS_VERSION', 'X.Y.Z');`

### MLS Listings Display (3 locations)
1. `version.json` - version field
2. `mls-listings-display.php` - Header comment `Version: X.Y.Z`
3. `mls-listings-display.php` - Constant `define('MLD_VERSION', 'X.Y.Z');`

### iOS App (1 location, 6 occurrences)
- `project.pbxproj` - `CURRENT_PROJECT_VERSION = N+1;` (use replace_all)

---

## Production Deployment

```bash
# Upload BMN Schools files (get password from password manager)
sshpass -p '$KINSTA_PASSWORD' scp -P 57105 \
  /path/to/file.php \
  stevenovakcom@35.236.219.140:~/public/wp-content/plugins/bmn-schools/includes/

# Upload MLD files
sshpass -p '$KINSTA_PASSWORD' scp -P 57105 \
  /path/to/file.php \
  stevenovakcom@35.236.219.140:~/public/wp-content/plugins/mls-listings-display/includes/

# Invalidate opcache (touch files)
sshpass -p '$KINSTA_PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "touch ~/public/wp-content/plugins/bmn-schools/includes/class-rest-api.php"
```

---

## Related Documentation

- `/CLAUDE.md` - Main project guide
- `/ios/CLAUDE.md` - iOS app development guide
- `/wordpress/wp-content/plugins/bmn-schools/CLAUDE.md` - BMN Schools plugin guide
- `/wordpress/wp-content/plugins/mls-listings-display/CLAUDE.md` - MLD plugin guide
